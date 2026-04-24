<?php

class RunController extends Controller {

    public function __construct(Site &$site) {
        
        parent::__construct($site);
        if (!Request::isAjaxRequest()) {
            $this->registerAssets('frontend');
        }
    }

    public function indexAction($runName = '', $privateAction = null) {
        // hack for run name
        $_GET['run_name'] = $runName;
        $this->site->request->run_name = $runName;
        $pageNo = null;
        
        // Handle trailing slashes in URLs, considering query strings
        $requestUri = $_SERVER['REQUEST_URI'];
        
        // Split URI into path and query parts
        $path = parse_url($requestUri, PHP_URL_PATH);
        $query = parse_url($requestUri, PHP_URL_QUERY);
        
        // If the path ends with $runName and no slash, redirect to the path with a slash, preserving query strings
        if ($path === '/' . $runName) {
            $redirectUrl = $path . '/';
            if ($query !== null && $query !== '') {
                $redirectUrl .= '?' . $query;
            }
            $this->request->redirect($redirectUrl);
        }

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

        Session::setSessionLifetime($this->run->expire_cookie);

        $run_vars = $this->run->exec($this->user);
		if (!$run_vars) {
			formr_error(500, 'Invalid Execution', 'The execution generated no output');
		}
		
        $run_vars['bodyClass'] = 'fmr-run';
        
        if (!empty($run_vars['redirect'])) {
            return $this->request->redirect($run_vars['redirect']);
        }

        $asset_vars = $this->filterAssets($run_vars);
        unset($run_vars['css'], $run_vars['js']);

        // form_v2 units render through a minimal standalone view that loads only
        // the form bundle. Falls through to run/index.php for Survey units and all
        // other unit types.
        $view = !empty($run_vars['use_form_v2']) ? 'run/form_index' : 'run/index';

        $this->setView($view, array_merge($run_vars, $asset_vars));

        return $this->sendResponse();
    }

    private function privacy_policyAction() {
        $this->run = $this->getRun();
        $run_name = $this->site->request->run_name;

        if (!$this->run->valid) {
            formr_error(404, 'Not Found', 'Requested Run does not exist or has been moved');
        }

        if(!$this->run->hasPrivacy()) {
            $this->request->redirect(run_url($run_name, ''));
        }

        $run_content = $this->run->getParsedPrivacyField('privacy-policy');

        $run_vars = array(
            'run_content' => $run_content,
            'bodyClass' => 'fmr-run',
        );

        $this->setView('run/static_page', $run_vars);

        return $this->sendResponse();
    }

    private function terms_of_serviceAction() {
        $this->run = $this->getRun();
        $run_name = $this->site->request->run_name;

        if (!$this->run->valid) {
            formr_error(404, 'Not Found', 'Requested Run does not exist or has been moved');
        }

        if(!$this->run->hasToS()) {
            $this->request->redirect(run_url($run_name, ''));
        }

        $run_content = $this->run->getParsedPrivacyField('terms-of-service');

        $run_vars = array(
            'run_content' => $run_content,
            'bodyClass' => 'fmr-run',
        );

        $this->setView('run/static_page', $run_vars);

        return $this->sendResponse();
    }

