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
	 * @var OAuth2\Server
	 */
	protected $oauthServer;

	public function __construct(Site &$site) {
		parent::__construct($site);
		$this->initialize();
	}

	public function indexAction() {
		$this->sendResponse(Response::STATUS_FORBIDDEN, 'Invalid', array('error' => 'No Entry Point'));
	}

	public function oauthAction($action = null) {
		if (!$this->isValidAction('oauth', $action)) {
			$this->response->badRequest('Invalid Auth Request');
		}

		$this->oauthServer = Site::getOauthServer();
		if ($action === 'authorize') {
			$this->authorize();
		} elseif ($action === 'access_token') {
			$this->access_token();
		}
	}

	public function postAction($action = null) {
		if (!Request::isHTTPPostRequest()) {
			$this->response->badMethod('Invalid Request Method');
		}

		if (!$this->isValidAction('post', $action)) {
			$this->response->badRequest('Invalid Post Request');
		}

		$this->doAction($this->post, $action);
	}

	public function getAction($action = null) {
		if (!Request::isHTTPGetRequest()) {
			$this->response->badMethod('Invalid Request Method');
		}

		if (!$this->isValidAction('get', $action)) {
			$this->response->badRequest('Invalid Get Request');
		}

		$this->doAction($this->get, $action);
	}

	public function osfAction($do = '') {
		$user = Site::getCurrentUser();
		if (!$user->loggedIn()) {
			alert('You need to login to access this section', 'alert-warning');
			redirect_to('login');
		}

		$osfconfg = Config::get('osf');
		$osfconfg['state'] = $user->user_code;

		$osf = new OSF($osfconfg);

		// Case 1: User wants to login to give formr authorization
		// If user has a valid access token then just use it (i.e redirect to where access token is needed
		if ($do === 'login') {
			$redirect = $this->request->getParam('redirect', 'admin/osf') . '#osf';
			if ($token = OSF::getUserAccessToken($user)) {
				// redirect user to where he came from and get access token from there for current user
				alert('You have authorized FORMR to act on your behalf on the OSF', 'alert-success');
			} else {
				Session::set('formr_redirect', $redirect);
				// redirect user to login link
				$redirect = $osf->getLoginUrl();
			}
			redirect_to($redirect);
		}

		// Case 2: User is oauth2-ing. Handle authorization code exchange
		if ($code = $this->request->getParam('code')) {
			if ($this->request->getParam('state') != $user->user_code) {
				throw new Exception("Invalid OSF-OAUTH 2.0 request");
			}

			$params = $this->request->getParams();
			try {
				$logged = $osf->login($params);
			} catch (Exception $e) {
				formr_log_exception($e, 'OSF');
				$logged = array('error' => $e->getMessage());
			}

			if (!empty($logged['access_token'])) {
				// save this access token for this user
				// redirect user to where osf actions need to happen (@todo pass this in a 'redirect session parameter'
				OSF::setUserAccessToken($user, $logged);
				alert('You have authorized FORMR to act on your behalf on the OSF', 'alert-success');
				if ($redirect = Session::get('formr_redirect')) {
					Session::delete('formr_redirect');
				} else {
					$redirect = 'admin/osf';
				}
				redirect_to($redirect);
			} else {
				$error = !empty($logged['error']) ? $logged['error'] : 'Access token could not be obtained';
				alert('OSF API Error: ' . $error, 'alert-danger');
				redirect_to('admin');
			}
		}

		// Case 3: User is oauth2-ing. Handle case when user cancels authorization
		if ($error = $this->request->getParam('error')) {
			alert('Access was denied at OSF-Formr with error code: ' . $error, 'alert-danger');
			redirect_to('admin');
		}

		redirect_to('index');
	}


	protected function doAction(Request $request, $action) {
		try {
			$this->authenticate($action); // only proceed if authenticated, if not exit via response
			$method = $this->getPrivateAction($action, '-', true);
			$helper = new ApiHelper($request, $this->fdb);
			$data = $helper->{$method}()->getData();
		} catch (Exception $e) {
			formr_log_exception($e, 'API');
			$data = array(
				'statusCode' => Response::STATUS_INTERNAL_SERVER_ERROR,
				'statusText' => 'Internal Server Error',
				'response' => array('error' => 'An unexpected error occured', 'error_code' => Response::STATUS_INTERNAL_SERVER_ERROR),
			);
		}

		$this->sendResponse($data['statusCode'], $data['statusText'], $data['response']);
	}

	protected function isValidAction($type, $action) {
		$actions = array(
			'oauth' => array('authorize', 'access_token'),
			'post' => array('create-session', 'end-last-external'),
			'get' => array('results'),
		);

		return isset($actions[$type]) && in_array($action, $actions[$type]);
	}

	protected function authorize() {
		/*
		 * @todo
		 * Implement authorization under oauth
		 */
		$this->response->badRequest('Not Implemented');
	}

	protected function access_token() {
		// Ex: curl -u testclient:testpass http://formr.org/api/oauth/token -d 'grant_type=client_credentials'
		$this->oauthServer->handleTokenRequest(OAuth2\Request::createFromGlobals())->send();
	}

	protected function authenticate($action) {
		$publicActions = array("end-last-external");
		if(!in_array($action, $publicActions) ) {
			$this->oauthServer = Site::getOauthServer();
			// Handle a request to a resource and authenticate the access token
			// Ex: curl http://formr.org/api/post/action-name -d 'access_token=YOUR_TOKEN'
			if (!$this->oauthServer->verifyResourceRequest(OAuth2\Request::createFromGlobals())) {
				$this->sendResponse(Response::STATUS_UNAUTHORIZED, 'Unauthorized Access', array(
					'error' => 'Invalid/Unauthorized access token',
					'error_code' => Response::STATUS_UNAUTHORIZED,
					'error_description' => 'Access token for this resouce request is invalid or unauthorized',
				));
			}
		}
	}

	protected function sendResponse($statusCode = Response::STATUS_OK, $statusText = 'OK', $response = null) {
		$this->response->setStatusCode($statusCode, $statusText);
		$this->response->setContentType('application/json');
		$this->response->setJsonContent($response);
		$this->response->send();
	}

	protected function initialize() {
		$this->post = new Request($_POST);
		$this->get = new Request($_GET);
		$this->response = new Response();
	}

}
