<?php
define('DEBUG',0);

if(DEBUG > -1)
	ini_set('display_errors',1);
ini_set("log_errors",1);
ini_set("error_log", INCLUDE_ROOT . "log/errors.log");

error_reporting(-1);
date_default_timezone_set('Europe/Berlin');
require_once INCLUDE_ROOT."Model/UserX.php";

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

if(isset($_SESSION['user']) AND is_object($_SESSION['user']))
	$user = $_SESSION['user'];
else
	$user = new UserX(null);

if(isset($_SESSION['site']) AND is_object($_SESSION['site']))
	$site = $_SESSION['site'];
else
	$site = new Site();


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
	$_SESSION['user'] = $user;
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