<?php

class SuperadminController extends Controller {

	public function __construct(Site &$site) {
		parent::__construct($site);
	}

	public function indexAction() {
		redirect_to('public/index');
	}

	public function ajaxAdminAction() {
		if (is_ajax_request()):
			if (isset($_POST['admin_level']) AND isset($_GET['user_id'])):
				$user_to_edit = new User($this->fdb, $_GET['user_id'], null);
				if (!$user_to_edit->setAdminLevelTo($_POST['admin_level'])):
					alert('<strong>Something went wrong with the admin level change.</strong>', 'alert-danger');
					bad_request_header();
				endif;
			endif;
			echo $site->renderAlerts();
			exit;
		else:
			redirect_to("/");
		endif;
	}

	public function cronLogAction() {
		$cron_entries = $this->fdb->prepare("SELECT COUNT(`survey_cron_log`.id) AS count FROM `survey_cron_log`");

		$pagination = new Pagination($cron_entries);
		$limits = $pagination->getLimits();

		$g_cron = $this->fdb->prepare("SELECT 
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
		FROM `survey_cron_log`
		LEFT JOIN `survey_runs`
		ON `survey_cron_log`.run_id = `survey_runs`.id
		LEFT JOIN `survey_users`
		ON `survey_users`.id = `survey_runs`.user_id

		ORDER BY `survey_cron_log`.id DESC 
		LIMIT $limits;");

		$g_cron->bindParam(':user_id', $this->user->id);
		$g_cron->execute() or die(print_r($g_cron->errorInfo(), true));

		$cronlogs = array();
		while ($cronlog = $g_cron->fetch(PDO::FETCH_ASSOC)) {
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
		$user_count= $this->fdb->prepare("SELECT COUNT(id) AS count FROM `survey_users`");

		$pagination = new Pagination($user_count, 200, true);
		$limits = $pagination->getLimits();

		$g_users = $this->fdb->prepare("SELECT 
			`survey_users`.id,
			`survey_users`.created,
			`survey_users`.modified,
			`survey_users`.email,
			`survey_users`.admin,
			`survey_users`.email_verified

		FROM `survey_users`

		ORDER BY `survey_users`.id ASC 
		LIMIT $limits;");
		$g_users->execute() or die(print_r($g_users->errorInfo(), true));

		$users = array();
		while($userx = $g_users->fetch(PDO::FETCH_ASSOC)) {
			$userx['Email'] = '<a href="'.h($userx['email']).'">'.h($userx['email']).'</a>' . ($userx['email_verified'] ? " <i class='fa fa-check-circle-o'></i>":" <i class='fa fa-envelope-o'></i>");
			$userx['Created'] = "<small class='hastooltip' title='{$userx['created']}'>".timetostr(strtotime($userx['created']))."</small>";
			$userx['Modified'] = "<small class='hastooltip' title='{$userx['modified']}'>".timetostr(strtotime($userx['modified']))."</small>";
			$userx['Admin'] = "
				<form class='form-inline form-ajax' action='".WEBROOT."superadmin/ajax_admin?user_id={$userx['id']}' method='post'>
				<span class='input-group' style='width:160px'>
					<span class='input-group-btn'>
						<button type='submit' class='btn hastooltip'
						title='Give this level to this user'><i class='fa fa-hand-o-right'></i></button>
					</span>
					<input type='number' name='admin_level' max='100' min='-1' value='".h($userx['admin'])."' class='form-control'>
				</span>
			</form>";


			unset($userx['email']);
			unset($userx['created']);
			unset($userx['modified']);
			unset($userx['admin']);
			unset($userx['id']);
			unset($userx['email_verified']);
		#	$user['body'] = "<small title=\"{$user['body']}\">". substr($user['body'],0,50). "…</small>";

			$users[] = $userx;
		}

		$this->renderView('superadmin/user_management', array(
			'users' => $users,
			'pagination' => $pagination,
		));
	}
}	