<?php

class AdminController extends Controller {

	public function __construct(Site &$site) {
		parent::__construct($site);
		$this->header();
	}

	public function indexAction() {
		$this->renderView('misc/index', array(
			'runs' => $this->user->getRuns(),
			'studies' => $this->user->getStudies(),
		));
	}

	public function infoAction() {
		$this->renderView('misc/info');
	}

	public function cronAction() {
		$this->renderView('misc/cron', array("fdb"=> $this->fdb));
	}

	public function cronForkedAction() {
		$this->renderView('misc/cron_forked');
	}

	public function testOpencpuAction() {
		$this->renderView('misc/test_opencpu');
	}

	public function testOpencpuSpeedAction() {
		$this->renderView('misc/test_opencpu_speed');
	}

	protected function renderView($template, $vars = array()) {
		$template = 'admin/' . $template;
		parent::renderView($template, $vars);
	}

	protected function header() {
		if (!$this->user->isAdmin()) {
			alert("<strong>Sorry:</strong> Only admins have access.", 'alert-info');
			access_denied();
		}

		if ($this->site->inSuperAdminArea() AND ! $this->user->isSuperAdmin()) {
			alert("<strong>Sorry:</strong> Only superadmins have access.", 'alert-info');
			access_denied();
		}
	}

}
