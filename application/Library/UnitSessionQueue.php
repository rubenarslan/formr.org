<?php

/**
 * UnitSessionQueue
 * Process unit sessions in unit_sessions_queue
 *
 */
class UnitSessionQueue extends Queue {

    protected $name = 'UnitSession-Queue';

    protected $logFile = 'session-queue.log';

    public function __construct(DB $db, array $config) {
        parent::__construct($db, $config);
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
                        $this->dbg("Sleeping because nothing was found in queue");
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
        $now = time();
        $query = "SELECT session, unit_session_id, run_session_id, unit_id, expires, execute, counter, run 
				  FROM survey_sessions_queue 
				  LEFT JOIN survey_run_sessions ON survey_sessions_queue.run_session_id = survey_run_sessions.id
                  WHERE survey_sessions_queue.expires <= {$now} ORDER BY expires ASC
                  LIMIT {$this->limit} OFFSET {$this->offset}";

        if ($this->debug) {
            $this->dbg($query);
        }
        return $this->db->rquery($query);
    }

    protected function processQueue() {
        $sessionsStmt = $this->getSessionsStatement();
        if ($sessionsStmt->rowCount() <= 0) {
            $sessionsStmt->closeCursor();
            return false;
        }

        while ($session = $sessionsStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!$session['session']) {
                $this->dbg('A session could not be found for item in queue: ' . print_r($session, 1));
                self::removeItem($session['unit_session_id'], $session['unit_id']);
                continue;
            }

            $run = $this->getRun($session['run']);
            if (!$run->valid) {
                self::removeItem($session['unit_session_id'], $session['unit_id']);
                continue;
            }
            $runSession = new RunSession($this->db, $run->id, 'cron', $session['session'], $run);

            if (!$runSession->id) {
                $this->dbg('A run session could not be found for item in queue: ' . print_r($session, 1));
                self::removeItem($session['unit_session_id'], $session['unit_id']);
                continue;
            }

            // Execute session again by getting current unit
            // This action might end or expire a session, thereby removing it from queue
            // or session might be re-queued to expire in x minutes
            $rsUnit = $runSession->getUnit($session['unit_id'], $session['execute']);
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
    protected function getRun($runName) {
        $run = $this->getCache('run', $runName);
        if (!$run) {
            $run = new Run($this->db, $runName);
            $this->setCache('run', $runName, $run);
        }
        return $run;
    }

    /**
     * Remove item from session queue
     *
     * @param int $unitSessionId ID of the unit session
     * @param int $runUnitId ID of the Run unit
     * @return booelan
     */
    public static function removeItem($unitSessionId, $runUnitId) {
        $db = DB::getInstance();
        $removed = $db->exec(
            "DELETE FROM `survey_sessions_queue` WHERE `unit_session_id` = :unit_session_id AND `unit_id` = :unit_id", array('unit_session_id' => (int) $unitSessionId, 'unit_id' => (int) $runUnitId)
        );

        return (bool) $removed;
    }

    /**
     * Add item to session queue
     *
     * @param UnitSession $unitSession
     * @param RunUnit $runUnit
     * @param mixed $execResults
     */
    public static function addItem(UnitSession $unitSession, RunUnit $runUnit, $execResults) {
        $helper = UnitSessionHelper::getInstance();
        $data = $helper->getUnitSessionExpiration($unitSession, $runUnit, $execResults);

        if (!empty($data['expires'])) {
            $q = array(
                'unit_session_id' => $unitSession->id,
                'run_session_id' => $unitSession->run_session_id,
                'unit_id' => $runUnit->id,
                'created' => time(),
                'expires' => $data['expires'],
                'execute' => (int) $data['execute'],
                'run' => $runUnit->run->name,
                'counter' => 1,
            );

            $db = DB::getInstance();
            $db->insert_update('survey_sessions_queue', $q, array('expires', 'counter' => '::counter + 1'));
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

        $where['unit_session_id'] = $unitSession->id;
        return DB::getInstance()->findRow('survey_sessions_queue', $where, array('run_session_id', 'created', 'expires', 'run'));
    }

}
