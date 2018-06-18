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

	protected $cache;

	public function __construct(DB $db, array $config) {
		parent::__construct($db, $config);
		$this->maxSessionsPerProcess = array_val($config, 'max_sessions_per_process', 500000);
		$this->unitSessionHelper = new UnitSessionHelper($db);
	}

	public function run($config) {
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
			SELECT us.id, us.unit_id, u.type, us.run_session_id, r.id AS run_id, r.name AS run_name, rs.session
			FROM survey_unit_sessions AS us
			LEFT JOIN survey_units u ON u.id = us.unit_id
			LEFT JOIN survey_run_sessions rs ON rs.id = us.run_session_id
			LEFT JOIN survey_runs r ON r.id = rs.run_id
			WHERE us.ended IS NULL AND r.name IS NOT null
			ORDER BY `us`.`id`  DESC
			LIMIT :offset, :limit
		';

		$offset = array_val($config, 'offset', 0);
		$limit  = array_val($config, 'limit', 1000);

		$stmt = $this->db->prepare($q);
		$stmt->bindParam(':offset', $offset);
		$stmt->bindParam(':limit', $limit);

		$skipUnits = array('Email', 'Shuffle', 'Page');

		while ($offset <= $this->maxSessionsPerProcess) {
			$stmt->execute();
			if ($stmt->rowCount() <= 0) {
				break;
			}
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				// Skip units that do not have an "expiration timestamp"
				if (in_array($row['type'], $skipUnits)) {
					continue;
				}

				// Get expiration date for this session and push to queue
				$row['cron'] = true;

				// @TODO create only necessary objects
				$run = $this->getRun($row['run_name']);
				$runSession = new RunSession($this->db, $row['run_id'], 'cron', $row['session'], $run);
				$unitSession = new UnitSession($this->db, $row['run_session_id'], $row['unit_id'], $row['id']);
				// Unit
				$runUnit = new RunUnit($this->db, $row['session'], $row, $runSession, $run);
				echo sprintf("Unit Session ID: %s\nSession: %s\nRun: %s \n\n", $unitSession->id, $runSession->session, $run->name);
				
			}
			$offset += $limit;
		}

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

}
