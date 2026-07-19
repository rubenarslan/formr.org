<?php

class RunSession extends Model {

    public $id;
    public $run_id;
    public $user_id;
    public $session = null;
    public $created;
    public $ended;
    public $last_access;
    public $position;
    public $current_unit_session_id;
    public $deactivated = 0;
    public $no_email;
    public $testing = 0;
    private $run_owner_id;
    /**
     * 
     * @var Run
     */
    protected $run;
    /**
     * 
     * @var User
     */
    public $user;
    protected $table = 'survey_run_sessions';
    /**
     * Currently active unit session;
     *
     * @var UnitSession
     */
    public $currentUnitSession;
    /**
     * Cache for unit ids in various positions
     *
     * @var array
     */
    protected $positionedUnitIds = [];
    /** @var array Cache for survey_run_units.id keyed by position */
    protected $positionedRunUnitIds = [];
    /** Default automated-unit ceiling per request; override via the
     * run_session.max_execution_count config. Exceeding it HALTS the request
     * (empty body, formr_log) without ending the run session — a misconfigured
     * skip cycle stays cheap and bounded per request, and no participant is
     * ever ejected irreversibly (review 2026-07, item 10: the earlier
     * per-position revisit + 200-execution two-tier design made the loop
     * verdict unreachable for cycles spanning >40 positions, letting them
     * burn ~200 executions per daemon poll forever). */
    const MAX_EXECUTION_COUNT = 10;
    /**
     * Current number of execution counts while recursive
     * @var int
     */
    protected $executionCount = 0;

    /**
     * A RunSession should always be initiated with a Run and a User
     * since a RunSession should belong to a User and needs a Run
     * 
     * @param string $session The code of the user executing the run
     * @param Run $run
     * @param array $options Other options that could be used to initiate a RunSession
     */
    public function __construct($session, Run $run, $options = []) {
        parent::__construct();

        $this->session = $session;
        $this->run = $run;
        // Defense-in-depth allowlist: only 'id' and 'user' are legitimate
        // constructor options (callers in Run.php / RunUnit.php / Queue
        // pass these). Everything else — user_id, position, deactivated,
        // current_unit_session_id, no_email — must come from the DB row
        // loaded below, not from caller input.
        if ($options) {
            $this->assignProperties(array_intersect_key(
                (array) $options,
                ['id' => true, 'user' => true]
            ));
        }

        if (($this->id || $this->session) && $this->run) {
            $this->load();
        }

        if ($run->isStudyTest()) {
            // User is just testing the survey so we only need a dummy run session since data is not saved
            $this->id = -1;
            $this->testing = true;
            Site::getInstance()->setRunSession($this);
        }

        if (!$this->user) {
            $this->user = new User(null, $this->session);
        }
    }

    private function load() {
        $options = [];
        if ($this->id) {
            $options['id'] = (int) $this->id;
        } elseif ($this->session) {
            $options['session'] = $this->session;
            $options['run_id'] = $this->run->id;
        }

        if (!$options) {
            return;
        }

        $data = $this->db->findRow('survey_run_sessions', $options);
        if ($data) {
            $this->assignProperties($data);
            $this->valid = true;
            Site::getInstance()->setRunSession($this);
            return true;
        }

        return false;
    }

    /**
     * Re-fetch this RunSession's row from the DB and re-assign properties
     * (position, ended, current_unit_session_id, etc.) — used inside
     * execute() right after acquireLock to ensure the cached state
     * reflects any UPDATEs committed by a concurrent request that won
     * the lock first. See execute()'s comment for the prod incident
     * this guards against.
     */
    public function reloadFromDb() {
        if (!$this->id) {
            return false;
        }
        $data = $this->db->findRow('survey_run_sessions', ['id' => (int) $this->id]);
        if ($data) {
            $this->assignProperties($data);
            return true;
        }
        return false;
    }

    public function getRun() {
        return $this->run;
    }

    public function getLastAccess() {
        return $this->db->findValue('survey_run_sessions', array('id' => $this->id), array('last_access'));
    }

    public function setLastAccess() {
        if (!$this->cron && (int) $this->id > 0) {
            $this->db->update('survey_run_sessions', array('last_access' => mysql_now()), array('id' => (int) $this->id));
        }
    }

    public function runAccessExpired() {
        if (!$this->run || !($last_access = $this->getLastAccess())) {
            return false;
        }

        if (($timestamp = strtotime($last_access)) && $this->run->expire_cookie) {
            return $timestamp + $this->run->expire_cookie < time();
        }

        return false;
    }

    public function create($session = null, $testing = 0) {
        if ($this->run->id === -1) {
            return false;
        }
        $code_rule = Config::get("user_code_regular_expression");
        if ($session !== null) {
            if (!preg_match($code_rule, $session)) {
                alert("<strong>Error.</strong> Session tokens needs to match $code_rule", 'alert-danger');
                return false;
            }
        } else {
            $session = crypto_token(48);
        }

        $this->db->insert_update('survey_run_sessions', array(
            'run_id' => $this->run->id,
            'user_id' => $this->user->id,
            'session' => $session,
            'created' => mysql_now(),
            'testing' => $testing
        ), array('user_id'));

        $this->session = $session;
        return $this->load();
    }

