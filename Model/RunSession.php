<?php

class RunSession
{
	public $session = null;
	public $id, $run_id, $ended, $position, $current_unit_type;
	private $dbh;
	private $cron = false;

	public function __construct($fdb, $run_id, $user_id, $session)
	{
		$this->dbh = $fdb;
		$this->session = $session;
		$this->run_id = $run_id;
		if($user_id == 'cron'):
			$this->cron = true;
		else:
			$this->user_id = $user_id;
		endif;
		
		
		if($this->session != null AND $this->run_id != null): // called with null in constructor if they have no session yet
			$this->load();
		endif;
	}
	private function load()
	{
		$session_q = "SELECT 
			`survey_run_sessions`.id, 
			`survey_run_sessions`.session, 
			`survey_run_sessions`.user_id, 
			`survey_run_sessions`.run_id, 
			`survey_run_sessions`.created, 
			`survey_run_sessions`.ended, 
			`survey_run_sessions`.position, 
			`survey_runs`.name AS run_name,
			`survey_runs`.user_id AS run_owner_id
			  FROM  `survey_run_sessions`
		
		LEFT JOIN `survey_runs`
		ON `survey_runs`.id = `survey_run_sessions`.run_id 
		
		WHERE 
		run_id = :run_id AND
		session = :session
		LIMIT 1;";
	
		$valid_session = $this->dbh->prepare($session_q) or die(print_r($dbh->errorInfo(), true));
	
		$valid_session->bindParam(":session",$this->session);
		$valid_session->bindParam(":run_id", $this->run_id);
	
		$valid_session->execute() or die(print_r($valid_session->errorInfo(), true));
		$valid = $valid_session->rowCount();
		$sess_array = $valid_session->fetch(PDO::FETCH_ASSOC);
		if($valid):
			$this->id = $sess_array['id'];
			$this->session = $sess_array['session'];
			$this->run_id = $sess_array['run_id'];
			$this->user_id = $sess_array['user_id'];
			$this->created = $sess_array['created'];
			$this->ended = $sess_array['ended'];
			$this->position = $sess_array['position'];
			$this->run_name = $sess_array['run_name'];
			$this->run_owner_id = $sess_array['run_owner_id'];
			
			if(!$this->cron):
				$last_access_q = "UPDATE `survey_run_sessions`
					SET last_access = NOW()
				WHERE 
				id = :id
				LIMIT 1;";
				
		
				$last_access = $this->dbh->prepare($last_access_q) or die(print_r($dbh->errorInfo(), true));
	
				$last_access->bindParam(":id",$this->id);
	
				$success = $last_access->execute() or die(print_r($last_access->errorInfo(), true));
			endif;
			return true;
		endif;
		return false;
	}
	public function create($session = NULL)
	{
		if($session !== NULL)
		{
			if(strlen($session)!=64)
			{
				alert("<strong>Error.</strong> Session tokens need to be exactly 64 characters long.",'alert-danger');
				return false;
			}
		}
		else
		{
			$session = bin2hex(openssl_random_pseudo_bytes(32));
		}
		
		$session_q = "INSERT IGNORE INTO  `survey_run_sessions`
		SET run_id = :run_id,
		session = :session,
		user_id = :user_id,
		created = NOW()
		";
		$add_session = $this->dbh->prepare($session_q) or die(print_r($this->dbh->errorInfo(), true));
	
		$add_session->bindParam(":session",$session);
		$add_session->bindParam(":run_id", $this->run_id);
		$add_session->bindParam(":user_id", $this->user_id);
	
		$add_session->execute() or die(print_r($add_session->errorInfo(), true));
		

		$this->session = $session;
		return $this->load();
	}

	
	public function getUnit()
	{
#		pr($this->id);
		$i = 0;
		$done = array();
		$unit_factory = new RunUnitFactory();
		
		$output = false;
		while(! $output): // only when there is something to display, stop.
			$i++;
			if($i > 80) {
				global $user;
				if($user->isCron() OR $user->isAdmin())
					 alert(print_r($unit,true),'alert-danger');
				if($i > 90):
					alert('Nesting too deep. Could there be an infinite loop or maybe no landing page?','alert-danger');
					return false;
				endif;
			}
			$unit_info = $this->getCurrentUnit(); // get first unit in line
			if($unit_info):								 // if there is one, spin that shit
				if($this->cron):
					$unit_info['cron'] = true;
				endif;
				
				$unit = $unit_factory->make($this->dbh, $this->session, $unit_info, $this);
				$this->current_unit_type = $unit->type;
				$output = $unit->exec();
				if(!$output AND is_object($unit)):
					if(isset($done[ $unit->type ]))
						$done[ $unit->type ]++;
					else
						$done[$unit->type ] = 1;
				endif;
				
				
			else:
				if(!$this->runToNextUnit()) 		// if there is nothing in line yet, add the next one in run order
					return array('title'=>'Nothing here.', 'body' => "<div class='broken_tape'><h1><span>Oops. This study's creator forgot to give it a proper ending and now the tape's run out.</span></h1></div>"); // if that fails because the run is wrongly configured, return
			endif;
		endwhile;
		
		if($this->cron)
			return $done;

		return $output;
	}

