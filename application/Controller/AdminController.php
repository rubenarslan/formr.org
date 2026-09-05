<?php

class AdminController extends Controller {

    public function __construct(Site &$site) {
        parent::__construct($site);
        $this->header();
        if (!Request::isAjaxRequest()) {
            $this->registerAssets('admin');
        }
    }

    public function indexAction() {
        $this->setView('home', array(
            'runs' => $this->user->getRuns('id DESC', 5),
            'studies' => $this->user->getStudies('id DESC', 5),
        ));
        return $this->sendResponse();
    }

    /**
     * Issue #608: per-run compute/runtime usage for the logged-in study
     * admin (their own runs only). Superadmins get an instance-wide view at
     * admin/advanced/compute_usage.
     */
    public function computeAction() {
        $this->setView('compute', array(
            'totals' => ComputeUsageHelper::totalsForUser($this->user->id),
            'runs' => ComputeUsageHelper::runUsageForUser($this->user->id),
        ));
        return $this->sendResponse();
    }


    protected function setView($template, $vars = array(), $prepend_admin = true) {
        if ($prepend_admin) {
            $template = 'admin/' . $template;
        }
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
        // The unit's identity never comes from the request: a posted `id`
        // would make create() modify() / insert_update an existing unit by
        // bare id. Only the explicit $id (delete_run_unit) may address one.
        unset($data['id']);
        if ($id) {
            $data['id'] = (int) $id;
        }

        $unit = RunUnitFactory::make($this->run, $data);
        if ($id && !$unit->id) {
            // find() could not place $id in this run and the constructor
            // dropped it; refuse instead of create()-ing a stray new unit.
            return $unit;
        }
        
        return $unit->create($data);
    }

}