    /**
     * Create a new unit session for this run session
     *
     * @param RunUnit $unit
     * @param boolean $setAsCurrent
     * @param boolean $save Should unit session be saved on TV?
     * @return \RunSession
     */
    public function createUnitSession(RunUnit $unit, $setAsCurrent = true, $save = true) {
        
        $unitSession = new UnitSession($this, $unit);
        if ($save === false) {
            $this->currentUnitSession = $unitSession;
            return $this;
        }

        $created = $unitSession->create($setAsCurrent);
        // A failed create() returns an invalid object (id null). Do not
        // install it as the current session — execute()'s recovery branch
        // detects the missing step and re-creates it at the current
        // position instead of silently executing a bogus row.
        $this->currentUnitSession = ($created && $created->id) ? $created : null;
        return $this;
    }

    /**
     * Loop over units in Run for a session until you get a unit with output
     */
    public function execute(UnitSession $referenceUnitSession = null, $executeReferenceUnit = false) {
        /* ────────────────  RUN-SESSION LEVEL LOCK  ────────────────
         * Prevent concurrent access (user ↔ queue) to the same run
         * session.  We try to obtain a named lock that is unique for
         * this session.  If the lock cannot be obtained quickly we
         * bail out – the queue will retry later and interactive
         * requests will simply send an empty body so the browser can
         * refresh.
         */
        $lock_name   = $this->lockName();
        // Use different timeouts for queue vs user requests
        $lock_timeout = formr_in_console() 
            ? Config::get('run_session.lock_timeout.queue', 0.1)  // Queue requests
            : Config::get('run_session.lock_timeout.user', 10.0); // User requests

        if (!$this->acquireLock($lock_name, $lock_timeout)) {
            // Could not grab the lock – somebody else is working on it.
            return formr_in_console() ? false : ['body' => 'Timeout. A large computation is running. Will automatically reload in 5 seconds. <script>window.setTimeout(function() { window.location.reload(); }, 5000);</script>'];
        }

        try {
            // Re-fetch run-session state now that we hold the exclusive
            // lock. Between this RunSession's constructor load() and the
            // acquireLock above, another request may have advanced
            // position / set ended / queued sibling unit-sessions. With-
            // out this refresh, $this->position is stale: two near-
            // simultaneous user requests at an expired Pause both load
            // position=N pre-lock, then the second one to acquire the
            // lock drives moveOn from N (instead of from N+1 where the
            // run-session has actually advanced), creating duplicate
            // downstream unit-sessions. Observed on AMOR 2026-05-09 at
            // 10:03–10:11 (18 affected participants, 2× Email + 2× Push +
            // 2× Survey rows per Pause anchor). See
            // tests/e2e/double-expiry.spec.js D1.
            $this->reloadFromDb();

            if ($this->ended) {
                // User tried to access an already ended run session, logout
                if (formr_in_console()) {
                    $referenceUnitSession->end('ended_by_queue_rse');
                    UnitSessionQueue::removeItem($referenceUnitSession->id);
                } elseif ($this->current_unit_session_id) {
                    $this->currentUnitSession = new UnitSession($this, null, [
                        'id' => $this->current_unit_session_id, 
                        'load' => true
                    ]);
                    if (!$this->currentUnitSession->runUnit) {
                        formr_error(404, 'Run Unit Not Found', 'The Run Unit you are trying to access may have been deleted');
                    }
                    return $this->executeUnitSession();
                }
                
                // logout if we are unable to get a current unit session
                return redirect_to(run_url($this->run->name, 'logout', ['prev' => $this->session]));
            }
            
            // Execution ceiling (review 2026-07, item 10): more than
            // max_execution_count automated units in ONE request is either a
            // misconfigured skip cycle or an unusually long catch-up cascade.
            // Either way: HALT this request without ending the run session —
            // a loop stays cheap and bounded per request (and is visible in
            // the error log), a genuine catch-up simply continues from the
            // advanced position on the next request. Nothing is ended
            // irreversibly (the old spam() ejection is gone).
            if ($this->executionCount > $this->maxExecutionCount()) {
                formr_log("Run session {$this->id}: exceeded run_session.max_execution_count ({$this->maxExecutionCount()}) at position {$this->position}; halting request without ending the run session. If this recurs, the run structure likely contains an automated-unit loop.");
                return ['body' => ''];
            }

            if ($this->run->isStudyTest()) {
                return $this->executeTest();
            }
            // Get the initial position if this run session hasn't executed before
            // Audit F12 (2026-07): null-check, not truthiness — a first
            // unit at position 0 must start the run, not read as "study
            // not defined". getFirstPosition returns null on an empty run.
            if ($this->position === null && ($position = $this->run->getFirstPosition()) === null) {
                alert('This study has not been defined.', 'alert-danger');
                return false;
            }

            if ($this->position === null) {
                $this->position = $position;
                $this->save();
            }

            $currentUnitSession = $this->getCurrentUnitSession();
            
            // If there is a referenceUnitSession then it is sent by the queue
            if ($referenceUnitSession && $currentUnitSession && $referenceUnitSession->id == $currentUnitSession->id && !$executeReferenceUnit) {
                $this->debug("END-q");
                // Audit F4 (2026-07): never end on the stored deadline
                // alone — revalidate against current state first. A
                // sliding-window Survey whose participant kept working
                // gets its queue row re-armed at the fresh deadline
                // instead of being expired mid-edit; a config that now
                // says "never expires" gets dequeued. Pause keeps its
                // end-at-stored-deadline semantic (80e89dcb) because its
                // expiration data returns end_session for an overdue
                // QUEUED_TO_END gate without re-evaluating relative_to.
                $verdict = $currentUnitSession->revalidateQueueVerdict();
                if ($verdict !== 'end') {
                    $this->debug("END-q deferred ({$verdict})");
                    return ['body' => ''];
                }
                if($this->endCurrentUnitSession()) {
                    return $this->moveOn();
                }
            } elseif ($referenceUnitSession && $currentUnitSession && $referenceUnitSession->id != $currentUnitSession->id) {
                // The queue handed us a stale reference: its unit-session
                // is no longer the active one for this run-session (the
                // run advanced past it via a prior cron tick or a back-jump).
                // Drop the reference and stop here. The active unit-session
                // is legitimate; the cron has nothing to do for THIS reference.
                //
                // Pre-fix this branch called moveOn() — which advanced the
                // run-session's position past the active unit AND triggered
                // a createUnitSession that supersede'd the active unit's
                // queue entry to queued=-9. That's how participants who were
                // mid-survey ended up orphaned (`ended IS NULL, expired IS
                // NULL, queued = -9, results-row populated`). See
                // tests/e2e/EXPIRY_PLAN.md "Fix 1".
                UnitSessionQueue::removeItem($referenceUnitSession->id);
                return ['body' => ''];
            }

            $this->debug('Current Unit Is ' . ($currentUnitSession ? $currentUnitSession->runUnit->type : '[none]'), true);
            if (!$currentUnitSession && $this->position === $this->run->getFirstPosition()) {
                // We are in the first unit of the run
                return $this->moveOn(true);
            } elseif (!$currentUnitSession) {
                // No live session at the stored position. Count the hop so
                // a stuck recovery can't recurse unboundedly — but fail the
                // REQUEST when the bound trips, never the participant:
                // spam() would end the run session irreversibly for what is
                // an engine-side fault.
                $this->executionCount++;
                if ($this->executionCount >= $this->maxExecutionCount()) {
                    formr_log("Run session {$this->id}: recovery loop at position {$this->position} exceeded run_session.max_execution_count; aborting request without ending the run session.");
                    alert(__('Temporary problem executing this study. Please try again in a moment.'), 'alert-warning');
                    return ['body' => ''];
                }
                // Distinguish "the step here ran and finished" (advance)
                // from "the step was never instantiated" (repair): a
                // moveOn() persists the position BEFORE createUnitSession,
                // so a failed create leaves zero rows for this placement —
                // pre-fix this branch then skipped the position entirely.
                if (!$this->placementHasAnyUnitSession() && ($unit_id = $this->getUnitIdAtPosition($this->position))) {
                    $this->debug('No session ever created at position ' . $this->position . ' — repairing', true);
                    $runUnit = RunUnitFactory::make($this->run, ['id' => $unit_id]);
                    if ($runUnit && $runUnit->valid) {
                        $this->createUnitSession($runUnit);
                        return $this->execute();
                    }
                }
                $this->debug('No live session at position ' . $this->position . ' — recovery moveOn', true);
                return $this->moveOn();
            } else {
                // Currently active unit session. Should most likely be a survey or pause
                $this->currentUnitSession = $currentUnitSession;
            }

            return $this->executeUnitSession();
        } finally {
            // Always release the named lock so that other processes can continue.
            $this->releaseLock($lock_name);
        }
    }

