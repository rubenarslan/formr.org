<?php
// todo:
// differentiate calls into those that need auth and those that don't
// in the call function, check whether opencpu server was set up with auth
// 
class OpenCPU {

    protected $localUrl = null;
    protected $publicUrl = null;
    protected $libUri = '/ocpu/library';
    protected $last_message = null;
    protected $rLibPath = '/usr/local/lib/R/site-library';

    const STRING_DELIMITER = "\n\n==========formr=opencpu=string=delimiter==========\n\n";
    const TEMP_BASE_URL = "__formr_opencpu_session_url__";
    const STRING_DELIMITER_PARSED = "<p>==========formr=opencpu=string=delimiter==========</p>";

    /**
     * @var OpenCPU[]
     */
    protected static $instances = array();

    /**
     * Additional curl options to set when making curl request
     *
     * @var Array 
     */
    private $curl_opts = array(
        CURLINFO_HEADER_OUT => true,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HEADER => true,
        CURLOPT_ENCODING => ""
    );
    private $curl_info = array();

    /**
     * @var OpenCPU_Request
     */
    protected $request;

    /**
     * Get an instance of OpenCPU class
     *
     * @param string $instance Config item name that holds opencpu base URL
     * @return OpenCPU
     */
    public static function getInstance($instance = 'opencpu_instance') {
        if (!isset(self::$instances[$instance])) {
            self::$instances[$instance] = new self($instance);
        }
        return self::$instances[$instance];
    }

    protected function __construct($instance) {
        $config = (array) Config::get($instance);
        foreach ($config as $key => $value) {
            $property = lcfirst(preg_replace('/\s+/', '', ucwords(str_replace('_', ' ', $key))));
            if (property_exists($this, $property)) {
                $this->{$property} = rtrim($value,"/").'/';
            }
        }

        $this->curl_opts = $this->curl_opts + array_val($config, 'curl_opts', array());
    }

    public function getPublicUrl() {
        return $this->publicUrl;
    }

    public function getLocalUrl() {
        return $this->localUrl;
    }

    public function getRLibPath() {
        return $this->rLibPath;
    }

    public function getRTempPublicUrl() {
        return self::TEMP_BASE_URL;
    }

    public function getLibUri() {
        return $this->libUri;
    }

    public function getLastMessage() {
        return $this->last_message;
    }

    /**
     * @return OpenCPU_Request
     */
    public function getRequest() {
        return $this->request;
    }

    /**
     * Get Response headers of opencpu request
     *
     * @return null|array
     */
    public function getResponseHeaders() {
        if (isset($this->curl_info[CURL::RESPONSE_HEADERS])) {
            return $this->curl_info[CURL::RESPONSE_HEADERS];
        } elseif (isset($this->curl_info['raw_header'])) {
            return http_parse_headers($this->curl_info['raw_header']);
        }
        return null;
    }

    public function getRequestInfo($item = null) {
        if ($item && isset($this->curl_info[$item])) {
            return $this->curl_info[$item];
        } elseif ($item) {
            return null;
        }
        return $this->curl_info;
    }

    /**
     * Send HTTP request to opencpu
     *
     * @uses CURL
     * @param string $uri
     * @param array $params
     * @param tystringpe $method
     * @return \OpenCPU_Session
     * @throws OpenCPU_Exception
     */
    private function call($uri = '', $params = array(), $method = CURL::HTTP_METHOD_GET) {
        if ($uri && strstr($uri, $this->localUrl) === false) {
            $uri = "/" . ltrim($uri, "/");
            $url = $this->localUrl . $this->libUri . $uri;
        } else {
            $url = $uri;
        }

        // set global props
        $this->curl_info = array();
        $this->request = new OpenCPU_Request($url, $method, $params);

        $curl_opts = $this->curl_opts;
        // encode request
        if ($method === CURL::HTTP_METHOD_POST) {
            $params = array_map(array($this, 'cr2nl'), $params);
            $params = http_build_query($params);
            $curl_opts = $this->curl_opts + array(CURLOPT_HTTPHEADER => array(
                    'Content-Length: ' . strlen($params),
            ));
        }

        // Maybe something bad happen in CURL request just throw it with OpenCPU_Exception with message returned from CURL
        try {
            $results = CURL::HttpRequest($url, $params, $method, $curl_opts, $this->curl_info);
        } catch (Exception $e) {
            throw new OpenCPU_Exception($e->getMessage(), -1, $e);
        }

        if ($this->curl_info['http_code'] == 400) {
            $results = "R Error: $results";
            return new OpenCPU_Session(null, null, $results, $this);
        } elseif ($this->curl_info['http_code'] < 200 || $this->curl_info['http_code'] > 302) {
            if (!$results) {
                $results = "OpenCPU server '{$this->publicUrl}' could not be contacted";
            }
            throw new OpenCPU_Exception($results, $this->curl_info['http_code']);
        }

        $headers = $this->getResponseHeaders();
        if ($method === CURL::HTTP_METHOD_GET) {
            $headers['Location'] = $url;
            if (preg_match("@/(x0[a-z0-9-_~]+)/@", $url, $matches)):
                $headers['X-Ocpu-Session'] = $matches[1];
            endif;
        }
        if (!$headers || empty($headers['Location']) || empty($headers['X-Ocpu-Session'])) {
            $request = sprintf('[uri %s] %s', $uri, print_r($params, 1));
            throw new OpenCPU_Exception("Response headers not gotten from request $request");
        }

        return new OpenCPU_Session($headers['Location'], $headers['X-Ocpu-Session'], $results, $this);
    }

