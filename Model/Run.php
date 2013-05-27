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
	public $cron_active = false;
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
			$run_data = $this->dbh->prepare("SELECT id,user_id,name,api_secret,public,cron_active FROM `survey_runs` WHERE name = :run_name LIMIT 1");
			$run_data->bindParam(":run_name",$this->name);
			$run_data->execute() or die(print_r($run_data->errorInfo(), true));
			$vars = $run_data->fetch(PDO::FETCH_ASSOC);
			
			if($vars):
				$this->id = $vars['id'];
				$this->user_id = $vars['user_id'];
				$this->api_secret = $vars['api_secret'];
				$this->public = $vars['public'];
				$this->cron_active = $vars['cron_active'];
			
				$this->valid = true;
			endif;
		endif;
	}
	public function getCronDues()
	{
		$g_unit = $this->dbh->prepare(
		"SELECT 
			`survey_run_sessions`.session, (`survey_unit_sessions`.expires < NOW()) AS expired
		
			 FROM `survey_run_sessions`

 		LEFT JOIN `survey_unit_sessions`
	 		ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id

		LEFT JOIN `survey_units` 
	 		ON `survey_unit_sessions`.unit_id = `survey_units`.id
	
		WHERE 
			`survey_run_sessions`.run_id = :run_id AND
			`survey_unit_sessions`.ended IS NULL AND
			(
			`survey_units`.type IN ('Branch','Pause','Email') OR
			`survey_unit_sessions`.expires < NOW()
			)
		
		ORDER BY `survey_unit_sessions`.id DESC 
		;"); // in the order they were added
		$g_unit->bindParam(':run_id',$this->id);
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$dues = array();
		while($run_session = $g_unit->fetch(PDO::FETCH_ASSOC))
			$dues[] = $run_session;
		return $dues;
	}

	/* ADMIN functions */
	
	public function getApiSecret($user)
	{
		if($user->isAdmin())
			return $this->api_secret;
		return false;
	}
	
	public function delete()
	{
		$this->dbh->beginTransaction() or die(print_r($this->dbh->errorInfo(), true));
		$delete_run = $this->dbh->prepare("DELETE FROM `survey_runs` WHERE id = :run_id") or die(print_r($this->dbh->errorInfo(), true)); // Cascades
		$delete_run->bindParam(':run_id',$this->id);
		$delete_run->execute() or die(print_r($delete_run->errorInfo(), true));
		
		$this->dbh->commit();
	}
	public function toggleCron($on)
	{
		$toggle = $this->dbh->prepare("UPDATE `survey_runs` SET cron_active = :cron_active WHERE id = :id;");
		$toggle->bindParam(':id',$this->id);
		$toggle->bindParam(':cron_active', $on );
		$success = $toggle->execute() or die(print_r($toggle->errorInfo(), true));
		return $success;
	}
	public function togglePublic($on)
	{
		$toggle = $this->dbh->prepare("UPDATE `survey_runs` SET public = :public WHERE id = :id;");
		$toggle->bindParam(':id',$this->id);
		$toggle->bindParam(':public', $on );
		$success = $toggle->execute() or die(print_r($toggle->errorInfo(), true));
		return $success;
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
		$create = $this->dbh->prepare("INSERT INTO `survey_runs` (user_id, name, api_secret) VALUES (:user_id, :name, :api_secret);");
		$create->bindParam(':user_id',$options['user_id']);
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