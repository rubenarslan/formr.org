<?php

/**
 * UnitSessionQueue
 * Process unit sessions in unit_sessions_queue
 *
 */
class UnitSessionQueue extends Queue {

    protected $name = 'UnitSession-Queue';

    protected $logFile = 'session-queue.log';
    
    protected $list_type = null;
    
    const QUEUED_TO_EXECUTE = 1;
    const QUEUED_TO_END = 2;
    const QUEUED_NOT = 0;

    public function __construct(DB $db, array $config) {
        parent::__construct($db, $config);
		$this->list_type = array_val($this->config, 'list_type', null);
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
        if ($this->list_type == 'fixed') {
			$where = ' survey_unit_sessions.queued = :queued ';
            $queued = self::QUEUED_TO_END;
        } elseif ($this->list_type == 'execute') {
            $where = ' survey_unit_sessions.queued = :queued ';
            $queued = self::QUEUED_TO_EXECUTE;
        } else {
            $where = 'survey_unit_sessions.queued >= :queued ';
            $queued = self::QUEUED_TO_EXECUTE;
        }
        
        $query = "SELECT survey_unit_sessions.id, survey_unit_sessions.run_session_id, survey_unit_sessions.unit_id, 
                survey_unit_sessions.expires, survey_unit_sessions.result, survey_unit_sessions.queued, 
                survey_run_sessions.session, survey_run_sessions.run_id, survey_run_sessions.id AS run_session_id 
			FROM survey_unit_sessions
            LEFT JOIN survey_run_sessions ON survey_unit_sessions.run_session_id = survey_run_sessions.id
            WHERE {$where} AND survey_unit_sessions.expires <= :now  
            ORDER BY survey_unit_sessions.id ASC";
            //LIMIT {$this->limit} OFFSET {$this->offset}";
                  
        if ($this->debug) {
            $this->dbg($query . ' queued: ' . $queued);
        }
        return $this->db->rquery($query, array(
            'now' => mysql_datetime(), 
            'queued' => $queued,
        ));
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
                self::removeItem($session['id'], $session['unit_id']);
                continue;
            }

            $run = $this->getRun($session['run_id']);
            if (!$run->valid || !$run->cron_active) {
                self::removeItem($session['id'], $session['unit_id']);
                continue;
            }
            $runSession = new RunSession($this->db, $run->id, 'cron', $session['session'], $run);

            if (!$runSession->id) {
                $this->dbg('A run session could not be found for item in queue: ' . print_r($session, 1));
                self::removeItem($session['id'], $session['unit_id']);
                continue;
            }

            // Execute session again by getting current unit
            // This action might end or expire a session, thereby removing it from queue
            // or session might be re-queued to expire in x minutes
            
            $unitSession = new UnitSession($this->db, $session['run_session_id'], $session['unit_id'], $session['id'], false);
            $rsUnit = $runSession->execute($unitSession, $session['queued'] == self::QUEUED_TO_EXECUTE);
            
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
            $run = new Run($this->db, null, $runId);
            $this->setCache('run', $runId, $run);
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
        $removed = $db->update('survey_unit_sessions', array(
                'queued' => 0,
        ), array('id' => $unitSessionId));

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
            $db = DB::getInstance();
            $db->update('survey_unit_sessions', array(
                'expires' => mysql_datetime($data['expires']),
                'queued' => $data['queued'],
            ), array('id' => $unitSession->id));
        } else {
            UnitSessionQueue::removeItem($unitSession->id, $runUnit->id);
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
        $query = '
          SELECT survey_unit_sessions.run_session_id, survey_unit_sessions.id as unit_session_id, session, position, unit_id, survey_unit_sessions.created, expires, queued, survey_units.type as unit_type
          FROM survey_unit_sessions
          LEFT JOIN survey_run_sessions ON survey_run_sessions.id = survey_unit_sessions.run_session_id
          LEFT JOIN survey_units ON survey_units.id = survey_unit_sessions.unit_id
          WHERE survey_run_sessions.run_id = :run AND survey_unit_sessions.queued > :no_queued
          ORDER BY unit_session_id DESC
        ';
        
        return DB::getInstance()->rquery($query, array('run' => $run->id, 'no_queued' => self::QUEUED_NOT));
    }

}
