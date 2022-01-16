<?php

class AdminAdvancedController extends Controller {

    public function __construct(Site &$site) {
        parent::__construct($site);
        if (!$this->user->isSuperAdmin()) {
            formr_error(403, 'Unauthorized', 'You do not have access to this area');
        }
        if (!Request::isAjaxRequest()) {
            $default_assets = get_default_assets('admin');
            $this->registerAssets($default_assets);
            $this->registerAssets('ace');
        }
    }

    public function indexAction() {
        $this->request->redirect('/');
    }

    public function infoAction() {
        $this->setView('admin/advanced/info');
        return $this->sendResponse();
    }

    public function timingAction() {
        $ocpu = OpenCPU::getInstance('opencpu_instance');

        $db_time = $this->fdb->query("SELECT NOW();")[0]["NOW()"];

        $ocpu_time = $ocpu->post('/base/R/Sys.time/json')->getRawResult();

        $this->setView('admin/advanced/timing', array(
            "php" => mysql_datetime(), 
            "db" => $db_time, 
            "ocpu" => $ocpu_time));
        return $this->sendResponse();
    }

    public function testOpencpuAction() {
        $this->setView('admin/advanced/test_opencpu');
        return $this->sendResponse();
    }

    public function testOpencpuSpeedAction() {
        $this->setView('admin/advanced/test_opencpu_speed');
        return $this->sendResponse();
    }

    public function ajaxAdminAction() {
        if (!Request::isAjaxRequest()) {
            return $this->request->redirect('/');
        }

        $request = new Request($_POST);

        if ($request->user_id && is_numeric($request->admin_level)) {
            $content = $this->setAdminLevel($request->user_id, $request->admin_level);
            return $this->sendResponse($content);
        }

        if ($request->user_api) {
            $content = $this->apiUserManagement($request->user_id, $request->user_email, $request->api_action);
            $this->response->setContentType('application/json');
            $this->response->setJsonContent($content);
            return $this->sendResponse();
        }
    }

    private function setAdminLevel($user_id, $level) {
        $level = (int) $level;
        $allowed_levels = array(0, 1, 100);
        $user = new User($user_id, null);

        if (!in_array($level, $allowed_levels) || !$user->email) {
            alert('<strong>Level not supported or could not be assigned to user</strong>', 'alert-danger');
        } elseif ($level == $user->getAdminLevel()) {
            alert('<strong>User already has requested admin rights</strong>', 'alert-warning');
        } else {
            if (!$user->setAdminLevel($level)) {
                alert('<strong>Something went wrong with the admin level change.</strong>', 'alert-danger');
                $this->response->setStatusCode(500, 'Bad Request');
            } else {
                alert('<strong>Level assigned to user.</strong>', 'alert-success');
            }
        }

        return $this->site->renderAlerts();
    }

    private function apiUserManagement($user_id, $user_email, $action) {
        $user = new User($user_id, null);
        $content = array();

        if ($user->email !== $user_email) {
            $content = array('success' => false, 'message' => 'Invalid User');
        } elseif ($action === 'create') {
            $client = OAuthHelper::getInstance()->createClient($user);
            if (!$client) {
                $content = array('success' => false, 'message' => 'Unable to create client');
            } else {
                $client['user'] = $user->email;
                $content = array('success' => true, 'data' => $client);
            }
        } elseif ($action === 'get') {
            $client = OAuthHelper::getInstance()->getClient($user);
            if (!$client) {
                $content = array('success' => true, 'data' => array('client_id' => '', 'client_secret' => '', 'user' => $user->email));
            } else {
                $client['user'] = $user->email;
                $content = array('success' => true, 'data' => $client);
            }
        } elseif ($action === 'delete') {
            if (OAuthHelper::getInstance()->deleteClient($user)) {
                $content = array('success' => true, 'message' => 'Credentials revoked for user ' . $user->email);
            } else {
                $content = array('success' => false, 'message' => 'An error occured');
            }
        } elseif ($action === 'change') {
            $client = OAuthHelper::getInstance()->refreshToken($user);
            if (!$client) {
                $content = array('success' => false, 'message' => 'An error occured refereshing API secret.');
            } else {
                $client['user'] = $user->email;
                $content = array('success' => true, 'data' => $client);
            }
        }

        return $content;
    }

    public function cronLogParsed() {
        $parser = new LogParser();
        $files = $parser->getCronLogFiles();
        $file = $this->request->getParam('f');
        $expand = $this->request->getParam('e');
        $parse = null;
        if ($file && isset($files[$file])) {
            $parse = $file;
        }

        $this->setView('admin/advanced/cron_log_parsed', array(
            'files' => $files,
            'parse' => $parse,
            'parser' => $parser,
            'expand_logs' => $expand,
        ));

        return $this->sendResponse();
    }

    public function cronLogAction() {
        return $this->cronLogParsed();
    }

    public function userManagementAction() {
        $table = UserHelper::getUserManagementTablePdoStatement($this->request->getParams());
        $this->setView('admin/advanced/user_management', $table);

        return $this->sendResponse();
    }

    public function activeUsersAction() {
        $table = UserHelper::getActiveUsersTablePdoStatement();
        $this->setView('admin/advanced/active_users', array(
            'pdoStatement' => $table['pdoStatement'],
            'pagination' => $table['pagination'],
            'status_icons' => array(0 => 'fa-eject', 1 => 'fa-volume-off', 2 => 'fa-volume-down', 3 => 'fa-volume-up')
        ));

        return $this->sendResponse();
    }

