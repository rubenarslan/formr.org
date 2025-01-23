<?php

class AdminController extends Controller {

    public function __construct(Site &$site) {
        parent::__construct($site);
        $this->header();
        if (!Request::isAjaxRequest()) {
            $default_assets = get_default_assets('admin');
            $this->registerAssets($default_assets);
            $this->registerAssets('ace');
        }
    }

    public function indexAction() {
        $this->setView('home', array(
            'runs' => $this->user->getRuns('id DESC', 5),
            'studies' => $this->user->getStudies('id DESC', 5),
        ));
        return $this->sendResponse();
    }

    public function osfAction() {
        if (!($token = OSF::getUserAccessToken($this->user))) {
            $this->request->redirect('api/osf/login');
        }

        $osf = new OSF(Config::get('osf'));
        $osf->setAccessToken($token);

        if (Request::isHTTPPostRequest() && $this->request->getParam('osf_action') === 'export-run') {
            $run = new Run($this->request->getParam('formr_project'));
            $osf_project = $this->request->getParam('osf_project');
            if (!$run->valid || !$osf_project) {
                throw new Exception('Invalid Request');
            }

            $unitIds = $run->getAllUnitTypes();
            $units = array();

            /* @var RunUnit $u */
            foreach ($unitIds as $u) {
                $unit = RunUnitFactory::make($run, $u);
                $ex_unit = $unit->getExportUnit();
                $ex_unit['unit_id'] = $unit->id;
                $units[] = (object) $ex_unit;
            }

            $export = $run->export($run->name, $units, true);
            $export_file = Config::get('survey_upload_dir') . '/run-' . time() . '-' . $run->name . '.json';
            $create = file_put_contents($export_file, json_encode($export, JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE + JSON_NUMERIC_CHECK));
            $response = $osf->upload($osf_project, $export_file, $run->name . '-' . date('YmdHis') . '.json');
            @unlink($export_file);

            if (!$response->hasError()) {
                $run->saveSettings(array('osf_project_id' => $osf_project));
                alert('Run exported to OSF', 'alert-success');
            } else {
                alert($response->getError(), 'alert-danger');
            }

            if ($redirect = $this->request->getParam('redirect')) {
                $this->request->redirect($redirect);
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

        $this->setView('misc/osf', array(
            'token' => $token,
            'runs' => $this->user->getRuns(),
            'run_selected' => $this->request->getParam('run'),
            'osf_projects' => $osf_projects,
        ));

        return $this->sendResponse();
    }

    protected function setView($template, $vars = array()) {
        $template = 'admin/' . $template;
        parent::setView($template, $vars);
    }

    protected function header() {
        if (!($cookie = Session::getAdminCookie())) {
            alert('You need to login to access the admin section', 'alert-warning');
            $this->request->redirect('admin/account/login');
        }
        
        $this->user = new User($cookie[0], $cookie[1]);

        // Check if 2FA is required but not set up
        if (Config::get('2fa.required', false) && !$this->user->is2FAenabled()) {
            alert('Two-factor authentication is required. Please set it up now.', 'alert-warning');
            $this->request->redirect('admin/account/setup-two-factor');
        }

        if (!$this->user->isAdmin()) {
            $docLink = site_url('documentation/#get_started');
            alert('You need to request for an admin account in order to access this section. <a href="'.$docLink.'">See Documentation</a>.', 'alert-warning');
            $this->request->redirect('admin/account');
        }

        if ($this->site->inSuperAdminArea() && !$this->user->isSuperAdmin()) {
            formr_error(403, 'Unauthorized', 'You are not authorized to access this section.');
        }
    }

    public function createRunUnit($id = null) {
        $data = array_merge($this->request->getParams(), RunUnit::getDefaults($this->request->type), RunUnit::getDefaults($this->request->special));
        if ($id) {
            $data['id'] = $id;
        }

        $unit = RunUnitFactory::make($this->run, $data);
        
        return $unit->create($data);
    }

}
