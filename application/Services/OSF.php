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
    protected $api = 'https://api.osf.io/v2';

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
     * @var array
     */
    protected $access_token = array();
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
     * Set access token. Array should contain @access_token and @expires entries
     *
     * @param array $access_token
     */
    public function setAccessToken(array $access_token) {
        $this->access_token = $access_token;
    }

    /**
     * Method to request access token, called from login()
     * Extending Adapters may override this.
     *
     * @param array $params
     * @return array
     */
    protected function getAccessToken($params) {
        return (array) $this->fetch($this->token_uri, $params, CURL::HTTP_METHOD_POST);
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
        $token_params = http_build_query($token_params, '', '&');
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
        $curlopts += $this->curlOpts();

        $content = CURL::HttpRequest($url, $params, $method, $curlopts);

        /** @var $result mixed */
        $result = null;
        if ($json) {
            $result = json_decode($content);
        } else {
            // For Adapters that don't return a json object but a query string
            parse_str($content, $result);
            $result = (object) $result;
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

    /**
     * Upload a file under a particular OSF node
     *
     * @param string $node_id OSF node id
     * @param string $file absolute path to file
     * @param string $osf_file name of file on the OSF server
     * 
     * @uses access_token
     * @return OSF_Response
     * @throws OSF_Exception
     */
    public function upload($node_id, $file, $osf_file) {
        if (!file_exists($file)) {
            throw new OSF_Exception("Requested file not found");
        }

        $info = null;
        $params = array('format' => 'json', '_' => time());
        $url = $this->api . '/nodes/' . $node_id . '/files/';
        try {
            $files_json = CURL::HttpRequest($url, $params, CURL::HTTP_METHOD_GET, $this->curlOpts(), $info);
        } catch (Exception $e) {
            $files_json = $this->wrapError($e->getMessage());
        }

        $response = new OSF_Response($files_json, $info);
        if ($response->hasError()) {
            return $response;
        }

        $links = $response->getJSON()->data[0]->links;
        $curlopts = $this->curlOpts();
        $curlopts[CURLOPT_POSTFIELDS] = file_get_contents($file);
        $upload_url = $links->upload . '?' . http_build_query(array('kind' => 'file', 'name' => $osf_file));
        $uploaded = CURL::HttpRequest($upload_url, array('file' => CURL::getPostFileParam($file)), CURL::HTTP_METHOD_PUT, $curlopts, $info);

        return new OSF_Response($uploaded, $info);
    }

    /**
     * Retrieve project list of particular user
     *
     * @param string $user OSF id of that user. Defaults to 'me' for authenticated user
     * @return OSF_Response
     * @throws OSF_Exception
     */
    public function getProjects($user = 'me') {
        $params = array('format' => 'json');
        $info = null;

        try {
            // first get user information to obtain nodes_api for that user
            $url = $this->api . '/users/' . $user;
            $json = CURL::HttpRequest($url, $params, CURL::HTTP_METHOD_GET, $this->curlOpts(), $info);
            $userResponse = new OSF_Response($json, $info);
            if ($userResponse->hasError()) {
                return $userResponse;
            }
            $nodesApi = $userResponse->getJSON()->data->links->self . 'nodes/';

            // get project list from user's nodes
            $params['filter'] = array('category' => 'project');
            $json = CURL::HttpRequest($nodesApi, $params, CURL::HTTP_METHOD_GET, $this->curlOpts(), $info);
        } catch (Exception $e) {
            $json = $this->wrapError($e->getMessage());
        }

        return new OSF_Response($json, $info);
    }

    protected function curlOpts() {
        $curlopts = array();

        if (!$this->is_https) {
            $curlopts[CURLOPT_SSL_VERIFYHOST] = 0;
            $curlopts[CURLOPT_SSL_VERIFYPEER] = 0;
        }

        if ($this->access_token) {
            $curlopts[CURLOPT_HTTPHEADER] = array("Authorization: Bearer {$this->access_token['access_token']}");
        }
        return $curlopts;
    }

    private function wrapError($error) {
        return json_encode(array('errors' => array(array('detail' => $error))));
    }

}

class OSF_Response {

    protected $json;
    protected $json_string;
    protected $http_info = array();

    public function __construct($string, array $http_info = array()) {
        $this->json_string = $string;
        $this->json = @json_decode($string);
        $this->http_info = $http_info;
    }

    public function hasError() {
        if (!empty($this->json->errors)) {
            return true;
        }
        return isset($this->http_info['http_code']) && ($this->http_info['http_code'] < 200 || $this->http_info['http_code'] > 302);
    }

    public function getError() {
        if (!empty($this->json->errors)) {
            $err = array();
            foreach ($this->json->errors as $error) {
                $err[] = $error->detail;
            }
            return implode(".\n ", $err);
        }
        return isset($this->json->message) ? $this->json->message : null;
    }

    public function getErrorCode() {
        return isset($this->json->code) ? $this->json->code : null;
    }

    public function getJSON() {
        return $this->json;
    }

    public function getJSONString() {
        $this->json_string;
    }

    public function getHttpInfo() {
        return $this->http_info;
    }

}

class OSF_Exception extends Exception {
    
}
