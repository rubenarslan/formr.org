<?php
require_once INCLUDE_ROOT . "Model/DB.php";
#require_once INCLUDE_ROOT . 'vendor/ircmaxell/password-compat/lib/password.php';

class User
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
	public $cron = false;
	private $dbh;


	public function __construct($fdb, $id, $user_code) 
	{		
		$this->dbh = $fdb;
		if($id !== NULL): // if there is a registered, logged in user
			$this->id = (int)$id;
			$this->load(); // load his stuff
		elseif($user_code !== NULL):
			$this->user_code = $user_code; // if there is someone who has been browsing the site
		else:
			$this->user_code = bin2hex(openssl_random_pseudo_bytes(32)); // a new arrival
		endif;
		
		if($this->user_code === NULL):
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
			$this->id = (int)$user['id'];
			$this->user_code = $user['user_code'];
			$this->admin = $user['admin'];
			return true;
		}
		return false;
	}
	public function loggedIn()
	{
		return $this->logged_in;
	}
	public function isCron()
	{
		return $this->cron;
	}
	public function isAdmin()
	{
		return $this->admin >= 1;
	}
	public function isSuperAdmin()
	{
		return $this->admin >= 10;
	}
	public function created($object)
	{
		return (int)$this->id === (int)$object->user_id; 
	}
	public function register($email, $password) 
	{
		$exists = $this->dbh->prepare("SELECT email FROM `survey_users` WHERE email = :email LIMIT 1");
		$exists->bindParam(':email',$email);
		$exists->execute() or die('db');
		if($user = $exists->rowCount() === 0)
		{
			$hash = password_hash($password, PASSWORD_DEFAULT);

			if($hash):
				$add = $this->dbh->prepare('INSERT INTO `survey_users` SET 
						email = :email,
						created = NOW(),
						password = :password,
						user_code = :user_code');
				$add->bindParam(':email',$email);
				$add->bindParam(':password',$hash);
				$add->bindParam(':user_code',$this->user_code);
				$add->execute() or die('Couldnt add user');
				
				$login = $this->login($email, $password);
				$this->needToVerifyMail();
				return true;
				
			else:
				alert('<strong>Error!</strong> Hash error.','alert-danger');
				return false;
			endif;
		} else
		{
			$this->errors[] = 'User exists already.';
		}
		return false;
	}
	public function needToVerifyMail()
	{
		$token = bin2hex(openssl_random_pseudo_bytes(32));
		$token_hash = password_hash($token, PASSWORD_DEFAULT);
	
		$add = $this->dbh->prepare('UPDATE `survey_users` SET 
				email_verification_hash = :token_hash,
				email_verified = 0
				WHERE id = :id');
		$add->bindParam(':id',$this->id);
		$add->bindParam(':token_hash',$token_hash);
		$add->execute() or die('Could not set up email verification');

		$verify_link = WEBROOT."public/verify_email/?email=".rawurlencode($this->email)."&verification_token=".$token;
	
		global $site;
		$mail = $site->makeAdminMailer();
		$mail->AddAddress($this->email);
		$mail->Subject = 'formr: confirm your email address';
		$mail->Body = "Dear user,

you, or someone else created an account on ".WEBROOT.".
For some studies, you will need a verified email address.
To verify your address, please go to this link:
".$verify_link."

If you did not sign up, please notify us and we will 
suspend the account.

Best regards,

formr robots";

		if(!$mail->Send()):
			alert($mail->ErrorInfo,'alert-danger');
		else:
			alert("You were sent an email to verify your address.",'alert-info');
		endif;
	}
	public function login($email,$password) 
	{
		$login = $this->dbh->prepare("SELECT id, password, admin, user_code FROM `survey_users` WHERE email = :email LIMIT 1");
		$login->bindParam(':email',$email);
		$login->execute() or die('db');
		if($user = $login->fetch())
		{
			if(password_verify($password, $user['password']))
			{
				if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) 
				{
			        $hash = password_hash($password, PASSWORD_DEFAULT);
			        /* Store new hash in db */
					if($hash):
						$add = $this->dbh->prepare('UPDATE `survey_users` SET 
								password = :password WHERE email = :email');
						$add->bindParam(':email',$email);
						$add->bindParam(':password',$hash);
						$add->execute() or die('probl');
					else:
						alert('<strong>Error!</strong> Hash error.','alert-danger');
						return false;
					endif;
			    }
				$this->logged_in = true;
				$this->email = $email;
				$this->id = $user['id'];
				$this->user_code = $user['user_code'];
				$this->admin = $user['admin'];
				return true;
			}
		}
		$this->errors[]=_("<strong>Error.</strong> Your login credentials were incorrect.");
		return false;
	}
	public function setAdminLevelTo($level)
	{
		global $user;
		if(! $user->isSuperAdmin()) die ("sth wrong");
		
		$level = (int)$level;
		if($level !== 0 AND $level !== 1):
			if($level > 1)
				$level = 1;
			else
				$level = 0;
		endif;
		
		$set_level = $this->dbh->prepare("UPDATE `survey_users` SET `admin` = :admin
		 WHERE `id` = :id AND `admin` < 100 LIMIT 1"); // cannot de-elevate superadmins here
		
		$set_level->bindParam(':id',$this->id);
		$set_level->bindParam(':admin',$level);
		return $set_level->execute() or die('db');
	}
	public function forgot_password($email)
	{
		$exists = $this->dbh->prepare("SELECT email FROM `survey_users` WHERE email = :email LIMIT 1");
		$exists->bindParam(':email',$email);
		$exists->execute() or die('db');
		if($user = $exists->rowCount() === 0):
			alert("This email address is not registered here.","alert-danger");
			return false;
		else:
			$token = bin2hex(openssl_random_pseudo_bytes(32));
			$update_token = $this->dbh->prepare("UPDATE `survey_users` SET `reset_token_hash` = :reset_token_hash,
			`reset_token_expiry` = NOW() + INTERVAL 2 DAY 
			 WHERE email = :email LIMIT 1");
			
			$hash = password_hash($token, PASSWORD_DEFAULT);
			$update_token->bindParam(':reset_token_hash',$hash);
			$update_token->bindParam(':email',$email);
			$update_token->execute() or die('db');
			
			$reset_link = WEBROOT."public/reset_password?email=".rawurlencode($email)."&reset_token=".$token;

			global $site;
			$mail = $site->makeAdminMailer();
			$mail->AddAddress($email);
			$mail->Subject = 'formr: forgot password';
			$mail->Body = "Dear user,

you, or someone else used the forgotten password box on ".WEBROOT."
to create a link for you to reset your password. 
If that was you, you can go to this link (within two days)
to choose a new password:
".$reset_link."

If that wasn't you, please simply do not react.

Best regards,

formr robots";
		
			if(!$mail->Send()):
				alert($mail->ErrorInfo,'alert-danger');
			else:
				alert("You were sent a password reset link.",'alert-info');
				redirect_to("public/forgot_password");
			endif;
			
		endif;
		
	}
	function logout() 
	{
		$this->logged_in = false;
	    session_unset();     // unset $_SESSION variable for the run-time 
	    session_destroy();   // destroy session data in storage
		session_name("formr_session");
		session_start();	 // get a new session
	}
	public function changePassword($password,$new_password) 
	{
		if($this->login($this->email,$password)):
			
	        $hash = password_hash($new_password, PASSWORD_DEFAULT);
	        /* Store new hash in db */
			if($hash):
				$add = $this->dbh->prepare('UPDATE `survey_users` SET 
						password = :password WHERE email = :email');
				$add->bindParam(':email',$email);
				$add->bindParam(':password',$hash);
				$add->execute() or die('probl');
				return true;
			else:
				alert('<strong>Error!</strong> Hash error.','alert-danger');
				return false;
			endif;
		endif;
		return false;
	}
	public function changeEmail($password, $email) 
	{
		if($this->login($this->email,$password)):
			
        	$add = $this->dbh->prepare('UPDATE `survey_users` SET 
					email_verified = 0,
					email = :email WHERE id = :id');
			$add->bindParam(':id',$this->id);
			$add->bindParam(':email',$email);
			$add->execute() or die('probl');
			$this->email = $email;
			$this->needToVerifyMail();
			return true;
		endif;
		return false;
	}
	public function reset_password($email, $token, $new_password)
	{
		$proper = $this->dbh->prepare("SELECT reset_token_hash FROM `survey_users` WHERE email = :email LIMIT 1");
		$proper->bindParam(':email',$email);
		$proper->execute() or die('db');
		if($user = $proper->fetch()):
			if(password_verify($token, $user['reset_token_hash'])):
				
				$update = $this->dbh->prepare('UPDATE `survey_users` SET 
						password = :password, reset_token_hash = NULL, reset_token_expiry = NULL
				WHERE email = :email LIMIT 1');
		        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
				$update->bindParam(':email',$email);
				$update->bindParam(':password',$password_hash);
				$update->execute() or die('probl');
				alert("Your password was successfully changed. You can now use it to login.","alert-success");
				return true;
			endif;
		endif;
		
		alert("Incorrect token or email address.","alert-danger");
		return false;
	}
	public function verify_email($email, $token)
	{
		$proper = $this->dbh->prepare("SELECT email_verification_hash FROM `survey_users` WHERE email = :email LIMIT 1");
		$proper->bindParam(':email',$email);
		$proper->execute() or die('db');
		if($user = $proper->fetch()):
			if(password_verify($token, $user['email_verification_hash'])):
				
				$update = $this->dbh->prepare('UPDATE `survey_users` SET 
						email_verification_hash = NULL, email_verified = 1
				WHERE email = :email LIMIT 1');
				$update->bindParam(':email',$email);
				$update->execute() or die('probl');
				alert("Your email was successfully verified!","alert-success");
				return true;
			else:
				alert("Your email verification token was invalid or oudated. Please try copy-pasting the link in your email and removing any spaces.","alert-danger");
				return false;
			endif;
		endif;
		
		alert("Incorrect token or email address.","alert-danger");
		return false;
	}
	public function getStudies() 
	{
		if($this->isAdmin()):
			$g_studies = $this->dbh->prepare("SELECT * FROM `survey_studies` WHERE user_id = :user_id");
			$g_studies->bindParam(':user_id',$this->id);
			$g_studies->execute();
			
			$results = array();
			while($run = $g_studies->fetch())
			{
				$results[] = $run;
			}
			return $results;
		endif;
		return false;
	}
	public function getEmailAccounts() {
		if($this->isAdmin()):
			$accs = $this->dbh->prepare("SELECT `id`,`from` FROM `survey_email_accounts` WHERE user_id = :user_id");
			$accs->bindParam(':user_id',$this->id);
			$accs->execute();
			$results = array();
			while($acc = $accs->fetch(PDO::FETCH_ASSOC))
			{
				if($acc['from']==null) $acc['from'] = 'New.';
				$results[] = $acc;
			}
			return $results;
		endif;
		
		return false;
	}
	public function getRuns() {
		if($this->isAdmin()):
			$g_runs = $this->dbh->prepare("SELECT * FROM `survey_runs` WHERE user_id = :user_id");
			$g_runs->bindParam(':user_id',$this->id);
			$g_runs->execute();
			
			$results = array();
			while($run = $g_runs->fetch())
			{
				$results[] = $run;
			}
			return $results;
		endif;
		return false;
	}



	function getAvailableRuns()
	{
		$runs = $this->dbh->query("SELECT name,title, public_blurb_parsed FROM `survey_runs` WHERE public > 2");
		$results = array();
		while($run = $runs->fetch())
		{
			$results[] = $run;
		}
		return $results;
	}


}

