<?php

class Session
{
	public function __construct($session,$study)
	{
		$this->dbh = new DB();
		$this->study = $study;
		if($session === null): // called with null in instructor if they have access but no session yet
			$this->new();
		else:
			$session_q = "SELECT *  FROM  `survey_sessions`
			WHERE 
			study_id = :study_id AND
			session = :session
			LIMIT 1;";
		
			$valid_session = $this->dbh->prepare($session_q) or die(print_r($this->dbh->errorInfo(), true));
		
			$valid_session->bindParam(":session",$session);
			$valid_session->bindParam(":study_id", $this->study->id);
		
			$valid_session->execute() or die(print_r($valid_session->errorInfo(), true));
			$valid = $valid_session->rowCount();
		
			if($valid)
				$this->session = $session;
			else $this->session = null;
		endif;
	}
	public function new()
	{
		$this->session = bin2hex(openssl_random_pseudo_bytes(32));
		$session_q = "INSERT INTO  `survey_sessions`
		(study_id,session)
	 	VALUES(:study_id, :session);";
		$session = $this->dbh->prepare($session_q) or die(print_r($this->dbh->errorInfo(), true));
	
		$session->bindParam(":session",$this->session);
		$session->bindParam(":study_id", $this->study->id);
	
		$session->execute() or die(print_r($session->errorInfo(), true));
		$valid = $session->rowCount();
		
	}
}