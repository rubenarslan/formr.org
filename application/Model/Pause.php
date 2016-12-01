<?php

class Pause extends RunUnit {

	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	protected $body = '';
	protected $body_parsed = '';
	protected $relative_to = null;
	protected $wait_minutes = null;
	protected $wait_until_time = null;
	protected $wait_until_date = null;
	public $ended = false;
	public $type = "Pause";
	public $icon = "fa-pause";
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
		$dialog = '<p>
				
				<label class="inline hastooltip" title="Leave empty so that this does not apply">wait until time: 
				<input style="width:200px" class="form-control" type="time" placeholder="e.g. 12:00" name="wait_until_time" value="' . h($this->wait_until_time) . '">
				</label> <strong>and</strong>
				
				</p>
				<p>
				<label class="inline hastooltip" title="Leave empty so that this does not apply">wait until date: 
				<input style="width:200px" class="form-control" type="date" placeholder="e.g. 01.01.2000" name="wait_until_date" value="' . h($this->wait_until_date) . '">
				</label> <strong>and</strong>
				
				</p>
				<p class="well well-sm">
					<span class="input-group">
						<input class="form-control" type="number" style="width:230px" placeholder="wait this many minutes" name="wait_minutes" value="' . h($this->wait_minutes) . '">
				        <span class="input-group-btn">
							<button class="btn btn-default from_days hastooltip" title="Enter a number of days and press this button to convert them to minutes (*60*24)"><small>convert days</small></button>
						</span>
					</span>
					
				 <label class="inline">relative to 
					<textarea data-editor="r" style="width:368px;" rows="2" class="form-control" placeholder="arriving at this pause" name="relative_to">' . h($this->relative_to) . '</textarea>
					</label
				</p> 
		<p><label>Text to show while waiting: <br>
			<textarea style="width:388px;"  data-editor="markdown" class="form-control col-md-5" placeholder="You can use Markdown" name="body" rows="10">' . h($this->body) . '</textarea>
		</label></p>
			';
		$dialog .= '<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Pause">Save</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=Pause">Test</a></p>';


		$dialog = $prepend . $dialog;

