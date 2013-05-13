<?php
require_once INCLUDE_ROOT . "Model/DB.php";

/*
## types of run units
	* branches 
		(these evaluate a condition and go to one position in the run, can be used for allowing access)
	* feedback 
		(atm just markdown pages with a title and body, but will have to use these for making graphs etc at some point)
		(END POINTS, does not automatically lead to next run unit in list, but doesn't have to be at the end because of branches)
	* breaks
		(go on if it's the next day, a certain date etc., so many days after beginning etc.)
	* emails
		(there should be another unit afterwards, otherwise shows default end page after email was sent)
	* surveys 
		(main component, upon completion give up steering back to run)
	* external 
		(formerly forks, can redirect internally to other runs too)
	* social network (later)
	* lab date selector (later)

*/
class Run
{
	public $id = null;
	public $name = null;
	public $valid = false;
	public $public = false;
	private $api_secret = null;
	public $settings = array();
	public $errors = array();
	public $messages = array();
	private $dbh;
	
	public function __construct($fdb, $name, $options = null) 
	{
		$this->dbh = $fdb;
		
		if($name !== null OR ($name = $this->create($options))):
			$this->name = $name;
			$run_data = $this->dbh->prepare("SELECT id,owner_id,name,api_secret FROM `survey_runs` WHERE name = :run_name LIMIT 1");
			$run_data->bindParam(":run_name",$this->name);
			$run_data->execute() or die(print_r($run_data->errorInfo(), true));
			$vars = $run_data->fetch(PDO::FETCH_ASSOC);
			
			if($vars):
				$this->id = $vars['id'];
				$this->owner_id = $vars['owner_id'];
				$this->api_secret = $vars['api_secret'];
			
				$this->valid = true;
			endif;
		endif;
	}

