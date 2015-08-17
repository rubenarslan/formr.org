<?php
/**
 * @todo
 * Wrap all public actions in try..catch construct
 */
class ApiController extends Controller {

	/**
	 * POST Request variables
	 *
	 * @var Request
	 */
	protected $post;

	/**
	 * GET Request variables
	 *
	 * @var Request
	 */
	protected $get;

	/**
	 * @var Response
	 */
	protected $response;

	/**
	 * A conainer to hold processed request's outcome
	 *
	 * @var array
	 */
	protected $data = array(
		'statusCode' => Response::STATUS_OK,
		'statusText' => 'OK',
		'response' => '',
	);

	/**
	 * Error information
	 *
	 * @var array
	 */
	protected $error = array();

	/**
	 * @var OAuth2\Server
	 */
	protected $oauthServer;

	public function __construct(Site &$site) {
		parent::__construct($site);
		$this->initialize();
	}

	public function indexAction() {
		redirect_to('public/index');
	}

	public function oauthAction($action = null) {
		if (!$this->isValidAction('oauth', $action)) {
			$this->response->badRequest('Invalid Auth Request');
		}

		$this->oauthServer = Site::getOauthServer();
		if ($action === 'authorize') {
			$this->authorize();
		} elseif ($action === 'token') {
			$this->token();
		}

		$this->response();
	}

	public function postAction($action = null) {
		if (!Request::isHTTPPostRequest()) {
			$this->response->badMethod();
		}

		if (!$this->isValidAction('post', $action)) {
			$this->response->badRequest('Invalid Post Request');
		}

		$this->doAction($action);
	}

	public function getAction($action = null) {
		if (!Request::isHTTPGetRequest()) {
			$this->response->badMethod();
		}

		if (!$this->isValidAction('get', $action)) {
			$this->response->badRequest('Invalid Get Request');
		}

		$this->doAction($action);
	}

	protected function doAction($action) {
		$this->request();
		$method = $this->getPrivateAction($action, '-', true);
		$this->{$method}();
		$this->response();
	}

	protected function isValidAction($type, $action) {
		$actions = array(
			'oauth' => array('authorize', 'token'),
			'post' => array('create-session', 'end-last-external'),
			'get' => array(),
		);

		return isset($actions[$type]) && in_array($action, $actions[$type]);
	}

	protected function createSession() {
		$this->initializeRun();
		$i = 0;
		$run_session = new RunSession($this->fdb, $this->run->id, null, null, null);
		$code = $this->post->getParam('code');

		if (!is_array($code)) {
			$code = array($code);
		}

		foreach ($code as $session) {
			if (($created = $run_session->create($session))) {
				$i++;
			}
		}

		if ($i) {
			$this->setData(Response::STATUS_OK, 'OK', array('created_sessions' => $i));
		} else {
			$this->setError(Response::STATUS_INTERNAL_SERVER_ERROR, 'Error occured when creating session');
			$this->setData(Response::STATUS_INTERNAL_SERVER_ERROR, 'Error Request', $this->error);
		}
	}

	protected function endLastExternal() {
		$this->initializeRun();
		if(($session_code = $this->post->getParam('session'))) {
			$run_session = new RunSession($this->fdb, $this->run->id, null, $session_code, null);

			if($run_session->session !== NULL) {
				$run_session->endLastExternal();
				$this->setData(Response::STATUS_OK, 'OK', array('success' => 'external unit ended'));
			} else {
				$this->setError(Response::STATUS_NOT_FOUND, 'Invalid Session Token');
				$this->setData(Response::STATUS_INTERNAL_SERVER_ERROR, 'Error Request', $this->error);
			}
		}

	}

	protected function authorize() {
		if (!Request::isHTTPPostRequest()) {
			$this->response->badMethod();
		}
		/*
		 * @todo
		 * Implement authorization under oauth
		 */
	}

	protected function token() {
		if (!Request::isHTTPPostRequest()) {
			$this->response->badMethod();
		}
		// Ex: curl -u testclient:testpass http://formr.org/api/oauth/token -d 'grant_type=client_credentials'
		$this->oauthServer->handleTokenRequest(OAuth2\Request::createFromGlobals())->send();
	}

	protected function request() {
		$this->oauthServer = Site::getOauthServer();
		// Handle a request to a resource and authenticate the access token
		// Ex: curl http://formr.org/api/post/action-name -d 'access_token=YOUR_TOKEN'
		if (!$this->oauthServer->verifyResourceRequest(OAuth2\Request::createFromGlobals())) {
			$this->setError(Response::STATUS_UNAUTHORIZED, 'Invalid/Unauthorized access token');
			$this->setData(Response::STATUS_UNAUTHORIZED, 'Error Request', $this->error);
			$this->response();
		}
	}

	protected function response() {
		$this->response->setStatusCode($this->data['statusCode'], $this->data['statusText']);
		$this->response->setContentType('application/json');
		$this->response->setJsonContent($this->data['response']);
		$this->response->send();
	}

	protected function initialize() {
		$this->post = new Request($_POST);
		$this->get = new Request($_GET);
		$this->response = new Response();
	}

	protected function initializeRun() {
		$run_name = $this->request->getParam('run_name');
		if (!$run_name) {
			alert('<strong>Error.</strong> Required "run_name" parameter not found!.', 'alert-danger');
		}

		$run = new Run($this->fdb, $run_name);
		if (!$run->valid) {
			$this->setError(Response::STATUS_NOT_FOUND, 'Invalid Run or run not found');
		} elseif (!$run->hasApiAccess($this->post->getParam('api_secret'))) {
			$this->setError(Response::STATUS_UNAUTHORIZED, 'Unauthorized access to run');
		}

		if ($this->error) {
			$this->setData(Response::STATUS_INTERNAL_SERVER_ERROR, 'Error Request', $this->error);
			return $this->response();
		}

		$this->run = $run;
		return true;
	}

	private function setData($statusCode = null, $statusText = null, $response = null) {
		if ($statusCode !== null) {
			$this->data['statusCode'] = $statusCode;
		}
		if ($statusText !== null) {
			$this->data['statusText'] = $statusText;
		}
		if ($response !== null) {
			$this->data['response'] = $response;
		}
	}

	private function setError($code = null, $error = null, $desc = null) {
		if ($code !== null) {
			$this->error['error_code'] = $code;
		}
		if ($error !== null) {
			$this->error['error'] = $error;
		}
		if ($desc !== null) {
			$this->error['error_description'] = $desc;
		}
	}

}
