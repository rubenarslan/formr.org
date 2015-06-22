<?php

/*
  HELPER FUNCTIONS
 */

function formr_log($msg, $type = '') {// shorthand
	$msg = print_r($msg, true);
	$msg = date('Y-m-d H:i:s') . ' ' . $msg;
	if ($type) {
		$msg = "[$type] $msg";
	}

	if(DEBUG) {
		alert('<pre>'.$msg.'</pre>', 'alert-danger');
	}

	error_log($msg . "\n", 3, get_log_file('errors.log'));
}

function get_log_file($filename) {
	return INCLUDE_ROOT . "tmp/logs/$filename";
}

function alert($msg, $class = 'alert-warning', $dismissable = true) { // shorthand
	global $site;
	$site->alert($msg, $class, $dismissable);
}

function log_exception(Exception $e, $prefix = '', $debug_data = null) {
	$msg = $prefix . ' Exception: ' . $e->getMessage(). "\n" . $e->getTraceAsString();

	error_log($msg);

	if ($debug_data !== null) {
		error_log('Debug Data: ' . print_r($debug_data, 1));
	}
}

function notify_user_error($error, $public_message = '') {
	global $user;
	$date = date('Y-m-d H:i:s');
	
	$message = $date . ': ' .$public_message ."<br>";
	
	if (DEBUG OR $user->isAdmin()) {
		if ($error instanceof Exception) {
			$message .= '<pre>'.$error->getMessage()."</pre>";
		} else {
			$message .= $error;
		}
	}
	alert($message, 'alert-danger');
}

function redirect_to($location) {
	global $site, $user;
	$_SESSION['site'] = $site;
	$_SESSION['user'] = serialize($user);
	if (strpos($location, 'index') !== false) {
		$location = '';
	}

	if (mb_substr($location, 0, 4) != 'http') {
		$base = WEBROOT;
		if (mb_substr($location, 0, 1) == '/') {
			$location = $base . mb_substr($location, 1);
		} else {
			$location = $base . $location;
		}
	}
	header("Location: $location");
	exit;
}

function session_over($site, $user) {
	static $closed;
	if ($closed) {
		return false;
	}

	$_SESSION['site'] = $site;
	$_SESSION['user'] = serialize($user);

	session_write_close();
	$closed = true;
	return true;
}

function access_denied() {
	global $site, $user;
	$_SESSION['site'] = $site;
	$_SESSION['user'] = serialize($user);

	header('HTTP/1.0 403 Forbidden');
	require_once INCLUDE_ROOT . "View/public/not_found.php";
	exit;
}

function not_found() {
	global $site, $user;
	$_SESSION['site'] = $site;
	$_SESSION['user'] = serialize($user);

	header('HTTP/1.0 404 Not Found');
	require_once INCLUDE_ROOT . "View/public/not_found.php";
	exit;
}

function bad_request() {
	global $site, $user;
	$_SESSION['site'] = $site;
	$_SESSION['user'] = serialize($user);

	header('HTTP/1.0 400 Bad Request');
	require_once INCLUDE_ROOT . "View/public/not_found.php";
	exit;
}

function bad_request_header() {
	header('HTTP/1.0 400 Bad Request');
}

function json_header() {
	header('Content-Type: application/json');
}

function is_ajax_request() {
	return strtolower(env('HTTP_X_REQUESTED_WITH')) === 'xmlhttprequest';
}

function h($text) {
	return htmlspecialchars($text);
}

function debug($string) {
	if (DEBUG) {
		echo "<pre>";
		print_r($string);
		echo "</pre>";
	}
}

function pr($string) {
	if (DEBUG > 0) {
		echo "<pre>";
		var_dump($string);
#		print_r(	debug_backtrace());
		echo "</pre>";
	} else {
		formr_log($string);
	}
}
function prb($string = null) {
	static $output = "";
	if($string === null) {
		if (DEBUG > 0) {
			echo "<pre>";
			var_dump($string);
	#		print_r(	debug_backtrace());
			echo "</pre>";
		} else {
			formr_log($string);
		}
	} else {
		$output .= "<br>". $string;
	}
}

if (!function_exists('_')) {

	function _($text) {
		return $text;
	}

}

