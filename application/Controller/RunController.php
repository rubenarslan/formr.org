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

        $this->setView('run/index', array_merge($run_vars, $asset_vars));

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
        // Pattern matches Config::get('user_code_regular_expression')
        // loosely (the server-side check in loginUser is authoritative);
        // we keep the input forgiving so a participant who pastes with
        // surrounding whitespace still gets to submit.
        $run_content = '<h1>' . __('Session expired') . '</h1>'
            . '<p>' . __('Your saved sign-in to this study has expired. '
                       . 'Please open the latest invitation email link, '
                       . 'or paste your participant code below to continue.') . '</p>'
            . '<form method="get" action="' . $form_action . '" class="pwa-recovery-form" '
            . '      style="margin-top:1.5em;max-width:480px">'
            . '<label for="pwa-recovery-code" style="display:block;margin-bottom:.25em;font-weight:600">'
            . h(__('Participant code')) . '</label>'
            . '<input type="text" id="pwa-recovery-code" name="code" autofocus required '
            . '       autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" '
            . '       pattern="[A-Za-z0-9+\\-_~]{8,64}" '
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
}
