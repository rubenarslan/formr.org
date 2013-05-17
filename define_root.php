<?php
function define_webroot() {
	if(defined('WEBROOT')) return;
	
	$protocol = (!isset($_SERVER['HTTPS']) OR $_SERVER['HTTPS'] == '') ? 'http://' : 'https://';

	if(isset($_SERVER['SERVER_NAME'])){
		switch($_SERVER['SERVER_NAME']){
			case 'localhost':
				$doc_root = "localhost:8888/zwang/survey/";
				$server_root = __DIR__ . '/';
				$online = false;
				break;
			default:
				$doc_root = $_SERVER['SERVER_NAME'].'/survey/';
				$server_root = __DIR__ . '/';
				$online = true;
				break;
		}
	}

	define('WEBROOT',$protocol . $doc_root);
	define('INCLUDE_ROOT',$server_root);
	define('ONLINE',$online);
}
define_webroot();