		return parent::runDialog($dialog, 'fa-pause');
	}

	public function removeFromRun($special = null) {
		return $this->delete($special);
	}

	protected function checkRelativeTo() {
		$this->relative_to = trim($this->relative_to);
		$this->wait_minutes = trim($this->wait_minutes);
		$this->wait_minutes_true = !($this->wait_minutes === null || $this->wait_minutes == '');
		$this->relative_to_true = !($this->relative_to === null || $this->relative_to == '');

		// disambiguate what user meant
		if ($this->wait_minutes_true && !$this->relative_to_true):  // user said wait minutes relative to, implying a relative to
			$this->relative_to = 'tail(survey_unit_sessions$created,1)'; // we take this as implied, this is the time someone arrived at this pause
			$this->relative_to_true = true;
		endif;
		return $this->relative_to_true;
	}

	protected function checkWhetherPauseIsOver() {
		$conditions = array();

		// if a relative_to has been defined by user or automatically, we need to retrieve its value
		if ($this->relative_to_true) {
			$opencpu_vars = $this->getUserDataInRun($this->relative_to);
			$result = opencpu_evaluate($this->relative_to, $opencpu_vars, 'json');
			if ($result === null) {
				return false;
			}
			$this->relative_to_result = $relative_to = $result;
		}

		$bind_relative_to = false;

		if (!$this->wait_minutes_true AND $this->relative_to_true): // if no wait minutes but a relative to was defined, we just use this as the param (useful for complex R expressions)
			if ($relative_to === true):
				$conditions['relative_to'] = "1=1";
			elseif ($relative_to === false):
				$conditions['relative_to'] = "0=1";
			elseif (!is_array($relative_to) && strtotime($relative_to)):
				$conditions['relative_to'] = ":relative_to <= NOW()";
				$bind_relative_to = true;
			else:
				alert("Pause {$this->position}: Relative to yields neither true nor false, nor a date, nor a time. " . print_r($relative_to, true), 'alert-warning');
				return false;
			endif;
		elseif ($this->wait_minutes_true):   // if a wait minutes was defined by user, we need to add its condition
			if (!is_array($relative_to) && strtotime($relative_to)):
				$conditions['minute'] = "DATE_ADD(:relative_to, INTERVAL :wait_minutes MINUTE) <= NOW()";
				$bind_relative_to = true;
			else:
				alert("Pause {$this->position}: Relative to yields neither a date, nor a time. " . print_r($relative_to, true), 'alert-warning');
				return false;
			endif;
		endif;

		if ($this->wait_until_date AND $this->wait_until_date != '0000-00-00'):
			$conditions['date'] = "CURDATE() >= :wait_date";
		endif;
		if ($this->wait_until_time AND $this->wait_until_time != '00:00:00'):
			$conditions['time'] = "CURTIME() >= :wait_time";
			$new_day = $this->dbh->prepare("SELECT 1 AS finished FROM `survey_unit_sessions` 
				WHERE `survey_unit_sessions`.unit_id = :id 
				AND `survey_unit_sessions`.run_session_id = :run_session_id
				AND DATE(`survey_unit_sessions`.ended) = CURDATE()
				LIMIT 1");
			$new_day->bindValue(":id", $this->id);
			$new_day->bindValue(":run_session_id", $this->run_session_id);
			$new_day->execute();
			if($new_day->rowCount() > 0) {
				return false;
			}
		endif;

		if (!empty($conditions)):
			$condition = implode($conditions, " AND ");

			$q = "SELECT ( {$condition} ) AS test LIMIT 1";

			$evaluate = $this->dbh->prepare($q); // should use readonly
			if (isset($conditions['minute'])):
				$evaluate->bindValue(':wait_minutes', $this->wait_minutes);
			endif;
			if ($bind_relative_to):
				$evaluate->bindValue(':relative_to', $relative_to);
			endif;

			if (isset($conditions['date'])):
				$evaluate->bindValue(':wait_date', $this->wait_until_date);
			endif;
			if (isset($conditions['time'])):
				$evaluate->bindValue(':wait_time', $this->wait_until_time);
			endif;

			$evaluate->execute();
			if ($evaluate->rowCount() === 1):
				$temp = $evaluate->fetch();
				$result = $temp['test'];
			endif;
		else:
			$result = true;
		endif;

		return $result;
	}

	protected function isOver() {
		// Frist get the latest pause session unit for this session.
		// This query assumes the unit session has been created but not ended
		// 
		$q = 'SELECT id, created, ended, expired 
			  FROM survey_unit_sessions 
			  WHERE unit_id = :id AND run_session_id = :run_session_id AND ended is NULL
			  ORDER BY created DESC LIMIT 1
			  ';
		$unit = $this->dbh->prepare($q);
		$unit->bindValue(':id', $this->id);
		$unit->bindValue('run_session_id', $this->run_session_id);
		$row = $unit->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			return false;
		}

		$now = time();
		$creation_datetime = strtotime($row['created']);
		$expiration_datetime = null;

		$expiration_second = date('s', $now);
		$expiration_minute = date('i', $now);
		$expiration_hour = date('H', $now);
		$expiration_day = date('j', $now);
		$expiration_month = date('n', $now);
		$expiration_year = date('Y', $now);

		$has_wait_minutes = !empty($this->wait_minutes);
		$has_relative_to = !empty($this->relative_to);

		if ($has_relative_to) {
			// send to opencpu to compute value
			$opencpu_vars = $this->getUserDataInRun($this->relative_to);
			$result = opencpu_evaluate($this->relative_to, $opencpu_vars, 'json');
			if ($result === null) {
				alert("Pause {$this->position}: Relative to yields neither true nor false, nor a date, nor a time. " . print_r($this->relative_to, true), 'alert-warning');
				return false;
			}
			$relative_to_result = $relative_to = $result;

			if ($relative_to_result === false) {
				// Return false (i.e pause should not expire) if the 'relative_to' field is boolean and condition is not satisfied
				return false;
			}

			if (is_string($relative_to_result) && ($stt = strtotime($relative_to_result))) {
				// Set the datetime from which expiration should be calculated
				$creation_datetime = $stt;
			}
		}

		if ($has_wait_minutes && $creation_datetime) {
			$expiration_datetime = $creation_datetime + ($this->wait_minutes * 60);
		}

		if ($this->wait_until_date && $this->wait_until_date != '0000-00-00') {
			list($expiration_year, $expiration_month, $expiration_day) = explode('-', $this->wait_until_date, 3);
			$expiration_datetime = mktime(0, 0, 5, $expiration_day, $expiration_month, $expiration_year);
		}

		if ($this->wait_until_time && $this->wait_until_time != '00:00:00') {
			list($expiration_hour, $expiration_minute, $expiration_second) = explode(':', $this->wait_until_time, 3);
			$expiration_datetime = mktime($expiration_hour, $expiration_minute, $expiration_second, $expiration_day, $expiration_month, $expiration_year);
		}

		if (!$expiration_datetime) {
			// There was some issue calculating the expiration time for this session
			alert("Pause {$this->position}: Unable to get expiration datetime for session with id: {$this->run_session_id}", 'alert-warning');
			return false;
		}

		// Pause expires IF $expiration_datetime is greater than now
		return $expiration_datetime <= $now;
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
			if ($this->relative_to_true) {
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
				if ($this->relative_to_true) {
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
