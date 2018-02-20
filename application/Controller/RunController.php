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
			formr_error(404, 'Not Found', 'The request URL was not found');
		}

		$this->user = $this->site->loginUser($this->user);
		$this->run = $this->getRun();
		$run_vars = $this->run->exec($this->user);
		$run_vars['bodyClass'] = 'fmr-run';

		$assset_vars = $this->filterAssets($run_vars);
		unset($run_vars['css'], $run_vars['js']);
		$this->renderView('public/run/index', array_merge($run_vars, $assset_vars));
	}

	protected function settingsAction() {		
		$run = $this->getRun();
		$run_name = $this->site->request->run_name;

		if (!$run->valid) {
			formr_error(404, 'Run Not Found', 'Requested Run does not exist or has been moved');
		}

		// Login if user entered with code and redirect without login code
		if (Request::isHTTPGetRequest() && ($code = $this->request->getParam('code'))) {
			$_GET['run_name'] = $run_name;
			$this->user = $this->site->loginUser($this->user);
			if ($this->user->user_code != $code) {
				alert('Unable to login with the provided code', 'alert-warning');
			}
			redirect_to(run_url($run_name, 'settings'));
		}

		// People who have no session in the run need not set anything
		$session = new RunSession($this->fdb, $run->id, 'cron', $this->user->user_code);
		if (!$session->id) {
			formr_error(403, 'Unauthorized Access', 'You cannot create settings in a study you have not participated in.');
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
			redirect_to(run_url($run_name, 'settings'));
		}

		$this->run = $run;
		$this->renderView('public/run/settings', array(
			'settings' => $session->getSettings(),
			'email_subscriptions' => Config::get('email_subscriptions'),
		));
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
		$runHelper = new RunHelper($this->request, $this->fdb, $run->name);

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
			alert($errors, 'alert-danger');
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

	private function getRun() {
		$name = $this->request->str('run_name');
		$run = new Run($this->fdb, $name);
		if ($name !== Run::TEST_RUN && Config::get('use_study_subdomains') && !FMRSD_CONTEXT) {
			//throw new Exception('Invalid Study Context');
			// Redirect existing users to run's sub-domain URL and QSA
			$params = $this->request->getParams();
			unset($params['route'], $params['run_name']);
			$url = run_url($name, null, $params);
			redirect_to($url);
		} elseif (!$run->valid) {
			$msg = __('If you\'re trying to create an online,  <a href="%s">read the full documentation</a> to learn how to set one up.', site_url('documentation'));
			formr_error(400, 'There isn\'t an online study here.', $msg);
		}

		return $run;
	}

	private function getPrivateActionMethod($action) {
		$actionName = $this->getPrivateAction($action, '-', true) . 'Action';
		if (!method_exists($this, $actionName)) {
			return false;
		}
		return $actionName;
	}

	private function filterAssets($assets) {
		$vars = array();
		if ($this->run->use_material_design === true || $this->request->str('tmd') === 'true') {
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

}
