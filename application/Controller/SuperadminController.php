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
            if (!$user->setAdminLevelTo($level)) :
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
        // @todo: deprecate code
        $cron_entries_count = $this->fdb->count('survey_cron_log');
        $pagination = new Pagination($cron_entries_count);
        $limits = $pagination->getLimits();

        $cron_entries_query = "SELECT 
			`survey_cron_log`.id,
			`survey_users`.email,
			`survey_cron_log`.run_id,
			`survey_cron_log`.created,
			`survey_cron_log`.ended - `survey_cron_log`.created AS time_in_seconds,
			`survey_cron_log`.sessions, 
			`survey_cron_log`.skipbackwards, 
			`survey_cron_log`.skipforwards, 
			`survey_cron_log`.pauses, 
			`survey_cron_log`.emails, 
			`survey_cron_log`.shuffles, 
			`survey_cron_log`.errors, 
			`survey_cron_log`.warnings, 
			`survey_cron_log`.notices, 
			`survey_cron_log`.message,
			`survey_runs`.name AS run_name
		FROM `survey_cron_log` LEFT JOIN `survey_runs` ON `survey_cron_log`.run_id = `survey_runs`.id
		LEFT JOIN `survey_users` ON `survey_users`.id = `survey_runs`.user_id
		ORDER BY `survey_cron_log`.id DESC LIMIT $limits;";

        $g_cron = $this->fdb->execute($cron_entries_query, array('user_id', $this->user->id));
        $cronlogs = array();
        foreach ($g_cron as $cronlog) {
            $cronlog = array_reverse($cronlog, true);
            $cronlog['Modules'] = '<small>';

            if ($cronlog['pauses'] > 0)
                $cronlog['Modules'] .= $cronlog['pauses'] . ' <i class="fa fa-pause"></i> ';
            if ($cronlog['skipbackwards'] > 0)
                $cronlog['Modules'] .= $cronlog['skipbackwards'] . ' <i class="fa fa-backward"></i> ';
            if ($cronlog['skipforwards'] > 0)
                $cronlog['Modules'] .= $cronlog['skipforwards'] . ' <i class="fa fa-forward"></i> ';
            if ($cronlog['emails'] > 0)
                $cronlog['Modules'] .= $cronlog['emails'] . ' <i class="fa fa-envelope"></i> ';
            if ($cronlog['shuffles'] > 0)
                $cronlog['Modules'] .= $cronlog['shuffles'] . ' <i class="fa fa-random"></i>';
            $cronlog['Modules'] .= '</small>';
            $cronlog['took'] = '<small>' . round($cronlog['time_in_seconds'] / 60, 2) . 'm</small>';
            $cronlog['time'] = '<small title="' . $cronlog['created'] . '">' . timetostr(strtotime($cronlog['created'])) . '</small>';
            $cronlog['Run name'] = $cronlog['run_name'];
            $cronlog['Owner'] = $cronlog['email'];

            $cronlog = array_reverse($cronlog, true);
            unset($cronlog['run_name']);
            unset($cronlog['created']);
            unset($cronlog['time_in_seconds']);
            unset($cronlog['skipforwards']);
            unset($cronlog['skipbackwards']);
            unset($cronlog['pauses']);
            unset($cronlog['emails']);
            unset($cronlog['shuffles']);
            unset($cronlog['run_id']);
            unset($cronlog['id']);
            unset($cronlog['email']);

            $cronlogs[] = $cronlog;
        }

        $this->renderView('superadmin/cron_log', array(
            'cronlogs' => $cronlogs,
            'pagination' => $pagination,
        ));
    }

    public function userManagementAction() {
        $user_count = $this->fdb->count('survey_users');
        $pagination = new Pagination($user_count, 200, true);
        $limits = $pagination->getLimits();

        $users_query = "SELECT 
			`survey_users`.id,
			`survey_users`.created,
			`survey_users`.modified,
			`survey_users`.email,
			`survey_users`.admin,
			`survey_users`.email_verified
		FROM `survey_users`
		ORDER BY `survey_users`.id ASC  LIMIT $limits;";

        $g_users = $this->fdb->prepare($users_query);
        $g_users->execute();

        $users = array();
        while ($userx = $g_users->fetch(PDO::FETCH_ASSOC)) {
            $userx['Email'] = '<a href="mailto:' . h($userx['email']) . '">' . h($userx['email']) . '</a>' . ($userx['email_verified'] ? " <i class='fa fa-check-circle-o'></i>" : " <i class='fa fa-envelope-o'></i>");
            $userx['Created'] = "<small class='hastooltip' title='{$userx['created']}'>" . timetostr(strtotime($userx['created'])) . "</small>";
            $userx['Modified'] = "<small class='hastooltip' title='{$userx['modified']}'>" . timetostr(strtotime($userx['modified'])) . "</small>";
            $userx['Admin'] = "
				<form class='form-inline form-ajax' action='" . site_url('superadmin/ajax_admin') . "' method='post'>
				<span class='input-group' style='width:160px'>
					<span class='input-group-btn'>
						<button type='submit' class='btn hastooltip' title='Give this level to this user'><i class='fa fa-hand-o-right'></i></button>
					</span>
					<input type='hidden' name='user_id' value='{$userx['id']}'>
					<input type='number' name='admin_level' max='100' min='-1' value='" . h($userx['admin']) . "' class='form-control'>
				</span>
			</form>";
            $userx['API Access'] = '<button type="button" class="btn api-btn hastooltip" title="Manage API Access" data-user="' . $userx['id'] . '" data-email="' . $userx['email'] . '"><i class="fa fa-cloud"></i></button>';

            unset($userx['email'], $userx['created'], $userx['modified'], $userx['admin'], $userx['id'], $userx['email_verified']);

            $users[] = $userx;
        }

        $this->renderView('superadmin/user_management', array(
            'users' => $users,
            'pagination' => $pagination,
        ));
    }

    public function activeUsersAction() {
        $user_count = $this->fdb->count('survey_users');
        $pagination = new Pagination($user_count, 200, true);
        $limits = $pagination->getLimits();

        $users_query = "SELECT 
			`survey_users`.id,
			`survey_users`.created,
			`survey_users`.modified,
			`survey_users`.email,
			`survey_users`.admin,
			`survey_users`.email_verified,
			`survey_runs`.name AS run_name,
			`survey_runs`.cron_active,
			`survey_runs`.public,
			COUNT(`survey_run_sessions`.id) AS number_of_users_in_run,
			MAX(`survey_run_sessions`.last_access) AS last_edit
		FROM `survey_users`
		LEFT JOIN `survey_runs` ON `survey_runs`.user_id = `survey_users`.id
		LEFT JOIN `survey_run_sessions` ON `survey_runs`.id = `survey_run_sessions`.run_id
		WHERE `survey_users`.admin > 0
		GROUP BY `survey_runs`.id
		ORDER BY `survey_users`.id ASC, last_edit DESC LIMIT $limits;";

        $g_users = $this->fdb->prepare($users_query);
        $g_users->execute();

        $users = array();
        $last_user = "";
        while ($userx = $g_users->fetch(PDO::FETCH_ASSOC)) {
            $public_status = (int) $userx['public'];
            $public_logo = '';
            if ($public_status === 0):
                $public_logo = "fa-eject";
            elseif ($public_status === 1):
                $public_logo = "fa-volume-off";
            elseif ($public_status === 2):
                $public_logo = "fa-volume-down";
            elseif ($public_status === 3):
                $public_logo = "fa-volume-up";
            endif;
            if ($last_user !== $userx['id']):
                $userx['Email'] = '<a href="mailto:' . h($userx['email']) . '">' . h($userx['email']) . '</a>' . ($userx['email_verified'] ? " <i class='fa fa-check-circle-o'></i>" : " <i class='fa fa-envelope-o'></i>");
                $last_user = $userx['id'];
            else:
                $userx['Email'] = '';
            endif;
            $userx['Created'] = "<small class='hastooltip' title='{$userx['created']}'>" . timetostr(strtotime($userx['created'])) . "</small>";
            $userx['Modified'] = "<small class='hastooltip' title='{$userx['modified']}'>" . timetostr(strtotime($userx['modified'])) . "</small>";
            $userx['Run'] = h($userx['run_name']) . " " .
                    ($userx['cron_active'] ? "<i class='fa fa-cog'></i>" : "") . ' ' .
                    "<i class='fa $public_logo'></i>";
            $userx['Users'] = $userx['number_of_users_in_run'];
            $userx['Last active'] = "<small class='hastooltip' title='{$userx['last_edit']}'>" . timetostr(strtotime($userx['last_edit'])) . "</small>";

            unset($userx['email']);
            unset($userx['created']);
            unset($userx['modified']);
            unset($userx['admin']);
            unset($userx['number_of_users_in_run']);
            unset($userx['public']);
            unset($userx['cron_active']);
            unset($userx['run_name']);
            unset($userx['id']);
            unset($userx['last_edit']);
            unset($userx['email_verified']);
            #	$user['body'] = "<small title=\"{$user['body']}\">". substr($user['body'],0,50). "…</small>";

            $users[] = $userx;
        }

        $this->renderView('superadmin/active_users', array(
            'users' => $users,
            'pagination' => $pagination,
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
            redirect_to('superadmin/runs_management');
        }

        $q = 'SELECT survey_runs.id AS run_id, name, survey_runs.user_id, cron_active, cron_fork, locked, count(survey_run_sessions.session) AS sessions, survey_users.email
			  FROM survey_runs 
			  LEFT JOIN survey_users ON survey_users.id = survey_runs.user_id 
			  LEFT JOIN survey_run_sessions ON survey_run_sessions.run_id = survey_runs.id 
			  GROUP BY survey_run_sessions.run_id
			  ORDER BY survey_runs.name ASC
		';
        $this->renderView('superadmin/runs_management', array(
            'runs' => $this->fdb->query($q),
            'i' => 1,
        ));
    }

}
