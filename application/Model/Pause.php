<?php

class Pause extends RunUnit {

	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	public $ended = false;
	public $type = "Pause";
	public $icon = "fa-pause";

	protected $body = '';
	protected $body_parsed = '';
	protected $relative_to = null;
	protected $wait_minutes = null;
	protected $wait_until_time = null;
	protected $wait_until_date = null;

	
	private $has_relative_to = false;
	private $has_wait_minutes = false;
	private $relative_to_result = null;
	
	/**
	 * An array of unit's exportable attributes
	 * @var array
	 */
	public $export_attribs = array('type', 'description', 'position', 'special', 'wait_until_time', 'wait_until_date', 'wait_minutes', 'relative_to', 'body');

	public function __construct($fdb, $session = null, $unit = null, $run_session = NULL, $run = NULL) {
		parent::__construct($fdb, $session, $unit, $run_session, $run);

		if ($this->id):
			$vars = $this->dbh->select('id, body, body_parsed, wait_until_time, wait_minutes, wait_until_date, relative_to')
							->from('survey_pauses')
							->where(array('id' => $this->id))
							->limit(1)->fetch();

			if ($vars):
				array_walk($vars, "emptyNull");
				$this->body = $vars['body'];
				$this->body_parsed = $vars['body_parsed'];
				$this->wait_until_time = $vars['wait_until_time'];
				$this->wait_until_date = $vars['wait_until_date'];
				$this->wait_minutes = $vars['wait_minutes'];
				$this->relative_to = $vars['relative_to'];

				$this->valid = true;
			endif;
		endif;
	}

	public function create($options) {
		$this->dbh->beginTransaction();
		if (!$this->id) {
			$this->id = parent::create($this->type);
		} else {
			$this->modify($options);
		}

		if (isset($options['body'])) {
			array_walk($options, "emptyNull");
			$this->body = $options['body'];
			$this->wait_until_time = $options['wait_until_time'];
			$this->wait_until_date = $options['wait_until_date'];
			$this->wait_minutes = $options['wait_minutes'];
			$this->relative_to = $options['relative_to'];
		}

		$parsedown = new ParsedownExtra();
		$parsedown->setBreaksEnabled(true);

		if (!$this->knittingNeeded($this->body)) {
			$this->body_parsed = $parsedown->text($this->body); // transform upon insertion into db instead of at runtime
		}

		$this->dbh->insert_update('survey_pauses', array(
			'id' => $this->id,
			'body' => $this->body,
			'body_parsed' => $this->body_parsed,
			'wait_until_time' => $this->wait_until_time,
			'wait_until_date' => $this->wait_until_date,
			'wait_minutes' => $this->wait_minutes,
			'relative_to' => $this->relative_to,
		));
		$this->dbh->commit();
		$this->valid = true;

		return true;
	}

	public function displayForRun($prepend = '') {
		$dialog = Template::get($this->getUnitTemplatePath(), array(
			'prepend' => $prepend,
			'wait_until_time' => $this->wait_until_time,
			'wait_until_date' => $this->wait_until_date,
			'wait_minutes' => $this->wait_minutes,
			'relative_to' => $this->relative_to,
			'body' => $this->body,
		));

		return parent::runDialog($dialog);
	}

	public function removeFromRun($special = null) {
		return $this->delete($special);
	}

	protected function checkRelativeTo() {
		$this->relative_to = trim($this->relative_to);
		$this->wait_minutes = trim($this->wait_minutes);
		$this->has_wait_minutes = !($this->wait_minutes === null || $this->wait_minutes == '');
		$this->has_relative_to = !($this->relative_to === null || $this->relative_to == '');

		// disambiguate what user meant
		if ($this->has_wait_minutes && !$this->has_relative_to) {
			// If user specified waiting minutes but did not specify relative to which timestamp,
			// we imply we are waiting relative to when the user arrived at the pause
			$this->relative_to = 'tail(survey_unit_sessions$created,1)';
			$this->has_relative_to = true;
		}

		return $this->has_relative_to;
	}

