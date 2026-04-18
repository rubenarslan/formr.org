<?php

/**
 * @todo
 * Wrap all public actions in try..catch construct
 */
class ApiController extends Controller
{

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

    protected $unrestrictedActions = ['end-last-external'];

    public function __construct(Site &$site)
    {
        parent::__construct($site);
        $this->initialize();
    }

    /**
     * Main API Entry Point.
     * * Determines if the request targets the V1 REST API or falls back to legacy behavior.
     *
     * @param string|null $resource The requested resource (or 'v1').
     * @param string|null $version  The version string (optional).
     * @return mixed Response object or void.
     */
    public function indexAction($resource = null, $version = null)
    {
        if ($version === 'v1' && $resource) {
            // Pass the rest of the arguments
            $args = array_slice(func_get_args(), 2);
            return $this->dispatchV1($resource, $args);
        }

        // Default behavior (Legacy 403)
        $this->respond(Response::STATUS_FORBIDDEN, 'Forbidden', array(
            'code' => Response::STATUS_FORBIDDEN,
            'message' => 'No valid API entry point found'
        ));
    }

    /**
     * V1 API Dispatcher.
     * * Authenticates the user, instantiates the V1 Helper, and dynamically calls 
     * the requested resource method. Wraps execution in a global try/catch for JSON error handling.
     *
     * @param string $resource The API resource to invoke (e.g., 'user', 'runs').
     * @param array $arguments Additional arguments passed from the router.
     * @return void Sends a JSON response.
     */
    private function dispatchV1($resource, $arguments = [])
    {
        $this->authenticate($resource);

        try {
            $token_data = $this->oauthServer->getAccessTokenData(OAuth2\Request::createFromGlobals());

            if (!class_exists('ApiHelperV1')) {
                throw new Exception("V1 API Helper not installed.");
            }

            $helper = new ApiHelperV1($this->site->request, $this->fdb, $token_data);

            if (!method_exists($helper, $resource)) {
                $this->respond(404, 'Not Found', ['error' => "Resource '$resource' not found in V1 API."]);
                return;
            }

            // Execute the helper method
            $helperResult = $helper->$resource(...$arguments);
            $data = $helperResult->getData();
        } catch (Exception $e) {
            formr_log_exception($e, 'API-V1-Dispatcher');
            $data = [
                'statusCode' => 500,
                'statusText' => 'Internal Server Error',
                'response' => ['message' => $e->getMessage()],
            ];
        }

        $this->respond($data['statusCode'], $data['statusText'], $data['response']);
    }

    public function oauthAction($action = null)
    {
        if (!$this->isValidAction('oauth', $action)) {
            $this->response->badRequest('Invalid Auth Request');
        }

        $this->oauthServer = Site::getOauthServer();
        if ($action === 'authorize') {
            $this->authorize();
        } elseif ($action === 'access_token') {
            $this->access_token();
        } elseif ($action === 'delete_token') {
            $this->delete_token();
        }
    }

    public function postAction($action = null)
    {
        if (!Request::isHTTPPostRequest()) {
            $this->response->badMethod('Invalid Request Method');
        }

        if (!$this->isValidAction('post', $action)) {
            $this->response->badRequest('Invalid Post Request');
        }

        $this->doAction($this->post, $action);
    }

    public function getAction($action = null)
    {
        if (!Request::isHTTPGetRequest()) {
            $this->response->badMethod('Invalid Request Method');
        }

        if (!$this->isValidAction('get', $action)) {
            $this->response->badRequest('Invalid Get Request');
        }

        $this->doAction($this->get, $action);
    }

    /**
     * OSF Integration Handler.
     * * Manages the OAuth2 flow with the Open Science Framework. Handles:
     * 1. Login redirection.
     * 2. Authorization code exchange.
     * 3. Error handling for denied access.
     *
     * @param string $do The specific action to perform (e.g., 'login').
     * @return void Redirects user based on authentication outcome.
     */
    public function osfAction($do = '')
    {
        $user = Site::getCurrentUser();
        if (!$user->loggedIn()) {
            alert('You need to login to access this section', 'alert-warning');
            redirect_to('admin/account/login');
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

    protected function doAction(Request $request, $action)
    {
        try {
            $this->authenticate($action); // only proceed if authenticated, if not exit via response
            $token_data = $this->oauthServer->getAccessTokenData(OAuth2\Request::createFromGlobals());
            $method = $this->getPrivateAction($action, '-', true);

            $helperClass = 'ApiHelper';
            $helper = new $helperClass($request, $this->fdb, $token_data);
            $data = $helper->{$method}()->getData();
        } catch (Exception $e) {
            formr_log_exception($e, 'API');
            $data = array(
                'statusCode' => Response::STATUS_INTERNAL_SERVER_ERROR,
                'statusText' => 'Internal Server Error',
                'response' => array('code' => Response::STATUS_INTERNAL_SERVER_ERROR, 'message' => 'An unexpected error occurred'),
            );
        }

        $this->respond($data['statusCode'], $data['statusText'], $data['response']);
    }

    protected function isValidAction($type, $action)
    {
        $actions = array(
            'oauth' => array('authorize', 'access_token', 'delete_token'),
            'post' => array('create-session', 'end-last-external'),
            'get' => array('results'),
        );

        return isset($actions[$type]) && in_array($action, $actions[$type]);
    }

    protected function authorize()
    {
        /*
         * @todo
         * Implement authorization under oauth
         */
        $this->response->badRequest('Not Implemented');
    }

    protected function access_token()
    {
        // Ex: curl -u testclient:testpass https://formr.org/api/oauth/token -d 'grant_type=client_credentials'
        $this->oauthServer->handleTokenRequest(OAuth2\Request::createFromGlobals())->send();
    }

    protected function delete_token()
    {
        OAuthHelper::getInstance()->deleteAccessToken($this->post->access_token);
        $this->respond(Response::STATUS_OK, 'Token deleted');
    }

    protected function authenticate($action)
    {
        if (!in_array($action, $this->unrestrictedActions)) {
            $this->oauthServer = Site::getOauthServer();
            // Handle a request to a resource and authenticate the access token
            // Ex: curl -H "Authorization: Bearer YOUR_TOKEN" https://formr.org/api/get/results
            if (!$this->oauthServer->verifyResourceRequest(OAuth2\Request::createFromGlobals())) {
                $this->respond(Response::STATUS_UNAUTHORIZED, 'Unauthorized', array(
                    'code' => Response::STATUS_UNAUTHORIZED,
                    'message' => 'Access token for this resource request is invalid or unauthorized',
                ));
            }
        }
    }

    protected function respond($statusCode = Response::STATUS_OK, $statusText = 'OK', $response = null)
    {
        $this->response->setStatusCode($statusCode, $statusText);
        $this->response->setContentType('application/json');
        $this->response->setJsonContent($response);
        return $this->sendResponse();
    }

    protected function initialize()
    {
        $this->view = null;
        $this->post = new Request($_POST);
        $this->get = new Request($_GET);
        $this->response = new Response();
    }
}
