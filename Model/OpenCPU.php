<?php

class OpenCPU {
	
	public $session_location = null;
	public $session_token = null;
	public $http_status = null;
	public $header_size = 0;
	public $admin_usage = false;

	private $instance;
	private $user_data = '';
	private $knitr_source = null;
	private $hashes = array();
	private $hash_of_call = null;
	private $called_function = null;
	private $posted = null;
	/**
	 *
	 * @var DB
	 */
	private $dbh = null;

	/**
	 * This will store header information returned by curl
	 * @var Array
	 */
	private $curl_info = array();
	
	/**
	 * Additional curl options to set when making curl request
	 * @var Array 
	 */
	private $curl_opts = array(
		CURLINFO_HEADER_OUT => true,
		CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
		CURLOPT_HEADER => true
	);

	public function __construct($instance, $fdb = null) {
		$this->dbh = $fdb;
		$this->instance = $instance;
		$this->clearUserData();
	}

	public function clearUserData() { // reset to init state, more or less (keep cache)
		$this->user_data = '';
		$this->knitr_source = null;
		$this->admin_usage = false;
		$this->http_status = null;
		$this->hash_of_call = null;
		$this->called_function = null;
		$this->session_location = null;
		$this->session_token = null;
		$this->replace_var = null;
		$this->posted = null;
		$this->header_size = 0;
		$this->curl_info = array();
	}

	public function addUserData($data) {
		// loop through the given datasets and import them to R via JSON
		// could I check here whether the dataset contains only null and not even send it to R? but that would break for e.g. is.na(email). hm.
		if (isset($data['datasets'])) {
			foreach ($data['datasets'] as $df_name => $content) {
				$this->user_data .= $df_name . ' = as.data.frame(jsonlite::fromJSON("' . addslashes(json_encode($content, JSON_UNESCAPED_UNICODE + JSON_NUMERIC_CHECK)) . '"), stringsAsFactors=F)'
				. '';
			}
			unset($data['datasets']);
		}

		// add other variables in dataset
		foreach ($data as $variable => $value) {
			$this->user_data .= $variable . ' = ' . $value . '';
		}
	}

	public function anyErrors() {
		$http_code = isset($this->curl_info['http_code']) ? $this->curl_info['http_code'] : null;
		if ($http_code !== null && ($http_code < 100 || $http_code > 302)) {
			return true;
		}

		return false;
	}

	public function responseHeaders() {
		return $this->curl_info;
	}

	private function handleErrors($message, $result, $post, $in, $level = "alert-danger") {
		if ($this->admin_usage):
			$error_msg = $result['body'];

			if (!trim($error_msg)) {
				$error_msg = "OpenCPU appears to be down.";
				if (mb_substr($this->instance, 0, 5) == 'https') {
					$error_msg .= " Maybe check your server's certificates to use encrypted connection to openCPU.";
				}
			}

			if (is_array($error_msg)) {
				$error_msg = current($error_msg);
			}

			if (is_array($post)) {
				$post = current($post);
			}

			alert($message . " <blockquote>" . h($error_msg) . "</blockquote><pre style='background-color:transparent;border:0'>" . h($post) . "</pre>", $level, true);
		endif;

		opencpu_log("R error in $in: " . print_r($post, true) . " " . print_r($result, true) . "\n");
	}

	private function returnParsed($result, $in = '') {
		//$header_parsed = http_parse_headers($result['header']);
		$header_parsed = $this->curl_info[CURL::RESPONSE_HEADERS];

		if (isset($header_parsed['Location']) && isset($header_parsed['X-ocpu-session'])): # won't be there if openCPU is down
			$this->session_location = $header_parsed['Location'];
			$this->session_token = $header_parsed['X-Ocpu-Session'];
		endif;

		$post = $result['post'];
		$parsed = json_decode($result['body'], true);

		if ($parsed === null):
			$this->handleErrors("There was an R error. If you don't find a problem, sometimes this may happen, if you do not test as part of a proper run, especially when referring to other surveys.", $result, $post, $in);
			return null;
		else:
			if (isset($parsed[0]) && is_string($parsed[0])) { // dont change type by accident!
				$parsed = str_replace('/usr/local/lib/R/site-library/', $this->instance . '/ocpu/library/', $parsed[0]);
			} elseif (isset($parsed[0])) {
				$parsed = $parsed[0];
			}

			$this->cache_query($result);
			return $parsed;
		endif;
	}