	public function getUnitIdAtPosition($position)
	{
		$data = $this->dbh->prepare("SELECT unit_id FROM `survey_run_units` WHERE 
			run_id = :run_id AND
			position = :position 
		LIMIT 1");
		$data->bindParam(":run_id",$this->run_id);
		$data->bindParam(":position",$position);
		$data->execute() or die(print_r($data->errorInfo(), true));
		$vars = $data->fetch(PDO::FETCH_ASSOC);
		if($vars)
			return $vars['unit_id'];
		return false;
	}
	public function forceTo($position)
	{
		$unit = $this->getCurrentUnit(); // get first unit in line
		if($unit):
			$unit_factory = new RunUnitFactory();
			$unit = $unit_factory->make($this->dbh,null,$unit, $this);
			$unit->end(); 				// cancel it
		endif;
		
		if($this->runTo($position)):
			return true;
		endif;
		return false;
	}
	public function runTo($position,$unit_id = null)
	{
		if($unit_id === null) $unit_id = $this->getUnitIdAtPosition($position);
			
		if($unit_id):
			
			$unit_session = new UnitSession($this->dbh, $this->id, $unit_id);
			if(!$unit_session->id) $unit_session->create();
			$_SESSION['session'] = $this->session;
		
			if($unit_session->id):
				$run_to_q = "UPDATE `survey_run_sessions`
					SET position = :position 
				WHERE 
				id = :id
				LIMIT 1;";
		
				$run_to_update = $this->dbh->prepare($run_to_q) or die(print_r($dbh->errorInfo(), true));
	
				$run_to_update->bindParam(":id",$this->id);
				$run_to_update->bindParam(":position",$position);
	
				$success = $run_to_update->execute() or die(print_r($run_to_update->errorInfo(), true));
				if($success):
					$this->position = (int)$position;
					return true;
				else:
					alert(__('<strong>Error.</strong> Could not edit run session position for unit %s at pos. %s.', $unit_id, $position), 'alert-danger');
				endif;
			else:
				alert(__('<strong>Error.</strong> Could not create unit session for unit %s at pos. %s.', $unit_id, $position), 'alert-danger');
			endif;
		elseif($unit_id !== null AND $position):
			alert(__('<strong>Error.</strong> The run position %s does not exist.', $position), 'alert-danger');
		else:
			alert('<strong>Error.</strong> You tried to jump to a non-existing run position or forgot to specify one entirely.', 'alert-danger');
		endif;
		return false;
	}


	public function getCurrentUnit()
	{
		$g_unit = $this->dbh->prepare(
		"SELECT 
			`survey_unit_sessions`.unit_id,
			`survey_unit_sessions`.id AS session_id,
			`survey_unit_sessions`.created,
			`survey_units`.type

		FROM `survey_unit_sessions`

 		LEFT JOIN `survey_units`
	 		ON `survey_unit_sessions`.unit_id = `survey_units`.id
	
		WHERE 
			`survey_unit_sessions`.run_session_id = :run_session_id AND
			`survey_unit_sessions`.unit_id = :unit_id AND
			`survey_unit_sessions`.ended IS NULL -- so we know when to runToNextUnit
		
		ORDER BY `survey_unit_sessions`.id DESC 
		LIMIT 1
		;"); // in the order they were added
		$g_unit->bindParam(':run_session_id',$this->id);
		$g_unit->bindValue(':unit_id',$this->getUnitIdAtPosition($this->position));
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$unit = $g_unit->fetch(PDO::FETCH_ASSOC);
		if($unit):
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
	public function runToNextUnit()
	{
		$g_unit = $this->dbh->prepare(
		"SELECT 
			unit_id,
			position
			
			 FROM `survey_run_units` 
			 
		WHERE 
			run_id = :run_id AND
			position > :position
			
		ORDER BY position ASC
		LIMIT 1
		;");
		$g_unit->bindParam(':run_id',$this->run_id);
		if($this->position !== NULL)
			$g_unit->bindParam(':position',$this->position);
		else
			$g_unit->bindValue(':position',-1000000);
		
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$next = $g_unit->fetch(PDO::FETCH_ASSOC);
		
		if(!$next)
		{
			alert('Run '.$this->run_name.': Forgot a landing page','alert-danger');
			return false;
		}
		return $this->runTo($next['position'],$next['unit_id']);
	}
	public function endLastExternal()
	{
		$end_q = "UPDATE `survey_unit_sessions`
			left join `survey_units`
		on `survey_unit_sessions`.unit_id = `survey_units`.id
			SET `survey_unit_sessions`.`ended` = NOW()
		WHERE 
		`survey_unit_sessions`.run_session_id = :id AND
		`survey_units`.type = 'External' AND 
		`survey_unit_sessions`.ended IS NULL;";
	
		$end_external = $this->dbh->prepare($end_q) or die(print_r($dbh->errorInfo(), true));
	
		$end_external->bindParam(":id",$this->id);
	
		$success = $end_external->execute() or die(print_r($end_external->errorInfo(), true));
		return $success;
	}
	

	public function end() // todo: not being used atm
	{
		$finish_run = $this->dbh->prepare("UPDATE `survey_run_sessions` 
			SET `ended` = NOW()
			WHERE 
			`id` = :id AND
			`ended` IS NULL
		LIMIT 1;");
		$finish_run->bindParam(":id", $this->id);
		$finish_run->execute() or die(print_r($finish_run->errorInfo(), true));

		if($finish_run->rowCount() === 1):
			$this->ended = true;
			return true;
		else:
			return false;
		endif;
	}
	
	public function __sleep()
	{
		return array('id', 'session', 'run_id');
	}
}