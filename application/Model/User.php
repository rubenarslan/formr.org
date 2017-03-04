<?php

class User {

	public $id = null;
	public $email = null;
	public $user_code = null;
	private $logged_in = false;
	private $admin = false;
	private $referrer_code = null;
	// todo: time zone, etc.
	public $settings = array();
	public $errors = array();
	public $messages = array();
	public $cron = false;

	/**
	 * @var DB
	 */
	private $dbh;

	public function __construct($fdb, $id = null, $user_code = null) {
		$this->dbh = $fdb;

		if ($id !== null): // if there is a registered, logged in user
			$this->id = (int) $id;
			$this->load(); // load his stuff
		elseif ($user_code !== null):
			$this->user_code = $user_code; // if there is someone who has been browsing the site
		else:
			$this->user_code = crypto_token(48); // a new arrival
		endif;
	}

	public function __sleep() {
		return array('id', 'user_code');
	}

	private function load() {
		$user = $this->dbh->select('id, email, password, admin, user_code, referrer_code')
				->from('survey_users')
				->where(array('id' => $this->id))
				->limit(1)
				->fetch();

		if ($user) {
			$this->logged_in = true;
			$this->email = $user['email'];
			$this->id = (int) $user['id'];
			$this->user_code = $user['user_code'];
			$this->admin = $user['admin'];
			$this->referrer_code = $user['referrer_code'];
			return true;
		}

		return false;
	}

	public function loggedIn() {
		return $this->logged_in;
	}

	public function isCron() {
		return $this->cron;
	}

	public function isAdmin() {
		return $this->admin >= 1;
	}

	public function isSuperAdmin() {
		return $this->admin >= 10;
	}

	public function getAdminLevel() {
		return $this->admin;
	}
	public function getEmail() {
		return $this->email;
	}

	public function created($object) {
		return (int) $this->id === (int) $object->user_id;
	}

	public function register($email, $password, $referrer_code) {
		$hash = password_hash($password, PASSWORD_DEFAULT);
		
		$user_exists = $this->dbh->entry_exists('survey_users', array('email' => $email));
		if ($user_exists) {
			$this->errors[] = "User already exists";
			return false;
		}
		
		if ($this->user_code === null) {
			$this->user_code = crypto_token(48);
		}
		
		$this->referrer_code = $referrer_code;

		if ($hash) :
			$inserted = $this->dbh->insert('survey_users', array(
				'email' => $email,
				'created' => mysql_now(),
				'password' => $hash,
				'user_code' => $this->user_code,
				'referrer_code' => $this->referrer_code
			));

			if (!$inserted) {
				throw new Exception("Unable create user account");
			}

			$login = $this->login($email, $password);
			$this->needToVerifyMail();
			return true;

		else:
			alert('<strong>Error!</strong> Hash error.', 'alert-danger');
			return false;
		endif;
	}

	public function needToVerifyMail() {
		$token = crypto_token(48);
		$token_hash = password_hash($token, PASSWORD_DEFAULT);
		$this->dbh->update('survey_users', array('email_verification_hash' => $token_hash, 'email_verified' => 0), array('id' => $this->id));

		$verify_link = WEBROOT . "public/verify_email/?email=" . rawurlencode($this->email) . "&verification_token=" . rawurlencode($token);

		global $site;
		$mail = $site->makeAdminMailer();
		$mail->AddAddress($this->email);
		$mail->Subject = 'formr: confirm your email address';
		$mail->Body = "Dear user,

you, or someone else created an account on " . WEBROOT . ".
You will need to verify that this is your email address.
To do so, please go to this link:
" . $verify_link . "

If you did not sign up, please notify us and we will 
suspend the account.

If you signed up with a valid referral token, you will 
automatically be able to create new studies (rather 
than just take part).
If you have no such token, please reply to this email 
and tell us why you should get access (we're happy 
about new users, but want to know a little about what 
you plan to do).

To get help with conducting studies, refer to the help 
section here.
We have provided copious documentation and some detailed 
HowTos, but there also is a mailing list to which you 
can write if you need more help. 
". site_url("public/documentation#help")."

While you have a live study on formr, you should be 
signed up to the mailing list, so that you hear 
quickly if there will be a big update or if there
might be trouble with formr. 
There are fewer than two messages per month on the list.
https://groups.google.com/forum/#!forum/formr

Best regards,

formr robots";

		if (!$mail->Send()):
			alert($mail->ErrorInfo, 'alert-danger');
		else:
			alert("You were sent an email to verify your address.", 'alert-info');
		endif;
	}

