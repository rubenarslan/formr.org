<?php
define('INCLUDE_ROOT', __DIR__. '/');

// Load composer Autoloader
require_once INCLUDE_ROOT . "vendor/autoload.php";

// Initialize settings array and define routes
$settings = array();
$settings['routes'] = array (
	'public',
	'admin',
	'admin/run',
	'admin/survey',
	'admin/mail',
	'superadmin',
	'api',
);

// Load application settings
/* @var $settings array */
require_once INCLUDE_ROOT . "config_default/settings.php";
require_once INCLUDE_ROOT . "config/settings.php";

// Load application autoloader
$autoloader = require_once INCLUDE_ROOT . "Library/Autoloader.php";
// Include helper functions
require_once INCLUDE_ROOT . "Library/Functions.php";
// Initialize Config
Config::initialize($settings);

// Define global constants
function define_webroot($settings = array()) {
	if(defined('WEBROOT')) return;

	$protocol = (!isset($_SERVER['HTTPS']) OR $_SERVER['HTTPS'] == '') ? 'http://' : 'https://';
    $doc_root = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'].'/' : '/';
	$online = true;

   // Maybe dev env contains $settings['define_root'] so use these
    if (!empty($settings['define_root'])) {
        extract($settings['define_root']);
    }
	
	define('WEBROOT', $protocol . $doc_root);
	define('ONLINE', $online);
	define('SSL', $protocol === "https://");
    define('RUNROOT', WEBROOT);
	define('DEBUG', Config::get('display_errors'));
	define('VERSION', Config::get('version'));
}
define_webroot($settings);