function used_opencpu($echo = false) {
	static $used;
	if ($echo):
		pr("Requests: ".$used);
		return $used;
	endif;
	if (isset($used)) {
		$used++;
	} else {
		$used = 1;
	}
	return $used;
}

function used_cache($echo = false) {
	static $used;
	if ($echo):
		pr("Hashcache: ".$used);
		return $used;
	endif;
	if (isset($used)) {
		$used++;
	} else {
		$used = 1;
	}
	return $used;
}

function used_nginx_cache($echo = false) {
	static $used;
	if ($echo):
		pr("Nginx: ".$used);
		return $used;
	endif;
	if (isset($used)) {
		$used++;
	} else {
		$used = 1;
	}
	return $used;
}

if (!function_exists('__')) {

	/**
	  taken from cakePHP
	 */
	function __($singular, $args = null) {
		if (!$singular) {
			return;
		}

		$translated = _($singular);
		if ($args === null) {
			return $translated;
		} elseif (!is_array($args)) {
			$args = array_slice(func_get_args(), 1);
		}
		return vsprintf($translated, $args);
	}

}

if (!function_exists('__n')) {

	/**
	  taken from cakePHP
	 */
	function __n($singular, $plural, $count, $args = null) {
		if (!$singular) {
			return;
		}

		$translated = ngettext($singular, $plural, null, 6, $count);
		if ($args === null) {
			return $translated;
		} elseif (!is_array($args)) {
			$args = array_slice(func_get_args(), 3);
		}
		return vsprintf($translated, $args);
	}

}

function endsWith($haystack, $needle) {
	$length = strlen($needle);
	if ($length == 0) {
		return true;
	}

	return (mb_substr($haystack, -$length) === $needle);
}

/**
 * Gets an environment variable from available sources, and provides emulation
 * for unsupported or inconsistent environment variables (i.e. DOCUMENT_ROOT on
 * IIS, or SCRIPT_NAME in CGI mode).  Also exposes some additional custom
 * environment information.
 *
 * @param  string $key Environment variable name.
 * @return string Environment variable setting.
 * @link http://book.cakephp.org/2.0/en/core-libraries/global-constants-and-functions.html#env
 */
function env($key) {
	if ($key === 'HTTPS') {
		if (isset($_SERVER['HTTPS'])) {
			return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
		}
		return (mb_strpos(env('SCRIPT_URI'), 'https://') === 0);
	}

	if ($key === 'SCRIPT_NAME') {
		if (env('CGI_MODE') && isset($_ENV['SCRIPT_URL'])) {
			$key = 'SCRIPT_URL';
		}
	}

	$val = null;
	if (isset($_SERVER[$key])) {
		$val = $_SERVER[$key];
	} elseif (isset($_ENV[$key])) {
		$val = $_ENV[$key];
	} elseif (getenv($key) !== false) {
		$val = getenv($key);
	}

	if ($key === 'REMOTE_ADDR' && $val === env('SERVER_ADDR')) {
		$addr = env('HTTP_PC_REMOTE_ADDR');
		if ($addr !== null) {
			$val = $addr;
		}
	}

	if ($val !== null) {
		return $val;
	}

	switch ($key) {
		case 'SCRIPT_FILENAME':
			if (defined('SERVER_IIS') && SERVER_IIS === true) {
				return str_replace('\\\\', '\\', env('PATH_TRANSLATED'));
			}
			break;
		case 'DOCUMENT_ROOT':
			$name = env('SCRIPT_NAME');
			$filename = env('SCRIPT_FILENAME');
			$offset = 0;
			if (!mb_strpos($name, '.php')) {
				$offset = 4;
			}
			return mb_substr($filename, 0, -(strlen($name) + $offset));
		case 'PHP_SELF':
			return str_replace(env('DOCUMENT_ROOT'), '', env('SCRIPT_FILENAME'));
		case 'CGI_MODE':
			return (PHP_SAPI === 'cgi');
		case 'HTTP_BASE':
			$host = env('HTTP_HOST');
			$parts = explode('.', $host);
			$count = count($parts);

			if ($count === 1) {
				return '.' . $host;
			} elseif ($count === 2) {
				return '.' . $host;
			} elseif ($count === 3) {
				$gTLD = array(
					'aero',
					'asia',
					'biz',
					'cat',
					'com',
					'coop',
					'edu',
					'gov',
					'info',
					'int',
					'jobs',
					'mil',
					'mobi',
					'museum',
					'name',
					'net',
					'org',
					'pro',
					'tel',
					'travel',
					'xxx'
				);
				if (in_array($parts[1], $gTLD)) {
					return '.' . $host;
				}
			}
			array_shift($parts);
			return '.' . implode('.', $parts);
	}
	return null;
}

