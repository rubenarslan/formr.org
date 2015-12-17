<?php

class RunSession {

	public $id;
	public $run_id;
	public $user_id;
	public $session = null;
	public $created;
	public $ended;
	public $last_access;
	public $position;
	public $current_unit_id;
	public $deactivated;
	public $no_mail;
	public $current_unit_type;
	public $run_name;
	public $run_owner_id;
	public $run;
	public $unit_session;
	private $cron = false;
	private $is_testing = false;
	private $test_run = false;

	/**
	 * @var DB
	 */
	private $dbh;

	public function __construct($fdb, $run_id, $user_id, $session, $run = NULL) {
		$this->dbh = $fdb;
		$this->session = $session;
		$this->run_id = $run_id;
		$this->run = $run;
		if ($user_id == 'cron') {
			$this->cron = true;
		} else {
			$this->user_id = $user_id;
		}
		
		if($run_id == -1) {
			$this->test_run = true;
			$this->id = -1;
			$this->session = $session;
			$this->run_id = $run_id;
			$this->user_id = $user_id;
			$this->run_name = $run->name;
			$this->run_owner_id = $user_id;
			$this->is_testing = true;
			Site::getInstance()->setRunSession($this);
		}
		else if ($this->session && $this->run_id) {// called with null in constructor if they have no session yet
			$this->load();
		}
	}

