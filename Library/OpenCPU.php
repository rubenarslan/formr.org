<?php

class OpenCPU {

	protected $baseUrl = 'https://public.opencpu.org';
	protected $libUri = '/ocpu/library';
	protected $last_message = null;
	protected $session_variables = '';

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
		$baseUrl = Config::get($instance);
		$this->setBaseUrl($baseUrl);
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

	/**
	 * Set variables to be used in the next openCPU request. Note that previously set variables are cleared once you call this function
	 * The array parameter if it contains an entry called 'datasets', then these will be passed as R dataframes and other key/value pairs will be passed as R variables
	 *
	 * @param array $data
	 * @return string Returns the session variables how they will be read in R
	 */
	public function defineSessionVariables(array $data) {
		$this->session_variables = '';
		// Set datasets
		if (isset($data['datasets']) && is_array($data['datasets'])) {
			foreach ($data['datasets'] as $data_frame => $content) {
				$this->session_variables .= $data_frame . ' = as.data.frame(jsonlite::fromJSON("' . addslashes(json_encode($content, JSON_UNESCAPED_UNICODE + JSON_NUMERIC_CHECK)) . '"), stringsAsFactors=F)
';
			}
		}
		unset($data['datasets']);
		// set other variables
		foreach ($data as $var_name => $var_value) {
			$this->session_variables .= $var_name . ' = ' .  $var_value . '
';
		}
		return $this->session_variables;
	}

	public function getSessionVariables() {
		return $this->session_variables;
	}

	public function getBaseUrl() {
		return $this->baseUrl;
	}

	public function setLibUrl($libUri) {
		$libUri = trim($libUri, "/");
		$this->libUri = '/' . $libUri;
	}

	public function getLibUrl() {
		return $this->libUri;
	}

	public function getLastMessage() {
		return $this->last_message;
	}

	/**
	 * Get Response headers of opencpu request
	 *
	 * @return null|array
	 */
	public function getResponseHeaders() {
		if (isset($this->curl_info[CURL::RESPONSE_HEADERS])) {
			return $this->curl_info[CURL::RESPONSE_HEADERS];
		}
		return null;
	}

	private function call($uri = '', $params = array(), $method = CURL::HTTP_METHOD_GET) {
		$cachekey = md5(serialize(func_get_args()));
		if (isset($this->cache[$cachekey])) {
			return $this->cache[$cachekey];
		}

		if ($uri) {
			$uri = "/" . ltrim($uri, "/"); // just to be fucking sure
		}
		$url = $this->baseUrl . $this->libUri . $uri;

		// Hack to HIT gnix cache
		if ($method === CURL::HTTP_METHOD_POST) {
			$params = array_map(array($this, 'cr2nl'), $params);
			$params = http_build_query($params);
			$curl_opts = $this->curl_opts + array(CURLOPT_HTTPHEADER => array(
				'Content-Length: ' . strlen($params),
			));
		}

		// Maybe something bad happen in CURL class just throw it with OpenCPU_Exception
		try {
			$results = CURL::HttpRequest($url, $params, $method, $curl_opts, $this->curl_info);
		} catch (Exception $e) {
			throw new OpenCPU_Exception($e->getMessage(), -1, $e);
		}

		if ($this->curl_info['http_code'] < 200 || $this->curl_info['http_code'] > 300) {
			if (!$results) {
				$results = "OpenCPU server '{$this->baseUrl}' could not be contacted";
			}
			throw new OpenCPU_Exception($results, $this->curl_info['http_code']);
		}

		$headers = $this->getResponseHeaders();
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

	public function __construct($location, $key, $raw_result, OpenCPU $ocpu = null) {
		$this->raw_result = $raw_result;
		$this->key = $key;
		$this->location = $location;
		$this->ocpu = $ocpu;
	}

	public function getRawResult() {
		return $this->raw_result;
	}

	public function getKey() {
		return $this->key;
	}

	/**
	 * Get an array of files present in current session
	 *
	 * @param string $result Output from post() or get() methods
	 * @return array
	 */
	public function getFiles() {
		$files = array();
		$result = explode("\n", $this->raw_result);

		foreach ($result as $path) {
			if (!$path || strpos($path, '/files/') === false) {
				continue;
			}

			$files[] = $this->getResponsePath($path);
		}
		return $files;
	}

	public function getLocation() {
		return $this->location;
	}

	public function getFileURL($path) {
		return $this->getResponsePath('/files/' . $path);
	}

	public function getJSONObject($name = null, $params = array()) {
		if ($name === null) {
			$name = '.val';
		}
		$url = $this->getLocation() . 'R/' . $name . '/json';
		$info = array(); // just in case needed in the furture to get curl info
		return CURL::HttpRequest($url, $params, $method = CURL::HTTP_METHOD_GET, array(), $info);
	}

	public function getStdout() {
		$url = $this->getLocation() . 'stdout/text';
		$info = array(); // just in case needed in the furture to get curl info
		return CURL::HttpRequest($url, null, $method = CURL::HTTP_METHOD_GET, array(), $info);
	}

	public function getConsole() {
		$url = $this->getLocation() . 'console/text';
		$info = array(); // just in case needed in the furture to get curl info
		return CURL::HttpRequest($url, null, $method = CURL::HTTP_METHOD_GET, array(), $info);
	}

	protected function getResponsePath($path) {
		return $this->ocpu->getBaseUrl() . $path;
	}
}

class OpenCPU_Exception extends Exception {}
