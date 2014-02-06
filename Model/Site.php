<?php
// welcome to the messy section of the code
require_once INCLUDE_ROOT . "config_default/settings.php"; ## this way, if I add new settings, the defaults are set
require_once INCLUDE_ROOT . "config/settings.php";

define('DEBUG', ONLINE ? 1 : $settings['display_errors_when_live']);

if(DEBUG > -1)
	ini_set('display_errors',1);
ini_set("log_errors",1);
ini_set("error_log", INCLUDE_ROOT . "tmp/logs/errors.log");
ini_set('session.gc_maxlifetime', $settings['session_cookie_lifetime'] * 60);
ini_set('session.cookie_lifetime', $settings['session_cookie_lifetime'] * 60 );

error_reporting(-1);

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
}
session_start();

// first we see what's in that session
if(isset($_SESSION['site']) AND is_object($_SESSION['site'])):
	$site = $_SESSION['site'];
endif;

$site->refresh();

if(isset($_SESSION['user'])):
	$sess_user = unserialize($_SESSION['user']);

	// this segment basically checks whether the user-specific expiry time was met
	if(isset($sess_user->id)):
		if(! expire_session($settings['expire_registered_session'])):
			$user = new User($fdb, $sess_user->id, $sess_user->user_code);
			
			if($user->isAdmin()):
				if(expire_session($settings['expire_admin_session'])):
					unset($user);
				endif;
			endif;
		endif;
	elseif(isset($sess_user->user_code)):
		if(! expire_session($settings['expire_unregistered_session'])):
			$user = new User($fdb, null, $sess_user->user_code);
		endif;
	endif;
endif;

$_SESSION['last_activity'] = time(); // update last activity time stamp

if(!isset($site)): // site is actually preserved, even if sessions expire, because it may contain warnings, referers
	$site = new Site();
endif;

if(!isset($user)):
	$user = new User($fdb, null, null);
endif;
/*
HELPER FUNCTIONS
*/
function expire_session($expiry)
{
	if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $expiry * 60)) {
	    // last request was more than 30 minutes ago
		alert("You were logged out automatically, because you were last active ". timetostr($_SESSION['last_activity']) .'.', 'alert-info');
		$last_active = $_SESSION['last_activity'];
	    session_unset();     // unset $_SESSION variable for the run-time 
	    session_destroy();   // destroy session data in storage
		session_start();	 // get a new session
		return true;
	}
	else
		return false;
}

function alert($msg, $class = 'alert-warning', $dismissable = true) // shorthand
{
	global $site;
	$site->alert($msg,$class, $dismissable);
}

function redirect_to($location) {
	global $site,$user;
	$_SESSION['site'] = $site;
	$_SESSION['user'] = serialize($user);

	if(mb_substr($location,0,4)!= 'http'){
		$base = WEBROOT;
		if(mb_substr($location,0,1)=='/')
			$location = $base . mb_substr($location,1);
		else $location = $base . $location;
	}
#	try
#	{
	    header("Location: $location");
		exit;
#	}
#	catch (Exception $e)
#	{ // legacy of not doing things properly, ie needing redirects after headers were sent. 
#		echo "<script type=\"text/javascript\">document.location.href = \"$location\";</script>";
#	}
}
function h($text) {
	return htmlspecialchars($text);
}

function debug($string) {
    if( DEBUG ) {
		echo "<pre>";
        var_dump($string);
		echo "</pre>";
    }
}
function pr($string) {
    if( DEBUG > -1 ) {
		echo "<pre>";
        var_dump($string);
#		print_r(	debug_backtrace());
		echo "</pre>";
    }
}

if (!function_exists('_')) {
	function _($text) {
		return $text;
	}
}


if (!function_exists('__')) {

/**
taken from cakePHP
 */
	function __($singular, $args = null) {
		if (!$singular) {
			return;
		}

		$translated = _($singular);
		if ($args === null) {
			return $translated;
		} elseif (!is_array($args)) {
			$args = array_slice(func_get_args(), 1);
		}
		return vsprintf($translated, $args);
	}
}

if (!function_exists('__n')) {

/**
taken from cakePHP
 */
	function __n($singular, $plural, $count, $args = null) {
		if (!$singular) {
			return;
		}

		$translated = ngettext($singular, $plural, null, 6, $count);
		if ($args === null) {
			return $translated;
		} elseif (!is_array($args)) {
			$args = array_slice(func_get_args(), 3);
		}
		return vsprintf($translated, $args);
	}

}

function endsWith($haystack, $needle)
{
    $length = strlen($needle);
    if ($length == 0) {
        return true;
    }

    return (mb_substr($haystack, -$length) === $needle);
}

/**
 * Gets an environment variable from available sources, and provides emulation
 * for unsupported or inconsistent environment variables (i.e. DOCUMENT_ROOT on
 * IIS, or SCRIPT_NAME in CGI mode).  Also exposes some additional custom
 * environment information.
 *
 * @param  string $key Environment variable name.
 * @return string Environment variable setting.
 * @link http://book.cakephp.org/2.0/en/core-libraries/global-constants-and-functions.html#env
 */
