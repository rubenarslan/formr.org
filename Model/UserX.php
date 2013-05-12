<?php
require_once INCLUDE_ROOT . "Model/DB.php";
require_once INCLUDE_ROOT . 'phpass/PasswordHash.php';

class UserX
{
	public $id = null;
	public $email = null;
	public $user_code = null;
	private $logged_in = false;
	private $admin = false;
	// todo: time zone, etc.
	public $settings = array();
	public $errors = array();
	public $messages = array();
	private $dbh;


	public function __construct($fdb, $id, $user_code) 
	{		
		$this->dbh = $fdb;
		if($id !== NULL): // if there is a registered, logged in user
			$this->id = $id;
			$this->load(); // load his stuff
		elseif($user_code !== NULL):
			$this->user_code = $user_code; // if there is someone who has been browsing the site
		else:
			$this->user_code = bin2hex(openssl_random_pseudo_bytes(32)); // a new arrival
		endif;
	}
	public function __sleep()
	{
		return array('id','user_code');
	}
	private function load()
	{
		$load = $this->dbh->prepare("SELECT id, email, password, admin, user_code FROM `survey_users` WHERE id = :id LIMIT 1");
		$load->bindParam(':id',$this->id);
		$load->execute() or die('db');
		if($user = $load->fetch())
		{
			$this->logged_in = true;
			$this->email = $user['email'];
			$this->id = $user['id'];
			$this->user_code = $user['user_code'];
			$this->admin = $user['admin'];
			return true;
		}
		$this->errors[]=_("Die Login Daten sind nicht korrekt");
		return false;
	}
	public function loggedIn()
	{
		return $this->logged_in;
	}
	public function isAdmin()
	{
		return $this->admin;
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
		$exists = $this->dbh->prepare("SELECT email FROM `survey_users` WHERE email = :email LIMIT 1");
		$exists->bindParam(':email',$email);
		$exists->execute() or die('db');
		if($user = $exists->rowCount() === 0)
		{
			$hash = new PasswordHash(8, FALSE);
			$hash = $hash->HashPassword($password);

			$add = $this->dbh->prepare('INSERT INTO `survey_users` SET 
					email = :email,
					password = :password,
					user_code = :user_code');
			$add->bindParam(':email',$email);
			$add->bindParam(':password',$hash);
			$add->bindParam(':user_code',$this->user_code);
			$add->execute() or die('probl');
			
			return $this->login($email,$password);
		} else
		{
			$this->errors[] = 'User exists already.';
		}
		return false;
	}
	public function login($email,$password) {
		$login = $this->dbh->prepare("SELECT id, password, admin, user_code FROM `survey_users` WHERE email = :email LIMIT 1");
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
				$this->user_code = $user['user_code'];
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
			# Try to use stronger but system-specific hashes, with a possible fallback to
			# the weaker portable hashes.
			$hash = new PasswordHash(8, FALSE);
			$hash = $hash->HashPassword($new_password);

			$change = $this->dbh->prepare('UPDATE `survey_users` SET password = :new_password WHERE email = :email');
			$change->bindParam(':email',$email);
			$change->bindParam(':new_password',$hash);
			$change->execute() or die('db');
			return true;
		}
		return false;
	}

	public function getStudies() {
		if($this->isAdmin()):
			$studies = $this->dbh->query("SELECT * FROM `survey_studies`");
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
		if($this->isAdmin()):
			$db = new DB();
			$studies = $this->dbh->query("SELECT * FROM `survey_runs`");
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
	}


}

