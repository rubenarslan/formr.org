<?php

define('FORMRORG_ROOT', dirname(__FILE__));

// Load composer Autoloader
require_once FORMRORG_ROOT . "/vendor/autoload.php";

// Load application settings
/* @var $settings array */
require_once FORMRORG_ROOT . "/config_default/settings.php";
require_once FORMRORG_ROOT . "/config/settings.php";

// Overwrite application settings with dev settings if defined
if (($devenv = getenv('DEV_ENV'))) {
    $devsettings = FORMRORG_ROOT . "/config/env/{$devenv}.php";
    if (is_file($devsettings)) {
        require_once $devsettings;
    }
}

// Include helper functions
require_once FORMRORG_ROOT . "/Model/helper_functions.php";

// Define glboal constants
function define_webroot($settings = array()) {
	if(defined('WEBROOT')) return;

	$protocol = (!isset($_SERVER['HTTPS']) OR $_SERVER['HTTPS'] == '') ? 'http://' : 'https://';
    $doc_root = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'].'/' : '/';
	$server_root = __DIR__ . '/';
	$online = true;
	$testing = false;

    // Maybe dev env contains $settings['define_root'] so use these
    if (isset($settings['define_root'])) {
        extract($settings['define_root']);
    }

	define('WEBROOT', $protocol . $doc_root);
	define('INCLUDE_ROOT', $server_root);
	define('ONLINE', $online);
	define('TESTING', $testing);
	define('SSL', $protocol === "https://");
    define('RUNROOT', WEBROOT . 'study/');
}
define_webroot($settings);

// Load application autoloader
$autoloader = require_once FORMRORG_ROOT . "/Library/Autoloader.php";

// Initialize Config
Config::initialize($settings);


