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

// Overwrite application settings with DEV_ENV if defined
if (getenv('DEV_ENV')):
	$devenv = getenv('DEV_ENV');
else:
	$devenv = "default";
endif;
if(preg_match("/[a-zA-Z0-9]+/", $devenv)):
    $devsettings = INCLUDE_ROOT . "config/env/{$devenv}.php";
    if (is_file($devsettings)):
        require_once $devsettings;
	else:
		require_once "config/env/default.php";
	endif;
endif;

// Load application autoloader
$autoloader = require_once INCLUDE_ROOT . "Library/Autoloader.php";
// Include helper functions
require_once INCLUDE_ROOT . "Library/Functions.php";
// Initialize Config
Config::initialize($settings);

// Define glboal constants
function define_webroot($settings = array()) {
	if(defined('WEBROOT')) return;

	$protocol = (!isset($_SERVER['HTTPS']) OR $_SERVER['HTTPS'] == '') ? 'http://' : 'https://';
    $doc_root = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'].'/' : '/';
	$online = true;
	$testing = false;

   // Maybe dev env contains $settings['define_root'] so use these
    if (isset($settings['define_root'])) {
        extract($settings['define_root']);
    }
	
	define('WEBROOT', $protocol . $doc_root);
	define('ONLINE', $online);
	define('TESTING', $testing);
	define('SSL', $protocol === "https://");
    define('RUNROOT', WEBROOT);
	define('DEBUG', ONLINE ? Config::get('display_errors_when_live') : 1);
}
define_webroot($settings);