function emptyNull(&$x) {
	$x = ($x == '') ? null : $x;
}

function stringBool($x) {
	if ($x === false) {
		return 'false';
	} elseif ($x === true) {
		return 'true';
	} elseif ($x === null) {
		return 'null';
	} elseif ($x === 0) {
		return '0';
	}

	return $x;
}

function hardTrueFalse($x) {
	if ($x === false) {
		return 'FALSE';
	} elseif ($x === true) {
		return 'TRUE';
#	elseif($x===null)  return 'NULL';
	} elseif ($x === 0) {
		return '0';
	}

	return $x;
}

if (!function_exists('http_parse_headers')) {

	function http_parse_headers($raw_headers) {
		$headers = array();
		$key = ''; // [+]

		foreach (explode("\n", $raw_headers) as $i => $h) {
			$h = explode(':', $h, 2);

			if (isset($h[1])) {
				if (!isset($headers[$h[0]])) {
					$headers[$h[0]] = trim($h[1]);
				} elseif (is_array($headers[$h[0]])) {
					// $tmp = array_merge($headers[$h[0]], array(trim($h[1]))); // [-]
					// $headers[$h[0]] = $tmp; // [-]
					$headers[$h[0]] = array_merge($headers[$h[0]], array(trim($h[1]))); // [+]
				} else {
					// $tmp = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [-]
					// $headers[$h[0]] = $tmp; // [-]
					$headers[$h[0]] = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [+]
				}

				$key = $h[0]; // [+]
			} else { // [+] // [+]
				if (mb_substr($h[0], 0, 1) == "\t") { // [+]
					$headers[$key] .= "\r\n\t" . trim($h[0]); // [+]
				} elseif (!$key) { // [+]
					$headers[0] = trim($h[0]); // [+]
				}
			} // [+]
		}

		return $headers;
	}

}

/**
 * Format a timestamp to display its age (5 days ago, in 3 days, etc.).
 *
 * @param   int     $timestamp
 * @return  string
 */
function timetostr($timestamp) {
	if ($timestamp === false) {
		return "";
	}
	$age = time() - $timestamp;

	$future = ($age <= 0);
	$age = abs($age);

	$age = (int) ($age / 60);		// minutes ago
	if ($age == 0) {
		return $future ? "a moment" : "just now";
	}

	$scales = [
		["minute", "minutes", 60],
		["hour", "hours", 24],
		["day", "days", 7],
		["week", "weeks", 4.348214286], // average with leap year every 4 years
		["month", "months", 12],
		["year", "years", 10],
		["decade", "decades", 10],
		["century", "centuries", 1000],
		["millenium", "millenia", PHP_INT_MAX]
	];

	foreach ($scales as $scale) {
		list($singular, $plural, $factor) = $scale;
		if ($age == 0) {
			return $future ? "less than 1 $singular" : "less than 1 $singular ago";
		}
		if ($age == 1) {
			return $future ? "1 $singular" : "1 $singular ago";
		}
		if ($age < $factor) {
			return $future ? "$age $plural" : "$age $plural ago";
		}

		$age = (int) ($age / $factor);
	}
}

// from http://de1.php.net/manual/en/function.filesize.php
function human_filesize($bytes, $decimals = 2) {
	$sz = 'BKMGTP';
	$factor = floor((strlen($bytes) - 1) / 3);
	return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
}

function cr2nl($string) {
	return str_replace("\r\n", "\n", $string);
}

