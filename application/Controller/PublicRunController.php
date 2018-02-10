<?php

class PublicRunController extends Controller {

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
		if ($method = $this->getPrivateActionMethod($privateAction)) {
			return $this->$method();
		}

		$this->user = $this->site->loginUser($this->user);
		$run = new Run($this->fdb, $this->request->str('run_name'));
		$this->run = $run;
		$run_vars = $run->exec($this->user);
		$run_vars['bodyClass'] = 'fmr-run';

		if ($run->use_material_design === true || $this->request->str('tmd') === 'true') {
			if (DEBUG) {
				$this->unregisterAssets('site:custom');
				$this->registerAssets('bootstrap-material-design');
				$this->registerAssets('site:custom');
			} else {
				$this->replaceAssets('site', 'site:material');
			}
			$run_vars['bodyClass'] = 'bs-material fmr-run';
		}
		$this->registerCSS($run_vars['css'], $this->run->name);
		$this->registerJS($run_vars['js'], $this->run->name);

		unset($run_vars['css'], $run_vars['js']);
		$this->renderView('public/run', $run_vars);
	}

	protected function getPrivateActionMethod($action) {
		$actionName = $this->getPrivateAction($action, '-', true) . 'Action';
		if (!method_exists($this, $actionName)) {
			return false;
		}
		return $actionName;
	}

	protected function settingsAction() {
		$run_name = $this->request->run_name;
		$run = new Run($this->fdb, $run_name);
		if (!$run->valid) {
			alert(' Invalid Run settings', 'alert-danger');
			not_found();
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
			alert('You cannot create settings in a study you have not participated in.', 'alert-danger');
			redirect_to('error/200');
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
		$this->renderView('public/settings', array(
			'settings' => $session->getSettings(),
			'email_subscriptions' => Config::get('email_subscriptions'),
		));
	}

}
