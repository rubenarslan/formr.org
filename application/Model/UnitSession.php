<?php

class UnitSession extends Model {

    public $id;
    public $unit_id; // survey_units.id
    public $run_unit_id; // survey_run_units.id — Track A: disambiguates same-unit-at-multiple-positions
    public $iteration; // 1-based count of attempts at THIS run_unit_id by THIS run_session
    public $study_iteration; // per-study sequence (wide-table-equivalent "Nth participant to start this study"); allocated via survey_studies.last_iteration
    public $layout; // paradata: the study layout mode ('default'/'solo') this response was collected under (patch 093)
    public $run_session_id;
    public $created;
    public $expires;
    public $queued = 0;
    public $result;
    public $result_log;
    public $ended;
    public $expired;
    public $state; // ENUM (PENDING, RUNNING, WAITING_USER, WAITING_TIMER, ENDED, EXPIRED, SUPERSEDED)

    /**
     * `result` values end() records when an OPERATOR ended a Survey/Form
     * unit session (`$completes = false`: RunSession::forceTo writes
     * 'manual_admin_push', RunSession::forceMoveOn defaults to 'moved').
     * The v1.8.0 "force-move is not a completion" rule leaves the wide
     * results row's `ended` NULL for these while survey_unit_sessions.ended
     * is still stamped — so the long read (survey_items_display +
     * survey_unit_sessions) has to reconstruct that distinction from
     * `result`, via completedEndedSql(). Keep this list in step with every
     * `end($reason, false)` call site.
     */
    const OPERATOR_END_RESULTS = array('manual_admin_push', 'moved');

    /**
     * SQL expression for a unit session's `ended` with v1 completion
     * semantics: NULL unless the participant (or the queue / expiry) ended
     * the survey. `!is.na(survey$ended)` in R and the admin "complete"
     * filter are TRUE only for real completions, exactly as the wide
     * results row's `ended` is.
     *
     * @param string $alias survey_unit_sessions alias in the query
     * @return string
     */
    public static function completedEndedSql($alias = 'us') {
        $in = implode(', ', array_map(function ($r) {
            return "'" . str_replace("'", "''", $r) . "'";
        }, self::OPERATOR_END_RESULTS));
        return "CASE WHEN `{$alias}`.`result` IN ({$in}) THEN NULL ELSE `{$alias}`.`ended` END";
    }

    /**
     * The `result` this request's logResult() wrote, or null if this request
     * logged nothing. NOT a column — load() hydrates `result`/`result_log`
     * from an EARLIER pass, and end() must be able to tell the two apart.
     */
    protected $loggedResultThisPass = null;
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
        $columns = 'id, unit_id, run_unit_id, iteration, run_session_id, created, expires, queued, result, result_log, ended, expired, state, idempotency_key';
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

            // Hydrated from a session row whose unit is no longer placed in
            // this run (the admin swapped the study behind this placement,
            // or a pre-F22 orphan): the constructor's run-scoped find()
            // failed and forgetIdentity() left a unit with no id and — for a
            // Survey — no study, so getUnitSessionOutput() would dereference
            // null. Treat it like a removed placement (audit F22): end this
            // unit session and let the run session move on to whatever now
            // sits at this position.
            if ($this->runUnit && $this->runUnit->id === null) {
                $this->execResults['end_session'] = true;
                $this->execResults['end_reason'] = 'unit_removed_from_run';
                $this->execResults['move_on'] = true;
                return $this->execResults;
            }

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
     * Public because RCallService meters the form_v2 reactive-R endpoints
     * (form-render-page) into the same column —
     * participant-driven OpenCPU load must count against the owner's
     * compute budget like unit execution does.
     *
     * @param float $seconds elapsed wall-clock seconds for one pass
     */
    public function addExecutionTime(float $seconds) {
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
            // The recompute produced no LIVE deadline. For a Survey this is
            // where the client/server expiry mismatch was born (v1.10.3): the
            // stored `expires` column still held a PAST deadline, head.php
            // rendered it into window.unit_session_expires, the ExpiryNotifier
            // showed "Session expired / reload", and the reload re-ran exactly
            // this branch — returning false, so the server re-served the same
            // page without ever ending it, forever.
            //
            // The common cause is a survey with a START window but no
            // post-access deadline (expire_invitation_after > 0, grace = 0,
            // expire_after = 0 → "X minutes to START, then no limit"): the
            // moment the participant starts, the PRE-access deadline stops
            // applying (the wiki's "Y=0 & Z=0 ⇒ never expires" post-access),
            // but the stored pre-access `expires` lingers on the row. Expiry
            // being switched off entirely lands here too.
            //
            // Reconcile at this single point, which BOTH the participant render
            // (execute()) and the daemon (revalidateQueueVerdict()) reach:
            // clear the stale stored `expires` so nothing renders a past
            // deadline the server will not enforce — the modal can no longer
            // outlive the row. Do NOT expire on the stale value: the
            // participant started within their window and is entitled to
            // finish; ending them here would discard an in-progress response
            // they were allowed to complete. A study that wants in-progress
            // sessions to time out must configure a post-access window (grace
            // or expire_after); X alone only gates STARTING. Scoped to Survey;
            // Pause/Wait/External keep their own handling.
            if ($this->runUnit instanceof Survey && $this->expires !== null) {
                $this->db->exec(
                    "UPDATE `survey_unit_sessions` SET `expires` = NULL
                     WHERE `id` = :id AND `ended` IS NULL AND `expired` IS NULL",
                    ['id' => $this->id]
                );
                $this->expires = null;
            }
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
     * NOTE: a streak-keyed rework was attempted in v1.7.1 and reverted. It
     * stored the streak marker in `state_log`, which logResult() overwrote
     * on every failing pass (it runs via logOutput() at the top of
     * isExpired(), before this reads it), so the streak reset every pass and
     * the backoff never escalated. v1.8.0 dropped `state_log` outright
     * (patch 073) — it had no reader and duplicated the row — so that avenue
     * is closed for good, which is the right outcome: a value that must
     * survive across passes cannot live in a column every pass rewrites. A
     * correct version needs its own column, holding the first-failure
     * timestamp. That would also close the audit finding that a retrying
     * unit loses the ORIGINAL error text (the actionable one) to the latest,
     * with no record of when the failure started. Until then this stays
     * age-keyed, which at least escalates. See
     * documentation/agent_doc/LOG_STATE_AUDIT.md (M3).
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
        // Same orphan execute() ends as unit_removed_from_run: the unit is
        // no longer placed in the run, so there is no deadline to recompute
        // (Survey's would dereference the nulled surveyStudy). End it so the
        // run session moves on, instead of 'dequeued' leaving it open forever.
        if ($this->runUnit && $this->runUnit->id === null) {
            return 'end';
        }
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

        // No live deadline. isExpired() (the shared reconciliation point) has
        // already handled a Survey whose armed deadline is past — it returns
        // true there, so we took the 'end' branch above — and has cleared a
        // stale stored `expires` for the no-deadline case. Nothing left to do
        // but drop the queue row.
        UnitSessionQueue::removeItem($this->id);
        return 'dequeued';
    }

