<?php
require_once '../define_root.php';
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

$site = Site::getInstance();
$fdb = DB::getInstance();

$site->start_session();
if (isset($_SESSION['site']) AND is_object($_SESSION['site'])): // first we see what's in that session
	$site = $_SESSION['site']; // if we already have a site object, possibly with alerts and referrers, we use that instead
    $site->updateRequestObject();
endif;

if (isset($_SESSION['user'])):
    $sess_user = unserialize($_SESSION['user']);

    // this segment basically checks whether the user-specific expiry time was met
    if (isset($sess_user->id)): // logged in user
        if (!$site->expire_session(Config::get('expire_registered_session'))): // if not expired: recreate user object
            $user = new User($fdb, $sess_user->id, $sess_user->user_code);

            if ($user->isAdmin()):
                if ($site->expire_session(Config::get('expire_admin_session'))): // admins have a different expiry, can only be lower
                    unset($user);
                endif;
            endif;
        endif;
    elseif (isset($sess_user->user_code)):
        if (!$site->expire_session(Config::get('expire_unregistered_session'))):
            $user = new User($fdb, null, $sess_user->user_code);
        endif;
    endif;
endif;

$_SESSION['last_activity'] = time(); // update last activity time stamp

$site->refresh();

if (!isset($user)):
    $user = new User($fdb, null, null);
endif;

try {
	$router = Router::getInstance()->route();
	$router->execute();
} catch (Exception $e) {
	log_exception($e);
	if(DEBUG) {
		alert("<pre style='background-color:transparent;border:0'>". 'Exception: ' . $e->getMessage() . "\n". $e->getTraceAsString()."</pre>", "alert-danger");
	} else {
		$date = date('Y-m-d H:i:s');
		alert("<small>".$date. " There was a fatal error. Please let the administrators and know what you were trying to do and provide this message's date & time.</small>", "alert-danger");
	}
	bad_request();
}

exit(0);
