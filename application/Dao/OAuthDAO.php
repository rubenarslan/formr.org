<?php

/**
 * This class acts as a Data Access Object for the OAuth2 Library
 * imported from https://github.com/bshaffer/oauth2-server-php
 */
class OAuthDAO {

	/**
	 * @var \OAuth2\Server
	 */
	protected $server;

	/**
	 *
	 * @var \OAuth2\Storage\Pdo
	 */
	protected $storage;

	/**
	 * @var array
	 */
	protected $config;

	/**
	 * @var OAuthDAO
	 */
	public static $instance;

	const DEFAULT_REDIRECT_URL = 'http://formr.org';

	public function __construct($config = array()) {
		$this->server = Site::getOauthServer();
		$this->storage = $this->server->getStorage('client');
		$this->config = array_merge(array(
			'client_table' => 'oauth_clients',
			'access_token_table' => 'oauth_access_tokens',
			'refresh_token_table' => 'oauth_refresh_tokens',
			'code_table' => 'oauth_authorization_codes',
			'user_table' => 'oauth_users',
			'jwt_table' => 'oauth_jwt',
			'jti_table' => 'oauth_jti',
			'scope_table' => 'oauth_scopes',
			'public_key_table' => 'oauth_public_keys',
				), $config);
	}

	public static function getInstance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function createClient(User $formrUser) {
		/* @var $storage \OAuth2\Storage\Pdo  */
		if (($client = $this->getClient($formrUser))) {
			return $client;
		}

		$details = $this->generateClientDetails($formrUser);
		$this->storage->setClientDetails($details['client_id'], $details['client_secret'], self::DEFAULT_REDIRECT_URL, null, null, $formrUser->email);
		return $this->getClient($formrUser);
	}

	public function getClient(User $formrUser) {
		$db = Site::getDb();
		$client_id = $db->findValue($this->config['client_table'], array('user_id' => $formrUser->email), 'client_id');
		if (!$client_id) {
			return false;
		}
		if (($client = $this->storage->getClientDetails($client_id))) {
			return $client;
		}
		return false;
	}

	public function deleteClient(User $formrUser) {
		$client = $this->getClient($formrUser);
		if (!$client) {
			return false;
		}

		$client_id = $client['client_id'];
		$db = Site::getDb();
		$db->delete($this->config['client_table'], array('client_id' => $client_id));
		$db->delete($this->config['access_token_table'], array('client_id' => $client_id));
		$db->delete($this->config['refresh_token_table'], array('client_id' => $client_id));
		$db->delete($this->config['code_table'], array('client_id' => $client_id));
		$db->delete($this->config['jwt_table'], array('client_id' => $client_id));
		return true;
	}

	public function refreshToken(User $formrUser) {
		$client = $this->getClient($formrUser);
		if (!$client) {
			return false;
		}
		$details = $this->generateClientDetails($formrUser, true);
		$client_id = $client['client_id'];
		$client_secret = $details['client_secret'];
		$this->storage->setClientDetails($client_id, $client_secret, self::DEFAULT_REDIRECT_URL, null, null, $formrUser->email);
		return compact('client_id', 'client_secret');
	}

	/**
	 * Generate client ID and client Secret from User object
	 *
	 * @todo Re-do the algorithm to create credentials
	 * @param User $formrUser
	 * @param bool $refresh
	 * @return array
	 */
	protected function generateClientDetails(User $formrUser, $refresh = false) {
		$jwt = new OAuth2\Encryption\Jwt();
		$append = $refresh ? microtime(true) : '';
		$client_id = md5($formrUser->id . $formrUser->email . $append);
		if ($refresh) {
			$client_id = $append . $formrUser->email . $formrUser->id;
		}
		$client_secret = substr(str_replace('.', '', $jwt->encode($client_id, $client_id)), 0, 60);
		return compact('client_id', 'client_secret');
	}

}