    /**
     * Move on to the next unit of the Run
     * 
     * @param boolean $starting TRUE if we are in the first run unit. FALSE otherwise.
     * @param boolean $execute TRUE means we continue executing the next unit
     * @return array|null
     */
    public function moveOn($starting = false, $execute = true) {
        if ($this->run->isStudyTest()) {
            // nothing to move on to
            return null;
        }

        // Audit F2 (2026-07): `ended` is terminal. The ended branch of
        // execute() re-dispatches the last unit session for display
        // (Endpage re-render); without this guard a completed Survey's
        // move_on result made an ended run session advance, create new
        // unit sessions, and send messages on every reload.
        if ($this->ended) {
            return ['body' => ''];
        }

        if (!$starting) {
            $this->currentUnitSession = null;
            $this->position = $this->run->getNextPosition($this->position);
            if ($this->position !== null) {
                $this->save();
            }
        }

        // Audit F12 (2026-07): compare against null, not truthiness — a
        // real unit at position 0 (reachable on legacy prod rows before
        // reorder validation) must not be read as "no next unit / end of
        // run". getNextPosition returns null at the genuine end.
        if ($this->position !== null && ($unit_id = $this->getUnitIdAtPosition($this->position))) {
            $runUnit = RunUnitFactory::make($this->run, ['id' => $unit_id]);
            $this->createUnitSession($runUnit);
            return $execute ? $this->execute() : null;
        }

        alert('Run ' . $this->run->name . ':<br /> Oops, this study\'s creator forgot to give it a proper ending (a Stop button), user ' . h($this->session) . ' is dangling at the end.', 'alert-danger');
        $this->end();
        return ['body' => ''];
    }