function time_point($line, $file) {
	static $times, $points;
	if (empty($times)) {
		$times = array($_SERVER["REQUEST_TIME_FLOAT"]);
		$points = array("REQUEST TIME " . round($_SERVER["REQUEST_TIME_FLOAT"] / 60, 6));
	}
	$took = $times[count($times) - 1];
	$times[] = microtime(true);
	$took = round(($times[count($times) - 1] - $took) / 60, 6);
	$points[] = "took $took minutes to get to line " . $line . " in file: " . $file;
	return $points;
}

function echo_time_points($points) {
//	echo "<!---";
	for ($i = 0; $i < count($points); $i++):
		echo $points[$i] . "<br>
";
	endfor;
	echo "took " . round((microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) / 60, 6) . " minutes to the end";
//	echo "--->";
}

function crypto_token($length, $url = true) {
	$bytes = openssl_random_pseudo_bytes($length, $crypto_strong);
	$base64 = base64_url_encode($bytes);
		if (!$crypto_strong):
		alert("Generated cryptographic tokens are not strong.", 'alert-error');
		bad_request();
	endif;
	return $base64;
}

function base64_url_encode($data) {
	return strtr(base64_encode($data), '+/=', '-_~');
}
function base64_url_decode($data) {
	return base64_decode(strtr($data,  '-_~', '+/='));
}

/**
 * Create URL Title
 *
 * Takes a "title" string as input and creates a
 * human-friendly URL string with a "separator" string 
 * as the word separator.
 *
 * @param string the string
 * @param string the separator
 * @param strin $lowercase Should string be returned in lowecase letters
 * @return	string
 */
function url_title($str, $separator = '-', $lowercase = false) {
	if ($separator == 'dash') {
		$separator = '-';
	} else if ($separator == 'underscore') {
		$separator = '_';
	}
	$q_separator = preg_quote($separator);
	$trans = array(
		'&.+?;' => '',
		'[^a-z0-9 _-]' => '',
		'\s+' => $separator,
		'(' . $q_separator . ')+' => $separator
	);
	$str = strip_tags($str);
	foreach ($trans as $key => $val) {
		$str = preg_replace("#" . $key . "#i", $val, $str);
	}

	if ($lowercase === true) {
		$str = strtolower($str);
	}

	return trim($str, $separator);
}

function empty_column($col, $arr) {
	$empty = true;
	$last = null;
	foreach ($arr AS $row):
		if (!(empty($row->$col)) OR // not empty column? (also treats 0 and empty strings as empty)
				$last != $row->$col OR // any variation in this column?
				! (!is_array($row->$col) AND trim($row->$col) == '')):
			$empty = false;
			break;
		endif;
		$last = $row->$col;
	endforeach;
	return $empty;
}

/**
 * Return an array of contents in the run export directory
 *
 * @param string $dir Absolute path to readable directory
 * @return mixed Returns an array if all is well or FALSE otherwise
 */
function get_run_dir_contents($dir) {
	if (!$dir || !is_dir($dir) || !is_readable($dir)) {
		return false;
	}

	$files = glob($dir . '/*.json');
	if (!$files) {
		return false;
	}

	$contents = array();
	foreach ($files as $file) {
		$file_contents = file_get_contents($file);
		$json = json_decode($file_contents);
		if ($json) {
			$contents[] = $json;
		}
	}
	return $contents;
}

/**
 * Get the mime type of a file given filename using FileInfo
 * @see http://php.net/manual/en/book.fileinfo.php
 *
 * @param string $filename
 * @return mixed Returns the mime type as a string or FALSE otherwise
 */
function get_file_mime($filename) {
	$constant = defined('FILEINFO_MIME_TYPE') ? FILEINFO_MIME_TYPE : FILEINFO_MIME;
	$finfo = finfo_open($constant);
	$info = finfo_file($finfo, $filename);
	finfo_close($finfo);
	$mime = explode(';', $info);
	if (!$mime) {
		return false;
	}

	$mime_type = $mime[0];
	return $mime_type;
}

/**
 * Send a file for download to client
 *
 * @param string $file Absolute path to file
 * @param boolean $unlink
 * @todo implement caching stuff
 */
