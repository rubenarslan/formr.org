<?php

class UnitSession extends Model {

    public $id;
    public $unit_id; // survey_units.id
    public $run_unit_id; // survey_run_units.id — Track A: disambiguates same-unit-at-multiple-positions
    public $iteration; // 1-based count of attempts at THIS run_unit_id by THIS run_session
    public $run_session_id;
    public $created;
    public $expires;
    public $queued = 0;
    public $result;
    public $result_log;
    public $ended;
    public $expired;
    public $state; // ENUM (PENDING, RUNNING, WAITING_USER, WAITING_TIMER, ENDED, EXPIRED, SUPERSEDED)
    public $state_log; // JSON, structured sibling of result_log
    public $idempotency_key;
    public $execution_time; // issue #608: cumulative wall-clock seconds spent in execute(), incl. OpenCPU
    public $meta;
    public $queueable = 1;
    /**
     * @var RunSession
     */
    public $runSession;
    /**
     * @var RunUnit
     */
    public $runUnit;
    
    public $validatedStudyItems = [];
    
    protected $execResults = [];
	
	protected $table = 'survey_unit_sessions';


	/**
     * A UnitSession needs a RunUnit to operate and belongs to a RunSession
     *
     * @param RunSession $runSession
     * @param RunUnit $runUnit
     * @param array $options An array of other options used to fetch a unit ID
     */
    public function __construct(RunSession $runSession, RunUnit $runUnit = null, $options = []) {
        parent::__construct();

        $this->runSession = $runSession;
        $this->runUnit = $runUnit;
        // Defense-in-depth allowlist (api_v1_dev ed56a95f). Callers in
        // Run.php / Queue / RunSession pass only 'id' (PK) and 'load'
        // (a flag). Other properties — unit_id, run_session_id, ended,
        // expired, queued, result — come from the DB row in load()
        // below or from create() upstream, never from caller-provided
        // $options.
        if ($options) {
            $this->assignProperties(array_intersect_key(
                (array) $options,
                ['id' => true, 'load' => true]
            ));
        }
        if (isset($options['id'], $options['load'])) {
            $this->load();
        }
    }

