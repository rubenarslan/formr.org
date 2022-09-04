<?php

class Request {

    private $data = array();
    private static $globals = array();

    /**
     * @param array $data
     */
    public function __construct($data = null) {
        if ($data === null) {
            $data = $_REQUEST;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $this->__set($key, $value);
            }
        }
    }

    /**
     * @param string $name
     */
    public function __isset($name) {
        return isset($this->data[$name]);
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value) {
        $this->data[$name] = self::stripControlChars($value);
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get($name) {
        if ($this->__isset($name)) {
            return $this->data[$name];
        }

        return null;
    }

    /**
     * Get all parameters from $_REQUEST variable
     *
     * @return array
     */
    public function getParams() {
        return $this->data;
    }

    /**
     * Get parameter
     *
     * @param string $name
     * @return mixed
     */
    public function getParam($name, $default = null) {
        $param = $this->__get($name);
        if ($param === null) {
            $param = $default;
        }
        return $param;
    }

    /**
     * Recursively input clean control characters (low bits in ASCII table)
     *
     * @param array|mixed|string $value
     * @return array|mixed|string
     */
    public static function stripControlChars($value) {
        if (is_array($value)) {
            foreach ($value as $key => $val) {
                $value[$key] = self::stripControlChars($val);
            }
        } else {
            // strip control chars, backspace and delete (including \r)
            $value = preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $value);
        }

        return $value;
    }

    /**
     * Access a request parameter as int
     *
     * @param string $name Parameter name
     * @param mixed $default Default to return if parameter isn't set or is an array
     * @param bool $nonempty Return $default if parameter is set but empty()
     * @return int
     */
    public function int($name, $default = 0, $nonempty = false) {
        if(!isset($this->  data[$name]))
            return $default;
        if (is_array($this->data[$name]))
            return $default;
        if ($this->data[$name] === '')
            return $default;
        if ($nonempty && empty($this->data[$name]))
            return $default;

        return (int) $this->data[$name];
    }

    /**
     * Access a request parameter as string
     *
     * @param string $name Parameter name
     * @param mixed $default Default to return if parameter isn't set or is an array
     * @param bool $nonempty Return $default if parameter is set but empty()
     * @return string
     */
    public function str($name, $default = '', $nonempty = false) {
        if (!isset($this->data[$name]))
            return $default;
        if (is_array($this->data[$name]))
            return $default;
        if ($nonempty && empty($this->data[$name]))
            return $default;

        return (string) $this->data[$name];
    }

    /**
     * Access a request parameter as bool
     *
     * Note: $nonempty is here for interface consistency and makes not much sense for booleans
     *
     * @param string $name Parameter name
     * @param mixed $default Default to return if parameter isn't set
     * @param bool $nonempty Return $default if parameter is set but empty()
     * @return bool
     */
    public function bool($name, $default = false, $nonempty = false) {
        if(!isset($this->  data[$name]))
            return $default;
        if (is_array($this->data[$name]))
            return $default;
        if ($this->data[$name] === '')
            return $default;
        if ($nonempty && empty($this->data[$name]))
            return $default;

        return (bool) $this->data[$name];
    }

    /**
     * Access a request parameter as array
     *
     * @param string $name Parameter name
     * @param mixed $default Default to return if parameter isn't set
     * @param bool $nonempty Return $default if parameter is set but empty()
     * @return array
     */
    public function arr($name, $default = array(), $nonempty = false) {
        if (!isset($this->data[$name]))
            return $default;
        if (!is_array($this->data[$name]))
            return $default;
        if ($nonempty && empty($this->data[$name]))
            return $default;

        return (array) $this->data[$name];
    }

    /**
     * Access a request parameter as float
     *
     * @param string $name Parameter name
     * @param mixed $default Default to return if parameter isn't set or is an array
     * @param bool $nonempty Return $default if parameter is set but empty()
     * @return float
     */
    public function float($name, $default = 0, $nonempty = false) {
        if(!isset($this->  data[$name]))
            return $default;
        if (is_array($this->data[$name]))
            return $default;
        if ($this->data[$name] === '')
            return $default;
        if ($nonempty && empty($this->data[$name]))
            return $default;

        return (float) $this->data[$name];
    }

    public static function isHTTPPostRequest() {
		$method = array_val($_SERVER, 'REQUEST_METHOD');
        return strtolower($method) === 'post';
    }

    public static function isHTTPGetRequest() {
		$method = array_val($_SERVER, 'REQUEST_METHOD');
        return strtolower($method) === 'get';
    }

    public static function isAjaxRequest() {
        $env = env('HTTP_X_REQUESTED_WITH');
        if (!$env) {
            return false;
        }
        
        return strtolower($env) === 'xmlhttprequest';
    }

    public static function setGlobals($key, $value) {
        self::$globals[$key] = $value;
    }

    public static function getGlobals($key, $default = null) {
        return isset(self::$globals[$key]) ? self::$globals[$key] : $default;
    }

    public static function stripslashes($value) {
        // skip objects (object.toString() results in wrong output)
        if (!is_object($value) && !is_array($value)) {
            $value = stripslashes($value);
        // object is array
        } elseif (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::stripslashes($v);
            }
        }

        return $value;
    }

    public function redirect($uri = '', $params = array()) {
        redirect_to($uri, $params);
    }

}