    public function expire() {
        $unit = $this->runUnit;

        // 'Form' too: v2 Form units dual-write the same wide results table via
        // createSurveyStudyRecord, so their rows need the expired stamp as well —
        // gating on 'Survey' alone left every Form row expired=NULL forever.
        // surveyStudy: see end() — no study once identity is forgotten.
        if (in_array($unit->type, ['Survey', 'Form'], true) && $unit->surveyStudy && Config::get('form_v2_dual_write_results', true)) {
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
        // column is additive.
        $expired = $this->db->exec(
            "UPDATE `survey_unit_sessions` SET
                `expired` = NOW(),
                `result` = 'expired',
                `queued` = 0,
                `state` = :state
             WHERE `id` = :id AND `unit_id` = :unit_id AND `ended` IS NULL LIMIT 1",
             [
                 'id'        => $this->id,
                 'unit_id'   => $unit->id ?? $this->unit_id,
                 'state'     => UnitSessionQueue::STATE_EXPIRED,
             ]
        );

        return $expired === 1;
    }

    /**
     * @param string|null $reason  explicit terminal result, wins over the type default
     * @param bool $completes      false when an OPERATOR ended this, not the participant
     */
    public function end($reason = null, $completes = true) {
        $unit = $this->runUnit;

        if ($unit->type == "Survey" || $unit->type == "Form" || $unit->type == "External") {
            // 'Form' included on both levels: v2 Forms dual-write the wide table
            // (see expire()) and end like a Survey. They also record
            // 'survey_ended' — not a Form-specific value — so downstream
            // analyses filtering result='survey_ended' keep counting
            // completions across a v1→v2 study upgrade.
            //
            // Audit X1 (v1.8.0): an admin force-move is not a completion.
            // The UPDATE below flips the results row's `ended` from NULL to
            // now, and `ended IS NOT NULL` is the standard completion filter in
            // analysis — so an admin using "send to position" on someone parked
            // mid-survey manufactured a row indistinguishable from a real
            // completion, and recordCompletion() folded its duration into the
            // study's geometric mean. RunSession::positionSessions() does it in
            // bulk. The rollup is an accumulator, so the skew was permanent and
            // silent, and it reached published numbers.
            //
            // $completes is false for the two operator paths (forceTo,
            // forceMoveOn). Everything else — participants finishing, the
            // queue, expiry — is unaffected.
            // A unit hydrated from a session row whose study is no longer
            // placed in the run (the constructor's find() failed and
            // forgetIdentity() nulled surveyStudy) has no results table to
            // stamp: the study behind the placement changed, so there is
            // nothing of this participant's to complete. Without the guard
            // the UPDATE targets an empty table name and the PDOException
            // escapes (no catch here, unlike expire()) — a 500 on every
            // participant request, and the daemon re-picks the queued row
            // on every pass because removeItem() is never reached.
            if (($unit->type == "Survey" || $unit->type == "Form") && $completes && $unit->surveyStudy && Config::get('form_v2_dual_write_results', true)) {
                $query = "UPDATE `{$unit->surveyStudy->results_table}` SET `ended` = NOW() WHERE `session_id` = :session_id AND `study_id` = :study_id AND `ended` IS null";
                $params = array('session_id' => $this->id, 'study_id' => $unit->surveyStudy->id);
                $this->db->exec($query, $params);
                // The completion rollup (StudyMetrics::recordCompletion) is fed
                // below, after the survey_unit_sessions UPDATE, so it also runs
                // with dual-write off (F5) — it no longer depends on the wide row.
            }
            // Honour an explicit reason from the caller (e.g. the queue's
            // run-session-ended branch passes 'ended_by_queue_rse').
            // Pre-fix Survey/External hardcoded 'survey_ended'/'external_ended'
            // and dropped the caller's reason on the floor — masking audit
            // trail. See tests/e2e/EXPIRY_PLAN.md "Hygiene 5".
            // Form deliberately shares "survey_ended" (see the comment above);
            // only External diverges.
            $this->resolveEndResult($reason, $unit->type == "External" ? "external_ended" : "survey_ended");

            // F4: with long reads the wide `ended` column no longer exists to
            // consult, so completedEndedSql() reconstructs "was this a real
            // completion?" from `result`: operator results (OPERATOR_END_RESULTS)
            // read as NULL ended. That only holds if every !$completes caller
            // records one of those results. A new operator path with a fresh
            // reason would silently count as a completion in R / exports —
            // make that loud rather than let it drift.
            if (!$completes && ($unit->type == "Survey" || $unit->type == "Form")
                    && !in_array($this->result, self::OPERATOR_END_RESULTS, true)) {
                formr_log(sprintf(
                    'UnitSession::end(): session %d ended with completes=false but result "%s" is not in OPERATOR_END_RESULTS (%s); long reads will treat it as a completion. Add the result to UnitSession::OPERATOR_END_RESULTS.',
                    (int) $this->id, (string) $this->result, implode(', ', self::OPERATOR_END_RESULTS)
                ), 'WARNING');
            }
        } else {
            if ($unit->type == "Pause") {
                $default = "pause_ended";
            } else if ($unit->type == "Wait") {
                $default = "wait_ended";
            } else if ($unit->type == "Endpage") {
                $default = $this->isExecutedByCron() ? 'ended_by_queue' : 'ended';
            } else {
                // Email, PushMessage, Shuffle, Branch/Skip: no type default —
                // whatever the unit's own log recorded stands.
                $default = null;
            }
            $this->resolveEndResult($reason, $default);
        }

        // Reset queued symmetrically with expire(). Pre-fix end() left
        // queued at whatever it was (typically 2), which the next
        // createUnitSession in the run-session would supersede to -9 —
        // making the audit trail ambiguous between "completed normally"
        // and "orphaned mid-flow". See "Hygiene 4" in EXPIRY_PLAN.md.
        // Track A: also set state='ENDED' (dual-write).
        $this->result_log = truncate_result_log($this->result_log);
        $ended = $this->db->exec(
                "UPDATE `survey_unit_sessions` SET
                `ended` = NOW(),
                `result` = :result,
                `result_log` = :result_log,
                `queued` = 0,
                `state` = :state
                WHERE `id` = :id AND `unit_id` = :unit_id AND `ended` IS NULL LIMIT 1",
                [
                    'id'         => $this->id,
                    'unit_id'    => $this->runUnit->id ?? $this->unit_id,
                    'result'     => $this->result,
                    'result_log' => $this->result_log,
                    'state'      => UnitSessionQueue::STATE_ENDED,
                ]
        );

        // Write-time hook (F5): the UPDATE above flips survey_unit_sessions.ended
        // NULL→now exactly once (the `ended IS NULL` guard), so $ended === 1 is a
        // real, first-time completion of a Survey/Form when $completes is true
        // (operator force-moves pass false and are not completions, audit X1).
        // Lives outside the form_v2_dual_write_results gate: the rollup
        // (counts + geometric-mean duration, audit SQ-10/SQ-11) must keep being
        // fed when the wide table is no longer written. Self-guarded. No
        // surveyStudy: see the dual-write block above (identity forgotten).
        if ($ended === 1 && $completes && ($unit->type == "Survey" || $unit->type == "Form") && $unit->surveyStudy) {
            StudyMetrics::recordCompletion(
                (int) $unit->surveyStudy->id, $this->runSession->testing ?? null, (int) $this->id
            );
        }

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
     * Decide the terminal `result` for end(), and keep `result_log` matched
     * to it.
     *
     * Precedence: an explicit caller reason, then whatever this pass's own
     * log recorded, then the type default. The middle arm is the v1.8.0 fix:
     * end() used to re-derive the result from the unit TYPE alone, so a
     * Survey that logged `survey_completed`, an External that logged
     * `external_r_call_no_redirect` and a Pause that logged
     * `error_pause_relative_to` all had their own verdict replaced by
     * `survey_ended` / `external_ended` / `pause_ended`. Distinct outcomes
     * collapsed into one label — which is why an admin force-push was
     * indistinguishable from a genuine survey completion. Wait's `end_reason`
     * lever solved this for one unit; this generalises it, and units that
     * record nothing keep the type default exactly as before.
     *
     * The log is cleared whenever the result being written did NOT come from
     * this pass. `result_log` is hydrated by load() out of an earlier pass and
     * end() re-writes it verbatim — that is how a Pause ended up reading
     * `pause_ended` next to an OpenCPU traceback, and a skip `moved` next to
     * an R error. A label describing one event beside text describing another
     * is worse than no text: it sends whoever reads it after the wrong bug.
     *
     * @param string|null $reason  explicit caller reason, wins outright
     * @param string|null $default type-derived fallback, null = leave as-is
     */
    protected function resolveEndResult($reason, $default) {
        if ($reason !== null) {
            $this->result = $reason;
        } elseif ($this->loggedResultThisPass !== null) {
            $this->result = $this->loggedResultThisPass;
        } elseif ($default !== null) {
            $this->result = $default;
        }

        if ($this->result !== $this->loggedResultThisPass) {
            // The pairing rule discards a log that describes a different
            // result — correct for the row, but that text can be the only
            // record of WHY a participant was stuck (an OpenCPU traceback,
            // an SMTP error) at the exact moment an operator or the queue
            // force-ends the session (`manual_admin_push`, `moved`,
            // `ended_by_queue_rse`). Park it in the application log before
            // it goes, so the post-hoc investigation still has something.
            if ((string) $this->result_log !== '') {
                formr_log("UnitSession {$this->id}: result_log superseded by result '{$this->result}': {$this->result_log}", 'result_log_superseded');
            }
            $this->result_log = null;
        }
    }

    /**
     * Persist `result` and `result_log` into the row.
     *
     * The two columns are a MATCHED PAIR: `result_log` must always describe
     * the `result` written beside it. Every writer therefore sets both, and
     * `end()` clears the log when it falls back to a type default rather than
     * leaving an earlier pass's text under a new label.
     *
     * v1.8.0 dropped the `state_log` JSON sibling this method used to
     * dual-write. It carried nothing the row did not already hold — `reason`
     * was derived from `result`, `ctx.unit_type` from the unit, `ctx.msg` was
     * a copy of `result_log` — and had no reader outside its own smoke test:
     * no admin template selected it and the v1 API projected `state` but not
     * `state_log`. It also actively misled the reverted streak-marker work,
     * which stored its marker in a column this method overwrites wholesale
     * every pass. `state` (the ENUM) is kept; it does have readers.
     */
    public function logResult() {
        $this->result_log = truncate_result_log($this->result_log);
        // v1.8.0: remember what THIS pass recorded so end() can tell the
        // unit's own verdict from a value hydrated by load() out of an
        // earlier pass. See resolveEndResult().
        $this->loggedResultThisPass = $this->result;
        $log = $this->db->exec(
                "UPDATE `survey_unit_sessions` SET
                `result` = :result,
                `result_log` = :result_log
                WHERE `id` = :id AND `unit_id` = :unit_id AND `ended` IS NULL LIMIT 1",
                [
                    'id'         => $this->id,
                    'unit_id'    => $this->runUnit->id ?? $this->unit_id,
                    'result'     => $this->result,
                    'result_log' => $this->result_log,
                ]
        );

        return $log;
    }

    /**
     * Does this session already have a survey_items_display row for every
     * LIVE item of the study?
     *
     * Both counts use the same predicate as SurveyStudy::getOrderedItemsIds
     * (`deleted IS NULL`, patch 094). Counting every survey_items row on one
     * side and every display row on the other made the two disagree forever
     * after any soft-delete — a deleted item still has its survey_items row
     * (so it was counted as an item) and its display rows survive (so an
     * older session counted it too, a newer one didn't) — and
     * createSurveyStudyRecord then re-ran the ordering on every request,
     * re-shuffling randomised blocks mid-session.
     *
     * Display rows are counted through survey_items so rows belonging to a
     * since-deleted item are excluded on that side as well; otherwise an
     * old session (10 rows, one item since deleted) would never match the 9
     * live items.
     */
    protected function hasOrderedStudyItems() {
        /** @var SurveyStudy $study */
        $study = $this->runUnit->surveyStudy;

        $nr_items = (int) $this->db->count('survey_items', '`study_id` = ' . (int) $study->id . ' AND `deleted` IS NULL', 'id');
        $nr_display_items = (int) $this->db->execute(
            'SELECT COUNT(*) FROM `survey_items_display` `sid`
             INNER JOIN `survey_items` `i` ON `i`.`id` = `sid`.`item_id`
             WHERE `sid`.`session_id` = :session_id AND `i`.`study_id` = :study_id AND `i`.`deleted` IS NULL',
            array('session_id' => $this->id, 'study_id' => $study->id),
            true
        );

        return $nr_display_items === $nr_items;
    }

    /**
     * Atomically allocate the next per-study iteration value.
     *
     * Uses MySQL's LAST_INSERT_ID(value) trick: the UPDATE locks the
     * survey_studies row, writes value + 1 into last_iteration, and
     * stashes the new value in LAST_INSERT_ID(). A subsequent SELECT
     * LAST_INSERT_ID() in the same connection returns it. Concurrent
     * allocations serialize on the row lock without explicit BEGIN /
     * COMMIT bookkeeping; counter gaps from rolled-back inserts are
     * tolerated (same semantic as AUTO_INCREMENT).
     *
     * @param int $studyId
     * @return int the new iteration value
     */
    public function allocateStudyIteration($studyId) {
        $this->db->exec(
            'UPDATE `survey_studies` SET `last_iteration` = LAST_INSERT_ID(`last_iteration` + 1) WHERE `id` = :study_id',
            ['study_id' => (int) $studyId]
        );
        // DB::query() returns fetchAll() rows for SELECTs (not a PDOStatement),
        // so index into the result array directly.
        $rows = $this->db->query('SELECT LAST_INSERT_ID() AS iter');
        return isset($rows[0]['iter']) ? (int) $rows[0]['iter'] : 0;
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

        $dualWrite = (bool) Config::get('form_v2_dual_write_results', true);

        if ($dualWrite && (!$study->results_table || !$this->db->table_exists($study->results_table))) {
            alert('A results table for this survey could not be found', 'alert-danger');
            throw new Exception("Results table '{$study->results_table}' not found!");
        }

        // Allocate per-study iteration on first visit. Atomic single-
        // statement increment via LAST_INSERT_ID(); concurrent allocations
        // serialize on the survey_studies row lock. Idempotent: if
        // study_iteration is already set on this session row, skip the
        // bump so re-renders of an in-progress survey don't burn counter
        // values.
        $alreadyAllocated = (int) $this->db->findValue(
            'survey_unit_sessions',
            ['id' => $this->id],
            'study_iteration'
        );

        // Legacy-host guard (F2): wide tables created before 2024-07 (commit
        // 89383543) have no `iteration` column. SurveyStudy owns the probe
        // (cached per request) so every writer below asks the same question
        // once instead of guessing from an error message.
        $wideHasIteration = $dualWrite && $study->resultsTableHasIteration();

        // A session that was already under way when patch 090 arrived has no
        // study_iteration yet but does have its wide row, numbered by the
        // table's AUTO_INCREMENT. Adopt that number: allocating a fresh one
        // would give the session two different iterations (the wide row's and
        // survey_unit_sessions'), and bin/backfill_study_iteration.php only
        // fills NULLs, so it would never be reconciled.
        if (!$alreadyAllocated && $wideHasIteration) {
            $wideIteration = (int) $this->db->findValue(
                $study->results_table,
                array('session_id' => $this->id, 'study_id' => $study->id),
                'iteration'
            );
            if ($wideIteration > 0) {
                $this->db->update('survey_unit_sessions',
                    array('study_iteration' => $wideIteration),
                    array('id' => $this->id));
                $alreadyAllocated = $wideIteration;
            }
        }

        $sessionStarted = false;
        if (!$alreadyAllocated) {
            // Lazy seed (F2): survey_studies.last_iteration (patch 090) starts at
            // 0 on hosts that pre-date the allocator, while the wide table's
            // AUTO_INCREMENT `iteration` may already be in the thousands. Seed
            // the counter from MAX(wide.iteration) exactly once, on the first
            // allocation, so allocated values never collide with historic rows.
            // Guarded by `last_iteration = 0` in the WHERE so a concurrent first
            // allocation can't double-seed; a table without the column has no
            // history to seed from.
            if ($wideHasIteration && (int) $study->last_iteration === 0) {
                $this->db->exec(
                    "UPDATE `survey_studies`
                     SET `last_iteration` = (SELECT COALESCE(MAX(`iteration`), 0) FROM `{$study->results_table}`)
                     WHERE `id` = :id AND `last_iteration` = 0",
                    array('id' => $study->id)
                );
            }
            $iteration = $this->allocateStudyIteration($study->id);
            // Stamp the layout mode this response is collected under (paradata
            // for measurement equivalence; patch 093). Captured at first render
            // so a later flip of the study's layout doesn't rewrite history.
            $this->db->update('survey_unit_sessions',
                ['study_iteration' => $iteration, 'layout' => $study->layout],
                ['id' => $this->id]);
            $this->study_iteration = $iteration;
            $sessionStarted = true;
        } else {
            $iteration = $alreadyAllocated;
            $this->study_iteration = $iteration;
        }

        if ($dualWrite) {
            $entry = array(
                'session_id' => $this->id,
                'study_id' => $study->id,
            );
            if (!$this->db->entry_exists($study->results_table, $entry)) {
                $entry['created'] = mysql_now();
                // Pass the allocated value so wide.iteration stays aligned
                // with survey_unit_sessions.study_iteration. The wide
                // table's AUTO_INCREMENT bumps its internal counter to
                // our value if higher, leaving subsequent inserts
                // consistent. Legacy wide tables without the column (F2) get
                // the row without it; survey_unit_sessions.study_iteration is
                // then the only iteration and the long path reads it there.
                if ($wideHasIteration) {
                    $entry['iteration'] = $iteration;
                }
                try {
                    $this->db->insert($study->results_table, $entry);
                } catch (Exception $e) {
                    if (!$wideHasIteration || strpos($e->getMessage(), "for key 'iteration'") === false) {
                        throw $e;
                    }
                    // survey_studies.last_iteration lags the wide table:
                    // rows were seeded outside the allocator (e.g.
                    // bin/import-results.php inserts via AUTO_INCREMENT and
                    // never bumps the counter), so our allocated value
                    // collides. Self-heal: resync the counter to the wide
                    // max, re-allocate, restamp the session, retry once.
                    $this->db->exec(
                        "UPDATE `survey_studies`
                         SET `last_iteration` = GREATEST(`last_iteration`, (SELECT COALESCE(MAX(`iteration`), 0) FROM `{$study->results_table}`))
                         WHERE `id` = :id",
                        array('id' => $study->id)
                    );
                    $iteration = $this->allocateStudyIteration($study->id);
                    $this->db->update('survey_unit_sessions',
                        array('study_iteration' => $iteration),
                        array('id' => $this->id));
                    $this->study_iteration = $iteration;
                    $entry['iteration'] = $iteration;
                    $this->db->insert($study->results_table, $entry);
                }

                if ($sessionStarted) {
                    $this->result = 'survey_started';
                    $this->logResult();
                    // Write-time hook (audit SQ-11): form_v2's dual-write path
                    // creates a wide row, so real survey starts also arrive via
                    // this branch — count them here too, not only in the
                    // no-wide-row elseif below. Self-guarded.
                    StudyMetrics::onSurveyStart((int) $study->id, $this->runSession->testing ?? null);
                }
            } else {
                $this->db->update($study->results_table, array('modified' => mysql_now()), $entry);
            }
        } elseif ($sessionStarted) {
            // No wide row to gate on, but still log the lifecycle event.
            $this->result = 'survey_started';
            $this->logResult();

            // Write-time hook: keep the study response counts fresh (audit
            // SQ-11). Self-guarded — never let metrics accounting break a run.
            StudyMetrics::onSurveyStart((int) $study->id, $this->runSession->testing ?? null);
        }
        // No trailing else: $entry is built only inside the `if ($dualWrite)` arm
        // above, so with dual-write off (form_v2_dual_write_results=false) there is
        // no wide row and no $entry — the !dualWrite && !sessionStarted case is a
        // no-op. (A prior `else` bumped $study->results_table with an undefined
        // $entry here → TypeError on the array $where arg in long-only mode.)

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
            // Insert-missing-only (F1). The UNIQUE key `session_item_views`
            // (session_id, item_id) makes the ON DUPLICATE KEY clause fire for
            // rows that already exist; historically it overwrote display_order
            // and page with the freshly computed ordering. Because
            // getOrderedItemsIds() randomises blocks and breaks ties at random,
            // every re-run re-shuffled the already-shown items of a session.
            // The self-assignment is a documented MySQL/MariaDB no-op that keeps
            // the statement a single upsert (no per-row existence probe) while
            // leaving existing rows' order and page untouched. An item added to
            // a study mid-session therefore only gets its own row appended.
            $sql_tpl = "INSERT INTO `survey_items_display` (`item_id`, `session_id`, `display_order`, `page`, `created`)  VALUES %s ON DUPLICATE KEY UPDATE `display_order` = `display_order`";
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
     * form_v2: the required items this session has not answered and that the
     * browser has not recorded as hidden — what stands between the participant
     * and the end of the form. The only completion rule for a v2 Form (the
     * final page submit, FormSubmissionService::apply); a GET never completes
     * one. Which items are required is FormSubmissionService::isRequired, the
     * same rule the browser's state block uses.
     *
     * @return array<string, Item> name => item
     */
    public function unansweredRequiredItems() {
        $study = $this->runUnit ? $this->runUnit->surveyStudy : null;
        if (!$study) {
            return array();
        }
        // The state block's rows (FormSubmissionService::stateRows), so the
        // server can only name an item the browser's store knows.
        $factory = new ItemFactory(array());
        $out = array();
        foreach (FormSubmissionService::stateRows($this->db, $study->id, $this->id) as $row) {
            if ($row['answer'] !== null || (int) $row['hidden'] === 1) {
                continue;
            }
            unset($row['answer'], $row['page'], $row['hidden'], $row['saved']);
            $item = $factory->make($row);
            if ($item && FormSubmissionService::isRequired($item) && !$item->formV2Unsupported()) {
                $out[(string) $row['name']] = $item;
            }
        }
        return $out;
    }

    /** Has this unit session already stored a passed check for this bot_check item? */
    private function botCheckPassed(Item $item) {
        $row = $this->db->execute(
            'SELECT answer FROM `survey_items_display` WHERE session_id = :s AND item_id = :i',
            array('s' => (int) $this->id, 'i' => (int) $item->id), false, true
        );
        return $row && $row['answer'] === 'verified';
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
    public function updateSurveyStudyRecord($posted, $validate = true, $quiet = false) {
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

        // One write, one verdict: a validation failure from an earlier call on
        // this object (the smokes drive several pages through one UnitSession)
        // must not veto this one.
        $this->errors = array();

        /** @var SurveyStudy $study */
        $study = $this->runUnit->surveyStudy;

        // remove variables user is not allowed to overwrite (they should not be sent to user in the first place if not used in request)
        unset($posted['id'], $posted['session'], $posted['session_id'], $posted['study_id'], $posted['created'], $posted['modified'], $posted['ended']);

        if (!$posted) {
            return false;
        }

        // The four buckets come as one set from a v1 form, but form_v2 builds
        // them in the browser (JSON, or multipart where an empty bucket posts
        // no field at all): a missing or non-array bucket is simply empty,
        // never a TypeError from array_filter — a 500 the browser would
        // queue and retry for good.
        if (isset($posted["_item_views"]["shown"]) && is_array($posted["_item_views"])) {
            foreach (array('shown', 'shown_relative', 'answered', 'answered_relative') as $bucket) {
                $posted["_item_views"][$bucket] = (isset($posted["_item_views"][$bucket]) && is_array($posted["_item_views"][$bucket]))
                    ? array_filter($posted["_item_views"][$bucket]) : array();
            }
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
        // (On a v2 study the items come with formV2 set: getItemsWithChoices.)

        // Validate items and if any fails return user to same page with all unanswered items and error messages
        // This loop also accumulates potential update data
        $update_data = array();
        $consuming = array();
        foreach ($posted as $item_name => $item_value) {
            if (!isset($items[$item_name])) {
                continue;
            }
            // A bot_check's validation BURNS its one-shot token. Judge it only
            // once everything else posted has passed: an unrelated error on the
            // same page would otherwise spend the token, and the participant's
            // corrected resubmit — widget still showing "verified" — would be
            // refused as unverified.
            if ($validate && !($item_value instanceof Item) && $items[$item_name] instanceof BotCheck_Item) {
                // Passed once in this unit session: a resend of its page (the
                // retry of a submit whose reply was lost or that failed after
                // storing, a queued copy) is not asked to verify again — the
                // one-shot token was spent the first time, and the widget
                // still shows "verified", so it would be refused forever.
                if ($this->botCheckPassed($items[$item_name])) {
                    // The display row is written from $posted
                    // (writeSurveyStudyRecord → getReply): hand it the stored
                    // marker, or the unvalidated token would store NULL over
                    // 'verified'.
                    $posted[$item_name] = 'verified';
                    continue;
                }
                $consuming[$item_name] = $item_value;
                continue;
            }
            $this->validatePostedItem($item_name, $item_value, $items, $update_data, $validate, $study);
        }
        if (empty($this->errors)) {
            foreach ($consuming as $item_name => $item_value) {
                $this->validatePostedItem($item_name, $item_value, $items, $update_data, $validate, $study);
            }
        }

        if (!empty($this->errors)) {
            $this->validatedStudyItems = $items;
            return false;
        }

        return $this->writeSurveyStudyRecord($posted, $items, $update_data, $study, $quiet);
    }

    /**
     * Validate one posted answer (updateSurveyStudyRecord's first pass): on
     * success its reply lands in $update_data, on failure its error in
     * $this->errors; $items[$item_name] carries the item with value_validated
     * for the write and for re-rendering.
     */
    private function validatePostedItem($item_name, $item_value, array &$items, array &$update_data, $validate, SurveyStudy $study) {
        /** @var $item Item */
        if ($item_value instanceof Item) {
            $item = $item_value;
            $item_value = $item->value_validated;
        } else {
            $item = $items[$item_name];
        }

        // Shape check before anything touches the reply: a client can post
        // `name[]=x` against any item, and the paths that skip
        // validateInput() (skip_validation, $validate = false) still hand
        // the value to getReply(), which for a scalar item may index an
        // array with it (Timezone) — a TypeError 500 on PHP 8. Items that
        // take several values (mc_multiple, select_multiple, file, …)
        // opt in via Item::$accepts_array_reply.
        if ($item->rejectNonScalarReply($item_value)) {
            $validInput = null;
        } else {
            $validInput = ($validate && !$item->skip_validation) ? $item->validateInput($item_value) : $item_value;
        }
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

    /**
     * updateSurveyStudyRecord's second pass: every posted answer validated,
     * write them in one transaction.
     */
    private function writeSurveyStudyRecord(array $posted, array $items, array $update_data, SurveyStudy $study, $quiet) {
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
            // Update results table in one query (gated on form_v2 dual-write).
            // Long-form (survey_items_display) is written above — source of truth.
            if ($update_data && Config::get('form_v2_dual_write_results', true)) {
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
            formr_log_exception($e, __CLASS__);
            // $quiet (best-effort autosave): swallow without surfacing a user
            // error or emailing the study admin — a partial/rate-limited save
            // that loses a race is harmless (the explicit page-submit is the
            // durable path), and spamming the admin on every such miss is not.
            if (!$quiet) {
                notify_user_error($e, 'An error occurred while trying to save your survey data. Please notify the author of this survey with this date and time');
                // Notify study admin about failure to save posted survey data
                $message = 'Failed to save posted survey data: ' . $e->getMessage();
                notify_study_admin($this, $message, 'error');
            }
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

            // A v2 study (numeric id, per-study wide table) is read from the
            // long format (survey_items_display) — what the R overlay
            // evaluates against, independent of the wide dual-write; a v1
            // study only where form_v2_long_reads opts in
            // (SurveyStudy::readsLong). The special pseudo-tables
            // (survey_unit_sessions/run_sessions/users, externals) and v1
            // studies otherwise keep the wide path below.
            if (is_numeric($study_id)
                    && !in_array($results_table, get_db_non_session_tables())
                    && SurveyStudy::readsLong($needed['rendering_modes'][$study_id] ?? null)) {
                // The wide table and its columns come along so sessions that
                // pre-date survey_items_display (v1 imports, restored backups)
                // are UNION ALL-ed back in rather than vanishing from the
                // INNER JOIN; [] when the table is gone, which turns the union
                // off. One cached SHOW COLUMNS per table per request.
                $datasets['datasets'][$survey_name] = $this->getSurveyDataLong(
                    (int) $study_id,
                    isset($matches_variable_names[$survey_name]) ? $matches_variable_names[$survey_name] : array(),
                    $results_table,
                    SurveyStudy::wideTableColumns($results_table)
                );
                continue;
            }

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

    /**
     * Long-format replacement for getRunData's per-study wide read: pivot
     * survey_items_display into the same column-arrays shape the R overlay
     * expects. Column sourcing mirrors SurveyStudy::buildPivotSql —
     * created = MIN(sid.created), modified = MAX(sid.saved), ended = us.ended,
     * iteration = us.study_iteration (ROW_NUMBER fallback pre-backfill),
     * answers = MAX(CASE) keyed by item NAME (name, not id, so a soft-deleted-
     * then-recreated item under the same name keeps contributing its old
     * sessions' answers, like the single wide column did).
     *
     * Row set = unit sessions that have display rows for this study (display
     * rows and the wide row are created by the same createSurveyStudyRecord
     * call, so the row counts match the wide table's) — one row per session,
     * ordered by unit-session id ASC (the wide table's PK order, which
     * tail(survey, 1) depends on).
     *
     * `ended` (F4): the wide column was only stamped by real completions —
     * UnitSession::end($reason, $completes = false), the operator force-move
     * path, leaves it NULL while it does stamp survey_unit_sessions.ended.
     * So the long read must not hand back us.ended verbatim; it uses
     * completedEndedSql(), which yields NULL when `result` records an
     * operator end. R's `!is.na(survey$ended)` therefore keeps meaning "the
     * participant finished", as in v1.
     *
     * Legacy wide-only sessions (F3): rows written before survey_items_display
     * carried every answer (pre-2019 imports, bin/import-results.php without
     * --include-itemsdisplay, hand-restored backups) have a wide row but no
     * display rows and would vanish from the INNER JOIN. When the caller
     * passes the study's wide table and its column list, those sessions are
     * UNION ALL-ed in from the wide table (only sessions with NO display rows,
     * so nothing is double-counted; a session with display rows is always
     * served from the long side). The wide half reads `ended` straight from
     * the wide column, which is v1 semantics by construction. The union is
     * chosen over a whole-table fallback so mixed studies (a v1 import plus
     * live v2 sessions) return one consistent data frame.
     *
     * @param int $studyId
     * @param string[] $names referenced variable names (meta + item names)
     * @param string|null $resultsTable wide table to union legacy sessions from (null: long only)
     * @param string[]|null $wideColumns its column names (SurveyStudy::wideTableColumns); empty/null: long only
     * @return array<string, array> column-arrays: [var => [v1, v2, ...]]
     */
    protected function getSurveyDataLong($studyId, array $names, $resultsTable = null, array $wideColumns = null) {
        $useWide = $resultsTable !== null && $resultsTable !== '' && !empty($wideColumns);
        $wideHasIteration = $useWide && in_array('iteration', $wideColumns, true);

        $selectParts = $wideParts = array();
        foreach ($names as $name) {
            $safe = str_replace('`', '``', $name);
            switch ($name) {
                case 'created':
                    $selectParts[$name] = 'MIN(`sid`.`created`) AS `created`';
                    $wideParts[$name] = '`w`.`created` AS `created`';
                    break;
                case 'modified':
                    // The last answer stored — v2 writes nothing on a page
                    // view, so not the last visit, as the results table's
                    // `modified` was — and the start of the unit session
                    // before any answer, rather than NA.
                    $selectParts[$name] = 'COALESCE(MAX(`sid`.`saved`), MIN(`us`.`created`)) AS `modified`';
                    $wideParts[$name] = '`w`.`modified` AS `modified`';
                    break;
                case 'ended':
                    $selectParts[$name] = self::completedEndedSql('us') . ' AS `ended`';
                    $wideParts[$name] = '`w`.`ended` AS `ended`';
                    break;
                case 'iteration':
                    // A session from before patch 090 has no study_iteration
                    // until bin/backfill_study_iteration.php has run; its wide
                    // row has the number, so read it there (`wi`, joined
                    // below) before falling back to a per-result-set count.
                    $selectParts[$name] = $wideHasIteration
                        ? 'COALESCE(`us`.`study_iteration`, `wi`.`iteration`, ROW_NUMBER() OVER (ORDER BY `us`.`id` ASC)) AS `iteration`'
                        : 'COALESCE(`us`.`study_iteration`, ROW_NUMBER() OVER (ORDER BY `us`.`id` ASC)) AS `iteration`';
                    $wideParts[$name] = $wideHasIteration
                        ? 'COALESCE(`us`.`study_iteration`, `w`.`iteration`) AS `iteration`'
                        : 'COALESCE(`us`.`study_iteration`, ROW_NUMBER() OVER (ORDER BY `us`.`id` ASC)) AS `iteration`';
                    break;
                default:
                    // Item names are validated as identifiers at upload;
                    // escape defensively anyway.
                    $quoted = str_replace("'", "''", $name);
                    $selectParts[$name] = "MAX(CASE WHEN `i`.`name` = '{$quoted}' THEN `sid`.`answer` END) AS `{$safe}`";
                    // A soft-deleted item's wide column is dropped; NULL then.
                    $wideParts[$name] = ($useWide && in_array($name, $wideColumns, true))
                        ? "`w`.`{$safe}` AS `{$safe}`"
                        : "NULL AS `{$safe}`";
            }
        }
        if (!$selectParts) {
            // Only the survey name was referenced (e.g. nrow(diary) > 3) —
            // the row COUNT still matters, so emit one dummy row per session
            // exactly like the wide read's "NULL AS formr_dummy".
            $selectParts[] = 'NULL AS `formr_dummy`';
            $wideParts[] = 'NULL AS `formr_dummy`';
        }

        $runSession = $this->runSession;
        $testingScope = ($runSession->id === null || $runSession->isTestingStudy());
        $where = $testingScope
            ? '`sid`.`session_id` = :session_id'
            : '`us`.`run_session_id` = :run_session_id';

        $iterationJoin = $iterationGroup = '';
        if ($wideHasIteration && in_array('iteration', $names, true)) {
            // session_id is the wide table's primary key: at most one row.
            $iterationJoin = "\n            LEFT JOIN `" . str_replace('`', '``', $resultsTable) . "` `wi` ON `wi`.`session_id` = `us`.`id`";
            $iterationGroup = ', `wi`.`iteration`';
        }
        $longSql = 'SELECT ' . implode(', ', $selectParts) . ', `us`.`id` AS `formr_sid__`
            FROM `survey_items_display` `sid`
            INNER JOIN `survey_items` `i` ON `i`.`id` = `sid`.`item_id` AND `i`.`study_id` = :study_id
            INNER JOIN `survey_unit_sessions` `us` ON `us`.`id` = `sid`.`session_id`' . $iterationJoin . '
            WHERE ' . $where . '
            GROUP BY `us`.`id`, `us`.`ended`, `us`.`result`, `us`.`study_iteration`' . $iterationGroup;

        if ($useWide) {
            // Distinct placeholder names: the DB layer uses non-emulated
            // prepares, which forbid binding one name twice.
            $wideWhere = $testingScope
                ? '`w`.`session_id` = :session_id_w'
                : '`us`.`run_session_id` = :run_session_id_w';
            $wideTable = str_replace('`', '``', $resultsTable);
            $wideSql = 'SELECT ' . implode(', ', $wideParts) . ', `us`.`id` AS `formr_sid__`
                FROM `' . $wideTable . '` `w`
                INNER JOIN `survey_unit_sessions` `us` ON `us`.`id` = `w`.`session_id`
                WHERE ' . $wideWhere . '
                  AND NOT EXISTS (SELECT 1 FROM `survey_items_display` `sid2` WHERE `sid2`.`session_id` = `w`.`session_id`)';
            // Halves are unparenthesised (SQLite rejects parenthesised
            // compound members); neither carries ORDER BY, so that is legal
            // on MariaDB too. The outer ORDER BY on the hidden id column
            // restores the wide PK order across both halves.
            $sql = 'SELECT * FROM (' . $longSql . ' UNION ALL ' . $wideSql . ') `u` ORDER BY `u`.`formr_sid__` ASC';
        } else {
            $sql = $longSql . ' ORDER BY `us`.`id` ASC';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':study_id', (int) $studyId, PDO::PARAM_INT);
        if ($testingScope) {
            $stmt->bindValue(':session_id', $this->id);
            if ($useWide) {
                $stmt->bindValue(':session_id_w', $this->id);
            }
        } else {
            $stmt->bindValue(':run_session_id', $runSession->id);
            if ($useWide) {
                $stmt->bindValue(':run_session_id_w', $runSession->id);
            }
        }
        $stmt->execute();

        // survey_items_display.answer is MEDIUMTEXT, so every pivoted value
        // comes back a PHP string — json_encode then ships "7" instead of 7
        // and R sees character columns where the wide read (typed columns)
        // gave it numbers: `age > 18` turns into a lexical comparison and
        // mean()/arithmetic error. Coerce to what the item's wide column
        // type would have produced.
        $casts = $this->getLongReadCasts($studyId, $names);
        if (in_array('iteration', $names)) {
            // COALESCE(INT UNSIGNED, ROW_NUMBER()) is typed DECIMAL by
            // MariaDB, which PDO hands back as a string; the wide column
            // was INT.
            $casts['iteration'] = 'int';
        }

        $columns = array();
        while ($res = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Ordering helper only (it restores the wide PK order across the
            // union's two halves) — never a variable of the data frame.
            unset($res['formr_sid__']);
            foreach ($res as $var => $val) {
                if (!isset($columns[$var])) {
                    $columns[$var] = array();
                }
                if ($val === '' && isset($casts[$var])) {
                    // A blank reply the wide column stored as NULL (numeric
                    // columns can hold nothing else; single-valued items were
                    // NULLed by validation, SurveyStudy::blankAnswerIsNull).
                    $val = null;
                } elseif ($val !== null && isset($casts[$var]) && $casts[$var] !== 'string' && is_numeric($val)) {
                    $val = $casts[$var] === 'int' ? (int) $val : (float) $val;
                }
                $columns[$var][] = $val;
            }
        }
        return $columns;
    }

    /**
     * Per-column PHP cast that reproduces what PDO returned from the wide
     * results table: integer column families → int, FLOAT/DOUBLE → float,
     * everything else (VARCHAR/TEXT/DATE…, and DECIMAL, which PDO also
     * returns as a string) → untouched. The column type is whatever
     * Item::getResultField() would have declared, so items are instantiated
     * through ItemFactory exactly as the upload path does; soft-deleted rows
     * are included because the long read still serves their answers, with
     * a live row winning over a deleted namesake. 'string' marks a text-typed
     * column whose blank answer ('') the wide table held as NULL
     * (SurveyStudy::blankAnswerIsNull); it is not cast.
     *
     * @param int $studyId
     * @param string[] $names
     * @return array<string, string> [item name => 'int'|'float'|'string']
     */
    protected function getLongReadCasts($studyId, array $names) {
        $itemNames = array_values(array_diff($names, array('created', 'modified', 'ended', 'iteration')));
        if (!$itemNames) {
            return array();
        }

        // Per-request memo (F7). getRunData is called once per unit render but
        // asks per study, and a run's R code routinely references the same
        // study from several units; each miss costs two queries plus an
        // ItemFactory build of every referenced item. Item definitions change
        // only through an upload, which is a different request.
        sort($itemNames);
        $cacheKey = ((int) $studyId) . ':' . implode(',', $itemNames);
        if (isset(self::$longReadCastsCache[$cacheKey])) {
            return self::$longReadCastsCache[$cacheKey];
        }

        $rows = $this->db->select('id, study_id, type, choice_list, type_options, name, deleted')
            ->from('survey_items')
            ->where(array('study_id' => (int) $studyId))
            ->whereIn('name', $itemNames)
            ->order(DB::raw('`deleted` IS NULL'), 'DESC')
            ->order('id', 'DESC')
            ->fetchAll();
        if (!$rows) {
            return self::$longReadCastsCache[$cacheKey] = array();
        }

        $choiceLists = array();
        $choiceRows = $this->db->select('list_name, name, label')
            ->from('survey_item_choices')
            ->where(array('study_id' => (int) $studyId))
            ->order('id', 'ASC')
            ->fetchAll();
        foreach ($choiceRows as $c) {
            $choiceLists[$c['list_name']][$c['name']] = $c['label'];
        }

        $factory = new ItemFactory($choiceLists);
        $casts = $seen = array();
        foreach ($rows as $row) {
            if (isset($seen[$row['name']])) {
                continue;
            }
            $seen[$row['name']] = true;
            $item = $factory->make($row);
            if ($item === false || !$item->isStoredInResultsTable() || ($field = $item->getResultField()) === null) {
                continue;
            }
            $type = strtoupper(ltrim(substr($field, strlen("`{$row['name']}` "))));
            if (preg_match('/^(TINY|SMALL|MEDIUM|BIG)?INT\b/', $type)) {
                $casts[$row['name']] = 'int';
            } elseif (preg_match('/^(FLOAT|DOUBLE)\b/', $type)) {
                $casts[$row['name']] = 'float';
            } elseif (SurveyStudy::blankAnswerIsNull($item)) {
                $casts[$row['name']] = 'string';
            }
        }
        return self::$longReadCastsCache[$cacheKey] = $casts;
    }

    /**
     * Drop the per-request long-read memo. A long-running process (the queue
     * daemons) calls this once per pass, or it would keep item types from
     * before an upload that changed them.
     */
    public static function forgetLongReadCasts() {
        self::$longReadCastsCache = array();
    }

    /**
     * [study id + referenced names => casts], see getLongReadCasts (F7).
     * Request-scoped: an upload that changes item types is another request.
     *
     * @var array<string, array<string, string>>
     */
    private static $longReadCastsCache = array();

    protected function getRunDataNeeded($q, $token_add = null) {
        $matches_variable_names = $variable_names_in_table = $matches = $matches_results_tables = $results_tables = $tables = array();
        $rendering_modes = array(); // study id → rendering_mode (SurveyStudy::readsLong)

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
            $rendering_modes[$study->id] = $study->rendering_mode;
        }

        // map table ID to the name that the user sees (because tables in the DB are prefixed with the user ID, so they're unique)
        foreach ($surveys as $res) {
            if ($res['name'] !== $token_add) {
                $table_ids[] = $res['id'];
                $tables[] = $res['name']; // FIXME: ID can overwrite the non_user_tables
                $results_tables[$res['name']] = $res['results_table'];
                $rendering_modes[$res['id']] = $res['rendering_mode'] ?? null;
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
                        ->where("name != 'iteration'");
                // A soft-deleted item (patch 094) is not part of the survey
                // any more: its answers stay in the database (and in
                // exports), but R does not see them — on the wide path its
                // column is gone, and on the long path it would otherwise
                // reappear, a scale name (extraversion) picking up a removed
                // extraversion_3.
                $items->where('`deleted` IS NULL');
                $items = $items->fetchAll();

                $variable_names_in_table[$table_name] = array("created", "modified", "ended"); // should avoid modified, sucks for caching
                // `iteration` (F2): the long read always serves it (from
                // survey_unit_sessions.study_iteration), the wide read only
                // when the legacy table actually has the column. One cached
                // SHOW COLUMNS per table per request via SurveyStudy.
                $res_table = $results_tables[$table_name];
                if (SurveyStudy::readsLong($rendering_modes[$study_id] ?? null)
                        || in_array('iteration', SurveyStudy::wideTableColumns($res_table), true)) {
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

        return compact("matches", "matches_results_tables", "matches_variable_names", "token_add", "variables", "rendering_modes");
    }

}