    protected function executeUnitSession() {
        $this->executionCount++;
        $this->debug("Execute");
        
        $result = $this->currentUnitSession->execute();
        $this->debug($result, true);

        if (!empty($result['expired'])) {
            $this->debug("EXPIRE");
            $this->currentUnitSession->expire();
        } elseif (!empty($result['end_session'])) {
            $this->debug("END");
            $this->currentUnitSession->end();
        } elseif (isset($result['queue'])) {
            $this->debug('QUEUE');
            $this->currentUnitSession->queue();
            return [
                'body' => array_val($result, 'content'),
                'redirect' => array_val($result, 'redirect')
            ];
        }

        if (!empty($result['wait_opencpu']) || !empty($result['wait_user'])) {
            return ['body' => ''];
        }

        if (isset($result['redirect'])) {
            // move on in the run before redirecting to external service (except for surveys)
            if ($this->currentUnitSession->runUnit->type !== 'Survey') {
                // Audit F5 (2026-07): a cron cascade cannot follow a
                // redirect. Advancing anyway (without executing) parked
                // the successor at PENDING/queued=0 — invisible to the
                // daemon — while the participant never saw the external
                // address. Stop the cascade instead: the External stays
                // current, the participant is redirected on their next
                // visit, and expire_after still bounds abandonment.
                if (formr_in_console()) {
                    return ['body' => ''];
                }
                $this->moveOn(false, false);
            }
            return $result;
        }

        if (isset($result['run_to'])) {
            return $this->runTo($result['run_to'], null, true);
        }
		
		if (isset($result['move_on'])) {
            return $this->moveOn();
        }

        if (isset($result['end_run_session'])) {
            $this->end();
        }
        
        if (isset($result['content'])) {
            return ['body' => $result['content']];
        }

        return $this->moveOn();

        //alert('Error: Premature study end.', "alert-danger");
        //return ['body' => 'FORMR_END'];
    }

    public function getUnitIdAtPosition($position) {
        if (empty($this->positionedUnitIds[$position])) {
            $this->positionedUnitIds[$position] = $this->db->findValue('survey_run_units', ['run_id' => $this->run->id, 'position' => $position], 'unit_id');
        }

        return $this->positionedUnitIds[$position];
    }

    /**
     * Resolve the survey_run_units.id that maps this run + position. Sister
     * to getUnitIdAtPosition() — that one returns the unit definition id
     * (survey_units.id), this one returns the per-run-per-position id
     * (survey_run_units.id) which Track A's `survey_unit_sessions.run_unit_id`
     * column needs in order to disambiguate same-unit-at-multiple-positions
     * runs (D1 in REFACTOR_QUEUE_PLAN.md). Returns null if the position is
     * unmapped (defensive — caller stays NULL-safe so legacy data paths
     * that don't have a clean position match continue to function without
     * the new column).
     */
    public function getRunUnitIdAtPosition($position) {
        if (!$this->run || !$this->run->id || $position === null) {
            return null;
        }
        if (!array_key_exists($position, $this->positionedRunUnitIds)) {
            // findValue → fetchColumn() returns FALSE on a miss; coerce to
            // null so the documented contract holds and `!== null` guards
            // (here and in UnitSession::create) treat a miss as "unmapped"
            // rather than binding false/0 as a real id.
            $id = $this->db->findValue('survey_run_units', ['run_id' => $this->run->id, 'position' => $position], 'id');
            $this->positionedRunUnitIds[$position] = ($id === false) ? null : $id;
        }
        return $this->positionedRunUnitIds[$position];
    }

    /**
     * TRUE if ANY unit-session row — alive, ended, expired or superseded —
     * exists for the current position's placement, i.e. the step was at
     * least instantiated once. Used by execute()'s recovery branch to
     * distinguish "finished here, advance" from "never created, repair".
     * Legacy rows (run_unit_id NULL, pre-047) count via unit_id.
     */
    protected function placementHasAnyUnitSession() {
        $unit_id = $this->getUnitIdAtPosition($this->position);
        if (!$unit_id || !$this->id) {
            return false;
        }
        $run_unit_id = $this->getRunUnitIdAtPosition($this->position);
        $query = $this->db->select('survey_unit_sessions.id')
                ->from('survey_unit_sessions')
                ->where('run_session_id = :run_session_id')
                ->where('unit_id = :unit_id')
                ->limit(1);
        if ($run_unit_id !== null) {
            $query->where('(run_unit_id = :run_unit_id OR run_unit_id IS NULL)')
                  ->bindParams(['run_unit_id' => $run_unit_id]);
        }
        $row = $query->bindParams(['run_session_id' => $this->id, 'unit_id' => $unit_id])->fetch();
        return (bool) $row;
    }

    public function forceTo($position) {
        // Audit F3 (2026-07): admin moves must hold the same lock as
        // execute(); unlocked they raced a daemon cascade mid-flight,
        // duplicating live unit sessions and clobbering position. GET_LOCK
        // is reference-counted per connection, so runTo()'s nested
        // execute() re-acquiring it is safe. Reviving an ended session is
        // the admin's explicit intent here, hence $revive = true.
        $lock_name = $this->lockName();
        if (!$this->acquireLock($lock_name, Config::get('run_session.lock_timeout.user', 10.0))) {
            alert('Could not move the session: another process is currently executing it. Try again.', 'alert-danger');
            return false;
        }
        try {
            $this->reloadFromDb();
            // If there a unit for current position, then end the unit's session before moving
            if (($unitSession = $this->getCurrentUnitSession())) {
                $unitSession->end();
                $unitSession->result = 'manual_admin_push';
                $unitSession->logResult();
            }
            return $this->runTo($position, null, false, true);
        } finally {
            $this->releaseLock($lock_name);
        }
    }

    /**
     * Admin/API "advance to the next unit": end the current unit session
     * and moveOn, under the run-session lock. Audit F3 (2026-07): both
     * callers ran lock-free, and the AJAX variant ended the current unit
     * WITHOUT advancing — leaving cron-only participants with nothing
     * current and nothing queued, permanently stalled.
     */
    public function forceMoveOn($reason = 'moved') {
        if ($this->ended) {
            alert('This run session has ended; revive it by sending it to a position instead.', 'alert-danger');
            return false;
        }
        $lock_name = $this->lockName();
        if (!$this->acquireLock($lock_name, Config::get('run_session.lock_timeout.user', 10.0))) {
            alert('Could not advance the session: another process is currently executing it. Try again.', 'alert-danger');
            return false;
        }
        try {
            $this->reloadFromDb();
            $unitSession = $this->getCurrentUnitSession();
            if (!$unitSession) {
                return false;
            }
            $unitSession->end($reason);
            return (bool) $this->moveOn();
        } finally {
            $this->releaseLock($lock_name);
        }
    }

