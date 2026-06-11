<?php

/*
  HELPER FUNCTIONS
 */

function formr_log($msg, $type = '')
{ // shorthand
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

function formr_log_exception(Throwable $e, $prefix = '', $debug_data = null)
{
    // Accept Throwable (not just Exception) so PHP Errors — type errors,
    // undefined methods, OOM-recoverable errors, etc. — can be logged
    // through the same path that already handles thrown Exceptions.
    $msg = $prefix . ' Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
    formr_log($msg);
    if ($debug_data !== null) {
        formr_log('Debug Data: ' . print_r($debug_data, 1));
    }
}

function get_log_file($filename)
{
    if (Config::get('error_to_stderr') == 1) {
        return "php://stderr";
    } else {
        return APPLICATION_ROOT . "tmp/logs/$filename";
    }
}

function alert($msg, $class = 'alert-warning', $dismissable = true)
{ // shorthand
    global $site;
    if (!is_object($site)) {
        $site = Site::getInstance();
    }
    $site->alert($msg, $class, $dismissable);
}

function notify_user_error($error, $public_message = '')
{
    $run_session = Site::getInstance()->getRunSession();
    $date = date('Y-m-d H:i:s');

    $message = $date . ': ' . $public_message . "<br>";

    // Show the actual error body whenever the viewer isn't a real
    // participant. That's: cron/queue daemons, test sessions, and
    // admin contexts that have no run_session at all (e.g. the
    // OverviewScriptPage render path). The participant-facing case
    // — a regular run_session that isn't cron and isn't testing —
    // still gets only the public_message, so internal error text
    // doesn't leak through a survey page. Matches the same logic
    // that drives $show_errors='TRUE' in the OpenCPU knit chunks.
    if (!$run_session || $run_session->isCron() || $run_session->isTesting()) {
        if ($error instanceof Exception) {
            $message .= opencpu_redact_secrets($error->getMessage());
        } else {
            $message .= opencpu_redact_secrets($error);
        }
    }
    alert($message, 'alert-danger');
}

function print_hidden_opencpu_debug_message($ocpu_req, $public_message = '')
{
    $run_session = Site::getInstance()->getRunSession();
    if ($run_session && !$run_session->isCron() && $run_session->isTesting()) {
        $date = date('Y-m-d H:i:s');

        $message = $date . ': ' . $public_message . "<br>";

        $message .= opencpu_debug($ocpu_req);
        alert($message, 'alert-info hidden_debug_message hidden');
    }
}

function redirect_to($location = '', $params = array())
{
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

function session_over($site, $user)
{
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

function formr_error($code = 500, $title = 'Bad Request', $text = 'Request could not be processed', $hint = null, $link = null, $link_text = null)
{
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

function formr_error_feature_unavailable()
{
    formr_error('503', 'Feature Unavailable', 'Sorry this feature is temporarily unavailable. Please try again later', '', 'javascript:history.back();', 'Go Back');
}

/**
 * Derive an HTML5 `pattern` attribute string from the configured
 * `user_code_regular_expression` PHP regex. Strips the delimiters,
 * any trailing flags, and the ^/$ anchors HTML5 auto-applies anyway.
 * Returns an empty string if the config is missing or shape we don't
 * recognize — caller can decide whether to omit the pattern attr or
 * fall back to a permissive default.
 */
function user_code_html_pattern()
{
    $regex = Config::get('user_code_regular_expression');
    if (!is_string($regex) || $regex === '') {
        return '';
    }
    if (!preg_match('@^([/~#])(.*)\1[a-zA-Z]*$@s', $regex, $m)) {
        return '';
    }
    $body = $m[2];
    if ($body !== '' && $body[0] === '^') {
        $body = substr($body, 1);
    }
    if ($body !== '' && substr($body, -1) === '$') {
        $body = substr($body, 0, -1);
    }
    return $body;
}

function h($text)
{
    if ($text === null) {
        return null;
    }

    return htmlspecialchars($text);
}

function debug($string)
{
    if (DEBUG) {
        echo "<pre>";
        print_r($string);
        echo "</pre>";
    }
}

function pr($string, $log = false)
{
    if (DEBUG > 0 && !$log) {
        echo "<pre>";
        var_dump($string);
        echo "</pre>";
    } else {
        formr_log(print_r($string, true));
    }
}

function prb($string = null)
{
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

    function _($text)
    {
        return $text;
    }
}

function used_opencpu($echo = false)
{
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

function used_cache($echo = false)
{
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

function used_nginx_cache($echo = false)
{
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
    function __($singular, $args = null)
    {
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

    function __n($singular, $plural, $count, $args = null)
    {
        if (!$singular) {
            return;
        }

        $translated = ngettext($singular, $plural, 6, $count);
        if ($args === null) {
            return $translated;
        } elseif (!is_array($args)) {
            $args = array_slice(func_get_args(), 3);
        }
        return vsprintf($translated, $args);
    }
}

function endsWith($haystack, $needle)
{
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
 * @link https://book.cakephp.org/2.0/en/core-libraries/global-constants-and-functions.html#env
 */
function env($key)
{
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
            return mb_substr($filename, 0, - (strlen($name) + $offset));
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

function emptyNull(&$x)
{
    $x = is_formr_truthy($x) ? $x : null;
}

function stringBool($x)
{
    if ($x === false) {
        return 'false';
    } elseif ($x === true) {
        return 'true';
    } elseif ($x === null) {
        return 'null';
    } elseif ($x === 0) {
        return '0';
    } elseif (is_array($x) and empty($x)) {
        return "NA";
    }

    return $x;
}

function hardTrueFalse($x)
{
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

    function http_parse_headers($raw_headers)
    {
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
function timetostr($timestamp)
{
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

// from https://de1.php.net/manual/en/function.filesize.php
function human_filesize($bytes, $decimals = 2)
{
    $sz = 'BKMGTP';
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
}

function cr2nl($string)
{
    return str_replace("\r\n", "\n", (string)$string);
}

function time_point($line, $file)
{
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

function echo_time_points($points)
{
    //	echo "<!---";
    for ($i = 0; $i < count($points); $i++):
        echo $points[$i] . "<br>
";
    endfor;
    echo "took " . round((microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) / 60, 6) . " minutes to the end";
    //	echo "--->";
}

function crypto_token($length, $url = true)
{
    return base64_url_encode(random_bytes($length));
}

function base64_url_encode($data)
{
    return strtr(base64_encode($data), '+/=', '-_~');
}

function base64_url_decode($data)
{
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
function url_title($str, $separator = '-', $lowercase = false)
{
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

function empty_column($col, $arr)
{
    $empty = true;
    $last = null;
    foreach ($arr as $row):
        if (
            !(empty($row->$col)) || // not empty column? (also treats 0 and empty strings as empty)
            $last != $row->$col || // any variation in this column?
            !(!is_array($row->$col) && trim((string)$row->$col) == '')
        ):
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
function get_run_dir_contents($dir)
{
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
 * @see https://php.net/manual/en/book.fileinfo.php
 *
 * @param string $filename
 * @return mixed Returns the mime type as a string or FALSE otherwise
 */
function get_file_mime($filename)
{
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
function download_file($file, $unlink = false)
{
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
function get_duplicate_update_string($columns)
{
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
function mysql_datetime($time = null)
{
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
function mysql_now()
{
    return mysql_datetime();
}

/**
 * Returns formatted strings equivalent to expressions like NOW() + INTERVAL 2 DAY
 *
 * @param string A string defining an interval accepted by PHP's strtotime() function
 * @return string
 */
function mysql_interval($interval)
{
    if (($time = strtotime($interval)) === false) {
        throw new Exception("Invalid time interval given to strtotime '$interval'");
    }
    return mysql_datetime($time);
}

function site_url($uri = '', $params = array())
{
    $url = WEBROOT;
    if ($uri) {
        // Remove any leading/trailing slashes from the URI
        $uri = rtrim($uri, '/');
        if ($uri) {
            $url .= $uri;
            // Only add trailing slash if there's no hash or query string or extension
            if (strpos($uri, '#') === false && strpos($uri, '?') === false && strpos($uri, '.') === false) {
                $url .= '/';
            }
        }
    }
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    return $url;
}

function api_base_url()
{
    $protocol = Config::get('protocol', 'https://');
    $api_domain = strtolower(trim((string) Config::get('api_domain', '')));
    $admin_domain = strtolower(trim((string) Config::get('admin_domain', '')));
    if ($api_domain !== '' && $api_domain !== $admin_domain) {
        // Dedicated API host: its vhost rewrites the root to route=api/...,
        // so the API lives at the domain root — appending /api would point
        // clients at api/api/... (#695).
        return rtrim($protocol . $api_domain, '/');
    }
    $domain = $api_domain !== '' ? $api_domain : $admin_domain;
    if (empty($domain)) {
        return rtrim(site_url('api'), '/');
    }
    return rtrim($protocol . $domain . '/api', '/');
}

function admin_url($uri = '', $params = array())
{
    if ($uri) {
        $uri = '/' . $uri;
    }
    return site_url('admin' . $uri, $params);
}

/**
 * Generate a URL for a run
 * 
 * @param string $name The name of the run
 * @param string $action The action to perform on the run
 * @param array $params Additional parameters to include in the URL
 * @return string The generated URL, always ends with a slash
 */
function run_url($name = '', $action = '', $params = array())
{
    if ($name === Run::TEST_RUN) {
        return site_url('run/' . $name . '/' . $action);
    }

    $protocol = Config::get('protocol');
    # use different domain for studies if set, independent of wildcard subdomain setting
    $domain = trim(Config::get('study_domain', ''), "*\/\\");
    $subdomain = null;

    if (Config::get('use_study_subdomains')) {
        $subdomain = strtolower($name);
    } else {
        $domain .= '/' . $name;
    }

    $url = $protocol . $subdomain . $domain;
    if ($action) {
        $action = trim($action, "\/\\");
        $url .= '/' . $action . '/';
    }
    $url = rtrim($url, "/") . "/";

    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

function admin_study_url($name = '', $action = '', $params = array())
{
    if ($action) {
        $name = $name . '/' . $action;
    }
    return admin_url('survey/' . $name, $params);
}

function admin_run_url($name = '', $action = '', $params = array())
{
    if ($action) {
        $name = $name . '/' . $action;
    }
    return admin_url('run/' . $name, $params);
}

/**
 * Determine session context and paths
 * 
 * @return array [
 *     'path' => string,
 *     'context' => string,
 *     'study_name' => string|null
 * ]
 */
function determine_session_context()
{
    $current_domain = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
    $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $path_segments = explode('/', trim($request_uri, '/'));
    $first_segment = !empty($path_segments[0]) ? $path_segments[0] : '';
    $admin_domain = Config::get('admin_domain');
    $study_domain = Config::get('study_domain');

    // Variables to return
    $session_path = "/";
    $session_context = "main";
    $study_name = null;
    $is_admin = false;

    // Determine if we're on admin path
    if ($first_segment === 'admin') {
        if (strpos($current_domain, $admin_domain) !== false) {
            $session_path = '/admin/';
            $session_context = 'admin';
            $is_admin = true;
        } else {
            redirect_to($admin_domain . $request_uri);
            exit;
        }
    } else {
        // Check if we're on study subdomain
        if (Config::get('use_study_subdomains') and strpos($current_domain, ".") !== false) {
            // Extract study name from subdomain (first part)
            $study_tld = explode('*.', Config::get('study_domain'))[1];
            $study_parts = explode('.', $current_domain, 2);
            $study_name = !empty($study_parts[0]) ? $study_parts[0] : '';

            if (strpos($current_domain, $study_tld) !== false && !empty($study_name)) {
                $session_path = '/';
                $session_context = 'study';
            }
        } else if (
            !empty($study_domain) && strpos($current_domain, $study_domain) !== false &&
            (empty($admin_domain) || strpos($current_domain, $admin_domain) === false) &&
            count($path_segments) > 0
        ) {
            // Extract study name from subdomain (first part)
            $study_name = $first_segment;

            if (!empty($study_name)) {
                $session_path = '/' . $first_segment . '/';
                $session_context = 'study';
                $study_name = $first_segment;
            }
        }
    }

    return [
        'path' => $session_path,
        'context' => $session_context,
        'study_name' => $study_name
    ];
}

/**
 * modified from https://stackoverflow.com/questions/118884/what-is-an-elegant-way-to-force-browsers-to-reload-cached-css-js-files?rq=1
 *  Given a file, i.e. /css/base.css, replaces it with a string containing the
 *  file's mtime, i.e. /css/base.1221534296.css.
 *  
 *  @param $file  The file to be loaded. Must not start with a slash.
 *  @param $add_mtime  Whether to add the mtime to the file name.
 */
function asset_url($file, $add_mtime = true)
{
    if (strpos($file, 'http') !== false || strpos($file, '//') === 0) {
        return $file;
    }
    if (strpos($file, 'assets') === false) {
        $file = 'assets/' . $file;
    }
    if ($add_mtime) {
        $mtime = @filemtime(APPLICATION_ROOT . "webroot/" . $file);
        if (!$mtime) {
            return site_url($file);
        }
        return site_url($file . "?v=" . $mtime);
    }
    return site_url($file);
}

function monkeybar_url($run_name, $action = '', $params = array())
{
    return run_url($run_name, 'monkey-bar/' . $action, $params);
}

function array_to_accordion($array)
{
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

function array_to_orderedlist($array, $olclass = null, $liclass = null)
{
    $ol = '<ol class="' . $olclass . '">';
    foreach ($array as $title => $label) {
        if (is_formr_truthy($label)) {
            $ol .= '<li title="' . $title . '" class="' . $liclass . '">' . $label . '</li>';
        }
    }
    $ol .= '</ol>';
    return $ol;
}

function is_formr_truthy($value)
{
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
function opencpu_define_vars(array $data, $context = null)
{
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
 * Retrieve an object from a previous OpenCPU session
 *
 * @param string $location A previous openCPU session location
 * @param string $return_format String like 'json'
 * @param bool $return_session Should OpenCPU_Session object be returned
 * @return string|OpenCPU_Session|null Returns null if an error occured so check the return value using the equivalence operator (===)
 */
function opencpu_get($location, $return_format = 'json', $return_session = false)
{
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
 * Prepares for an OpenCPU call that requires API authentication.
 * If the R code contains 'formr_api_authenticate', it creates a temporary access token for the run owner.
 *
 * @param string $code The R code to inspect.
 * @param array|null &$variables The array of variables to be passed to OpenCPU. This is passed by reference.
 * @return string|null The temporary access token to be deleted after use, or null if no token was created.
 */
function opencpu_prepare_api_access($code, &$variables)
{
    // Bridge participates in: opencpu_knit_iframe, opencpu_knit2html
    // wrappers (knitdisplay / knitadmin / knit_email).
    // NOT participating: opencpu_knit_plaintext / opencpu_evaluate.
    // Plaintext rendering is for short string interpolation (email
    // subjects, push titles, item label_parsed); live API authentication
    // in those slots is a misuse — a `formr_api_authenticate()` call in
    // an email subject won't have a place to put the result. The
    // omission is deliberate; if an author hits this they should move
    // the API call into the body chunk (knit_email path) instead.
    //
    // Match the actual call, not the bare identifier — a comment,
    // docstring, or string literal containing "formr_api_authenticate"
    // should not mint and immediately delete a one-shot OAuth token
    // for nothing.
    if (!preg_match('/\bformr_api_authenticate\s*\(/', (string) $code)) {
        return null;
    }

    $run_session = run_session(); // Get the current run session
    if (!$run_session) {
        // Try to get the run session from the Site instance for test runs where run_session() does not work.
        $run_session = Site::getInstance()->getRunSession();
    }

    $run = null;
    if ($run_session) {
        $run = $run_session->getRun();
    }
    if (!$run) {
        // Admin contexts (overview-script render, mockup, etc.) reach
        // this helper without a participant RunSession. Fall back to the
        // Site-level current run, which AdminRunController pushes when
        // it routes to a specific run. The embedded token is owner-scoped
        // and run-restricted, so this is safe — the admin can't reach
        // this code path without being authorized to view the run.
        $run = Site::getInstance()->getRun();
    }
    if (!$run) {
        return null;
    }

    $owner = $run->getOwner(); // Get the user object of the run owner
    if (!$owner || !$owner->id) {
        return null;
    }

    $oauth = OAuthHelper::getInstance();
    // Token lifetime is pinned to OpenCPU's `timelimit.post` (180s, set
    // in opencpu/conf/server.conf): that's the hard wall on any single
    // OpenCPU POST, so a token issued just before the POST can never
    // need to outlive 180s — anything longer is dead weight, anything
    // shorter risks the R session's last API call (after computation,
    // plotting, knitr chunks) hitting an already-expired token.
    // 120s was the original ceiling and turned out to be tight: overview
    // scripts that ran for >2min between formr_api_authenticate() and
    // a subsequent fetch 401'd on the fetch. The lifetime also functions
    // as a safety net for the failure modes that skip the explicit
    // delete (process crash, uncaught exception, OpenCPU timeout
    // leaving the request hung). External API consumers go through
    // the standard client_credentials grant and get the 1h default.
    // Stamp the token with a per-token run allowlist: this OpenCPU call
    // is operating in the context of exactly one run, so the embedded
    // R token has no reason to be able to touch any other run the owner
    // owns. Without $forRun, the token would inherit the owner's
    // per-client allowlist (commonly empty = unrestricted), which is
    // wider than what this short-lived helper needs.
    $token_data = $oauth->createAccessTokenForUser($owner, 'user:read session:read session:write run:read data:read', false, 180, $run);

    if (!$token_data || empty($token_data['access_token'])) {
        return null;
    }

    // Default bshaffer tokens are bin2hex(random_bytes(...)), so safe.
    // Defensive escape covers a future swap to a generator that might
    // include a single quote or backslash (JWT, custom, etc.) — without it
    // the token could break the R string literal we're embedding it in,
    // or worse, escape into surrounding R code.
    // Inject into the package's hidden `.formr` env (see
    // formr-r-package/R/shorthands.R) instead of polluting .GlobalEnv.
    // `host` in particular is a name widely used by httr/curl in user
    // code. The R-side helpers (formr_api_authenticate, formr_api_results)
    // read these from `.formr$` as their auto-pickup source.
    $api_host = rtrim(Config::get('api_internal_url', ''), '/') ?: api_base_url();
    $access_token = "'" . addcslashes($token_data['access_token'], "'\\") . "'";
    $host = "'" . addcslashes($api_host, "'\\") . "'";
    $run_name = "'" . addcslashes($run->name, "'\\") . "'";

    if (is_string($variables)) {
        // Append the R assignment to an existing string
        $variables .= "\n.formr\$access_token = " . $access_token . "\n.formr\$host = " . $host . "\n.formr\$run_name = " . $run_name . "\n";
    } else {
        if ($variables === null) {
            $variables = [];
        }
        $variables['.formr$access_token'] = $access_token;
        $variables['.formr$host'] = $host;
        $variables['.formr$run_name'] = $run_name;
    }

    return $token_data['access_token'];
}

/**
 * Get the custom R functions defined for the currently active run.
 * Relies on the Run model's instance-level cache so the file is read
 * at most once per request.
 */
function opencpu_custom_r()
{
    $run_session = run_session();
    if (!$run_session) {
        $run_session = Site::getInstance()->getRunSession();
    }

    $run = null;
    if ($run_session) {
        $run = $run_session->getRun();
    }
    if (!$run) {
        $run = Site::getInstance()->getRun();
    }
    if (!$run) {
        return '';
    }

    return $run->getCustomRFunctions();
}

/**
 * Get secrets defined for the currently active run.
 * Loaded from the survey_run_secrets table, decrypted via Crypto.
 *
 * @return array Associative array of [name => plaintext_value]
 */
function opencpu_secrets()
{
    $run_session = run_session();
    if (!$run_session) {
        $run_session = Site::getInstance()->getRunSession();
    }

    $run = null;
    if ($run_session) {
        $run = $run_session->getRun();
    }
    if (!$run) {
        $run = Site::getInstance()->getRun();
    }
    if (!$run) {
        return [];
    }

    return $run->getSecrets();
}

/**
 * Generate R code to inject secrets as .formr$ variables.
 *
 * Only injects secrets whose `.formr$secret_<name>` reference appears in
 * the R code, matching the conditional-injection pattern used by data
 * variables and API tokens. Secrets referenced via string construction
 * (e.g. `paste0(".formr$secret_", var)`) are not detected — use the
 * literal `.formr$secret_<name>` form.
 *
 * Secrets are assigned to the hidden .formr$ environment so they are
 * accessible in R as .formr$secret_<name> but do not appear in ls()
 * output or pollute the global scope.
 *
 * @param string $q The R code or R Markdown source to scan for secret references.
 * @param array|null $secrets Optional secrets array; if null loaded from run.
 * @return string R code assigning each referenced secret.
 */
function opencpu_inject_secrets($q, $secrets = null)
{
    if ($secrets === null) {
        $secrets = opencpu_secrets();
    }

    if (empty($secrets)) {
        return '';
    }

    $code = '';
    foreach ($secrets as $name => $value) {
        if (strpos((string) $q, ".formr\$secret_{$name}") === false) {
            continue;
        }
        // Escape quote/backslash AND newlines: a raw newline inside the
        // single-quoted R string is valid R, but in knit contexts a value
        // line starting with ``` would terminate the Rmd chunk early.
        // addcslashes turns the newline char into the two-character \n
        // sequence, which R's string parser converts back to a newline.
        $escaped = "'" . addcslashes((string) $value, "'\\\n\r") . "'";
        $code .= ".formr\$secret_{$name} = {$escaped}\n";
    }

    return $code;
}

/**
 * Build the R prelude injected into every evaluation/knit for the active
 * run: the run's custom R functions followed by any referenced secrets.
 *
 * The custom R code is wrapped in eval(parse(text = <json string>)) rather
 * than pasted raw: that keeps it a single statement (safe inside knitr
 * settings chunks, where a raw line starting with ``` would terminate the
 * chunk) and surfaces syntax errors as runtime errors with a clear message.
 * Must run AFTER library(formr) so top-level custom code can call formr
 * functions. Secrets are scanned for in both the caller's code and the
 * custom R itself, so helpers like
 * `call_api <- function() httr::GET(..., key = .formr$secret_api_key)`
 * trigger injection without the unit code repeating the literal.
 *
 * @param string $q The R code or R Markdown source about to be evaluated.
 * @return string R code to prepend (empty string if nothing to inject).
 */
function opencpu_run_prelude($q)
{
    $custom_r = opencpu_custom_r();
    $prelude = '';
    if ($custom_r) {
        $prelude .= 'eval(parse(text = ' . json_encode($custom_r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '))' . "\n";
    }
    $prelude .= opencpu_inject_secrets((string) $q . "\n" . (string) $custom_r);
    return $prelude;
}

/**
 * Redact known secret values from a string.
 *
 * Replaces every occurrence of each secret value with "[SECRET REDACTED]".
 * Only redacts values at least 6 characters long to avoid false positives
 * on short strings that may appear commonly in output.
 *
 * @param string $text Text to redact secrets from
 * @param array|null $known_secrets Optional pre-loaded secrets array; if null loaded from run
 * @return string Text with secret values replaced
 */
function opencpu_redact_secrets($text, $known_secrets = null)
{
    if ($known_secrets === null) {
        $known_secrets = opencpu_secrets();
    }

    $values = array_values($known_secrets);
    if (empty($values)) {
        return $text;
    }

    // Escaped variants too, not just the raw value. Debug output shows the
    // secret at several encoding depths: the R Markdown panel carries the
    // R single-quoted form written by opencpu_inject_secrets (' -> \'),
    // the Request panel carries that form JSON-encoded again into the
    // `text` param (\\' and \"), and request bodies can be URL-encoded.
    // Close over compositions of those escapers to depth 2 — a literal
    // match on the raw value alone misses every one of them.
    $transforms = [
        function ($s) { return addcslashes($s, "'\\\n\r"); }, // opencpu_inject_secrets form
        function ($s) { return addslashes($s); },              // OpenCPU_Request::__toString escapes ' " \
        function ($s) { return trim(json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '"'); },
        function ($s) { return rawurlencode($s); },
    ];
    $to_redact = [];
    foreach ($values as $v) {
        $v = (string) $v;
        // Minimum length guards against redacting ubiquitous short strings
        // (a secret of "1" would replace every digit 1 in the log).
        if (mb_strlen($v) < 6) {
            continue;
        }
        $variants = [$v];
        for ($depth = 0; $depth < 2; $depth++) {
            $next = $variants;
            foreach ($variants as $base) {
                foreach ($transforms as $t) {
                    $next[] = $t($base);
                }
            }
            $variants = array_unique($next);
        }
        $to_redact = array_merge($to_redact, $variants);
    }
    $to_redact = array_values(array_unique($to_redact));
    // longest first, so a deeper-escaped variant isn't partially mangled
    // by an earlier replacement of a shorter one it contains
    usort($to_redact, function ($a, $b) { return strlen($b) - strlen($a); });

    if (empty($to_redact)) {
        return $text;
    }

    return str_replace($to_redact, '[SECRET REDACTED]', $text);
}

/**
 * Truncate a result_log so it fits survey_unit_sessions.result_log
 * (MEDIUMTEXT, 16 MiB — see sql/patches/058_result_log_mediumtext.sql).
 * mb_strcut is byte-safe: it never splits a UTF-8 sequence.
 *
 * @param string|null $log
 * @return string|null
 */
function truncate_result_log($log)
{
    $max_bytes = 16777215; // MEDIUMTEXT
    if ($log !== null && mb_strlen($log, '8bit') > $max_bytes) {
        $log = mb_strcut($log, 0, $max_bytes);
    }
    return $log;
}

/**
 * Check whether R code is syntactically valid by calling base::parse() on OpenCPU.
 *
 * Runs R's built-in parser (which only checks syntax, not semantics).
 * The code is NOT executed — parse() returns an expression object.
 *
 * @param string $code The R code to validate
 * @return array{valid: bool|null, message: string}
 *               valid=true  → syntax is valid
 *               valid=false → syntax error, message contains the R error
 *               valid=null  → OpenCPU unreachable, message explains
 */
function opencpu_validate_r_code(string $code): array {
    if (trim($code) === '') {
        return ['valid' => true, 'message' => ''];
    }
    try {
        // OpenCPU parses urlencoded POST params as R code, so this JSON
        // string is read by R's parser as a string literal. PHP's default
        // \/ escape and \uXXXX surrogate pairs (for emoji etc.) are not
        // valid R escapes — both flags are load-bearing.
        $session = OpenCPU::getInstance()->post('/base/R/parse', ['text' => json_encode($code, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        if ($session->hasError()) {
            $msg = $session->getError();
            $msg = preg_replace('/^R Error: /', '', $msg);
            return ['valid' => false, 'message' => $msg];
        }
        return ['valid' => true, 'message' => ''];
    } catch (OpenCPU_Exception $e) {
        return ['valid' => null, 'message' => 'Could not contact OpenCPU server to validate R code.'];
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
function opencpu_evaluate($code, $variables = null, $return_format = 'json', $context = null, $return_session = false)
{
    if ($return_session !== true && ($result = shortcut_without_opencpu($code, $variables)) !== null) {
        return current($result);
    }

    $temp_token_to_delete = opencpu_prepare_api_access($code, $variables);

    $r_variables = is_string($variables) ? $variables : opencpu_define_vars($variables, $context);

    $r_prelude = opencpu_run_prelude($code);

    $params = ['x' => '{
(function() {
	library(formr)
	' . $r_prelude . '	' . $r_variables . '
	' . $code . '
})() }'];

    $uri = '/base/R/identity/' . $return_format;
    try {
        $session = OpenCPU::getInstance()->post($uri, $params);

        if ($session->hasError()) {
            throw new OpenCPU_Exception(opencpu_debug($session));
        } else {
            print_hidden_opencpu_debug_message($session, "OpenCPU debugger for run R code.");
        }

        return $return_session ? $session : ($return_format === 'json' ? $session->getJSONObject() : $session->getObject($return_format));
    } catch (OpenCPU_Exception $e) {
        notify_user_error($e, "There was a computational error.");
        opencpu_log($e);
        return null;
    } finally {
        if ($temp_token_to_delete) {
            OAuthHelper::getInstance()->deleteAccessToken($temp_token_to_delete);
        }
    }
}

/**
 * In one common, well-defined case, we just skip calling openCPU
 *
 * @param string code
 * @param array data for openCPU
 * @return mixed|null Returns null if things aren't simple, so check the return value using the equivalence operator (===)
 */
function shortcut_without_opencpu($code, $data)
{
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
 * @return OpenCPU_Session|string|null
 */
function opencpu_knit($code, $return_format = 'json', $self_contained = 1, $return_session = false)
{
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

function opencpu_knit_plaintext($source, $variables = null, $return_session = false, $context = null)
{
    if (!is_string($variables)) {
        $variables = opencpu_define_vars($variables, $context);
    }

    $run_session = Site::getInstance()->getRunSession();

    $show_errors = 'FALSE';
    $show_warnings = 'FALSE';
    if (!$run_session or $run_session->isTesting()) {
        $show_errors = 'FALSE';
        $show_warnings = 'TRUE';
    }

    $r_prelude = opencpu_run_prelude($source);

    $source = '```{r settings,warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F}
library(knitr); library(formr)
' . $r_prelude . '
opts_chunk$set(warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F,fig.height=7,fig.width=10)
opts_knit$set(base.url="' . OpenCPU::TEMP_BASE_URL . '")
' . $variables . '
```
' .
        $source;

    $result = opencpu_knit($source, 'json', 0, $return_session);

    // remove leading first new line
    $result = preg_replace('/^\n/', '', $result);

    return $result;
}

/**
 * Knit R markdown to HTML via the formr_inline_render endpoint.
 *
 * Plain HTTP wrapper around the OpenCPU call. Variable injection and
 * formr_api_authenticate() bridging are the caller's job — each of
 * opencpu_knitdisplay / opencpu_knitadmin / opencpu_knit_email bakes
 * its own settings chunk (with library(formr) + $variables) and runs
 * opencpu_prepare_api_access before doing so, which is the only ordering
 * where the injected .formr$ assignments can resolve.
 *
 * @param string $source The R Markdown source to be knitted.
 * @param string $return_format Desired output format ('json' for the JSON object, otherwise raw $return_format).
 * @param int $self_contained Passed through to rmarkdown::render.
 * @param bool $return_session If true, returns the OpenCPU_Session as-is so the caller can inspect ->hasError() itself.
 * @return OpenCPU_Session|mixed|string|null Knitted output, session, or null on error.
 */
function opencpu_knit2html($source, $return_format = 'json', $self_contained = 1, $return_session = false)
{
    $params = array('text' => "'" . addslashes($source) . "'", 'self_contained' => $self_contained);
    $uri = '/formr/R/formr_inline_render/' . $return_format;
    try {
        $session = OpenCPU::getInstance()->post($uri, $params);

        // Callers asking for the raw session expect to inspect ->hasError()
        // themselves (e.g. RunUnit::getParsedBody distinguishes "server
        // down" from "R error" by exactly this check). Return early before
        // the throw-on-error branch so a session-or-null contract is kept.
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
 * Render R Markdown source to an HTML iframe suitable for display via OpenCPU.
 *
 * This function prepares the R environment, handles YAML front matter, and injects variables.
 * It calls the `formr::formr_render` function in R.
 *
 * It also handles internal API access by creating and using a temporary token
 * if `formr_api_authenticate()` is found in the R code. The token is automatically
 * deleted after execution.
 *
 * @param string $source The R Markdown source code to be knitted.
 * @param array|string|null $variables Variables to define in the R environment before knitting.
 * @param bool $return_session If true, the full OpenCPU_Session object is returned instead of the JSON object.
 * @param string|null $context The name of a dataframe to attach for easier variable access in R.
 * @param string $description Optional HTML content to prepend to the rendered output.
 * @param string $footer_text Optional HTML content to append to the rendered output.
 * @return OpenCPU_Session|mixed|null Returns the JSON object (usually array) containing the iframe data, the Session object, or null on error.
 */
function opencpu_knit_iframe($source, $variables = null, $return_session = false, $context = null, $description = '', $footer_text = '')
{
    $temp_token_to_delete = opencpu_prepare_api_access($source, $variables);

    if (!is_string($variables)) {
        $variables = opencpu_define_vars($variables, $context);
    }

    $run_session = Site::getInstance()->getRunSession();

    $show_errors = 'FALSE';
    $show_warnings = 'FALSE';
    if (!$run_session or $run_session->isTesting()) {
        $show_errors = 'TRUE';
        $show_warnings = 'TRUE';
    }

    // Save the original source so we can scan it for secret references
    // before YAML extraction removes the frontmatter. Secret references in
    // YAML are not a realistic concern (YAML is markdown metadata, not R
    // code), but scanning the full original text is more defensive.
    $source_with_yaml = $source;

    $yaml = "";
    $yaml_lines = '/^\-\-\-/um';
    if (preg_match_all($yaml_lines, (string)$source) >= 2) {
        $parts = preg_split($yaml_lines, $source, 3);
        $yaml = "---" . $parts[1] . "---\n\n";
        $source = $parts[2];
    }

    $r_prelude = opencpu_run_prelude($source_with_yaml);

    // include=FALSE on the settings chunk: the chunk's R code still runs
    // (library() / opts_chunk$set() / variable assignments), but the chunk
    // source never lands in the rendered output. This matters because
    // $variables contains the .formr$access_token = '…' assignment that
    // opencpu_prepare_api_access injected — with echo=TRUE (the previous
    // mode in admin/test context) that token leaked into the rendered
    // iframe. include=FALSE also dominates over echo / warning / message,
    // so a stray warning during the variable assignment can't leak the
    // token either. opts_chunk$set on the next line still carries the
    // show_warnings setting forward to user chunks.
    $source = $yaml .
        '```{r settings,include=FALSE}
library(knitr); library(formr)
' . $r_prelude . '
opts_chunk$set(warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=' . $show_warnings . ',fig.height=7,fig.width=10)
' . $variables . '
```

' . $description . '

' . $source . "

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
    } finally {
        if ($temp_token_to_delete) {
            OAuthHelper::getInstance()->deleteAccessToken($temp_token_to_delete);
        }
    }
}

function opencpu_knitdisplay($source, $variables = null, $return_session = false, $context = null)
{
    // Bridge formr_api_authenticate() before stringifying $variables so the
    // .formr$ assignments land inside the settings chunk below, after
    // library(formr) and before the user's chunks run.
    $temp_token_to_delete = opencpu_prepare_api_access($source, $variables);
    if (!is_string($variables)) {
        $variables = opencpu_define_vars($variables, $context);
    }

    $run_session = Site::getInstance()->getRunSession();

    $show_errors = 'FALSE';
    $show_warnings = 'FALSE';
    if (!$run_session or $run_session->isTesting()) {
        $show_errors = 'TRUE';
        $show_warnings = 'TRUE';
    }

    $r_prelude = opencpu_run_prelude($source);

    $source = '```{r settings,warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F}
library(knitr); library(formr)
' . $r_prelude . '
opts_chunk$set(warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F,fig.height=7,fig.width=10)
opts_knit$set(base.url="' . OpenCPU::TEMP_BASE_URL . '")
' . $variables . '
```
' .
        $source;

    try {
        return opencpu_knit2html($source, 'json', 0, $return_session);
    } finally {
        if ($temp_token_to_delete) {
            OAuthHelper::getInstance()->deleteAccessToken($temp_token_to_delete);
        }
    }
}

function opencpu_knitadmin($source, $variables = null, $return_session = false)
{
    $temp_token_to_delete = opencpu_prepare_api_access($source, $variables);
    if (!is_string($variables)) {
        $variables = opencpu_define_vars($variables);
    }

    $run_session = Site::getInstance()->getRunSession();

    $show_errors = 'FALSE';
    $show_warnings = 'FALSE';
    if (!$run_session or $run_session->isTesting()) {
        $show_errors = 'TRUE';
        $show_warnings = 'TRUE';
    }

    $r_prelude = opencpu_run_prelude($source);

    $source = '```{r settings,warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F}
library(knitr); library(formr)
' . $r_prelude . '
opts_chunk$set(warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F)
opts_knit$set(base.url="' . OpenCPU::TEMP_BASE_URL . '")
' . $variables . '
```
' .
        $source;

    try {
        return opencpu_knit2html($source, 'json', 0, $return_session);
    } finally {
        if ($temp_token_to_delete) {
            OAuthHelper::getInstance()->deleteAccessToken($temp_token_to_delete);
        }
    }
}

function opencpu_knit_email($source, array $variables = null, $return_format = 'json', $return_session = false)
{
    $temp_token_to_delete = opencpu_prepare_api_access($source, $variables);
    if (!is_string($variables)) {
        $variables = opencpu_define_vars($variables);
    }
    $run_session = Site::getInstance()->getRunSession();

    $show_errors = 'FALSE';
    $show_warnings = 'FALSE';
    if (!$run_session or $run_session->isTesting()) {
        $show_errors = 'TRUE';
        $show_warnings = 'TRUE';
    }

    $r_prelude = opencpu_run_prelude($source);

    $source = '```{r settings,warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F}
library(knitr); library(formr)
' . $r_prelude . '
opts_chunk$set(warning=' . $show_warnings . ',message=' . $show_warnings . ',error=' . $show_errors . ',echo=F,fig.retina=2)
opts_knit$set(upload.fun=function(x) { paste0("cid:", URLencode(basename(x))) })
' . $variables . '
```
' .
        $source;

    try {
        return opencpu_knit2html($source, $return_format, 0, $return_session);
    } finally {
        if ($temp_token_to_delete) {
            OAuthHelper::getInstance()->deleteAccessToken($temp_token_to_delete);
        }
    }
}

function opencpu_string_key($index)
{
    return 'formr-ocpu-label-' . $index;
}

function opencpu_string_key_parsing($strings)
{
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
function opencpu_multistring_parse(UnitSession $unitSession, array $string_templates)
{
    $survey = $unitSession->runUnit->surveyStudy;
    $markdown = implode(OpenCPU::STRING_DELIMITER, $string_templates);
    $opencpu_vars = $unitSession->getRunData($markdown, $survey->name);
    $session = opencpu_knitdisplay($markdown, $opencpu_vars, true, $survey->name);

    if ($session && !$session->hasError()) {
        print_hidden_opencpu_debug_message($session, "OpenCPU debugger for dynamic values and showifs.");
        $parsed_strings = $session->getJSONObject();
        $strings = explode(OpenCPU::STRING_DELIMITER_PARSED, $parsed_strings);
        $strings = array_map("remove_tag_wrapper", $strings);
        return opencpu_string_key_parsing($strings);
    } else {
        // notify study admin
        $err = (string) opencpu_last_error();
        $message = 'OpenCPU knitting HTML failed in opencpu_multistring_parse: ' . $err;
        notify_study_admin($unitSession, $message, 'error');
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
function opencpu_substitute_parsed_strings(array &$array, array $parsed_strings)
{
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

function opencpu_multiparse_showif(UnitSession $unitSession, array $showifs, $return_session = false)
{
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

function opencpu_multiparse_values(UnitSession $unitSession, array $values, $return_session = false)
{
    $survey = $unitSession->runUnit->surveyStudy;
    $code = "(function() {with(tail({$survey->name}, 1), {\n";
    $code .= "list(\n" . implode(",\n", $values) . "\n)";
    $code .= "})})()\n";

    $variables = $unitSession->getRunData($code, $survey->name);
    return opencpu_evaluate($code, $variables, 'json', null, $return_session);
}

/**
 * Build a link that opens R code in the in-browser R fiddle
 * (https://fiddle.rforms.org, webR-based — runs entirely client-side).
 *
 * The code travels in the URL *fragment* (#code=<base64url>): browsers do
 * not send fragments to the server, so the snippet never reaches the
 * fiddle host or its logs, and fragments have no practical length budget.
 * Callers must pass already-redacted code — the URL lands in the admin's
 * browser history and clipboard.
 *
 * @param string $code The (redacted!) R or R Markdown source
 * @param string $lang Editor mode: 'r' or 'rmd'
 * @return string The fiddle URL
 */
function opencpu_fiddle_url($code, $lang = 'r')
{
    $base = rtrim((string) Config::get('r_fiddle_url', 'https://fiddle.rforms.org/'), '/');
    if ($base === '') {
        return '';
    }
    $b64url = rtrim(strtr(base64_encode((string) $code), '+/', '-_'), '=');
    return $base . '/?lang=' . rawurlencode($lang) . '#code=' . $b64url;
}

/**
 * Anchor tag for opencpu_fiddle_url(), or '' when the fiddle is disabled.
 * $code must already be secret-redacted.
 */
function opencpu_fiddle_link($code, $lang = 'r')
{
    $url = opencpu_fiddle_url($code, $lang);
    if ($url === '') {
        return '';
    }
    return '&nbsp;|&nbsp;<a href="' . h($url) . '" target="_blank" rel="noopener">Open in R Fiddle <i class="fa fa-external-link"></i></a>';
}

function opencpu_debug($session, OpenCPU $ocpu = null, $rtype = 'json')
{
    $debug = array();
    if (empty($session)) {
        $debug['Response'] = 'No OpenCPU_Session found. Server may be down.';
        if ($ocpu !== null) {
            $request = $ocpu->getRequest();
            $debug['Request'] = opencpu_redact_secrets((string) $request);
            $reponse_info = $ocpu->getRequestInfo();
            $debug['Request Headers'] = pre_htmlescape(print_r($reponse_info['request_header'], 1));
        }
    } else {

        try {
            $request = $session->getRequest();
            $params = $request->getParams();
            if (isset($params['text'])) {
                $rmd_source = opencpu_redact_secrets(stripslashes(substr($params['text'], 1, -1)));
                $debug['R Markdown'] = '
					<a href="#" class="download_r_code" data-filename="formr_rmarkdown.Rmd">Download R Markdown file to debug.</a>' . opencpu_fiddle_link($rmd_source, 'rmd') . '<br>
					<textarea class="form-control" rows="10" readonly>' . h($rmd_source) . '</textarea>';
            } elseif (isset($params['x'])) {
                $r_source = opencpu_redact_secrets(substr($params['x'], 1, -1));
                $debug['R Code'] = '
					<a href="#" class="download_r_code" data-filename="formr_values_showifs.R">Download R code file to debug.</a>' . opencpu_fiddle_link($r_source, 'r') . '<br>
					<textarea class="form-control" rows="10" readonly>' . h($r_source) . '</textarea>';
            }
            if ($session->hasError()) {
                $debug['Response'] = pre_htmlescape(opencpu_redact_secrets($session->getError()));
            } else {
                if (($files = $session->getFiles("knit.html"))) {
                    $iframesrc = $files['knit.html'];
                    $debug['Response'] = '
					<p>
						<a href="' . $iframesrc . '" target="_blank">Open in new window</a>
					</p>';
                } else if (isset($params['text']) || $rtype === 'text') {
                    $debug['Response'] = opencpu_redact_secrets(stringBool($session->getObject('text')));
                } else {
                    $debug['Response'] = pre_htmlescape(opencpu_redact_secrets(json_encode($session->getJSONObject(), JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE)));
                }
            }

            $urls = $session->getFiles();
            if (!$session->hasError() and !empty($urls)) {
                $locations = '';
                foreach ($urls as $path => $link) {
                    $path = str_replace('/ocpu/tmp/' . $session->getKey(), '', $path);
                    $locations .= "<a href='$link'>$path</a><br />";
                }
                $debug['Locations'] = $locations;
            }
            $debug['Session Info'] = pre_htmlescape(opencpu_redact_secrets($session->getInfo()));
            $debug['Session Console'] = pre_htmlescape(opencpu_redact_secrets($session->getConsole()));
            $debug['Session Stdout'] = pre_htmlescape(opencpu_redact_secrets($session->getStdout()));
            $debug['Request'] = pre_htmlescape(opencpu_redact_secrets((string) $request));

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

function opencpu_log($msg)
{
    $log = '';
    if ($msg instanceof Exception) {
        $log .= $msg->getMessage() . "\n" . $msg->getTraceAsString();
    } else {
        $log .= $msg;
    }
    error_log(opencpu_redact_secrets($log) . "\n", 3, get_log_file('opencpu.log'));
}

function opencpu_formr_variables($q)
{
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

/**
 * This function manages the OpenCPU session.
 * 
 * @param mixed $session Optional. If provided, sets the global $opencpu_session variable to this value.
 * @return mixed Returns the current value of the global $opencpu_session variable.
 */
function opencpu_session(OpenCPU_Session $session = null)
{
    global $opencpu_session;
    if ($session !== null) {
        $opencpu_session = $session;
    }
    return $opencpu_session;
}

function opencpu_last_error()
{
    global $opencpu_session;
    if ($opencpu_session !== null) {
        return opencpu_redact_secrets($opencpu_session->getError());
    }
    return null;
}

/**
 * This function gets or sets the current RunSession.
 * 
 * @param RunSession $runSession Optional. If provided, sets the global $run_session variable to this value.
 * @return RunSession Returns the current value of the global $run_session variable.
 */
function run_session(RunSession $runSession = null)
{
    global $run_session;
    if ($runSession !== null) {
        $run_session = $runSession;
    }
    return $run_session;
}

function pre_htmlescape($str)
{
    $str = (string) $str;
    return '<pre>' . htmlspecialchars($str) . '</pre>';
}

function array_val($array, $key, $default = "")
{
    if (!is_array($array)) {
        return false;
    }
    if (array_key_exists($key, $array)) {
        return $array[$key];
    }
    return $default;
}

function shutdown_formr_org()
{
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

function remove_tag_wrapper($text, $tag = 'p')
{
    $text = trim((string)$text);
    if (preg_match("@^<{$tag}>(.+)</{$tag}>$@", $text, $matches)) {
        $text = isset($matches[1]) ? $matches[1] : $text;
    }
    return $text;
}

function delete_tmp_file($file)
{
    // unlink tmp file especially for the case of google sheets
    if (!empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
        @unlink($file['tmp_name']);
    }
}

/**
 * Function to dwnload an excel sheet from google
 *
 * @param string $google_link The URL of the Google Sheet
 * @return array|boolean Returns an array similar to that of an 'uploaded-php-file' or FALSE otherwise;
 */
function google_download_survey_sheet($google_link)
{
    $google_id = google_get_sheet_id($google_link);
    if (!$google_id) {
        return false;
    }

    $destination_file = Config::get('survey_upload_dir') . '/googledownload-' . $google_id . '.xlsx';
    $google_download_link = "https://docs.google.com/spreadsheets/d/{$google_id}/export?format=xlsx";
    $info = array();

    try {
        if (!is_writable(dirname($destination_file))) {
            throw new Exception("The survey backup directory is not writable");
        }
        $options = array(
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            'DOWNLOAD_FILTERS' => 1
        );

        CURL::DownloadUrl($google_download_link, $destination_file, null, CURL::HTTP_METHOD_GET, $options, $info);
        if (empty($info['http_code']) || $info['http_code'] < 200 || $info['http_code'] > 302 || strstr($info['content_type'], "text/html") !== false) {
            $link = google_get_sheet_link($google_id);
            throw new Exception("The google sheet at {$link} could not be downloaded. Please make sure everyone with the link can access the sheet!");
        }

        if ($info['filename'] === NULL) {
            $link = google_get_sheet_link($google_id);
            throw new Exception("The google sheet at {$link} did not specify a name for the survey.");
        }

        $ret = array(
            'name' => $info['filename'] . '.xlsx',
            'tmp_name' => $destination_file,
            'filename' => $info['filename'],
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
function google_get_sheet_id($link)
{
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
function google_get_sheet_link($id)
{
    return "https://docs.google.com/spreadsheets/d/{$id}/edit";
}

function strt_replace($str, $params)
{
    foreach ($params as $key => $value) {
        $str = str_replace('%{' . $key . '}', $value, $str);
        $str = str_replace('{' . $key . '}', $value, $str);
    }
    return $str;
}

function fill_array($array, $value = '')
{
    foreach ($array as $key => $v) {
        $array[$key] = $value;
    }
    return $array;
}

function files_are_equal($a, $b)
{
    if (!file_exists($a) || !file_exists($b))
        return false;

    // Check if filesize is different
    if (filesize($a) !== filesize($b))
        return false;

    if (sha1_file($a) !== sha1_file($b))
        return false;

    return true;
}

function create_zip_archive($files, $destination, $overwrite = false)
{
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

function create_ini_file($assoc, $filepath)
{
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

function deletefiles($files)
{
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

function print_stylesheets($files, $id = null)
{
    foreach ($files as $i => $file) {
        $id = 'css-' . $i . $id;
        echo '<link href="' . asset_url($file) . '" rel="stylesheet" type="text/css" id="' . $id . '">' . "\n";
    }
}

function print_scripts($files, $id = null)
{
    foreach ($files as $i => $file) {
        $id = 'js-' . $i . $id;
        echo '<script src="' . asset_url($file) . '" id="' . $id . '"></script>' . "\n";
    }
}

function fwrite_json($handle, $data)
{
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

function do_run_shortcodes($text, $run_name, $sess_code)
{
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

function factortosecs($value, $unit)
{
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

function secstofactor($seconds)
{
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

function knitting_needed($source)
{
    if (!$source) {
        return false;
    }

    if (mb_strpos($source, '`r ') !== false || mb_strpos($source, '```{r') !== false) {
        return true;
    }

    return false;
}

function get_db_non_user_tables()
{
    return [
        'survey_users' => array("created", "modified", "user_code", "email", "email_verified", "mobile_number", "mobile_verified"),
        'survey_run_sessions' => array("session", "created", "ended", "last_access", "position", "current_unit_id", "deactivated", "no_email"),
        'survey_unit_sessions' => array("created", "ended", "expired", "unit_id", "position", "type"),
        'externals' => array("created", "ended", "position"),
        'survey_items_display' => array("created", "answered_time", "answered", "displaycount", "item_id"),
        'survey_email_log' => array("email_id", "created", "recipient"),
        'shuffle' => array("unit_id", "created", "group"),
    ];
}

function get_db_non_session_tables()
{
    return ['survey_users', 'survey_run_sessions', 'survey_unit_sessions'];
}

function formr_check_maintenance()
{
    $ip = env('REMOTE_ADDR');

    if (Config::get('in_maintenance') && !in_array($ip, Config::get('maintenance_ips', []))) {
        formr_error(503, 'Service Unavailable', 'This website is currently undergoing maintenance. Please try again later.', 'Maintenance Mode', false);
    }
}

function formr_in_console()
{
    return php_sapi_name() === 'cli';
}

function formr_search_highlight($search, $subject)
{
    return str_replace($search, '<span class="search-highlight">' . $search . '</span>', $subject);
}

function notify_study_admin(UnitSession $unitSession, string $message, string $type = 'error') {
    try {
        Notification::getInstance()->notifyStudyAdmin($unitSession, $message, $type);
    } catch (Exception $e) {
        // Don't let an admin-notification failure bubble up and break the
        // user-facing request, but do log it so silent breakage is at
        // least diagnosable in tmp/logs/errors.log.
        formr_log_exception($e, 'notify_study_admin');
    }
}

// Convert php.ini values to bytes
function convertToBytes($value)
{
    $value = trim($value);
    $lastChar = strtolower($value[strlen($value) - 1]);
    $value = (int) $value;

    switch ($lastChar) {
        case 'g':
            $value *= 1024;
        case 'm':
            $value *= 1024;
        case 'k':
            $value *= 1024;
    }

    return $value;
}

// check whether we're allowed to set anything but session cookies
function gave_functional_cookie_consent()
{
    if (isset($_COOKIE['formrcookieconsent']) && strstr($_COOKIE['formrcookieconsent'], '"necessary","functionality"')) {
        return true;
    }
    return false;
}
