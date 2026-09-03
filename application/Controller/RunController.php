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

        // Force a fresh session when the URL carries ?_new_session=1. This
        // lets a *static* link / QR code (which cannot embed a per-rater session
        // code) start a clean traversal on every visit: e.g. an external
        // rating run scanned by many people, or one person rating several
        // targets in turn. We mint a new random code and 302 to its
        // ?code= form, dropping _new_session and keeping any other params
        // (so a target like ?rated=P1 survives to the survey's get items).
        // Access is still governed by Run::exec()'s public>=2 gate on the
        // redirected request, so this grants no capability a plain
        // no-cookie visit doesn't already have. Runs before the cookie
        // self-heal below so a fresh code wins over resuming the cookie's
        // session; the redirect consumes the trigger exactly once.
        if ($pageNo === null && Request::isHTTPGetRequest()
                && $this->run->valid
                && !empty($_GET['_new_session'])) {
            $params = array('code' => crypto_token(48));
            foreach ($_GET as $k => $v) {
                if (in_array($k, array('route', 'run_name', 'code', '_new_session'), true)) continue;
                $params[$k] = $v;
            }
            $this->request->redirect(run_url($this->run->name) . '?' . http_build_query($params));
            return;
        }

        // Migration self-heal for existing PWA installs that captured a
        // pre-tokenization start_url. If the request has no ?code= query
        // but the cookie identifies a participant who already has a
        // session in this run, 302 to the tokenized form so the URL bar
        // (and any in-PWA history.replaceState) carries the code from
        // here on. iOS keeps us in the standalone shell because the
        // redirect is same-scope. Once the URL has the token, future
        // launches survive cookie eviction. GET-only, idempotent, runs
        // before Run::exec so we don't double-render the unit.
        if ($pageNo === null && Request::isHTTPGetRequest()
                && empty($_GET['code'])
                && $this->run->valid) {
            $hasSessionInRun = false;
            if (!empty($this->user->user_code)) {
                $sessionRow = DB::getInstance()
                    ->select('id')
                    ->from('survey_run_sessions')
                    ->where(array(
                        'session' => $this->user->user_code,
                        'run_id' => $this->run->id,
                    ))
                    ->fetch();
                $hasSessionInRun = (bool) $sessionRow;
            }

            if ($hasSessionInRun) {
                // Cookie self-heal (existing PWA install with alive cookie).
                // Drop mod_rewrite's route= / run_name= artifacts; keep
                // explicit params like _pwa=true so the standalone marker
                // survives.
                $params = array('code' => $this->user->user_code);
                foreach ($_GET as $k => $v) {
                    if ($k === 'route' || $k === 'run_name' || $k === 'code') continue;
                    $params[$k] = $v;
                }
                $target = run_url($this->run->name) . '?' . http_build_query($params);
                $this->request->redirect($target);
                return;
            }

            // Worst-case fallback: PWA shell launched at clean URL with no
            // recoverable cookie session. Show a code-paste recovery form
            // so the participant can re-establish the session in-shell
            // rather than getting dumped into the public landing flow
            // (or, for non-public runs, the "you cannot access" alert).
            // Gate on _pwa=true so non-PWA traffic falls through to the
            // existing public flow unchanged.
            if (!empty($_GET['_pwa'])) {
                return $this->renderPwaSessionRecovery();
            }
        }

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
     * Serve the manifest file with appropriate headers for the run.
     *
     * If `?code=<participant_session>` is supplied AND validates against a
     * survey_run_sessions row in this run, the manifest is personalized:
     * `start_url`, `id`, every `shortcuts[].url`, and every
     * `protocol_handlers[].url` gets `?code=X` appended. iOS captures
     * `start_url` into the home-screen icon at install time, so a
     * tokenized install is recoverable later even if the
     * formr_session cookie evicts (ITP cap, storage pressure, Safari ↔
     * standalone container isolation). Without participant context the
     * un-personalized manifest is served unchanged — public installs
     * still work.
     *
     * Invalid codes 404 rather than 401: a malformed code shouldn't
     * surface as an install-flow auth error, and the genuine "no code"
     * path remains a successful 200.
     *
     * Source: the on-disk manifest_json file written by generateManifest
     * (or by the admin's manifest_json textarea in run settings) is the
     * base; admin customizations to e.g. theme_color, name, icons are
     * preserved and only the URL fields are touched per request.
     */
    public function manifestAction() {
        $run = $this->getRun();
        if (!$run || !$run->valid) {
            header('HTTP/1.0 404 Not Found');
            header('Content-Type: text/plain');
            echo "Manifest not found";
            return;
        }

        $jsonText = $run->getManifestJSON();
        if (!$jsonText || trim($jsonText) === '') {
            header('HTTP/1.0 404 Not Found');
            header('Content-Type: text/plain');
            echo "Manifest not found";
            return;
        }

        $manifest = json_decode($jsonText, true);
        if (!is_array($manifest)) {
            header('HTTP/1.0 500 Internal Server Error');
            header('Content-Type: text/plain');
            echo "Manifest could not be parsed";
            return;
        }

        $code = isset($_GET['code']) ? (string) $_GET['code'] : '';
        if ($code !== '') {
            $code_rule = Config::get('user_code_regular_expression');
            $codeOk = $code_rule && preg_match($code_rule, $code);
            if ($codeOk) {
                $exists = DB::getInstance()
                    ->select('id')
                    ->from('survey_run_sessions')
                    ->where(array('session' => $code, 'run_id' => $run->id))
                    ->fetch();
                $codeOk = (bool) $exists;
            }
            if (!$codeOk) {
                header('HTTP/1.0 404 Not Found');
                header('Content-Type: text/plain');
                echo "Manifest not found";
                return;
            }
            $this->personalizeManifest($manifest, $run, $code);
        }

        header('Content-Type: application/manifest+json');
        // private = single-user cache only; no-store = don't keep on disk.
        // The personalized variant must NEVER be served to a different
        // participant via an intermediate proxy.
        header('Cache-Control: private, no-store');
        echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Render the in-PWA "session expired" recovery page.
     *
     * Reached when the standalone shell (`_pwa=true`) launches at the
     * clean run URL with no `?code=` and no cookie-resolvable session in
     * this run — the worst-case post-cookie-eviction state. Shows a
     * code-paste form that GETs the same run URL with the supplied code
     * so the participant can recover without leaving the PWA shell or
     * hunting through Safari for the original email link.
     */
    private function renderPwaSessionRecovery() {
        $form_action = h(run_url($this->run->name));
        // Pattern attribute is derived from the configured
        // user_code_regular_expression so customized deployments still
        // get accurate client-side validation. Server-side loginUser()
        // remains authoritative.
        $code_pattern = user_code_html_pattern();
        $pattern_attr = $code_pattern !== ''
            ? ' pattern="' . h($code_pattern) . '"'
            : '';
        $run_content = '<h1>' . __('Session expired') . '</h1>'
            . '<p>' . __('Your saved sign-in to this study has expired. '
                       . 'Please open the latest invitation email link, '
                       . 'or paste your participant code below to continue.') . '</p>'
            . '<form method="get" action="' . $form_action . '" class="pwa-recovery-form" '
            . '      style="margin-top:1.5em;max-width:480px">'
            . '<label for="pwa-recovery-code" style="display:block;margin-bottom:.25em;font-weight:600">'
            . h(__('Participant code')) . '</label>'
            . '<input type="text" id="pwa-recovery-code" name="code" autofocus required '
            . '       autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false"'
            . $pattern_attr
            . '       style="width:100%;padding:.6em;border:1px solid #ccc;border-radius:4px;'
            . 'font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:1em">'
            . '<input type="hidden" name="_pwa" value="true">'
            . '<button type="submit" class="btn btn-primary" '
            . '        style="margin-top:.75em;padding:.5em 1.25em">'
            . h(__('Continue')) . '</button>'
            . '</form>';

        $this->setView('run/static_page', array(
            'run_content' => $run_content,
            'bodyClass' => 'fmr-run fmr-pwa-recovery',
        ));
        return $this->sendResponse();
    }

    /**
     * Append ?code=X (or &code=X for URLs that already carry a query) to
     * every URL field in the manifest that should re-establish the
     * participant's session on launch / shortcut activation. Mutates
     * \$manifest in place.
     */
    private function personalizeManifest(array &$manifest, Run $run, $code) {
        $base = run_url($run->name);
        $tokenized = $base . '?code=' . urlencode($code);
        $manifest['start_url'] = $tokenized;
        $manifest['id'] = $tokenized;
        $appendCode = function ($url) use ($code) {
            if (!is_string($url) || $url === '') return $url;
            $sep = (strpos($url, '?') === false) ? '?' : '&';
            return $url . $sep . 'code=' . urlencode($code);
        };
        if (isset($manifest['shortcuts']) && is_array($manifest['shortcuts'])) {
            foreach ($manifest['shortcuts'] as &$sc) {
                if (is_array($sc) && isset($sc['url'])) {
                    $sc['url'] = $appendCode($sc['url']);
                }
            }
            unset($sc);
        }
        if (isset($manifest['protocol_handlers']) && is_array($manifest['protocol_handlers'])) {
            foreach ($manifest['protocol_handlers'] as &$ph) {
                if (is_array($ph) && isset($ph['url'])) {
                    $ph['url'] = $appendCode($ph['url']);
                }
            }
            unset($ph);
        }
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
        $claimedUnitSessionId = 0;
        if ($isMultipart) {
            $submittedPage = isset($_POST['page']) ? (int) $_POST['page'] : 1;
            $claimedUnitSessionId = isset($_POST['unit_session_id']) ? (int) $_POST['unit_session_id'] : 0;
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
            $claimedUnitSessionId = isset($payload['unit_session_id']) ? (int) $payload['unit_session_id'] : 0;
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

        if (!$this->formWriteRateAllowed((int) $runSession->id)) {
            $this->sendJsonResponse(array('error' => 'Rate limit exceeded'), 429);
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

        // Bind the submit to the unit session the page was rendered for (the
        // form ships its unit-session id in a hidden field). Without this gate
        // a stale tab from an earlier iteration of a repeated Form (diary runs)
        // posts into *today's* unit session — same study, same item names,
        // silent cross-session corruption. Mirrors formSyncAction's
        // claimedUnitSessionId gate; 0 (older clients) is accepted leniently.
        if ($claimedUnitSessionId > 0 && $claimedUnitSessionId !== (int) $unitSession->id) {
            $this->sendJsonResponse(array(
                'error' => 'This form was rendered for a different session (continued elsewhere or a new entry started). Reload to continue.',
                'stale_session' => true,
            ), 409);
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

        // Mark client-hidden conditional items as resolved-hidden so they stop
        // blocking completion. v2 showifs are evaluated client-side (Alpine); the
        // server can't resolve a showif whose dependency is unanswered (e.g. an
        // item behind a random group, or a `block` guard once its inputs are
        // satisfied) and would otherwise leave it hidden=NULL = "not answered"
        // forever — so the survey could never finish ("block recurs on the last
        // page", "can't finish"). The client reports its CURRENT showif visibility
        // in `_item_views[hidden]` (ids whose wrapper carries data-fmr-hidden);
        // record exactly those as hidden. (Using current visibility, NOT inverting
        // `_item_views[shown]`: that stamp fires once an item flashes into view at
        // load before Alpine hides it, so a shown-then-hidden item looks shown.)
        // Answered items are already `saved` and skipped by the helper, and a
        // shown-but-unanswered required item is never in this set, so genuine
        // required gaps still block — correctly.
        $hiddenIds = array();
        if (isset($itemViews['hidden']) && is_array($itemViews['hidden'])) {
            $hiddenIds = array_map('intval', array_keys($itemViews['hidden']));
        }
        if ($hiddenIds) {
            $this->markClientHiddenItems((int) $unitSession->id, (int) $submittedPage, $hiddenIds);
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
     * form_v2: incremental ("autosave") persistence.
     *
     * URL: POST /{runName}/form-save  — JSON only: {data:{...}, item_views:{...}}.
     *
     * Writes whatever answers the participant has entered so far WITHOUT
     * page-completion semantics: no required-gating, no page advance, no
     * redirect. So a mid-page breakoff (close tab, lose signal, walk away) still
     * yields partial data — the spec's "persist on advance/blur, not only on
     * explicit submit." Idempotent: updateSurveyStudyRecord upserts
     * survey_items_display by (session_id, item_id) and the results row by
     * (study_id, session_id), so a later validated page-submit cleanly
     * overwrites. Best-effort by design — the client rate-limits these and
     * treats failures as non-fatal (the explicit submit + offline queue are the
     * durable path). Files are not autosaved (they ride the explicit submit).
     */
    public function formSaveAction() {
        if (!Request::isHTTPPostRequest()) {
            $this->sendJsonResponse(array('error' => 'Method Not Allowed'), 405);
            return;
        }
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $this->sendJsonResponse(array('error' => 'Invalid JSON body'), 400);
            return;
        }
        $data = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : array();
        $itemViews = (isset($payload['item_views']) && is_array($payload['item_views'])) ? $payload['item_views'] : array();
        $claimedUnitSessionId = isset($payload['unit_session_id']) ? (int) $payload['unit_session_id'] : 0;

        // Keep only actually-answered fields. The client's collectPayload also
        // emits the empty hidden placeholder of each mc/mc_multiple item
        // (name=..., value=""), and writing "" raw to a typed results column
        // (e.g. a TINYINT mc) errors; it would also mark an unanswered REQUIRED
        // item as saved and let the participant bypass it. Dropping empties here
        // means autosave persists only what's been entered.
        $clean = array();
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $v = array_values(array_filter($v, function ($x) { return $x !== '' && $x !== null; }));
                if ($v) $clean[$k] = $v;
            } elseif ($v !== '' && $v !== null) {
                $clean[$k] = $v;
            }
        }
        $data = $clean;
        if (!$data) {
            $this->sendJsonResponse(array('status' => 'noop'));
            return;
        }

        $this->run = $this->getRun();
        $this->user = $this->loginUser();
        $runSession = new RunSession($this->user->user_code, $this->run, array('user' => $this->user));
        if (!$runSession->id) {
            $this->sendJsonResponse(array('error' => 'No active run session'), 403);
            return;
        }
        if (!$this->formWriteRateAllowed((int) $runSession->id)) {
            $this->sendJsonResponse(array('error' => 'Rate limit exceeded'), 429);
            return;
        }
        $unitSession = $runSession->getCurrentUnitSession();
        if (!$unitSession || !$unitSession->runUnit) {
            $this->sendJsonResponse(array('error' => 'No current unit session'), 409);
            return;
        }
        if (!($unitSession->runUnit instanceof Survey)) {
            $this->sendJsonResponse(array('error' => 'Current unit is not a survey/form'), 409);
            return;
        }

        // Same stale-tab gate as formPageSubmitAction: an autosave from a page
        // rendered for an earlier unit session (repeated Form / diary) must not
        // bleed into the current one. Autosave is best-effort, so the client
        // treats the 409 as non-fatal.
        if ($claimedUnitSessionId > 0 && $claimedUnitSessionId !== (int) $unitSession->id) {
            $this->sendJsonResponse(array(
                'error' => 'Autosave targets a different unit session',
                'stale_session' => true,
            ), 409);
            return;
        }

        // survey_items_display rows already exist from the GET render; we only
        // UPDATE them. validate=false → save the partial answers as-is; quiet=true
        // → a lost race is silent (no user error, no admin email).
        $posted = $data;
        $posted['_item_views'] = $itemViews;
        $unitSession->updateSurveyStudyRecord($posted, false, true);

        $this->sendJsonResponse(array('status' => 'saved'));
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
     * Record the client's showif visibility decision for a just-submitted page.
     * `$hiddenIds` are the item ids the client currently HIDES (their wrapper
     * carries data-fmr-hidden); set hidden=1 for them so they no longer count as
     * unanswered (not_answered) and the form can complete. Scoped to the page and
     * to unsaved items, so it can never overwrite a real answer.
     */
    private function markClientHiddenItems($unitSessionId, $page, array $hiddenIds) {
        $hiddenIds = array_values(array_unique(array_filter(array_map('intval', $hiddenIds))));
        if (!$hiddenIds) {
            return;
        }
        // Only showif-bearing items are legitimately client-hideable: the whole
        // reason this endpoint trusts the client is that v2 showifs resolve in
        // the browser. An item with no showif can never be hidden by Alpine, so
        // a claim on one is a forged payload trying to skip a required item —
        // the JOIN makes such claims a silent no-op while honest claims still
        // land. Soft-deleted items are excluded for the same reason: they don't
        // render, so no honest client can be hiding them.
        $stmt = DB::getInstance()->prepare(
            'UPDATE `survey_items_display` `d`
               JOIN `survey_items` `i` ON `i`.`id` = `d`.`item_id`
             SET `d`.`hidden` = 1
             WHERE `d`.`session_id` = :sid
               AND `d`.`page` = :p
               AND `d`.`saved` IS NULL
               AND `i`.`showif` IS NOT NULL AND TRIM(`i`.`showif`) != \'\'
               AND `i`.`deleted` IS NULL
               AND `d`.`item_id` IN (' . implode(',', $hiddenIds) . ')'
        );
        $stmt->bindValue(':sid', (int) $unitSessionId, PDO::PARAM_INT);
        $stmt->bindValue(':p', (int) $page, PDO::PARAM_INT);
        $stmt->execute();
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

        $this->run = $this->getRun();
        $this->user = $this->loginUser();

        $runSession = new RunSession($this->user->user_code, $this->run, array('user' => $this->user));
        if (!$runSession->id) {
            $this->sendJsonResponse(array('error' => 'No active run session'), 403);
            return;
        }

        if (!$this->formWriteRateAllowed((int) $runSession->id)) {
            // 429 without drop_entry: the client's drain treats it as a
            // validation-ish stop and retries later, which is what we want.
            $this->sendJsonResponse(array('error' => 'Rate limit exceeded'), 429);
            return;
        }

        // Dedup pre-check: if we've already persisted this uuid, short-circuit
        // so retries are safe and cheap. The uuid is unique-indexed so a true
        // race ends in a constraint error on INSERT below rather than a
        // double-apply — the apply is still best-effort idempotent per item.
        // Deliberately AFTER the session gates so an unauthenticated request
        // can't probe the ledger as a uuid-existence oracle.
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

        // Same hidden-item resolution as formPageSubmitAction: the queued page
        // may contain client-side showif-hidden items, which would otherwise
        // stay hidden=NULL = "not answered" and block studyCompleted() forever
        // once the queue drains.
        $hiddenIds = array();
        if (isset($itemViews['hidden']) && is_array($itemViews['hidden'])) {
            $hiddenIds = array_map('intval', array_keys($itemViews['hidden']));
        }
        if ($hiddenIds) {
            $this->markClientHiddenItems((int) $unitSession->id, (int) $submittedPage, $hiddenIds);
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
        $this->sendJsonResponse(array('result' => RCallService::rResultToBool($out['result'])));
    }

    /**
     * bot_check (Altcha): mint a fresh, session-bound proof-of-work challenge.
     *
     * URL: GET /{runName}/form-bot-challenge
     *
     * The <altcha-widget challenge="…/form-bot-challenge"> fetches this lazily
     * when it mounts (NOT at form render), so the challenge is fresh when the
     * participant reaches the bot_check — sidestepping the form_v2 "render every
     * page at once" staleness bug. The challenge is bound to the current
     * participant (user_code folded into the signed `data`); the same session
     * cookie is present here as at submit time, so BotCheckChallenge::subject()
     * resolves identically. Everything is computed on this server — no third
     * party, no PII leaves the box.
     *
     * Gated like the other form_v2 endpoints: an active run session is required
     * and the mint is rate limited per run session. A challenge minted without a
     * session was never usable anyway — it binds to the empty subject, and by
     * submit time subject() is a real user_code, so verify() rejects it — but as
     * an open endpoint it was a free HMAC-minting oracle for anyone who knew a
     * run name.
     */
    public function formBotChallengeAction() {
        if (!Request::isHTTPGetRequest()) {
            $this->sendJsonResponse(array('error' => 'Method Not Allowed'), 405);
            return;
        }
        $this->run = $this->getRun();
        // Resolve the participant so the challenge binds to THIS session.
        $this->user = $this->loginUser();

        // Ownership: only a participant with a live session in this run may mint.
        // The widget only ever mounts inside a rendered form, which already
        // requires one, so this costs no legitimate caller anything. (The
        // /survey-test/ preview path rewrites the run URL, so its widget never
        // reached this endpoint in the first place.)
        $runSession = new RunSession($this->user->user_code, $this->run, array('user' => $this->user));
        if (!$runSession->id) {
            $this->sendJsonResponse(array('error' => 'No active run session'), 403);
            return;
        }
        if (!$this->botChallengeRateAllowed((int) $runSession->id)) {
            $this->sendJsonResponse(array('error' => 'Rate limit exceeded'), 429);
            return;
        }

        // Optional per-item difficulty (prefix bytes). mint() clamps to [1,3] and
        // the HMAC signature pins it, so a tampered value can't weaken the gate.
        $difficulty = isset($_GET['difficulty']) && is_numeric($_GET['difficulty'])
            ? (int) $_GET['difficulty'] : null;
        $challenge = BotCheckChallenge::mint($difficulty);
        if ($challenge === null) {
            // Server can't sign (no crypto key / unreadable key file). This is a
            // misconfiguration, not a participant problem — but it fails CLOSED:
            // verify() refuses every token while the secret is missing, so the
            // bot_check item blocks the page rather than waving everyone through.
            // Log it loudly; it is otherwise invisible until participants report
            // being stuck.
            formr_log(
                'bot_check unavailable: BotCheckChallenge cannot sign (no bot_check_secret and no readable '
                . 'encryption key file). Participants on a bot_check item are blocked until this is fixed. run='
                . (string) $this->run->name,
                'bot_check'
            );
            $this->sendJsonResponse(array('error' => 'bot_check_unavailable'), 503);
            return;
        }
        // Discourage caching/proxy storage of a one-shot challenge.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        $this->sendJsonResponse($challenge);
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
        $this->sendJsonResponse(array('value' => RCallService::rResultToScalarString($out['result'])));
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

        // Shares the r-call bucket so a hostile client can't multiply its
        // budget by alternating endpoints.
        if (!$this->rcallRateAllowed((int) $runSession->id)) {
            $this->sendJsonResponse(array('error' => 'Rate limit exceeded'), 429);
            return;
        }

        // The service scopes calls to items actually scheduled on that page
        // in THIS unit-session and applies the allowlist revocation check.
        $service = new RCallService($unitSession, $study);
        $calls = $service->callsForPage($pageNum);
        if (empty($calls['value']) && empty($calls['label'])) {
            $this->sendJsonResponse(array('values' => array(), 'labels' => array()));
            return;
        }

        $values = $service->resolveExpressions($calls['value'], $answers, RCallService::ttlForSlot('value'));
        $labels = $service->resolveLabels($calls['label'], $answers, RCallService::ttlForSlot('label'));

        $valuesOut = array();
        foreach ($values as $callId => $val) {
            $valuesOut[(string) $callId] = RCallService::rResultToScalarString($val);
        }
        $labelsOut = array();
        foreach ($labels as $callId => $html) {
            $labelsOut[(string) $callId] = $html;
        }

        $this->sendJsonResponse(array('values' => $valuesOut, 'labels' => $labelsOut));
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

        // Reactive r-calls are debounced (300ms) and seq-guarded client-side,
        // but a malicious or buggy client could loop. DB-keyed bucket (patch
        // 076) — the old $_SESSION bucket reset with every session-cookie
        // rotation.
        if (!$this->rcallRateAllowed((int) $runSession->id)) {
            return array('ok' => false, 'status' => 429, 'error' => 'Rate limit exceeded');
        }

        $service = new RCallService($unitSession, $study);
        return $service->evaluateSingle((int) $callId, $expectedSlot, $answers);
    }

    /**
     * Shared r-call/fill/render-page budget: one bucket per run session so a
     * hostile client can't multiply its budget by alternating endpoints.
     * 30/min default is generous for a participant typing through every
     * debounce window of a long form; override via
     * $settings['form_v2_rate_limits']['rcall_per_min'].
     */
    private function rcallRateAllowed($runSessionId) {
        $limits = Config::get('form_v2_rate_limits', array());
        $max = isset($limits['rcall_per_min']) ? (int) $limits['rcall_per_min'] : 30;
        return FixedWindowRateLimiter::allow('rcall:' . (int) $runSessionId, $max, 60);
    }

    /**
     * Budget for the v2 write endpoints (form-page-submit, form-save,
     * form-sync). Wide headroom — autosave is client-throttled to 1/20s and a
     * fast participant submits well under a page a second — but it stops a
     * scripted client from hammering DB writes indefinitely.
     */
    private function formWriteRateAllowed($runSessionId) {
        $limits = Config::get('form_v2_rate_limits', array());
        $max = isset($limits['write_per_min']) ? (int) $limits['write_per_min'] : 90;
        return FixedWindowRateLimiter::allow('formwrite:' . (int) $runSessionId, $max, 60);
    }

    /**
     * Budget for minting bot_check challenges (/form-bot-challenge). A
     * participant needs one mint per bot_check item, and the widget is
     * auto="off" so it only fetches on click — 20/min is far more than any
     * human produces, while still bounding a scripted client to something that
     * can't be used as a free HMAC-minting oracle. Own bucket rather than
     * sharing `formwrite`, so a burst of challenge fetches can never eat a
     * participant's page-submit budget (or vice versa). Override via
     * $settings['form_v2_rate_limits']['bot_challenge_per_min'].
     */
    private function botChallengeRateAllowed($runSessionId) {
        $limits = Config::get('form_v2_rate_limits', array());
        $max = isset($limits['bot_challenge_per_min']) ? (int) $limits['bot_challenge_per_min'] : 20;
        return FixedWindowRateLimiter::allow('botchallenge:' . (int) $runSessionId, $max, 60);
    }

    /**
     * Service-worker / PWA telemetry beacon. Endpoint exists so SW
     * install / activate failures surface in formr's error log instead
     * of dying silently in the participant's browser console (where we
     * never see them). No auth: SW context can't carry session cookies
     * reliably across all browsers, so we log the bare report as-is.
     * The participant origin already gates blast radius; we accept
     * anonymous reports from anyone in-scope. Returns 204.
     *
     * Payload is whatever the SW POSTed (small JSON). We log it
     * verbatim plus User-Agent + remote IP for triage.
     */
    public function pwaBeaconAction()
    {
        if (!Request::isHTTPPostRequest()) {
            http_response_code(405);
            return;
        }
        // Unauthenticated endpoint — rate limit per IP so it can't be used to
        // flood formr_log / disk. Fail-open like the other buckets.
        $limits = Config::get('form_v2_rate_limits', array());
        $beaconMax = isset($limits['beacon_per_min']) ? (int) $limits['beacon_per_min'] : 10;
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        if (!FixedWindowRateLimiter::allow('beacon:' . $ip, $beaconMax, 60)) {
            http_response_code(429);
            return;
        }
        $raw = file_get_contents('php://input');
        // Cap at 4 KB — beacons are small structured JSON, not bug reports.
        $raw = $raw === false ? '' : substr((string) $raw, 0, 4096);
        $this->run = $this->getRun();
        $context = [
            'run' => $this->run ? $this->run->name : null,
            'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 256),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ];
        formr_log('PWA beacon ' . json_encode($context) . ' body=' . $raw);
        http_response_code(204);
        exit;
    }
}