	public function r_function($function, array $post) {

		used_opencpu();

		if (($result = $this->query_cache($function, $post))) {
			return $result;
		}

		$this->called_function = $this->instance . '/ocpu/' . $function;
		$method = CURL::HTTP_METHOD_GET;
		$params = array();

		if ($post !== null) {
			$method = CURL::HTTP_METHOD_POST;
			$this->posted = $post;
			$params = $this->posted;
		}

		$result = CURL::HttpRequest($this->called_function, $params, $method, $this->curl_opts, $this->curl_info);

		$this->http_status = $this->curl_info['http_code'];
		$this->header_size = $this->curl_info['header_size'];

		if ($this->anyErrors() && !$this->admin_usage) {
			alert(date('Y-m-d H:i:s') . ' There were problems with openCPU. Please try again later.', 'alert-danger');
		}

		$header = $this->curl_info['raw_header'];
		$body = $result;

		$ret = compact('header', 'body', 'post');
		return $ret;
	}

	public function identity($post, $return = '/json') {
		return $this->r_function('library/base/R/identity' . $return, $post);
	}

	private function query_cache($function, $post) {
		$this->hash_of_call = hash("md5", $function . json_encode($post));

		if (isset($this->hashes[$this->hash_of_call])) { // caching at the lowest level for where I forgot it elsewhere
			used_cache();
			return $this->hashes[$this->hash_of_call];
		} elseif ($this->dbh !== null) {
			$result = $this->dbh->findRow('survey_opencpu_query_cache', array('hash' => $this->hash_of_call), array('result_short'));
			if ($result){
				$result['post'] = $post;
				$result['body'] = array();

				if ($result['result_short'] != 9) {
					$result['body'][0] = $result['result_short'];
				} else {
					$result = $this->dbh->findRow('survey_opencpu_query_cache', array('hash' => $this->hash_of_call), array('result_long'));
					$result['body'][0] = $result['result_long'];
				}

				return $result;
			}
		}

		return false;
	}

	private function cache_query($result) {
		$header_parsed = $this->curl_info[CURL::RESPONSE_HEADERS];

		if (isset($header_parsed['X-Ocpu-Cache']) AND $header_parsed['X-Ocpu-Cache'] == "HIT") {
			used_nginx_cache();
		}

		if (!isset($header_parsed['Cache-Control'])) {
			return false;
		}

		// Won't be there if openCPU is down i.e buggus request
		if (!isset($header_parsed['X-Ocpu-Session'])) {
			return false;
		}

		// If we are here then we can cache
		$this->hashes[$this->hash_of_call] = $result;
		if ($this->dbh !== null) {
			$location = $header_parsed['Location'];
			if (isset($header_parsed['Content-Type']) && $header_parsed['Content-Type'] == 'application/json') {
				$location .= 'R/.val/json';
			}

			$result_short = 9;
			if ($result['body'] == true || $result['body'] == false) {
				$result_short = $result['body'];
			}

			$this->dbh->insert('survey_opencpu_query_cache', array(
				'created' => mysql_now(),
				'hash' => $this->hash_of_call,
				'result_short' => $result_short,
				'result_long' => $location,
			));
		}

		return true;
	}

	public function evaluate($source, $return = '/json') {
		$post = array('x' => '{ 
			(function() {
				library(formr)
				' . $this->user_data . '
				' . $source . '
			})() }');

		$result = $this->identity($post, $return);

		return $this->returnParsed($result, "evaluate");
	}

