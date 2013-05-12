<?php
define('DEBUG',0);

if(DEBUG > -1)
	ini_set('display_errors',1);
ini_set("log_errors",1);
ini_set("error_log", INCLUDE_ROOT . "log/errors.log");

error_reporting(-1);
date_default_timezone_set('Europe/Berlin');
require_once INCLUDE_ROOT."Model/UserX.php";

$fdb = new DB();

class Site
{
	public $alerts = array();
	public function __construct()
	{
	}
	public function renderAlerts()
	{
		$now_handled = $this->alerts;
		$this->alerts = array();
		return implode($now_handled);
	}
	public function alert($msg, $class = '')
	{
		$this->alerts[] = "<div class='alert $class'>$msg</div>";
	}
}

session_start();

if(isset($_SESSION['site']) AND is_object($_SESSION['site']))
	$site = $_SESSION['site'];
else
	$site = new Site();

if(isset($_SESSION['user']))
{
	$sess_user = unserialize($_SESSION['user']);
	
	if(isset($sess_user->id))
		$user = new UserX($fdb, $sess_user->id, $sess_user->user_code);
	elseif(isset($sess_user->user_code))
		$user = new UserX($fdb, null, $sess_user->user_code);	
}
if(!isset($user))
	$user = new UserX($fdb, null, null);

/*
HELPER FUNCTIONS
*/
function alert($msg, $class) // shorthand
{
	global $site;
	$site->alert($msg,$class);
}

function redirect_to($location) {
	global $site,$user;
	$_SESSION['site'] = $site;
	$_SESSION['user'] = serialize($user);

	if(substr($location,0,4)!= 'http'){
		$base = WEBROOT;
		if(substr($location,0,1)=='/')
			$location = $base . substr($location,1);
		else $location = $base . $location;
	}
	try
	{
	    header("Location: $location");
		exit;
	}
	catch (Exception $e)
	{ // legacy of not doing things properly, ie needing redirects after headers were sent. 
		echo "<script type=\"text/javascript\">document.location.href = \"$location\";</script>";
	}
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
    if( DEBUG!==-1 ) {
		echo "<pre>";
        var_dump($string);
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

    return (substr($haystack, -$length) === $needle);
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
		return (strpos(env('SCRIPT_URI'), 'https://') === 0);
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
			if (!strpos($name, '.php')) {
				$offset = 4;
			}
			return substr($filename, 0, -(strlen($name) + $offset));
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
