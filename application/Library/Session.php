<?php

/** 
 * Simple PHP session class
 * Manipulates the $_SESSION global variable
 *
 */

class Session {

	protected static $name = 'formr_session';

	protected static $lifetime;

	protected static $path = '/';

	protected static $domain = null;

	protected static $secure = false;

	protected static $httponly = true;
	
	const REQUEST_TOKENS = '_formr_request_tokens';
	const REQUEST_USER_CODE = '_formr_code';
	const REQUEST_NAME = '_formr_cookie';

	public static function configure($config = array()) {
		self::$lifetime = Config::get('session_cookie_lifetime');
		self::$secure = SSL;
		foreach ($config as $key => $value) {
			self::$key = $value;
		}
	}

	/**
	 * Start a PHP session
	 */
	public static function start() {
		session_name(self::$name);
        session_set_cookie_params(self::$lifetime, self::$path, self::$domain, self::$secure, self::$httponly);
        session_start();
	}

	public static function destroy() {
		setcookie(session_name(), '', time() - 3600, '/');
		session_unset();
        session_destroy();
		session_write_close();
	}

	public static function over() {
		static $closed;
		if ($closed) {
			return false;
		}
		session_write_close();
		$closed = true;
		return true;
	}

	public static function isExpired($expiry) {
		return isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $expiry);
	}

	public static function set($key, $value) {
		$_SESSION[$key] = $value;
	}

	public static function get($key, $default = null) {
		return isset($_SESSION[$key]) ? $_SESSION[$key] : $default;
	}

	public static function delete($key) {
		if (isset($_SESSION[$key])) {
			unset($_SESSION[$key]);
		}
	}

	public static function  globalRefresh() {
		global $user, $site;
		self::set('user', serialize($user));
		self::set('site', $site);
	}

	public static function getRequestToken() {
		$token = sha1(mt_rand());
		if (!$tokens = self::get(self::REQUEST_TOKENS)) {
			$tokens = array($token => 1);
		} else {
			$tokens[$token] = 1;
		}
		self::set(self::REQUEST_TOKENS, $tokens);
		return $token;
	}

	public static function canValidateRequestToken(Request $request) {
		$token = $request->getParam(self::REQUEST_TOKENS);
		$tokens = self::get(self::REQUEST_TOKENS, array());
		if (!empty($tokens[$token])) {
			// a valid request token dies after it's validity is retrived :P
			unset($tokens[$token]);
			self::set(self::REQUEST_TOKENS, $tokens);
			return true;
		}
		return false;	
	}
}


