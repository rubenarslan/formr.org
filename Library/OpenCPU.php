<?php

class OpenCPU {

	protected $baseUrl = 'https://public.opencpu.org';
	protected $libUri = '/ocpu/library';
	protected $last_message = null;
	protected $rLibPath = '/usr/local/lib/R/site-library';

	const STRING_DELIMITER = "\n\n========== formr_opencpu_string_delimiter ==========\n\n";
	const STRING_DELIMITER_PARSED = "<p>========== formr_opencpu_string_delimiter ==========</p>";

	/**
	 * @var OpenCPU[]
	 */
	protected static $instances = array();
	/**
	 * @var OpenCPU_Session[]
	 */
	protected $cache = array();

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
				$this->{$property} = $value;
			}
		}
	}

	/**
	 * @param string $baseUrl
	 */
	public function setBaseUrl($baseUrl) {
		if ($baseUrl) {
			$baseUrl = rtrim($baseUrl, "/");
			$this->baseUrl = $baseUrl;
		}
	}

	public function getBaseUrl() {
		return $this->baseUrl;
	}

	public function getRLibPath() {
		return $this->rLibPath;
	}

	public function setLibUrl($libUri) {
		$libUri = trim($libUri, "/");
		$this->libUri = '/' . $libUri;
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
		} elseif(isset($this->curl_info['raw_header'])) {
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

	private function call($uri = '', $params = array(), $method = CURL::HTTP_METHOD_GET) {
		$cachekey = md5(serialize(func_get_args()));
		if (isset($this->cache[$cachekey])) {
			return $this->cache[$cachekey];
		}


		if ($uri && strstr($uri, $this->baseUrl) === false) {
			$uri = "/" . ltrim($uri, "/");
			$url = $this->baseUrl . $this->libUri . $uri;
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
		} elseif ($this->curl_info['http_code'] < 200 || $this->curl_info['http_code'] > 300) {
			if (!$results) {
				$results = "OpenCPU server '{$this->baseUrl}' could not be contacted";
			}
			throw new OpenCPU_Exception($results, $this->curl_info['http_code']);
		}

		$headers = $this->getResponseHeaders();
		if ($method === CURL::HTTP_METHOD_GET) {
			$headers['Location'] = $url;
			if(preg_match("@/(x0[a-z0-9-_~]+)/@",$url, $matches)):
				$headers['X-Ocpu-Session'] = $matches[1];
			endif;
		}
		if (!$headers || empty($headers['Location']) || empty($headers['X-Ocpu-Session'])) {
			$request = sprintf('[uri %s] %s', $uri, print_r($params, 1));
			throw new OpenCPU_Exception("Response headers not gotten from request $request");
		}

		$this->cache[$cachekey] = new OpenCPU_Session($headers['Location'], $headers['X-Ocpu-Session'], $results, $this);
		return $this->cache[$cachekey];
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
	 * @return array
	 */
	public function getFiles($match = '/files/') {
		if (!$this->key) {
			return null;
		}

		$files = array();
		$result = explode("\n", $this->raw_result);
		foreach ($result as $path) {
			if (!$path || strpos($path, $match) === false) {
				continue;
			}

			$id = basename($path);
			$files[$id] = $this->getResponsePath($path);
		}
		return $files;
	}

	/**
	 * Get absolute URLs of all resources in the response
	 *
	 * @return array
	 */
	public function getResponsePaths() {
		if (!$this->key) {
			return null;
		}

		$result = explode("\n", $this->raw_result);
		$files = array();
		foreach ($result as $id => $path) {
			$files[$id] = $this->getResponsePath($path);
		}
		return $files;
	}
	public function getResponsePathsAsLinks() {
		if (!$this->key) {
			return null;
		}

		$result = explode("\n", $this->raw_result);
		$files = array();
		foreach ($result as $path) {
			$files[$path] = $this->getResponsePath($path);
		}
		return $files;
	}

	public function getLocation() {
		return $this->location;
	}

	public function getFileURL($path) {
		return $this->getResponsePath('/files/' . $path);
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
		$this->object_length = count($json);
		// if decoded object is a non-empty array, get it's first element
		if (is_array($json) && array_key_exists(0, $json)) {
			if(is_string($json[0])) {
				return str_replace($this->ocpu->getRLibPath(), $this->getBaseUrl() . $this->ocpu->getLibUri(), $json[0]);
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
		return $this->ocpu->getRequestInfo('http_code') >=  400;
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

	public function getBaseUrl() {
		return $this->caller()->getBaseUrl();
	}

	protected function getResponsePath($path) {
		return $this->caller()->getBaseUrl() . $path;
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

class OpenCPU_Exception extends Exception {}