    public function runTo($position, $unit_id = null, $execute = false, $revive = false) {
        // Audit F2 (2026-07): Branch/Skip jumps land here; they must not
        // resurrect an ended run session. Only an explicit admin move
        // (forceTo) passes $revive.
        if ($this->ended && !$revive) {
            return ['body' => ''];
        }

        if ($unit_id === null) {
            $unit_id = $this->getUnitIdAtPosition($position);
        }

        if ($unit_id) {
            $this->position = $position;
            $unit = RunUnitFactory::make($this->run, ['id' => $unit_id]);
            if ($unit->valid) {
                $this->createUnitSession($unit);
                if ($revive) {
                    $this->db->exec(
                        "UPDATE `survey_run_sessions` SET `ended` = NULL, `position` = :position WHERE `id` = :id",
                        ['id' => $this->id, 'position' => $position]
                    );
                    $this->ended = null;
                } else {
                    $this->db->exec(
                        "UPDATE `survey_run_sessions` SET `position` = :position WHERE `id` = :id",
                        ['id' => $this->id, 'position' => $position]
                    );
                }
				$exec = ['body' => null];
				if (formr_in_console() || $execute) {
					$exec = $this->execute();
				}

                return $exec;
            } else {
                alert(__('<strong>Error.</strong> Could not create unit session for unit %s at pos. %s.', $unit_id, $position), 'alert-danger');
            }
        } else {
            // Audit F9 (2026-07): a dangling jump target reaching here
            // (direct/admin caller) is a structural fault — surface it to
            // the admin, not just a participant-facing alert.
            alert('<strong>Error.</strong> You tried to jump to a non-existing run position or forgot to specify one entirely.', 'alert-danger');
            if ($this->currentUnitSession) {
                notify_study_admin($this->currentUnitSession, 'Jump to non-existing run position ' . h($position) . '. Fix the run structure.', 'error');
            }
        }

        return false;
    }