    public function runsManagementAction() {
        if (Request::isHTTPPostRequest()) {
            // process post request and redirect
            foreach ($this->request->arr('runs') as $id => $data) {
                $update = array(
                    'cron_active' => (int) isset($data['cron_active']),
                    'cron_fork' => (int) isset($data['cron_fork']),
                    'locked' => (int) isset($data['locked']),
                );
                $this->fdb->update('survey_runs', $update, array('id' => (int) $id));
            }
            alert('Changes saved', 'alert-success');
            $qs = $this->request->page ? '/?page=' . $this->request->page : null;
            $this->request->redirect('admin/advanced/runs-management' . $qs);
        } elseif ($id = $this->request->int('id')) {
            $run = new Run(null, $id);
            if (!$run->valid) {
                formr_error(404, 'Not Found', 'Run Not Found');
            }
            $this->setView('admin/advanced/runs_management_queue', array(
                'stmt' => UnitSessionQueue::getRunItems($run),
                'run' => $run,
            ));
            return $this->sendResponse();
        } else {
            $this->setView('admin/advanced/runs_management', RunHelper::getRunsManagementTablePdoStatement());
            return $this->sendResponse();
        }
    }

    public function settingsAction() {
        if (Request::isHTTPPostRequest()) {
            $allowedSettings = array(
                'content:publications', 'content:footerimprint', 
                'links:policyurl', 'links:logourl', 'links:logolink', 
                'js:cookieconsent'
            );
            
            foreach ($allowedSettings as $setting) {
                if (($value = $this->request->getParam($setting)) !== null) {
                    $this->fdb->insert_update('survey_settings', array('setting' => $setting, 'value' => $value));
                }
            }

            alert('Settings saved', 'alert-success');
            $this->sendResponse($this->site->renderAlerts());
        }

        $this->setView('admin/advanced/settings', array('settings' => Site::getSettings()));
        return $this->sendResponse();
    }
    public function userDetailsAction() {
        $querystring = array();
        $queryparams = array( 'position_operator' => '=');

        if ($this->request->position_lt && in_array($this->request->position_lt, array('=', '>', '<'))) {
            $queryparams['position_operator'] = $this->request->position_lt;
            $querystring['position_lt'] = $queryparams['position_operator'];
        }

        if ($this->request->run_name) {
            $queryparams['run_name'] = $this->request->run_name;
            $querystring['run_name'] = $queryparams['run_name'];
        }

        if ($this->request->session) {
            $session = str_replace("…", "", $this->request->session);
            $queryparams['session'] = "%" . $session . "%";
            $querystring['session'] = $session;
        }

        if ($this->request->position) {
            $queryparams['position'] = $this->request->position;
            $querystring['position'] = $queryparams['position'];
        }

        $table = $this->getUserDetailTable($queryparams);
        $users = $table['data'];

        foreach ($users as $i => $userx) {
            if ($userx['expired']) {
                $stay_seconds = strtotime($userx['expired']) - strtotime($userx['created']);
            } else {
                $stay_seconds = ($userx['ended'] ? strtotime($userx['ended']) : time() ) - strtotime($userx['created']);
            }
            $userx['stay_seconds'] = $stay_seconds;
            if ($userx['expired']) {
                $userx['ended'] = $userx['expired'] . ' (expired)';
            }

            if ($userx['unit_type'] != 'Survey') {
                $userx['delete_msg'] = "Are you sure you want to delete this unit session?";
                $userx['delete_title'] = "Delete this waypoint";
            } else {
                $userx['delete_msg'] = "You SHOULDN'T delete survey sessions, you might delete data! <br />Are you REALLY sure you want to continue?";
                $userx['delete_title'] = "Survey unit sessions should not be deleted";
            }

            $users[$i] = $userx;
        }

        $this->setView('admin/advanced/user_detail', array(
            'users' => $users,
            'pagination' => $table['pagination'],
            'position_lt' => $queryparams['position_operator'],
            'querystring' => $querystring,
        ));

        return $this->sendResponse();
    }

    private function getUserDetailTable($queryParams, $page = null) {
        $query = array();
        if (!empty($queryParams['run_name'])) {
            $query[] = ' `survey_runs`.name LIKE :run_name ';
        }

        if (!empty($queryParams['session'])) {
            $query[] = ' `survey_run_sessions`.session LIKE :session ';
        }

        if (!empty($queryParams['position'])) {
            $query[] = " `survey_run_units`.position {$queryParams['position_operator']} :position ";
        }
        unset($queryParams['position_operator']);

        if(count($query) > 0 ) {
            $where = "WHERE " . implode(' AND ', $query);
        } else {
            $where = "";
        }

        $itemsQuery = "SELECT 
                `survey_run_sessions`.session,
                `survey_unit_sessions`.id AS session_id,
                `survey_runs`.name AS run_name,
                `survey_run_units`.position,
                `survey_run_units`.description,
                `survey_units`.type AS unit_type,
                `survey_unit_sessions`.created,
                `survey_unit_sessions`.ended,
                `survey_unit_sessions`.expired,
                `survey_unit_sessions`.expires,
                `survey_unit_sessions`.`queued`,
                `survey_unit_sessions`.result,
                `survey_unit_sessions`.result_log
            FROM `survey_unit_sessions`
            LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
            LEFT JOIN `survey_units` ON `survey_unit_sessions`.unit_id = `survey_units`.id
            LEFT JOIN `survey_run_units` ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
            LEFT JOIN `survey_runs` ON `survey_runs`.id = `survey_run_units`.run_id
            {$where}
            ORDER BY `survey_run_sessions`.id DESC,`survey_unit_sessions`.id ASC LIMIT 1000
        ";

        return array(
            'data' => $this->fdb->execute($itemsQuery, $queryParams),
            'pagination' => "",
        );
    }

}