function download_file($file, $unlink = false) {
	$type = get_file_mime($file);
	$filename = basename($file);
	$filesize = filesize($file);
	header('Content-Description: File Transfer');
	header('Content-Type: ' . $type);
	header('Content-Disposition: attachment; filename = "' . $filename . '"');
	header('Content-Transfer-Encoding: binary');
	header('Expires: 0');
	header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
	if ($filesize) {
		header('Content-Length: ' . $filesize);
	}
	readfile($file);
	if ($unlink) {
		unlink($file);
	}
	exit(0);
}

/**
 * @deprecated
 */
function get_duplicate_update_string($columns) {
	foreach ($columns as $i => $column) {
		$column = trim($column, '`');
		$columns[$i] = "`$column` = VALUES(`$column`)";
	}
	return $columns;
}

/**
 * Returns a valid MySQL datetime string
 *
 * @param int $time [optional] Valid unix timestamp
 * @return string
 */
function mysql_datetime($time = null) {
	if ($time === null) {
		$time = time();
	}
	return date('Y-m-d H:i:s', $time);
}

/**
 * Returns a string equivalent to MySQL's NOW() function
 *
 * @return string
 */
function mysql_now() {
	return mysql_datetime();
}

/**
 * Returns formatted strings equivalent to expressions like NOW() + INTERVAL 2 DAY
 *
 * @param string A string defining an interval accepted by PHP's strtotime() function
 * @return string
 */
function mysql_interval($interval) {
	if (($time = strtotime($interval)) === false) {
		throw new Exception ("Invalid time interval given to strtotime '$interval'");
	}
	return mysql_datetime($time);
}

function site_url($uri = '') {
	if ($uri) {
		return WEBROOT . $uri;
	}
	return WEBROOT;
}

function admin_url($uri = '') {
	if ($uri) {
		$uri = '/' . $uri;
	}
	return site_url('admin' . $uri);
}

function assets_url($uri = '') {
	if ($uri) {
		$uri = '/' . $uri;
	}
	return site_url('assets' . $uri);
}

function run_url($name = '') {
	return RUNROOT . $name;
}

function admin_study_url($name = '', $action = '') {
	if ($action) {
		$name = $name . '/' . $action;
	}
	return admin_url('survey/' . $name);
}

function admin_run_url($name = '', $action = '') {
	if ($action) {
		$name = $name . '/' . $action;
	}
	return admin_url('run/' . $name);
}

function array_to_accordion($array) {
	$rand = mt_rand(0,10000);
	$acc = '<div class="panel-group opencpu_accordion" id="opencpu_accordion'.$rand.'">';
	$first = ' in';

	foreach($array as $title => $content):
		if($content == null) {
			$content = stringBool($content);
		}
		$id  = 'collapse' . str_replace(' ', '', $rand.$title);

		$acc .= '
			<div class="panel panel-default">
				<div class="panel-heading">
					<a class="accordion-toggle" data-toggle="collapse" data-parent="#opencpu_accordion'.$rand.'" href="#'.$id.'">
						'.$title.'
					</a>
				</div>
				<div id="'.$id.'" class="panel-collapse collapse'.$first.'">
					<div class="panel-body">
						'.$content.'
					</div>
				</div>
			</div>';
		$first = '';
	endforeach;

	$acc .= '</div>';
	return $acc;
}

function array_to_orderedlist($array, $olclass = null, $liclass = null) {
	$ol = '<ol class="' . $olclass . '">';
	foreach ($array as $title => $label) {
		if ($label) {
			$ol .= '<li title="' . $title . '" class="' . $liclass . '">' . $label . '</li>';
		}
	}
	$ol .= '</ol>';
	return $ol;
}


/**
 * Convert an array of data into variables for OpenCPU request
 * The array parameter if it contains an entry called 'datasets', then these will be passed as R dataframes and other key/value pairs will be passed as R variables
 *
 * @param array $data
 * @return string Returns R variables
 */