    /**
     * Send a POST request to OpenCPU
     *
     * @param string $uri A uri that is relative to openCPU's library entry point for example '/markdown/R/render'
     * @param array $params An array of parameters to pass
     * @throws OpenCPU_Exception
     * @return OpenCPU_Session
     */
    public function post($uri = '', $params = array()) {
        return $this->call($uri, $params, CURL::HTTP_METHOD_POST);
    }

    /**
     * Send a GET request to OpenCPU
     *
     * @param string $uri A uri that is relative to openCPU's library entry point for example '/markdown/R/render'
     * @param array $params An array of parameters to pass
     * @throws OpenCPU_Exception
     * @return OpenCPU_Session
     */
    public function get($uri = '', $params = array()) {
        return $this->call($uri, $params, CURL::HTTP_METHOD_GET);
    }

    /**
     * Execute a snippet of R code
     *
     * @param string $code
     * @return OpenCPU_Session
     */
    public function snippet($code) {
        $params = array('x' => '{ 
(function() {
	' . $code . '
})()}');
        return $this->post('/base/R/identity', $params);
    }

    private function cr2nl($string) {
        return str_replace("\r\n", "\n", $string);
    }

}

class OpenCPU_Session {

    /**
     * @var string
     */
    protected $raw_result;

    /**
     * @var string
     */
    protected $key;

    /**
     * @var string
     */
    protected $location;

    /**
     * @var OpenCPU
     */
    private $ocpu;

    /**
     * @var integer
     */
    private $object_length = null;

    public function __construct($location, $key, $raw_result, OpenCPU $ocpu = null) {
        $this->raw_result = $raw_result;
        $this->key = $key;
        $this->location = $location;
        $this->ocpu = $ocpu;
    }

    /**
     * Returns the list of returned paths as a string separated by newline char
     *
     * @return string
     */
    public function getRawResult() {
        return $this->raw_result;
    }

    /**
     * @return OpenCPU_Request
     */
    public function getRequest() {
        return $this->ocpu->getRequest();
    }

    public function isJSONResult() {
        return ($this->ocpu->getRequestInfo("content_type") === "application/json");
    }

    /**
     * Returns the list of returned paths as a string separated by newline char
     *
     * @param bool $as_array If TRUE, paths will be returned in an array
     * @return string|array
     */
    public function getResponse($as_array = false) {
        if ($as_array === true) {
            return explode("\n", $this->raw_result);
        }
        return $this->raw_result;
    }

    public function getKey() {
        return $this->key;
    }

    /**
     * Get an array of files present in current session
     *
     * @param string $match You can match only files with some slug in the path name
     * @param string $localUrl URL segment to prepend to paths
     * @return array
     */
    public function getFiles($match = '/files/', $baseUrl = null) {
        if (!$this->key) {
            return null;
        }

        if($baseUrl !== null) {
            $baseUrl = str_replace($this->caller()->getLocalUrl(), 
                        $this->caller()->getPublicUrl(), $baseUrl);
        }

        $files = array();
        $result = explode("\n", $this->raw_result);
        foreach ($result as $path) {
            if (!$path || strpos($path, $match) === false) {
                continue;
            }

            $id = basename($path);
            $files[$id] = $baseUrl ? $baseUrl . $path : $this->getResponsePath($path);
        }
        return $files;
    }

