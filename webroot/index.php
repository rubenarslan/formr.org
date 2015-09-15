<?php
require_once '../define_root.php';

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
$fdb = DB::getInstance();

// Set current user
if (($usr = Session::get('user'))) {
    $user = unserialize($usr);
	// This segment basically checks whether the user-specific expiry time was met
	// If user session is expired, user is logged out and redirected
	if (!empty($user->id) && !$site->expire_session(Config::get('expire_registered_session'))) { // logged in user
		// refresh user object if not expired
		$user = new User($fdb, $user->id, $user->user_code);
		// admins have a different expiry, can only be lower
		if ($user->isAdmin() && $site->expire_session(Config::get('expire_admin_session'))) {
			unset($user);
		}
	} elseif (!empty($user->user_code) && !$site->expire_session(Config::get('expire_unregistered_session'))) { // visitor
		// refresh user object
		$user = new User($fdb, null, $user->user_code);
	}
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
