<?php

/**
 * Cache database objects and others.
 * With allocation for implementing memcache in furture
 */
class Cache {

    protected static $memory = array();
    protected static $type = 'memory';
    protected static $allowed_types = array('memory');

    public static function init($type = 'memory', $params = array()) {
        if (!in_array($type, self::$allowed_types)) {
            throw new InvalidArgumentException("Unsupported cache type '{$type}'");
        }

        self::$type = $type;
        $initmethod = 'init' . ucwords(self::$type) . 'Cache';
        if (!method_exists('Cache', $initmethod)) {
            throw new RuntimeException("Could not initialize cache type {$type}");
        }

        call_user_func(array('Cache', $initmethod), $params);
    }

    protected static function initMemoryCache() {
        self::$memory = array();
    }

    /**
     * Return a valid key index for cache
     *
     * @param mixed $mixed
     * @return string
     */
    public static function makeKey($mixed) {
        $mixed = func_get_args();
        return md5(serialize($mixed));
    }

    /**
     * Set cache data
     *
     * @param string $key
     * @param mixed $data
     * @return string Returns the key/index of the cache data that can be used in Cache::get()
     */
    public static function set($key, $data) {
        if (!is_string($key)) {
            $key = self::makeKey($key);
        }

        self::$memory[$key] = $data;
        return $key;
    }

    /**
     * Get cahced data
     *
     * @param string $key
     * @param mixed $default Some default value to be returned if cached item is not found
     * @return mixed
     */
    public static function get($key = null, $default = null) {
        if ($key === null) {
            return self::$memory;
        }

        if (isset(self::$memory[$key])) {
            $default = self::$memory[$key];
        }

        return $default;
    }

}
