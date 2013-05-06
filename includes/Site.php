<?php
define('DEBUG',0);

if(DEBUG > -1)
	ini_set('display_errors',1);
ini_set("log_errors",1);
ini_set("error_log", INCLUDE_ROOT . "log/errors.log");

error_reporting(-1);
date_default_timezone_set('Europe/Berlin');

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

function redirect_to($location) {
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
