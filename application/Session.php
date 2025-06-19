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
    protected static $domain = ''; // strictest domain matching is leaving domain empty
    protected static $secure = true;
    protected static $httponly = true;
    protected static $samesite = 'Lax';
    
    const REQUEST_TOKENS_COOKIE = 'formr_token';
    const REQUEST_TOKENS = '_formr_request_tokens';
    const REQUEST_USER_CODE = '_formr_code';
    const REQUEST_NAME = '_formr_cookie';
    const ADMIN_COOKIE = 'formr_user';

    /**
     * Configures the session with the given configuration.
     * 
     * @param array $config The configuration array
     */
    public static function configure($config = array()) {
        self::$lifetime = Config::get('session_cookie_lifetime');
        self::$secure = SSL;

        // Set path based on the detected context
        self::$path = defined('SESSION_PATH') ? SESSION_PATH : '/';
        
        // empty domain for strictest domain matching
        self::$domain = '';
    }


    /**
     * Start a PHP session
     */
    public static function start() {
        session_name(self::$name);
        
        // Log session parameters for debugging if needed
        if (DEBUG) {
            error_log("Session starting with path: " . self::$path . ", domain: " . self::$domain);
        }

        $lifetime = self::$lifetime;
        // until the cookie modal is accepted, don't allow lifetime to go beyond session
        if (!gave_functional_cookie_consent()) {
            $lifetime = 0;
        }
        
        session_set_cookie_params([
            "lifetime" => $lifetime, 
            "path" => self::$path, 
            "domain" => self::$domain, 
            "secure" => self::$secure, 
            "httponly" => self::$httponly,
            "samesite" => self::$samesite]);
        session_start();
    }

    public static function setSessionLifetime($lifetime) {
        if($lifetime === null) {
            $lifetime = self::$lifetime;
        }
        // To immediately affect the cookie sent with the current response,
        // we explicitly call setcookie().

        // until the cookie modal is accepted, don't allow lifetime to go beyond session
        if (!gave_functional_cookie_consent()) {
            $lifetime = 0;
        }

        if (session_status() == PHP_SESSION_ACTIVE) {
            $session_id = session_id();
            if ($session_id) { // Ensure there's a session ID to send
                $expires_timestamp = ($lifetime > 0) ? time() + $lifetime : 0;

                return setcookie(
                    session_name(),       // Use the current session name
                    $session_id,          // The current session ID is the value
                    [
                        'expires' => $expires_timestamp, // Absolute timestamp or 0
                        "path" => self::$path,
                        "domain" => self::$domain,
                        "secure" => self::$secure,
                        "httponly" => self::$httponly,
                        "samesite" => self::$samesite
                    ]
                );
            }
        }
        return false; // Indicate failure if session not active or no ID
    }

    /**
     * Destroys the session and deletes the admin cookie if specified.
     * 
     * @param bool $with_admin Whether to delete the admin cookie
     */
    public static function destroy($with_admin = true) {
        if ($with_admin === true) {
            self::deleteAdminCookie();
        }
        setcookie(session_name(), '', time() - 3600, '/');
        session_unset();
        session_destroy();
        session_write_close();
    }

    /**
     * Checks if the session is closed.
     * 
     * @return bool True if the session is closed, false otherwise
     */
    public static function over() {
        static $closed;
        if ($closed) {
            return false;
        }
        session_write_close();
        $closed = true;
        return true;
    }

    /**
     * Checks if the session is expired.
     * 
     * @param int $expiry The expiration time of the session
     * @return bool True if the session is expired, false otherwise
     */
    public static function isExpired($expiry) {
        return isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $expiry);
    }

    /**
     * Sets a session variable.
     * 
     * @param string $key The key of the session variable
     * @param mixed $value The value of the session variable
     */
    public static function set($key, $value) {
        $_SESSION[$key] = $value;
    }

    /**
     * Gets a session variable.
     * 
     * @param string $key The key of the session variable
     * @param mixed $default The default value to return if the key is not set
     * @return mixed The value of the session variable or the default value
     */
    public static function get($key, $default = null) {
        return isset($_SESSION[$key]) ? $_SESSION[$key] : $default;
    }

    /**
     * Deletes a session variable.
     * 
     * @param string $key The key of the session variable to delete
     */
    public static function delete($key) {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Refreshes the session with global variables.
     */
    public static function globalRefresh() {
        global $user, $site;
        self::set('user', serialize($user));
        self::set('site', $site);
    }

    /**
     * Generates a new request token, stores it in the session, and sets a cookie.
     * This token is used for validating form submissions to prevent CSRF attacks.
     * 
     * @return string The generated token
     */
    public static function getRequestToken() {
        if (formr_in_console()) {
            return null;
        }

        // Generate a random token
        $token = sha1(mt_rand() . uniqid('', true) . time());
        
        if (!$tokens = self::get(self::REQUEST_TOKENS)) {
            $tokens = array($token => 1);
        } else {
            // Limit number of tokens to prevent session bloat (keep max 5 tokens)
            if (count($tokens) > 5) {
                // Keep only the 4 most recent tokens
                $tokens = array_slice($tokens, -4, 4, true);
            }
            $tokens[$token] = 1;
        }

        // Set the token cookie
        setcookie(self::REQUEST_TOKENS_COOKIE, $token, 
            ['expires' => 0, 
            'path' => self::$path, 
            'domain' => self::$domain, 
            'secure' => self::$secure,
            'httponly' => self::$httponly,
            'samesite' => self::$samesite]);
        
        // Store tokens in session
        self::set(self::REQUEST_TOKENS, $tokens);
        
        // Debug logging
        if (DEBUG) {
            error_log("Generated new request token: " . $token);
            error_log("Current tokens in session: " . print_r($tokens, true));
        }
        
        return $token;
    }

    /**
     * Validates a request token from the request against stored tokens in the session.
     * Modified to handle multiple valid tokens to address race conditions where
     * the cookie token gets updated by a concurrent request.
     *
     * @param Request $request The request object containing the token
     * @return bool True if token is valid, false otherwise
     */
    public static function canValidateRequestToken(Request $request) {
        // Get token from request parameters
        $token = $request->getParam(self::REQUEST_TOKENS);
        
        // Get stored tokens from session
        $tokens = self::get(self::REQUEST_TOKENS, array());
        
        // Get cookie token
        $cookieToken = array_val($_COOKIE, self::REQUEST_TOKENS_COOKIE);
        
        // Debug logging for token validation issues
        if (DEBUG) {
            error_log("Token validation attempt:");
            error_log("- Request token: " . ($token ?: 'not set'));
            error_log("- Cookie token: " . ($cookieToken ?: 'not set'));
            error_log("- Session tokens: " . print_r($tokens, true));
            
            // Log more details about the request to help diagnose issues
            error_log("- Request method: " . $_SERVER['REQUEST_METHOD']);
            error_log("- Request URI: " . $_SERVER['REQUEST_URI']);
            error_log("- Request referrer: " . (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'not set'));
        }
        
        // Enhanced validation that addresses the race condition:
        // Accept the request token if it exists in the session, regardless of whether
        // it matches the current cookie token
        if (!empty($tokens[$token])) {
            if (DEBUG) {
                error_log("Token validation successful: Valid session token found");
            }
            
            // A valid request token is invalidated after use (one-time use)
            unset($tokens[$token]);
            
            // Only clear the cookie if the request token matches the cookie token
            if ($cookieToken == $token) {
                setcookie(self::REQUEST_TOKENS_COOKIE, '', 
                    ['expires' => -3600, 
                    'path' => self::$path, 
                    'domain' => self::$domain, 
                    'secure' => self::$secure,
                    'httponly' => self::$httponly,
                    'samesite' => self::$samesite]);
            }
            
            self::set(self::REQUEST_TOKENS, $tokens);
            return true;
        } else {
            if (DEBUG) {
                error_log("Token validation failed: Token not found in session tokens");
            }
        }
        
        return false;
    }
    
    /**
     * Sets a cookie with the given name, value, and parameters.
     * 
     * @param string $name The name of the cookie
     * @param string $value The value of the cookie
     * @param int $lifetime The life time of the cookie
     * @param string $path The path on the server in which the cookie will be available on
     * @param string $domain The domain that the cookie is available to
     * @param string $samesite The same site attribute of the cookie
     * @return bool True if the cookie was set successfully, false otherwise
     */
    public static function setCookie($name, $value, $lifetime = 0,  $path = "/", $domain = '', $samesite = 'Lax') {
        return setcookie($name, $value, 
                ['expires' => $lifetime === 0 ? 0 : time() + $lifetime, 
                'path' => $path, 
                'domain' => $domain, 
                'secure' => self::$secure,
                'httponly' => self::$httponly,
                'samesite' => $samesite]);
    }
    
    /**
     * Deletes a cookie with the given name.
     * 
     * @param string $name The name of the cookie to delete
     * @return bool True if the cookie was deleted successfully, false otherwise
     */
    public static function deleteCookie($name) {
        return setcookie($name, '',
            ['expires' => time() - 3600, 
            'path' => self::$path, 
            'domain' => self::$domain, 
            'secure' => self::$secure,
            'httponly' => self::$httponly,
            'samesite' => self::$samesite]);
    }
  
    /**
     * Sets an admin cookie with the given user object.
     * 
     * @param User $admin The user object to set in the cookie
     */
    public static function setAdminCookie(User $admin) {
        $data = [$admin->id, $admin->user_code, time()];
        
        // Admin cookies should always be set with admin path
        $admin_path = '/admin/';

        $lifetime = Config::get('expire_admin_session');
        // until the cookie modal is accepted, don't allow lifetime to go beyond session
        if (!gave_functional_cookie_consent()) {
            $lifetime = 0;
        }
        
        $cookie = self::setCookie(self::ADMIN_COOKIE, 
            Crypto::encrypt($data, '-'), 
            $lifetime,
            $admin_path, self::$domain, 'Strict');
        if (!$cookie) {
            formr_error(505, 'Invalid Token', 'Unable to set admin token');
        }
        
        Session::set('admin', $data);
    }
    
    public static function getAdminCookie() {
        $cookie = array_val($_COOKIE, self::ADMIN_COOKIE);
        if ($cookie) {
            $cookie_data = explode('-', Crypto::decrypt($cookie));
            $session_data = Session::get('admin', []);
            if ($cookie_data && $session_data && $cookie_data[0] == $session_data[0]) {
                return $session_data;
            }
        }
    }

    public static function deleteAdminCookie() {
        self::deleteCookie(self::ADMIN_COOKIE);
    }
    
    

}