function opencpu_define_vars(array $data) {
	$vars = '';
	if (!$data) {
		return $vars;
	}

	// Set datasets
	if (isset($data['datasets']) && is_array($data['datasets'])) {
		foreach ($data['datasets'] as $data_frame => $content) {
			$vars .= $data_frame . ' = as.data.frame(jsonlite::fromJSON("' . addslashes(json_encode($content, JSON_UNESCAPED_UNICODE + JSON_NUMERIC_CHECK)) . '"), stringsAsFactors=F)
';
		}
	}
	unset($data['datasets']);

	// set other variables
	foreach ($data as $var_name => $var_value) {
		$vars .= $var_name . ' = ' .  $var_value . '
';
	}
	return $vars;
}

/**
 * Execute a piece of code against OpenCPU
 *
 * @param string $location A previous openCPU session location
 * @param string $return_format String like 'json'
 * @param mixed $context If this paramter is set, $code will be evaluated with a context
 * @param bool $return_session Should OpenCPU_Session object be returned
 * @return string|OpenCPU_Session|null Returns null if an error occured so check the return value using the equivalence operator (===)
*/
function opencpu_get($location, $return_format = 'json', $context = null, $return_session = false) {
	$uri = $location . $return_format;
	try {
		$session = OpenCPU::getInstance()->get($uri);
		if ($return_session === true) {
			return $session;
		}

		if ($session->hasError()) {
			throw new OpenCPU_Exception($session->getError());
		}
		return $return_format === 'json' ? $session->getJSONObject() : $session->getObject($return_format);
	} catch (OpenCPU_Exception $e) {
		log_exception($e);
		return null;
	}
}
/**
 * Execute a piece of code against OpenCPU
 *
 * @param string $code Each code line should be separated by a newline characted
 * @param string|array An array or string (separated by newline) of variables to be used in OpenCPU request
 * @param string $return_format String like 'json'
 * @param mixed $context If this paramter is set, $code will be evaluated with a context
 * @param bool $return_session Should OpenCPU_Session object be returned
 * @return string|OpenCPU_Session|null Returns null if an error occured so check the return value using the equivalence operator (===)
*/
function opencpu_evaluate($code, $variables = null, $return_format = 'json', $context = null, $return_session = false) {
	if (!is_string($variables)) {
		$variables = opencpu_define_vars($variables);
	}

	if ($context !== null) {
		$code = 'with(tail(' . $context . ', 1), { '
. $code . '
})';
	}

	$params = array('x' => '{ 
(function() {
	library(formr)
	' . $variables . '
	' . $code . '
})() }');

	$uri = '/base/R/identity/' . $return_format;
	try {
		$session = OpenCPU::getInstance()->post($uri, $params);
		if ($return_session === true) {
			return $session;
		}

		if ($session->hasError()) {
			throw new OpenCPU_Exception($session->getError());
		}
		return $return_format === 'json' ? $session->getJSONObject() : $session->getObject($return_format);
	} catch (OpenCPU_Exception $e) {
		notify_user_error($e, "There was a problem dynamically evaluating a value using openCPU.");
		log_exception($e, 'OpenCPU');
		return null;
	}
}


/**
 * Call knit() function from the knitr R package
 *
 * @param string $code
 * @param string $return_format
 * @param bool $return_session Should OpenCPU_Session object be returned
 * @return string|null
*/
function opencpu_knit($code, $return_format = 'json', $return_session = false) {
	$params = array('text' => "'" . addslashes($code) . "'");
	$uri = '/knitr/R/knit/' . $return_format;
	try {
		$session = OpenCPU::getInstance()->post($uri, $params);
		if ($return_session === true) {
			return $session;
		}

		if ($session->hasError()) {
			throw new OpenCPU_Exception($session->getError());
		}
		return $return_format === 'json' ? $session->getJSONObject() : $session->getObject($return_format);
	} catch (OpenCPU_Exception $e) {
		notify_user_error($e, "There was a problem dynamically knitting something using openCPU.");
		log_exception($e, 'OpenCPU');
		return null;
	}
}

