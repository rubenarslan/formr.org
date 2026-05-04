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

        // Serve the manifest file. `getManifestJSONPath()` returns the
        // webroot-relative path stored in the DB (e.g.
        // `assets/tmp/admin/HASH.json`); resolve against APPLICATION_ROOT
        // to get the absolute path. The "Save Manifest Text" textarea path
        // wrote a relative path, so file_exists() against it failed silently.
        $manifestPath = $run->getManifestJSONPath();
        if (!empty($manifestPath)) {
            $absolutePath = APPLICATION_ROOT . 'webroot/' . ltrim($manifestPath, '/');
            if (is_file($absolutePath) && is_readable($absolutePath)) {
                readfile($absolutePath);
                exit;
            }
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
        $nextPage = (int) $this->findNextRenderablePage($unitSession->id, $submittedPage);
        if ($nextPage > 0) {
            $this->sendJsonResponse(array(
                'status' => 'ok',
                'next_page' => $nextPage,
            ));
            return;
        }

        $this->sendJsonResponse(array(
            'status' => 'ok',
            'redirect' => run_url($this->run->name),
        ));
    }

    /**
     * Return the lowest survey_items_display.page > $submittedPage that still
     * has at least one unanswered, unhidden item — i.e. the next page the
     * client will actually render. Earlier code returned $submittedPage + 1
     * unconditionally, which broke when DOM `data-fmr-page` values were
     * non-contiguous (submit-only intermediate pages get hidden=1 in
     * FormRenderer Step 4 + groupByPage drops empty pages; resumed sessions
     * have fully-answered middle pages stripped by the saved IS NULL filter;
     * server-side showif can hide every item on a given page). The client
     * `pages.findIndex` then returned -1 and the navigation silently bailed.
     *
     * Returns 0 (or false coerced to 0) when no further page is renderable —
     * caller redirects to the run URL so Run::exec advances units.
     */
    private function findNextRenderablePage($unitSessionId, $submittedPage) {
        return DB::getInstance()
            ->select('MIN(page)')
            ->from('survey_items_display')
            ->where('session_id = :sid AND page > :p AND saved IS NULL AND (hidden IS NULL OR hidden = 0)')
            ->bindParams(array('sid' => (int) $unitSessionId, 'p' => (int) $submittedPage))
            ->fetchColumn();
    }

    /**
     * form_v2 Phase 5: offline-queue sync endpoint.
     *
     * URL: POST /{runName}/form-sync
     * Body:
     *   - application/json: `{"uuid": str, "page": int, "data": {...}, "item_views": {...}, "client_ts": str}`
     *   - multipart/form-data (queued file upload): flat fields `uuid`, `page`,
     *     `client_ts`, `data[name]`, `data[name][]`, `item_views[bucket][id]`,
     *     plus `files[name]` carrying the Blob; same shape as form-page-submit's
     *     multipart branch.
     *
     * Accepts a single queued submission. Idempotent via `survey_form_submissions.uuid`:
     * a retry with the same uuid returns `{status: 'ok', already_applied: true}` without
     * re-applying. Otherwise the endpoint follows the same auth + apply path as
     * `formPageSubmitAction` and records the uuid on success.
     */
    public function formSyncAction() {
        if (!Request::isHTTPPostRequest()) {
            $this->sendJsonResponse(array('error' => 'Method Not Allowed'), 405);
            return;
        }
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : '';
        $isMultipart = stripos($contentType, 'multipart/form-data') === 0;
        if ($isMultipart) {
            $uuid = isset($_POST['uuid']) ? (string) $_POST['uuid'] : '';
            $submittedPage = isset($_POST['page']) ? (int) $_POST['page'] : 1;
            $claimedUnitSessionId = isset($_POST['unit_session_id']) ? (int) $_POST['unit_session_id'] : 0;
            $data = (isset($_POST['data']) && is_array($_POST['data'])) ? $_POST['data'] : array();
            $itemViews = (isset($_POST['item_views']) && is_array($_POST['item_views'])) ? $_POST['item_views'] : array();
            $clientTs = isset($_POST['client_ts']) ? (string) $_POST['client_ts'] : null;
            // Re-project $_FILES['files'][...][itemName] into the flat
            // {name,type,tmp_name,error,size} dict File_Item::validateInput
            // expects — same logic as formPageSubmitAction.
            if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
                foreach ($_FILES['files']['name'] as $itemName => $_) {
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
            $payload = json_decode(file_get_contents('php://input'), true);
            if (!is_array($payload)) {
                $this->sendJsonResponse(array('error' => 'Invalid JSON body'), 400);
                return;
            }
            $uuid = isset($payload['uuid']) ? (string) $payload['uuid'] : '';
            $submittedPage = isset($payload['page']) ? (int) $payload['page'] : 1;
            $claimedUnitSessionId = isset($payload['unit_session_id']) ? (int) $payload['unit_session_id'] : 0;
            $data = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : array();
            $itemViews = (isset($payload['item_views']) && is_array($payload['item_views'])) ? $payload['item_views'] : array();
            $clientTs = isset($payload['client_ts']) ? (string) $payload['client_ts'] : null;
        }
        // RFC 4122-ish: 8-4-4-4-12 hex chars. Tight so a malformed client can't
        // pollute the ledger with arbitrary strings.
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            $this->sendJsonResponse(array('error' => 'Malformed uuid'), 400);
            return;
        }

        // Dedup pre-check: if we've already persisted this uuid, short-circuit
        // so retries are safe and cheap. The uuid is unique-indexed so a true
        // race ends in a constraint error on INSERT below rather than a
        // double-apply — the apply is still best-effort idempotent per item.
        $existing = DB::getInstance()
            ->select('id')
            ->from('survey_form_submissions')
            ->where('uuid = :uuid')
            ->bindParams(array('uuid' => $uuid))
            ->fetch();
        if ($existing) {
            $this->sendJsonResponse(array('status' => 'ok', 'already_applied' => true));
            return;
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
            // Most common cause: the participant has since advanced past the form,
            // so the unit session ended. Surface a distinct status so the client
            // can drop the entry instead of retrying forever.
            $this->sendJsonResponse(array('error' => 'No current unit session', 'drop_entry' => true), 409);
            return;
        }
        if (!($unitSession->runUnit instanceof Survey)) {
            $this->sendJsonResponse(array('error' => 'Current unit is not a survey/form', 'drop_entry' => true), 409);
            return;
        }

        // The queue entry was tagged with the unit-session id at enqueue time.
        // Drop it if the participant has since advanced to a *different* Form
        // unit (still a Survey instance, FK-shape compatible, but the wrong
        // record). Without this gate the queued answers would be silently
        // written into whichever Form unit happens to be current — diary /
        // multi-Form runs were the canonical break case. Older queue entries
        // from before this gate landed have unit_session_id=0; we accept
        // those rather than wedge in-flight queues, matching the lenient
        // posture of the existing "no current unit" / "not a survey" drops.
        if ($claimedUnitSessionId > 0 && $claimedUnitSessionId !== (int) $unitSession->id) {
            $this->sendJsonResponse(array(
                'error' => 'Queued submission targets a different unit session',
                'drop_entry' => true,
            ), 409);
            return;
        }

        $unitSession->createSurveyStudyRecord();
        $posted = $data;
        $posted['_item_views'] = $itemViews;

        $saved = $unitSession->updateSurveyStudyRecord($posted, true);
        if (!$saved) {
            $errors = isset($unitSession->errors) && is_array($unitSession->errors) ? $unitSession->errors : array();
            // Validation errors are not a transient offline failure — surface
            // them so the client can show them to the user instead of looping.
            $this->sendJsonResponse(array('status' => 'errors', 'errors' => $errors));
            return;
        }

        // Record uuid only on successful apply. Use a stmt so a concurrent
        // retry caught between pre-check and insert fails fast via the UNIQUE
        // constraint (caught above by the fetch on next tick).
        $stmt = DB::getInstance()->prepare(
            'INSERT INTO `survey_form_submissions` (uuid, unit_session_id, page, client_ts) '
            . 'VALUES (:uuid, :usid, :page, :cts)'
        );
        $stmt->bindValue(':uuid', $uuid);
        $stmt->bindValue(':usid', (int) $unitSession->id);
        $stmt->bindValue(':page', $submittedPage);
        $stmt->bindValue(':cts', $clientTs);
        try {
            $stmt->execute();
        } catch (PDOException $e) {
            // Concurrent retry raced us to INSERT. Treat as already-applied.
            if ((int) $e->errorInfo[1] !== 1062) {
                throw $e;
            }
        }

        $nextPage = (int) $this->findNextRenderablePage($unitSession->id, $submittedPage);
        if ($nextPage > 0) {
            $this->sendJsonResponse(array('status' => 'ok', 'next_page' => $nextPage));
            return;
        }
        $this->sendJsonResponse(array('status' => 'ok', 'redirect' => run_url($this->run->name)));
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
     * form_v2 page-transition resolver.
     *
     * URL: POST /{runName}/form-render-page
     * Body: JSON `{"page": int, "answers": {name: value, ...}}`
     *
     * Resolves all dynamic values (`survey_r_calls.slot='value'`) and dynamic
     * labels (`slot='label'`) for items on the requested page in one batched
     * OpenCPU pass. Returns:
     *
     *   { "values": { "<call_id>": <scalar>, ... },
     *     "labels": { "<call_id>": "<rendered html>", ... } }
     *
     * Page-transition flow: client submits page N via /form-page-submit,
     * server persists answers, returns `next_page`. Client then POSTs here
     * with `page=next_page` and the current page's answers (so any same-page
     * hidden-field-with-r() values resolve against the latest state). Client
     * substitutes labels into the wrapper innerHTML and writes values into
     * the matching `data-fmr-fill-id` inputs before showing the page.
     *
     * Initial page render does NOT call this — the first visible page's
     * dynamic content is resolved server-side at FormRenderer time.
     *
     * The `answers` payload also enables a "retrigger" use case: a client
     * could re-POST with hypothetical answers to refresh dynamic content
     * without leaving the page.
     */
    public function formRenderPageAction() {
        if (!Request::isHTTPPostRequest()) {
            $this->sendJsonResponse(array('error' => 'Method Not Allowed'), 405);
            return;
        }
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload) || !isset($payload['page'])) {
            $this->sendJsonResponse(array('error' => 'Missing page'), 400);
            return;
        }
        $pageNum = (int) $payload['page'];
        if ($pageNum < 1) {
            $this->sendJsonResponse(array('error' => 'Invalid page number'), 400);
            return;
        }
        $answers = (isset($payload['answers']) && is_array($payload['answers'])) ? $payload['answers'] : array();

        $this->run = $this->getRun();
        $this->user = $this->loginUser();
        $runSession = new RunSession($this->user->user_code, $this->run, array('user' => $this->user));
        if (!$runSession->id) {
            $this->sendJsonResponse(array('error' => 'No active run session'), 403);
            return;
        }
        $unitSession = $runSession->getCurrentUnitSession();
        if (!$unitSession || !$unitSession->runUnit) {
            $this->sendJsonResponse(array('error' => 'No current unit session', 'drop_entry' => true), 409);
            return;
        }
        $runUnit = $unitSession->runUnit;
        if (!($runUnit instanceof Survey)) {
            $this->sendJsonResponse(array('error' => 'Current unit is not a form'), 409);
            return;
        }
        $study = method_exists($runUnit, 'getStudy') ? $runUnit->getStudy(true) : $runUnit->surveyStudy;
        if (!$study || empty($study->id)) {
            $this->sendJsonResponse(array('error' => 'No study'), 409);
            return;
        }

        // Per-session rate limit (shares the bucket with /form-r-call so a
        // hostile client can't bypass by alternating endpoints).
        $rateKey = 'form_v2_rcall_rate_' . (int) $runSession->id;
        $now = time();
        $bucket = Session::get($rateKey, null);
        if (!is_array($bucket) || !isset($bucket['window_start']) || ($now - (int) $bucket['window_start']) >= 60) {
            $bucket = array('window_start' => $now, 'count' => 0);
        }
        $bucket['count'] = (int) $bucket['count'] + 1;
        Session::set($rateKey, $bucket);
        if ($bucket['count'] > 30) {
            $this->sendJsonResponse(array('error' => 'Rate limit exceeded'), 429);
            return;
        }

        // Allowlisted r-calls for items on the requested page. The JOIN to
        // survey_items_display ensures we only resolve calls for items
        // actually scheduled on that page in this unit-session — a hostile
        // client passing a different page number can't resolve calls for
        // items not on that page (or not in their session at all).
        // Raw SQL — DB::select()/from() don't support `AS` aliases (quoteCol
        // wraps "table AS x" in one set of backticks → "`table AS x`" which
        // doesn't exist), and DB::join() only takes (table, condition);
        // earlier `->join(..., 'INNER')` got parsed as a second condition
        // and threw "Unable to get join condition clauses". Both stayed
        // latent until first-page label deferral made every initial render
        // hit this query with non-empty results.
        $stmt = DB::getInstance()->prepare(
            'SELECT r.id, r.slot, r.expr, r.item_id, i.name AS item_name'
            . ' FROM survey_r_calls r'
            . ' INNER JOIN survey_items_display d ON d.item_id = r.item_id'
            . ' INNER JOIN survey_items i ON i.id = r.item_id'
            . ' WHERE r.study_id = :study_id'
            . ' AND r.slot IN ("value", "label")'
            . ' AND d.session_id = :session_id'
            . ' AND d.page = :page'
        );
        $stmt->bindValue(':study_id', (int) $study->id, PDO::PARAM_INT);
        $stmt->bindValue(':session_id', (int) $unitSession->id, PDO::PARAM_INT);
        $stmt->bindValue(':page', (int) $pageNum, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            $this->sendJsonResponse(array('values' => array(), 'labels' => array()));
            return;
        }

        $valueCalls = array();
        $labelCalls = array();
        foreach ($rows as $row) {
            $callId = (int) $row['id'];
            $entry = array('item_id' => (int) $row['item_id'], 'expr' => (string) $row['expr']);
            if ($row['slot'] === 'value') {
                $valueCalls[$callId] = $entry;
            } elseif ($row['slot'] === 'label') {
                $labelCalls[$callId] = $entry;
            }
        }

        $values = $this->batchResolveValues($valueCalls, $answers, $unitSession, $study);
        $labels = $this->batchResolveLabels($labelCalls, $answers, $unitSession, $study);

        $valuesOut = array();
        foreach ($values as $callId => $val) {
            $valuesOut[(string) $callId] = self::rResultToScalarString($val);
        }
        $labelsOut = array();
        foreach ($labels as $callId => $html) {
            $labelsOut[(string) $callId] = $html;
        }

        $this->sendJsonResponse(array('values' => $valuesOut, 'labels' => $labelsOut));
    }

    /**
     * Batched value resolution: one OpenCPU call evaluates every requested
     * call_id's expression against the participant's data + the optional
     * answer overlay. Cache hits short-circuit; misses are evaluated and
     * stored. Returns map [call_id => result].
     */
    protected function batchResolveValues(array $valueCalls, array $answers, $unitSession, $study) {
        if (empty($valueCalls)) return array();

        $normalized = self::normalizeAnswersForHash($answers);
        $argsHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
        $now = time();
        $ttl = 300; // value cache TTL (matches single-call /form-fill path)
        $results = array();
        $misses = array();

        // Pull cached rows in one query (`call_id IN (...)` + same args_hash).
        $callIds = array_keys($valueCalls);
        if ($callIds) {
            $placeholders = implode(',', array_fill(0, count($callIds), '?'));
            $stmt = DB::getInstance()->prepare(
                "SELECT call_id, result_json, UNIX_TIMESTAMP(created_at) AS ts
                 FROM survey_r_call_results
                 WHERE call_id IN ($placeholders) AND args_hash = ?"
            );
            $i = 1;
            foreach ($callIds as $cid) {
                $stmt->bindValue($i++, (int) $cid, PDO::PARAM_INT);
            }
            $stmt->bindValue($i, $argsHash);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (($now - (int) $row['ts']) >= $ttl) continue;
                $decoded = json_decode((string) $row['result_json'], true);
                if (is_array($decoded) && array_key_exists('result', $decoded)) {
                    $results[(int) $row['call_id']] = $decoded['result'];
                }
            }
        }

        foreach ($valueCalls as $callId => $entry) {
            if (!array_key_exists($callId, $results)) {
                $misses[$callId] = $entry;
            }
        }

        if (!empty($misses)) {
            $survey_name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $study->name);
            if ($survey_name === '') {
                // Bad study name: bail; return what we have from cache.
                return $results;
            }
            $overlayR = self::formatROverlay($answers);
            // Build one R script that returns a named list keyed by call_id.
            $listEntries = array();
            foreach ($misses as $callId => $entry) {
                // Sanitize the key to a valid R identifier (call_id is int so
                // backtick-quoting is enough). Expr is admin-authored R from
                // the allowlist — already trusted.
                $listEntries[] = sprintf(
                    "`%d` = tryCatch({ %s }, error = function(e) NA)",
                    (int) $callId,
                    (string) $entry['expr']
                );
            }
            $code = "(function() {\n"
                  . "  .fmr.overlay <- {$overlayR}\n"
                  . "  .fmr.row <- tail({$survey_name}, 1)\n"
                  . "  for (.n in names(.fmr.overlay)) .fmr.row[[.n]] <- .fmr.overlay[[.n]]\n"
                  . "  with(.fmr.row, list(\n"
                  . "    " . implode(",\n    ", $listEntries) . "\n"
                  . "  ))\n"
                  . "})()\n";
            $variables = $unitSession->getRunData($code, $survey_name);
            $session = opencpu_evaluate($code, $variables, 'json', null, true);
            if ($session && !$session->hasError()) {
                $batched = $session->getJSONObject();
                if (is_array($batched)) {
                    foreach ($batched as $key => $val) {
                        $cid = (int) $key;
                        if (!isset($misses[$cid])) continue;
                        // R length-1 vector unwrap (matches single-call path).
                        if (is_array($val) && count($val) === 1 && array_keys($val) === array(0)) {
                            $val = $val[0];
                        }
                        $results[$cid] = $val;
                        // Cache write.
                        try {
                            $stmt = DB::getInstance()->prepare(
                                'REPLACE INTO `survey_r_call_results` (call_id, args_hash, result_json) '
                                . 'VALUES (:cid, :hash, :res)'
                            );
                            $stmt->bindValue(':cid', $cid);
                            $stmt->bindValue(':hash', $argsHash);
                            $stmt->bindValue(':res', json_encode(array('result' => $val), JSON_UNESCAPED_UNICODE));
                            $stmt->execute();
                        } catch (PDOException $e) {
                            formr_log('value batch cache write failed: ' . $e->getMessage());
                        }
                    }
                }
            } else {
                formr_log('batchResolveValues OpenCPU error: ' . (string) opencpu_last_error());
                // Misses stay missing — client substitutes empty / leaves placeholder.
            }
        }
        return $results;
    }

    /**
     * Batched label resolution: knit each label's R-Markdown via OpenCPU,
     * delimiting them v1-style (opencpu_multistring_parse). Returns map
     * [call_id => html].
     */
    protected function batchResolveLabels(array $labelCalls, array $answers, $unitSession, $study) {
        if (empty($labelCalls)) return array();

        $normalized = self::normalizeAnswersForHash($answers);
        $argsHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
        $now = time();
        $ttl = 300; // label cache TTL — labels rarely change per-keystroke; longer is fine
        $results = array();
        $misses = array();

        $callIds = array_keys($labelCalls);
        if ($callIds) {
            $placeholders = implode(',', array_fill(0, count($callIds), '?'));
            $stmt = DB::getInstance()->prepare(
                "SELECT call_id, result_json, UNIX_TIMESTAMP(created_at) AS ts
                 FROM survey_r_call_results
                 WHERE call_id IN ($placeholders) AND args_hash = ?"
            );
            $i = 1;
            foreach ($callIds as $cid) {
                $stmt->bindValue($i++, (int) $cid, PDO::PARAM_INT);
            }
            $stmt->bindValue($i, $argsHash);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (($now - (int) $row['ts']) >= $ttl) continue;
                $decoded = json_decode((string) $row['result_json'], true);
                if (is_array($decoded) && array_key_exists('result', $decoded)) {
                    $results[(int) $row['call_id']] = $decoded['result'];
                }
            }
        }

        foreach ($labelCalls as $callId => $entry) {
            if (!array_key_exists($callId, $results)) {
                $misses[$callId] = $entry;
            }
        }

        if (!empty($misses)) {
            // Concatenate label sources with the formr knit delimiter, knit,
            // then split. Same strategy as opencpu_multistring_parse, but we
            // also emit the answer overlay so labels see the latest state.
            $survey_name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $study->name);
            if ($survey_name === '') return $results;

            // Order matters — we map results back by index → call_id.
            $orderedCallIds = array_keys($misses);
            $sources = array();
            foreach ($orderedCallIds as $cid) {
                $sources[] = (string) $misses[$cid]['expr'];
            }
            $markdown = implode(OpenCPU::STRING_DELIMITER, $sources);

            // Apply the answer overlay onto tail(survey, 1) so inline R chunks
            // see the latest values. getRunData() returns a structured array
            // for opencpu_define_vars — we can't `.=` a string onto it (that
            // coerces to "Array" and the warning HTML breaks our JSON).
            // Instead, prepend a hidden knitr chunk to the markdown that
            // overlays the latest answers; matches batchResolveValues' shape.
            $opencpu_vars = $unitSession->getRunData($markdown, $study->name);
            if (!empty($answers)) {
                $overlayR = self::formatROverlay($answers);
                $overlayChunk = "```{r overlay,echo=FALSE,results='hide',message=FALSE,warning=FALSE}\n"
                              . ".fmr.overlay <- {$overlayR}\n"
                              . "if (exists('{$survey_name}') && nrow({$survey_name}) > 0) {\n"
                              . "  for (.n in names(.fmr.overlay)) {\n"
                              . "    if (.n %in% names({$survey_name})) {\n"
                              . "      {$survey_name}[nrow({$survey_name}), .n] <- .fmr.overlay[[.n]]\n"
                              . "    }\n"
                              . "  }\n"
                              . "}\n"
                              . "```\n";
                $markdown = $overlayChunk . $markdown;
            }

            $session = opencpu_knitdisplay($markdown, $opencpu_vars, true, $study->name);
            if ($session && !$session->hasError()) {
                $parsed = $session->getJSONObject();
                if (is_string($parsed)) {
                    $parts = explode(OpenCPU::STRING_DELIMITER_PARSED, $parsed);
                    $parts = array_map('remove_tag_wrapper', $parts);
                    foreach ($orderedCallIds as $idx => $cid) {
                        if (!isset($parts[$idx])) continue;
                        $html = (string) $parts[$idx];
                        $results[$cid] = $html;
                        try {
                            $stmt = DB::getInstance()->prepare(
                                'REPLACE INTO `survey_r_call_results` (call_id, args_hash, result_json) '
                                . 'VALUES (:cid, :hash, :res)'
                            );
                            $stmt->bindValue(':cid', $cid);
                            $stmt->bindValue(':hash', $argsHash);
                            $stmt->bindValue(':res', json_encode(array('result' => $html), JSON_UNESCAPED_UNICODE));
                            $stmt->execute();
                        } catch (PDOException $e) {
                            formr_log('label batch cache write failed: ' . $e->getMessage());
                        }
                    }
                }
            } else {
                formr_log('batchResolveLabels OpenCPU error: ' . (string) opencpu_last_error());
            }
        }
        return $results;
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

        // Per-session token-bucket rate limit. Reactive showif r-calls are
        // already debounced (300ms) and seq-guarded client-side, but a
        // malicious or buggy client could loop. 30 calls / 60s is enough
        // headroom for a participant typing naturally into a long form and
        // triggering every debounce window without being punitive. Bucket
        // state lives in the participant's PHP session so it survives
        // across requests but not across logouts/device changes — fine
        // because the limit is cheap to recover from.
        $rateKey = 'form_v2_rcall_rate_' . (int) $runSession->id;
        $now = time();
        $window = 60;
        $maxCalls = 30;
        $bucket = Session::get($rateKey, null);
        if (!is_array($bucket) || !isset($bucket['window_start']) || ($now - (int) $bucket['window_start']) >= $window) {
            $bucket = array('window_start' => $now, 'count' => 0);
        }
        $bucket['count'] = (int) $bucket['count'] + 1;
        Session::set($rateKey, $bucket);
        if ($bucket['count'] > $maxCalls) {
            return array('ok' => false, 'status' => 429, 'error' => 'Rate limit exceeded');
        }

        // Result cache lookup (patch 052). Keyed on (call_id, sha256 of a
        // normalized JSON encoding of the answers). TTL differs by slot:
        // showif is reactive and wants short-lived cache (30s) so an admin's
        // quick edit flows through; value is one-shot on page load and
        // benefits from a longer 5-minute TTL. Cache hits skip OpenCPU
        // entirely — big win on identical reactive-showif hammering.
        $ttl = ($expectedSlot === 'value') ? 300 : 30;
        $normalized = self::normalizeAnswersForHash($answers);
        $argsHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
        $cachedRow = DB::getInstance()
            ->select('result_json, UNIX_TIMESTAMP(created_at) AS ts')
            ->from('survey_r_call_results')
            ->where('call_id = :cid AND args_hash = :hash')
            ->bindParams(array('cid' => (int) $callId, 'hash' => $argsHash))
            ->fetch();
        if ($cachedRow && ($now - (int) $cachedRow['ts']) < $ttl) {
            $decoded = json_decode((string) $cachedRow['result_json'], true);
            if (is_array($decoded) && array_key_exists('result', $decoded)) {
                return array('ok' => true, 'result' => $decoded['result']);
            }
        }

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

        // Populate cache on successful evaluation. REPLACE so a stale row for
        // the same (call_id, args_hash) gets bumped to the current timestamp.
        try {
            $stmt = DB::getInstance()->prepare(
                'REPLACE INTO `survey_r_call_results` (call_id, args_hash, result_json) '
                . 'VALUES (:cid, :hash, :res)'
            );
            $stmt->bindValue(':cid', (int) $callId);
            $stmt->bindValue(':hash', $argsHash);
            $stmt->bindValue(':res', json_encode(array('result' => $result), JSON_UNESCAPED_UNICODE));
            $stmt->execute();
        } catch (PDOException $e) {
            // Cache write is best-effort — log and move on.
            formr_log('r-call cache write failed: ' . $e->getMessage());
        }

        return array('ok' => true, 'result' => $result);
    }

    /**
     * Normalize answers for cache-key hashing: sort keys, coerce to a stable
     * JSON shape so e.g. {a:1,b:2} and {b:2,a:1} hash identically. Arrays are
     * left as-is (order matters for list-typed answers).
     *
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    protected static function normalizeAnswersForHash(array $answers) {
        ksort($answers);
        return $answers;
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
