<?php
/**
 * Formr.org configuration
 */

$settings['version'] = '0.8.0';

// Database Settings
$settings['database'] = array(
	'datasource' => 'Database/Mysql',
	'persistent' => false,
	'host' => 'localhost',
	'login' => 'user',
	'password' => 'password',
	'database' => 'database',
	'prefix' => '',
	'encoding' => 'utf8',
	'unix_socket' => '/Applications/MAMP/tmp/mysql/mysql.sock',
);

// OpenCPU instance settings
$settings['opencpu_instance'] = array(
	'base_url' => 'https://public.opencpu.org',
	'r_lib_path' => '/usr/local/lib/R/site-library'
);
// (used in admin/test_opencpu)
$settings['alternative_opencpu_instance'] = array(
	'base_url' => 'https://public.opencpu.org',
	'r_lib_path' => '/usr/local/lib/R/site-library'
);

// email SMTP configuration
$settings['email'] = array(
	'host' => 'smtp.example.com',
	'port' => 587,
	'tls' => true,
	'from' => 'email@example.com',
	'from_name' => 'Formr',
	'username' => 'email@example.com',
	'password' => 'password',
);

// should PHP and MySQL errors be displayed to the users when formr is not running locally? If 0, they are only logged
$settings['display_errors_when_live'] = 0;
$settings['display_errors'] = 0;

// Timezone
$settings['timezone'] = 'Europe/Berlin';

// Session expiration related settings
// (for unregistered users. in seconds (defaults to a year))
$settings['expire_unregistered_session'] = 365 * 24 * 60 * 60;
// (for registered users. in seconds (defaults to a week))
$settings['expire_registered_session'] = 7 * 24 * 30 * 60;
// (for admins. in seconds (defaults to a week). has to be lower than the expiry for registered users)
$settings['expire_admin_session'] = 7 * 24 * 30 * 60;
// upper limit for all values above (defaults to their max)
$settings['session_cookie_lifetime'] = max($settings['expire_unregistered_session'], $settings['expire_registered_session'], $settings['expire_admin_session']);

// Maximum size allowed for uploaded files in MB
$settings['admin_maximum_size_of_uploaded_files'] = 50;

// Directory for exported runs
$settings['run_exports_dir'] = INCLUDE_ROOT . 'documentation/run_components';

// Directory for uploaded survey
$settings['survey_upload_dir'] = INCLUDE_ROOT . 'tmp/backups/surveys';

// application webroot
$settings['web_dir'] = INCLUDE_ROOT . 'webroot';

// Setup settings for application that can overwrite defaults in /define_root.php
$settings['define_root'] = array(
    //'protocol' => 'http://',
    //'doc_root' => 'localhost/formr.org/',
    //'server_root' => INCLUDE_ROOT . '/',
    //'online' => false,
    //'testing' => true
);

$settings['referrer_codes'] = array();

// Cron settings
$settings['cron'] = array(
	// maximum time to live for a 'cron session' in minutes
	'ttl_cron' => 15,
	// maximum time to live for log file in minutes
	'ttl_lockfile' => 30,
	// Should cron be intercepted if session time is exceeded?
	'intercept_if_expired' => false,
);

// Settings for social share buttons
$settings['social_share'] = array(
	'facebook' => array(
		'url' => 'https://www.facebook.com/sharer.php?u=%{url}&t=%{title}',
		'target' => '_blank',
		'width' => 300,
		'height' => 400,
	),
	'twitter' => array(
		'url' => 'http://twitter.com/share?url=%{url}&text=%{title}',
		'target' => '_blank',
		'width' => 300,
		'height' => 400,
	),
);
