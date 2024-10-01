<?php

/**
 * Formr.org configuration
 */
// Use sub domains for studies
$settings['use_study_subdomains'] = true;
$settings['admin_domain'] = "www.example.com";
# ideally not the same domain as the admin domain, for study subdomains use *.example.com
$settings['study_domain'] = "*.example.com";
$settings['protocol'] = "https://";

// Codes listed here can be entered in the sign-up box to turn
// users into admins automatically (upon email confirmation)
$settings['referrer_codes'] = array();

// Timezone
$settings['timezone'] = 'Europe/Berlin';

// Database Settings
$settings['database'] = array(
	'datasource' => 'Database/Mysql',
	'persistent' => false,
	'host' => 'localhost',
	'login' => 'user',
	'password' => 'password',
	'database' => 'database',
	'prefix' => '',
	'encoding' => 'utf8mb',
	'unix_socket' => '',
);

// OpenCPU instance settings
$settings['opencpu_instance'] = array(
	'local_url' => 'http://opencpu:8004',
	'public_url' => 'https://public.opencpu.org',
	'r_lib_path' => '/usr/local/lib/R/site-library'
);
// (used in admin/test_opencpu)
$settings['alternative_opencpu_instance'] = array(
	'local_url' => 'http://opencpu:8004',
	'public_url' => 'https://public.opencpu.org',
	'r_lib_path' => '/usr/local/lib/R/site-library'
);

// email SMTP and queueing configuration for emails sent by the formr app itself
// for example for email confirmation and password reset
$settings['email'] = array(
	'host' => 'smtp.example.com',
	'port' => 587,
	'tls' => true,
	'from' => 'email@example.com',
	'from_name' => 'Formr',
	'username' => 'email@example.com',
	'password' => 'password',
	// use db queue for emailing
	'use_queue' => true,
	// Number of seconds for which deamon loop should rest before getting next batch
	'queue_loop_interval' => 10,
	// Number of seconds to expire (i.e delete) a queue item if it failed to get delivered
	'queue_item_ttl' => 20*60,
	// Number of times to retry an item before deleting
	'queue_item_tries' => 4,
    // an array of account IDs to skip when processing mail queue
    'queue_skip_accounts' => array(),
	// SMTP options for phpmailer
	'smtp_options' => array(),
);

// email SMTP and queueing configuration for emails sent by formr admins in studies
// maybe not be the same as the formr app email
$settings['default_admin_email'] = array(
	'host' => NULL,
	'port' => NULL,
	'tls' => true,
	'from' => NULL,
	'from_name' => NULL,
	'username' => NULL,
	'password' => NULL
);

// should PHP and MySQL errors be displayed to the users when formr is not running locally? If 0, they are only logged
$settings['display_errors_when_live'] = 0;
$settings['display_errors'] = 0;
$settings['error_to_stderr'] = 0;

// What regular expression should the user codes match? Make sure your RegEx allows 64 character base64 like ^[A-Za-z0-9+-_~]{64}, as these are the codes formr generates. You can generate shorter/longer codes yourself though.
$settings['user_code_regular_expression'] = "/^[A-Za-z0-9+-_~]{64}$/";

// Should studies be required to have a non-empty privacy policy before they can go live/public?
$settings['require_privacy_policy'] = false;

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
$settings['allowed_file_endings_for_run_upload'] = array(
	'image/jpeg' => 'jpg', 
	'image/png' => 'png', 
	'image/gif' => 'gif', 
	'image/tiff' => 'tif',
	'video/mpeg' => 'mpg', 
	'video/quicktime' => 'mov', 
	'video/x-flv' => 'flv', 
	'video/x-f4v' => 'f4v', 
	'video/x-msvideo' => 'avi',
	'audio/mpeg' => 'mp3',
	'application/pdf' => 'pdf',
	'text/csv' => 'csv', 
	'text/javascript' => 'js', 
	'text/css' => 'css', 
	'text/tab-separated-values' => 'tsv', 
	'text/plain' => 'txt',
	'text/html' => 'html'
);