	protected function checkWhetherPauseIsOver() {
		$this->execData['check_failed'] = false;
		$this->execData['expire_relatively'] = null;

		// if a relative_to has been defined by user or automatically, we need to retrieve its value
		if ($this->has_relative_to) {
			$opencpu_vars = $this->getUserDataInRun($this->relative_to);
			$result = opencpu_evaluate($this->relative_to, $opencpu_vars, 'json');

			if ($result === null) {
				$this->execData['check_failed'] = true;
				return false;
			}
			$this->relative_to_result = $relative_to = $result;
		}

		$bind_relative_to = false;
		$conditions = array();

		if (!$this->has_wait_minutes && $this->has_relative_to) {
			// if no wait minutes but a relative to was defined, we just use this as the param (useful for complex R expressions)
			if ($relative_to === true) {
				$conditions['relative_to'] = '1=1';
				$this->execData['expire_relatively'] = true;
			} elseif ($relative_to === false) {
				$conditions['relative_to'] = '0=1';
				$this->execData['expire_relatively'] = false;
			} elseif (!is_array($relative_to) && strtotime($relative_to)) {
				$conditions['relative_to'] = ':relative_to <= NOW()';
				$bind_relative_to = true;
				$this->execData['expire_timestamp'] = strtotime($relative_to);
				// If there was a wait_time, set the timestamp to have this time
				if ($time = $this->parseWaitTime(true)) {
					$ts = $this->execData['expire_timestamp'];
					$this->execData['expire_timestamp'] = mktime($time[0], $time[1], 0, date('m', $ts), date('d', $ts), date('Y', $ts));
				}
			} else {
				alert("Pause {$this->position}: Relative to yields neither true nor false, nor a date, nor a time. " . print_r($relative_to, true), 'alert-warning');
				$this->execData['check_failed'] = true;
				return false;
			}
		} elseif ($this->has_wait_minutes) {
			if (!is_array($relative_to) && strtotime($relative_to)) {
				$conditions['minute'] = "DATE_ADD(:relative_to, INTERVAL :wait_minutes MINUTE) <= NOW()";
				$bind_relative_to = true;
				$this->execData['expire_timestamp'] = strtotime($relative_to) + ($this->wait_minutes * 60);
			} else {
				alert("Pause {$this->position}: Relative to yields neither a date, nor a time. " . print_r($relative_to, true), 'alert-warning');
				$this->execData['check_failed'] = true;
				return false;
			}
		}

		if ($this->wait_until_date && $this->wait_until_date != '0000-00-00') {
			$wait_date = $this->wait_until_date;
		}

		if ($this->wait_until_time && $this->wait_until_time != '00:00:00') {
			$wait_time = $this->wait_until_time;
		}

		$wait_date = $this->parseWaitDate();
		$wait_time = $this->parseWaitTime();

		if (!empty($wait_date) && empty($wait_time)) {
			$wait_time = '00:00:01';
		}
		
		if (!empty($wait_time) && empty($wait_date)) {
			$wait_date = date('Y-m-d');
		}

		if (!empty($wait_date) && !empty($wait_time) && empty($this->execData['expire_timestamp'])) {
			$wait_datetime = $wait_date . ' ' . $wait_time;
			$this->execData['expire_timestamp'] = strtotime($wait_datetime);

			// If the expiration hour already passed before the user entered the pause, set expiration to the next day (in 24 hours)
			$exp_ts =  $this->execData['expire_timestamp'];
			$created_ts = strtotime($this->run_session->unit_session->created);
			$exp_hour_min = mktime(date('G', $exp_ts), date('i', $exp_ts), 0);
			if ($created_ts > $exp_hour_min) {
				$this->execData['expire_timestamp'] += 24 * 60 * 60;
				return false;
			}
/*
			// Check if this unit already expired today for current run_session_id
			$q = '
				SELECT 1 AS finished FROM `survey_unit_sessions`
				WHERE `survey_unit_sessions`.unit_id = :id AND `survey_unit_sessions`.run_session_id = :run_session_id AND DATE(`survey_unit_sessions`.ended) = CURDATE()
				LIMIT 1
			';
			$stmt = $this->dbh->prepare($q);
			$stmt->bindValue(':id', $this->id);
			$stmt->bindValue(':run_session_id', $this->run_session_id);
			$stmt->execute();
			if ($stmt->rowCount() > 0) {
				$this->execData['expire_timestamp'] = strtotime('+1 day', $this->execData['expire_timestamp']);
				return false;
			}
*/

			$conditions['datetime'] = ':wait_datetime <= NOW()';
		}

		$result = !empty($this->execData['expire_timestamp']) && $this->execData['expire_timestamp'] <= time();

		if ($conditions) {
			$condition = implode(' AND ', $conditions);
			$stmt = $this->dbh->prepare("SELECT {$condition} AS test LIMIT 1");
			if ($bind_relative_to) {
				$stmt->bindValue(':relative_to', $relative_to);
			}
			if (isset($conditions['minute'])) {
				$stmt->bindValue(':wait_minutes', $this->wait_minutes);
			}
			if (isset($conditions['datetime'])) {
				$stmt->bindValue(':wait_datetime', $wait_datetime);
			}

			$stmt->execute();
			if ($stmt->rowCount() === 1 && ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
				$result = (bool)$row['test'];
			}
		} else {
			$result = true;
		}

		$this->execData['pause_over'] = $result;
		return $result;
	}

