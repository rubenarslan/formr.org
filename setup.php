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
$settings['version'] = 'v0.15.6';

// Define default assets
$settings['default_assets'] = array();
$settings['default_assets']['dev'] = array(
	// site theme
	'site' => array(
		'css' => array(
			'https://fonts.googleapis.com/css?family=Roboto:400,100,300,600,400italic,700',
			//'bower_components/material/material.css',
			'bower_components/bootstrap/dist/css/bootstrap.css',
			'bower_components/font-awesome/css/font-awesome.css',
			'bower_components/select2/select2.css',
			'common/js/highlight/styles/vs.css',
			'site/css/style.css',
		),
		'js' => array(
			'bower_components/jquery/jquery.js',
			'bower_components/bootstrap/dist/js/bootstrap.js',
			'bower_components/select2/select2.js',
			//'bower_components/material/material.js',
			'bower_components/webshim/js-webshim/dev/polyfiller.js',
			'common/js/webshim.js',
			'common/js/highlight/highlight.pack.js',
			'common/js/main.js',
			'site/js/main.js',
		)
	),
	// admin theme
	'admin' => array(
		'css' => array(
			'bower_components/bootstrap/dist/css/bootstrap.css',
			'bower_components/font-awesome/css/font-awesome.css',
			'admin/css/AdminLTE.css',
			'admin/css/skin-black.css',
			'admin/css/custom.css',
			'bower_components/select2/select2.css',
			'admin/css/AdminLTE-select2.css'
		),
		'js' => array(
			'bower_components/jquery/jquery.js',
			'bower_components/bootstrap/dist/js/bootstrap.js',
			'bower_components/ace-builds/src-noconflict/ace.js',
		)
	),
);
$settings['default_assets']['min'] = array(
	'site' => array(
		'css' => array(
			'build/css/formr.min.css'
		),
		'js' => array(
			'build/js/formr.min.js'
		)
	),
	'admin' => array(
		'css' => array(
			'build/css/formr-admin.min.css'
		),
		'js' => array(
			'build/js/formr-admin.min.js'
		)
	),
);

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

