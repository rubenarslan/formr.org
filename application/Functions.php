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
    /*
      if (DEBUG) {
      alert('<pre>' . $msg . '</pre>', 'alert-danger');
      }
     */
    error_log($msg . "\n", 3, get_log_file('errors.log'));
}

function formr_log_exception(Exception $e, $prefix = '', $debug_data = null) {
    $msg = $prefix . ' Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
    formr_log($msg);
    if ($debug_data !== null) {
        formr_log('Debug Data: ' . print_r($debug_data, 1));
    }
}

function get_log_file($filename) {
    return APPLICATION_ROOT . "tmp/logs/$filename";
}

function alert($msg, $class = 'alert-warning', $dismissable = true) { // shorthand
    global $site;
    if (!is_object($site)) {
        $site = Site::getInstance();
    }
    $site->alert($msg, $class, $dismissable);
}

function notify_user_error($error, $public_message = '') {
    $run_session = Site::getInstance()->getRunSession();
    $date = date('Y-m-d H:i:s');

    $message = $date . ': ' . $public_message . "<br>";

    if ($run_session && ($run_session->isCron() || $run_session->isTesting())) {
        if ($error instanceof Exception) {
            $message .= $error->getMessage();
        } else {
            $message .= $error;
        }
    }
    alert($message, 'alert-danger');
}

function print_hidden_opencpu_debug_message($ocpu_req, $public_message = '') {
    $run_session = Site::getInstance()->getRunSession();
    if ($run_session && !$run_session->isCron() && $run_session->isTesting()) {
        $date = date('Y-m-d H:i:s');

        $message = $date . ': ' . $public_message . "<br>";

        $message .= opencpu_debug($ocpu_req);
        alert($message, 'alert-info hidden_debug_message hidden');
    }
}

function redirect_to($location = '', $params = array()) {
	if (formr_in_console()) {
		return;
	}

    $location = str_replace(PHP_EOL, '', (string)$location);
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
    if ($params) {
        $location .= '?' . http_build_query($params);
    }

    Session::globalRefresh();
    Session::over();
    header("Location: $location");
    exit;
}

function session_over($site, $user) {
    static $closed;
    if ($closed) {
        return false;
    }
    /*
      $_SESSION['site'] = $site;
      $_SESSION['user'] = serialize($user);
     */
    session_write_close();
    $closed = true;
    return true;
}

function formr_error($code = 500, $title = 'Bad Request', $text = 'Request could not be processed', $hint = null, $link = null, $link_text = null) {
    $code = $code ? $code : 500;
    $text = str_replace(APPLICATION_ROOT, '', $text);
    if ($link === null) {
        $link = site_url();
    }

    if ($link_text === null) {
        $link_text = 'Go to Site';
    }

    if (php_sapi_name() == 'cli') {
        echo date('r') . " Error {$code}: {$text} \n";
        exit;
    }

    $view = new View('public/error', array(
        'code' => $code,
        'title' => $hint ? $hint : $title,
        'text' => $text,
        'link' => $link,
        'link_text' => $link_text,
    ));

    $response = new Response();
    $response->setStatusCode($code, $title)->setContent($view->render())->send();
}

function formr_error_feature_unavailable() {
    formr_error('503', 'Feature Unavailable', 'Sorry this feature is temporarily unavailable. Please try again later', '', 'javascript:history.back();', 'Go Back');
}

function h($text) {
    if (!$text) {
        return null;
    }
    
    return htmlspecialchars($text);
}

function debug($string) {
    if (DEBUG) {
        echo "<pre>";
        print_r($string);
        echo "</pre>";
    }
}

function pr($string, $log = false) {
    if (DEBUG > 0 && !$log) {
        echo "<pre>";
        var_dump($string);
        echo "</pre>";
    } else {
        formr_log(print_r($string, true));
    }
}

