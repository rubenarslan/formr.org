<?php
define('INCLUDE_ROOT', __DIR__. '/');

define('APPLICATION_PATH', INCLUDE_ROOT . 'application/');

// Load composer Autoloader
require_once INCLUDE_ROOT . 'vendor/autoload.php';

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
require_once INCLUDE_ROOT . 'config_default/settings.php';
require_once INCLUDE_ROOT . 'config/settings.php';
// Set current formr version (bumbped on release)
$settings['version'] = 'v0.14.0';

// Load application autoloader
$autoloader = require_once APPLICATION_PATH . 'Library/Autoloader.php';
// Include helper functions
require_once APPLICATION_PATH . 'Library/Functions.php';
// Initialize Config
Config::initialize($settings);

// Global Setup
function __setup($settings = array()) {
	if(defined('WEBROOT')) return;

	$protocol = (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] == '') ? 'http://' : 'https://';
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
	
	// General PHP-side configuration
	error_reporting(-1);
	if (DEBUG > 0) {
		ini_set('display_errors', 1);
	}

	ini_set("log_errors", 1);
	ini_set("error_log", get_log_file('errors.log'));

	ini_set('session.gc_maxlifetime', Config::get('session_cookie_lifetime'));
	ini_set('session.cookie_lifetime', Config::get('session_cookie_lifetime'));
	ini_set('session.hash_function', 1);
	ini_set('session.hash_bits_per_character', 5);
	ini_set('session.gc_divisor', 100);
	ini_set('session.gc_probability', 1);

	date_default_timezone_set(Config::get('timezone'));
	mb_internal_encoding('UTF-8');
	register_shutdown_function('shutdown_formr_org');
}
__setup($settings);