function env($key) {
	if ($key === 'HTTPS') {
		if (isset($_SERVER['HTTPS'])) {
			return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
		}
		return (mb_strpos(env('SCRIPT_URI'), 'https://') === 0);
	}

	if ($key === 'SCRIPT_NAME') {
		if (env('CGI_MODE') && isset($_ENV['SCRIPT_URL'])) {
			$key = 'SCRIPT_URL';
		}
	}

	$val = null;
	if (isset($_SERVER[$key])) {
		$val = $_SERVER[$key];
	} elseif (isset($_ENV[$key])) {
		$val = $_ENV[$key];
	} elseif (getenv($key) !== false) {
		$val = getenv($key);
	}

	if ($key === 'REMOTE_ADDR' && $val === env('SERVER_ADDR')) {
		$addr = env('HTTP_PC_REMOTE_ADDR');
		if ($addr !== null) {
			$val = $addr;
		}
	}

	if ($val !== null) {
		return $val;
	}

	switch ($key) {
		case 'SCRIPT_FILENAME':
			if (defined('SERVER_IIS') && SERVER_IIS === true) {
				return str_replace('\\\\', '\\', env('PATH_TRANSLATED'));
			}
			break;
		case 'DOCUMENT_ROOT':
			$name = env('SCRIPT_NAME');
			$filename = env('SCRIPT_FILENAME');
			$offset = 0;
			if (!mb_strpos($name, '.php')) {
				$offset = 4;
			}
			return mb_substr($filename, 0, -(strlen($name) + $offset));
			break;
		case 'PHP_SELF':
			return str_replace(env('DOCUMENT_ROOT'), '', env('SCRIPT_FILENAME'));
			break;
		case 'CGI_MODE':
			return (PHP_SAPI === 'cgi');
			break;
		case 'HTTP_BASE':
			$host = env('HTTP_HOST');
			$parts = explode('.', $host);
			$count = count($parts);

			if ($count === 1) {
				return '.' . $host;
			} elseif ($count === 2) {
				return '.' . $host;
			} elseif ($count === 3) {
				$gTLD = array(
					'aero',
					'asia',
					'biz',
					'cat',
					'com',
					'coop',
					'edu',
					'gov',
					'info',
					'int',
					'jobs',
					'mil',
					'mobi',
					'museum',
					'name',
					'net',
					'org',
					'pro',
					'tel',
					'travel',
					'xxx'
				);
				if (in_array($parts[1], $gTLD)) {
					return '.' . $host;
				}
			}
			array_shift($parts);
			return '.' . implode('.', $parts);
			break;
	}
	return null;
}

function makeUnit($dbh, $session, $unit)
{
	$type = $unit['type'];
	if(!in_array($type, array('Survey', 'Study','Pause','Email','External','Page','SkipBackward','SkipForward','Shuffle')))
		die('The unit type is not allowed!');
	
	require_once INCLUDE_ROOT . "Model/$type.php";
	return new $type($dbh, $session, $unit);
}
function emptyNull(&$x){
	$x = ($x=='') ? null : $x;
}
function stringBool($x)
{
	if($x===false) return 'false';
	elseif($x===true) return 'true';
	elseif($x===null)  return 'null';
	elseif($x===0)  return '0';
	else return $x;
}
function hardTrueFalse($x)
{
	if($x===false) return 'FALSE';
	elseif($x===true) return 'TRUE';
#	elseif($x===null)  return 'NULL';
	elseif($x===0)  return '0';
	else return $x;
}





if (!function_exists('http_parse_headers'))
{
    function http_parse_headers($raw_headers)
    {
        $headers = array();
        $key = ''; // [+]

        foreach(explode("\n", $raw_headers) as $i => $h)
        {
            $h = explode(':', $h, 2);

            if (isset($h[1]))
            {
                if (!isset($headers[$h[0]]))
                    $headers[$h[0]] = trim($h[1]);
                elseif (is_array($headers[$h[0]]))
                {
                    // $tmp = array_merge($headers[$h[0]], array(trim($h[1]))); // [-]
                    // $headers[$h[0]] = $tmp; // [-]
                    $headers[$h[0]] = array_merge($headers[$h[0]], array(trim($h[1]))); // [+]
                }
                else
                {
                    // $tmp = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [-]
                    // $headers[$h[0]] = $tmp; // [-]
                    $headers[$h[0]] = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [+]
                }

                $key = $h[0]; // [+]
            }
            else // [+]
            { // [+]
                if (mb_substr($h[0], 0, 1) == "\t") // [+]
                    $headers[$key] .= "\r\n\t".trim($h[0]); // [+]
                elseif (!$key) // [+]
                    $headers[0] = trim($h[0]); // [+]
            } // [+]
        }

        return $headers;
    }
}


/**
 * Format a timestamp to display its age (5 days ago, in 3 days, etc.).
 *
 * @param   int     $timestamp
 * @return  string
 */
function timetostr($timestamp) {
    $age = time() - $timestamp;
    if ($age == 0)
        return "just now";
    $future = ($age < 0);
    $age = abs($age);

    $age = (int)($age / 60);        // minutes ago
    if ($age == 0) return $future ? "momentarily" : "just now";

    $scales = [
        ["minute", "minutes", 60],
        ["hour", "hours", 24],
        ["day", "days", 7],
        ["week", "weeks", 4.348214286],     // average with leap year every 4 years
        ["month", "months", 12],
        ["year", "years", 10],
        ["decade", "decades", 10],
        ["century", "centuries", 1000],
        ["millenium", "millenia", PHP_INT_MAX]
    ];

    foreach ($scales as $scale) {
        list($singular, $plural, $factor) = $scale;
        if ($age == 0)
            return $future
                ? "in less than 1 $singular"
                : "less than 1 $singular ago";
        if ($age == 1)
            return $future
                ? "in 1 $singular"
                : "1 $singular ago";
        if ($age < $factor)
            return $future
                ? "in $age $plural"
                : "$age $plural ago";
        $age = (int)($age / $factor);
    }
}
// from http://de1.php.net/manual/en/function.filesize.php
function human_filesize($bytes, $decimals = 2) {
  $sz = 'BKMGTP';
  $factor = floor((strlen($bytes) - 1) / 3);
  return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
}

function cr2nl ($string)
{
	return str_replace("\r\n","\n",$string);
}
