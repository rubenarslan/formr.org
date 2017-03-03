<?php

class PublicController extends Controller {
	public function __construct(Site &$site) {
		parent::__construct($site);
		if (!Request::isAjaxRequest()) {
			$default_assets = get_default_assets();
			$this->registerCSS($default_assets['css']);
			$this->registerJS($default_assets['js']);
		}
	}

	public function indexAction() {
		$this->renderView('public/home');
	}

	public function documentationAction() {
		$this->renderView('public/documentation');
	}

	public function studiesAction() {
		$this->renderView('public/studies', array('runs' => $this->user->getAvailableRuns()));
	}

	public function aboutAction() {
		$this->renderView('public/about', array('bodyClass' => 'fmr-about'));
	}

	public function editUserAction() {
		/**
		* @todo: 
		* - allow changing email address
		* - email address verification
		* - my access code has been compromised, reset? possible problems with external data, maybe they should get their own tokens...
		*/

		if(!$this->user->loggedIn()) {
			alert('You need to be logged in to go here.', 'alert-info');
			redirect_to("index");
		}

		if(!empty($_POST)) {
			$redirect = false;
			if($this->request->str('new_password')) {
				if($this->user->changePassword($this->request->str('password'), $this->request->str('new_password'))) {
					alert('<strong>Success!</strong> Your password was changed!','alert-success');
					$redirect = true;
				} else {
					alert(implode($this->user->errors), 'alert-danger');
				}
			}

			if($this->request->str('new_email')) {
				if($this->user->changeEmail($this->request->str('password'), $this->request->str('new_email'))) {
					alert('<strong>Success!</strong> Your email address was changed!', 'alert-success');
					$redirect = true;
				} else {
					alert(implode($this->user->errors),'alert-danger');
				}
			}

			if($redirect) {
				redirect_to('index');
			}
		}
		$this->renderView('public/edit_user');
	}

	public function loginAction() {
		if($this->user->loggedIn()) {
			redirect_to("index");
		}

		if($this->request->str('email') && $this->request->str('password')) {
			if($this->user->login($this->request->str('email'), $this->request->str('password'))) {
				alert('<strong>Success!</strong> You were logged in!', 'alert-success');
				Session::set('user', serialize($this->user));
				$redirect = $this->user->isAdmin() ? redirect_to('admin') : redirect_to();
			} else {
				alert(implode($this->user->errors), 'alert-danger');
			}
		}

		$this->registerAssets('material');
		$this->renderView('public/login');
	}

	public function logoutAction() {
		$user = $this->user;
		if($user->loggedIn()) {
			$user->logout();
			$redirect_to = 'login';
		} else {
			Session::destroy();
			$redirect_to = $this->request->getParam('_rdir');
		}

		$user = new User($this->fdb, null, null);
		alert('<strong>Logged out:</strong> You have been logged out.','alert-info');
		redirect_to($redirect_to);
	}

	public function registerAction() {
		$user = $this->user;
		$site = $this->site;

		//fixme: cookie problems lead to fatal error with missing user code
		if($user->loggedIn()) {
			alert('You were already logged in. Please logout before you can register.', 'alert-info');
			redirect_to("index");
		}

		if($site->request->str('email')) {
			if($user->register($site->request->str('email'), $site->request->str('password'), $site->request->str('referrer_code'))) {
				alert('<strong>Success!</strong> You were registered and logged in!','alert-success');
				redirect_to('index');
			} else {
				alert(implode($user->errors),'alert-danger');
			}
		}
		
		$this->registerAssets('material');
		$this->renderView('public/register');
	}

	public function verifyEmailAction() {
		$user = $this->user;

		if((!isset($_GET['verification_token']) OR !isset($_GET['email']) ) AND !isset($_POST['email'])):
			alert("You need to follow the link you received in your verification mail.");
			redirect_to("login");
		else:
			$user->verify_email($_GET['email'], $_GET['verification_token']);
			redirect_to("login");
		endif;
	}

	public function forgotPasswordAction() {
		if($this->user->loggedIn()) {
			redirect_to("index");
		}

		if($this->request->str('email')) {
			$this->user->forgot_password($this->request->str('email'));
		}

		$this->registerAssets('material');
		$this->renderView('public/forgot_password');
	}

	public function resetPasswordAction() {
		$user = $this->user;
		if($user->loggedIn()) {
			redirect_to("index");
		}

		if((!isset($_GET['reset_token']) OR !isset($_GET['email']) ) AND !isset($_POST['email'])):
			alert("You need to follow the link you received in your password reset mail");
			redirect_to("forgot_password");
		endif;

		if(!empty($_POST) AND isset($_POST['email'])  AND isset($_POST['new_password'])  AND isset($_POST['reset_token'])) {
			$user->reset_password($_POST['email'], $_POST['reset_token'], $_POST['new_password']);
		}

		$this->registerAssets('material');
		$this->renderView('public/reset_password', array(
			'reset_data_email' => isset($_GET['email']) ? $_GET['email'] : '',
			'reset_data_token' => isset($_GET['reset_token']) ? $_GET['reset_token'] : '',
		));
	}

