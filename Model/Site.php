<?php
// welcome to the messy section of the code
require_once INCLUDE_ROOT . "config_default/settings.php"; ## this way, if I add new settings, the defaults are set
require_once INCLUDE_ROOT . "config/settings.php";

require_once INCLUDE_ROOT . "Model/helper_functions.php";


error_reporting(-1);
define('DEBUG', ONLINE ? $settings['display_errors_when_live'] : 1);
if(DEBUG > -1)
	ini_set('display_errors',1);
ini_set("log_errors",1);
ini_set("error_log", INCLUDE_ROOT . "tmp/logs/errors.log");

ini_set('session.gc_maxlifetime', $settings['session_cookie_lifetime']);
ini_set('session.cookie_lifetime', $settings['session_cookie_lifetime']);
ini_set('session.hash_function', 1);
ini_set('session.hash_bits_per_character', 5);
ini_set('session.gc_divisor', 100);
ini_set('session.gc_probability', 1);
# ini_set('session.save_path', INCLUDE_ROOT . "tmp/sessions/"); // debian has a cronjob which automatically clears sessions every 30 minutes using /usr/lib/php5/maxlifetime, if the expiry is not set in 


date_default_timezone_set($settings['timezone']);
mb_internal_encoding("UTF-8");

require_once INCLUDE_ROOT . "Model/DB.php";
$fdb = new DB();

require_once INCLUDE_ROOT."Model/User.php";

