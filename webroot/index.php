<?php
require_once dirname(__FILE__) . '/../setup.php';

// Start formr session
Session::configure();
Session::start();

// Check if maintenance is going on
if (Config::get('in_maintenance')) {
	formr_error(404, 'Not Found', 'This website is currently undergoing maintenance. Please try again later.', 'Maintenance Mode', false);
}

// Define SITE object
/** @var Site $site */
if (($site = Session::get('site')) && is_object($site)) {
	$site->updateRequestObject();
} else {
	$site = Site::getInstance();
	Session::set('site', $site);
}

// Create DB connection
try {
	$fdb = DB::getInstance();
} catch(Exception $e) {
	formr_log($e->getMessage(), 'Database Connection Error');
	formr_error(503, 'Service Unavailable', 'Data store unavailable', null, false);
}

// Set site's session user or create one if not available
$user = $site->getSessionUser();

// update session
Session::set('last_activity', time());
Session::set('user', serialize($user));

$site->refresh();

// Route request
try {
	$router = Router::getInstance()->route();
	$router->execute();
} catch (Exception $e) {
	formr_log_exception($e);
	$msg = date('Y-m-d H:i:s') . "\n {$e->getMessage()}. \nPlease let the administrators and know what you were trying to do and provide this message's date & time.";
	formr_error(500, 'Internal Server Error', nl2br($msg), 'Fatal Error', false);
}

exit(0);
