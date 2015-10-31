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
		session_unset();
        session_destroy();
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

	public static function get($key) {
		return isset($_SESSION[$key]) ? $_SESSION[$key] : null;
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
}