	public function getUnit($session)
	{
		$i = 0;
		$search_units = true;
		while($search_units):
			$i++;
			if($i > 90) {
				global $user;
				if($user->isAdmin()) pr($unit);
				die('Nesting too deep. Could there be an infinite loop or maybe no landing page?');
			}
			$unit = $this->getCurrentUnit($session); // get first unit in line
#			pr($unit);
			if($unit):								 // if there is one, spin that shit
				$type = $unit['type'];
				if(!in_array($type, array('Survey','Pause','Email','External','Page','Branch','End'))) die('imp type');
				require_once INCLUDE_ROOT . "Model/$type.php";
				$unit = new $type($this->dbh, $session, $unit);
				if($output = $unit->exec() ):
					$search_units = false; 			// only when there is something to display, stop.
				endif;
			else:
				$this->getNextUnit($session); 		// if there is nothing in line yet, add the next one in run order
			endif;
		endwhile;
		
		return $output;
	}
	public function getCurrentUnit($session)
	{
		$g_unit = $this->dbh->prepare(
		"SELECT 
			`survey_unit_sessions`.id AS session_id,
			`survey_unit_sessions`.session,
			`survey_unit_sessions`.created,
			`survey_runs`.name AS run_name,
			`survey_runs`.id,
			`survey_runs`.owner_id,
			`survey_run_units`.position,
			`survey_run_units`.unit_id,
			`survey_run_units`.run_id,
			`survey_units`.type
		
			 FROM `survey_unit_sessions`

 		LEFT JOIN `survey_units`
	 		ON `survey_unit_sessions`.unit_id = `survey_units`.id
 	
		LEFT JOIN `survey_run_units` 
	 		ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
		 
		LEFT JOIN `survey_runs`
		ON `survey_run_units`.run_id = `survey_runs`.id
	
		WHERE 
			`survey_run_units`.run_id = :run_id AND
			`survey_unit_sessions`.session = :session AND
			
			`survey_unit_sessions`.ended IS NULL
		
		ORDER BY `survey_unit_sessions`.id DESC 
		LIMIT 1
		;"); // in the order they were added
		$g_unit->bindParam(':run_id',$this->id);
		$g_unit->bindParam(':session',$session);
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$unit = $g_unit->fetch(PDO::FETCH_ASSOC);
		return $unit;
	}
	public function getPreviousUnit($session)
	{
		$g_unit = $this->dbh->prepare(
		"SELECT `survey_run_units`.position
			 FROM `survey_unit_sessions`
			 
		LEFT JOIN `survey_run_units` 
		ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
		
		WHERE 
			`survey_run_units`.run_id = :run_id AND
			`survey_unit_sessions`.session = :session AND
			`survey_unit_sessions`.ended IS NOT NULL
			
		ORDER BY `survey_unit_sessions`.id DESC
		LIMIT 1
		;");
		$g_unit->bindParam(':run_id',$this->id);
		$g_unit->bindParam(':session',$session);
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$unit = $g_unit->fetch(PDO::FETCH_ASSOC);
		return $unit;
	}
	public function getNextUnit($session)
	{
		$last_unit = $this->getPreviousUnit($session);
		$g_unit = $this->dbh->prepare(
		"SELECT 
			`survey_run_units`.unit_id
			
			 FROM `survey_run_units` 
			 
		WHERE 
			`survey_run_units`.run_id = :run_id AND
			`survey_run_units`.position > :position
		ORDER BY `survey_run_units`.position ASC
		LIMIT 1
		;");
		$g_unit->bindParam(':run_id',$this->id);
		if($last_unit)
			$g_unit->bindParam(':position',$last_unit['position']);
		else
			$g_unit->bindValue(':position',-1000);
		
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$unit = $g_unit->fetch(PDO::FETCH_ASSOC);
		if(!$unit)
		{
			die('Forgot a landing page');
		}
		$unit_session = new UnitSession($this->dbh, $session, $unit['unit_id']);
		if(!$unit_session->session)
			$unit_session->create($session);
		$_SESSION['session'] = $session;
	}

	
	/* ADMIN functions */
	
	public function delete()
	{
		$this->dbh->beginTransaction() or die(print_r($this->dbh->errorInfo(), true));
		$delete_run = $this->dbh->prepare("DELETE FROM `survey_runs` WHERE id = :run_id") or die(print_r($this->dbh->errorInfo(), true)); // Cascades
		$delete_run->bindParam(':run_id',$this->id);
		$delete_run->execute() or die(print_r($delete_run->errorInfo(), true));
		
		$this->dbh->commit();
	}
	public function create($options)
	{
	    $name = trim($options['run_name']);
	    if($name == ""):
			$this->errors[] = _("You have to specify a run name.");
			return false;
		elseif(!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,20}/",$name)):
			$this->errors[] = _("The run's name has to be between 3 and 20 characters and can't start with a number or contain anything other a-Z_0-9.");
			return false;
		elseif($this->existsByName($name)):
			$this->errors[] = __("The run's name '%s' is already taken.",h($name));
			return false;
		endif;

		$this->dbh->beginTransaction();
		$create = $this->dbh->prepare("INSERT INTO `survey_runs` (owner_id, name, api_secret) VALUES (:owner_id, :name, :api_secret);");
		$create->bindParam(':owner_id',$options['owner_id']);
		$create->bindParam(':name',$name);
		$new_secret = bin2hex(openssl_random_pseudo_bytes(32));
		$create->bindParam(':api_secret',$new_secret);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();

		return $name;
	}
	protected function existsByName($name)
	{
		$exists = $this->dbh->prepare("SELECT name FROM `survey_runs` WHERE name = :name LIMIT 1");
		$exists->bindParam(':name',$name);
		$exists->execute() or die(print_r($create->errorInfo(), true));
		if($exists->rowCount())
			return true;
		
		return false;
	}
	public function reorder($positions)
	{
		$reorder = $this->dbh->prepare("UPDATE `survey_run_units` SET
			position = :position WHERE 
			unit_id = :unit_id AND
			run_id = :run_id");
		$reorder->bindParam(':run_id',$this->id);
		foreach($positions AS $unit_id => $pos):
			$reorder->bindParam(':unit_id',$unit_id);
			$reorder->bindParam(':position',$pos);
			$reorder->execute() or die(print_r($reorder->errorInfo(), true));
		endforeach;
		return true;
	}
	public function getAllUnitIds()
	{
		$g_unit = $this->dbh->prepare(
		"SELECT 
			`survey_run_units`.unit_id,
			`survey_run_units`.position
			
			 FROM `survey_run_units` 
		WHERE 
			`survey_run_units`.run_id = :run_id
			
		ORDER BY `survey_run_units`.position ASC
		;");
		$g_unit->bindParam(':run_id',$this->id);
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$units = array();
		while($unit = $g_unit->fetch(PDO::FETCH_ASSOC))
			$units[] = $unit;
		
		return $units;
	}
	public function getUnitAdmin($id)
	{
		$g_unit = $this->dbh->prepare(
		"SELECT 
			`survey_run_units`.*,
			`survey_units`.*
			
			 FROM `survey_run_units` 
			 
		LEFT JOIN `survey_units`
		ON `survey_units`.id = `survey_run_units`.unit_id
		
		WHERE 
			`survey_run_units`.run_id = :run_id AND
			`survey_run_units`.unit_id = :unit_id
		LIMIT 1
		;");
		$g_unit->bindParam(':run_id',$this->id);
		$g_unit->bindParam(':unit_id',$id);
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));

		$unit = $g_unit->fetch(PDO::FETCH_ASSOC);
		return $unit;
	}
}