    public function create($new_current_unit = true) {
        // only one can be current unit session at all times
        // Track A: resolve run_unit_id (the per-position survey_run_units.id)
        // and iteration (1-based count of prior unit-sessions for this
        // (run_session, run_unit) pair). Both NULL-safe so paths that
        // can't compute them (no run_session, no current position)
        // simply skip the column. See REFACTOR_QUEUE_PLAN.md A2.
        // (Initialized before the try so the catch can log them even when
        // beginTransaction itself throws.)
        $run_unit_id = null;
        $iteration   = 1;
        try {
            $this->db->beginTransaction();

            if ($this->runSession && $this->runSession->id > 0) {
                $position    = $this->runSession->position;
                // Audit F1 (2026-07): only stamp the placement when the
                // unit being created IS the unit hosted at the current
                // position. Side-channel creators (admin reminder emails
                // via Run::getReminderSession) otherwise inherit the
                // participant's current run_unit_id and their never-ended
                // row hijacks getCurrentUnitSession. Off-position sessions
                // keep run_unit_id NULL (the UNIQUE key permits NULLs).
                // Mirrors the guard 34378a8e added to load()'s fallback.
                if ($this->runSession->getUnitIdAtPosition($position) == $this->runUnit->id) {
                    $run_unit_id = $this->runSession->getRunUnitIdAtPosition($position);
                }
                if ($run_unit_id !== null) {
                    // COALESCE(MAX,0)+1 — read inside the open TX, just before
                    // INSERT, so concurrent inserts in another connection
                    // serialise on the supersede UPDATE that follows.
                    $next = $this->db->execute(
                        "SELECT COALESCE(MAX(`iteration`), 0) + 1 AS next_iter
                         FROM `survey_unit_sessions`
                         WHERE `run_session_id` = :rsid AND `run_unit_id` = :ruid",
                        [':rsid' => $this->runSession->id, ':ruid' => $run_unit_id],
                        false, true
                    );
                    if ($next && isset($next['next_iter'])) {
                        $iteration = (int) $next['next_iter'];
                    }
                }
            }

            $session = $this->assignProperties([
                'unit_id' => $this->runUnit->id,
                'run_unit_id' => $run_unit_id,
                'iteration' => $iteration,
                'run_session_id' => $this->runSession->id > 0 ? $this->runSession->id : null,
                'created' => mysql_now(),
                'state' => UnitSessionQueue::STATE_PENDING,
            ]);

            $this->id = $this->db->insert('survey_unit_sessions', $session);
            if ($this->runSession->id !== null && $new_current_unit) {
                $this->runSession->currentUnitSession = $this;
                $this->db->update('survey_run_sessions', ['current_unit_session_id' => $this->id], ['id' => $this->runSession->id]);

                // Supersede prior queue entries for THIS unit only — i.e.
                // duplicates created by SkipBackward / runTo where a previous
                // iteration's unit-session for the same unit is still queued.
                // Pre-fix the WHERE clause was (run_session_id, id <>, queued > 0)
                // — a blanket flip that would also supersede *unrelated*
                // queued siblings (a queued ESM Survey while a moveOn cascade
                // is creating a downstream Pause). That blanket flip was the
                // damage amplifier for the queue-stale-reference orphan path
                // (Symptom A in the wild). See tests/e2e/EXPIRY_PLAN.md "Fix 2".
                // Track A also writes state='SUPERSEDED' alongside queued=-9
                // for the same rows so analysts and admin tooling can read
                // the named state instead of decoding the queued magic value.
                //
                // D1 fix (v0.26.4): "THIS unit" must mean this *placement*
                // (survey_run_units.id), not this unit definition. Keyed on
                // unit_id, a run that slots the same survey at multiple
                // positions superseded the participant's still-active
                // session at an EARLIER position whenever a cascade created
                // a session for a LATER occurrence — zombifying it
                // (queued=-9, invisible to the daemon and to
                // getCurrentUnitSession) and collapsing the run forward.
                // Supersede by run_unit_id when known; legacy rows with
                // run_unit_id IS NULL (pre-047) keep the unit_id match.
                if ($run_unit_id !== null) {
                    $this->db->exec(
                        "UPDATE `survey_unit_sessions`
                            SET `queued` = :queued_superseded, `state` = :state_superseded
                          WHERE `run_session_id` = :run_session_id
                            AND `id` != :id
                            AND `queued` > 0
                            AND (`run_unit_id` = :run_unit_id
                                 OR (`run_unit_id` IS NULL AND `unit_id` = :unit_id))",
                        [
                            'queued_superseded' => UnitSessionQueue::QUEUED_SUPERCEDED,
                            'state_superseded'  => UnitSessionQueue::STATE_SUPERSEDED,
                            'run_session_id'    => $this->runSession->id,
                            'id'                => $this->id,
                            'run_unit_id'       => $run_unit_id,
                            'unit_id'           => $this->runUnit->id,
                        ]
                    );
                } else {
                    $this->db->update('survey_unit_sessions', ['queued' => UnitSessionQueue::QUEUED_SUPERCEDED, 'state' => UnitSessionQueue::STATE_SUPERSEDED], [
                        'run_session_id' => $this->runSession->id,
                        'unit_id'        => $this->runUnit->id,
                        'id <>'          => $this->id,
                        'queued >'       => 0,
                    ]);
                }
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();

            // Dedup L2 (backport of the 0.27.0 forever-fix; patch 063 on this
            // line): the UNIQUE (run_session_id, run_unit_id, iteration) key
            // rejected this INSERT because a CONCURRENT create() already
            // committed the same placement + iteration — the race that used to
            // leave two rows ("repeated pause"). Adopt the winner's row instead
            // of failing: idempotent create. The re-read of the exact tuple is
            // the guard — a 23000 from anything else finds no winner and falls
            // through to the generic recovery path below. (After rollBack the
            // connection is in autocommit, so this SELECT sees the committed
            // winner row.)
            if ($e->getCode() === '23000' && $run_unit_id !== null
                && $this->runSession && $this->runSession->id > 0) {
                $winner = $this->db->findValue('survey_unit_sessions', [
                    'run_session_id' => $this->runSession->id,
                    'run_unit_id'    => $run_unit_id,
                    'iteration'      => $iteration,
                ], 'id');
                if ($winner) {
                    $this->id = (int) $winner;
                    if ($new_current_unit) {
                        $this->runSession->currentUnitSession = $this;
                        $this->db->update('survey_run_sessions',
                            ['current_unit_session_id' => $this->id],
                            ['id' => $this->runSession->id]);
                    }
                    return $this->load();
                }
            }

            // Never swallow this silently: a failed INSERT leaves the
            // run-session with an already-advanced position and NO session
            // row (the raw material for silent position skips).
            formr_log_exception($e, __METHOD__, [
                'unit_id'        => $this->runUnit->id ?? null,
                'run_unit_id'    => $run_unit_id,
                'run_session_id' => $this->runSession->id ?? null,
            ]);
            // The rolled-back INSERT may have assigned $this->id an id that
            // no longer exists; loading by it (or via the no-id fallback)
            // used to hand the caller an arbitrary prior row or a phantom
            // that createUnitSession installed as "current". Return the
            // invalid object instead — createUnitSession treats a missing
            // id as "creation failed" and execute()'s recovery branch
            // re-creates the step at the current position.
            $this->id = null;
            $this->valid = false;
            return $this;
        }

        return $this->load();
    }

    public function load() {
        $columns = 'id, unit_id, run_unit_id, iteration, run_session_id, created, expires, queued, result, result_log, ended, expired, state, state_log, idempotency_key';
        if ($this->id !== null) {
            $vars = $this->db->findRow('survey_unit_sessions', ['id' => (int)$this->id], $columns);
        } else {
            $run_session_id = $this->runSession ? $this->runSession->id : $this->run_session_id;
            $unit_id = $this->runUnit ? $this->runUnit->id : $this->unit_id;
            // D1 fix (v0.26.4): this fallback used to pick an ARBITRARY row
            // (findRow, no ORDER BY) — for a unit reused at several positions
            // that could resolve a session belonging to a different placement.
            // The row must ALWAYS belong to this unit (unit_id constraint —
            // the sampler callers in RunUnit::getSampleSessions/
            // grabRandomSession load a unit the run session has often moved
            // PAST, so the current position may host a different unit
            // entirely). The placement preference applies only when the
            // current position actually hosts this unit (the create()
            // failure-recovery case); legacy rows with run_unit_id NULL
            // keep matching. Newest row wins either way.
            $run_unit_id = null;
            if ($this->runSession && $this->runSession->position !== null
                    && $this->runSession->getUnitIdAtPosition($this->runSession->position) == $unit_id) {
                $run_unit_id = $this->runSession->getRunUnitIdAtPosition($this->runSession->position);
            }
            $query = $this->db->select($columns)
                    ->from('survey_unit_sessions')
                    ->where('run_session_id = :run_session_id')
                    ->where('unit_id = :unit_id')
                    ->order('id', 'desc')
                    ->limit(1);
            if ($run_unit_id !== null) {
                $query->where('(run_unit_id = :run_unit_id OR run_unit_id IS NULL)')
                      ->bindParams(['run_unit_id' => $run_unit_id]);
            }
            $vars = $query->bindParams(['run_session_id' => $run_session_id, 'unit_id' => $unit_id])->fetch();
        }
        
        if (!empty($vars['unit_id']) && !$this->runUnit) {
			$this->runUnit = RunUnitFactory::make($this->runSession->getRun(), ['id' => $vars['unit_id']]);
        }

		if ($vars) {
			$this->assignProperties($vars);
			$this->valid = true;
		}
        
        return $this;
    }

    public function __sleep() {
        return array('id', 'session', 'unit_id', 'created');
    }

    public function execute() {
        // issue #608: time the whole execute() — including the expiration
        // check (which can itself hit OpenCPU for Pause/Branch relative_to)
        // and getUnitSessionOutput (knitting, skip logic, External R, etc.).
        // hrtime is monotonic; the finally clause records on every return path.
        $started = hrtime(true);
        try {
            $this->execResults = [];
            // Check if session has expired by getting relevant unit data
            if ($this->isExpired()) {
                $this->execResults['expired'] = true;
                $this->execResults['move_on'] = true;
                return $this->execResults;
            }

            if (!empty($this->execResults['end_session'])) {
                $this->execResults['move_on'] = true;
                return $this->execResults;
            }

            if (($output = $this->runUnit->getUnitSessionOutput($this))) {
                $this->logOutput($output);
                unset($output['log']);

                foreach ($output as $key => $value) {
                    $this->execResults[$key] = $value;
                }
            }

            return $this->execResults;
        } finally {
            $this->addExecutionTime((hrtime(true) - $started) / 1e9);
        }
    }

    /**
     * Accumulate wall-clock seconds spent executing this unit session
     * (issue #608). Units that execute repeatedly (surveys, pauses) add
     * up over their lifetime. Uses an in-place increment so concurrent or
     * sequential passes never lose time to a read-modify-write race, and
     * intentionally has no `ended IS NULL` guard so the final pass that
     * ends the session still counts.
     *
     * @param float $seconds elapsed wall-clock seconds for one pass
     */
    protected function addExecutionTime(float $seconds) {
        $delta = round($seconds, 3);
        // Skip sub-millisecond passes entirely: the stored value is rounded to
        // 3 decimals, so they would write +0.000 — one pointless UPDATE per
        // daemon tick on the hottest table (review 2026-07).
        if (empty($this->id) || $delta <= 0) {
            return;
        }
        try {
            $this->db->exec(
                "UPDATE `survey_unit_sessions`
                 SET `execution_time` = COALESCE(`execution_time`, 0) + :delta
                 WHERE `id` = :id LIMIT 1",
                ['delta' => $delta, 'id' => $this->id]
            );
            // Write-time month attribution (review 2026-07, item 7): charge
            // the run's rollup bucket for the month the work HAPPENED. The
            // lifetime execution_time above can't be split by month after the
            // fact, and attributing by us.created let long-lived sessions
            // (recheck loops, old Pauses) escape every later month's budget.
            $run_id = (int) ($this->runSession->run_id ?? ($this->runSession->run->id ?? 0));
            RunMetrics::addMonthExecution($run_id, $delta);
        } catch (Exception $e) {
            // Timing is best-effort instrumentation; never let it break
            // a participant's run because the column is missing or the
            // write failed.
            formr_log_exception($e, 'addExecutionTime');
        }
    }

    protected function isExpired() {
        $expirationData = $this->runUnit->getUnitSessionExpirationData($this);
        $this->logOutput($expirationData);
        unset($expirationData['log']);
        
        $this->execResults = array_merge($this->execResults, $expirationData);
            
        if ($this->runUnit instanceof Pause || $this->runUnit instanceof Branch) {
            if ($expirationData['check_failed'] === true || $expirationData['expire_relatively'] === false) {
                // Audit F19 (2026-07): a permanently-broken relative_to /
                // condition re-armed every 10 minutes FOREVER — hammering
                // OpenCPU and emailing the admin on every cycle. Escalate
                // the backoff by how long this session has been failing,
                // so a transient OpenCPU blip still recovers fast but a
                // genuinely broken run degrades to a few retries per day
                // (and a few admin emails per day) instead of ~144. The
                // row is never abandoned: an admin fix is picked up at the
                // next retry, and the F6 sweep still sees it.
                $extension = $this->recheckBackoffExpression();
                $expirationData['expires'] = mysql_datetime(strtotime($extension));
                $expirationData['queued'] = UnitSessionQueue::QUEUED_TO_EXECUTE;
            }
        }

        // Upstream PR #702 (Tim Seidel), companion to F23 below. A Pause/Wait
        // elapse assigns end_session and expired the SAME value (Pause.php:272),
        // and the array_merge above copied BOTH into execResults. The expired
        // copy must never reach executeUnitSession(), which tests `expired`
        // before `end_session` and would call expire() — stamping a normal
        // elapse as result='expired' with `ended` left NULL (which also fed the
        // wrong `seconds_stayed` in the user-detail export, PR #703).
        //
        // Strip the leaked copy whenever end_session is present, BEFORE the
        // return branches below. end_session is only ever set by Pause/Wait
        // (Survey and External never set it; Branch never sets expired), so
        // this is correct on every path — including the text-only / boolean-
        // true Pause that carries no `expires` and returns at the empty-expires
        // guard, which the original merge-point fix (scoped to the end_session
        // branch) did not reach. The flag stays in $expirationData: Wait's
        // getUnitSessionOutput() branches on it to tell "came back in time"
        // from "elapsed" — only the execResults copy is wrong.
        if (!empty($expirationData['end_session'])) {
            unset($this->execResults['expired']);
        }

        if (empty($expirationData['expires'])) {
            return false;
        } elseif(!empty($expirationData['end_session'])) {
            $this->execResults['end_session'] = true;
            return false; // ended NOT expired
        } elseif ($expirationData['expires'] < time()) {
            // Audit F23 (2026-07): a timer-based unit that reaches its
            // deadline should END (pause_ended/wait_ended), not EXPIRE
            // (result='expired'). expire() is for Survey/External access
            // windows. Routing an elapsed Pause/Wait deadline that
            // arrived via the recompute path (rather than the overdue
            // QUEUED_TO_END branch, which already returns end_session)
            // through expire() gave the same terminal event two different
            // labels depending on which path fired, contradicting the
            // documented per-type state machine and analysis exports.
            if ($this->runUnit instanceof Pause || $this->runUnit instanceof Wait) {
                $this->execResults['end_session'] = true;
                return false; // ended NOT expired
            }
            return true;
        } elseif ($expirationData['queued']) {
            $this->execResults['queue'] = [
                'expires' => $expirationData['expires'],
                'queued' => $expirationData['queued'],
            ];
        }
    }
    
    /**
     * Audit F19 (2026-07): escalating backoff for the Pause/Branch
     * re-check loop, keyed on how long this unit session has been alive
     * (a proxy for attempts, since each retry is ~one interval apart).
     * Configurable via unit_session.queue_expiration_extension (the fast
     * tier) and unit_session.recheck_backoff_* .
     *
     * NOTE (v1.7.1): a streak-keyed rework was attempted and reverted —
     * the streak marker was stored in `state_log`, which logResult()
     * overwrites on every failing pass (it runs via logOutput() at the
     * top of isExpired(), before this reads it), so the streak reset
     * every pass and the backoff never escalated. A correct version needs
     * the streak start in a field logResult() does not own (a new column),
     * which is a migration and out of scope for a patch release. Tracked
     * for feature/form_v2 with an integration test that drives the real
     * daemon pass rather than injecting the marker by reflection. Until
     * then this stays age-keyed, which at least escalates.
     */
    protected function recheckBackoffExpression() {
        $fast = Config::get('unit_session.queue_expiration_extension', '+10 minutes');
        $ageSeconds = $this->created ? max(0, time() - strtotime($this->created)) : 0;
        if ($ageSeconds < 3600) {                 // < 1h alive: fast recovery
            return $fast;
        }
        if ($ageSeconds < 86400) {                // < 1d alive: hourly
            return Config::get('unit_session.recheck_backoff_mid', '+1 hour');
        }
        return Config::get('unit_session.recheck_backoff_max', '+6 hours'); // capped
    }

    protected function logOutput ($output) {
        if (!empty($output['log'])) {
            $this->assignProperties($output['log']);
            $this->logResult();
        }
    }

    /**
     * Check if unit session should be queued
     * ** ALWAYS CALL AFTER $this->isExpired() ***
     *
     * @return boolean
     */
    /**
     * Audit F4 (2026-07): re-derive the expiry verdict from CURRENT state
     * before the daemon acts on a stored queue deadline. The queue's
     * `expires` is a snapshot from when the row was (re)armed; sliding
     * Survey windows (last_active + Z) move with participant activity the
     * daemon never saw. Returns:
     *   'end'      — deadline genuinely passed (or unit says end_session);
     *                proceed with endCurrentUnitSession()
     *   'requeued' — fresh deadline lies in the future; queue row re-armed
     *   'dequeued' — no deadline applies anymore ("never expires" config);
     *                stale queue row dropped
     */
    public function revalidateQueueVerdict() {
        $this->execResults = [];
        $isExpired = $this->isExpired();
        if ($isExpired || !empty($this->execResults['expired']) || !empty($this->execResults['end_session'])) {
            return 'end';
        }
        if (!empty($this->execResults['queue'])) {
            // Write directly (not via queue()'s path) so a run toggled
            // cron-inactive mid-flight still gets its row re-armed instead
            // of the daemon hot-looping on the unchanged stale deadline.
            UnitSessionQueue::addItem($this, $this->runUnit, $this->execResults['queue']);
            return 'requeued';
        }
        UnitSessionQueue::removeItem($this->id);
        return 'dequeued';
    }

    public function expire() {
        $unit = $this->runUnit;

        if ($unit->type === 'Survey') {
            $query = "UPDATE `{$unit->surveyStudy->results_table}` SET `expired` = NOW() WHERE `session_id` = :session_id AND `study_id` = :study_id AND `ended` IS null";
			$params = ['session_id' => $this->id, 'study_id' => $unit->surveyStudy->id];
			try {
				$this->db->exec($query, $params);
			} catch (Exception $e) {
				//formr_log_exception($e, 'RESULTS_TABLE: ' . $unit->surveyStudy->results_table);
			}
        }
                
        // Track A: dual-write `state = 'EXPIRED'` alongside the legacy
        // queued=0/expired=NOW() update so admin tooling and analysis
        // exports get a self-documenting state value without having to
        // reason about the absence-of-`queued` semantics. The legacy
        // columns remain authoritative for queue pickup; the `state`
        // column is additive. state_log captures the structured reason.
        $expired = $this->db->exec(
            "UPDATE `survey_unit_sessions` SET
                `expired` = NOW(),
                `result` = 'expired',
                `queued` = 0,
                `state` = :state,
                `state_log` = :state_log
             WHERE `id` = :id AND `unit_id` = :unit_id AND `ended` IS NULL LIMIT 1",
             [
                 'id'        => $this->id,
                 'unit_id'   => $unit->id,
                 'state'     => UnitSessionQueue::STATE_EXPIRED,
                 'state_log' => self::buildStateLog('expired', ['unit_type' => $unit->type]),
             ]
        );

        return $expired === 1;
    }

    public function end($reason = null) {
        $unit = $this->runUnit;

        if ($unit->type == "Survey" || $unit->type == "External") {
            if ($unit->type == "Survey") {
                $query = "UPDATE `{$unit->surveyStudy->results_table}` SET `ended` = NOW() WHERE `session_id` = :session_id AND `study_id` = :study_id AND `ended` IS null";
                $params = array('session_id' => $this->id, 'study_id' => $unit->surveyStudy->id);
                $ended_now = $this->db->exec($query, $params);

                // Write-time hook: this UPDATE flips ended NULL→now exactly once
                // (the `ended IS null` guard), so a non-zero row count is a real
                // completion. Feed the study rollup (counts + geometric-mean
                // duration, audit SQ-10/SQ-11). Self-guarded — best-effort.
                if ($ended_now) {
                    StudyMetrics::recordCompletion(
                        (int) $unit->surveyStudy->id, $this->runSession->testing ?? null,
                        $unit->surveyStudy->results_table, (int) $this->id
                    );
                }
            }
            // Honour an explicit reason from the caller (e.g. the queue's
            // run-session-ended branch passes 'ended_by_queue_rse').
            // Pre-fix Survey/External hardcoded 'survey_ended'/'external_ended'
            // and dropped the caller's reason on the floor — masking audit
            // trail. See tests/e2e/EXPIRY_PLAN.md "Hygiene 5".
            if ($reason !== null) {
                $this->result = $reason;
            } else {
                $this->result = $unit->type == "Survey" ? "survey_ended" : "external_ended";
            }
        } else {
            if ($reason !== null) {
                $this->result = $reason;
            } else if ($unit->type == "Pause") {
                $this->result = "pause_ended";
            } else if ($unit->type == "Wait") {
                $this->result = "wait_ended";
            } else if ($unit->type == "Endpage") {
                $this->result = $this->isExecutedByCron() ? 'ended_by_queue' : 'ended';
            } else {
                //$this->result = "ended_other";
            }
        }

        // Reset queued symmetrically with expire(). Pre-fix end() left
        // queued at whatever it was (typically 2), which the next
        // createUnitSession in the run-session would supersede to -9 —
        // making the audit trail ambiguous between "completed normally"
        // and "orphaned mid-flow". See "Hygiene 4" in EXPIRY_PLAN.md.
        // Track A: also set state='ENDED' (dual-write) and state_log
        // (structured sibling of the human-readable result_log).
        $this->result_log = truncate_result_log($this->result_log);
        $ended = $this->db->exec(
                "UPDATE `survey_unit_sessions` SET
                `ended` = NOW(),
                `result` = :result,
                `result_log` = :result_log,
                `queued` = 0,
                `state` = :state,
                `state_log` = :state_log
                WHERE `id` = :id AND `unit_id` = :unit_id AND `ended` IS NULL LIMIT 1",
                [
                    'id'         => $this->id,
                    'unit_id'    => $this->runUnit->id,
                    'result'     => $this->result,
                    'result_log' => $this->result_log,
                    'state'      => UnitSessionQueue::STATE_ENDED,
                    'state_log'  => self::buildStateLog($this->result, [
                        'unit_type' => $unit->type,
                        'msg'       => $this->result_log,
                    ]),
                ]
        );

        return $ended === 1;
    }

    public function queue($output = null) {
        if (!empty($this->execResults['queue'])) {
            // Audit F15 (2026-07): persist the deadline even when the run
            // is not cron-active (the old isQueuable() gate). The daemon
            // ignores these rows either way (pickup filters
            // cron_active = 1 twice), but the web path's overdue guard
            // (Pause, 80e89dcb) needs the stored expires + QUEUED_TO_END
            // to end an overdue deadline instead of re-evaluating
            // relative_to — the forever-slide otherwise survived on
            // cron-inactive runs. If the author later enables cron,
            // already-armed deadlines start being enforced, which is the
            // expected semantic.
            UnitSessionQueue::addItem($this, $this->runUnit, $this->execResults['queue']);
        }
    }

    /**
     * Persist `result` and `result_log` into the row. Track A also
     * dual-writes a structured `state_log` JSON value of the form:
     *
     *   {
     *     "reason": "<result string, e.g. survey_started, email_sent>",
     *     "ctx":    { "unit_type": "Survey", "msg": "<result_log text>" },
     *     "at":     "<ISO 8601 timestamp>"
     *   }
     *
     * Reason values mirror the `result` column. The "ctx" sub-object is
     * a per-reason free bag: handlers add unit_type, the human-readable
     * message, and any reason-specific extras. Consumers (analysis
     * exports, admin tooling) prefer state_log for structured reads and
     * fall back to result_log for legacy rows where state_log is NULL.
     * See REFACTOR_QUEUE_PLAN.md A6 / D5.
     */
    public function logResult() {
        $this->result_log = truncate_result_log($this->result_log);
        $log = $this->db->exec(
                "UPDATE `survey_unit_sessions` SET
                `result` = :result,
                `result_log` = :result_log,
                `state_log` = :state_log
                WHERE `id` = :id AND `unit_id` = :unit_id AND `ended` IS NULL LIMIT 1",
                [
                    'id'         => $this->id,
                    'unit_id'    => $this->runUnit->id,
                    'result'     => $this->result,
                    'result_log' => $this->result_log,
                    'state_log'  => self::buildStateLog($this->result, [
                        'unit_type' => $this->runUnit->type ?? null,
                        'msg'       => $this->result_log,
                    ]),
                ]
        );

        return $log;
    }

    /**
     * Build the JSON value written into the `state_log` column. Returns
     * a string so it can be passed straight into a PDO bind. Returns
     * NULL when there's no useful reason to log (caller's UPDATE then
     * sets state_log = NULL, preserving the legacy NULL semantics for
     * rows the new code chooses to skip).
     *
     * Hardened against malformed UTF-8 in `result_log` text (which can
     * arrive from OpenCPU error responses or external service callbacks
     * containing raw bytes). Without JSON_INVALID_UTF8_SUBSTITUTE,
     * json_encode would return false on a single bad byte and the
     * caller's UPDATE would fail the JSON_VALID CHECK constraint on
     * the column. The substitution writes U+FFFD in place of bad
     * bytes; the overall message still round-trips, just lossily on
     * the bad spans.
     */
    public static function buildStateLog($reason, array $ctx = []): ?string {
        if ($reason === null || $reason === '') {
            return null;
        }
        $ctx = array_filter($ctx, function ($v) {
            return $v !== null && $v !== '';
        });
        $encoded = json_encode([
            'reason' => (string) $reason,
            'ctx'    => $ctx,
            'at'     => date(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        // Last-ditch defence: if encoding still failed (e.g. recursive
        // ctx structure), write a sentinel rather than `false` so the
        // CHECK constraint passes and we lose only the offending row's
        // detail, not the row itself.
        if ($encoded === false) {
            return json_encode([
                'reason' => (string) $reason,
                'ctx'    => ['encode_error' => json_last_error_msg()],
                'at'     => date(DATE_ATOM),
            ], JSON_UNESCAPED_SLASHES);
        }
        return $encoded;
    }

    protected function hasOrderedStudyItems() {
        /** @var SurveyStudy $study */
        $study = $this->runUnit->surveyStudy;

        $nr_items = $this->db->count('survey_items', array('study_id' => $study->id), 'id');
        $nr_display_items = $this->db->count('survey_items_display', array('session_id' => $this->id), 'id');

        return $nr_display_items === $nr_items;
    }

    /**
     * Create a study record entry for this session. This is called only when
     * operating on a Survey unit
     *
     * @return boolean
     * @throws Exception
     */
    public function createSurveyStudyRecord() {
        /** @var SurveyStudy $study */
        $study = $this->runUnit->surveyStudy;
		
		if (!$this->db->entry_exists($this->table, ['id' => $this->id])) {
			formr_error(404, 'Unit Session Not Found. Please contact study author');
		}

        if (!$study->results_table || !$this->db->table_exists($study->results_table)) {
            alert('A results table for this survey could not be found', 'alert-danger');
            throw new Exception("Results table '{$study->results_table}' not found!");
        }

        $entry = array(
            'session_id' => $this->id,
            'study_id' => $study->id,
        );
        if (!$this->db->entry_exists($study->results_table, $entry)) {
            $entry['created'] = mysql_now();
            $this->db->insert($study->results_table, $entry);

            $this->result = 'survey_started';
            $this->logResult();

            // Write-time hook: keep the study response counts fresh (audit
            // SQ-11). Self-guarded — never let metrics accounting break a run.
            StudyMetrics::onSurveyStart((int) $study->id, $this->runSession->testing ?? null);
        } else {
            $this->db->update($study->results_table, array('modified' => mysql_now()), $entry);
        }

        if (!$this->hasOrderedStudyItems()) {
            // get the definition of the order
            list($item_ids, $item_types) = $study->getOrderedItemsIds();

            // define paramers to bind parameters
            $display_order = null;
            $item_id = null;
            $page = 1;
            $created = mysql_datetime();

            $values = '';
            $valuesCount = 0;
            $valuesMax = 60;
            $sql_tpl = "INSERT INTO `survey_items_display` (`item_id`, `session_id`, `display_order`, `page`, `created`)  VALUES %s ON DUPLICATE KEY UPDATE `display_order` = VALUES(`display_order`), `page` = VALUES(`page`)";
            $lastId = end($item_ids);

            foreach ($item_ids as $display_order => $item_id) {
                $values .= '(' . $item_id . ',' . $this->id . ',' . $display_order . ',' . $page . ',' . $this->db->quote($created) . '),';
                $valuesCount++;
                if (($valuesCount >= $valuesMax) || ($item_id == $lastId && $values)) {
                    $query = sprintf($sql_tpl, trim($values, ','));
                    $this->db->query($query);
                    $values = '';
                    $valuesCount = 0;
                }

                //$survey_items_display->execute();
                // set page number when submit button is hit or we reached max_items_per_page for survey
                if ($item_types[$item_id] === 'submit') {
                    $page++;
                }
            }
        }
    }

    /**
     * Save posted survey data to database
     *
     * @param array $posted An array of posted answers
     * @param bool $validate Should items be validated before posted?
     * 
     * @return boolean Returns TRUE if all data was successfully validated and saved or FALSE otherwise
     * @throws Exception
     */
    public function updateSurveyStudyRecord($posted, $validate = true) {
        // Audit F2 (2026-07): never write answers into a terminal unit
        // session. The ended-run-session branch of RunSession::execute()
        // re-dispatches the last unit session for display; a back-button
        // re-POST then reached this method and mutated submitted data
        // (the item/result UPDATEs below are scoped by session_id only,
        // with no `ended IS NULL` clause). Requires the hydrated-load
        // fix in getCurrentUnitSession — a skeletal object reads null.
        if ($this->ended !== null || $this->expired !== null) {
            return false;
        }

        // Audit F13 (2026-07): bind the POST to the unit session it was
        // rendered for. The form carries a hidden session_id
        // (SpreadsheetRenderer); in a looping/diary run a back-button
        // resubmit of iteration N's still-open form would otherwise be
        // written into iteration N+1's freshly-created session (item
        // names match, validation passes) — silently duplicating old
        // answers into the new iteration. A mismatch means the form is
        // stale; reject the write. (Legacy forms without the hidden
        // field, or non-numeric values, skip the check.) Checked before
        // touching surveyStudy so a stale POST is cheap to reject.
        if (isset($posted['session_id']) && is_numeric($posted['session_id'])
                && (int) $posted['session_id'] !== (int) $this->id) {
            return false;
        }

        /** @var SurveyStudy $study */
        $study = $this->runUnit->surveyStudy;

        // remove variables user is not allowed to overwrite (they should not be sent to user in the first place if not used in request)
        unset($posted['id'], $posted['session'], $posted['session_id'], $posted['study_id'], $posted['created'], $posted['modified'], $posted['ended']);

        if (!$posted) {
            return false;
        }

        if (isset($posted["_item_views"]["shown"])) {
            $posted["_item_views"]["shown"] = array_filter($posted["_item_views"]["shown"]);
            $posted["_item_views"]["shown_relative"] = array_filter($posted["_item_views"]["shown_relative"]);
            $posted["_item_views"]["answered"] = array_filter($posted["_item_views"]["answered"]);
            $posted["_item_views"]["answered_relative"] = array_filter($posted["_item_views"]["answered_relative"]);
        }

        /**
         * The concept of 'save all possible data' is not so correct
         * ALL data on current page must valid before any database operation or saves are made
         * This should help avoid inconsistencies or having notes and headings spread across pages
         */
        // Get items from database that are related to what is being posted
        $items = $study->getItemsWithChoices(null, array(
            'field' => 'name',
            'values' => array_keys($posted),
        ));

        // Validate items and if any fails return user to same page with all unanswered items and error messages
        // This loop also accumulates potential update data
        $update_data = array();
        foreach ($posted as $item_name => $item_value) {
            if (!isset($items[$item_name])) {
                continue;
            }

            /** @var $item Item */
            if ($item_value instanceof Item) {
                $item = $item_value;
                $item_value = $item->value_validated;
            } else {
                $item = $items[$item_name];
            }

            $validInput = ($validate && !$item->skip_validation) ? $item->validateInput($item_value) : $item_value;
            if ($item->save_in_results_table) {
                if ($item->error) {
                    $this->errors[$item_name] = $item->error;
                } else {
					$answer = $item->getReply($validInput);
					if (is_array($answer)) {
						$answer = json_encode($answer);
					}
                    $update_data[$item_name] = $answer;
                    
                    // Track uploaded files
                    if ($item instanceof File_Item) {
                        $fileInfo = $item->getFileInfo();
                        if ($fileInfo) {
                            $this->db->insert('user_uploaded_files', array_merge($fileInfo, [
                                'study_id' => $study->id,
                                'unit_session_id' => $this->id,
                                'created' => mysql_now()
                            ]));
                        }
                    }
                }
                $item->value_validated = $item_value;
                $items[$item_name] = $item;
            }
        }

        if (!empty($this->errors)) {
            $this->validatedStudyItems = $items;
            return false;
        }

        // Collect the per-item display writes and flush them in one batched
        // UPDATE after the loop instead of a round trip per posted item on the
        // hottest write path in the app (audit SQ-40). Keyed by item_id; the
        // per-column CASE below preserves exactly the old per-row semantics
        // (update-only, never insert). displaycount starts at 2 — fixme kept.
        $saved = mysql_now();
        $displayRows = array();

        try {
            $this->db->beginTransaction();

            // accumulate the item_display write for each posted item
            foreach ($posted as $name => $value) {
                if (!isset($items[$name])) {
                    continue;
                }

                /* @var $item Item */
                if ($value instanceof Item) {
                    $item = $value;
                    $value = $item->value_validated;
                } else {
                    $item = $items[$name];
                }

                if (isset($posted["_item_views"]["shown"][$item->id], $posted["_item_views"]["shown_relative"][$item->id])) {
                    $shown = $posted["_item_views"]["shown"][$item->id];
                    $shown_relative = $posted["_item_views"]["shown_relative"][$item->id];
                } else {
                    $shown = mysql_now();
                    $shown_relative = null; // and where this is null, performance.now wasn't available
                }

                if (isset($posted["_item_views"]["answered"][$item->id], // separately to "shown" because of items like "note"
                                $posted["_item_views"]["answered_relative"][$item->id])) {
                    $answered = $posted["_item_views"]["answered"][$item->id];
                    $answered_relative = $posted["_item_views"]["answered_relative"][$item->id];
                } else {
                    $answered = $shown; // this way we can identify items where JS time failed because answered and show time are exactly identical
                    $answered_relative = null;
                }

				$answer = $item->getReply($value);
				if (is_array($answer)) {
					$answer = json_encode($answer);
				}

                $displayRows[(int) $item->id] = array(
                    'answer' => $answer,
                    'shown' => $shown,
                    'shown_relative' => $shown_relative,
                    'answered' => $answered,
                    'answered_relative' => $answered_relative,
                    'hidden' => $item->skip_validation ? (int) $item->hidden : 0, // answered => must have been shown
                );
            } //endforeach

            // One batched UPDATE for every posted item's display row: a CASE per
            // column keyed on item_id reproduces the per-row values; `saved` is
            // one shared stamp; created/displaycount keep their COALESCE-preserve
            // semantics; the IN list scopes to existing rows only (never inserts),
            // matching the old per-item UPDATEs exactly.
            if ($displayRows) {
                $this->db->batchUpdateByKey('survey_items_display', 'item_id', $displayRows,
                    array('saved' => $saved),
                    array('session_id' => $this->id),
                    array('created = COALESCE(created, NOW())', 'displaycount = COALESCE(displaycount, 1)'));
            }
            // Update results table in one query
            if ($update_data) {
                $update_where = array(
                    'study_id' => $study->id,
                    'session_id' => $this->id,
                );
                $this->db->update($study->results_table, $update_data, $update_where);
            }
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            notify_user_error($e, 'An error occurred while trying to save your survey data. Please notify the author of this survey with this date and time');
            formr_log_exception($e, __CLASS__);
            // Notify study admin about failure to save posted survey data
            $message = 'Failed to save posted survey data: ' . $e->getMessage();
            notify_study_admin($this, $message, 'error');
            return false;
        }
        
    }

    public function isExecutedByCron() {
        return $this->runSession->isCron();
    }

    /**
     * Get data associated with this unit session based on query text
     *
     * @param string $q Query text to search for variables
     * @param string $required
     * @return array
     */
    public function getRunData($q, $required = null) {
        $runSession = $this->runSession;
        $cache_key = Cache::makeKey(__METHOD__, $q, $required, $this->id, $runSession->id);
        if (($data = Cache::get($cache_key))) {
            return $data;
        }

        $needed = $this->getRunDataNeeded($q, $required);
        $surveys = $needed['matches'];
        $results_tables = $needed['matches_results_tables'];
        $matches_variable_names = $needed['matches_variable_names'];
        $datasets = ['datasets' => []];

        foreach ($surveys as $study_id => $survey_name) {
            if (isset($datasets['datasets'][$survey_name])) {
                continue;
            }

            $results_table = $results_tables[$survey_name];
            $variables = [];
            if (empty($matches_variable_names[$survey_name])) {
                $variables[] = "NULL AS formr_dummy";
            } else {
                if ($results_table === "survey_unit_sessions") {
                    if (($key = array_search('position', $matches_variable_names[$survey_name])) !== false) {
                        unset($matches_variable_names[$survey_name][$key]);
                        $variables[] = 'COALESCE(`sru_own`.`position`, `sru_fallback`.`position`) AS `position`';
                    }
                    if (($key = array_search('type', $matches_variable_names[$survey_name])) !== false) {
                        unset($matches_variable_names[$survey_name][$key]);
                        $variables[] = '`survey_units`.`type`';
                    }
                }

                if (!empty($matches_variable_names[$survey_name])) {
                    foreach ($matches_variable_names[$survey_name] as $k => $v) {
                        $variables[] = DB::quoteCol($v, $results_table);
                    }
                }
            }

            $variables = implode(', ', $variables);
            $select = "SELECT $variables";

            if (($runSession->id === null || $runSession->isTestingStudy()) && !in_array($results_table, get_db_non_session_tables())) { // todo: what to do with session_id tables in faketestrun
                $where = " WHERE `$results_table`.session_id = :session_id"; // just for testing surveys
            } else {
                $where = " WHERE  `survey_run_sessions`.id = :run_session_id";
                if ($survey_name === "externals") {
                    $where .= " AND `survey_units`.`type` = 'External'";
                }
            }

            // Upstream PR #702 (Tim Seidel): every data frame handed to R must
            // come back in a deterministic chronological order. Without an
            // ORDER BY the optimizer is free to return rows grouped by unit
            // (it drives from `survey_run_units` and does a per-unit ref
            // lookup), so `tail(survey_unit_sessions$created, 1)` is not "the
            // most recent unit session" but "the newest session of whichever
            // unit sorts last" — an anchor that lags by minutes on a looping
            // ESM run and by days on a long diary. `created` is the semantic
            // key (it is what tail() is being asked for); `id` breaks ties,
            // which are real because a cascade creates several unit sessions
            // inside the same second. The sort set is one participant's
            // history, not the table, so the cost is ~0.2 ms at 2.4k rows.
            //
            // v1.7.0 no longer routes its OWN Pause/Wait anchor through here
            // (Pause.php:150 short-circuits the default relative_to to
            // $unitSession->created), so this is not the Wait-expiry fix it
            // was upstream — it is what makes study-authored tail()/head()
            // idioms in relative_to, Branch conditions, item values/showifs
            // and email bodies mean what their authors think they mean.
            $order = ' ORDER BY `survey_unit_sessions`.`created`, `survey_unit_sessions`.`id`';

            if (!in_array($results_table, get_db_non_session_tables())) {
                $joins = "
					LEFT JOIN `survey_unit_sessions` ON `$results_table`.session_id = `survey_unit_sessions`.id
					LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
				";
            } elseif ($results_table == 'survey_unit_sessions') {
                // D1 fix (v0.26.4): the old join fanned out on unit_id — for a
                // unit reused at N positions every unit-session row came back
                // N times, in undefined order, with N different `position`
                // values, and R code anchored on this data (Pause relative_to,
                // Branch conditions) computed from an arbitrary duplicate.
                // Two-alias form: `sru_own` pins post-047 rows to their own
                // placement (indexed PK lookup); `sru_fallback` fires only
                // when that misses — legacy NULL rows AND rows whose
                // placement was since deleted keep the old unit_id match, so
                // no row silently drops out of the R data. Both arms are
                // index-served (no OR in a single ON clause).
                $joins = "LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
				LEFT JOIN `survey_units` ON `survey_unit_sessions`.unit_id = `survey_units`.id
				LEFT JOIN `survey_run_units` AS `sru_own` ON `sru_own`.id = `survey_unit_sessions`.run_unit_id
				LEFT JOIN `survey_run_units` AS `sru_fallback` ON `sru_own`.id IS NULL
					AND `sru_fallback`.unit_id = `survey_unit_sessions`.unit_id
					AND `sru_fallback`.run_id = `survey_run_sessions`.run_id
				LEFT JOIN `survey_runs` ON `survey_runs`.id = COALESCE(`sru_own`.run_id, `sru_fallback`.run_id)
				";
                $where .= " AND `survey_runs`.id = :run_id";
            } elseif ($results_table == 'survey_run_sessions') {
                $joins = "";
                // No survey_unit_sessions in scope to order by (and both of
                // these resolve to a single row for the current participant).
                $order = '';
            } elseif ($results_table == 'survey_users') {
                $joins = "LEFT JOIN `survey_run_sessions` ON `survey_users`.id = `survey_run_sessions`.user_id";
                $order = '';
            }

            $select .= " FROM `$results_table` ";

            $q = $select . $joins . $where . $order . ";";

            $get_results = $this->db->prepare($q);
            if (($runSession->id === null || $runSession->isTestingStudy()) && !in_array($results_table, get_db_non_session_tables())) {
                $get_results->bindValue(':session_id', $this->id);
            } else {
                $get_results->bindValue(':run_session_id', $runSession->id);
            }
            if ($results_table == 'survey_unit_sessions') {
                $get_results->bindValue(':run_id', $this->runSession->getRun()->id);
            }
            $get_results->execute();

            $datasets['datasets'][$survey_name] = array();
            while ($res = $get_results->fetch(PDO::FETCH_ASSOC)) {
                foreach ($res AS $var => $val) {
                    if (!isset($datasets['datasets'][$survey_name][$var])) {
                        $datasets['datasets'][$survey_name][$var] = array();
                    }
                    $datasets['datasets'][$survey_name][$var][] = $val;
                }
            }
        }

        if (!empty($needed['variables'])) {
            if (in_array('formr_last_action_date', $needed['variables']) || in_array('formr_last_action_time', $needed['variables'])) {
                $datasets['.formr$last_action_date'] = "NA";
                $datasets['.formr$last_action_time'] = "NA";
                // D1 fix (v0.26.4): scope to THIS session's placement — the
                // old unit_id-only match with LIMIT 1 and no ORDER BY
                // returned an arbitrary un-ended session among all
                // placements/iterations of a reused unit. Prefer this
                // session's own run_unit_id; legacy sessions (NULL) fall
                // back to unit_id, newest first.
                $placement_where = $this->run_unit_id !== null
                    ? "(`survey_unit_sessions`.`run_unit_id` = :run_unit_id OR (`survey_unit_sessions`.`run_unit_id` IS NULL AND `unit_id` = :unit_id))"
                    : "`unit_id` = :unit_id";
                $placement_params = array('run_session_id' => $runSession->id, 'unit_id' => $this->runUnit->id);
                if ($this->run_unit_id !== null) {
                    $placement_params['run_unit_id'] = $this->run_unit_id;
                }
                $last_action = $this->db->execute(
                        "SELECT `survey_unit_sessions`.`created` FROM `survey_unit_sessions`
					LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
					WHERE `survey_run_sessions`.id  = :run_session_id AND {$placement_where} AND `survey_unit_sessions`.`ended` IS NULL
					ORDER BY `survey_unit_sessions`.`id` DESC LIMIT 1", $placement_params, true
                );
                if ($last_action !== false) {
                    $last_action_time = strtotime($last_action);
                    if (in_array('formr_last_action_date', $needed['variables'])) {
                        $datasets['.formr$last_action_date'] = "as.POSIXct('" . date("Y-m-d", $last_action_time) . "')";
                    }
                    if (in_array('formr_last_action_time', $needed['variables'])) {
                        $datasets['.formr$last_action_time'] = "as.POSIXct('" . date("Y-m-d H:i:s T", $last_action_time) . "')";
                    }
                }
            }

            if (in_array('formr_login_link', $needed['variables'])) {
                $datasets['.formr$login_link'] = "'" . run_url($runSession->getRun()->name, null, array('code' => $this->runSession->session)) . "'";
            }
            if (in_array('formr_login_code', $needed['variables'])) {
                $datasets['.formr$login_code'] = "'" . $this->runSession->session . "'";
            }
            if (in_array('user_id', $needed['variables'])) {
                $datasets['user_id'] = "'" . $this->runSession->session . "'";
            }
            if (in_array('formr_nr_of_participants', $needed['variables'])) {
                $count = (int) $this->db->count('survey_run_sessions', array('run_id' => $runSession->getRun()->id), 'id');
                $datasets['.formr$nr_of_participants'] = (int) $count;
            }
            if (in_array('formr_session_last_active', $needed['variables']) && $runSession->id) {
                $last_access = $this->db->findValue('survey_run_sessions', array('id' => $runSession->id), 'last_access');
                if ($last_access) {
                    $datasets['.formr$session_last_active'] = "as.POSIXct('" . date("Y-m-d H:i:s T", strtotime($last_access)) . "')";
                }
            }
        }

        if ($needed['token_add'] !== null && !isset($datasets['datasets'][$needed['token_add']])) {
            $datasets['datasets'][$needed['token_add']] = [];
        }

        Cache::set($cache_key, $datasets);
        return $datasets;
    }

    protected function getRunDataNeeded($q, $token_add = null) {
        $matches_variable_names = $variable_names_in_table = $matches = $matches_results_tables = $results_tables = $tables = array();

//		$results = $this->run->getAllLinkedSurveys(); // fixme -> if the last reported email thing is known to work, we can turn this on
        $surveys = $this->runSession->getRun()->getAllSurveys();

        // also add some "global" formr tables
        $nu_tables = get_db_non_user_tables();
        $non_user_tables = array_keys($nu_tables);
        $tables = $non_user_tables;
        $table_ids = $non_user_tables;
        $results_tables = array_combine($non_user_tables, $non_user_tables);
        if (isset($results_tables['externals'])) {
            $results_tables['externals'] = 'survey_unit_sessions';
        }

        if ($token_add !== null) {  // send along this table if necessary, always as the first one, since we attach it
            $study = $this->runUnit->surveyStudy;
            $table_ids[] = $study->id;
            $tables[] = $study->name;
            $results_tables[$study->name] = $study->results_table;
        }

        // map table ID to the name that the user sees (because tables in the DB are prefixed with the user ID, so they're unique)
        foreach ($surveys as $res) {
            if ($res['name'] !== $token_add) {
                $table_ids[] = $res['id'];
                $tables[] = $res['name']; // FIXME: ID can overwrite the non_user_tables
                $results_tables[$res['name']] = $res['results_table'];
            }
        }

        foreach ($tables as $index => $table_name) {
            $study_id = $table_ids[$index];

            // For preg_match, study name appears as word, matches nrow(survey), survey$item, survey[row,], but not survey_2
            if ($table_name == $token_add || preg_match("/\b$table_name\b/", (string)$q)) {
                $matches[$study_id] = $table_name;
                $matches_results_tables[$table_name] = $results_tables[$table_name];
            }
        }

        // loop through any studies that are mentioned in the command
        foreach ($matches as $study_id => $table_name) {

            // generate a search set of variable names for each study
            if (array_key_exists($table_name, $nu_tables)) {
                $variable_names_in_table[$table_name] = $nu_tables[$table_name];
            } else {
                $items = $this->db->select('name')->from('survey_items')
                        ->where(['study_id' => $study_id])
                        ->where("type NOT IN ('mc_heading', 'note', 'submit', 'block', 'note_iframe')")
                        ->where("name != 'iteration'")
                        ->fetchAll();

                $variable_names_in_table[$table_name] = array("created", "modified", "ended"); // should avoid modified, sucks for caching
                $res_table = $results_tables[$table_name];
                $has_iter = $this->db->prepare("DESCRIBE `$res_table` `iteration`");
                $has_iter->execute();
                if($has_iter->fetch(PDO::FETCH_ASSOC) !== false) {
                    $variable_names_in_table[$table_name][] = "iteration";
                }
        
                foreach ($items as $res) {
                    $variable_names_in_table[$table_name][] = $res['name']; // search set for user defined tables
                }
            }

            $matches_variable_names[$table_name] = array();
            // generate match list for variable names
            foreach ($variable_names_in_table[$table_name] as $variable_name) {
                // try to match scales too, extraversion_1 + extraversion_2 - extraversion_3R - extraversion_4r = extraversion (script might mention the construct name, but not its item constituents)
                $variable_name_base = preg_replace("/_?[0-9]{1,3}R?$/i", "", $variable_name);
                // don't match very short variable name bases
                if (strlen($variable_name_base) < 3) {
                    $variable_name_base = $variable_name;
                }
                // item name appears as word, matches survey$item, survey[, "item"], but not item_2 for item-scale unfortunately
                if (preg_match("/\b$variable_name\b/", $q) || preg_match("/\b$variable_name_base\b/", $q)) {
                    $matches_variable_names[$table_name][] = $variable_name;
                }
            }
        }

        $variables = opencpu_formr_variables($q);

        return compact("matches", "matches_results_tables", "matches_variable_names", "token_add", "variables");
    }

}
