<?php
define('FORMR_VERSION', 'v0.19.4');

define('APPLICATION_ROOT', __DIR__ . '/');
define('INCLUDE_ROOT', APPLICATION_ROOT);

define('APPLICATION_PATH', APPLICATION_ROOT . 'application/');

define('APPPLICATION_CRYPTO_KEY_FILE', APPLICATION_ROOT . 'formr-crypto.key');

// Load composer Autoloader
require_once APPLICATION_ROOT . 'vendor/autoload.php';

// Initialize settings array and define routes
$settings = array();
$settings['routes'] = array(
	'admin'          => 'AdminController',
	'admin/run'      => 'AdminRunController',
	'admin/survey'   => 'AdminSurveyController',
	'admin/mail'     => 'AdminMailController',
	'admin/advanced' => 'AdminAdvancedController',
    'admin/account'  => 'AdminAccountController',
    'public'         => 'PublicController',
	'api'            => 'ApiController',
	'run'            => 'RunController'
);

// Load application settings
/* @var $settings array */
require_once APPLICATION_ROOT . 'config-dist/settings.php';
require_once APPLICATION_ROOT . 'config/settings.php';

// Define default assets
if (php_sapi_name() != 'cli') {
	require_once APPLICATION_ROOT . 'config-dist/assets.php';
	require_once APPLICATION_ROOT . 'config-dist/css-classes.php';
}

// Set current formr version (bumped on release)
$settings['version'] = FORMR_VERSION;

// Load application autoloader
$autoloader = require_once APPLICATION_PATH . 'Library/Autoloader.php';
// Include helper functions
require_once APPLICATION_PATH . 'Library/Functions.php';
// Initialize Config
Config::initialize($settings);

// Global Setup
function __formr_setup($settings = array()) {
	if (defined('WEBROOT')) {
		return;
	}

	$protocol = (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] == '') ? 'http://' : 'https://';
	$doc_root = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] . '/' : '/';
	$online = true;

	// Maybe dev env contains $settings['define_root'] so use these
	if (!empty($settings['define_root'])) {
		extract($settings['define_root']);
	}

	define('WEBROOT', $protocol . $doc_root);
	define('ONLINE', $online);
	define('SSL', $protocol === "https://");
	define('RUNROOT', WEBROOT);
	define('DEBUG', $settings['display_errors']);
	define('FMRSD_CONTEXT', getenv('FMRSD_CONTEXT'));

	// General PHP-side configuration
	error_reporting(-1);
	if (DEBUG > 0) {
		ini_set('display_errors', 1);
	}

	ini_set("log_errors", 1);
	ini_set("error_log", get_log_file('errors.log'));
	ini_set('session.gc_maxlifetime', $settings['session_cookie_lifetime']);
	ini_set('session.cookie_lifetime', $settings['session_cookie_lifetime']);

	// Set cryptography module
	try {
		Crypto::setup();
	} catch (Exception $e) {
		formr_log_exception($e);
		formr_error(503, 'Service Unavailable', 'Encryption service unavailable', null, false);
	}

	// Set default timzone, encoding and shutdown function.
	date_default_timezone_set($settings['timezone']);
	mb_internal_encoding('UTF-8');
	register_shutdown_function('shutdown_formr_org');
}

__formr_setup($settings);

