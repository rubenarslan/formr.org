<?php

class SuperadminController extends Controller {

    public function __construct(Site &$site) {
        parent::__construct($site);
        if (!$this->user->isSuperAdmin()) {
            alert('You do not have sufficient rights to access the requested location', 'alert-danger');
            redirect_to('admin');
        }
        if (!Request::isAjaxRequest()) {
            $default_assets = get_default_assets('admin');
            $this->registerAssets($default_assets);
        }
    }

    public function indexAction() {
        redirect_to('/');
    }

    public function ajaxAdminAction() {
        if (!is_ajax_request()) {
            return redirect_to('/');
        }

        $request = new Request($_POST);
        if ($request->user_id && is_numeric($request->admin_level)) {
            $this->setAdminLevel($request->user_id, $request->admin_level);
        }

        if ($request->user_api) {
            $this->apiUserManagement($request->user_id, $request->user_email, $request->api_action);
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
            if (!$user->setAdminLevel($level)) :
                alert('<strong>Something went wrong with the admin level change.</strong>', 'alert-danger');
                bad_request_header();
            else:
                alert('<strong>Level assigned to user.</strong>', 'alert-success');
            endif;
        }

        echo $this->site->renderAlerts();
        exit;
    }

    private function apiUserManagement($user_id, $user_email, $action) {
        $user = new User($this->fdb, $user_id, null);
        $response = new Response();
        $response->setContentType('application/json');
        if ($user->email !== $user_email) {
            $response->setJsonContent(array('success' => false, 'message' => 'Invalid User'));
        } elseif ($action === 'create') {
            $client = OAuthHelper::getInstance()->createClient($user);
            if (!$client) {
                $response->setJsonContent(array('success' => false, 'message' => 'Unable to create client'));
            } else {
                $client['user'] = $user->email;
                $response->setJsonContent(array('success' => true, 'data' => $client));
            }
        } elseif ($action === 'get') {
            $client = OAuthHelper::getInstance()->getClient($user);
            if (!$client) {
                $response->setJsonContent(array('success' => true, 'data' => array('client_id' => '', 'client_secret' => '', 'user' => $user->email)));
            } else {
                $client['user'] = $user->email;
                $response->setJsonContent(array('success' => true, 'data' => $client));
            }
        } elseif ($action === 'delete') {
            if (OAuthHelper::getInstance()->deleteClient($user)) {
                $response->setJsonContent(array('success' => true, 'message' => 'Credentials revoked for user ' . $user->email));
            } else {
                $response->setJsonContent(array('success' => false, 'message' => 'An error occured'));
            }
        } elseif ($action === 'change') {
            $client = OAuthHelper::getInstance()->refreshToken($user);
            if (!$client) {
                $response->setJsonContent(array('success' => false, 'message' => 'An error occured refereshing API secret.'));
            } else {
                $client['user'] = $user->email;
                $response->setJsonContent(array('success' => true, 'data' => $client));
            }
        }

        $response->send();
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

        $this->renderView('superadmin/cron_log_parsed', array(
            'files' => $files,
            'parse' => $parse,
            'parser' => $parser,
            'expand_logs' => $expand,
        ));
    }

    public function cronLogAction() {
        return $this->cronLogParsed();
    }

    public function userManagementAction() {
        $table = UserHelper::getUserManagementTablePdoStatement();
        $this->renderView('superadmin/user_management', $table);
    }

    public function activeUsersAction() {
        $table = UserHelper::getActiveUsersTablePdoStatement();
        $this->renderView('superadmin/active_users', array(
            'pdoStatement' => $table['pdoStatement'],
            'pagination' => $table['pagination'],
            'status_icons' => array(0 => 'fa-eject', 1 => 'fa-volume-off', 2 => 'fa-volume-down', 3 => 'fa-volume-up' )
        ));

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
            redirect_to('superadmin/runs_management' . $qs);
        } else {
            $this->renderView('superadmin/runs_management', RunHelper::getRunsManagementTablePdoStatement());
        }
    }

}