	public function login($email, $password) {
		$user = $this->dbh->select('id, password, admin, user_code')
				->from('survey_users')
				->where(array('email' => $email))
				->limit(1)->fetch();

		if ($user && password_verify($password, $user['password'])) {
			if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
				$hash = password_hash($password, PASSWORD_DEFAULT);
				/* Store new hash in db */
				if ($hash):
					$this->dbh->update('survey_users', array('password' => $hash), array('email' => $email));
				else:
					alert('<strong>Error!</strong> Hash error.', 'alert-danger');
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

		$this->errors[] = _("<strong>Error.</strong> Your login credentials were incorrect.");
		return false;
	}

	public function setAdminLevelTo($level) {
		global $user;
		if (!$user->isSuperAdmin()) {
			throw new Exception("You need more admin rights to effect this change");
		}

		$level = (int) $level;
		if ($level !== 0 AND $level !== 1):
			if ($level > 1) {
				$level = 1;
			} else {
				$level = 0;
			}
		endif;

		return $this->dbh->update('survey_users', array('admin' => $level), array('id' => $this->id, 'admin <' => 100));
	}

	public function forgot_password($email) {
		$user_exists = $this->dbh->entry_exists('survey_users', array('email' => $email));

		if (!$user_exists):
			alert("This email address is not registered here.", "alert-danger");
			return false;
		else:
			$token = crypto_token(48);
			$hash = password_hash($token, PASSWORD_DEFAULT);

			$this->dbh->update('survey_users', array('reset_token_hash' => $hash, 'reset_token_expiry' => mysql_interval('+2 days')), array('email' => $email));

			$reset_link = WEBROOT . "public/reset_password?email=" . rawurlencode($email) . "&reset_token=" . rawurlencode($token);
			
			global $site;
			$mail = $site->makeAdminMailer();
			$mail->AddAddress($email);
			$mail->Subject = 'formr: forgot password';
			$mail->Body = "Dear user,

you, or someone else used the forgotten password box on " . site_url() . "
to create a link for you to reset your password. 
If that was you, you can go to this link (within two days)
to choose a new password:
" . $reset_link . "

If that wasn't you, please simply do not react.

Best regards,

formr robots";

			if (!$mail->Send()):
				alert($mail->ErrorInfo, 'alert-danger');
			else:
				alert("You were sent a password reset link.", 'alert-info');
				redirect_to("public/forgot_password");
			endif;

		endif;
	}

	function logout() {
		$this->logged_in = false;
		Session::destroy();
	}

	public function changePassword($password, $new_password) {
		if ($this->login($this->email, $password)):

			$hash = password_hash($new_password, PASSWORD_DEFAULT);
			/* Store new hash in db */
			if ($hash):
				$this->dbh->update('survey_users', array('password' => $hash), array('email' => $this->email));
				return true;
			else:
				alert('<strong>Error!</strong> Hash error.', 'alert-danger');
				return false;
			endif;
		endif;
		return false;
	}

	public function changeEmail($password, $email) {
		if ($this->login($this->email, $password)):
			$this->dbh->update('survey_users', array('email' => $email, 'email_verified' => 0), array('id' => $this->id));
			$this->email = $email;
			$this->needToVerifyMail();
			return true;
		endif;
		return false;
	}

	public function reset_password($email, $token, $new_password, $new_password_confirm) {
		if ($new_password !== $new_password_confirm) {
			alert('The passwords you entered do not match', 'alert-danger');
			return false;
		}

		$reset_token_hash = $this->dbh->findValue('survey_users', array('email' => $email), array('reset_token_hash'));

		if ($reset_token_hash):
			if (password_verify($token, $reset_token_hash)):
				$password_hash = password_hash($new_password, PASSWORD_DEFAULT);
				$this->dbh->update('survey_users', 
					array('password' => $password_hash, 'reset_token_hash' => null, 'reset_token_expiry' => null), 
					array('email' => $email),
					array('str', 'int', 'int')
				);
				$login_anchor = '<a href="'.site_url('login').'">login</a>';
				alert("Your password was successfully changed. You can now use it to {$login_anchor}.", 'alert-success');
				return true;
			endif;
		endif;

		alert("Incorrect token or email address.", "alert-danger");
		return false;
	}

	public function verify_email($email, $token) {
		$email_verification_hash = $this->dbh->findValue('survey_users', array('email' => $email), array('email_verification_hash'));

		if ($email_verification_hash):
			if (password_verify($token, $email_verification_hash)):

				$this->dbh->update('survey_users', 
					array('email_verification_hash' => null, 'email_verified' => 1), 
					array('email' => $email),
					array('int', 'int')
				);
				alert("Your email was successfully verified!", "alert-success");
				
				if(in_array( $this->referrer_code, Config::get("referrer_codes"))):
					$this->dbh->update('survey_users', 
						array('admin' => 1),
						array('email' => $email)
					);
					alert("You now have the rights to create your own studies!", "alert-success");
				endif;
				return true;
			else:
				alert("Your email verification token was invalid or oudated. Please try copy-pasting the link in your email and removing any spaces.", "alert-danger");
				return false;
			endif;
		endif;

		alert("Incorrect token or email address.", "alert-danger");
		return false;
	}

	public function getStudies() {
		if ($this->isAdmin()):
			return $this->dbh->find('survey_studies', array('user_id' => $this->id));
		endif;
		return false;
	}

	public function getEmailAccounts() {
		if ($this->isAdmin()):
			$accs = $this->dbh->find('survey_email_accounts', array('user_id' => $this->id), array('cols' => 'id, from'));

			$results = array();
			foreach ($accs as $acc) {
				if ($acc['from'] == null) {
					$acc['from'] = 'New.';
				}
				$results[] = $acc;
			}
			return $results;
		endif;

		return false;
	}

	public function getRuns() {
		if ($this->isAdmin()):
			return $this->dbh->find('survey_runs', array('user_id' => $this->id));
		endif;
		return false;
	}

	function getAvailableRuns() {
		return $this->dbh->select('name,title, public_blurb_parsed')
				->from('survey_runs')
				->where('public > 2')
				->fetchAll();
	}

}