	public function notFoundAction() {
		$this->renderView('public/not_found');
	}

	public function fileDownloadAction($run_id = 0, $original_filename = '') {
		$path = $this->fdb->findValue('survey_uploaded_files', array('run_id' => (int)$run_id, 'original_file_name' => $original_filename), array('new_file_path'));
		if ($path) {
			return redirect_to(asset_url($path));
		}
		bad_request();
	}

	public function runAction($run_name = '') {
		// hack for run name
		$_GET['run_name'] = $run_name;
		$this->site->request->run_name = $run_name;

		$this->user = $this->site->loginUser($this->user);
		$run = new Run($this->fdb, $this->request->str('run_name'));
		$this->run = $run;
		$run_vars = $run->exec($this->user);
		$this->registerCSS($run_vars['css']);
		$this->registerJS($run_vars['js']);
		unset($run_vars['css'], $run_vars['js']);
		// @todo. Cleapup CSS and remove this hack
		foreach ($this->css as $i => $file) {
			if ($file === 'site/css/style.css') {
				$this->css[$i] = 'site/css/run.css';
			}
		}

		$this->renderView('public/run', $run_vars);
	}

	public function settingsAction($run_name = '') {
		$run = new Run($this->fdb, $run_name);
		if (!$run->valid) {
			not_found();
		}

		// Login if user entered with code and redirect without login code
		if (Request::isHTTPGetRequest() && ($code = $this->request->getParam('code'))) {
			$_GET['run_name'] = $run_name;
			$this->user = $this->site->loginUser($this->user);
			if ($this->user->user_code != $code) {
				alert('Unable to login with the provided code', 'alert-warning');
			}
			redirect_to('settings/' . $run_name);
		}

		// People who have no session in the run need not set anything
		$session = new RunSession($this->fdb, $run->id, 'cron', $this->user->user_code);
		if (!$session->id) {
			alert('You cannot create settings in a study you have not participated in.', 'alert-danger');
			redirect_to('index');
		}

		$settings = array('no_email' => 1);
		if (Request::isHTTPPostRequest() && $this->user->user_code == $this->request->getParam('_sess')) {
			$update = array();
			$settings = array(
				'no_email' => $this->request->getParam('no_email'),
				'delete_cookie' => (int)$this->request->getParam('delete_cookie'),
			);

			if ($settings['no_email'] === '1') {
				$update['no_email'] = null;
			} elseif ($settings['no_email'] == 0) {
				$update['no_email'] = 0;
			} elseif ($ts = strtotime($settings['no_email'])) {
				$update['no_email'] = $ts;
			}

			$session->saveSettings($settings, $update);

			alert('Settings saved successfully for survey "'.$run->name.'"', 'alert-success');
			if ($settings['delete_cookie'])  {
				Session::destroy();
				redirect_to('index');
			}
			redirect_to('settings/' . $run->name);
		}

		$this->run = $run;
		$this->renderView('public/settings', array(
			'settings' => $session->getSettings(),
			'email_subscriptions' => Config::get('email_subscriptions'),
		));
	}

	public function monkeyBarAction($run_name = '', $action = '') {
		$action = str_replace('ajax_', '', $action);
		$allowed_actions = array('send_to_position', 'remind', 'next_in_run', 'delete_user', 'snip_unit_session');
		if (!$run_name || !in_array($action, $allowed_actions)) {
			throw new Exception("Invalid Request parameters");
		}

		$parts = explode('_', $action);
		$method = array_shift($parts) . str_replace(' ', '', ucwords(implode(' ', $parts)));
		$runHelper = new RunHelper($this->request, $this->fdb, $run_name);

		// check if run session usedby the monkey bar is a test if not this action is not valid
		if (!($runSession = $runHelper->getRunSession()) || !$runSession->isTesting()) {
			throw new Exception ("Unauthorized access to run session");
		}

		if (!method_exists($runHelper, $method)) {
			throw new Exception("Invalid method {$action}");
		}

		$runHelper->{$method}();
		if (($errors = $runHelper->getErrors())) {
			$errors = implode("\n", $errors);
			alert(nl2br($errors), 'alert-danger');
		}

		if (($message = $runHelper->getMessage())) {
			alert($message, 'alert-info');
		}

		if (is_ajax_request()) {
			echo $this->site->renderAlerts();
			exit;
		}
		redirect_to('');
	}

	public function osfApiAction($do = '') {
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

}