function prb($string = null) {
    static $output = "";
    if ($string === null) {
        if (DEBUG > 0) {
            echo "<pre>";
            var_dump($string);
            #		print_r(	debug_backtrace());
            echo "</pre>";
        } else {
            formr_log($string);
        }
    } else {
        $output .= "<br>" . $string;
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
        pr("Requests: " . $used);
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
        pr("Hashcache: " . $used);
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
        pr("Nginx: " . $used);
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
    $x = is_formr_truthy($x) ? $x : null;
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
    } elseif (is_array($x) AND empty($x)) {
        return "NA";
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

    $age = (int) ($age / 60);  // minutes ago
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
    return str_replace("\r\n", "\n", (string)$string);
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
    if (!$crypto_strong) {
        formr_error(500, 'Internal Server Error', 'Generated cryptographic tokens are not strong.', 'Cryptographic Error');
    }
    return $base64;
}

function base64_url_encode($data) {
    return strtr(base64_encode($data), '+/=', '-_~');
}

function base64_url_decode($data) {
    return base64_decode(strtr($data, '-_~', '+/='));
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
        if (!(empty($row->$col)) || // not empty column? (also treats 0 and empty strings as empty)
                $last != $row->$col || // any variation in this column?
                !(!is_array($row->$col) && trim((string)$row->$col) == '')):
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
            $contents[basename($file)] = $json->name;
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
    if (is_string($time)) {
        $time = strtotime($time);
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
        throw new Exception("Invalid time interval given to strtotime '$interval'");
    }
    return mysql_datetime($time);
}

function site_url($uri = '', $params = array()) {
    $url = WEBROOT;
    if ($uri) {
        $url .= $uri . '/';
    }
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    return trim($url, '\/\\');
}

function admin_url($uri = '', $params = array()) {
    if ($uri) {
        $uri = '/' . $uri;
    }
    return site_url('admin' . $uri, $params);
}

function run_url($name = '', $action = '', $params = array()) {
    if ($name === Run::TEST_RUN) {
        return site_url('run/' . $name . '/' . $action);
    }

    $protocol = Config::get('define_root.protocol');
    $domain = trim(Config::get('define_root.doc_root', ''), "\/\\");
    $subdomain = null;
    if (Config::get('use_study_subdomains')) {
        $domain = Config::get('define_root.study_domain', $domain); # use different domain for studies if set
        $subdomain = strtolower($name) . '.';
    } else {
        $domain .= '/' . $name;
    }
    $url = $protocol . $subdomain . $domain;
    if ($action) {
        $action = trim($action, "\/\\");
        $url .= '/' . $action . '/';
    }
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    return $url;
}

function admin_study_url($name = '', $action = '', $params = array()) {
    if ($action) {
        $name = $name . '/' . $action;
    }
    return admin_url('survey/' . $name, $params);
}

function admin_run_url($name = '', $action = '', $params = array()) {
    if ($action) {
        $name = $name . '/' . $action;
    }
    return admin_url('run/' . $name, $params);
}

/**
 * modified from https://stackoverflow.com/questions/118884/what-is-an-elegant-way-to-force-browsers-to-reload-cached-css-js-files?rq=1
 *  Given a file, i.e. /css/base.css, replaces it with a string containing the
 *  file's mtime, i.e. /css/base.1221534296.css.
 *  
 *  @param $file  The file to be loaded. Must not start with a slash.
 */
function asset_url($file) {
    if (strpos($file, 'http') !== false || strpos($file, '//') === 0) {
        return $file;
    }
    if (strpos($file, 'assets') === false) {
        $file = 'assets/' . $file;
    }
    $mtime = @filemtime(APPLICATION_ROOT . "webroot/" . $file);
    if (!$mtime) {
        return site_url($file);
    }
    return site_url($file . "?v" . $mtime);
}

function monkeybar_url($run_name, $action = '', $params = array()) {
    return run_url($run_name, 'monkey-bar/' . $action, $params);
}

function array_to_accordion($array) {
    $rand = mt_rand(0, 10000);
    $acc = '<div class="panel-group opencpu_accordion" id="opencpu_accordion' . $rand . '">';
    $first = ' in';

    foreach ($array as $title => $content):
        if ($content == null) {
            $content = stringBool($content);
        }
        $id = 'collapse' . str_replace(' ', '', $rand . $title);

        $acc .= '
			<div class="panel panel-default">
				<div class="panel-heading">
					<a class="accordion-toggle" data-toggle="collapse" data-parent="#opencpu_accordion' . $rand . '" href="#' . $id . '">
						' . $title . '
					</a>
				</div>
				<div id="' . $id . '" class="panel-collapse collapse' . $first . '">
					<div class="panel-body">
						' . $content . '
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
        if (is_formr_truthy($label)) {
            $ol .= '<li title="' . $title . '" class="' . $liclass . '">' . $label . '</li>';
        }
    }
    $ol .= '</ol>';
    return $ol;
}

function is_formr_truthy($value) {
    if (is_array($value)) {
        return $value;
    }
    $value = (string) $value;
    $value = trim($value);
    return $value || $value === '0';
}

/**
 * Convert an array of data into variables for OpenCPU request
 * The array parameter if it contains an entry called 'datasets', then these will be passed as R dataframes and other key/value pairs will be passed as R variables
 *
 * @param array $data
 * @param string $context
 * @return string Returns R variables
 */