    protected function settingsAction() {
        $run = $this->getRun();
        $run_name = $this->site->request->run_name;

        if (!$run->valid) {
            formr_error(404, 'Not Found', 'Requested Run does not exist or has been moved');
        }

        // People who have no session in the run need not set anything
        $session = new RunSession($this->user->user_code, $run);
        if (!$session->id) {
            formr_error(401, 'Unauthorized', 'You cannot create settings in a study you have not participated in.');
        }

        $settings = array('no_email' => 1);
        if (Request::isHTTPPostRequest()) {
            $update = array();
            $settings = array(
                'no_email' => $this->request->getParam('no_email')
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
            $this->request->redirect(run_url($run_name, 'settings'));
        }

        $this->run = $run;
        $this->setView('run/settings', array(
            'settings' => $session->getSettings(),
            'email_subscriptions' => Config::get('email_subscriptions'),
            'bodyClass' => 'fmr-run fmr-settings',
            'user_email' => $session->getRecipientEmail(),
            'vapid_key_exists' => !empty($this->run->getVapidPublicKey()),
            'current_push_subscription' => $session->getSubscription(false)
        ));
        
        return $this->sendResponse();
    }

    protected function logoutAction() {
        $this->run = $this->getRun();
        $this->user = $this->loginUser();
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
            $this->request->redirect(run_url($run->name, ''));
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
            $this->registerAssets('material');
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

    /**
     * 
     * @return \User
     */
    protected function loginUser() {
        $id = null;
        $user = Site::getInstance()->getSessionUser();

        // came here with a login link
        $code_rule = Config::get("user_code_regular_expression");
        if (isset($_GET['run_name']) && isset($_GET['code'])) {
            $login_code = $_GET['code'];
            if (!preg_match($code_rule, $login_code)) {
                alert("Invalid user code. Please contact the study administrator.", "alert-danger");
            } elseif (isset($_POST['_formr_code'])) {
                $posted_login_code = $_POST['_formr_code'];
                if($posted_login_code != null AND $posted_login_code !== $login_code) {
                    alert("Mismatched user codes. Please contact the study administrator.", "alert-danger");
                    if(preg_match($code_rule, $posted_login_code)) {
                        $login_code = $posted_login_code;
                    }
                }
            } elseif ($user->user_code !== $login_code) {
                 // this user came here with a session code that he wasn't using before. 
                // this will always be true if the user is 
                // (a) new (auto-assigned code by site) 
                // (b) already logged in with a different account
                if ($user->loggedIn()) {
                    // if the user is new and has an auto-assigned code, there's no need to talk about the behind-the-scenes change
                    // but if he's logged in we should alert them
                    alert("You switched sessions, because you came here with a login link and were already logged in as someone else.", 'alert-info');
                }
                // a special case are admins. if they are not already logged in, verified through password, they should not be able to obtain access so easily. but because we only create a mock user account, this is no problem. the admin flags are only set/privileges are only given if they legitimately log in
            }
        } elseif ($user) {
            // try to get user from cookie
            $login_code = $user->user_code;
            $id = $user->id;
        } else {
            // new user just entering the run;
            $login_code = null;
        }
        
        return new User($id, $login_code);
    }

    /**
     * Serve the service worker file with appropriate headers for the run
     */
    public function serviceWorkerAction() {
        $run = $this->getRun();
        
        // Set appropriate headers
        header('Content-Type: application/javascript');
        
        // Set Service-Worker-Allowed header based on the deployment type (subdomain vs folder)
        if (Config::get('use_study_subdomains') && FMRSD_CONTEXT) {
            // For subdomain deployments
            header('Service-Worker-Allowed: /');
        } else {
            // For folder deployments, scope to the run path
            $runPath = run_url($run->name, '');
            $parsedUrl = parse_url($runPath);
            header('Service-Worker-Allowed: ' . $parsedUrl['path']);
        }
        
        // No caching for development, adjust for production if needed
        header('Cache-Control: no-cache, no-store, must-revalidate');
        
        // Serve the service worker file
        $serviceWorkerPath = APPLICATION_ROOT . 'webroot/assets/common/js/service-worker.js';
        if (file_exists($serviceWorkerPath)) {
            readfile($serviceWorkerPath);
            exit;
        } 
        
        // If file doesn't exist, return 404
        header('HTTP/1.0 404 Not Found');
        echo "Service worker not found";
        exit;
    }

    /**
     * Serve the manifest file with appropriate headers for the run
     */
    public function manifestAction() {
        $run = $this->getRun();

        // Set appropriate headers
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        // Serve the manifest file
        $manifestPath = $run->getManifestJSONPath();
        if(!empty($manifestPath) && file_exists($manifestPath)) {
            readfile($manifestPath);
            exit;
        }

        // If file doesn't exist, return 404
        header('HTTP/1.0 404 Not Found');
        echo "Manifest not found";
    }

    private function sendJsonResponse($data, $statusCode = 200) {
        // Bypass the Response object to guarantee a raw JSON payload with no layout or extra HTML.
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit; // Stop further script execution
    }

    public function ajax_get_push_subscription_statusAction() {
        $this->run = $this->getRun();
        $this->user = $this->loginUser(); // Ensure user and session are loaded
        $session = new RunSession($this->user->user_code, $this->run);
        if (!$session->id) {
            $this->sendJsonResponse(array('error' => 'User session not found.'), 401);
            return;
        }

        $subscription = array_val($session->getSettings(), 'push_subscription');
        $this->sendJsonResponse(array('subscription' => $subscription));
    }

    public function ajax_save_push_subscriptionAction() {
        if (!Request::isHTTPPostRequest()) {
            $this->sendJsonResponse(array('error' => 'Invalid request method.'), 405);
            return;
        }

        $this->run = $this->getRun();
        $this->user = $this->loginUser();
        $session = new RunSession($this->user->user_code, $this->run);

        if (!$session->id) {
            $this->sendJsonResponse(array('error' => 'User session not found.'), 401);
            return;
        }

        $subscriptionJson = $this->request->getParam('subscription');
        if (empty($subscriptionJson)) {
            $this->sendJsonResponse(array('error' => 'Subscription data not provided.'), 400);
            return;
        }

        $subscriptionData = json_decode($subscriptionJson, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($subscriptionData['endpoint'])) {
            $this->sendJsonResponse(array('error' => 'Invalid subscription JSON.'), 400);
            return;
        }

        if ($session->updateSubscription($subscriptionJson)) {
            $this->sendJsonResponse(array('success' => true, 'message' => 'Subscription saved.'));
        } else {
            $this->sendJsonResponse(array('error' => 'Failed to save subscription.'), 500);
        }
    }

    public function ajax_delete_push_subscriptionAction() {
        if (!Request::isHTTPPostRequest()) {
            $this->sendJsonResponse(array('error' => 'Invalid request method.'), 405);
            return;
        }

        $this->run = $this->getRun();
        $this->user = $this->loginUser();
        $session = new RunSession($this->user->user_code, $this->run);

        if (!$session->id) {
            $this->sendJsonResponse(array('error' => 'User session not found.'), 401);
            return;
        }

        if ($session->updateSubscription(null)) {
            $this->sendJsonResponse(array('success' => true, 'message' => 'Subscription deleted.'));
        } else {
            $this->sendJsonResponse(array('error' => 'Failed to delete subscription.'), 500);
        }
    }

    /**
     * form_v2 page-submit endpoint (Phase 1).
     *
     * URL: POST /{runName}/form-page-submit
     * Body: JSON `{"page": int, "data": {name: value, ...}, "item_views": {shown|shown_relative|answered|answered_relative: {itemId: value}}}`
     *
     * Validates and persists a page's answers via UnitSession::updateSurveyStudyRecord
     * (the same path v1 uses for form submits). On success, instructs the client to
     * redirect back to the run URL so Run::exec can advance to the next unit.
     * Validation errors are returned as `{status: "errors", errors: {name: msg}}`
     * for inline display.
     */
    public function formPageSubmitAction() {
        if (!Request::isHTTPPostRequest()) {
            $this->sendJsonResponse(array('error' => 'Method Not Allowed'), 405);
            return;
        }

        // Accept two payload shapes:
        //   - application/json with {page, data, item_views} (file-less fast path)
        //   - multipart/form-data with flat fields `data[name]=...`,
        //     `item_views[shown][itemId]=...`, plus $_FILES entries for File_Item.
        // v2 switches to multipart when the current page has a file field with
        // a selected file; FormData is the only way to ship binary through
        // $_FILES, which File_Item::validateInput requires.
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : '';
        $isMultipart = stripos($contentType, 'multipart/form-data') === 0;

        $submittedPage = 1;
        if ($isMultipart) {
            $submittedPage = isset($_POST['page']) ? (int) $_POST['page'] : 1;
            $data = (isset($_POST['data']) && is_array($_POST['data'])) ? $_POST['data'] : array();
            $itemViews = (isset($_POST['item_views']) && is_array($_POST['item_views'])) ? $_POST['item_views'] : array();
            // Merge $_FILES[*] entries into $data so File_Item::validateInput
            // receives the canonical {error, tmp_name, name, size, type} dict
            // under the item's name. Nested form field `files[name]` is what
            // the client sends.
            if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
                $fileNames = $_FILES['files']['name'];
                foreach ($fileNames as $itemName => $_) {
                    if (!is_string($itemName) || $itemName === '') continue;
                    $data[$itemName] = array(
                        'name' => $_FILES['files']['name'][$itemName] ?? '',
                        'type' => $_FILES['files']['type'][$itemName] ?? '',
                        'tmp_name' => $_FILES['files']['tmp_name'][$itemName] ?? '',
                        'error' => $_FILES['files']['error'][$itemName] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['files']['size'][$itemName] ?? 0,
                    );
                }
            }
        } else {
            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                $this->sendJsonResponse(array('error' => 'Invalid JSON body'), 400);
                return;
            }
            $submittedPage = isset($payload['page']) ? (int) $payload['page'] : 1;
            $data = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : array();
            $itemViews = (isset($payload['item_views']) && is_array($payload['item_views'])) ? $payload['item_views'] : array();
        }

        $this->run = $this->getRun();
        $this->user = $this->loginUser();

        $runSession = new RunSession($this->user->user_code, $this->run, array('user' => $this->user));
        if (!$runSession->id) {
            $this->sendJsonResponse(array('error' => 'No active run session'), 403);
            return;
        }

        $unitSession = $runSession->getCurrentUnitSession();
        if (!$unitSession || !$unitSession->runUnit) {
            $this->sendJsonResponse(array('error' => 'No current unit session'), 409);
            return;
        }

        $runUnit = $unitSession->runUnit;
        // Form extends Survey — accept both, but only v2-rendered studies should reach this endpoint.
        if (!($runUnit instanceof Survey)) {
            $this->sendJsonResponse(array('error' => 'Current unit is not a survey/form'), 409);
            return;
        }

        $unitSession->createSurveyStudyRecord();

        // Reassemble the v1-style $posted shape: flat answers + nested _item_views.
        $posted = $data;
        $posted['_item_views'] = $itemViews;

        $saved = $unitSession->updateSurveyStudyRecord($posted, true);
        if (!$saved) {
            $errors = isset($unitSession->errors) && is_array($unitSession->errors) ? $unitSession->errors : array();
            $this->sendJsonResponse(array(
                'status' => 'errors',
                'errors' => $errors,
            ));
            return;
        }

        // For a multi-page form, if more pages remain, tell the client to show the
        // next one locally (no reload). If this was the last page, redirect back
        // to the run URL so Run::exec can advance to the next unit.
        $maxPage = (int) DB::getInstance()
            ->select('MAX(page)')
            ->from('survey_items_display')
            ->where('session_id = :sid')
            ->bindParams(['sid' => $unitSession->id])
            ->fetchColumn();

        if ($maxPage > 0 && $submittedPage < $maxPage) {
            $this->sendJsonResponse(array(
                'status' => 'ok',
                'next_page' => $submittedPage + 1,
            ));
            return;
        }

        $this->sendJsonResponse(array(
            'status' => 'ok',
            'redirect' => run_url($this->run->name),
        ));
    }

