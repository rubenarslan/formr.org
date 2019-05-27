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
     * @param string $key Key identifying element in cache
     * @param mixed $data Cache data
     * @param int $ttl Number of seconds before cache expires
     * @return string Returns the key/index of the cache data that can be used in Cache::get()
     */
    public static function set($key, $data, $ttl = null) {
        if (!is_string($key)) {
            $key = self::makeKey($key);
        }
        
        if ($ttl === null) {
            $ttl = 600;
        }
        $ttl = (int)$ttl;

        self::$memory[$key] = array('ttl' => time() + $ttl, 'data' => $data);
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
            $cache = self::$memory[$key];
            if ($cache['ttl'] >= time()) {
                $default = $cache['data'];
            }
        }

        return $default;
    }

}