    public function getCurrentUnitSession() {
        if ($this->currentUnitSession) {
            $this->debug("Using current unit session at {$this->position} [{$this->currentUnitSession->id}]", true);
            return $this->currentUnitSession;
        }

        $this->debug("Getting current unit session at {$this->position} [0]", true);
        $unit_id = $this->getUnitIdAtPosition($this->position);
        $run_unit_id = $this->getRunUnitIdAtPosition($this->position);
        $query = $this->db->select('
			`survey_unit_sessions`.unit_id,
			`survey_unit_sessions`.id,
            `survey_unit_sessions`.run_session_id,
            `survey_unit_sessions`.created,
            `survey_unit_sessions`.expires,
			`survey_unit_sessions`.ended,
            `survey_unit_sessions`.expired,
            `survey_unit_sessions`.queued,
			`survey_units`.type')
                ->from('survey_unit_sessions')
                ->leftJoin('survey_units', 'survey_unit_sessions.unit_id = survey_units.id')
                ->where('survey_unit_sessions.run_session_id = :run_session_id')
                ->where('survey_unit_sessions.ended IS NULL AND survey_unit_sessions.expired IS NULL') //so we know when to runToNextUnit
                // Exclude superseded siblings (queued=-9 set by
                // UnitSession::create()'s same-unit_id supersede). Pre-fix
                // a back-jump iteration created Pause(N)#new which flipped
                // Pause(N)#old to queued=-9 (ended/expired stayed NULL).
                // Once #new's `ended` got set, ORDER BY id DESC LIMIT 1
                // started returning #old — a row that was conceptually
                // "done" but didn't carry an `ended`/`expired` timestamp.
                // See tests/e2e/EXPIRY_AUDIT.md §11.
                ->where('survey_unit_sessions.queued != ' . UnitSessionQueue::QUEUED_SUPERCEDED)
                ->order('survey_unit_sessions`.id', 'desc')
                ->limit(1);

        // D1 fix (v0.26.4): when the same unit is slotted at multiple
        // positions, matching by unit_id alone lets a session created at
        // a *different* position be adopted as "current" — position and
        // session silently drift apart until the next moveOn advances
        // from the wrong (usually the last same-unit) position. Prefer
        // run_unit_id (the per-run-per-position survey_run_units.id,
        // written on every session since patch 047). Legacy rows where
        // run_unit_id IS NULL (pre-047; the 048 backfill intentionally
        // leaves multi-position rows NULL) keep matching by unit_id.
        if ($run_unit_id !== null) {
            // Audit F1 (2026-07): the run_unit_id arm must ALSO match on
            // unit_id. Reminder emails (Run::getReminderSession) used to
            // insert sessions stamped with the participant's current
            // placement's run_unit_id but a different unit_id; without
            // the unit_id constraint this query adopted the newer
            // reminder row as "current" and the participant skipped
            // their live unit. Healthy rows always satisfy both.
            $query->where('survey_unit_sessions.unit_id = :unit_id')
                  ->where('(survey_unit_sessions.run_unit_id = :run_unit_id OR survey_unit_sessions.run_unit_id IS NULL)')
                  ->bindParams(array('run_unit_id' => $run_unit_id));
        } else {
            $query->where('survey_unit_sessions.unit_id = :unit_id');
        }
        $query->bindParams(array('run_session_id' => $this->id, 'unit_id' => $unit_id));

        $row = $query->fetch();

        if ($row) {
            $u = $row;
            $u['id'] = $u['unit_id'];
            $unit = RunUnitFactory::make($this->run, $u);
            // Audit 2026-07 (hydration): the UnitSession constructor
            // allowlists $options to id/load (ed56a95f), so passing $row
            // produced an object with ONLY `id` set — every stored-state
            // guard (Pause/Survey/External expires, created, queued,
            // result) evaluated against null on the web path, silently
            // disabling deadline enforcement and the 80e89dcb overdue fix.
            // Load through the trusted PK path instead.
            $this->currentUnitSession = new UnitSession($this, $unit, ['id' => $row['id'], 'load' => true]);
            return $this->currentUnitSession;
        } else {
            return false;
        }
    }

    public function endCurrentUnitSession($reason = null) {
        if ($this->getCurrentUnitSession()) {
            $type = $this->currentUnitSession->runUnit->type;
            if ($type == 'Survey' || $type == 'External') {
                $this->currentUnitSession->expire();
            } else {
                $this->currentUnitSession->end($reason);
            }

            return true;
        }

        return false;
    }

    public function endLastExternal() {
        // Track A A8: raw-UPDATE bypass for "External returned from redirect,
        // mark ended". Column set mirrors what UnitSession::end() writes so the
        // row reads cleanly in admin tooling and analysis exports.
        //
        // Audit F20 (2026-07): scope to the ONE most-recent live External
        // (the row this api_end callback is for), not every live External
        // on the run session. Pre-fix a delayed callback for one External
        // ended the participant's current, unrelated External too. Resolve
        // the target id first, then UPDATE by id.
        $target = $this->db->execute(
            "SELECT us.id FROM `survey_unit_sessions` us
             JOIN `survey_units` u ON u.id = us.unit_id
             WHERE us.run_session_id = :id AND u.type = 'External'
               AND us.ended IS NULL AND us.expired IS NULL
             ORDER BY us.id DESC LIMIT 1",
            ['id' => $this->id], true
        );
        if (!$target) {
            return false;
        }

        $query = "UPDATE `survey_unit_sessions`
			SET `ended`     = NOW(),
			    `result`    = 'external_ended',
			    `queued`    = 0,
			    `state`     = :state,
			    `state_log` = :state_log
			WHERE `id` = :us_id AND `ended` IS NULL AND `expired` IS NULL LIMIT 1";

        $updated = $this->db->exec($query, [
            'us_id'     => $target,
            'state'     => UnitSessionQueue::STATE_ENDED,
            'state_log' => UnitSession::buildStateLog('external_ended', [
                'unit_type' => 'External',
                'via'       => 'endLastExternal',
            ]),
        ]);
        return $updated !== false && $updated > 0;
    }

    public function end() {
        $query = "UPDATE `survey_run_sessions` SET `ended` = NOW() WHERE `id` = :id AND `ended` IS NULL";
        $updated = $this->db->exec($query, array('id' => $this->id));

        if ($updated === 1) {
            $this->ended = mysql_datetime();
            return true;
        }

        return false;
    }
    
    /** Automated-unit ceiling per request (see MAX_EXECUTION_COUNT). */
    protected function maxExecutionCount(): int {
        return (int) Config::get('run_session.max_execution_count', self::MAX_EXECUTION_COUNT);
    }

    public function setTestingStatus($status = 0) {
        $this->db->update("survey_run_sessions", array('testing' => $status), array('id' => $this->id));
    }

    public function isTesting() {
        return $this->testing;
    }

    public function isCron() {
        return $this->user->isCron();
    }

    /**
     * Check if current run session is a test
     *
     * @param User $user
     * @return boolean True if current user in run is testing. False otherwise
     */
    public function isTest(User $user) {
        return $this->run_owner_id == $user->id;
    }

    public function __sleep() {
        return array('id', 'session', 'run_id');
    }

    public function saveSettings($settings, $update = null) {
        if (!empty($update)) {
            $this->db->update('survey_run_sessions', $update, array('id' => $this->id));
        }

        $oldSettings = $this->getSettings();
        unset($oldSettings['code']);
        if ($oldSettings) {
            $settings = array_merge($oldSettings, $settings);
        }

        $this->db->insert_update('survey_run_settings', array(
            'run_session_id' => $this->id,
            'settings' => json_encode($settings),
        ));
    }

    public function getSettings() {
        $settings = array();
        $row = $this->db->findRow('survey_run_settings', array('run_session_id' => $this->id));
        if ($row) {
            $settings = (array) json_decode($row['settings']);
        }
        $settings['code'] = $this->session;
        return $settings;
    }

    /**
     * Get push notification subscription for this run session
     * 
     * @param boolean $json Whether to return the subscription as a JSON string or an array
     * @return array|null The subscription data or null if no subscription found
     */
    public function getSubscription($json = true) {
        // Query the subscription from survey_items_display for this user's session
        $query = "SELECT sid.answer 
                 FROM survey_items_display sid
                 JOIN survey_items si ON si.id = sid.item_id
                 JOIN survey_unit_sessions sus ON sus.id = sid.session_id
                 WHERE sus.run_session_id = :run_session_id
                 AND si.type = 'push_notification'
                 AND sid.answer NOT IN ('not_requested', 'not_supported', 'expired', 'ios_version_not_supported')
                 ORDER BY sid.created DESC
                 LIMIT 1";

        $result = $this->db->execute($query, [
            ':run_session_id' => $this->id
        ], false, true);

        if (!$result || empty($result['answer'])) {
            return null;
        }

        if (!$json) {
            return $result['answer'];
        } else {
            return json_decode($result['answer'], true);
        }
    }

    /**
     * Update the most recent push notification subscription for this run session
     * 
     * @param array|string|null $subscriptionData The subscription data (array or JSON string) or null to remove subscription
     * @return bool True if update was successful, false otherwise
     */
    public function updateSubscription($subscriptionData) {
        // Convert array to JSON string if needed
        if (is_array($subscriptionData)) {
            $subscriptionJson = json_encode($subscriptionData);
        } elseif (is_string($subscriptionData)) {
            $subscriptionJson = $subscriptionData;
        } elseif ($subscriptionData === null) {
            $subscriptionJson = 'not_requested';
        } else {
            return false;
        }

        // Find the most recent push notification item for this session
        $query = "SELECT sid.id, sid.item_id
                 FROM survey_items_display sid
                 JOIN survey_items si ON si.id = sid.item_id
                 JOIN survey_unit_sessions sus ON sus.id = sid.session_id
                 WHERE sus.run_session_id = :run_session_id 
                 AND si.type = 'push_notification'
                 ORDER BY sid.created DESC
                 LIMIT 1";

        $result = $this->db->execute($query, [
            ':run_session_id' => $this->id
        ], false, true);

        if (!$result || empty($result['id'])) {
            return false;
        }

        // Update the subscription data
        $updateQuery = "UPDATE survey_items_display 
                       SET answer = :answer, saved = NOW()
                       WHERE id = :id";

        $updateResult = $this->db->execute($updateQuery, [
            ':answer' => $subscriptionJson,
            ':id' => $result['id']
        ]);

        return $updateResult !== false;
    }

    /**
     * Get email recipient field for this run session
     * 
     * @param string|null $recipient_field The recipient field to evaluate, or null to get most recent email
     * @param bool $return_session Whether to return OpenCPU session for debugging
     * @param UnitSession|null $unitSession Optional unit session for dynamic field evaluation
     * @return string|OpenCPU_Session|null The recipient email address or OpenCPU session
     */
    public function getRecipientEmail($recipient_field = null, $return_session = false, $unitSession = null) {
        $mostrecent = "most recent reported address";
        
        if (!$recipient_field || $recipient_field === $mostrecent) {
            $recent_email_query = "
                SELECT survey_items_display.answer AS email FROM survey_unit_sessions
                LEFT JOIN survey_units ON survey_units.id = survey_unit_sessions.unit_id AND survey_units.type = 'Survey'
                LEFT JOIN survey_run_units ON survey_run_units.unit_id = survey_units.id
                LEFT JOIN survey_items_display ON survey_items_display.session_id = survey_unit_sessions.id
                LEFT JOIN survey_items ON survey_items.id = survey_items_display.item_id
                WHERE
                survey_unit_sessions.run_session_id = :run_session_id AND 
                survey_run_units.run_id = :run_id AND 
                survey_items.type = 'email'
                ORDER BY survey_items_display.answered DESC
                LIMIT 1
            ";

            $result = $this->db->execute($recent_email_query, [
                ':run_id' => $this->run->id,
                ':run_session_id' => $this->id
            ], false, true);

            $recipient = array_val($result, 'email', null);
        } else {
            // For dynamic recipient fields, we need a UnitSession to get run data
            $unitSessionToUse = $unitSession ?: $this->currentUnitSession;
            if ($unitSessionToUse) {
                $opencpu_vars = $unitSessionToUse->getRunData($recipient_field);
                $recipient = opencpu_evaluate($recipient_field, $opencpu_vars, 'json', null, $return_session);
            } else {
                // Fallback: try to get the most recent email
                return $this->getRecipientEmail(null, $return_session);
            }
        }

        return $recipient;
    }

    /**
     * Update the most recent email address for this run session
     * 
     * @param string $email The email address to update
     * @return bool True if update was successful, false otherwise
     */
    public function updateRecipientField($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Find the most recent email item for this session
        $query = "SELECT sid.id, sid.item_id
                 FROM survey_items_display sid
                 JOIN survey_items si ON si.id = sid.item_id
                 JOIN survey_unit_sessions sus ON sus.id = sid.session_id
                 WHERE sus.run_session_id = :run_session_id 
                 AND si.type = 'email'
                 ORDER BY sid.created DESC
                 LIMIT 1";

        $result = $this->db->execute($query, [
            ':run_session_id' => $this->id
        ], false, true);

        if (!$result || empty($result['id'])) {
            return false;
        }

        // Update the email address
        $updateQuery = "UPDATE survey_items_display 
                       SET answer = :answer, saved = NOW()
                       WHERE id = :id";

        $updateResult = $this->db->execute($updateQuery, [
            ':answer' => $email,
            ':id' => $result['id']
        ]);

        return $updateResult !== false;
    }

    public static function toggleTestingStatus($sessions, $run_id) {
        $dbh = DB::getInstance();
        if (is_string($sessions)) {
            $sessions = array($sessions);
        }

        foreach ($sessions as $session) {
            $qs[] = $dbh->quote($session);
        }

        $query = 'UPDATE survey_run_sessions SET testing = 1 - testing WHERE session IN (' . implode(',', $qs) . ') AND run_id = ' . (int)$run_id;
        return $dbh->query($query)->rowCount();
    }

    public static function deleteSessions($sessions, $run_id) {
        $dbh = DB::getInstance();
        if (is_string($sessions)) {
            $sessions = array($sessions);
        }

        foreach ($sessions as $session) {
            $qs[] = $dbh->quote($session);
        }

        $query = 'DELETE FROM survey_run_sessions WHERE session IN (' . implode(',', $qs) . ') AND run_id = ' . (int)$run_id;
        return $dbh->query($query)->rowCount();
    }

    public static function positionSessions(Run $run, $sessions, $position) {
        if (is_string($sessions)) {
            $sessions = array($sessions);
        }

        $count = 0;
        foreach ($sessions as $session) {
            $runSession = new RunSession($session, $run);
            if ($runSession->position != $position && $runSession->forceTo($position)) {
                $runSession->execute();
                $count++;
            }
        }
        return $count;
    }

    public static function getSentRemindersBySessionId($id) {
        $stmt = DB::getInstance()->prepare('
            SELECT survey_unit_sessions.id as unit_session_id, survey_run_special_units.id as unit_id FROM survey_unit_sessions 
			LEFT JOIN survey_units ON survey_unit_sessions.unit_id = survey_units.id
			LEFT JOIN survey_run_special_units ON survey_run_special_units.id = survey_units.id
			WHERE survey_unit_sessions.run_session_id = :run_session_id AND survey_run_special_units.type = "ReminderEmail"
		');
        $stmt->bindValue('run_session_id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function toArray() {
        return [
            'id' => $this->id,
            'run_id' => $this->run->id,
            'user_id' => $this->user->id,
            'session' => $this->session,
            'created' => $this->created,
            'ended' => $this->ended,
            'last_access' => $this->last_access,
            'position' => $this->position,
            'current_unit_session_id' => $this->current_unit_session_id,
            'deactivated' => $this->deactivated,
            'no_email' => $this->no_email,
            'testing' => $this->testing,
        ];
    }

    public function executeTest() {
        return $this->executeUnitSession();
    }

    public function canReceiveMails() {
        if ($this->no_email === null) {
            return true;
        }

        // If no mail is 0 then user has choose not to receive emails
        if ((int) $this->no_email === 0) {
            return false;
        }

        // If no_mail is set && the timestamp is less that current time then the snooze period has expired
        if ($this->no_email <= time()) {
            // modify subscription settings
            $this->saveSettings(array('no_email' => '1'), array('no_email' => null));
            return true;
        }

        return false;
    }
    
    public static function getTestSession(Run $run) {
        $animal_name = AnimalName::haikunate(["tokenLength" => 0, "delimiter" => "",]) . "XXX";
        $animal_name = str_replace(" ", "", $animal_name);
        $test_code = crypto_token(48 - floor(3 / 4 * strlen($animal_name)));
        $test_code = $animal_name . substr($test_code, 0, 64 - strlen($animal_name));
        $run_session = new RunSession($test_code, $run);
        $run_session->create($test_code, true);

        return $run_session;
    }
    
    public static function getNamedSession(Run $run, $name, $testing = 0) {
        $name = str_replace(" ", "_", $name);
        if ($name && !preg_match('/^[a-zA-Z0-9_-~]{0,32}$/', $name)) {
            alert("Invalid characters in suggested name. Only a-z, numbers, _ - and ~ are allowed. Spaces are automatically replaced by a _.", 'alert-danger');
            return false;
        }

        if ($name) {
            $name .= 'XXX';
        }

        $new_code = crypto_token(48 - floor(3 / 4 * strlen($name)));
        $new_code = $name . substr($new_code, 0, 64 - strlen($name));
        $run_session = new RunSession($new_code, $run); // does this user have a session?
        $run_session->create($new_code, $testing);

        return $run_session;
    }
    
    public function isTestingStudy() {
        return $this->run->isStudyTest() || $this->id === -1;
    }
    
    protected function debug($message = '', $only = false) {
        if (!DEBUG) {
            return;
        }
        if (is_array($message)) {
             unset($message['content']);
        }
        $message = "(Count {$this->executionCount}) " . print_r($message, true);

        if ($this->currentUnitSession && $only === false) {
            formr_log("{$message} {$this->currentUnitSession->runUnit->type} [{$this->currentUnitSession->id}]", $this->id);
        } else {
            formr_log($message, $this->id);
        }
    }

    /**
     * Obtain a MariaDB named lock for the current connection.
     *
     * @param string $name    Lock identifier (≤ 64 chars)
     * @param int    $timeout Seconds to wait
     * @return bool           TRUE = lock acquired
     */
    protected function lockName() {
        return 'run_session_' . ($this->id !== null ? $this->id
                                                    : substr(sha1($this->session), 0, 40));
    }

    protected function acquireLock($name, $timeout = 1) {
        $stmt = $this->db->prepare('SELECT GET_LOCK(:name, :timeout) AS l');
        $stmt->bindValue('name',    $name);
        // Audit (2026-07): bind as string — PDO::PARAM_INT truncated the
        // queue's configured 0.1 s timeout to 0. GET_LOCK accepts decimals.
        $stmt->bindValue('timeout', (string) $timeout);
        $stmt->execute();
        return (int)$stmt->fetchColumn() === 1;
    }

    /**
     * Release a previously acquired named lock.
     *
     * @param string $name
     */
    protected function releaseLock($name) {
        $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
        $stmt->bindValue('name', $name);
        $stmt->execute();
    }

}
