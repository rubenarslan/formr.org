<?php
require_once INCLUDE_ROOT . "Model/DB.php";
require_once INCLUDE_ROOT . 'phpass/PasswordHash.php';

class UserX
{
	public $id = null;
	public $email = null;
	public $logged_in = false;
	public $admin = false;
	// todo: time zone, etc.
	public $settings = array();
	public $errors = array();
	public $messages = array();


	public function __construct($id,$options = NULL) 
	{		
		if($id !== NULL):
			$this->id = $id;
		endif;
	}
	public function createdStudy($study)
	{
		return $this->id === $study->user_id; 
	}
	public function createdRun($run)
	{
		return $this->id === $run->user_id; 
	}
	
	public function eligibleForStudy($study) {
		$this->status=true;
		if(!isset($study) or !is_object($study)) {
			$this->errors[]=_("Interner Fehler");
			$this->status=false;
			return false;
		}
		if($this->userCreatedStudy($study))
			return true;
	
		if($study->registered_req and $this->anonymous==true) {
			$this->errors[]=_("Sie müssen registriert sein um an dieser Studie teilzunehmen.");
			$this->status=false;
		}
		return $this->status;
	}
	public function register($email, $password) 
	{
		$db = new DB();
		$exists = $db->prepare("SELECT email FROM `survey_users` WHERE email = :email LIMIT 1");
		$exists->bindParam(':email',$email);
		$exists->execute() or die('db');
		if($user = $exists->rowCount() === 0)
		{
			$hash = new PasswordHash(8, FALSE);
			$hash = $hash->HashPassword($password);

			$add = $db->prepare('INSERT INTO `survey_users` SET 
					email = :email,
					password = :password');
			$add->bindParam(':email',$email);
			$add->bindParam(':password',$hash);
			$add->execute() or die('probl');
			
			return $this->login($email,$password);
		} else
		{
			$this->errors[] = 'User exists already.';
		}
		return false;
	}
	public function login($email,$password) {
		$db = new DB();
		$login = $db->prepare("SELECT id, password, admin FROM `survey_users` WHERE email = :email LIMIT 1");
		$login->bindParam(':email',$email);
		$login->execute() or die('db');
		if($user = $login->fetch())
		{
			# Try to use stronger but system-specific hashes, with a possible fallback to
			# the weaker portable hashes.
			$hash = new PasswordHash(8, FALSE);
			
			$check = $hash->CheckPassword($password, $user['password']);
			if($check)
			{
				$this->logged_in = true;
				$this->email = $email;
				$this->id = $user['id'];
				$this->admin = $user['admin'];
				return true;
			}
		}
		$this->errors[]=_("Die Login Daten sind nicht korrekt");
		return false;
	}
	function logout() {
		$this->logged_in = false;
	}
	public function changePassword($password,$new_password) 
	{
		if($this->login($this->email,$password))
		{
			$db = new DB();
			# Try to use stronger but system-specific hashes, with a possible fallback to
			# the weaker portable hashes.
			$hash = new PasswordHash(8, FALSE);
			$hash = $hash->HashPassword($new_password);

			$change = $db->prepare('UPDATE `survey_users` SET password = :new_password WHERE email = :email');
			$change->bindParam(':email',$email);
			$change->bindParam(':new_password',$hash);
			$change->execute() or die('db');
			return true;
		}
		return false;
	}

	public function getStudies() {
		if($this->admin):
			$db = new DB();
			$studies = $db->query("SELECT * FROM `survey_studies`");
			$results = array();
			while($study = $studies->fetch())
			{
				$results[] = $study;
			}
			return $results;
		endif;
		return false;
	}
	public function getRuns() {
		if($this->admin):
			$db = new DB();
			$studies = $db->query("SELECT * FROM `survey_runs`");
			$results = array();
			while($study = $studies->fetch())
			{
				$results[] = $study;
			}
			return $results;
		endif;
		return false;
	}



	function getAvailableRuns()
	{
		return array();
	}


}