    public function getLocation() {
        return $this->location;
    }

    public function getObject($name = 'json', $params = array()) {
        if (!$this->key) {
            return null;
        }

        $url = $this->getLocation() . 'R/.val/' . $name;
        $info = array(); // just in case needed in the furture to get curl info
        $object = CURL::HttpRequest($url, $params, $method = CURL::HTTP_METHOD_GET, array(), $info);
        if ($name === 'json') {
            $object = $this->getJSONObject($object);
        }
        if (is_string($object)) {
            $object = str_replace($this->ocpu->getRLibPath(), $this->getPublicUrl() . $this->ocpu->getLibUri(), $object);
            return str_replace($this->ocpu->getRTempPublicUrl(), $this->getLocation() . 'files/', $object);
        }

        return $object;
    }

    public function getJSONObject($string = null, $as_assoc = true) {
        if (!$this->key) {
            return null;
        }

        if ($string === null) {
            $string = $this->raw_result;
        }
        $json = json_decode($string, $as_assoc);
        $this->object_length = is_null($json) ? 0 : count($json);
        // if decoded object is a non-empty array, get it's first element
        if (is_array($json) && array_key_exists(0, $json)) {
            if (is_string($json[0])) {
                $string = str_replace($this->ocpu->getRLibPath(), $this->getLocalUrl() . $this->ocpu->getLibUri(), $json[0]);
                return str_replace($this->ocpu->getRTempPublicUrl(), $this->getLocation() . 'files/', $string);
            }
            return $json[0];
        }

        return $json;
    }

    public function getObjectLength() {
        return $this->object_length;
    }

    public function getStdout() {
        if (!$this->key) {
            return null;
        }

        $url = $this->getLocation() . 'stdout';
        $info = array(); // just in case needed in the furture to get curl info
        return CURL::HttpRequest($url, null, $method = CURL::HTTP_METHOD_GET, array(), $info);
    }

    public function getConsole() {
        if (!$this->key) {
            return null;
        }

        $url = $this->getLocation() . 'console';
        $info = array(); // just in case needed in the furture to get curl info
        return CURL::HttpRequest($url, null, $method = CURL::HTTP_METHOD_GET, array(), $info);
    }

    public function getInfo() {
        if (!$this->key) {
            return null;
        }

        $url = $this->getLocation() . 'info';
        $info = array(); // just in case needed in the furture to get curl info
        return CURL::HttpRequest($url, null, $method = CURL::HTTP_METHOD_GET, array(), $info);
    }

    public function hasError() {
        return $this->ocpu->getRequestInfo('http_code') >= 400;
    }

    public function getError() {
        if (!$this->hasError()) {
            return null;
        }
        return $this->raw_result;
    }

    /**
     * @return OpenCPU
     */
    public function caller() {
        return $this->ocpu;
    }

    public function getResponseHeaders() {
        return $this->caller()->getResponseHeaders();
    }

    public function getPublicUrl() {
        return $this->caller()->getPublicUrl();
    }

    public function getLocalUrl() {
        return $this->caller()->getLocalUrl();
    }

    protected function getResponsePath($path) {
        return $this->caller()->getPublicUrl() . $path;
    }

}

class OpenCPU_Request {

    protected $url;
    protected $params;
    protected $method;

    public function __construct($url, $method, $params = null) {
        $this->url = $url;
        $this->method = $method;
        $this->params = $params;
    }

    public function getUrl() {
        return $this->url;
    }

    public function getMethod() {
        return $this->method;
    }

    public function getParams() {
        return $this->params;
    }

    public function __toString() {
        $request = array("METHOD: {$this->method}", "URL: {$this->url}", "PARAMS: " . $this->stringify($this->params));
        return implode("\n", $request);
    }

    protected function stringify($object) {
        if (is_string($object)) {
            return $object;
        }

        $string = "\n";
        if (is_array($object)) {
            foreach ($object as $key => $value) {
                $value = $this->stringify($value);
                $string .= "{$key} = {$value} \n";
            }
        } else {
            $string .= (string) $object;
        }

        return $string;
    }

}

class OpenCPU_Exception extends Exception {
    
}
