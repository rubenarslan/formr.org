<?php

class RunController extends Controller {

    public function __construct(Site &$site) {
        
        parent::__construct($site);
        if (!Request::isAjaxRequest()) {
            $default_assets = get_default_assets('site');
            $this->registerAssets($default_assets);
        }
    }

    public function indexAction($runName = '', $privateAction = null) {
        // hack for run name
        $_GET['run_name'] = $runName;
        $this->site->request->run_name = $runName;
        $pageNo = null;

        if ($method = $this->getPrivateActionMethod($privateAction)) {
            $args = array_slice(func_get_args(), 2);
            return call_user_func_array(array($this, $method), $args);
        } elseif (is_numeric($privateAction)) {
            $pageNo = (int) $privateAction;
            Request::setGlobals('pageNo', $pageNo);
        } elseif ($privateAction !== null) {
            formr_error(404, 'Not Found', 'The requested URL was not found');
        }

        $this->run = $this->getRun();
        // @todo check the POSTed code ($_POST[formr_code]) and save data before redirecting
        // OR if cookie is expired then logout
        $this->user = $this->loginUser();

        //Request::setGlobals('COOKIE', $this->setRunCookie());

        $run_vars = $this->run->exec($this->user);
		if (!$run_vars) {
			formr_error(500, 'Invalid Execution', 'The execution generated no output');
		}
		
        $run_vars['bodyClass'] = 'fmr-run';
        
        if (!empty($run_vars['redirect'])) {
            return $this->request->redirect($run_vars['redirect']);
        }

        $assset_vars = $this->filterAssets($run_vars);
        unset($run_vars['css'], $run_vars['js']);

        $this->setView('run/index', array_merge($run_vars, $assset_vars));

        return $this->sendResponse();
    }

    protected function settingsAction() {
        $run = $this->getRun();
        $run_name = $this->site->request->run_name;

        if (!$run->valid) {
            formr_error(404, 'Not Found', 'Requested Run does not exist or has been moved');
        }

        // Login if user entered with code and redirect without login code
        if (Request::isHTTPGetRequest() && ($code = $this->request->getParam('code'))) {
            $_GET['run_name'] = $run_name;
            $this->user = $this->loginUser();
            //Request::setGlobals('COOKIE', $this->setRunCookie());

            if ($this->user->user_code != $code) {
                alert('Unable to login with the provided code', 'alert-warning');
            }
            $this->request->redirect(run_url($run_name, 'settings'));
        }

        // People who have no session in the run need not set anything
        $session = new RunSession($this->user->user_code, $run);
        if (!$session->id) {
            formr_error(401, 'Unauthorized', 'You cannot create settings in a study you have not participated in.');
        }

        $settings = array('no_email' => 1);
        if (Request::isHTTPPostRequest() && $this->user->user_code == $this->request->getParam('_sess')) {
            $update = array();
            $settings = array(
                'no_email' => $this->request->getParam('no_email'),
                'delete_cookie' => (int) $this->request->getParam('delete_cookie'),
            );

            if ($settings['no_email'] === '1') {
                $update['no_email'] = null;
            } elseif ($settings['no_email'] == 0) {
                $update['no_email'] = 0;
            } elseif ($ts = strtotime($settings['no_email'])) {
                $update['no_email'] = $ts;
            }

            $session->saveSettings($settings, $update);

            alert('Settings saved successfully for survey "' . $run->name . '"', 'alert-success');
            if ($settings['delete_cookie']) {
                Session::destroy();
                $this->request->redirect('index');
            }
            $this->request->redirect(run_url($run_name, 'settings'));
        }

        $this->run = $run;
        $this->setView('run/settings', array(
            'settings' => $session->getSettings(),
            'email_subscriptions' => Config::get('email_subscriptions'),
        ));
        
        return $this->sendResponse();
    }

    protected function logoutAction() {
        $this->run = $this->getRun();
        $this->user = $this->loginUser();
        $cookie = $this->getRunCookie();
        $cookie->destroy();
        Session::destroy(false);
        $hint = 'Session Ended';
        $text = 'Your session was successfully closed! You can restart a new session by clicking the link below.';
        $url = run_url($this->run->name);
        if ($this->request->prev) {
            //If user is loggin out from a test session, show button to create another test session
            $prevRunSesson = new RunSession($this->request->prev, $this->run);
            if ($prevRunSesson->testing) {
                $url = admin_run_url($this->run->name, 'create_new_test_code');
            }
        }
        formr_error(200, 'OK', $text, $hint, $url, 'Start New Session');
    }

