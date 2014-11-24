<?php

class UnitSession
{
	public $session = null;
	public $id, $unit_id, $ended;
	private $dbh;
	
	public function __construct($fdb, $run_session_id, $unit_id)
	{
		$this->dbh = $fdb;
		$this->unit_id = $unit_id;
		$this->run_session_id = $run_session_id;
		
		$this->load();
	}
	public function create()
	{
		$session_q = "INSERT INTO  `survey_unit_sessions`
		SET 
		unit_id = :unit_id,
		run_session_id = :run_session_id,
		created = NOW()
		";
		$add_session = $this->dbh->prepare($session_q);
	
		$add_session->bindParam(":unit_id", $this->unit_id);
		$add_session->bindParam(":run_session_id", $this->run_session_id);
	
		$add_session->execute();
		
		if($add_session->rowCount()===1)
		{
			$this->id = $this->dbh->lastInsertId();
		}
	}
	public function load()
	{
		if($this->run_session_id != null AND $this->unit_id != null):
			$session_q = "SELECT id  FROM  `survey_unit_sessions`
			WHERE 
			unit_id = :unit_id AND
			run_session_id = :run_session_id AND
			ended IS NULL
			ORDER BY created DESC
			LIMIT 1;
			";
		
			$valid_session = $this->dbh->prepare($session_q);
		
			$valid_session->bindParam(":run_session_id",$this->run_session_id);
			$valid_session->bindParam(":unit_id", $unit_id);
		
			$valid_session->execute();
			$valid = $valid_session->rowCount();
			$sess_array = $valid_session->fetch(PDO::FETCH_ASSOC);
			if($valid):
				$this->id = $sess_array['id'];
#				$this->ended = $sess_array['ended'];
			endif;
		endif;
	}
	public function __sleep()
	{
		return array('id', 'session', 'unit_id');
	}
}