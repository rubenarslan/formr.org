<?php

class CURL {

    /**
     * constant for HttpRequest UserAgent string
     */
    const USERAGENT = 'formr-curl/1.0';

    /**
     * Encoding used by translit() call
     */
    const ENCODING = 'utf-8';

    /**
     * Array key used to retrieve response headers with HttpRequest from $info
     */
    const RESPONSE_HEADERS = "\theaders[]";

    /**
     * Array key name for previous headers in response
     */
    const RESPONSE_PREVIOUS_HEADERS = "\tprevious-headers[]";
    const HTTP_METHOD_GET = 'GET';
    const HTTP_METHOD_POST = 'POST';
    const HTTP_METHOD_HEAD = 'HEAD';
    const HTTP_METHOD_PUT = 'PUT';
    const HTTP_METHOD_DELETE = 'DELETE';

    /**
     * Download Filters
     */
    const DOWNLOAD_FILTERS = 'DOWNLOAD_FILTERS';
    const DOWNLOAD_FILTER_MAXSIZE = 'maxsize';
    const DOWNLOAD_FILTER_CONTENT_TYPE = 'content-type';
    const DOWNLOAD_FILTER_KEEP_LAST_MODIFIED = 'keep-last-modified';

    /**
     * Default cURL options.
     *
     * @var array
     */
    private static $curlOptions = array(
        // The number of seconds to wait while trying to connect. Use 0 to wait indefinitely.
        CURLOPT_CONNECTTIMEOUT => 20,
        // The maximum number of seconds to allow cURL functions to execute.
        CURLOPT_TIMEOUT => 120,
        // TRUE to return the transfer as a string of the return value of
        // curl_exec() instead of outputting it out directly.
        CURLOPT_RETURNTRANSFER => 1,
        // TRUE to follow any "Location: " header that the server sends as part
        // of the HTTP header (note this is recursive, PHP will follow as many
        // "Location: " headers that it is sent, unless CURLOPT_MAXREDIRS is
        // set).
        CURLOPT_FOLLOWLOCATION => true,
        // FALSE to stop cURL from verifying the peer's certificate.
        // Alternate certificates to verify against can be specified with the
        // CURLOPT_CAINFO option or a certificate directory can be specified
        // with the CURLOPT_CAPATH option. CURLOPT_SSL_VERIFYHOST may also need
        // to be TRUE or FALSE if CURLOPT_SSL_VERIFYPEER is disabled (it defaults to 2)
        CURLOPT_SSL_VERIFYPEER => true,
        // 1 to check the existence of a common name in the SSL peer certificate.
        // 2 to check the existence of a common name and also verify that it matches the hostname provided.
        CURLOPT_SSL_VERIFYHOST => 2,
        // The contents of the "User-Agent: " header to be used in a HTTP request.
        CURLOPT_USERAGENT => self::USERAGENT,
    );

    /**
     * Create cURL resource initialized with common options
     * Requires curl php extension to be present
     *
     * Parameters in POST method can be passed in two modes:
     * - application/x-www-form-urlencoded
     * - multipart/form-data
     * Default is 'multipart/form-data'
     *
     * While PHP can parse both modes just fine, certain servers accept only one. One such example is Twitter API.
     * To enforce encoding with 'application/x-www-form-urlencoded', pass parameters as string, like 'para1=val1&para2=val2&...'
     * See {@link http://php.net/manual/en/function.curl-setopt.php curl_setopt} for CURLOPT_POSTFIELDS.
     *
     * @static
     * @throws Exception
     * @param string $url url to request
     * @param array $params request parameters (GET or POST)
     * @param string $method http method (GET/POST)
     * @param array $options curl extra options
     * @param array $info = null curl_getinfo() results stored here
     * @return string content
     */
    public static function HttpRequest($url, $params = array(), $method = self::HTTP_METHOD_GET, $options = array(), &$info = null) {
        static $have_curl;
        if ($have_curl === null) {
            $have_curl = extension_loaded('curl');
        }
        if ($have_curl === false) {
            throw new Exception("cURL extension not loaded.");
        }

        $curl = curl_init();
        if ($curl === false) {
            throw new Exception("Unable to initialize cURL.");
        }

        if (!$options) {
            $options = array();
        }

        $curlConfigOptions = Config::get('curl', array());
        $curlConfigOptions += self::$curlOptions;
        $options += $curlConfigOptions;

        if ($method == self::HTTP_METHOD_POST) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $params;
        } elseif ($method === self::HTTP_METHOD_PUT) {
            $options[CURLOPT_RETURNTRANSFER] = true;
            $options[CURLOPT_CUSTOMREQUEST] = self::HTTP_METHOD_PUT;
        } elseif ($method == self::HTTP_METHOD_GET || $method == self::HTTP_METHOD_HEAD) {
            if ($method == self::HTTP_METHOD_GET) {
                $options[CURLOPT_HTTPGET] = true;
            } else if ($method == self::HTTP_METHOD_HEAD) {
                // make HEAD request
                $options[CURLOPT_NOBODY] = true;
                // enable header to capture response headers
                $options[CURLOPT_HEADER] = true;
            }

            if ($params) {
                if (!is_array($params)) {
                    // undefined how you pass key=value pairs with params being 'string'.
                    throw new LogicException("Can't do GET and params of type '" . gettype($params) . "', use POST instead");
                }
                $url = self::urljoin($url, $params);
            }
        }
        $options[CURLOPT_URL] = $url;
        curl_setopt_array($curl, $options);
//		curl_setopt($curl, CURLOPT_HTTPHEADER,array("Expect:"));
        $res = curl_exec($curl);
        $info = curl_getinfo($curl);