function opencpu_define_vars(array $data, $context = null) {
    $vars = '';
    if (!$data) {
        return $vars;
    }

    // Set datasets
    if (isset($data['datasets']) && is_array($data['datasets'])) {
        foreach ($data['datasets'] as $data_frame => $content) {
            $vars .= $data_frame . ' = as.data.frame(jsonlite::fromJSON("' . addslashes(json_encode($content, JSON_UNESCAPED_UNICODE)) . '"), stringsAsFactors=F)
';
            if ($context === $data_frame) {
                $vars .= 'attach(tail(' . $context . ', 1))
';
            }
        }
    }
    unset($data['datasets']);

    // set other variables
    foreach ($data as $var_name => $var_value) {
        $vars .= $var_name . ' = ' . $var_value . '
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
        opencpu_log($e);
        return null;
    }
}

/**
 * Execute a piece of code against OpenCPU
 *
 * @param string $code Each code line should be separated by a newline characted
 * @param string|array $variables An array or string (separated by newline) of variables to be used in OpenCPU request
 * @param string $return_format String like 'json'
 * @param mixed $context If this paramter is set, $code will be evaluated with a context
 * @param bool $return_session Should OpenCPU_Session object be returned
 * @return string|OpenCPU_Session|null Returns null if an error occured so check the return value using the equivalence operator (===)
 */
function opencpu_evaluate($code, $variables = null, $return_format = 'json', $context = null, $return_session = false) {
    if ($return_session !== true) {
        $result = shortcut_without_opencpu($code, $variables);
        if ($result !== null) {
            return current($result);
        }
    }

    if (!is_string($variables)) {
        $variables = opencpu_define_vars($variables, $context);
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
            throw new OpenCPU_Exception(opencpu_debug($session));
        } else {
            print_hidden_opencpu_debug_message($session, "OpenCPU debugger for run R code.");
        }

        return $return_format === 'json' ? $session->getJSONObject() : $session->getObject($return_format);
    } catch (OpenCPU_Exception $e) {
        notify_user_error($e, "There was a computational error.");
        opencpu_log($e);
        return null;
    }
}

/**
 * In one common, well-defined case, we just skip calling openCPU
 *
 * @param string code
 * @param array data for openCPU
 * @return mixed|null Returns null if things aren't simple, so check the return value using the equivalence operator (===)
 */
function shortcut_without_opencpu($code, $data) {
    if ($code === 'tail(survey_unit_sessions$created,1)') {
        return array(end($data['datasets']['survey_unit_sessions']['created']));
    } elseif (preg_match("/^([a-zA-Z0-9_]+)\\\$([a-zA-Z0-9_]+)$/", (string)$code, $matches)) {
        $survey = $matches[1];
        $variable = $matches[2];
        if (!empty($data['datasets'][$survey][$variable]) && count($data['datasets'][$survey][$variable]) == 1) {
            return $data['datasets'][$survey][$variable];
        }
    }

    return null;
}

/**
 * Call knit() function from the knitr R package
 *
 * @param string $code
 * @param string $return_format
 * @param bool $return_session Should OpenCPU_Session object be returned
 * @return string|null
 */
function opencpu_knit($code, $return_format = 'json', $self_contained = 1, $return_session = false) {
    $params = array('text' => "'" . addslashes($code) . "'");
    $uri = '/knitr/R/knit/' . $return_format;

    try {
        $session = OpenCPU::getInstance()->post($uri, $params);
        if ($return_session === true) {
            return $session;
        }

        if ($session->hasError()) {
            throw new OpenCPU_Exception(opencpu_debug($session));
        }
        return $return_format === 'json' ? $session->getJSONObject() : $session->getObject($return_format);
    } catch (OpenCPU_Exception $e) {
        notify_user_error($e, "There was a problem dynamically knitting something using openCPU.");
        opencpu_log($e);
        return null;
    }
}

