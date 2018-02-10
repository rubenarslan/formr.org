<?php
require_once dirname(__FILE__) . '/../setup.php';

// Start formr session
Session::configure();
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
	_die('no data store found');
}

// Set site's session user or create one if not available
$user = $site->getSessionUser();
if (empty($user->user_code)) {
	$user = new User($fdb, null, null);
}

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
	if(!DEBUG) {
		$date = date('Y-m-d H:i:s');
		alert("<small>".$date. " There was a fatal error. Please let the administrators and know what you were trying to do and provide this message's date & time.</small>", "alert-danger");
	}
	bad_request();
}

exit(0);