	protected function parseWaitTime($parts = false) {
		if ($this->wait_until_time && $this->wait_until_time != '00:00:00') {
			return $parts ? explode(':', $this->wait_until_time) : $this->wait_until_time;
		}

		return null;
	}

	protected function parseWaitDate($parts = false) {
		if ($this->wait_until_date && $this->wait_until_date != '0000-00-00') {
			return $parts ? explode('-', $this->wait_until_date) : $this->wait_until_date;
		}
		
		return null;
	}

	public function test() {
		if (!$this->knittingNeeded($this->body)) {
			echo "<h3>Pause message</h3>";
			echo $this->getParsedBodyAdmin($this->body);
		}

		$results = $this->getSampleSessions();
		if (!$results) {
			return false;
		}
		if ($this->knittingNeeded($this->body)) {
			echo "<h3>Pause message</h3>";
			echo $this->getParsedBodyAdmin($this->body);
		}
		if ($this->checkRelativeTo()) {
			// take the first sample session
			$this->run_session_id = current($results)['id'];
			echo "<h3>Pause relative to</h3>";

			$opencpu_vars = $this->getUserDataInRun($this->relative_to);
			$session = opencpu_evaluate($this->relative_to, $opencpu_vars, 'json', null, true);

			echo opencpu_debug($session);
		}

		if (!empty($results) && (empty($session) || !$session->hasError())) {

			echo '<table class="table table-striped">
					<thead><tr>
						<th>Code</th>';
			if ($this->has_relative_to) {
				echo '<th>Relative to</th>';
			}
			echo '<th>Wait?</th>
					</tr></thead>
					<tbody>';

			foreach ($results AS $row):
				$this->run_session_id = $row['id'];

				$result = $this->checkWhetherPauseIsOver();
				echo "<tr>
						<td style='word-wrap:break-word;max-width:150px'><small>" . $row['session'] . " ({$row['position']})</small></td>";
				if ($this->has_relative_to) {
					echo "<td><small>" . stringBool($this->relative_to_result) . "</small></td>";
				}
				echo "<td>" . stringBool($result) . "</td>
					</tr>";

			endforeach;
			echo '</tbody></table>';
		}
	}

	public function exec() {
		$this->checkRelativeTo();
		if ($this->checkWhetherPauseIsOver()) {
			$this->end();
			return false;
		} else {
			$body = $this->getParsedBody($this->body);
			if ($body === false) {
				return true; // openCPU errors
			}
			return array(
				'body' => $body
			);
		}
	}

}
