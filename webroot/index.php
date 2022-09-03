<?php
require_once dirname(__FILE__) . '/../setup.php';

// Start formr session
Session::configure(Config::get('php_session', []));
Session::start();

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
    $code = strtoupper(AnimalName::haikunate());
    formr_log_exception($e, $code);
    $msg = DEBUG ? $code. ' ' . $e->getMessage() : 'An Unexpected Error Occured. CODE ' . $code;
	$msg = date('Y-m-d H:i:s') . "\n {$msg}. \nPlease let the administrators and know what you were trying to do and provide this message's code, date & time.";
	formr_error(500, 'Internal Server Error', nl2br($msg), 'Fatal Error', false);
}

exit(0);