    /**
     * form_v2 Phase 3: evaluate an allowlisted r(...) expression with live answers.
     *
     * URL: POST /{runName}/form-r-call
     * Body: JSON `{"call_id": int, "answers": {name: value, ...}}`
     *
     * The client sends its current reactive answers; the server overlays them on
     * the persisted survey row and evaluates the stored R expression. This exists
     * so admin-authored showifs that contain R-only constructs the regex
     * transpiler can't handle still react within the same page, without ever
     * shipping R source to the client.
     */
    public function formRCallAction() {
        if (!Request::isHTTPPostRequest()) {
            $this->sendJsonResponse(array('error' => 'Method Not Allowed'), 405);
            return;
        }
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->sendJsonResponse(array('error' => 'Invalid JSON body'), 400);
            return;
        }
        $callId = isset($payload['call_id']) ? (int) $payload['call_id'] : 0;
        $answers = (isset($payload['answers']) && is_array($payload['answers'])) ? $payload['answers'] : array();

        $out = $this->evaluateAllowlistedRCall($callId, 'showif', $answers);
        if (!$out['ok']) {
            $this->sendJsonResponse(array('error' => $out['error']), $out['status']);
            return;
        }
        $this->sendJsonResponse(array('result' => self::rResultToBool($out['result'])));
    }

    /**
     * form_v2 Phase 4: deferred fill for r(...)-wrapped `value` expressions.
     *
     * URL: POST /{runName}/form-fill
     * Body: JSON `{"call_id": int, "answers": {name: value, ...}}`
     *
     * Same shape as form-r-call but enforces slot='value' and returns the R
     * result stringified for the input. Client sets `input.value` and fires a
     * change event so dependent showifs re-evaluate.
     */
    public function formFillAction() {
        if (!Request::isHTTPPostRequest()) {
            $this->sendJsonResponse(array('error' => 'Method Not Allowed'), 405);
            return;
        }
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->sendJsonResponse(array('error' => 'Invalid JSON body'), 400);
            return;
        }
        $callId = isset($payload['call_id']) ? (int) $payload['call_id'] : 0;
        $answers = (isset($payload['answers']) && is_array($payload['answers'])) ? $payload['answers'] : array();

        $out = $this->evaluateAllowlistedRCall($callId, 'value', $answers);
        if (!$out['ok']) {
            $this->sendJsonResponse(array('error' => $out['error']), $out['status']);
            return;
        }
        $this->sendJsonResponse(array('value' => self::rResultToScalarString($out['result'])));
    }

    /**
     * Shared body for form-r-call + form-fill: validate session/study ownership
     * of the call_id, enforce expected slot, overlay client answers on the last
     * persisted row, evaluate the R expression via OpenCPU. Never ships R
     * source to the client.
     *
     * @param int $callId survey_r_calls.id
     * @param string $expectedSlot one of the survey_r_calls.slot enum values
     * @param array $answers client-sent reactive answers, overlaid on tail(survey, 1)
     * @return array{ok:bool, status?:int, error?:string, result?:mixed}
     */
    protected function evaluateAllowlistedRCall($callId, $expectedSlot, array $answers) {
        if ($callId <= 0) {
            return array('ok' => false, 'status' => 400, 'error' => 'Missing call_id');
        }

        $this->run = $this->getRun();
        $this->user = $this->loginUser();

        $runSession = new RunSession($this->user->user_code, $this->run, array('user' => $this->user));
        if (!$runSession->id) {
            return array('ok' => false, 'status' => 403, 'error' => 'No active run session');
        }

        $unitSession = $runSession->getCurrentUnitSession();
        if (!$unitSession || !$unitSession->runUnit) {
            return array('ok' => false, 'status' => 409, 'error' => 'No current unit session');
        }
        $runUnit = $unitSession->runUnit;
        if (!($runUnit instanceof Survey)) {
            return array('ok' => false, 'status' => 409, 'error' => 'Current unit is not a survey/form');
        }
        $study = method_exists($runUnit, 'getStudy') ? $runUnit->getStudy(true) : $runUnit->surveyStudy;
        if (!$study || empty($study->id)) {
            return array('ok' => false, 'status' => 409, 'error' => 'No study on current unit');
        }

        $row = DB::getInstance()
            ->select('id, study_id, slot, expr')
            ->from('survey_r_calls')
            ->where('id = :id')
            ->bindParams(array('id' => $callId))
            ->fetch();
        if (!$row || (int) $row['study_id'] !== (int) $study->id) {
            return array('ok' => false, 'status' => 404, 'error' => 'Unknown call_id for this study');
        }
        if ((string) $row['slot'] !== (string) $expectedSlot) {
            return array('ok' => false, 'status' => 400, 'error' => 'call_id slot mismatch');
        }

        $expr = (string) $row['expr'];
        // TODO(phase 4+): per-session bucket rate-limit once RateLimitService
        // grows a generic check() API (today it's email-specific). Reactive
        // cadence is already implicitly one-per-participant-keystroke.

        $overlayR = self::formatROverlay($answers);
        $survey_name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $study->name);
        if ($survey_name === '') {
            return array('ok' => false, 'status' => 500, 'error' => 'Bad study name');
        }

        $code = "(function() {\n"
              . "  .fmr.overlay <- {$overlayR}\n"
              . "  .fmr.row <- tail({$survey_name}, 1)\n"
              . "  for (.n in names(.fmr.overlay)) .fmr.row[[.n]] <- .fmr.overlay[[.n]]\n"
              . "  with(.fmr.row, {\n"
              . "    tryCatch({ {$expr} }, error = function(e) NA)\n"
              . "  })\n"
              . "})()\n";

        $variables = $unitSession->getRunData($code, $survey_name);
        $session = opencpu_evaluate($code, $variables, 'json', null, true);
        if (!$session || $session->hasError()) {
            return array('ok' => false, 'status' => 502, 'error' => 'Evaluation failed');
        }

        $result = $session->getJSONObject();
        // R returns length-1 vectors as single-element arrays; unwrap.
        if (is_array($result) && count($result) === 1 && array_keys($result) === array(0)) {
            $result = $result[0];
        }
        return array('ok' => true, 'result' => $result);
    }

    /**
     * Stringify an R scalar for the client. Empty string for NA / null / empty
     * arrays (deferred fill should leave the field blank if the expression
     * couldn't produce a value, rather than emitting "NA" or "null" literally).
     */
    protected static function rResultToScalarString($result) {
        if ($result === null) return '';
        if (is_bool($result)) return $result ? 'TRUE' : 'FALSE';
        if (is_int($result) || is_float($result)) {
            if (is_float($result) && is_nan($result)) return '';
            return (string) $result;
        }
        if (is_array($result)) {
            if (empty($result)) return '';
            // Flatten to first scalar; Phase 4 fills target a single input.
            return self::rResultToScalarString(reset($result));
        }
        return (string) $result;
    }

    /**
     * Build an R `list(name = value, ...)` literal from a PHP associative array,
     * with keys restricted to valid R/formr item names (letters, digits,
     * underscore, leading letter) and values coerced to R scalars / vectors.
     */
    protected static function formatROverlay(array $answers) {
        $parts = array();
        foreach ($answers as $key => $val) {
            if (!is_string($key) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            $parts[] = $key . ' = ' . self::formatRValue($val);
        }
        return 'list(' . implode(', ', $parts) . ')';
    }

    protected static function formatRValue($v) {
        if ($v === null || $v === '') return 'NA';
        if (is_bool($v)) return $v ? 'TRUE' : 'FALSE';
        if (is_int($v) || is_float($v)) return (string) $v;
        if (is_array($v)) {
            if (empty($v)) return 'c()';
            $parts = array_map(array(__CLASS__, 'formatRValue'), array_values($v));
            return 'c(' . implode(', ', $parts) . ')';
        }
        if (is_numeric($v)) return (string) $v;
        // String: R double-quoted, escape backslash and double-quote.
        $s = str_replace(array('\\', '"'), array('\\\\', '\"'), (string) $v);
        return '"' . $s . '"';
    }

    protected static function rResultToBool($result) {
        if (is_bool($result)) return $result;
        if (is_int($result) || is_float($result)) return ((float) $result) != 0.0;
        if (is_string($result)) {
            $l = strtolower(trim($result));
            if ($l === 'true' || $l === 't' || $l === '1') return true;
            return false;
        }
        return (bool) $result;
    }
}
