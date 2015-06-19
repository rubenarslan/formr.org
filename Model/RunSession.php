<?php

class RunSession {

	public $session = null;
	public $id, $run_id, $ended, $position, $current_unit_type, $user_id, $created, $run_name, $run_owner_id;
	private $cron = false;
	/**
	 * @var DB
	 */
	private $dbh;

	public function __construct($fdb, $run_id, $user_id, $session) {
		$this->dbh = $fdb;
		$this->session = $session;
		$this->run_id = $run_id;
		if ($user_id == 'cron'):
			$this->cron = true;
		else:
			$this->user_id = $user_id;
		endif;

		if ($this->session != null AND $this->run_id != null): // called with null in constructor if they have no session yet
			$this->load();
		endif;
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

			if (!$this->cron) {
				$this->dbh->update('survey_run_sessions', array('last_access' => mysql_now()), array('id' => $this->id));
			}
			return true;
		}

		return false;
	}

	public function create($session = NULL) {
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
				if ($user->isCron() OR $user->isAdmin()) {
					alert(print_r($unit, true), 'alert-danger');
				}
				alert('Nesting too deep. Could there be an infinite loop or maybe no landing page?', 'alert-danger');
				return false;
			}

			$unit_info = $this->getCurrentUnit(); // get first unit in line
			if ($unit_info) {		 // if there is one, spin that shit
				if ($this->cron) {
					$unit_info['cron'] = true;
				}

				$unit = $unit_factory->make($this->dbh, $this->session, $unit_info, $this);
				$this->current_unit_type = $unit->type;
				$output = $unit->exec();

				if (!$output AND is_object($unit)) {
					if (!isset($done[$unit->type])) {
						$done[$unit->type] = 0;
					}
					$done[$unit->type]++;
				}

			} else {
				if (!$this->runToNextUnit()) {   // if there is nothing in line yet, add the next one in run order
					return array(
						'title' => 'Nothing here.',
						'body' => '<div class="broken_tape">
							<div class="tape_label_box">
								<div class="tape_label">
										Oops. This study\'s creator forgot to give it a proper ending and now the tape\'s run out.
									</div>
								</div>
							</div>'); // if that fails because the run is wrongly configured, return
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
		if ($unit):
			$unit_factory = new RunUnitFactory();
			$unit = $unit_factory->make($this->dbh, null, $unit, $this);
			$unit->end();	 // cancel it
		endif;

		if ($this->runTo($position)):
			return true;
		endif;
		return false;
	}

	public function runTo($position, $unit_id = null) {
		if ($unit_id === null) {
			$unit_id = $this->getUnitIdAtPosition($position);
		}

		if ($unit_id):

			$unit_session = new UnitSession($this->dbh, $this->id, $unit_id);
			if (!$unit_session->id) {
				$unit_session->create();
			}
			$_SESSION['session'] = $this->session;

			if ($unit_session->id):
				$updated = $this->dbh->update('survey_run_sessions', array('position' => $position), array('id' => $this->id));
				$success = $updated != 0;
				if ($success):
					$this->position = (int) $position;
					return true;
				else:
					alert(__('<strong>Error.</strong> Could not edit run session position for unit %s at pos. %s.', $unit_id, $position), 'alert-danger');
				endif;
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
			alert('Run ' . $this->run_name . ': Forgot a landing page', 'alert-danger');
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

	public function __sleep() {
		return array('id', 'session', 'run_id');
	}

}
