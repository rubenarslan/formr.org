<?php
/*
HELPER FUNCTIONS
*/
function formr_log($msg) {// shorthand
	error_log(  date( 'Y-m-d H:i:s' ).' ' . $msg. "\n", 3, INCLUDE_ROOT ."tmp/logs/formr_error.log");
}
function opencpu_log_warning($msg) {// shorthand
	error_log(  date( 'Y-m-d H:i:s' ).' ' . $msg. "\n", 3, INCLUDE_ROOT ."tmp/logs/opencpu_warning.log");
}
function opencpu_log($msg) {// shorthand
	error_log(  date( 'Y-m-d H:i:s' ).' ' . $msg. "\n", 3, INCLUDE_ROOT ."tmp/logs/opencpu_error.log");
}

function alert($msg, $class = 'alert-warning', $dismissable = true) // shorthand
{
	global $site;
	$site->alert($msg,$class, $dismissable);
}

function redirect_to($location) {
	global $site, $user;
	$_SESSION['site'] = $site;
	$_SESSION['user'] = serialize($user);
    if (strpos($location, 'index') !== false) {
        $location = '';
    }

	if(mb_substr($location,0,4)!= 'http'){
		$base = WEBROOT;
		if(mb_substr($location,0,1)=='/')
			$location = $base . mb_substr($location,1);
		else $location = $base . $location;
	}
	    header("Location: $location");
		exit;
}
function session_over($site, $user)
{
	static $closed;
	if($closed) return false;
	
	$_SESSION['site'] = $site;
	$_SESSION['user'] = serialize($user);

	session_write_close();
	$closed = true;
	return true;
}

function access_denied() {
	global $site,$user;
	$_SESSION['site'] = $site;
	$_SESSION['user'] = serialize($user);

    header('HTTP/1.0 403 Forbidden');
	require_once INCLUDE_ROOT."webroot/public/not_found.php";
	exit;
}
function not_found() {
	global $site,$user;
	$_SESSION['site'] = $site;
	$_SESSION['user'] = serialize($user);

    header('HTTP/1.0 404 Not Found');
	require_once INCLUDE_ROOT."webroot/public/not_found.php";
	exit;
}

function bad_request() {
	global $site,$user;
	$_SESSION['site'] = $site;
	$_SESSION['user'] = serialize($user);

    header('HTTP/1.0 400 Bad Request');
	require_once INCLUDE_ROOT."webroot/public/not_found.php";
	exit;
}
function bad_request_header() {
    header('HTTP/1.0 400 Bad Request');
}

function is_ajax_request() {
    return strtolower(env('HTTP_X_REQUESTED_WITH')) === 'xmlhttprequest';
}

function h($text) {
	return htmlspecialchars($text);
}

function debug($string) {
    if( DEBUG ) {
		echo "<pre>";
        print_r($string);
		echo "</pre>";
    }
}
function pr($string) {
    if( DEBUG > -1 ) {
		echo "<pre>";
        var_dump($string);
#		print_r(	debug_backtrace());
		echo "</pre>";
    }
}
if (!function_exists('_')) {
	function _($text) {
		return $text;
	}
}
function used_opencpu($echo = false)
{
	static $used;
	if($echo):
		pr($used);
		return;
	endif;
	if(isset($used)) $used++;
	else $used = 1;
}
function used_cache($echo = false)
{
	static $used;
	if($echo):
		pr($used);
		return;
	endif;
	if(isset($used)) $used++;
	else $used = 1;
}
function used_nginx_cache($echo = false)
{
	static $used;
	if($echo):
		pr($used);
		return;
	endif;
	if(isset($used)) $used++;
	else $used = 1;
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
			break;
		case 'PHP_SELF':
			return str_replace(env('DOCUMENT_ROOT'), '', env('SCRIPT_FILENAME'));
			break;
		case 'CGI_MODE':
			return (PHP_SAPI === 'cgi');
			break;
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
			break;
	}
	return null;
}

