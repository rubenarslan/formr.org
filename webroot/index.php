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

try {
	// Create DB connection
	$fdb = DB::getInstance();
} catch(Exception $e) {
	formr_log($e->getMessage(), 'Database Connection Error');
	bad_request_header();
	_die('no data store found');
}

// Set current user
$expiry = Config::get('expire_unregistered_session');
if (($usr = Session::get('user'))) {
    $user = unserialize($usr);
	// This segment basically checks whether the user-specific expiry time was met
	// If user session is expired, user is logged out and redirected
	if (!empty($user->id)) { // logged in user
		// refresh user object if not expired
		$expiry = Config::get('expire_registered_session');
		$user = new User($fdb, $user->id, $user->user_code);
		// admins have a different expiry, can only be lower
		if ($user->isAdmin()) {
			$expiry = Config::get('expire_admin_session');
		}
	} elseif (!empty($user->user_code)) { // visitor
		// refresh user object
		$user = new User($fdb, null, $user->user_code);
	}
}

if($site->expire_session($expiry)) {
	unset($user, $expiry);
}
// we were unable to get 'proper' user from session
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