function opencpu_knit_plaintext($source, $variables = null, $return_session = false, $context = null) {
    if (!is_string($variables)) {
        $variables = opencpu_define_vars($variables, $context);
    }

    $run_session = Site::getInstance()->getRunSession();

    $show_errors = 'FALSE';
    $show_warnings = 'FALSE';
    if (!$run_session OR $run_session->isTesting()) {
        $show_errors = 'FALSE';
        $show_warnings = 'TRUE';
    }

    $source = '```{r settings,warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F}
library(knitr); library(formr)
opts_chunk$set(warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F,fig.height=7,fig.width=10)
opts_knit$set(base.url="' . OpenCPU::TEMP_BASE_URL . '")
' . $variables . '
```
' .
            $source;

    return opencpu_knit($source, 'json', 0, $return_session);
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
    $uri = '/formr/R/formr_render_commonmark/' . $return_format;

    $uri = '/formr/R/formr_inline_render/' . $return_format;
    try {
        $session = OpenCPU::getInstance()->post($uri, $params);
        if ($return_session === true) {
            return $session;
        }

        if ($session->hasError()) {
            throw new OpenCPU_Exception(opencpu_debug($session));
        }

        return $return_format === 'json' ? $session->getJSONObject() : $session->getObject($return_format);
    } catch (OpenCPU_Exception $e) {
        notify_user_error($e, "There was a problem dynamically knitting something to HTML using openCPU.");
        opencpu_log($e);
        return null;
    }
}

/**
 * Replaces an html image tag with data URI
 *
 * @param $matches array The matches from preg_replace_callback [full match, group 1, group 2, group 3]
 * @return string The replaced image tag with data URI
 */
function replaceImgTags(array $matches): string {
    [, $pre, $url, $post] = $matches;
    // if url is relative -> file should be in webroot
    if (!str_contains($url, '://')) {
        $url = APPLICATION_ROOT . 'webroot/' . $url;
    }
    $imageData = base64_encode(file_get_contents($url));
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_buffer($finfo, $url);
    $dataUri = "data:$mime;base64,$imageData";
    return "<img{$pre}src='$dataUri'$post>";
}

function opencpu_knit_iframe($source, $variables = null, $return_session = false, $context = null, $description = '', $footer_text = '') {
    if (!is_string($variables)) {
        $variables = opencpu_define_vars($variables, $context);
    }

    $run_session = Site::getInstance()->getRunSession();

    $show_errors = 'FALSE';
    $show_warnings = 'FALSE';
    if (!$run_session OR $run_session->isTesting()) {
        $show_errors = 'TRUE';
        $show_warnings = 'TRUE';
    }

    $yaml = "";
    $yaml_lines = '/^\-\-\-/um';
    if (preg_match_all($yaml_lines, (string)$source) >= 2) {
        $parts = preg_split($yaml_lines, $source, 3);
        $yaml = "---" . $parts[1] . "---\n\n";
        $source = $parts[2];
    }

    $source = $yaml .
            '```{r settings,warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=' . $show_warnings . '}
library(knitr); library(formr)
opts_chunk$set(warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=' . $show_warnings . ',fig.height=7,fig.width=10)
' . $variables . '
```

' .
            $description . '


' .
            $source .
            "



# &nbsp;

" . $footer_text;

    $params = array('text' => "'" . addslashes($source) . "'");

    $uri = '/formr/R/formr_render/';
    try {
        $session = OpenCPU::getInstance()->post($uri, $params);
        if ($return_session === true) {
            return $session;
        }

        if ($session->hasError()) {
            throw new OpenCPU_Exception(opencpu_debug($session));
        }

        return $session->getJSONObject();
    } catch (OpenCPU_Exception $e) {
        notify_user_error($e, "There was a computational error.");
        opencpu_log($e);
        return null;
    }
}

function opencpu_knitdisplay($source, $variables = null, $return_session = false, $context = null) {
    if (!is_string($variables)) {
        $variables = opencpu_define_vars($variables, $context);
    }

    $run_session = Site::getInstance()->getRunSession();

    $show_errors = 'FALSE';
    $show_warnings = 'FALSE';
    if (!$run_session OR $run_session->isTesting()) {
        $show_errors = 'TRUE';
        $show_warnings = 'TRUE';
    }

    $source = '```{r settings,warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F}
library(knitr); library(formr)
opts_chunk$set(warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F,fig.height=7,fig.width=10)
opts_knit$set(base.url="' . OpenCPU::TEMP_BASE_URL . '")
' . $variables . '
```
' .
            $source;

    return opencpu_knit2html($source, 'json', 0, $return_session);
}

function opencpu_knitadmin($source, $variables = null, $return_session = false) {
    if (!is_string($variables)) {
        $variables = opencpu_define_vars($variables);
    }

    $run_session = Site::getInstance()->getRunSession();

    $show_errors = 'FALSE';
    $show_warnings = 'FALSE';
    if (!$run_session OR $run_session->isTesting()) {
        $show_errors = 'TRUE';
        $show_warnings = 'TRUE';
    }

    $source = '```{r settings,warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F}
library(knitr); library(formr)
opts_chunk$set(warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F)
opts_knit$set(base.url="' . OpenCPU::TEMP_BASE_URL . '")
' . $variables . '
```
' .
            $source;

    return opencpu_knit2html($source, 'json', 0, $return_session);
}

function opencpu_knit_email($source, array $variables = null, $return_format = 'json', $return_session = false) {
    if (!is_string($variables)) {
        $variables = opencpu_define_vars($variables);
    }
    $run_session = Site::getInstance()->getRunSession();

    $show_errors = 'FALSE';
    $show_warnings = 'FALSE';
    if (!$run_session OR $run_session->isTesting()) {
        $show_errors = 'TRUE';
        $show_warnings = 'TRUE';
    }

    $source = '```{r settings,warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F}
library(knitr); library(formr)
opts_chunk$set(warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F,fig.retina=2)
opts_knit$set(upload.fun=function(x) { paste0("cid:", URLencode(basename(x))) })
' . $variables . '
```
' .
            $source;

    return opencpu_knit2html($source, $return_format, 0, $return_session);
}

function opencpu_string_key($index) {
    return 'formr-ocpu-label-' . $index;
}

function opencpu_string_key_parsing($strings) {
    $ret = array();
    foreach ($strings as $index => $string) {
        $ret['formr-ocpu-label-' . $index] = $string;
    }
    return $ret;
}

/**
 * Parse a bulk of strings in ocpu
 *
 * @param UnitSession $unitSession Unit session containing the data needed
 * @param array $string_templates An array of strings to be parsed
 * @return array Returns an array of parsed labels indexed by the label-key to be substituted
 */
function opencpu_multistring_parse(UnitSession $unitSession, array $string_templates) {
    $survey = $unitSession->runUnit->surveyStudy;
    $markdown = implode(OpenCPU::STRING_DELIMITER, $string_templates);
    $opencpu_vars = $unitSession->getRunData($markdown, $survey->name);
    $session = opencpu_knitdisplay($markdown, $opencpu_vars, true, $survey->name);

    if ($session AND!$session->hasError()) {
        print_hidden_opencpu_debug_message($session, "OpenCPU debugger for dynamic values and showifs.");
        $parsed_strings = $session->getJSONObject();
        $strings = explode(OpenCPU::STRING_DELIMITER_PARSED, $parsed_strings);
        $strings = array_map("remove_tag_wrapper", $strings);
        return opencpu_string_key_parsing($strings);
    } else {
        notify_user_error(opencpu_debug($session), "There was a problem dynamically knitting something to HTML using openCPU.");
        return fill_array(opencpu_string_key_parsing($string_templates));
    }
}

/**
 * Substitute parsed strings in the collection of items that were sent for parsing
 * This function does not return anything as the collection of items is passed by reference
 * For objects having the property 'label_parsed', they are checked and substituted
 *
 * @param array $array An array of data contaning label templates
 * @param array $parsed_strings An array of parsed labels
 */
function opencpu_substitute_parsed_strings(array &$array, array $parsed_strings) {
    foreach ($array as $key => &$value) {
        if (is_array($array[$key])) {
            opencpu_substitute_parsed_strings($array[$key], $parsed_strings);
        } elseif (is_object($value) && property_exists($value, 'label_parsed')) {
            $value->label_parsed = isset($parsed_strings[$value->label_parsed]) ? $parsed_strings[$value->label_parsed] : $value->label_parsed;
            $array[$key] = $value;
        } elseif (isset($parsed_strings[$value])) {
            $array[$key] = $parsed_strings[$value];
        }
    }
}

function opencpu_multiparse_showif(UnitSession $unitSession, array $showifs, $return_session = false) {
    $survey = $unitSession->runUnit->surveyStudy;
    $code = "(function() {with(tail({$survey->name}, 1), {\n";
    $code .= "formr.showifs = list();\n";
    $code .= "within(formr.showifs,  { \n";
    $code .= implode("\n", $showifs) . "\n";
    $code .= "})\n";
    $code .= "})})()\n";

    $variables = $unitSession->getRunData($code, $survey->name);
    return opencpu_evaluate($code, $variables, 'json', null, $return_session);
}

function opencpu_multiparse_values(UnitSession $unitSession, array $values, $return_session = false) {
    $survey = $unitSession->runUnit->surveyStudy;
    $code = "(function() {with(tail({$survey->name}, 1), {\n";
    $code .= "list(\n" . implode(",\n", $values) . "\n)";
    $code .= "})})()\n";

    $variables = $unitSession->getRunData($code, $survey->name);
    return opencpu_evaluate($code, $variables, 'json', null, $return_session);
}

function opencpu_debug($session, OpenCPU $ocpu = null, $rtype = 'json') {
    $debug = array();
    if (empty($session)) {
        $debug['Response'] = 'No OpenCPU_Session found. Server may be down.';
        if ($ocpu !== null) {
            $request = $ocpu->getRequest();
            $debug['Request'] = (string) $request;
            $reponse_info = $ocpu->getRequestInfo();
            $debug['Request Headers'] = pre_htmlescape(print_r($reponse_info['request_header'], 1));
        }
    } else {

        try {
            $request = $session->getRequest();
            $params = $request->getParams();
            if (isset($params['text'])) {
                $debug['R Markdown'] = '
					<a href="#" class="download_r_code" data-filename="formr_rmarkdown.Rmd">Download R Markdown file to debug.</a><br>
					<textarea class="form-control" rows="10" readonly>' . h(stripslashes(substr($params['text'], 1, -1))) . '</textarea>';
            } elseif (isset($params['x'])) {
                $debug['R Code'] = '
					<a href="#" class="download_r_code" data-filename="formr_values_showifs.R">Download R code file to debug.</a><br>
					<textarea class="form-control" rows="10" readonly>' . h((substr($params['x'], 1, -1))) . '</textarea>';
            }
            if ($session->hasError()) {
                $debug['Response'] = pre_htmlescape($session->getError());
            } else {
                if (($files = $session->getFiles("knit.html"))) {
                    $iframesrc = $files['knit.html'];
                    $debug['Response'] = '
					<p>
						<a href="' . $iframesrc . '" target="_blank">Open in new window</a>
					</p>';
                } else if (isset($params['text']) || $rtype === 'text') {
                    $debug['Response'] = stringBool($session->getObject('text'));
                } else {
                    $debug['Response'] = pre_htmlescape(json_encode($session->getJSONObject(), JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE));
                }
            }

            $urls = $session->getResponsePathsAsLinks();
            if (!$session->hasError() AND!empty($urls)) {
                $locations = '';
                foreach ($urls AS $path => $link) {
                    $path = str_replace('/ocpu/tmp/' . $session->getKey(), '', $path);
                    $locations .= "<a href='$link'>$path</a><br />";
                }
                $debug['Locations'] = $locations;
            }
            $debug['Session Info'] = pre_htmlescape($session->getInfo());
            $debug['Session Console'] = pre_htmlescape($session->getConsole());
            $debug['Session Stdout'] = pre_htmlescape($session->getStdout());
            $debug['Request'] = pre_htmlescape((string) $request);

            $reponse_headers = $session->getResponseHeaders();
            $debug['Response Headers'] = pre_htmlescape(print_r($reponse_headers, 1));

            $reponse_info = $session->caller()->getRequestInfo();
            $debug['Request Headers'] = pre_htmlescape(print_r($reponse_info['request_header'], 1));
        } catch (Exception $e) {
            $debug['Response'] = 'An error occured: ' . $e->getMessage();
        }
    }

    return array_to_accordion($debug);
}

function opencpu_log($msg) {
    $log = '';
    if ($msg instanceof Exception) {
        $log .= $msg->getMessage() . "\n" . $msg->getTraceAsString();
    } else {
        $log .= $msg;
    }
    error_log($log . "\n", 3, get_log_file('opencpu.log'));
}

function opencpu_formr_variables($q) {
    $variables = [];
    if (preg_match("/\btime_passed\b/", (string)$q)) {
        $variables[] = 'formr_last_action_time';
    }
    if (preg_match("/\bnext_day\b/", (string)$q)) {
        $variables[] = 'formr_last_action_date';
    }
    if (strstr((string)$q, '.formr$login_code') !== false) {
        $variables[] = 'formr_login_code';
    }
    if (preg_match("/\buser_id\b/", (string)$q)) {
        $variables[] = 'user_id';
    }
    if (strstr((string)$q, '.formr$login_link') !== false) {
        $variables[] = 'formr_login_link';
    }
    if (strstr((string)$q, '.formr$nr_of_participants') !== false) {
        $variables[] = 'formr_nr_of_participants';
    }
    if (strstr((string)$q, '.formr$session_last_active') !== false) {
        $variables[] = 'formr_session_last_active';
    }

    return $variables;
}

function pre_htmlescape($str) {
    $str = (string) $str;
    return '<pre>' . htmlspecialchars($str) . '</pre>';
}

function array_val($array, $key, $default = "") {
    if (!is_array($array)) {
        return false;
    }
    if (array_key_exists($key, $array)) {
        return $array[$key];
    }
    return $default;
}

function shutdown_formr_org() {
    $user = Site::getCurrentUser();
    if (is_object($user) && $user->cron) {
        return;
    }

    $error = error_get_last();
    if ($error !== null && $error['type'] === E_ERROR && !DEBUG) {
        $errno = $error["type"];
        $errfile = $error["file"];
        $errline = $error["line"];
        $errstr = $error["message"];
        $code = strtoupper(AnimalName::haikunate());

        $msg = "A fatal error occured and your request could not be completed. Contact site admins with these details \n";
        $msg .= "Error [$errno] in $errfile line $errline \n $code";
  
        formr_log("$msg \n $errstr", $code);
        formr_error(500, 'Internal Server Error', nl2br($msg), 'Fatal Error');
    }
}

function remove_tag_wrapper($text, $tag = 'p') {
    $text = trim((string)$text);
    if (preg_match("@^<{$tag}>(.+)</{$tag}>$@", $text, $matches)) {
        $text = isset($matches[1]) ? $matches[1] : $text;
    }
    return $text;
}

function delete_tmp_file($file) {
    // unlink tmp file especially for the case of google sheets
    if (!empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
        @unlink($file['tmp_name']);
    }
}

/**
 * Hackathon to dwnload an excel sheet from google
 *
 * @param string $survey_name
 * @param string $google_link The URL of the Google Sheet
 * @return array|boolean Returns an array similar to that of an 'uploaded-php-file' or FALSE otherwise;
 */
function google_download_survey_sheet($survey_name, $google_link) {
    $google_id = google_get_sheet_id($google_link);
    if (!$google_id) {
        return false;
    }

    $destination_file = Config::get('survey_upload_dir') . '/googledownload-' . $google_id . '.xlsx';
    $google_download_link = "http://docs.google.com/spreadsheets/d/{$google_id}/export?format=xlsx&{$google_id}";
    $info = array();

    try {
        if (!is_writable(dirname($destination_file))) {
            throw new Exception("The survey backup directory is not writable");
        }
        $options = array(
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
        );

        CURL::DownloadUrl($google_download_link, $destination_file, null, CURL::HTTP_METHOD_GET, $options, $info);
        if (empty($info['http_code']) || $info['http_code'] < 200 || $info['http_code'] > 302 || strstr($info['content_type'], "text/html") !== false) {
            $link = google_get_sheet_link($google_id);
            throw new Exception("The google sheet at {$link} could not be downloaded. Please make sure everyone with the link can access the sheet!");
        }

        $ret = array(
            'name' => $survey_name . '.xlsx',
            'tmp_name' => $destination_file,
            'size' => filesize($destination_file),
            'google_id' => $google_id,
            'google_file_id' => $google_id,
            'google_link' => google_get_sheet_link($google_id),
            'google_download_link' => $google_download_link,
        );
    } catch (Exception $e) {
        formr_log_exception($e, 'CURL_DOWNLOAD', $google_link);
        alert($e->getMessage(), 'alert-danger');
        $ret = false;
    }
    return $ret;
}

/**
 * preg-match the Google sheet ID from the google sheet link
 *
 * @param string $link
 * @return string|null
 */
function google_get_sheet_id($link) {
    $matches = array();
    preg_match('/spreadsheets\/d\/(.*)\/edit/', $link, $matches);
    if (!empty($matches[1])) {
        return $matches[1];
    }
    return null;
}

/**
 * Returns the google sheet link given ID
 *
 * @param string $id
 * @return string
 */
function google_get_sheet_link($id) {
    return "https://docs.google.com/spreadsheets/d/{$id}/edit";
}

function strt_replace($str, $params) {
    foreach ($params as $key => $value) {
        $str = str_replace('%{' . $key . '}', $value, $str);
        $str = str_replace('{' . $key . '}', $value, $str);
    }
    return $str;
}

function fill_array($array, $value = '') {
    foreach ($array as $key => $v) {
        $array[$key] = $value;
    }
    return $array;
}

function files_are_equal($a, $b) {
    if (!file_exists($a) || !file_exists($b))
        return false;

    // Check if filesize is different
    if (filesize($a) !== filesize($b))
        return false;

    if (sha1_file($a) !== sha1_file($b))
        return false;

    return true;
}

function create_zip_archive($files, $destination, $overwrite = false) {
    $zip = new ZipArchive();

    if ($zip->open($destination, $overwrite ? ZIPARCHIVE::OVERWRITE : ZIPARCHIVE::CREATE) !== true) {
        return false;
    }

    //add the files
    foreach ($files as $file) {
        if (is_file($file)) {
            $zip->addFile($file, basename($file));
        }
    }
    $zip->close();

    //check to make sure the file exists
    return file_exists($destination);
}

function create_ini_file($assoc, $filepath) {
    file_put_contents($filepath, '');
    foreach ($assoc as $section => $fields) {
        file_put_contents($filepath, "[{$section}]\n", FILE_APPEND);
        foreach ($fields as $key => $value) {
            file_put_contents($filepath, "{$key} = {$value}\n", FILE_APPEND);
        }
        file_put_contents($filepath, "\n", FILE_APPEND);
    }
    return file_exists($filepath);
}

function deletefiles($files) {
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

function get_default_assets($config = 'site') {
    if (DEBUG) {
        return Config::get("default_assets.dev.{$config}");
    } else {
        return Config::get("default_assets.prod.{$config}");
    }
}

function get_assets() {
    return get_default_assets('assets');
}

function print_stylesheets($files, $id = null) {
    foreach ($files as $i => $file) {
        $id = 'css-' . $i . $id;
        echo '<link href="' . asset_url($file) . '" rel="stylesheet" type="text/css" id="' . $id . '">' . "\n";
    }
}

function print_scripts($files, $id = null) {
    foreach ($files as $i => $file) {
        $id = 'js-' . $i . $id;
        echo '<script src="' . asset_url($file) . '" id="' . $id . '"></script>' . "\n";
    }
}

function fwrite_json($handle, $data) {
    if ($handle) {
        fseek($handle, 0, SEEK_END);
        if (ftell($handle) > 0) {
            fseek($handle, -1, SEEK_END);
            fwrite($handle, ',', 1);
            fwrite($handle, "\n" . json_encode($data) . "]");
        } else {
            fwrite($handle, json_encode(array($data)));
        }
    }
}

function do_run_shortcodes($text, $run_name, $sess_code) {
    $link_tpl = '<a href="%{url}">%{text}</a>';
    if ($run_name) {
        $login_url = run_url($run_name, null, array('code' => $sess_code));
        $logout_url = run_url($run_name, 'logout', array('code' => $sess_code));
        $settings_url = run_url($run_name, 'settings', array('code' => $sess_code));
    } else {
        $login_url = $settings_url = site_url();
        $logout_url = site_url('logout');
        //alert("Generated a login link, but no run was specified", 'alert-danger');
    }


    $settings_link = Template::replace($link_tpl, array('url' => $settings_url, 'text' => 'Settings Link'));
    $login_link = Template::replace($link_tpl, array('url' => $login_url, 'text' => 'Login Link'));
    $logout_link = Template::replace($link_tpl, array('url' => $logout_url, 'text' => 'Logout Link'));

    $text = str_replace("{{login_link}}", $login_link, (string)$text);
    $text = str_replace("{{login_url}}", $login_url, $text);
    $text = str_replace("{{login_code}}", urlencode($sess_code), $text);
    $text = str_replace("{{settings_link}}", $settings_link, $text);
    $text = str_replace("{{settings_url}}", $settings_url, $text);
    $text = str_replace("{{logout_link}}", $logout_link, $text);
    $text = str_replace("{{logout_url}}", $logout_url, $text);
    $text = str_replace(urlencode("{{login_url}}"), $login_url, $text);
    $text = str_replace(urlencode("{{login_code}}"), urlencode($sess_code), $text);
    $text = str_replace(urlencode("{{settings_url}}"), $settings_url, $text);
    $text = str_replace(urlencode("{{logout_url}}"), $logout_url, $text);

    return $text;
}

function factortosecs($value, $unit) {
    $factors = array(
        'seconds' => 1,
        'minutes' => 60,
        'hours' => 3600,
        'days' => 86400,
        'months' => 30 * 86400,
        'years' => 365 * 86400,
    );

    if (isset($factors[$unit])) {
        return $value * $factors[$unit];
    } else {
        return null;
    }
}

function secstofactor($seconds) {
    if (!$seconds) {
        return null;
    }

    $factors = array(
        'years' => 365 * 86400,
        'months' => 30 * 86400,
        'days' => 86400,
        'hours' => 3600,
        'minutes' => 60,
        'seconds' => 1,
    );

    foreach ($factors as $unit => $factor) {
        if ($seconds % $factor === 0) {
            return array($seconds / $factor, $unit);
        }
    }
    return array($seconds, 'seconds');
}

function knitting_needed($source) {
	if (!$source) {
		return false;
	}
	
    if (mb_strpos($source, '`r ') !== false || mb_strpos($source, '```{r') !== false) {
        return true;
    }

    return false;
}

function get_db_non_user_tables() {
    return [
        'survey_users' => array("created", "modified", "user_code", "email", "email_verified", "mobile_number", "mobile_verified"),
        'survey_run_sessions' => array("session", "created", "last_access", "position", "current_unit_id", "deactivated", "no_email"),
        'survey_unit_sessions' => array("created", "ended", 'expired', "unit_id", "position", "type"),
        'externals' => array("created", "ended", 'expired', "position"),
        'survey_items_display' => array("created", "answered_time", "answered", "displaycount", "item_id"),
        'survey_email_log' => array("email_id", "created", "recipient"),
        'shuffle' => array("unit_id", "created", "group"),
    ];
}

function get_db_non_session_tables() {
    return ['survey_users', 'survey_run_sessions', 'survey_unit_sessions'];
}

function formr_check_maintenance() {
    $ip = env('REMOTE_ADDR');
    
    if (Config::get('in_maintenance') && !in_array($ip, Config::get('maintenance_ips', []))) {
        formr_error(503, 'Service Unavailable', 'This website is currently undergoing maintenance. Please try again later.', 'Maintenance Mode', false);
    }
}

function formr_in_console() {
	return php_sapi_name() === 'cli';
}

function formr_search_highlight($search, $subject) {
    return str_replace($search, '<span class="search-highlight">'.$search.'</span>', $subject);
}
