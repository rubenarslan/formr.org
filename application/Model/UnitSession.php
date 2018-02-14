<?php

class UnitSession {

	public $session = null;
	public $id;
	public $unit_id;
	public $created;
	public $ended;
	public $expired;
	public $run_session_id;

	/**
	 * @var DB
	 */
	private $dbh;

	public function __construct($fdb, $run_session_id, $unit_id, $unit_session_id = null) {
		$this->dbh = $fdb;
		$this->unit_id = $unit_id;
		$this->run_session_id = $run_session_id;
		$this->id = $unit_session_id;

		$this->load();
	}

	public function create() {
		$now = mysql_now();
		$this->id = $this->dbh->insert('survey_unit_sessions', array(
			'unit_id' => $this->unit_id,
			'run_session_id' => $this->run_session_id,
			'created' => $now
		));
		$this->created = $now;
		return $this->id;
	}

	public function load() {
		if($this->id !== null) {
			$vars = $this->dbh->select('id, created, unit_id, run_session_id, ended, meta')
					->from('survey_unit_sessions')
					->where(array('id' => $this->id))
					->fetch();
		} else {
			$vars = $this->dbh->select('id, created, unit_id, run_session_id, ended, meta')
					->from('survey_unit_sessions')
					->where(array('run_session_id' => $this->run_session_id, 'unit_id' => $this->unit_id))
					->where('ended IS NULL AND expired IS NULL')
					->order('created', 'desc')->limit(1)
					->fetch();
		}

		if (!$vars) {
			return;
		}

		foreach ($vars as $property => $value) {
			if (property_exists($this, $property)) {
				$this->{$property} = $value;
			}
		}
	}

	public function __sleep() {
		return array('id', 'session', 'unit_id', 'created');
	}

}