	private function load() {
		$sess_array = $this->dbh->select(' 
			`survey_run_sessions`.id, 
			`survey_run_sessions`.session, 
			`survey_run_sessions`.user_id, 
			`survey_run_sessions`.run_id, 
			`survey_run_sessions`.created, 
			`survey_run_sessions`.ended, 
			`survey_run_sessions`.position,
			`survey_run_sessions`.last_access,
			`survey_run_sessions`.no_email,
			`survey_run_sessions`.testing,
			`survey_runs`.name AS run_name,
			`survey_runs`.user_id AS run_owner_id')
						->from('survey_run_sessions')
						->leftJoin('survey_runs', 'survey_runs.id = survey_run_sessions.run_id')
						->where(array('run_id' => $this->run_id, 'session' => $this->session))
						->limit(1)->fetch();

		if ($sess_array) {
			$this->id = $sess_array['id'];
			$this->session = $sess_array['session'];
			$this->run_id = $sess_array['run_id'];
			$this->user_id = $sess_array['user_id'];
			$this->created = $sess_array['created'];
			$this->ended = $sess_array['ended'];
			$this->position = $sess_array['position'];
			$this->run_name = $sess_array['run_name'];
			$this->run_owner_id = $sess_array['run_owner_id'];
			$this->last_access = $sess_array['last_access'];
			$this->no_mail = $sess_array['no_email'];
			$this->is_testing = (bool)$sess_array['testing'];

			if (!$this->cron) {
				$this->dbh->update('survey_run_sessions', array('last_access' => mysql_now()), array('id' => $this->id));
			}
			Site::getInstance()->setRunSession($this);
			return true;
		}

		return false;
	}

	public function getLastAccess() {
		return $this->dbh->select('last_access')
						->from('survey_run_sessions')
						->where(array('id' => $this->id));
	}

	public function create($session = NULL, $testing = 0) {
		if($this->run_id === -1) {
			return false;
		}
		if ($session !== NULL) {
			if (strlen($session) != 64) {
				alert("<strong>Error.</strong> Session tokens need to be exactly 64 characters long.", 'alert-danger');
				return false;
			}
		} else {
			$session = crypto_token(48);
		}
		
		$this->dbh->insert_update('survey_run_sessions', array(
			'run_id' => $this->run_id,
			'user_id' => $this->user_id,
			'session' => $session,
			'created' => mysql_now(),
			'testing' => $testing
		), array('user_id'));
		$this->session = $session;
		return $this->load();
	}

	public function getUnit() {
		$i = 0;
		$done = array();
		$unit_factory = new RunUnitFactory();

		$output = false;
		while (!$output): // only when there is something to display, stop.
			$i++;
			if ($i > 80) {
				global $user;
				if ($user->isCron() || $user->isAdmin()) {
					if (isset($unit))
						alert(print_r($unit, true), 'alert-danger');
				}
				alert('Nesting too deep. Could there be an infinite loop or maybe no landing page?', 'alert-danger');
				return false;
			}

			$unit_info = $this->getCurrentUnit(); // get first unit in line
			if ($unit_info) {   // if there is one, spin that shit
				if ($this->cron) {
					$unit_info['cron'] = true;
				}

				$unit = $unit_factory->make($this->dbh, $this->session, $unit_info, $this, $this->run);
				$this->current_unit_type = $unit->type;
				$output = $unit->exec();

				if (!$output && is_object($unit)) {
					if (!isset($done[$unit->type])) {
						$done[$unit->type] = 0;
					}
					$done[$unit->type] ++;
				}
			} else {
				if (!$this->runToNextUnit()) {   // if there is nothing in line yet, add the next one in run order
					return array('body' => ''); // if that fails because the run is wrongly configured, return nothing
				}
			}
		endwhile;

		if ($this->cron) {
			return $done;
		}

		return $output;
	}

	public function getUnitIdAtPosition($position) {
		$unit_id = $this->dbh->findValue('survey_run_units', array('run_id' => $this->run_id, 'position' => $position), 'unit_id');
		if (!$unit_id) {
			return false;
		}
		return $unit_id;
	}

	public function forceTo($position) {
		$unit = $this->getCurrentUnit(); // get first unit in line
		if ($unit) {
			$unit_factory = new RunUnitFactory();
			$unit = $unit_factory->make($this->dbh, null, $unit, $this, $this->run);
			if ($unit->type == "Survey") {
				$unit->expire();
			} else {
				$unit->end();  // cancel it
			}
		}

		return $this->runTo($position);
	}

	public function runTo($position, $unit_id = null) {
		if ($unit_id === null) {
			$unit_id = $this->getUnitIdAtPosition($position);
		}

		if ($unit_id):

			$this->unit_session = new UnitSession($this->dbh, $this->id, $unit_id);
			if (!$this->unit_session->id) {
				$this->unit_session->create();
			}
			$_SESSION['session'] = $this->session;

			if ($this->unit_session->id):
				$updated = $this->dbh->update('survey_run_sessions', array('position' => $position), array('id' => $this->id));
				$this->position = (int) $position;
				return true;
			else:
				alert(__('<strong>Error.</strong> Could not create unit session for unit %s at pos. %s.', $unit_id, $position), 'alert-danger');
			endif;
		elseif ($unit_id !== null AND $position):
			alert(__('<strong>Error.</strong> The run position %s does not exist.', $position), 'alert-danger');
		else:
			alert('<strong>Error.</strong> You tried to jump to a non-existing run position or forgot to specify one entirely.', 'alert-danger');
		endif;
		return false;
	}

	public function getCurrentUnit() {
		$query = $this->dbh->select('
			`survey_unit_sessions`.unit_id,
			`survey_unit_sessions`.id AS session_id,
			`survey_unit_sessions`.created,
			`survey_units`.type')
				->from('survey_unit_sessions')
				->leftJoin('survey_units', 'survey_unit_sessions.unit_id = survey_units.id')
				->where('survey_unit_sessions.run_session_id = :run_session_id')
				->where('survey_unit_sessions.unit_id = :unit_id')
				->where('survey_unit_sessions.ended IS NULL') //so we know when to runToNextUnit
				->bindParams(array('run_session_id' => $this->id, 'unit_id' => $this->getUnitIdAtPosition($this->position)))
				->order('survey_unit_sessions`.id', 'desc')
				->limit(1);

		$unit = $query->fetch();

		if ($unit):
			// unit needs:
			# run_id
			# run_name
			# unit_id
			# session_id
			# run_session_id
			# type
			# session? 
			$unit['run_id'] = $this->run_id;
			$unit['run_name'] = $this->run_name;
			$unit['run_session_id'] = $this->id;
			$this->unit_session = new UnitSession($this->dbh, $this->id, $unit['unit_id'], $unit['session_id']);
			return $unit;
		endif;
		return false;
	}

	public function runToNextUnit() {
		$select = $this->dbh->select('unit_id, position')
				->from('survey_run_units')
				->where('run_id = :run_id')
				->where('position > :position')
				->order('position', 'asc')
				->limit(1);

		$position = -1000000;
		if ($this->position !== null) {
			$position = $this->position;
		}

		$select->bindParams(array('run_id' => $this->run_id, 'position' => $position));
		$next = $select->fetch();
		if (!$next) {
			alert('Run ' . $this->run_name . ': Oops, this study\'s creator forgot to give it a proper ending.', 'alert-danger');
			return false;
		}
		return $this->runTo($next['position'], $next['unit_id']);
	}

	public function endLastExternal() {
		$query = "
			UPDATE `survey_unit_sessions`
			LEFT JOIN `survey_units` ON `survey_unit_sessions`.unit_id = `survey_units`.id
			SET `survey_unit_sessions`.`ended` = NOW()
			WHERE `survey_unit_sessions`.run_session_id = :id AND `survey_units`.type = 'External' AND  `survey_unit_sessions`.ended IS NULL;";

		$updated = $this->dbh->exec($query, array('id' => $this->id));
		$success = $updated !== false;
		return $success;
	}

	public function end() {
		$query = "UPDATE `survey_run_sessions` SET `ended` = NOW() WHERE `id` = :id AND `ended` IS NULL";
		$updated = $this->dbh->exec($query, array('id' => $this->id));

		if ($updated === 1) {
			$this->ended = true;
			return true;
		}

		return false;
	}
	
	public function setTestingStatus($status = 0) {
		$this->dbh->update("survey_run_sessions", array('testing' => $status), array('id' => $this->id));
	}
	
	public function isTesting() {
		return $this->is_testing;
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
			$this->dbh->update('survey_run_sessions', $update, array('id' => $this->id));
		}

		$oldSettings = $this->getSettings();
		unset($oldSettings['code']);
		if ($oldSettings) {
			$settings = array_merge($oldSettings, $settings);
		}

		$this->dbh->insert_update('survey_run_settings', array(
			'run_session_id' => $this->id,
			'settings' => json_encode($settings),
		));
	}

	public function getSettings() {
		$settings = array();
		$row = $this->dbh->findRow('survey_run_settings', array('run_session_id' => $this->id));
		if ($row) {
			$settings = (array)json_decode($row['settings']);
		}
		$settings['code'] = $this->session;
		return $settings;
	}

}
