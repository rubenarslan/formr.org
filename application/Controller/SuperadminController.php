<?php

class SuperadminController extends Controller {

    public function __construct(Site &$site) {
        parent::__construct($site);
        if (!$this->user->isSuperAdmin()) {
            formr_error(403, 'Unauthorized', 'You do not have access to this area');
        }
        if (!Request::isAjaxRequest()) {
            $default_assets = get_default_assets('admin');
            $this->registerAssets($default_assets);
        }
    }

    public function indexAction() {
        $this->request->redirect('/');
    }
    
    public function infoAction() {
        $this->setView('superadmin/info');
        return $this->sendResponse();
    }

    public function testOpencpuAction() {
        $this->setView('superadmin/test_opencpu');
        return $this->sendResponse();
    }

    public function testOpencpuSpeedAction() {
        $this->setView('superadmin/test_opencpu_speed');
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
        $user = new User($this->fdb, $user_id, null);

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
        $user = new User($this->fdb, $user_id, null);
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

        $this->setView('superadmin/cron_log_parsed', array(
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
        $table = UserHelper::getUserManagementTablePdoStatement();
        $this->setView('superadmin/user_management', $table);

        return $this->sendResponse();
    }

    public function activeUsersAction() {
        $table = UserHelper::getActiveUsersTablePdoStatement();
        $this->setView('superadmin/active_users', array(
            'pdoStatement' => $table['pdoStatement'],
            'pagination' => $table['pagination'],
            'status_icons' => array(0 => 'fa-eject', 1 => 'fa-volume-off', 2 => 'fa-volume-down', 3 => 'fa-volume-up' )
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
            $this->request->redirect('superadmin/runs_management' . $qs);
        } elseif ($id = $this->request->int('id')) {
            $runName = $this->fdb->findValue('survey_runs', array('id' => $id), 'name');
            $run = new Run($this->fdb, $runName);
            if (!$run->valid) {
                formr_error(404, 'Not Found', 'Run Not Found');
            }
            $this->setView('superadmin/runs_management_queue', array(
                'stmt' => UnitSessionQueue::getRunItems($run),
                'run' => $run,
            ));
            return $this->sendResponse();
        } else {
            $this->setView('superadmin/runs_management', RunHelper::getRunsManagementTablePdoStatement());
            return $this->sendResponse();
        }
    }

}
