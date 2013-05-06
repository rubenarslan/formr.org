<?php

class Session
{
	public function __construct($session,$study)
	{
		$this->dbh = new DB();
		$this->study = $study;
		
		if($session === null): // called with null in instructor if they have no session yet
			$this->session = null;
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
			$sess_array = $valid_session->fetch(PDO::FETCH_ASSOC);
			if($valid):
				$this->session = $sess_array['session'];
				$this->id = $sess_array['id'];
			else: 
				$this->session = null;
			endif;
					
		endif;
		
	}
	public function create()
	{
		$this->session = bin2hex(openssl_random_pseudo_bytes(32));
		$session_q = "INSERT INTO  `survey_sessions`
		(study_id,session)
	 	VALUES(:study_id, :session);";
		$add_session = $this->dbh->prepare($session_q) or die(print_r($this->dbh->errorInfo(), true));
	
		$add_session->bindParam(":session",$this->session);
		$add_session->bindParam(":study_id", $this->study->id);
	
		$add_session->execute() or die(print_r($add_session->errorInfo(), true));
		
		if($add_session->rowCount()!==1) 
			$this->session = null;
	}
}