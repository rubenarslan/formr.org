<?php

class Cookie {


	protected $data = array();

	protected $name;

	protected $value;

	protected $filename;

	protected $file;

	protected $isBrowserCookie = true;

	const REQUEST_TOKENS = '_formr_request_tokens';
	const REQUEST_USER_CODE = '_formr_code';
	const REQUEST_NAME = '_formr_cookie';

	public function __construct($name, $file = null) {
		$this->name = $name;
		$clear = false;

		if ($file) {
			$this->file = md5($file);
		} elseif (isset($_COOKIE[$name])) {
			$this->file = $_COOKIE[$name];
		} elseif (isset($_POST[self::REQUEST_NAME])) {
			// Load cookie from POSTed data if browser cookie already expired
			// to avoid data loss on submission
			$this->file = $_POST[self::REQUEST_NAME];
			$clear = true; // cookie not sent by browser but by post request
			$this->isBrowserCookie = false;
		} else {
			$this->file = md5(crypto_token(48));
		}

		$this->filename = APPLICATION_ROOT . 'tmp/sessions/sess_' . $this->file . '.json';
		if ($this->exists()) {
			$this->data = $this->getData();
		}
		if ($clear) {
			//$this->deleteFile();
		}
	}

	public function create($data, $expire = 0, $path = '/', $domain = null, $secure = false, $httponly = false) {
		if (file_exists($this->filename)) {
			unset($data['created'], $data['expires']);
			return $this->saveData($data);
		}
		$this->saveData($data);
		return $this->set($expire, $path, $domain, $secure, $httponly);
	}

	public function saveData($data) {
		if (!file_exists($this->filename) && !isset($data['expires'])) {
			return;
		}

		$this->data = array_merge($this->data, $data);
		$this->data['modified'] = time();
		return file_put_contents($this->filename, json_encode($this->data, JSON_PRETTY_PRINT));
	}

	public function getData($field = null, $default = null) {
		if (!$this->data && file_exists($this->filename)) {
			$contents = file_get_contents($this->filename);
			$this->data = @json_decode($contents, true);
		}

		if ($field !== null) {
			return array_val($this->data, $field, $default);
		} else {
			return $this->data;
		}
	}

	public function clearData() {
		$this->data = array();
	}

	public function exists() {
		return isset($_COOKIE[$this->name]) || isset($_POST[self::REQUEST_NAME]);
	}

	public function set($expire = 0, $path = '/', $domain = null, $secure = false, $httponly = false) {
		return setcookie($this->name, $this->file, $expire, $path, $domain, $secure, $httponly);
	}

	public function destroy() {
		setcookie($this->name, '', time() - 3600);
		$this->deleteFile();
	}

	public function isExpired() {
		if (empty($this->data['expires'])) {
			return false;
		}

		return $this->data['expires'] < time();
	}

	public function getRequestToken() {
		$token = sha1(mt_rand());
		if (!$tokens = $this->getData(self::REQUEST_TOKENS)) {
			$tokens = array($token => 1);
		} else {
			$tokens[$token] = 1;
		}

		$this->saveData(array(self::REQUEST_TOKENS => $tokens));
		return $token;
	}

	public function canValidateRequestToken(Request $request) {
		$token = $request->getParam(self::REQUEST_TOKENS);
		$tokens = $this->getData(self::REQUEST_TOKENS, array());
		//$cookie = $request->getParam(self::REQUEST_NAME);
		$code = $request->getParam(self::REQUEST_USER_CODE);
		$validated = false;

		if (!$this->isBrowserCookie) {
			$validated = $code == $this->getData('code') && !empty($tokens[$token]);
			error_log('Validated: ' . $validated);
			error_log('POSTed cookie: ' . print_r($this->getData(), 1));
		} else {
			$validated = !empty($tokens[$token]);
			if ($validated) {
				unset($tokens[$token]);
				$this->saveData(array(self::REQUEST_TOKENS => $tokens));
			}
			error_log('Browser cookie: ' . print_r($this->getData(), 1));
		}

		return $validated;	
	}

	public function getName() {
		return $this->name;
	}

	public function getFile() {
		return $this->file;
	}

	public function deleteFile() {
		if (file_exists($this->filename)) {
			@unlink($this->filename);
		}
	}

}