class Site
{
	public $alerts = array();
	public $alert_types = array("alert-warning" => 0, "alert-success" => 0, "alert-info" => 0, "alert-danger" => 0);
	public $last_outside_referrer;
	public function refresh()
	{
		$this->lastOutsideReferrer();
	}
	public function renderAlerts()
	{
		$now_handled = $this->alerts;
		$this->alerts = array();
		$this->alert_types = array("alert-warning" => 0, "alert-success" => 0, "alert-info" => 0, "alert-danger" => 0);
		return implode($now_handled);
	}
	public function alert($msg, $class = 'alert-warning', $dismissable = true)
	{
		if(isset($this->alert_types[$class])): // count types of alerts
			$this->alert_types[$class]++;
		else:
			$this->alert_types[$class] = 1;
		endif;
		if(is_array($msg)) $msg = $msg['body'];
		
		if($class == 'alert-warning')
			$class_logo = 'exclamation-triangle';
		elseif($class == 'alert-danger')
			$class_logo = 'bolt';
		elseif($class == 'alert-info')
			$class_logo = 'info-circle';
		else // if($class == 'alert-success')
			$class_logo = 'thumbs-up';
		
		$logo = '<i class="fa fa-'.$class_logo.'"></i>';
		$this->alerts[] = "<div class='alert $class'>".$logo.'<button type="button" class="close" data-dismiss="alert">&times;</button>'."$msg</div>";
	}
	public function inSuperAdminArea()
	{
		return strpos($_SERVER['SCRIPT_NAME'],'/superadmin/')!==FALSE;
	}
	public function inAdminArea()
	{
		return strpos($_SERVER['SCRIPT_NAME'],'/admin/')!==FALSE;		
	}
	public function inAdminRunArea()
	{
		return strpos($_SERVER['SCRIPT_NAME'],'/admin/run/')!==FALSE;
	}
	public function inAdminSurveyArea()
	{
		return strpos($_SERVER['SCRIPT_NAME'],'/admin/survey/')!==FALSE;
	}
	public function lastOutsideReferrer()
	{
		$ref = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
		if(mb_strpos($ref, WEBROOT) !== 0)
		{
			$this->last_outside_referrer = $ref;
		}
	}
	public function makeAdminMailer()
	{
		global $settings;
		$mail = new PHPMailer();
		$mail->SetLanguage("de","/");
	
		$mail->IsSMTP();  // telling the class to use SMTP
		$mail->Mailer = "smtp";
		$mail->Host = $settings['email']['host'];
		$mail->Port = $settings['email']['port'];
		if($settings['email']['tls'])
			$mail->SMTPSecure = 'tls';
		else
			$mail->SMTPSecure = 'ssl';
		$mail->SMTPAuth = true; // turn on SMTP authentication
		$mail->Username = $settings['email']['username']; // SMTP username
		$mail->Password = $settings['email']['password']; // SMTP password
	
		$mail->From = $settings['email']['from'];
		$mail->FromName = $settings['email']['from_name'];
		$mail->AddReplyTo($settings['email']['from'],$settings['email']['from_name']);
		$mail->CharSet = "utf-8";
		$mail->WordWrap = 65;                                 // set word wrap to 50 characters

		return $mail;
	}
	public function expire_session($expiry)
	{
		if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $expiry)) {
		    // last request was more than 30 minutes ago
			alert("You were logged out automatically, because you were last active ". timetostr($_SESSION['last_activity']) .'.', 'alert-info');
		    session_unset();     // unset $_SESSION variable for the run-time 
		    session_destroy();   // destroy session data in storage
			$this->start_session();
			return true;
		}
		else
			return false;
	}
	public function start_session()
	{
		global $settings;
		session_name("formr_session");
		session_set_cookie_params ( $settings['session_cookie_lifetime'], "/" , null,  SSL , true );
		session_start();
	}
	public function loginUser($user)
	{
		if(isset($_GET['run_name']) AND isset($_GET['code']) AND strlen($_GET['code'])==64): // came here with a login link
			$login_code = $_GET['code'];

			if($user->user_code !== $login_code): // this user came here with a session code that he wasn't using before. this will always be true if the user is (a) new (auto-assigned code by site) (b) already logged in with a different account

				if($user->loggedIn()): // if the user is new and has an auto-assigned code, there's no need to talk about the behind-the-scenes change
					// but if he's logged in we should alert them
					alert("You switched sessions, because you came here with a login link and were already logged in as someone else.", 'alert-info');
				endif;

				$user->logout();
				$user = new User($fdb, null, $login_code);

				// a special case are admins. if they are not already logged in, verified through password, they should not be able to obtain access so easily. but because we only create a mock user account, this is no problem. the admin flags are only set/privileges are only given if they legitimately log in
			endif;
	
		elseif(isset($_GET['run_name']) AND isset($user->user_code)):
			// all good
		else:
			alert("<strong>Sorry.</strong> Something went wrong when you tried to access.",'alert-danger');
			redirect_to("index");
		endif;
		
		return $user;
	}
	public function makeTitle()
	{
		global $title;
		if(trim($title)) return $title;
		$path = '';
		if(isset($_SERVER['REDIRECT_URL'])) $path = $_SERVER['REDIRECT_URL'];
		else if (isset($_SERVER['SCRIPT_NAME'])) $path = $_SERVER['SCRIPT_NAME'];

		$path = preg_replace(array(
			"@var/www/@",
			"@formr/@",
			"@webroot/@",
			"@\.php$@",
			"@index$@",
			"@^/@",
			"@/$@",
		),"",$path);

		if($path != ''): 
			$title = "formr /". $path;
			$title = str_replace(array('_','/'),array(' ',' / '), $title);
		endif;
		return isset($title) ? $title : 'formr survey framework';
	}
}

$site = new Site();
$site->start_session();
if(isset($_SESSION['site']) AND is_object($_SESSION['site'])): // first we see what's in that session
	$site = $_SESSION['site']; // if we already have a site object, possibly with alerts and referrers, we use that instead
endif;

if(isset($_SESSION['user'])):
	$sess_user = unserialize($_SESSION['user']);


	// this segment basically checks whether the user-specific expiry time was met
	if(isset($sess_user->id)): // logged in user
		if(! $site->expire_session($settings['expire_registered_session'])): // if not expired: recreate user object
			$user = new User($fdb, $sess_user->id, $sess_user->user_code);
			
			if($user->isAdmin()):
				if($site->expire_session($settings['expire_admin_session'])): // admins have a different expiry, can only be lower
					unset($user);
				endif;
			endif;
		endif;
	elseif(isset($sess_user->user_code)):
		if(! $site->expire_session($settings['expire_unregistered_session'])):
			$user = new User($fdb, null, $sess_user->user_code);
		endif;
	endif;
endif;

$_SESSION['last_activity'] = time(); // update last activity time stamp



$site->refresh();

if(!isset($user)):
	$user = new User($fdb, null, null);
endif;
