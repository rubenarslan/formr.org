<?php

/**
 * Interface for the Open Sciecne Framework API
 * Class responsible for handling oauth and node operations
 *
 */

class OSF {

	/**
	 * OSF API entry point
	 *
	 * @var string
	 */
	protected $entry_point = 'https://api.osf.io/v2/';

	/**
	 * URI to exchange for access token
	 *
	 * @var string
	 */
	protected $token_uri = 'https://accounts.osf.io/oauth2/token';

	/**
	 * URL to redirect to for authorization
	 *
	 * @var string
	 */
	protected $authorization_uri = 'https://accounts.osf.io/oauth2/authorize';

	/**
	 * Application client id
	 *
	 * @var string
	 */
	protected $client_id;

	/**
	 * Application client secret
	 *
	 * @var string
	 */
	protected $client_secret;

	/**
	 * Access token object stored here after authorization
	 *
	 * @var string
	 */
	protected $access_token;

	protected $redirect_url;

	protected $scope;

	protected $state = 'formr-osf-ugaAJAuTlg';

	/**
	 * Are we authenticated from an https protocol
	 *
	 * @var boolean
	 */
	protected $is_https = false;

	/**
	 * Class can be initialized with a set of config parameters
	 *
	 * @param array $config
	 */
	public function __construct($config = array()) {
		if ($config && is_array($config)) {
			foreach ($config as $property => $value) {
				if (property_exists($this, $property)) {
					$this->{$property} = $value;
				}
			}
		}
	}

	/**
	 * Method to request access token, called from login()
	 * Extending Adapters may override this.
	 *
	 * @param array $params
	 * @return array
	 */
	protected function getAccessToken($params) {
		return (array )$this->fetch($this->token_uri, $params, CURL::HTTP_METHOD_POST);
	}

	/**
	 * Login method called from web with adapter specific parameters.
	 *
	 * After this method is called a valid session should be created that
	 * can be passed as parameter to profile() method.
	 *
	 * @param  $params
	 * @param  $token_params
	 * @return array parameters containing key => val pairs that uniquely identify the session
	 */
	public function login($params, array $token_params = array()) {
		if (!empty($params['error'])) {
			throw new OSF_Exception($params['error']);
		}

		if (empty($params['code'])) {
			throw new OSF_Exception("code is required");
		}

		// Set paramters tha are necessary for getting access token
		if (!$token_params) {
			$token_params = array(
				'client_id' => $this->client_id,
				'client_secret' => $this->client_secret,
				'grant_type' => 'authorization_code',
				'code' => $params['code'],
				'redirect_uri' => $this->getConnectUrl(),
			);
		}

		// pack params urlencoded instead of array, or curl will use multipart/form-data as Content-Type
		$token_params = http_build_query($token_params, null, '&');
		// TODO: To access API service after access token expired, need to get another access token with refresh token.
		$result = $this->getAccessToken($token_params);
		if (isset($result['expires'])) {
			// convert to timestamp, as relative expire has no use later
			$result['expires'] = time() + $result['expires'];
		} elseif (isset($result['expires_in'])) {
			// convert to timestamp, as relative expire has no use later
			$result['expires'] = time() + $result['expires_in'];
		}

		// save for debugging
		$result['code'] = $params['code'];

		return $result;
	}

	/**
	 * Fetch URL, decodes json response and checks for basic errors
	 *
	 * @throws OSF_Exception
	 * @param string $url
	 * @param array $params Url Parameters
	 * @param string $method GET or POST
	 * @param bool $json
	 * @return mixed json decoded data
	 */
	protected function fetch($url, $params = array(), $method = CURL::HTTP_METHOD_GET, $json = true, $curlopts = array()) {
		if (!$this->is_https) {
			$curlopts[CURLOPT_SSL_VERIFYHOST] = 0;
			$curlopts[CURLOPT_SSL_VERIFYPEER] = 0;
		}

		$content = CURL::HttpRequest($url, $params, $method, $curlopts);

		/** @var $result mixed */
		$result = null;
		if ($json) {
			$result = json_decode($content);
		} else {
			// For Adapters that don't return a json object but a query string
			parse_str($content, $result);
			$result = (object)$result;
		}

		if ($result === null) {
			$url = CURL::urljoin($url, $params);
			throw new OSF_Exception("Failed to parse response");
		}

		if (!empty($result->error)) {
			if (isset($result->error->subcode)) {
				throw new OSF_Exception($result->error->message, $result->error->code, $result->error->subcode, $result->error->type);

			} elseif (isset($result->error->code)) {
				throw new OSF_Exception($result->error->message, $result->error->code, 0, $result->error->type);

			} elseif (isset($result->error->type)) {
				throw new OSF_Exception($result->error->message, 0, 0, $result->error->type);

			} elseif (isset($result->error->description)) {
				throw new OSF_Exception($result->error->description);

			} elseif (isset($result->error_description)) {
				throw new OSF_Exception($result->error_description);

			} else {
				throw new OSF_Exception($result->error);
			}
		}

		return $result;
	}

	/**
	 * Get url where adapter should return on connecting.
	 *
	 * @return string
	 */
	public function getConnectUrl() {
		return $this->redirect_url;
	}

	/**
	 * URL to which user should be redirected to for login
	 * @return string
	 */
	public function getLoginUrl() {
		$url = CURL::urljoin($this->authorization_uri, array(
			'client_id' => $this->client_id,
			'scope' => $this->scope,
			'response_type' => 'code',
			'redirect_uri' => $this->getConnectUrl(),
			'state' => $this->state,
			'display' => 'popup',
		));
		return $url;
	}

	public static function getUserAccessToken(User $user) {
		$db = DB::getInstance();
		$row = $db->findRow('osf', array('user_id' => $user->id));
		if (!$row || $row['access_token_expires'] < time()) {
			return false;
		}
		return array(
			'access_token' => $row['access_token'],
			'expires' => $row['access_token_expires'],
		);
	}

	public static function setUserAccessToken(User $user, $token) {
		$db = DB::getInstance();
		$db->insert_update('osf', array(
			'user_id' => $user->id,
			'access_token' => $token['access_token'],
			'access_token_expires' => $token['expires'],
		), array(
			'access_token', 'access_token_expires'
		));
	}


}

class OSF_Exception extends Exception {}