/**
 * knit R markdown to html
 *
 * @param string $source
 * @param string $return_format
 * @param int $self_contained
 * @param bool $return_session Should OpenCPU_Session object be returned
 * @return string|null
*/
function opencpu_knit2html($source, $return_format = 'json', $self_contained = 1, $return_session = false) {
	$params = array('text' => "'" . addslashes($source) . "'", 'self_contained' => $self_contained);
	$uri = '/formr/R/formr_render/' . $return_format;
	try {
		$session = OpenCPU::getInstance()->post($uri, $params);
		if ($return_session === true) {
			return $session;
		}

		if ($session->hasError()) {
			throw new OpenCPU_Exception($session->getError());
		}
		return $return_format === 'json' ? $session->getJSONObject() : $session->getObject($return_format);
	} catch (OpenCPU_Exception $e) {
		notify_user_error($e, "There was a problem dynamically knitting something to HTML using openCPU.");
		log_exception($e, 'OpenCPU');
		return null;
	}
}

function opencpu_knitdisplay($source, $variables = null, $return_session = false) {
	if (!is_string($variables)) {
		$variables = opencpu_define_vars($variables);
	}

	$source = '```{r settings,message=FALSE,warning=F,echo=F}
library(knitr); library(formr)
opts_chunk$set(warning=F,message=F,echo=F)
' . $variables . '
```
'.
$source;

	return opencpu_knit2html($source, 'json', 1, $return_session);
}

function opencpu_knitadmin($source, $variables = null, $return_session = false) {
	if (!is_string($variables)) {
		$variables = opencpu_define_vars($variables);
	}

	$source = '```{r settings,message=FALSE,warning=F,echo=F}
library(knitr); library(formr)
opts_chunk$set(warning=T,message=T,echo=T)
' . $variables . '
```
'.
$source;

	return opencpu_knit2html($source, '', 1, $return_session);
}


function opencpu_knitemail($source, array $variables = null, $return_format = 'json', $return_session = false) {
	if (!is_string($variables)) {
		$variables = opencpu_define_vars($variables);
	}

	$source = '```{r settings,message=FALSE,warning=F,echo=F}
library(knitr); library(formr)
opts_chunk$set(warning=F,message=F,echo=F)
opts_knit$set(upload.fun=function(x) { paste0("cid:", basename(x)) })
' . $variables . '
```
'.
$source;

	return opencpu_knit2html($source, $return_format, 0, $return_session);
}

function opencpu_string_key($string) {
	return ':{' . md5($string) . '}';
}

function opencpu_debug($session, OpenCPU $ocpu = null) {
	$debug = array();
	if (empty($session)) {
		$debug['Response'] = 'No OpenCPU_Session found. Server may be down.';
		if ($ocpu !== null) {
			$debug['Request'] = (string)$ocpu->getRequest();
			$reponse_info  = $ocpu->getRequestInfo();
			$debug['Request Headers'] = pre_htmlescape(print_r($reponse_info['request_header'], 1));
		}
	} else {

		try {
			if($session->hasError()):
				$debug['Response'] = pre_htmlescape($session->getError());
			else:
				$debug['Response'] = stringBool($session->getObject());
			endif;
			$debug['Request'] = pre_htmlescape((string)$session->getRequest());
			$urls = $session->getResponsePathsAsLinks();
			if(!$session->hasError() AND !empty($urls)) {
				$locations = '';
				foreach($urls AS $path => $link) {
					$path = str_replace('/ocpu/tmp/'.$session->getKey(), '', $path);
					$locations .= "<a href='$link'>$path</a><br />";
				}
				$debug['Locations'] = $locations;
				$debug['Session Info'] = pre_htmlescape($session->getInfo());
				$debug['Session Console'] = pre_htmlescape($session->getConsole());
				$debug['Session Stdout'] = pre_htmlescape($session->getStdout());
			}

			$reponse_headers = $session->getResponseHeaders();
			$debug['Response Headers'] = pre_htmlescape(print_r($reponse_headers, 1));

			$reponse_info  = $session->caller()->getRequestInfo();
			$debug['Request Headers'] = pre_htmlescape(print_r($reponse_info['request_header'], 1));

		} catch (Exception $e) {
			$debug['Response'] = 'An error occured: ' . $e->getMessage();
		}
	}

	return array_to_accordion($debug);
}

function pre_htmlescape($str) {
	return '<pre>' . htmlspecialchars($str) . '</pre>';
}

function array_val($array, $key, $default = '') {
	if (isset($array[$key])) {
		$default = trim($array[$key]);
	}
	return $default;
}
