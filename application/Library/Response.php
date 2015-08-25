<?php

class Response {

	/** HTTP status codes */
	const STATUS_OK						= 200;
	const STATUS_CREATED				= 201;
	const STATUS_NO_CONTENT				= 204;
	const STATUS_NOT_MODIFIED			= 304;
	const STATUS_TEMPORARY_REDIRECT		= 307;
	const STATUS_PERMANENT_REDIRECT		= 308;
	const STATUS_BAD_REQUEST			= 400;
	const STATUS_UNAUTHORIZED			= 401;
	const STATUS_FORBIDDEN				= 403;
	const STATUS_NOT_FOUND				= 404;
	const STATUS_METHOD_NOT_ALLOWED		= 405;
	const STATUS_UNPROCESSABLE_ENTITY	= 422;
	const STATUS_INTERNAL_SERVER_ERROR	= 500;
	const STATUS_SERVICE_UNAVAILABLE	= 503;
	const STATUS_GATEWAY_TIMEOUT		= 504;

	/**
	 * @var string
	 */
	protected $content;

	/**
	 * @var string
	 */
	protected $contentType;

	/**
	 * @var array
	 */
	protected $config;

	/**
	 * @param array $config
	 */
	public function __construct($config = array()) {
		$this->config = $config;
	}

	/**
	 * Sets the response status code.
	 *
	 * @param integer $code HTTP status code
	 * @param mixed $text HTTP status text
	 * @return Response
	 */
	public function setStatusCode($code, $text = null) {
		$code = (int) $code;
		$text = $text ? $text : "Status $code";
		// status
		header(sprintf('HTTP/1.0 %s %s', $code, $text));

		return $this;
	}

	/**
	 * Resource not found
	 * @return void
	 */
	public function notFound() {
		$this->setStatusCode(self::STATUS_NOT_FOUND, 'Resource Not Found');
		$this->setHeader('Pragma', 'no-cache');
		exit;
	}

	/**
	 * Bad request: may be cached, it won't improve if fetched again
	 *
	 * @param $message string
	 * @return void
	 */
	public function badRequest($message = "") {
		$this->setStatusCode(self::STATUS_BAD_REQUEST, 'Bad Request');
		if ($message) {
			echo "<h1>{$message}</h1>";
		}
		exit;
	}

	/**
	 * Not modified: the request was verified to be identical with previous cache
	 *
	 * @param $etag
	 * @return void
	 */
	public function notModified($etag) {
		// if not modified, save some cpu and bandwidth
		$this->setStatusCode(self::STATUS_NOT_MODIFIED, 'Not Modified');
		$this->setEtag($etag);
		exit;
	}

	/**
	 * Forbidden, access to this resource is not allowed
	 *
	 * @return void
	 */
	public function forbidden() {
		$this->setStatusCode(self::STATUS_FORBIDDEN, 'Forbidden');
		exit;
	}

	/**
	 * Internal/Fatal Error
	 *
	 * @return void
	 */
	public function fatalError() {
		$this->setStatusCode(self::STATUS_INTERNAL_SERVER_ERROR, 'Internal Error');
		exit;
	}

	/**
	 * Gateway timeout
	 *
	 * @return void
	 */
	public function gatewayTimeout() {
		$this->setStatusCode(self::STATUS_GATEWAY_TIMEOUT, 'Gateway Timeout');
		exit;
	}

	public function badMethod($message = '') {
		$this->setStatusCode(self::STATUS_METHOD_NOT_ALLOWED, 'Method Not Allowed');
		if ($message) {
			echo "<h1>{$message}</h1>";
		}
		exit;
	}

	/**
	 * Set Content-Type HTTP header
	 *
	 * @param string $content_type
	 * @return Response
	 */
	public function setContentType($content_type) {
		$this->contentType = $content_type;
		return $this->setHeader('Content-Type', $content_type);
	}

	/**
	 * Get Content-Type HTTP header
	 *
	 * @return string
	 * @since 1.3
	 */
	public function getContentType() {
		return $this->contentType;
	}

	/**
	 * Set Content-Length HTTP header
	 *
	 * @param int $length
	 * @return Response
	 */
	public function setContentLength($length) {
		return $this->setHeader('Content-Length', $length);
	}

	/**
	 * Sets the response content.
	 *
	 * Valid types are strings, numbers, and objects that implement a __toString() method.
	 *
	 * @param mixed $content
	 * @return Response
	 * @throws UnexpectedValueException
	 */
	public function setContent($content) {
		if (null !== $content && !is_string($content) && !is_numeric($content) && !is_callable(array($content, '__toString'))) {
			throw new UnexpectedValueException('The Response content must be a string or object implementing __toString(), "' . gettype($content) . '" given.');
		}

		$this->content = (string) $content;

		return $this;
	}

	/**
	 * Set JSON response content
	 *
	 * @param mixed $content Can be of any type except a resource.
	 * @return Response
	 * @throws UnexpectedValueException
	 */
	public function setJsonContent($content) {
		if (is_string($content)) {
			return $this->setContent($content);
		}

		if (!$content = json_encode($content)) {
			throw new UnexpectedValueException('The Response content cannot be json encoded');
		}

		return $this->setContent($content);
	}

	/**
	 * Gets the current response content.
	 *
	 * @return string Content
	 */
	public function getContent() {
		return $this->content;
	}

	/**
	 * Sets the ETag value.
	 *
	 * @param string $etag The ETag unique identifier
	 * @return Response
	 */
	public function setEtag($etag) {
		return $this->setHeader('Etag', '"' . $etag . '"');
	}

	/**
	 * Set HTTP Response header.
	 *
	 * @param string $header
	 * @param string $value
	 * @param bool $replace
	 * @return Response
	 */
	public function setHeader($header, $value, $replace = true) {
		header("$header: $value", $replace);

		return $this;
	}

	/**
	 * Marks the response as "private".
	 * It makes the response ineligible for serving other clients.
	 *
	 * @return Response
	 */
	public function setPrivate() {
		return $this->setHeader('Cache-Control', 'private');
	}

	/**
	 * Send caching header
	 *
	 * @param string $duration Any string supported my PHP's strtotime() function (http://php.net/manual/en/function.strtotime.php)
	 * @param bool $public
	 * @return Response
	 */
	public function setCacheHeaders($duration, $public = true) {
		$time = strtotime($duration);
		if (!$time) {
			return $this;
		}

		$max_age = $time - time();
		$cache = $public ? 'public' : 'private';
		return $this->setHeader('Cache-Control', $cache . ', max-age=' . $max_age);
	}

	/**
	 * Send out Response content.
	 * Note that this ends the request
	 *
	 * @return void
	 */
	public function send() {
		if ($this->content) {
			echo $this->content;
		}
		exit;
	}

}
