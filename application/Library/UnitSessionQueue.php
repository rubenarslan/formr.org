<?php

/**
 * UnitSessionQueue
 * Process unit sessions in unit_sessions_queue
 *
 */

/**
SELECT us.id, us.unit_id, u.type, us.run_session_id, r.name, rs.session
FROM survey_unit_sessions AS us
LEFT JOIN survey_units u ON u.id = us.unit_id
LEFT JOIN survey_run_sessions rs ON rs.id = us.run_session_id
LEFT JOIN survey_runs r ON r.id = rs.run_id
WHERE us.ended IS NULL AND r.name IS NOT null
ORDER BY `us`.`id`  DESC
LIMIT 1000
 */

class UnitSessionQueue extends Queue{

	protected static $name = 'UnitSession-Queue';

	/**
	 *
	 * @var UnitSessionHelper
	 */
	protected $unitSessionHelper;

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

	protected $cache;

	public function __construct(DB $db, array $config) {
		parent::__construct($db, $config);
		$this->unitSessionHelper = new UnitSessionHelper($db);
	}

	public function run($config) {
		$this->maxSessionsPerProcess = array_val($config, 'max-sessions', 500000);
		$operation = array_val($config, 'queue_operation');
		if (!in_array($operation, array('pop', 'push'))) {
			$this->out = true;
		}

		// loop forever until terminated by SIGINT
		while (!$this->out) {
			try {
				// loop until terminated but with taking some nap
				$sleeps = 0;
				while (!$this->out && $this->rested()) {
					if ($this->{$operation}($config) === false) {
						// if there is nothing to process in the queue sleep for sometime
						self::dbg("Sleeping because nothing was found in queue");
						sleep($this->sleep);
						$sleeps++;
					}
					if ($sleeps > $this->allowedSleeps) {
						// exit to restart supervisor process
						self::dbg('Exit and restart process because you have slept alot');
						$this->out = true;
					}
				}
			} catch (Exception $e) {
				// if connection disappeared - try to restore it
				$error_code = $e->getCode();
				if ($error_code != 1053 && $error_code != 2006 && $error_code != 2013 && $error_code != 2003) {
					throw $e;
				}

				self::dbg($e->getMessage() . "[" . $error_code . "]");

				self::dbg("Unable to connect. waiting 5 seconds before reconnect.");
				sleep(5);
			}
		}
	}

	/**
	 * Add items to queue
	 *
	 * @param array $config
	 * @return boolean
	 */
	protected function push($config) {

		$q = '
			SELECT us.id AS session_id, us.unit_id, u.type, us.run_session_id, r.id AS run_id, r.name AS run_name, rs.session, ru.position
			FROM survey_unit_sessions AS us
			LEFT JOIN survey_units u ON u.id = us.unit_id
			LEFT JOIN survey_run_sessions rs ON rs.id = us.run_session_id
			LEFT JOIN survey_runs r ON r.id = rs.run_id
			LEFT JOIN survey_run_units ru ON ru.unit_id = us.unit_id
			WHERE us.ended IS NULL AND r.name IS NOT NULL AND us.queueable = 1 AND us.id NOT IN (SELECT unit_session_id FROM survey_sessions_queue)
			ORDER BY `us`.`id`  DESC
			LIMIT :offset, :limit
		';
		// @todo: If the NOT IN condition takes too long then mark items as queueable = 0 when adding them to queue and mark them as queueable = 1 when removing from queue in survey_unit_sessions

		$offset = array_val($config, 'batch-offset', 0);
		$limit  = array_val($config, 'batch-limit', 1000);

		$stmt = $this->db->prepare($q);
		$stmt->bindParam(':offset', $offset);
		$stmt->bindParam(':limit', $limit);

		$skipUnits = array('Email', 'Shuffle', 'Page', 'Endpage');
		$unitFactory = new RunUnitFactory();

		while ($offset <= $this->maxSessionsPerProcess) {
			$stmt->execute();
			if ($stmt->rowCount() <= 0) {
				break;
			}
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$unit = $row;
				$unit['cron'] = true;
				$pushQuery = array(
					'unit_id' => $unit['unit_id'],
					'unit_session_id' => $unit['session_id'],
					'run_session_id' => $unit['run_session_id'],
					'refresh' => false,
				);

				// @TODO create only necessary objects
				$run = $this->getRun($row['run_name']);
				$unitSession = new UnitSession($this->db, $unit['run_session_id'], $unit['unit_id'], $unit['session_id']);
				$runSession = new RunSession($this->db, $row['run_id'], 'cron', $row['session'], $run);
				$runSession->unit_session = $unitSession;
				$runUnit = $unitFactory->make($this->db, $row['session'], $unit, $runSession, $run);
				// Skip units that do not have an "expiration timestamp"
				// Execute them directly and do not add them to queue except they demand so on execution i.e do not return FALSE on execution
				if (in_array($unit['type'], $skipUnits)) {
					try {
						$output = $runUnit->exec();
					} catch (Exception $e) {
						// Log error to run error log file
						formr_log_exception($e);
						$runUnit->end();
						$output = false;
					}
					if ($output !== false) {
						// maybe add to queue to process again later in x (configured) minutes
						// Othewise it will be executed each time the 'push' queue runs.
						$pushQuery['expires'] = strtotime('+10 minutes');
						$pushQuery['refresh'] = true;
						$this->pushQuery($pushQuery);

						// @TODO: Maybe bookmark sessions that are causing errors and alert study creator for formr admins
					}

					continue;
				}

				// @TODO: Get expiration date for this session and push to queue
				$expires = (int) $this->unitSessionHelper->getUnitSessionExpiration($runUnit->type, $unitSession, $runUnit);
				if ($expires && $expires < time()) {
					// Execute and end already expired sessions
					$runUnit->exec();
					$runUnit->end();
				} elseif ($expires) {
					$pushQuery['expires'] = $expires;
					$this->pushQuery($pushQuery);
				} else {
					// flag session as not queue-able and don't include in next query
					$this->db->update('survey_unit_sessions', array('queueable' => 0), array('id' => (int)$unit['session_id']));
				}
			}
			$offset += $limit;
			self::dbg("Go to next offset {$offset}");
		}
		self::dbg("End push process");
		$stmt->closeCursor();
		return false;
	}

	/**
	 * Remove items from queue
	 *
	 * @param array $config
	 * @return boolean
	 */
	protected function pop($config) {
		return false;
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

	protected function pushQuery($query) {
		$refresh = $query['refresh'];
		unset($query['refresh']);
		$updates = array('unit_id');
		if ($refresh) {
			$updates[] = 'expires';
		}

		return $this->db->insert_update('survey_sessions_queue', $query, $updates);
	}

}