function emptyNull(&$x){
	$x = ($x=='') ? null : $x;
}
function stringBool($x)
{
	if($x===false) return 'false';
	elseif($x===true) return 'true';
	elseif($x===null)  return 'null';
	elseif($x===0)  return '0';
	else return $x;
}
function hardTrueFalse($x)
{
	if($x===false) return 'FALSE';
	elseif($x===true) return 'TRUE';
#	elseif($x===null)  return 'NULL';
	elseif($x===0)  return '0';
	else return $x;
}





if (!function_exists('http_parse_headers'))
{
    function http_parse_headers($raw_headers)
    {
        $headers = array();
        $key = ''; // [+]

        foreach(explode("\n", $raw_headers) as $i => $h)
        {
            $h = explode(':', $h, 2);

            if (isset($h[1]))
            {
                if (!isset($headers[$h[0]]))
                    $headers[$h[0]] = trim($h[1]);
                elseif (is_array($headers[$h[0]]))
                {
                    // $tmp = array_merge($headers[$h[0]], array(trim($h[1]))); // [-]
                    // $headers[$h[0]] = $tmp; // [-]
                    $headers[$h[0]] = array_merge($headers[$h[0]], array(trim($h[1]))); // [+]
                }
                else
                {
                    // $tmp = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [-]
                    // $headers[$h[0]] = $tmp; // [-]
                    $headers[$h[0]] = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [+]
                }

                $key = $h[0]; // [+]
            }
            else // [+]
            { // [+]
                if (mb_substr($h[0], 0, 1) == "\t") // [+]
                    $headers[$key] .= "\r\n\t".trim($h[0]); // [+]
                elseif (!$key) // [+]
                    $headers[0] = trim($h[0]); // [+]
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
	if($timestamp === false) return "";
    $age = time() - $timestamp;

    $future = ($age <= 0);
    $age = abs($age);

    $age = (int)($age / 60);        // minutes ago
    if ($age == 0) return $future ? "a moment" : "just now";

    $scales = [
        ["minute", "minutes", 60],
        ["hour", "hours", 24],
        ["day", "days", 7],
        ["week", "weeks", 4.348214286],     // average with leap year every 4 years
        ["month", "months", 12],
        ["year", "years", 10],
        ["decade", "decades", 10],
        ["century", "centuries", 1000],
        ["millenium", "millenia", PHP_INT_MAX]
    ];

    foreach ($scales as $scale) {
        list($singular, $plural, $factor) = $scale;
        if ($age == 0)
            return $future
                ? "less than 1 $singular"
                : "less than 1 $singular ago";
        if ($age == 1)
            return $future
                ? "1 $singular"
                : "1 $singular ago";
        if ($age < $factor)
            return $future
                ? "$age $plural"
                : "$age $plural ago";
        $age = (int)($age / $factor);
    }
}
// from http://de1.php.net/manual/en/function.filesize.php
function human_filesize($bytes, $decimals = 2) {
  $sz = 'BKMGTP';
  $factor = floor((strlen($bytes) - 1) / 3);
  return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
}

function cr2nl ($string)
{
	return str_replace("\r\n","\n",$string);
}

function time_point($line, $file) {
	static $times, $points;
	if(empty($times))
	{
		$times = array($_SERVER["REQUEST_TIME_FLOAT"]);
		$points = array("REQUEST TIME ". round($_SERVER["REQUEST_TIME_FLOAT"]/60,6));		
	}
	$took = $times[count($times)-1];
	$times[] = microtime(true);
	$took = round(($times[count($times)-1] - $took)/60, 6);
	$points[] = "took $took minutes to get to line ".$line." in file: ". $file;
	return $points;
}

function echo_time_points($points)
{
//	echo "<!---";
	for($i=0;$i<count($points); $i++):
		echo $points[$i]."<br>
";
	endfor;
	echo "took ".round((microtime(true)-$_SERVER["REQUEST_TIME_FLOAT"])/60,6). " minutes to the end";	
//	echo "--->";
}

function crypto_token($length)
{
	$base64 = base64_encode(openssl_random_pseudo_bytes($length, $crypto_strong));
	if(!$crypto_strong):
		alert("Generated cryptographic tokens are not strong.", 'alert-error');
		bad_request();
	endif;
	return $base64;
}