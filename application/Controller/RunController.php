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
        $view = !empty($run_vars['form_v2']) ? 'run/form_index' : 'run/index';

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
        $payload = $this->readFormPayload();
        if ($payload === null) {
            return;
        }
        $context = $this->formWriteContext($payload['unit_session_id'], array(), 'write', true);
        if (!$context) {
            return;
        }
        list(, $unitSession, $study, $onV2) = $context;
        if (!$onV2) {
            $payload['final'] = false;
        }
        try {
            $result = (new FormSubmissionService($unitSession, $study))->apply($payload);
        } catch (Throwable $e) {
            // Not the participant's doing (see FormSubmissionService::apply):
            // a 5xx, which the browser queues and retries like an outage.
            formr_log_exception($e, __METHOD__);
            $this->sendJsonResponse(array('error' => 'The page could not be stored.'), 500);
            return;
        }
        if ($result['status'] === 'stale') {
            $this->sendJsonResponse($this->staleDocumentReply(), 409);
            return;
        }
        if ($result['status'] === 'ok') {
            FormSubmissionService::recordSubmitSeq($unitSession->id, $payload['doc'], $payload['seq']);
        }
        $this->sendJsonResponse($onV2 ? $this->withRunRedirect($result) : $this->leftV2Reply($result));
    }

    /**
     * The body of a v2 write (FormSubmissionService::readPayload), or null
     * once the error reply has been sent.
     *
     * A body over post_max_size reaches PHP with $_POST and $_FILES empty —
     * the multipart transport of a page with a large file. Read as-is it is
     * an empty page, which apply() would store as a success: the answers and
     * the file gone, every hidden item un-hidden, `final` lost. It is refused
     * instead, as Survey::getUnitSessionOutput does for v1; a 413 is a 4xx,
     * so the browser reports it rather than queueing a retry that can never
     * fit.
     *
     * @return array|null
     */
    private function readFormPayload() {
        if (PageSubmission::bodyExceedsPostMaxSize()) {
            $this->sendJsonResponse(array('error' => "The upload exceeds the server's maximum size."), 413);
            return null;
        }
        $payload = FormSubmissionService::readPayload(
            isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
            file_get_contents('php://input'), $_POST, $_FILES
        );
        if ($payload === null) {
            $this->sendJsonResponse(array('error' => 'Invalid JSON body'), 400);
        }
        return $payload;
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
     * @param int|null $claimedUnitSessionId the unit session a write was
     *   made in (its page's state block): anything else, a missing one (0)
     *   included, is refused as stale; null for a request that writes no
     *   answers (the bot_check mint, form-render-page)
     * @param array $extra409 keys merged into every 409 reply (the sync
     *   endpoint adds drop_entry so the queue stops retrying the entry)
     * @param string $rateBucket 'write' (form writes), 'rcall' (R calls) or
     *   'bot' (bot_check challenges)
     * @return array|null
     */
    private function formWriteContext($claimedUnitSessionId, array $extra409 = array(), $rateBucket = 'write', $acceptLeftV2 = false) {
        if ($this->previewContext !== null) {
            return $this->previewWriteContext($claimedUnitSessionId, $extra409, $rateBucket);
        }
        $this->run = $this->getRun();
        $this->user = $this->loginUser();
        $runSession = new RunSession($this->user->user_code, $this->run, array('user' => $this->user));
        if (!$runSession->id) {
            $this->sendJsonResponse(array('error' => 'No active run session'), 403);
            return null;
        }
        // The run's access rule, as for the page (Run::exec): a run closed
        // while a form was open takes no more answers from it. `closed` lets
        // the browser reload into the run's service message.
        if (!$this->run->admits($runSession, $this->user)) {
            $this->sendJsonResponse(array('error' => 'This study is not open', 'closed' => true), 403);
            return null;
        }
        // What a page load does for its participant (indexAction, Run::exec):
        // slide the session cookie by the run's expire_cookie, and note the
        // activity. A v2 form is one page load whose answers all come here,
        // so a form filled in for longer than expire_cookie lost its cookie,
        // and so its run session, half way.
        Session::setSessionLifetime($this->run->expire_cookie);
        $runSession->setLastAccess();
        if (!$this->rateAllowed($rateBucket, (int) $runSession->id)) {
            $this->sendJsonResponse(array('error' => 'Rate limit exceeded'), 429);
            return null;
        }
        $unitSession = $runSession->getCurrentUnitSession();
        if (!$unitSession || !$unitSession->runUnit) {
            $this->sendJsonResponse(array('error' => 'No current unit session') + $extra409, 409);
            return null;
        }
        $study = $this->formV2Study($unitSession->runUnit);
        $onV2 = true;
        if (!$study && $acceptLeftV2 && $claimedUnitSessionId !== null
                && (int) $claimedUnitSessionId === (int) $unitSession->id
                && $unitSession->runUnit instanceof Survey
                && $unitSession->runUnit->surveyStudy && $unitSession->runUnit->surveyStudy->valid) {
            // The survey was switched back to v1 while this page was open, or
            // while its pages waited in the offline queue. The unit session is
            // still the one the page was rendered for, and both engines keep
            // its answers in the same rows: they are stored, and the
            // participant continues on v1 (the caller sends them there).
            $study = $unitSession->runUnit->surveyStudy;
            $onV2 = false;
        }
        if (!$study) {
            $this->sendJsonResponse(array('error' => 'Current unit is not a v2 form') + $extra409, 409);
            return null;
        }
        if ($claimedUnitSessionId !== null && (int) $claimedUnitSessionId !== (int) $unitSession->id) {
            $this->sendJsonResponse(array(
                'error' => 'This form was rendered for a different session (continued elsewhere or a new entry started). Reload to continue.',
                'stale_session' => true,
            ) + $extra409, 409);
            return null;
        }
        BotCheckChallenge::bindTo($unitSession->id);
        return array($runSession, $unitSession, $study, $onV2);
    }

    /**
     * The 409 for a write from a document that has not seen the latest
     * write of its form (FormSubmissionService::claimWrite): another tab or
     * window has written since it was rendered. Nothing was stored; the
     * browser reloads it.
     */
    private function staleDocumentReply() {
        return array(
            'error' => 'This form was continued in another tab or window. Reload to continue.',
            'stale_document' => true,
        );
    }

    /**
     * A v2 page written for a unit session whose survey has gone back to v1
     * (formWriteContext's $onV2 false): its answers are stored, but it ends
     * nothing — v1 ends a survey itself once every item is answered — and
     * the reply sends the browser to the run URL, where v1 shows the rest.
     */
    private function leftV2Reply(array $result) {
        if ($result['status'] === 'ok') {
            $result['redirect'] = run_url($this->run->name);
            $result['engine_changed'] = true;
        }
        return $result;
    }

    /**
     * A completed form's reply sends the browser back to the run URL, where
     * Run::exec shows the unit the run moved on to — or to where that unit
     * sends the participant (an External's address, a paged survey's first
     * page; set by FormSubmissionService::apply).
     */
    private function withRunRedirect(array $result) {
        if ($this->previewContext !== null) {
            if (!empty($result['complete'])) {
                $result['redirect'] = $this->previewContext['runUrl'];
            }
        } elseif (!empty($result['complete']) && empty($result['redirect'])) {
            $result['redirect'] = run_url($this->run->name);
        }
        return $result;
    }

    /**
     * A "Test Survey" preview of a v2 study (SurveyTestController): the
     * unit session comes from the preview's own session slot, not from a
     * participant's run session. Set by dispatchFormV2Preview.
     *
     * @var array{run: Run, unitSession: UnitSession, runUrl: string}|null
     */
    private $previewContext = null;

    /** The v2 actions a preview may call (see SurveyTestController). */
    const FORM_V2_PREVIEW_ACTIONS = array(
        'form-page-submit' => 'formPageSubmitAction',
        'form-sync' => 'formSyncAction',
        'form-save' => 'formSaveAction',
        'form-render-page' => 'formRenderPageAction',
        'form-bot-challenge' => 'formBotChallengeAction',
    );

    /**
     * Run one v2 endpoint for a "Test Survey" preview. The action is the
     * very one participants hit; only the gate (formWriteContext) takes the
     * preview's unit session instead of looking up a run session, so the
     * preview exercises the same code as a real run.
     *
     * @param string $action a key of FORM_V2_PREVIEW_ACTIONS
     * @param array $context run (the TEST_RUN), unitSession, runUrl
     *   (where a completed form redirects: the preview URL)
     */
    public function dispatchFormV2Preview($action, array $context) {
        if (!isset(self::FORM_V2_PREVIEW_ACTIONS[$action])) {
            formr_error(404, 'Not Found', 'The requested URL was not found');
            return null;
        }
        $this->previewContext = $context;
        $this->run = $context['run'];
        $method = self::FORM_V2_PREVIEW_ACTIONS[$action];
        return $this->$method();
    }

    /**
     * formWriteContext for a preview: the same checks, against the preview's
     * unit session. Rate limits are keyed by the unit session (every preview
     * shares the dummy run session id -1).
     */
    private function previewWriteContext($claimedUnitSessionId, array $extra409, $rateBucket) {
        $unitSession = $this->previewContext['unitSession'];
        $study = $this->formV2Study($unitSession ? $unitSession->runUnit : null);
        $bucketId = -1 * (int) ($unitSession ? $unitSession->id : 0);
        if (!$this->rateAllowed($rateBucket, $bucketId)) {
            $this->sendJsonResponse(array('error' => 'Rate limit exceeded'), 429);
            return null;
        }
        if (!$unitSession || $unitSession->ended !== null || !$study) {
            $this->sendJsonResponse(array('error' => 'No current unit session') + $extra409, 409);
            return null;
        }
        if ($claimedUnitSessionId !== null && (int) $claimedUnitSessionId !== (int) $unitSession->id) {
            $this->sendJsonResponse(array(
                'error' => 'This preview was rendered for a different test session. Reload to continue.',
                'stale_session' => true,
            ) + $extra409, 409);
            return null;
        }
        BotCheckChallenge::bindTo($unitSession->id);
        return array($unitSession->runSession, $unitSession, $study, true);
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
     * (see FormSubmissionService::autosaveEligible()). Otherwise anyone could persist an unverified
     * bot_check token, an out-of-range choice or an arbitrary file string.
     *
     * An autosaved item gets `survey_items_display.saved` stamped like a
     * submitted one: `saved` is the per-item "when this answer was stored"
     * timestamp, nothing more. Which page the participant is on is the
     * browser's business (state.mjs), so an autosaved item is rendered and
     * editable exactly as before a reload.
     */
    public function formSaveAction() {
        if (!Request::isHTTPPostRequest()) {
            $this->sendJsonResponse(array('error' => 'Method Not Allowed'), 405);
            return;
        }
        $payload = $this->readFormPayload();
        if ($payload === null) {
            return;
        }
        $data = $payload['data'];
        $itemViews = $payload['item_views'];

        // Keep only actually-answered fields. The client's collectPayload also
        // emits the empty hidden placeholder of each mc/mc_multiple item
        // (name=..., value=""), and writing "" raw to a typed results column
        // (e.g. a TINYINT mc) errors; it would also mark an unanswered REQUIRED
        // item as saved and let the participant bypass it. So empties are not
        // stored as answers; an empty value instead withdraws an answer stored
        // earlier (FormSubmissionService::withdrawnNames / withdrawAnswers).
        $clean = array();
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $v = array_values(array_filter($v, function ($x) { return $x !== '' && $x !== null; }));
                if ($v) $clean[$k] = $v;
            } elseif ($v !== '' && $v !== null) {
                $clean[$k] = $v;
            }
        }
        if (!$data) {
            $this->sendJsonResponse(array('status' => 'noop'));
            return;
        }

        // Same gates as form-page-submit, including the stale-tab one. Autosave
        // is best-effort, so the client treats a 409 as non-fatal — except
        // stale_document (claimWrite, below), on which it reloads.
        $context = $this->formWriteContext($payload['unit_session_id'], array(), 'write', true);
        if (!$context) {
            return;
        }
        list(, $unitSession, $study) = $context;
        // Started before a page submit of the same document that has since
        // landed: its answers are older than the stored ones.
        if (FormSubmissionService::autosaveIsStale($unitSession->id, $payload['doc'], $payload['seq'])) {
            $this->sendJsonResponse(array('status' => 'stale'));
            return;
        }

        // Keep only values that (a) belong to an item this study actually has,
        // (b) belong to an item type that may be autosaved, and (c) pass that
        // item's own validateInput(). Anything else is silently dropped — the
        // explicit page-submit is where the participant sees errors.
        $items = $study->getItemsWithChoices(null, array(
            'field' => 'name',
            'values' => array_keys($data),
        ));
        // An emptied field or a deselected choice withdraws the answer
        // autosave stored before; the rest are answers to store.
        $withdraw = FormSubmissionService::withdrawnNames($data, $items);
        $accepted = FormSubmissionService::filterAutosaveAnswers($clean, $items);
        if (!$withdraw && !$accepted) {
            $this->sendJsonResponse(array('status' => 'noop'));
            return;
        }
        $service = new FormSubmissionService($unitSession, $study);
        // A document that has not seen the latest write would withdraw the
        // answers given since in another tab: it is refused, and reloads.
        if (!$service->claimWrite($payload['doc'], $payload['base'], $payload['prev'])) {
            $this->sendJsonResponse($this->staleDocumentReply(), 409);
            return;
        }
        $withdrawn = $service->withdrawAnswers($withdraw);
        if (!$accepted) {
            $this->sendJsonResponse(array('status' => $withdrawn ? 'saved' : 'noop'));
            return;
        }

        // survey_items_display rows already exist from the GET render; we only
        // UPDATE them. validate=true → the same per-item checks as page-submit
        // (every value here already passed them, so this cannot fail on
        // validation); quiet=true → a lost race is silent (no user error, no
        // admin email).
        $posted = $accepted;
        $posted['_item_views'] = $itemViews;
        // quiet: a failed write returns false without a user error — then
        // nothing was stored, and the page must not be told "Saved".
        $stored = $unitSession->updateSurveyStudyRecord($posted, true, true, $items);

        $this->sendJsonResponse(array('status' => ($stored || $withdrawn) ? 'saved' : 'noop'));
    }

    /**
     * The SurveyStudy behind $runUnit if — and only if — the form_v2 endpoints
     * may serve it: a Form unit whose study renders in v2. Only
     * Form::getUnitSessionOutput renders v2 (a placement is a Form while its
     * study is on v2: SurveyStudy::syncUnitType). v1 pages submit through
     * RunController::indexAction and must not be reachable through the v2
     * AJAX paths, which skip v1's own gating.
     *
     * @return SurveyStudy|null
     */
    private function formV2Study($runUnit) {
        return $runUnit instanceof Form ? $runUnit->v2Study() : null;
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
     * form-page-submit; the uuid is recorded once the page is applied
     * (FormSubmissionService::applyQueued), and a request applying the same
     * entry at that moment is answered 429, which the queue retries. The envelope
     * (uuid / page / unit_session_id / client_ts — every field that lands in a
     * typed ledger column) is validated by FormSyncPayload before any state
     * change; a bad value is a 400, never a 500 halfway through. 409 replies
     * carry drop_entry so the queue stops retrying an entry that can never
     * apply (session ended, participant moved on to another Form). A page
     * whose document has not seen the latest write of the form is stored
     * without its hidden list (FormSubmissionService::apply, `stale`).
     */
    public function formSyncAction() {
        if (!Request::isHTTPPostRequest()) {
            $this->sendJsonResponse(array('error' => 'Method Not Allowed'), 405);
            return;
        }
        $payload = $this->readFormPayload();
        if ($payload === null) {
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

        $context = $this->formWriteContext($payload['unit_session_id'], array('drop_entry' => true), 'write', true);
        if (!$context) {
            return;
        }
        list(, $unitSession, $study, $onV2) = $context;
        if (!$onV2) {
            $payload['final'] = false;
        }

        // Once per entry (FormSubmissionService::applyQueued): an entry
        // refused for validation errors, or one whose write failed, is not
        // recorded, so the participant's corrected resend or the retry
        // applies it.
        try {
            $result = (new FormSubmissionService($unitSession, $study))->applyQueued($uuid, $clientTs, $payload);
        } catch (Throwable $e) {
            formr_log_exception($e, __METHOD__);
            $this->sendJsonResponse(array('error' => 'The page could not be stored.'), 500);
            return;
        }
        if ($result['status'] === 'busy') {
            // Another request is applying this entry. A 429 keeps it queued
            // and counts no attempt; the browser retries it later.
            $this->sendJsonResponse(array('error' => 'This page is being saved by another request'), 429);
            return;
        }
        if ($result['status'] !== 'ok' || !empty($result['already_applied'])) {
            $this->sendJsonResponse($result);
            return;
        }
        FormSubmissionService::recordSubmitSeq($unitSession->id, $payload['doc'], $payload['seq']);
        $this->sendJsonResponse($onV2 ? $this->withRunRedirect($result) : $this->leftV2Reply($result));
    }

    /**
     * bot_check (Altcha): mint a fresh, session-bound proof-of-work challenge.
     *
     * URL: GET /{runName}/form-bot-challenge?item=<bot_check item name>
     *
     * The difficulty is the SERVER's call: `item` names a bot_check item of the
     * current unit session's (v2) study, and its `bot_check N` type_options —
     * not anything else in the request — set the PoW difficulty. verify()
     * re-derives the floor from the item as well, so even a genuinely signed
     * easier challenge is refused at submit.
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
        // session, so this costs no legitimate caller anything. A "Test
        // Survey" preview reaches it through /survey-test/<token>/ with the
        // preview's unit session (dispatchFormV2Preview).
        $context = $this->formWriteContext(null, array(), 'bot');
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
     * Called by the browser for every page it is about to show — the one it
     * lands on and each one it moves to (state.mjs decides which) — with the
     * form's current answers (so hidden-field values resolve against the
     * latest state). The client substitutes labels into the wrapper
     * innerHTML and writes values into the matching `data-fmr-fill-id`
     * inputs. This is the only R a v2 form runs; FormRenderer runs none. It
     * stores no answers (only its own result cache).
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

        // The r-call rate bucket: page resolutions are OpenCPU calls.
        $context = $this->formWriteContext(null, array(), 'rcall');
        if (!$context) {
            return;
        }
        list(, $unitSession, $study) = $context;
        // Release the PHP session lock before the OpenCPU work: the browser
        // fetches the next page's content alongside the page submit, and
        // with the lock held the submit would wait for this request's R.
        // Nothing below writes the session.
        Session::over();

        $service = new RCallService($unitSession, $study);

        // The service scopes calls to items actually scheduled on that page
        // in THIS unit-session and applies the allowlist revocation check.
        $calls = $service->callsForPage($pageNum);
        if (empty($calls['value']) && empty($calls['label']) && empty($calls['choice_label'])) {
            $this->sendJsonResponse(array('values' => array(), 'labels' => array(), 'choices' => array()));
            return;
        }

        // Values, labels and every note_iframe at once (RCallService::resolvePage).
        $resolved = $service->resolvePage($calls, $answers);
        $values = $resolved['values'];
        $labels = $resolved['labels'];
        $choiceLabels = $resolved['choices'];

        $valuesOut = $follows = array();
        foreach ($values as $callId => $val) {
            $valuesOut[(string) $callId] = RCallService::rResultToScalarString($val);
            $call = $calls['value'][$callId];
            if ($service->valueFollowsAnswers($call['expr'], isset($call['item_name']) ? $call['item_name'] : '')
                    && $service->answersChangedFor($callId)) {
                $follows[] = (string) $callId;
            }
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

        // `follows`: the value calls whose R reads the form's answers
        // (RCallService::valueFollowsAnswers) and was evaluated for answers
        // that changed since (answersChangedFor).
        $this->sendJsonResponse(array('values' => $valuesOut, 'follows' => $follows, 'labels' => $labelsOut, 'choices' => $choicesOut));
    }

    /**
     * Per-run-session budgets for the v2 endpoints, each its own bucket so a
     * burst on one can never eat another's budget:
     * - 'rcall' (form-render-page): one bucket for all R resolution, so a
     *   hostile client can't multiply its budget by alternating endpoints;
     *   30/min is generous for a participant paging through a long form.
     * - 'write' (form-page-submit, form-save, form-sync): wide headroom —
     *   autosave is client-throttled to 1/20s and a fast participant submits
     *   well under a page a second — but it stops a scripted client from
     *   hammering DB writes indefinitely.
     * - 'bot' (/form-bot-challenge): one mint per bot_check item, fetched
     *   only on click; 20/min is far more than any human produces while
     *   bounding a scripted client to something that can't be used as a free
     *   HMAC-minting oracle.
     * - 'beacon' (/pwa-beacon): unauthenticated, keyed by IP.
     * Override via $settings['form_v2_rate_limits'][<config key>].
     */
    private function rateAllowed($bucket, $id) {
        static $buckets = array(
            'rcall' => array('rcall', 'rcall_per_min', 30),
            'write' => array('formwrite', 'write_per_min', 90),
            'bot' => array('botchallenge', 'bot_challenge_per_min', 20),
            'beacon' => array('beacon', 'beacon_per_min', 10),
        );
        list($prefix, $key, $default) = isset($buckets[$bucket]) ? $buckets[$bucket] : $buckets['write'];
        $max = (int) Config::get('form_v2_rate_limits.' . $key, $default);
        return FixedWindowRateLimiter::allow($prefix . ':' . $id, $max, 60);
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
        // flood formr_log / disk. Fail-open like the other buckets. An IPv6
        // client owns its whole /64 and could send each beacon from a new
        // address: those are counted per /64 (MailGuardService::subnetKey).
        $ip = MailGuardService::clientIp();
        $bucket = ($ip !== null && strpos($ip, ':') !== false) ? MailGuardService::subnetKey($ip) : ($ip ?: 'unknown');
        if (!$this->rateAllowed('beacon', $bucket)) {
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
