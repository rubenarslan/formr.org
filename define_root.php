<?php
require_once "vendor/autoload.php";
function define_webroot() {
	if(defined('WEBROOT')) return;
	
	$protocol = (!isset($_SERVER['HTTPS']) OR $_SERVER['HTTPS'] == '') ? 'http://' : 'https://';

	if(isset($_SERVER['SERVER_NAME'])){
		switch($_SERVER['SERVER_NAME']){
			case 'localhost':
				$doc_root = "localhost:8888/formr/";
				$server_root = __DIR__ . '/';
				$online = false;
				$testing = false;

				break;
			default:
				$doc_root = $_SERVER['SERVER_NAME'].'/';
				$server_root = __DIR__ . '/';
				$online = true;
				$testing = false;
				
				break;
		}
	} 
	else
	{
		$doc_root = "localhost:8888/formr/";
		$server_root = __DIR__ . '/';
		$online = false;
		$testing = true;
	}

	define('WEBROOT',$protocol . $doc_root);
	define('INCLUDE_ROOT',$server_root);
	define('ONLINE',$online);
	define('TESTING',$testing);
	define('SSL',$protocol === "https://");
}
define_webroot();