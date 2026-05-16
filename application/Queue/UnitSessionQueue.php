<?php

/**
 * UnitSessionQueue
 * Process unit sessions in survey_unit_sessions
 *
 */
class UnitSessionQueue extends Queue {

    protected $name = 'UnitSession-Queue';

    protected $logFile = 'session-queue.log';
    
    const QUEUED_TO_EXECUTE = 1;
    const QUEUED_TO_END = 2;
    const QUEUED_NOT = 0;
    const QUEUED_SUPERCEDED = -9;

    // Track A: named state values written into survey_unit_sessions.state
    // alongside the legacy `queued` column. The mapping isn't 1:1 with
    // queued — `queued` answers "is this in the cron pickup set?", `state`
    // answers "what is this row's lifecycle position?". See
    // REFACTOR_QUEUE_PLAN.md A3b for design rationale.
    const STATE_PENDING       = 'PENDING';
    const STATE_RUNNING       = 'RUNNING';
    const STATE_WAITING_USER  = 'WAITING_USER';
    const STATE_WAITING_TIMER = 'WAITING_TIMER';
    const STATE_ENDED         = 'ENDED';
    const STATE_EXPIRED       = 'EXPIRED';
    const STATE_SUPERSEDED    = 'SUPERSEDED';

    /**
     * Map a queued unit-session to its named state based on the runUnit type.
     * Used by addItem() to dual-write the state column. The split:
     *   - Survey / External / Page: WAITING_USER
     *     (interactive units; the participant is the next actor; the
     *     queue's expires deadline is the fallback timer for inactivity)
     *   - everything else (Pause, Wait, Branch, SkipForward/Backward, Shuffle,
     *     Email, PushMessage): WAITING_TIMER
     *     (no participant interaction; the cron tick or expires deadline
     *     is the next actor)
     */
    public static function stateForQueuedUnit(RunUnit $runUnit) {
        if (in_array($runUnit->type, ['Survey', 'External', 'Page', 'Endpage'], true)) {
            return self::STATE_WAITING_USER;
        }
        return self::STATE_WAITING_TIMER;
    }

    public function __construct(DB $db, array $config) {
		$this->list_type = array_val($config, 'list_type', null);
        parent::__construct($db, $config);
    }

    /**
     * Process the queue exactly once and return. Used by tests
     * (`bin/queue.php -t UnitSession --once`) to drive the daemon
     * deterministically; no sleeps, no restart-supervisor behaviour.
     */
    public function runOnce() {
        $this->processQueue();
    }

    public function run() {
        if (empty($this->config['use_queue'])) {
            throw new Exception('Explicitely configure $settings[unit_session][use_queue] to TRUE in order to use DB queuing.');
        }

        // loop forever until terminated by SIGINT
        while (!$this->out) {
            try {
                // loop until terminated but with taking some nap
                $sleeps = 0;
                while (!$this->out && $this->rested()) {
                    if ($this->processQueue() === false) {
                        // if there is nothing to process in the queue sleep for sometime
//                        $this->dbg("Sleeping because nothing was found in queue");
                        sleep($this->sleep);
                        $sleeps++;
                    }
                    if ($sleeps > $this->allowedSleeps) {
                        // exit to restart supervisor process
                        $this->dbg('Exit and restart process because you have slept alot');
                        $this->out = true;
                    }
                }
            } catch (Exception $e) {
                // if connection disappeared - try to restore it
                $error_code = $e->getCode();
                if ($error_code != 1053 && $error_code != 2006 && $error_code != 2013 && $error_code != 2003) {
                    throw $e;
                }

                $this->dbg($e->getMessage() . "[" . $error_code . "]");

                $this->dbg("Unable to connect. waiting 5 seconds before reconnect.");
                sleep(5);
            }
        }
    }

