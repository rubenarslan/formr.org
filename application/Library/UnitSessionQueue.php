<?php

/**
 * UnitSessionQueue
 * Process unit sessions in unit_sessions_queue
 *
 */


class UnitSessionQueue extends Queue{

	protected $name = 'UnitSession-Queue';

	/**
	 * Maximum sessions to be processed by each PHP process started for a queue operation
	 *
	 * @var int
	 */
	protected $maxSessionsPerProcess;

	/**
	 * Array to hold push query values (50 inserted at once)
	 *
	 * @var array
	 */
	protected $pushQueries = array();
	
	protected $logFile = 'session-queue.log';

	protected $cache;

	public function __construct(DB $db, array $config) {
		parent::__construct($db, $config);
	}

	public function run() {
		if (empty($this->config['use_queue'])) {
			throw new Exception('Explicitely configure $settings[unit_session] to TRUE in order to use DB queuing.');
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
		$now =  time();
		$query = "SELECT session, unit_session_id, run_session_id, unit_id, expires, counter, run 
				  FROM survey_sessions_queue 
				  LEFT JOIN survey_run_sessions ON survey_sessions_queue.run_session_id = survey_run_sessions.id
                  WHERE survey_sessions_queue.expires <= {$now} ORDER BY expires ASC";

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
				continue;
			}
			
			$run = $this->getRun($session['run']);
			$runSession = new RunSession($this->db, $run->id, 'cron', $session['session'], $run);

			// Execute session again by getting current unit
			// This action might end or expire a session, thereby removing it from queue
			// or session might be re-queued to expire in x minutes
			$rsUnit = $runSession->getUnit();
			if ($this->debug) {
				$this->dbg('Proccessed: ' . print_r($session, 1));
			}
		}
	}

	protected function setCache($type, $key, $value) {
		if (!isset($this->cache[$type])) {
			$this->cache[$type] = array();
		}
		$this->cache[$type][$key] = $value;
	}

	protected function getCache($type, $key) {
		if (!empty($this->cache[$type])) {
			return array_val($this->cache[$type], $key, null);
		}
		return null;
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

	public static function removeItem($unitId, $unitSessionId) {
		$db = DB::getInstance();
		$removed = $db->exec(
			"DELETE FROM `survey_sessions_queue` WHERE `unit_session_id` = :unit_session_id AND `unit_id` = :unit_id", 
			array('unit_session_id' => (int)$unitSessionId, 'unit_id' => (int)$unitId)
		);

		return $removed;
	}

}