	public function evaluateWith($name, $source, $return = '/json') {
		$post = array('x' => '{ 
			(function() {
				library(formr)
				' . $this->user_data . '
				with(tail(' . $name . ',1), { ## by default evaluated in the most recent results row
				' . $source . '
				})
			})() }');

		$result = $this->identity($post, $return);

		return $this->returnParsed($result, "evaluateWith");
	}

	public function evaluateAdmin($source, $return = '') {
		$this->admin_usage = true;
		$post = array('x' => '{ 
			(function() {
			library(formr)
			' . $this->user_data . '
			' . $source . '
		})() }');

		$result = $this->identity($post, $return);

		return $this->debugCall($result);
	}

	public function knit($source, $return = '/json') {
		$post = array('text' => "'" . addslashes($source) . "'");
		$result = $this->r_function('library/knitr/R/knit' . $return, $post);

		return $this->returnParsed($result, "knit");
	}

	public function knit2html($source, $return = '/json', $self_contained = 1) {
		$post = array('text' => "'" . addslashes($source) . "'", 'self_contained' => $self_contained);
		return $this->r_function('library/formr/R/formr_render' . $return, $post);
	}

	public function knitForUserDisplay($source = '') {
		$source = '
			```{r settings,message=FALSE,warning=F,echo=F}
			library(knitr); library(formr)
			opts_chunk$set(warning=F,message=F,echo=F)
			' . $this->user_data . '
			```
			'.
			$source;

		$result = $this->knit2html($source, '/json');

		return $this->returnParsed($result, "knit");
	}

	//FIXME: something wrong! probably because I did not turn off base64_images!
	public function knitEmail($source) {
		$source = '
			```{r settings,message=FALSE,warning=F,echo=F}
			library(knitr); library(formr)
			opts_chunk$set(warning=F,message=F,echo=F)
			opts_knit$set(upload.fun=formr::email_image)
			' . $this->user_data . '
			```
			'.
			$source;

		$result = $this->knit2html($source, '', 0);

		if ($this->anyErrors()):
			$response = array(
				'Response' => '<pre>' . htmlspecialchars($result['body']) . '</pre>',
				'HTTP headers' => '<pre>' . htmlspecialchars($result['header']) . '</pre>',
			);
		else:
			$header_parsed = $this->curl_info[CURL::RESPONSE_HEADERS];

			if (isset($header_parsed['X-Ocpu-Session'])) {
				$session = '/ocpu/tmp/' . $header_parsed['X-Ocpu-Session'] . '/';
			} else {
				return "Error, no session header.";
			}

			$available = explode("\n", $result['body']);

			$response = array();
			$response['images'] = array();

			$rmarkdown_fig_path = '/files/file';
			foreach ($available as $part):
				$upto = mb_strpos($part, $rmarkdown_fig_path);
				$is_figure = mb_strpos($part, "/figure-html/");
				if ($is_figure !== false):
					$image_id = preg_replace("/[^a-zA-Z0-9]/", '', mb_substr($part, $upto + 1 + strlen($rmarkdown_fig_path))) . '.png'; // 
					$response['images'][$image_id] = $this->instance . $part;
				endif;
			endforeach;

			// info/text stdout/text console/text R/.val/text

			if (in_array($session . 'R/.val', $available)):
				$response['body'] = $this->returnParsed( array(
					"post" => $result['post'],
					"header" => $result['header'],
					"body" => file_get_contents($this->instance . $session . 'R/.val/json'),
				));
			endif;
		endif;

		return $response;
	}

	public function knitEmailForAdminDebug($source) {
		$this->admin_usage = true;

		$source = '
			```{r settings,message=FALSE,warning=F,echo=F}
			library(knitr); library(formr)
			opts_chunk$set(warning=F,message=F,echo=F)
			#opts_knit$set(upload.fun=formr::email_image)
			' . $this->user_data . '
			```
			'.
			$source;

		$this->knitr_source = $source;
		$result = $this->knit2html($source, '', 0);
		return $this->debugCall($result);
	}

