<?php
define('APPLICATION_ROOT', __DIR__. '/');
define('INCLUDE_ROOT', APPLICATION_ROOT);

define('APPLICATION_PATH', APPLICATION_ROOT . 'application/');

define('APPPLICATION_CRYPTO_KEY_FILE', APPLICATION_ROOT  . 'formr-crypto.key');

// Load composer Autoloader
require_once APPLICATION_ROOT . 'vendor/autoload.php';

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
require_once APPLICATION_ROOT . 'config_default/settings.php';
require_once APPLICATION_ROOT . 'config/settings.php';

// Define default assets
if(php_sapi_name() != 'cli') {
	require_once APPLICATION_ROOT . 'config_default/assets.php';
}

// Set current formr version (bumped on release)
$settings['version'] = 'v0.16.13';

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

	// Set cryptography module
	try {
		Crypto::setup();
	} catch (Exception $e) {
		formr_log_exception($e);
		exit($e->getMessage());
		
	}

	// Set default timzone, encoding and shutdown function.
	date_default_timezone_set(Config::get('timezone'));
	mb_internal_encoding('UTF-8');
	register_shutdown_function('shutdown_formr_org');

}
__setup($settings);