// Directory for exported runs
$settings['run_exports_dir'] = APPLICATION_ROOT . 'documentation/run_components';

// Directory for uploaded survey
$settings['survey_upload_dir'] = APPLICATION_ROOT . 'tmp/backups/surveys';

// application webroot
$settings['web_dir'] = APPLICATION_ROOT . 'webroot';

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

// Settings for the OSF API
$settings['osf'] = array(
	'client_id' => 'xxxxxxxxx',
	'client_secret' => 'xxxxxxx-secret',
	'redirect_url' => 'https://formr.org/api/osf',
	'scope' => 'osf.full_write',
	'token_uri' => 'https://accounts.osf.io/oauth2/token',
	'authorization_uri' => 'https://accounts.osf.io/oauth2/authorize',
	'api' => 'https://api.osf.io/v2',
	'site_url' => 'https://osf.io/myprojects/'
);

// Default time lengths for email subscriptions
$settings['email_subscriptions'] = array(
	'1' => 'Subscribe to E-mails',
	'+1 week' => 'Unsubscribe for one week',
	'+2 weeks' => 'Unsubscribe for two weeks',
	'+1 month' => 'Unsubscribe for one month',
	'0' => 'Never receive emails',
);

// Limit to number of pages to skip in a survey
$settings['allowed_empty_pages'] = 100;

// Run unit session settings for the sessions queue
$settings['unit_session'] = array(
	// String representing howmany minutes to set as default expiration for unit sessions
	// @see http://php.net/manual/en/function.strtotime.php
	'queue_expiration_extension' => '+10 minutes',
	// use db queue for processing unit sessions
	'use_queue' => true,
	// Log debug messages
	'debug' => false,
);

// Configure memory limits to be set when performing certain actions
$settings['memory_limit'] = array(
	// Run
	'run_get_data' => '1024M',
	'run_import_units' => '256M',
	// Spreadsheet
	'spr_object_array' => '1024M',
	'spr_sheets_array' => '1024M',
	// Survey
	'survey_get_results' => '2048M',
	'survey_upload_items' => '256M',
);

// Absolute path to file where encryption key for creating email accounts should be stored
// Defaults to {APPLICATION_ROOT}/formr-crypto.key
$settings['encryption_key_file'] = null;

// Use this config item to enable the apache X-Sendfile header. @see https://tn123.org/mod_xsendfile/
// Before using this, make sure xsendfile is installed and configured correctly with apache
$settings['use_xsendfile'] = true;

// Reserved run names which users are not allowed to use
$settings['reserved_run_names'] = array('api', 'test', 'delegate');

// Flag indicating whether formr is in maintenance mode
// If this is set to true, then users will see a maintenance message and cron jobs will not run
// Restart all server daemon whenever this flag is changed
$settings['in_maintenance'] = false;

// Configure IP addresses that can still access the application even in maintenance mode
// Example ['192.18.2.3', '192.18.3.4']
$settings['maintenance_ips'] = [];

// curl settings that override the default settings in the CURL class
// Use exact PHP constants as defined in http://php.net/manual/en/function.curl-setopt.php
$settings['curl'] = array(
	CURLOPT_SSL_VERIFYPEER => true,
	CURLOPT_SSL_VERIFYHOST => 2,
);

// Disable features temporarily by entering the Controller action names in this array
$settings['disabled_features'] = array(
    // RUN.controller_method_name
    // SURVEY.controller_method_name
);

// Brand
$settings['brand'] = '<span>f</span>orm<span>{`r}</span>';
$settings['brand_long'] = '<b>formr</b> survey framework';

// Settings for creating the context used in the 'copy' function when copying images from the opencpu server to formr
$settings['copy_context'] = array(
	'ssl' => array(
		"verify_peer" => false,
		"verify_peer_name" => false,
	)
);