    /**
     * 
     * @param int $account_id
     * @return PDOStatement
     */
    protected function getSessionsStatement() {
        if ($this->list_type === 'fixed') {
			$where = ' survey_unit_sessions.queued = :queued ';
            $queued = self::QUEUED_TO_END;
        } elseif ($this->list_type === 'execute') {
            $where = ' survey_unit_sessions.queued = :queued ';
            $queued = self::QUEUED_TO_EXECUTE;
        } else {
            $where = 'survey_unit_sessions.queued >= :queued ';
            $queued = self::QUEUED_TO_EXECUTE;
        }
        
        $query = "SELECT survey_unit_sessions.id, survey_unit_sessions.run_session_id, survey_unit_sessions.unit_id, 
                survey_unit_sessions.expires, survey_unit_sessions.queued, 
                survey_run_sessions.session, survey_run_sessions.run_id 
			FROM survey_unit_sessions
            LEFT JOIN survey_run_sessions ON survey_unit_sessions.run_session_id = survey_run_sessions.id
            LEFT JOIN survey_runs ON survey_run_sessions.run_id = survey_runs.id
            WHERE {$where} AND survey_runs.cron_active = 1 AND survey_unit_sessions.expires <= NOW() 
            ORDER BY RAND();";
            //LIMIT {$this->limit} OFFSET {$this->offset}";
                  
        if ($this->debug) {
            $this->dbg($query . ' queued: ' . $queued);
        }
        
        return $this->db->rquery($query, array('queued' => $queued));
    }