	public function knitForAdminDebug($source) {
		$this->admin_usage = true;

		$source = '
			```{r settings,message=FALSE,warning=F,echo=F}
			library(knitr); library(formr)
			opts_chunk$set(warning=T,message=T,echo=T)
			' . $this->user_data . '
			```
			'.
			$source;

		$this->knitr_source = $source;
		$result = $this->knit2html($source, '');

		return $this->debugCall($result);
	}

	public function debugCall($result) {
		if ($this->http_status === 0):
			$response = array(
				'Response' => 'OpenCPU at ' . $this->instance . ' is down.'
			);
			if (mb_substr($this->instance, 0, 5) == 'https'):
				$response["Response"] .= " Maybe check your server's certificates to use encrypted connection to openCPU.";
			endif;
		elseif ($this->http_status < 303):
			$header_parsed = $this->curl_info[CURL::RESPONSE_HEADERS];
			$available = explode("\n", $result['body']);

			$response = array();

			if (isset($header_parsed['X-Ocpu-Session'])): # won't be there if openCPU is down
				$session = '/ocpu/tmp/' . $header_parsed['X-Ocpu-Session'] . '/';
				#			$session = explode('/',$available[0]);
				#			$session = '/'.$session[1].'/'.$session[2] .'/'.$session[3] . '/';
				// info/text stdout/text console/text R/.val/text

				if (in_array($session . 'R/.val', $available)):
					$response['Result'] = file_get_contents($this->instance . $session . 'R/.val/text');
				endif;

				$locations = '';
				foreach ($available AS $segment):
					$href = $this->instance . $segment;
					$path = substr($segment, strlen("/ocpu/tmp/" . $header_parsed['X-Ocpu-Session'] . "/"));
					$locations .= "<a href='$href'>$path</a><br>";
				endforeach;
				$response['Locations'] = $locations;
				$response['Function called'] = substr($this->called_function, strlen($this->instance));
				$response['Posted data'] = '<pre>' . print_r($this->posted, true) . '</pre>';


				if ($this->knitr_source === NULL) {
					$response['Call'] = '<pre>' . htmlspecialchars(current($result['post'])) . '</pre>';
				} else {
					$response['Knitr doc'] = '<pre>' . htmlspecialchars($this->knitr_source) . '</pre>';
				}

				$response['HTTP headers'] = '<pre>' . htmlspecialchars($result['header']) . '</pre>';
				$response['Headers sent'] = '<pre>' . htmlspecialchars($this->curl_info['request_header']) . '</pre>';

				if (in_array($session . 'info', $available)):
					$response['Session info'] = '<pre>' . htmlspecialchars(file_get_contents($this->instance . $session . 'info/print')) . '</pre>';
				endif;

				if (in_array($session . 'console', $available)):
					$response['Console'] = '<pre>' . htmlspecialchars(file_get_contents($this->instance . $session . 'console/print')) . '</pre>';
				endif;
				if (in_array($session . 'stdout', $available)):
					$response['Stdout'] = '<pre>' . htmlspecialchars(file_get_contents($this->instance . $session . 'stdout/print')) . '</pre>';
				endif;

			else:
				$response = array(
					'Response' => 'OpenCPU at ' . $this->instance . ' is down.'
				);
				if (mb_substr($this->instance, 0, 5) == 'https'):
					$response['Response'] .= " Maybe check your server's certificates to use encrypted connection to openCPU.";
				endif;
			endif;
		else:

			$response = array(
				'Response' => '<pre>' . htmlspecialchars($result['body']) . '</pre>',
				'HTTP headers' => '<pre>' . htmlspecialchars($result['header']) . '</pre>',
				'Call' => '<pre>' . htmlspecialchars(current($result['post'])) . '</pre>',
				'Headers sent' => '<pre><code class="r hljs">' . htmlspecialchars($this->curl_info['request_header']) . '</code></pre>',
			);
		endif;

		return array_to_accordion($response);
	}

}
