<?php

class PublicController extends Controller {
	public function __construct(Site &$site) {
		parent::__construct($site);
		if (!Request::isAjaxRequest()) {
			$default_assets = get_default_assets('site');
			$this->registerAssets($default_assets);
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

	public function publicationsAction() {
		$this->renderView('public/publications');
	}

	public function accountAction() {
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
				if ($this->request->str('new_password') !== $this->request->str('new_password_c')) {
					alert('The new passwords do not match', 'alert-danger');
				} elseif($this->user->changePassword($this->request->str('password'), $this->request->str('new_password'))) {
					alert('<strong>Success!</strong> Your password was changed! Please sign-in with your new password.','alert-success');
					$redirect = 'logout';
				} else {
					alert(implode($this->user->errors), 'alert-danger');
				}
			}

			if($this->request->str('new_email')) {
				if ($this->fdb->entry_exists('survey_users', array('email' => $this->request->str('new_email')))) {
					alert('The provided email address is already in use!', 'alert-danger');
				} elseif ($this->user->changeEmail($this->request->str('password'), $this->request->str('new_email'))) {
					//alert('<strong>Success!</strong> Your email address was changed! Please veirfy your new email and sign-in.', 'alert-success');
					$redirect = 'logout';
				} else {
					alert(implode($this->user->errors), 'alert-danger');
				}
			}

			if($redirect) {
				redirect_to($redirect);
			}
		}

		$this->registerAssets('bootstrap-material-design');
		$this->renderView('public/account', array('user' => $this->user));
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

		$this->registerAssets('bootstrap-material-design');
		$this->renderView('public/login', array('alerts' => $this->site->renderAlerts()));
	}

	public function logoutAction() {
		$user = $this->user;
		if($user->loggedIn()) {
			alert('You have been logged out!', 'alert-info');
			$alerts = $this->site->renderAlerts();
			$user->logout();
			$this->registerAssets('bootstrap-material-design');
			$this->renderView('public/login', array('alerts' => $alerts));
		} else {
			Session::destroy();
			$redirect_to = $this->request->getParam('_rdir');
			redirect_to($redirect_to);
		}
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

		$this->registerAssets('bootstrap-material-design');
		$this->renderView('public/register');
	}

	public function verifyEmailAction() {
		$user = $this->user;
		$verification_token = $this->request->str('verification_token');
		$email = $this->request->str('email');

		if(!$verification_token || !$email) {
			alert("You need to follow the link you received in your verification mail.");
			redirect_to('login');
		} else {
			$user->verify_email($email, $verification_token);
			redirect_to('login');
		};
	}

	public function forgotPasswordAction() {
		if($this->user->loggedIn()) {
			redirect_to("index");
		}

		if($this->request->str('email')) {
			$this->user->forgot_password($this->request->str('email'));
		}

		$this->registerAssets('bootstrap-material-design');
		$this->renderView('public/forgot_password');
	}

	public function resetPasswordAction() {
		$user = $this->user;
		if($user->loggedIn()) {
			redirect_to('index');
		}

		if ($this->request->isHTTPGetRequest() && (!$this->request->str('email') || !$this->request->str('reset_token')) && !$this->request->str('ok')) {
			alert('You need to follow the link you received in your password reset mail');
			redirect_to('forgot_password');
		} elseif ($this->request->isHTTPPostRequest()) {
			$postRequest = new Request($_POST);
			$email = $postRequest->str('email');
			$token = $postRequest->str('reset_token');
			$newPass = $postRequest->str('new_password');
			$newPassOK = $postRequest->str('new_password_c');
			if (($done = $user->reset_password($email, $token, $newPass, $newPassOK))) {
				redirect_to('forgot_password');
			}
		}

		$this->registerAssets('bootstrap-material-design');
		$this->renderView('public/reset_password', array(
			'reset_data_email' => $this->request->str('email'),
			'reset_data_token' => $this->request->str('reset_token'),
		));
	}

	public function fileDownloadAction($run_id = 0, $original_filename = '') {
		$path = $this->fdb->findValue('survey_uploaded_files', array('run_id' => (int)$run_id, 'original_file_name' => $original_filename), array('new_file_path'));
		if ($path) {
			return redirect_to(asset_url($path));
		}
		bad_request();
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

	public function errorAction($code = null) {
		if ($code == 200) {
			// do nothing
		} elseif ($code == 400) {
			header('HTTP/1.0 404 Not Found');
		} elseif ($code == 403) {
			header('HTTP/1.0 403 Forbidden');
		} else {
			header('HTTP/1.0 500 Bad Request');
		}
		$this->renderView('public/error');
		exit;
	}

}