    protected function processQueue() {
        $sessionsStmt = $this->getSessionsStatement();
		
		$this->dbg('Count: ' . $sessionsStmt->rowCount());
        if ($sessionsStmt->rowCount() <= 0) {
            $sessionsStmt->closeCursor();
            return false;
        }

        while ($session = $sessionsStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!$session['session']) {
                $this->dbg('A session could not be found for item in queue: ' . print_r($session, 1));
                self::removeItem($session['id']);
                continue;
            }

            $run = $this->getRun($session['run_id']);
            if (!$run->valid || !$run->cron_active) {
                continue;
            }

            $runSession = run_session(new RunSession($session['session'], $run));
            if (!$runSession->id) {
                $this->dbg('A run session could not be found for item in queue: ' . print_r($session, 1));
                self::removeItem($session['id']);
                continue;
            }

            // Track A A5 / A7 fix: mark the run-session's user as cron-
            // driven so isExecutedByCron() (which delegates to
            // User->isCron()) returns true downstream. Without this,
            // Email::cron_only never fires correctly — the gate sees
            // every cron tick as a user-driven request and skips the
            // send. Pre-fix this latent bug meant cron_only=true emails
            // were effectively undeliverable. See REFACTOR_QUEUE_PLAN.md
            // A5 + tests/EmailCronOnlyTest.php.
            $runSession->user->cron = true;

            // Execute session again by getting current unit
            // This action might end or expire a session, thereby removing it from queue
            // or session might be re-queued to expire in x minutes
            $unitSession = new UnitSession($runSession, null, ['id' => $session['id'], 'load' => true]);
            $execution = $runSession->execute($unitSession,  $session['queued'] == self::QUEUED_TO_EXECUTE);

            if ($this->debug) {
                $this->dbg('Proccessed: ' . print_r($session, 1));
            }
        }
    }

    protected function setCache($type, $key, $value) {
        $cache_key = "{$type}.{$key}";
        Cache::set($cache_key, $value);
    }

    protected function getCache($type, $key) {
        $cache_key = "{$type}.{$key}";
        return Cache::get($cache_key);
    }

    /**
     * Get Run Object
     *
     * @param string $runName
     * @return \Run
     */
    protected function getRun($runId) {
        $run = $this->getCache('run', $runId);
        if (!$run) {
            $run = new Run(null, $runId);
            $this->setCache('run', $runId, $run);
        }
        return $run;
    }

    /**
     * Remove item from session queue
     *
     * @param int $unitSessionId ID of the unit session
     * @return bool
     */
    public static function removeItem($unitSessionId) {
        $db = DB::getInstance();
        $removed = $db->update('survey_unit_sessions', array('queued' => self::QUEUED_NOT), array('id' => $unitSessionId));

        return (bool) $removed;
    }

    /**
     * Add item to session queue
     *
     * @param UnitSession $unitSession
     * @param RunUnit $runUnit
     * @param array $data Data array description expiration info
     * @param mixed $execResults
     */
    public static function addItem(UnitSession $unitSession, RunUnit $runUnit, $data, $execResults = null) {
        if (!empty($data['expires'])) {
            $db = DB::getInstance();
            $expires = mysql_datetime($data['expires']);
            $unitSession->expires = $expires;
            // Track A: dual-write the named state alongside the legacy
            // queued/expires update. The state value is derived from the
            // runUnit type via stateForQueuedUnit().
            $state = self::stateForQueuedUnit($runUnit);
            $db->update('survey_unit_sessions', array(
                'expires' => $expires,
                'queued' => $data['queued'],
                'state' => $state,
            ), array('id' => $unitSession->id));
            $unitSession->state = $state;
        } else {
            UnitSessionQueue::removeItem($unitSession->id);
        }
    }

    /**
     * Find a UnitSession in the queue
     *
     * @param UnitSession $unitSession
     * @param array $where
     * @return boolean|array Returns FALSE if an item was not found or an array with queue information
     */
    public static function findItem(UnitSession $unitSession, $where = array()) {
        if (!Config::get('unit_session.use_queue')) {
            return false;
        }

        $where['id'] = $unitSession->id;
        return DB::getInstance()->findRow('survey_unit_sessions', $where, array('run_session_id', 'created', 'expires', 'queued'));
    }
    
    public static function getRunItems(Run $run) {
        // Track A: also project state, iteration, run_unit_id so admin
        // templates can render the named state alongside the legacy
        // queued column. Legacy rows (pre-047) have NULL state and the
        // template falls back to the queued mapping below in
        // queueLabelForRow().
        $query = '
          SELECT survey_unit_sessions.run_session_id, survey_unit_sessions.id as unit_session_id, session, position,
                 unit_id, survey_unit_sessions.run_unit_id, survey_unit_sessions.iteration,
                 survey_unit_sessions.created, expires, queued, survey_unit_sessions.state,
                 survey_units.type as unit_type
          FROM survey_unit_sessions
          LEFT JOIN survey_run_sessions ON survey_run_sessions.id = survey_unit_sessions.run_session_id
          LEFT JOIN survey_units ON survey_units.id = survey_unit_sessions.unit_id
          WHERE survey_run_sessions.run_id = :run AND survey_unit_sessions.queued > :no_queued
          ORDER BY unit_session_id DESC
        ';

        return DB::getInstance()->rquery($query, array('run' => $run->id, 'no_queued' => self::QUEUED_NOT));
    }

    /**
     * Render-side helper: return a short human label for a queue row's
     * lifecycle state. Prefers the new `state` column (Track A) when
     * present; falls back to the legacy `queued` magic-value mapping for
     * pre-047 rows. Templates use this to render the "To Execute" /
     * "State" column without re-implementing the mapping.
     */
    public static function queueLabelForRow(array $row): array {
        $state = $row['state'] ?? null;
        if ($state) {
            // Color hint follows admin AdminLTE label-* convention.
            $colors = [
                self::STATE_PENDING       => 'default',
                self::STATE_RUNNING       => 'info',
                self::STATE_WAITING_USER  => 'primary',
                self::STATE_WAITING_TIMER => 'warning',
                self::STATE_ENDED         => 'success',
                self::STATE_EXPIRED       => 'danger',
                self::STATE_SUPERSEDED    => 'default',
            ];
            return ['label' => $state, 'color' => $colors[$state] ?? 'default'];
        }

        // Legacy fallback — decode the magic queued value.
        $queued = (int) ($row['queued'] ?? 0);
        switch ($queued) {
            case self::QUEUED_TO_EXECUTE:  return ['label' => 'TO_EXECUTE', 'color' => 'success'];
            case self::QUEUED_TO_END:      return ['label' => 'TO_END',     'color' => 'warning'];
            case self::QUEUED_NOT:         return ['label' => 'NOT_QUEUED', 'color' => 'default'];
            case self::QUEUED_SUPERCEDED:  return ['label' => 'SUPERSEDED', 'color' => 'default'];
            default:                       return ['label' => 'queued=' . $queued, 'color' => 'default'];
        }
    }

}
