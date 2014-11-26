<?php

class UnitSession {

	public $session = null;
	public $id, $unit_id, $ended, $run_session_id;

	/**
	 * @var DB
	 */
	private $dbh;

	public function __construct($fdb, $run_session_id, $unit_id) {
		$this->dbh = $fdb;
		$this->unit_id = $unit_id;
		$this->run_session_id = $run_session_id;

		$this->load();
	}

	public function create() {
		$insert = "INSERT INTO `survey_unit_sessions` SET unit_id = :unit_id, run_session_id = :run_session_id, created = NOW()";
		$inserted = $this->dbh->exec($insert, array('unit_id' => $this->unit_id, 'run_session_id' => $this->run_session_id));
		if ($inserted) {
			$this->id = $this->dbh->lastInsertId();
		}
	}

	public function load() {
		if ($this->run_session_id != null AND $this->unit_id != null):
			$id = $this->dbh->select('id')
					->from('survey_unit_sessions')
					->where(array('run_session_id' => $this->run_session_id, 'unit_id' => $this->unit_id))
					->where('ended IS NULL')
					->order('created', 'desc')->limit(1)
					->fetchColumn();
			if ($id):
				$this->id = $id;
#				$this->ended = $sess_array['ended'];
			endif;
		endif;
	}

	public function __sleep() {
		return array('id', 'session', 'unit_id');
	}

}
