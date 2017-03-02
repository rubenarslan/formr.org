<?php

class AdminController extends Controller {

	public function __construct(Site &$site) {
		parent::__construct($site);
		$this->header();
		if (!Request::isAjaxRequest()) {
			$default_assets = get_default_assets('admin');
			$this->registerCSS($default_assets['css']);
			$this->registerJS($default_assets['js']);
		}
	}

	public function indexAction() {
		$this->renderView('home', array(
			'runs' => $this->user->getRuns(),
			'studies' => $this->user->getStudies(),
		));
	}

	public function infoAction() {
		$this->renderView('misc/info');
	}
/*
	public function cronAction() {
		$this->renderView('misc/cron', array("fdb"=> $this->fdb));
	}

	public function cronForkedAction() {
		$this->renderView('misc/cron_forked');
	}
*/
	public function testOpencpuAction() {
		$this->renderView('misc/test_opencpu');
	}

	public function testOpencpuSpeedAction() {
		$this->renderView('misc/test_opencpu_speed');
	}

	public function osfAction() {
		if (!($token = OSF::getUserAccessToken($this->user))) {
			redirect_to('osf-api/login');
		}

		$osf = new OSF(Config::get('osf'));
		$osf->setAccessToken($token);

		if (Request::isHTTPPostRequest() && $this->request->getParam('osf_action') === 'export-run') {
			$run = new Run($this->fdb, $this->request->getParam('formr_project'));
			$osf_project = $this->request->getParam('osf_project');
			if (!$run->valid || !$osf_project) {
				throw new Exception('Invalid Request');
			}

			$unitIds = $run->getAllUnitTypes();
			$units = array();
			$factory = new RunUnitFactory();

			/* @var RunUnit $u */
			foreach ($unitIds as $u) {
				$unit = $factory->make($this->fdb, null, $u, null, $run);
				$ex_unit = $unit->getExportUnit();
				$ex_unit['unit_id'] = $unit->id;
				$units[] = (object) $ex_unit;
			}

			$export = $run->export($run->name, $units, true);
			$export_file = Config::get('survey_upload_dir') . '/run-' . time() . '-' . $run->name . '.json';
			$create = file_put_contents($export_file, json_encode($export, JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE + JSON_NUMERIC_CHECK));
			$response = $osf->upload($osf_project, $export_file, $run->name . '.json');
			@unlink($export_file);

			if (!$response->hasError()) {
				$run->saveSettings(array('osf_project_id' => $osf_project));
				alert('Run exported to OSF', 'alert-success');
			} else {
				alert($response->getError(), 'alert-danger');
			}

			if ($redirect = $this->request->getParam('redirect')) {
				redirect_to($redirect);
			}
		}

		// @todo implement get projects recursively
		$response = $osf->getProjects();
		$osf_projects = array();
		if ($response->hasError()) {
			alert($response->getError(), 'alert-danger');
		} else {
			foreach ($response->getJSON()->data as $project) {
				$osf_projects[] = array('id' => $project->id, 'name' => $project->attributes->title);
			}
		}

		$this->renderView('misc/osf', array(
			'token' => $token,
			'runs' => $this->user->getRuns(),
			'run_selected'=> $this->request->getParam('run'),
			'osf_projects' => $osf_projects,
		));
	}

	protected function renderView($template, $vars = array()) {
		$template = 'admin/' . $template;
		parent::renderView($template, $vars);
	}

	protected function header() {
		if (!$this->user->isAdmin()) {
			alert('You need to login to access the admin section', 'alert-warning');
			redirect_to('login');
		}

		if ($this->site->inSuperAdminArea() && !$this->user->isSuperAdmin()) {
			alert("<strong>Sorry:</strong> Only superadmins have access.", 'alert-info');
			access_denied();
		}
	}

	public function createRunUnit($id = null) {
		$dbh = $this->fdb;
		$run = $this->run;
		$unit_factory = new RunUnitFactory();
		$unit_data = array(
			'type' => $this->request->type,
			'position' => (int)$this->request->position,
			'special' => $this->request->special,
		);
		$unit_data = array_merge($this->request->getParams(), $unit_data, RunUnit::getDefaults($this->request->type), RunUnit::getDefaults($this->request->special));
		if ($id) {
			$unit_data['unit_id'] = $id;
		}
		$unit = $unit_factory->make($dbh, null, $unit_data, null, $run);
		$unit->create($unit_data);
		return $unit;
	}

}
