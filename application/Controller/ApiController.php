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
		redirect_to('public/index');
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

	protected function doAction(Request $request, $action) {
		try {
			$this->authenticate($action); // only proceed if authenticated, if not exit via response
			$method = $this->getPrivateAction($action, '-', true);
			$dao = new ApiDAO($request, $this->fdb);
			$data = $dao->{$method}()->getData();
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