    protected function monkeyBarAction($action = '') {
        $action = str_replace('ajax_', '', $action);
        $allowed_actions = array('send_to_position', 'remind', 'next_in_run', 'delete_user', 'snip_unit_session');
        $run = $this->getRun();

        if (!in_array($action, $allowed_actions) || !$run->valid) {
            throw new Exception("Invalid Request parameters");
        }

        $parts = explode('_', $action);
        $method = array_shift($parts) . str_replace(' ', '', ucwords(implode(' ', $parts)));
        $runHelper = new RunHelper($run, $this->fdb, $this->request);

        // check if run session usedby the monkey bar is a test if not this action is not valid
        if (!($runSession = $runHelper->getRunSession()) || !$runSession->isTesting()) {
            throw new Exception("Unauthorized access to run session");
        }

        if (!method_exists($runHelper, $method)) {
            throw new Exception("Invalid method {$action}");
        }

        $runHelper->{$method}();

        if (($errors = $runHelper->getErrors())) {
            $errors = implode("\n", $errors);
            alert($errors, 'alert-danger');
        }

        if (($message = $runHelper->getMessage())) {
            alert($message, 'alert-info');
        }

        if (Request::isAjaxRequest()) {
            $content = $this->site->renderAlerts();
            $this->sendResponse($content);
        } else {
            $this->request->redirect('');
        }
    }

    private function getRun() {
        $name = $this->request->str('run_name');
        $run = new Run($name);
        if ($name !== Run::TEST_RUN && Config::get('use_study_subdomains') && !FMRSD_CONTEXT) {
            //throw new Exception('Invalid Study Context');
            // Redirect existing users to run's sub-domain URL and QSA
            $params = $this->request->getParams();
            unset($params['route'], $params['run_name']);
            $name = str_replace('_', '-', $name);
            $url = run_url($name, null, $params);
            $this->request->redirect($url);
        } elseif (!$run->valid) {
            $msg = __('If you\'re trying to create an online study,  <a href="%s">read the full documentation</a> to learn how to create one.', site_url('documentation'));
            formr_error(404, 'Not Found', $msg, 'There isn\'t an online study here.');
        }

        return $run;
    }

    private function getPrivateActionMethod($action) {
        if ($action === null) {
            return false;
        }

        $actionName = $this->getPrivateAction($action, '-', true) . 'Action';
        if (!method_exists($this, $actionName)) {
            return false;
        }
        return $actionName;
    }

    private function filterAssets($assets) {
        $vars = array();
        if ($this->run->use_material_design || $this->request->str('tmd') === 'true') {
            if (DEBUG) {
                $this->unregisterAssets('site:custom');
                $this->registerAssets('bootstrap-material-design');
                $this->registerAssets('site:custom');
            } else {
                $this->replaceAssets('site', 'site:material');
            }
            $vars['bodyClass'] = 'bs-material fmr-run';
        }
        $this->registerCSS($assets['css'], $this->run->name);
        $this->registerJS($assets['js'], $this->run->name);
        return $vars;
    }

    protected function generateMetaInfo() {
        $meta = parent::generateMetaInfo();
        $meta['title'] = $this->run->title ? $this->run->title : $this->run->name;
        $meta['url'] = run_url($this->run->name);
        if ($this->run->description) {
            $meta['description'] = $this->run->description;
        }
        if ($this->run->header_image_path) {
            $meta['image'] = $this->run->header_image_path;
        }

        return $meta;
    }

    protected function setRunCookie($refresh = false) {
        $cookie = $this->getRunCookie();
        $expires = $this->run->expire_cookie ? time() + $this->run->expire_cookie : 0;

        if (!$cookie->exists() || $refresh === true) {
            $data = array(
                'code' => $this->user->user_code,
                'created' => time(),
                'modified' => time(),
                'expires' => $expires,
            );
            $cookie->create($data, $expires, '/', null, SSL, true);
        } elseif ($cookie->exists()) {
            $cookie->setExpiration($expires);
        }

        return $cookie;
    }

    /**
     * 
     * @return \Cookie
     */
    protected function getRunCookie() {
        $cookie = new \Cookie($this->run->getCookieName());
        return $cookie;
    }

    protected function loginUser() {
        $id = null;

        // came here with a login link
        if (isset($_GET['run_name']) && isset($_GET['code']) && strlen($_GET['code']) == 64) {
            // user came in with login code
            $loginCode = $_GET['code'];
        } elseif ($user = Site::getInstance()->getSessionUser()) {
            // try to get user from cookie
            $loginCode = $user->user_code;
            $id = $user->id;
        } else {
            // new user just entering the run;
            $loginCode = null;
        }
        
        return new User($id, $loginCode);
    }

}
