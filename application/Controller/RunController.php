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
     * form_v2 page-submit endpoint.
     *
     * URL: POST /{runName}/form-page-submit
     * Body: JSON or multipart, see FormSubmissionService::readPayload.
     *
     * The gates live here (formWriteContext); FormSubmissionService::apply
     * stores the page and answers with the stored state of what it wrote —
     * `{status:'ok', items}` — which the browser merges into its state block
     * and derives the next page from. When the browser flagged the page as
     * the form's last (`final`), the unit session has been ended and the
     * reply carries `redirect` so the run can advance, or `missing` (name →
     * message) when a required item is still unanswered. Validation errors
     * are `{status:'errors', errors:{name: msg}}` and nothing was written.
     */
    public function formPageSubmitAction() {
        if (!Request::isHTTPPostRequest()) {
            $this->sendJsonResponse(array('error' => 'Method Not Allowed'), 405);
            return;
        }
        $payload = FormSubmissionService::readPayload(
            isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
            file_get_contents('php://input'), $_POST, $_FILES
        );
        if ($payload === null) {
            $this->sendJsonResponse(array('error' => 'Invalid JSON body'), 400);
            return;
        }
        $context = $this->formWriteContext($payload['unit_session_id']);
        if (!$context) {
            return;
        }
        list(, $unitSession, $study) = $context;
        $result = (new FormSubmissionService($unitSession, $study))->apply($payload);
        $this->sendJsonResponse($this->withRunRedirect($result));
    }

    /**
     * The gates every v2 write shares: an active run session, the write
     * rate limit, a current unit session that is a v2 Form, and — when the
     * browser names the unit session its page was rendered for — that it is
     * still the current one (a page rendered for an earlier unit session of
     * a repeated Form / diary must not write into the new one). Sends the
     * error reply and returns null when a gate fails; otherwise
     * [$runSession, $unitSession, $study].
     *
     * @param int $claimedUnitSessionId 0 = not claimed
     * @param array $extra409 keys merged into every 409 reply (the sync
     *   endpoint adds drop_entry so the queue stops retrying the entry)
     * @param string $rateBucket 'write' (form writes), 'rcall' (R calls) or
     *   'bot' (bot_check challenges)
     * @return array|null
     */
    private function formWriteContext($claimedUnitSessionId, array $extra409 = array(), $rateBucket = 'write') {
        $this->run = $this->getRun();
        $this->user = $this->loginUser();
        $runSession = new RunSession($this->user->user_code, $this->run, array('user' => $this->user));
        if (!$runSession->id) {
            $this->sendJsonResponse(array('error' => 'No active run session'), 403);
            return null;
        }
        switch ($rateBucket) {
            case 'rcall': $allowed = $this->rcallRateAllowed((int) $runSession->id); break;
            case 'bot': $allowed = $this->botChallengeRateAllowed((int) $runSession->id); break;
            default: $allowed = $this->formWriteRateAllowed((int) $runSession->id);
        }
        if (!$allowed) {
            $this->sendJsonResponse(array('error' => 'Rate limit exceeded'), 429);
            return null;
        }
        $unitSession = $runSession->getCurrentUnitSession();
        if (!$unitSession || !$unitSession->runUnit) {
            $this->sendJsonResponse(array('error' => 'No current unit session') + $extra409, 409);
            return null;
        }
        $study = $this->formV2Study($unitSession->runUnit);
        if (!$study) {
            $this->sendJsonResponse(array('error' => 'Current unit is not a v2 form') + $extra409, 409);
            return null;
        }
        if ((int) $claimedUnitSessionId > 0 && (int) $claimedUnitSessionId !== (int) $unitSession->id) {
            $this->sendJsonResponse(array(
                'error' => 'This form was rendered for a different session (continued elsewhere or a new entry started). Reload to continue.',
                'stale_session' => true,
            ) + $extra409, 409);
            return null;
        }
        return array($runSession, $unitSession, $study);
    }

    /**
     * A completed form's reply sends the browser back to the run URL, where
     * Run::exec advances past the ended unit session.
     */
    private function withRunRedirect(array $result) {
        if (!empty($result['complete'])) {
            $result['redirect'] = run_url($this->run->name);
        }
        return $result;
    }
    /**
     * form_v2: incremental ("autosave") persistence.
     *
     * URL: POST /{runName}/form-save  — JSON only: {data:{...}, item_views:{...}}.
     *
     * Writes the answers the participant has entered so far WITHOUT
     * page-completion semantics: no required-gating (unanswered items are just
     * not in the payload), no page advance, no redirect. So a mid-page breakoff
     * (close tab, lose signal, walk away) still yields partial data — the
     * spec's "persist on advance/blur, not only on explicit submit."
     * Idempotent: updateSurveyStudyRecord upserts survey_items_display by
     * (session_id, item_id) and the results row by (study_id, session_id), so a
     * later validated page-submit cleanly overwrites. Best-effort by design —
     * the client rate-limits these and treats failures as non-fatal (the
     * explicit submit + offline queue are the durable path).
     *
     * Every autosaved value goes through the item's own validateInput() first,
     * exactly as on page-submit: a value that fails validation is dropped (not
     * saved, not an error: autosave is best-effort), and items whose
     * validation has side effects or must only ever be judged at submit —
     * bot_check, file/image/audio/video uploads — are never autosaved at all
     * (see autosaveEligible()). Otherwise anyone could persist an unverified
     * bot_check token, an out-of-range choice or an arbitrary file string.
     *
     * An autosaved item gets `survey_items_display.saved` stamped like a
     * submitted one: `saved` is the per-item "when this answer was stored"
     * timestamp, nothing more. Which page the participant is on is the
     * browser's business (state.js), so an autosaved item is rendered and
     * editable exactly as before a reload.
     */
    public function formSaveAction() {
        if (!Request::isHTTPPostRequest()) {
            $this->sendJsonResponse(array('error' => 'Method Not Allowed'), 405);
            return;
        }
        $payload = FormSubmissionService::readPayload(
            isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
            file_get_contents('php://input'), $_POST, $_FILES
        );
        if ($payload === null) {
            $this->sendJsonResponse(array('error' => 'Invalid JSON body'), 400);
            return;
        }
        $data = $payload['data'];
        $itemViews = $payload['item_views'];

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

        // Same gates as form-page-submit, including the stale-tab one. Autosave
        // is best-effort, so the client treats a 409 as non-fatal.
        $context = $this->formWriteContext($payload['unit_session_id']);
        if (!$context) {
            return;
        }
        list(, $unitSession, $study) = $context;

        // Keep only values that (a) belong to an item this study actually has,
        // (b) belong to an item type that may be autosaved, and (c) pass that
        // item's own validateInput(). Anything else is silently dropped — the
        // explicit page-submit is where the participant sees errors.
        $items = $study->getItemsWithChoices(null, array(
            'field' => 'name',
            'values' => array_keys($data),
        ));
        $accepted = self::filterAutosaveAnswers($data, $items);
        if (!$accepted) {
            $this->sendJsonResponse(array('status' => 'noop'));
            return;
        }

        // survey_items_display rows already exist from the GET render; we only
        // UPDATE them. validate=true → the same per-item checks as page-submit
        // (every value here already passed them, so this cannot fail on
        // validation); quiet=true → a lost race is silent (no user error, no
        // admin email).
        $posted = $accepted;
        $posted['_item_views'] = $itemViews;
        $unitSession->updateSurveyStudyRecord($posted, true, true);

        $this->sendJsonResponse(array('status' => 'saved'));
    }

    /**
     * Item types the autosave path must never touch. bot_check: its value is a
     * one-shot proof-of-work token that verify() BURNS, so validating it here
     * would consume it before the real submit (and it must only ever be judged
     * at submit). File uploads: the JSON autosave can't carry bytes anyway, and
     * File_Item::validateInput moves the upload — an arbitrary string posted
     * under a file item's name must not be persisted as if it were one.
     */
    public static function autosaveEligible(Item $item) {
        if ($item instanceof BotCheck_Item || $item instanceof File_Item) {
            return false;
        }
        return true;
    }

    /**
     * The subset of $data (name => value) that autosave may persist: only
     * names that resolve to an eligible item AND whose value passes that
     * item's validateInput(). Validation runs on a clone so the study's item
     * objects (and the later real save) aren't left holding this attempt's
     * reply/error state.
     *
     * @param array $data posted name => value
     * @param array $items name => Item, from SurveyStudy::getItemsWithChoices
     * @return array name => value
     */
    public static function filterAutosaveAnswers(array $data, array $items) {
        $accepted = array();
        foreach ($data as $name => $value) {
            if (!isset($items[$name]) || !($items[$name] instanceof Item)) {
                continue;
            }
            $item = $items[$name];
            if (!self::autosaveEligible($item)) {
                continue;
            }
            // $_POST semantics: a form submit only ever yields strings (or
            // arrays of strings), and the validators assume that — Mc_Item's
            // choice check is `is_string($reply) && !in_array(...)`, so a JSON
            // integer 7 would sail past `mc` with choices 1..2. Normalise before
            // probing so the JSON autosave can't reach a laxer path than submit.
            $value = FormSubmissionService::postScalar($value);
            // Nothing to persist for a cleared/empty field — and stamping
            // `saved` on an empty optional item would drop it from the next
            // render. Only real answers are autosaved.
            if ($value === null || $value === '' || $value === array()) {
                continue;
            }
            // An array only belongs to an item whose input is `<name>[]`
            // (mc_multiple, select_multiple, rank, …). Scalar validators aren't
            // written for arrays (Number_Item's trim() throws), and a scalar
            // under an array item is equally malformed.
            $inputName = isset($item->input_attributes['name']) ? (string) $item->input_attributes['name'] : $name;
            $wantsArray = substr($inputName, -2) === '[]';
            if (is_array($value) !== $wantsArray) {
                continue;
            }
            $probe = clone $item;
            $probe->error = null;
            $probe->validateInput($value);
            if ($probe->error) {
                continue;
            }
            $accepted[$name] = $value;
        }
        return $accepted;
    }

    /**
     * The SurveyStudy behind $runUnit if — and only if — the form_v2 endpoints
     * may serve it: a Form unit whose study renders in v2. Only
     * Form::getUnitSessionOutput renders v2; a plain Survey unit renders v1
     * whatever its study's rendering_mode says (a Form linked to a study that
     * is also placed as a Survey flips the study to v2 without touching that
     * placement). v1 pages submit through RunController::indexAction and must
     * not be reachable through the v2 AJAX paths, which skip v1's own gating.
     *
     * @return SurveyStudy|null
     */
    private function formV2Study($runUnit) {
        if (!($runUnit instanceof Form)) {
            return null;
        }
        $study = $runUnit->surveyStudy;
        if (!$study || empty($study->id)) {
            $study = $runUnit->getStudy(true);
        }
        if (!$study || empty($study->id)) {
            return null;
        }
        if (!isset($study->rendering_mode) || $study->rendering_mode !== 'v2') {
            return null;
        }
        return $study;
    }

    /**
     * form_v2: offline-queue sync endpoint.
     *
     * URL: POST /{runName}/form-sync
     * Body: as form-page-submit (FormSubmissionService::readPayload), plus the
     * queue entry's `uuid` and `client_ts`.
     *
     * Accepts one queued submission. Idempotent via `survey_form_submissions.uuid`:
     * a retry with the same uuid returns `{status:'ok', already_applied:true}`
     * without re-applying. Otherwise the same gates and the same apply path as
     * form-page-submit, with the uuid recorded in the same transaction as the
     * answers, so a failure can never leave answers applied with no uuid row
     * (which is what made the client retry into a double-apply). The envelope
     * (uuid / page / unit_session_id / client_ts — every field that lands in a
     * typed ledger column) is validated by FormSyncPayload before any state
     * change; a bad value is a 400, never a 500 halfway through. 409 replies
     * carry drop_entry so the queue stops retrying an entry that can never
     * apply (session ended, participant moved on to another Form).
     */
    public function formSyncAction() {
        if (!Request::isHTTPPostRequest()) {
            $this->sendJsonResponse(array('error' => 'Method Not Allowed'), 405);
            return;
        }
        $payload = FormSubmissionService::readPayload(
            isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
            file_get_contents('php://input'), $_POST, $_FILES
        );
        if ($payload === null) {
            $this->sendJsonResponse(array('error' => 'Invalid JSON body'), 400);
            return;
        }
        $envelope = FormSyncPayload::validate(
            $payload['uuid'], $payload['raw_page'], $payload['raw_unit_session_id'], $payload['client_ts']
        );
        if (!$envelope['ok']) {
            $this->sendJsonResponse(array('error' => $envelope['error'], 'field' => $envelope['field']), 400);
            return;
        }
        $uuid = $envelope['uuid'];
        $clientTs = $envelope['client_ts'];
        $payload['page'] = $envelope['page'];
        $payload['unit_session_id'] = $envelope['unit_session_id'];

        $context = $this->formWriteContext($payload['unit_session_id'], array('drop_entry' => true));
        if (!$context) {
            return;
        }
        list(, $unitSession, $study) = $context;

        // Dedup pre-check: a uuid we already persisted is a cheap, safe retry.
        // The uuid is unique-indexed, so a race between two drains of the same
        // entry is caught by the INSERT below; the transaction keeps the
        // ledger row and the answers together.
        $db = DB::getInstance();
        $existing = $db->select('id')->from('survey_form_submissions')
            ->where('uuid = :uuid')->bindParams(array('uuid' => $uuid))->fetch();
        if ($existing) {
            $this->sendJsonResponse(array('status' => 'ok', 'already_applied' => true));
            return;
        }
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO `survey_form_submissions` (uuid, unit_session_id, page, client_ts) '
                . 'VALUES (:uuid, :usid, :page, :cts)'
            );
            $stmt->bindValue(':uuid', $uuid);
            $stmt->bindValue(':usid', (int) $unitSession->id, PDO::PARAM_INT);
            $stmt->bindValue(':page', (int) $payload['page'], PDO::PARAM_INT);
            if ($clientTs === null) {
                $stmt->bindValue(':cts', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':cts', $clientTs);
            }
            $stmt->execute();
            $result = (new FormSubmissionService($unitSession, $study))->apply($payload);
        } catch (Throwable $e) {
            $db->rollBack();
            if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                $this->sendJsonResponse(array('status' => 'ok', 'already_applied' => true));
                return;
            }
            throw $e;
        }
        if ($result['status'] !== 'ok') {
            // Drop the uuid claim so the participant can fix the errors and
            // re-drain the same entry; leaving it would make the retry look
            // already-applied and silently discard the answers.
            $db->rollBack();
            $this->sendJsonResponse($result);
            return;
        }
        $db->commit();
        $this->sendJsonResponse($this->withRunRedirect($result));
    }

    /**
     * bot_check (Altcha): mint a fresh, session-bound proof-of-work challenge.
     *
     * URL: GET /{runName}/form-bot-challenge?item=<bot_check item name>
     *
     * The difficulty is the SERVER's call: `item` names a bot_check item of the
     * current unit session's (v2) study, and its `bot_check N` type_options —
     * not anything else in the request — set the PoW prefix bytes. (An earlier
     * version took `?difficulty=` from the query string and signed whatever it
     * was given, so `?difficulty=1` downgraded a `bot_check 3` to a 1-byte
     * prefix; verify() now re-derives the floor from the item as well, so even
     * a genuinely signed easier challenge is refused at submit.)
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
        // Ownership: only a participant with a live session in this run may
        // mint, and only for a v2 form's own bot_check items — the same gates
        // as the other form_v2 endpoints (the challenge binds to THIS
        // session), with the challenge bucket for the rate limit. The widget
        // only ever mounts inside a rendered form, which already requires a
        // session, so this costs no legitimate caller anything. (The
        // /survey-test/ preview path rewrites the run URL, so its widget never
        // reached this endpoint in the first place.)
        $context = $this->formWriteContext(0, array(), 'bot');
        if (!$context) {
            return;
        }
        list(, $unitSession, $study) = $context;
        $itemName = isset($_GET['item']) && is_string($_GET['item']) ? trim($_GET['item']) : '';
        if ($itemName === '' || !preg_match('/^[A-Za-z0-9_]{1,255}$/', $itemName)) {
            $this->sendJsonResponse(array('error' => 'Missing or invalid bot_check item name'), 400);
            return;
        }
        $items = $study->getItemsWithChoices(null, array('field' => 'name', 'values' => array($itemName)));
        $item = isset($items[$itemName]) ? $items[$itemName] : null;
        if (!($item instanceof BotCheck_Item)) {
            $this->sendJsonResponse(array('error' => 'No such bot_check item in this form'), 404);
            return;
        }

        $challenge = BotCheckChallenge::mint($item);
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
     * Enforces slot='value' and returns the R
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
        if ($callId <= 0) {
            $this->sendJsonResponse(array('error' => 'Missing call_id'), 400);
            return;
        }
        // Reactive r-calls are debounced and seq-guarded client-side, but a
        // malicious or buggy client could loop: the 'rcall' bucket is DB-keyed
        // (formr_rate_limits, patch 095), so it survives cookie rotation.
        $context = $this->formWriteContext(0, array(), 'rcall');
        if (!$context) {
            return;
        }
        list(, $unitSession, $study) = $context;
        // Validates study ownership of the call_id and the slot, overlays the
        // client's answers on the last persisted row and evaluates via
        // OpenCPU. Never ships R source to the client.
        $out = (new RCallService($unitSession, $study))->evaluateSingle($callId, 'value', $answers);
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
     * The first page goes through here too: the client calls it for the page
     * it lands on, right after load. FormRenderer evaluates no R for the
     * participant's display (only the calculate items that are due).
     *
     * `choices` (R in choice labels): call id => {html, item_id, values}.
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

        // Shares the r-call bucket with form-fill so a hostile client can't
        // multiply its budget by alternating endpoints.
        $context = $this->formWriteContext(0, array(), 'rcall');
        if (!$context) {
            return;
        }
        list(, $unitSession, $study) = $context;

        // Showing a page is when its calculate items — and those of every
        // page before it — are due (FormRenderer rule 4). They are stored on
        // the server and handed back so the page's showifs can read them.
        $service = new RCallService($unitSession, $study);
        $computed = $service->computeCalculations($pageNum);

        // The service scopes calls to items actually scheduled on that page
        // in THIS unit-session and applies the allowlist revocation check.
        $calls = $service->callsForPage($pageNum);
        if (empty($calls['value']) && empty($calls['label']) && empty($calls['choice_label'])) {
            $this->sendJsonResponse(array('values' => array(), 'labels' => array(), 'choices' => array(), 'computed' => $computed));
            return;
        }

        // A note_iframe's "label" is a whole R Markdown document knitted to its
        // own HTML file; every other label is inline markdown.
        $iframeCalls = array_filter($calls['label'], function ($call) {
            return $call['item_type'] === 'note_iframe';
        });
        $labelCalls = array_diff_key($calls['label'], $iframeCalls);

        $values = $service->resolveExpressions($calls['value'], $answers, RCallService::ttlForSlot('value'));
        $labels = $service->resolveLabels($labelCalls, $answers, RCallService::ttlForSlot('label'))
                + $service->resolveIframes($iframeCalls, RCallService::ttlForSlot('label'));
        $choiceLabels = $service->resolveLabels($calls['choice_label'], $answers, RCallService::ttlForSlot('choice_label'));

        $valuesOut = array();
        foreach ($values as $callId => $val) {
            $valuesOut[(string) $callId] = RCallService::rResultToScalarString($val);
        }
        $labelsOut = array();
        foreach ($labels as $callId => $html) {
            $labelsOut[(string) $callId] = $html;
        }
        // Choice labels also name the item and the choice values they label:
        // a placeholder span can't live inside an <option>, so the client
        // reaches options by value instead.
        $choicesOut = array();
        foreach ($choiceLabels as $callId => $html) {
            $choicesOut[(string) $callId] = array(
                'html' => $html,
                'item_id' => $calls['choice_label'][$callId]['item_id'],
                'values' => $calls['choice_label'][$callId]['values'],
            );
        }

        $this->sendJsonResponse(array('values' => $valuesOut, 'labels' => $labelsOut, 'choices' => $choicesOut, 'computed' => $computed));
    }

    /**
     * Shared fill/render-page budget: one bucket per run session so a
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
