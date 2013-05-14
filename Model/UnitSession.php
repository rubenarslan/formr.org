<?php

class UnitSession
{
	public $session = null;
	public $id, $unit_id, $ended;
	private $dbh;
	
	public function __construct($fdb, $session, $unit_id)
	{
		$this->dbh = $fdb;
		$this->unit_id = $unit_id;
		
		if($session != null AND $unit_id != null): // called with null in constructor if they have no session yet
			$session_q = "SELECT id, session, unit_id, ended  FROM  `survey_unit_sessions`
			WHERE 
			unit_id = :unit_id AND
			session = :session
			LIMIT 1;";
		
			$valid_session = $this->dbh->prepare($session_q) or die(print_r($dbh->errorInfo(), true));
		
			$valid_session->bindParam(":session",$session);
			$valid_session->bindParam(":unit_id", $unit_id);
		
			$valid_session->execute() or die(print_r($valid_session->errorInfo(), true));
			$valid = $valid_session->rowCount();
			$sess_array = $valid_session->fetch(PDO::FETCH_ASSOC);
			if($valid):
				$this->id = $sess_array['id'];
				$this->session = $sess_array['session'];
				$this->unit_id = $sess_array['unit_id'];
				$this->ended = $sess_array['ended'];
			endif;
		endif;
		
	}
	public function create($session = NULL)
	{
		if($session !== NULL)
		{
			if(strlen($session)!=64)
				die('invalid token');
		}
		else
			$session = bin2hex(openssl_random_pseudo_bytes(32));
		
		$session_q = "INSERT INTO  `survey_unit_sessions`
		SET unit_id = :unit_id,
		session = :session,
		created = NOW()
		";
		$add_session = $this->dbh->prepare($session_q) or die(print_r($this->dbh->errorInfo(), true));
	
		$add_session->bindParam(":session",$session);
		$add_session->bindParam(":unit_id", $this->unit_id);
	
		$add_session->execute() or die(print_r($add_session->errorInfo(), true));
		
		if($add_session->rowCount()===1)
		{
			$this->id = $this->dbh->lastInsertId();
			$this->session = $session;
		}
	}
	public function __sleep()
	{
		return array('id', 'session', 'unit_id');
	}
}