        if ($res === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new Exception("cURL error: {$error} in {$info['total_time']}");
        }
        curl_close($curl);

        // convert to array in case of headers wanted
        $return = $res;
        if (!empty($options[CURLOPT_HEADER])) {
            $info[self::RESPONSE_HEADERS] = self::chopHttpHeaders($res, $info);
            $return = substr($return, $info['header_size']);
        }

        return $return;
    }

    /**
     * Retrieve URL using cURL. Decodes resonse as JSON.
     *
     * @param string $url url to download
     * @param array $params request parameters (GET or POST)
     * @param string $method http method (GET/POST)
     * @param array $options curl extra options
     * @param array &$info curl_getinfo() results stored here
     * @param bool $assoc [optional] When true, returned objects will be converted into associative arrays.
     * @throws Exception when url can't be retrieved or json does not parse
     * @return object
     */
    public static function JsonRequest($url, $params = array(), $method = self::HTTP_METHOD_GET, $options = array(), &$info = null, $assoc = false) {
        $res = self::HttpRequest($url, $params, $method, $options, $info);

        $json = json_decode($res, $assoc);
        // we'll assume nobody wants to return NULL
        if ($json === null) {
            if (function_exists('json_last_error_msg')) {
                // PHP 5.5
                $error = json_last_error_msg();
            } elseif (function_exists('json_last_error')) {
                // PHP 5.3
                $error = 'error code ' . json_last_error();
            } else {
                $error = 'no more info available';
            }
            throw new Exception('Unable decode json response from [' . $url . '][http code ' . $info['http_code'] . ']: ' . $error);
        }

        return $json;
    }

    /**
     * Append parameters to URL. URL may already contain parameters.
     *
     * $quote_style = ENT_QUOTES for '&' to became '&amp;'
     * $quote_style = ENT_NOQUOTES for '&' to became '&'
     *
     * @static
     * @param $url
     * @param array $params
     * @param int $quote_style = ENT_NOQUOTES
     * @return string
     */
    public static function urljoin($url, $params = array(), $quote_style = ENT_NOQUOTES) {
        if ($params) {
            $amp = $quote_style == ENT_QUOTES ? '&amp;' : '&';
            $args = http_build_query($params, '', $amp);
            if ($args) {
                $q = strstr($url, '?') ? $amp : '?';
                $url .= $q . $args;
            }
        }

        return $url;
    }

    /**
     * 
     * @param string $filename
     * @param string $postname
     * @return mixed Returns a string if CURLFile class is not present else returns an instance of curl file
     *
     * @todo Detect mime type automatically
     */
    public static function getPostFileParam($filename, $postname = 'filename') {
        if (class_exists('CURLFile', false)) {
            return new CURLFile($filename, null, $postname);
        }
        return "@$filename";
    }

    /**
     * Download file from URL using cURL. The file is streamed so it does not occupy lots of memory for large downloads.
     * File is downloaded to temporary file and renamed on success. on failure temporary file is cleaned up.
     *
     * @param string $url url to download
     * @param string $output_file path there to save the result
     * @param Array $ params request parameters (GET or POST)
     * @param string $method http method (GET/POST)
     * @param array $options curl extra options
     * @param array &$info curl_getinfo() results stored here
     * @throws Exception
     */
    public static function DownloadUrl($url, $output_file, $params = array(), $method = self::HTTP_METHOD_GET, $options = array(), &$info = null) {
        $last_modified = null;

        // if content filters present check those first
        if (isset($options[self::DOWNLOAD_FILTERS])) {
            // make copy of filters, do not pass it in real HttpRequest
            $filters = $options[self::DOWNLOAD_FILTERS];
            unset($options[self::DOWNLOAD_FILTERS]);

            // pre-check with HEAD request
            self::HttpRequest($url, $params, self::HTTP_METHOD_HEAD, $options, $info);
            $file_header = $info[self::RESPONSE_HEADERS]['Content-Disposition'];

            // extract file name if exists
            if (preg_match('/filename\*?=.*?([\'"]?)([^;\'".]+)\1/', $file_header, $matches)) {
                $filename = $matches[2];
            } else {
                $filename = NULL;
            }

            // extract Last-Modified header to save timestamp later
            if (!empty($filters[self::DOWNLOAD_FILTER_KEEP_LAST_MODIFIED])) {
                if (isset($info[self::RESPONSE_HEADERS]['Last-Modified'])) {
                    $last_modified = strtotime($info[self::RESPONSE_HEADERS]['Last-Modified']);
                }
            }

            if (isset($filters[self::DOWNLOAD_FILTER_CONTENT_TYPE])) {
                if (empty($info['content_type'])) {
                    throw new Exception("Didn't get Content-Type from HEAD request");
                }
                if (!in_array($info['content_type'], $filters[self::DOWNLOAD_FILTER_CONTENT_TYPE])) {
                    throw new Exception("Wrong Content-Type: {$info['content_type']}");
                }
            }

            if (isset($filters[self::DOWNLOAD_FILTER_MAXSIZE])) {
                if (empty($info['download_content_length'])) {
                    throw new Exception("Didn't get Content-Length from HEAD request");
                }
                // TODO: handle -1
                // http://stackoverflow.com/questions/5518323/curl-getinfo-returning-1-as-content-length
                if ($info['download_content_length'] > $filters[self::DOWNLOAD_FILTER_MAXSIZE]) {
                    throw new Exception("File too large: {$info['download_content_length']} bytes");
                }
            }
        }

        $tmpfile = tempnam(dirname($output_file), basename($output_file));
        $fp = fopen($tmpfile, 'wb+');
        if ($fp == false) {
            throw new Exception("Failed to create: $tmpfile");
        }

        // set defaults for this method
        $options += array(
            CURLOPT_TIMEOUT => 10 * 60,
        );

        // override, must be this or the method will fail
        $options[CURLOPT_RETURNTRANSFER] = false;
        $options[CURLOPT_FILE] = $fp;

        $res = self::HttpRequest($url, $params, $method, $options, $info);
        $info['filename'] = $filename;
        
        if (fclose($fp) !== true) {
            throw new Exception("Unable to save download result");
        }

        if ($res !== true) {
            unlink($tmpfile);
            throw new Exception("Download error (in {$info['total_time']})");
        }

        // expect 2xx status code
        if ($info['http_code'] < 200 || $info['http_code'] > 300) {
            unlink($tmpfile);
            throw new Exception($url . ': bad statuscode ' . $info['http_code'], $info['http_code']);
        }

        // restore timestamp, if available
        if ($last_modified) {
            touch($tmpfile, $last_modified);
        }

        $rename = rename($tmpfile, $output_file);
        if ($rename !== true) {
            throw new Exception("Unable to rename temporary file");
        }
    }

    /**
     * Parse HTTP Headers out of cURL $response, it modifies $response while doing so
     *
     * Uses header splitter from
     * {@link http://www.sitepoint.com/forums/showthread.php?590248-Getting-response-header-in-PHP-cURL-request here}
     *
     * @param string $response
     * @param array $info
     * @return array
     */
    private static function chopHttpHeaders(&$response, &$info) {
        $headerstext = substr($response, 0, $info['header_size']);
        $info['raw_header'] = $headerstext;

        // 'download_content_length' is -1 for HEAD request
        if ($info['download_content_length'] >= 0) {
            $response = substr($response, -$info['download_content_length']);
        } else {
            $response = '';
        }
        $headersets = explode("\r\n\r\n", $headerstext);

        // http_parse_headers() php implementation from here:
        // http://php.net/manual/en/function.http-parse-headers.php
        $res = null;
        foreach ($headersets as $i => $headerset) {
            if (empty($headerset)) {
                continue;
            }

            $headerlist = explode("\r\n", $headerset);
            $headers = array();
            // fill status line as 'Status' header, it's identical to what 'Status' actual header would be used in CGI scripts
            $headers['Status'] = array_shift($headerlist);
            foreach ($headerlist as $line) {
                $line = rtrim($line);
                if (empty($line)) {
                    continue;
                }

                list($header, $value) = preg_split('/:\s*/', $line, 2);

                // Do Camel-Case to header name
                $header = str_replace(" ", "-", ucwords(strtolower(str_replace("-", " ", $header))));

                // add as array if duplicate
                if (isset($headers[$header])) {
                    $headers[$header] = array($headers[$header], $value);
                } else {
                    $headers[$header] = $value;
                }
            }

            if (isset($res)) {
                $headers[self::RESPONSE_PREVIOUS_HEADERS] = $res;
            }
            $res = $headers;
        }

        return $res;
    }

}
