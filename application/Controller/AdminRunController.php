<?php

class AdminRunController extends AdminController {

	public function __construct(Site &$site) {
		parent::__construct($site);
	}

	public function indexAction($run_name = '', $private_action = '') {
		$this->setRun($run_name);
		if ($private_action) {
			if (empty($this->run) || !$this->run->valid) {
				throw new Exception("You cannot access this page with no valid run");
			}

			if (strpos($private_action, 'ajax') !== false) {
				return AdminAjaxController::call($private_action, $this);
			}

			$privateAction = $this->getPrivateAction($private_action);
			return $this->$privateAction();
		}

		if (empty($this->run)) {
			redirect_to('admin/run/add_run');
		}

		$vars = array(
			'show_panic' => $this->showPanicButton(),
		);
		$this->renderView('run/index', $vars);
	}

	public function listAction() {
		$vars = array(
			'runs' => $this->user->getRuns('id DESC', null),
		);
		$this->renderView('run/list', $vars);
	}

	public function addRunAction() {
		if ($this->request->isHTTPPostRequest()) {
			$run_name = $this->request->str('run_name');
			if (!$run_name) {
				$error = 'You have to specify a run name';
			} elseif (!preg_match("/^[a-zA-Z][a-zA-Z0-9-]{2,255}$/", $run_name)) {
				$error = 'The run name can contain <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the hyphen(-) (at least 2 characters, at most 255). It needs to start with a letter.';
			} elseif ($run_name == Run::TEST_RUN || Router::isWebRootDir($run_name) || in_array($run_name, Config::get('reserved_run_names', array())) || Run::nameExists($run_name)) {
				$error = __('The run name "%s" is already taken. Please choose another name', $run_name);
			} else {
				$run = new Run($this->fdb, null);
				$run->create(array('run_name' => $run_name, 'user_id' => $this->user->id));
				if ($run->valid) {
					alert("<strong>Success.</strong> Run '{$run->name}' was created.", 'alert-success');
					redirect_to(admin_run_url($run->name));
				} else {
					$error = 'An error creating your run please try again';
				}
			}

			if (!empty($error)) {
				alert("<strong>Error:</strong> {$error}", 'alert-danger');
			}
		}

		$this->renderView('run/add_run');
	}

	private function userOverviewAction() {
		$run = $this->run;
		$fdb = $this->fdb;

		$search = '';
		$querystring = array();
		$position_cmp = '=';
		$query_params = array(':run_id' => $run->id);

		if ($this->request->position_lt && in_array($this->request->position_lt, array('=', '>', '<'))) {
			$position_cmp = $this->request->position_lt;
			$querystring['position_lt'] = $position_cmp;
		}

		if ($this->request->session) {
			$session = str_replace("…", "", $this->request->session);
			$search .= 'AND `survey_run_sessions`.session LIKE :session ';
			$query_params[':session'] = "%" . $session . "%";
			$querystring['session'] = $session;
		}

		if ($this->request->position) {
			$position = $this->request->position;
			$search .= "AND `survey_run_sessions`.position {$position_cmp} :position ";
			$query_params[':position'] = $position;
			$querystring['position'] = $position;
		}

		if ($this->request->sessions) {
			$sessions = array();
			foreach (explode("\n", $this->request->sessions) as $session) {
				$session = $this->fdb->quote($session);
				if($session) {
					$sessions[] = $session;
				}
			}
			$search .= " AND session IN (" . implode($sessions, ",") . ")";
			$querystring['sessions'] = $this->request->sessions;
		}

		$user_count_query = "SELECT COUNT(`survey_run_sessions`.id) AS count FROM `survey_run_sessions` WHERE `survey_run_sessions`.run_id = :run_id $search;";
		$user_count = $fdb->execute($user_count_query, $query_params, true);
		$pagination = new Pagination($user_count, 200, true);
		$limits = $pagination->getLimits();

		$query_params[':admin_code'] = $this->user->user_code;

		$users_query = "SELECT 
			`survey_run_sessions`.id AS run_session_id,
			`survey_run_sessions`.session,
			`survey_run_sessions`.position,
			`survey_run_units`.description,
			`survey_run_sessions`.last_access,
			`survey_run_sessions`.created,
			`survey_run_sessions`.testing,
			`survey_runs`.name AS run_name,
			`survey_units`.type AS unit_type,
			`survey_run_sessions`.last_access,
			(`survey_units`.type IN ('Survey','External','Email') AND DATEDIFF(NOW(), `survey_run_sessions`.last_access) >= 2) AS hang
		FROM `survey_run_sessions`
		LEFT JOIN `survey_runs` ON `survey_run_sessions`.run_id = `survey_runs`.id
		LEFT JOIN `survey_run_units` ON `survey_run_sessions`.position = `survey_run_units`.position AND `survey_run_units`.run_id = `survey_run_sessions`.run_id
		LEFT JOIN `survey_units` ON `survey_run_units`.unit_id = `survey_units`.id
		WHERE `survey_run_sessions`.run_id = :run_id $search
		ORDER BY `survey_run_sessions`.session != :admin_code, hang DESC, `survey_run_sessions`.last_access DESC
		LIMIT $limits;";

		$vars = get_defined_vars();
		$vars['users'] = $fdb->execute($users_query, $query_params);
		$vars['position_lt'] = $position_cmp;
		$vars['currentUser'] = $this->user;
		$vars['unit_types'] = $run->getAllUnitTypes();
		$vars['reminders'] = $this->run->getSpecialUnits(false, 'ReminderEmail');
		$this->renderView('run/user_overview', $vars);
	}

	private function exportUserOverviewAction() {
		$users_query = "SELECT 
		        `survey_run_sessions`.position,
		        `survey_units`.type AS unit_type,
			`survey_run_units`.description,
		        `survey_run_sessions`.session,
		        `survey_run_sessions`.created,
		        `survey_run_sessions`.last_access,
		        (`survey_units`.type IN ('Survey','External','Email') AND DATEDIFF(NOW(), `survey_run_sessions`.last_access) >= 2) AS hang
		FROM `survey_run_sessions`
		LEFT JOIN `survey_runs` ON `survey_run_sessions`.run_id = `survey_runs`.id
		LEFT JOIN `survey_run_units` ON `survey_run_sessions`.position = `survey_run_units`.position AND `survey_run_units`.run_id = `survey_run_sessions`.run_id
		LEFT JOIN `survey_units` ON `survey_run_units`.unit_id = `survey_units`.id
		WHERE `survey_run_sessions`.run_id = :run_id ORDER BY `survey_run_sessions`.session != :admin_code, hang DESC, `survey_run_sessions`.last_access DESC";

		$query_params = array(':run_id' => $this->run->id, ':admin_code' => $this->user->user_code);
		$query_obj = $this->fdb->prepare($users_query);
		$query_obj->execute($query_params);

		$SPR = new SpreadsheetReader();
		$download_successfull = $SPR->exportInRequestedFormat($query_obj , $this->run->name . '_user_overview', $this->request->str('format'));
                if (!$download_successfull) {
			alert('An error occured during user overview download.', 'alert-danger');
			redirect_to(admin_run_url($this->run->name, 'user_overview'));
                }
	}

	private function exportUserDetailAction() {
		$users_query = "SELECT
			        `survey_run_units`.position,
			        `survey_units`.type AS unit_type,
			        `survey_run_units`.description,
			        `survey_run_sessions`.session,
			        `survey_unit_sessions`.created AS entered,
			        IF (`survey_unit_sessions`.ended > 0, UNIX_TIMESTAMP(`survey_unit_sessions`.ended)-UNIX_TIMESTAMP(`survey_unit_sessions`.created),
						UNIX_TIMESTAMP(NOW())-UNIX_TIMESTAMP(`survey_unit_sessions`.created)) AS 'seconds_stayed',
					`survey_unit_sessions`.ended AS 'left',
			        `survey_unit_sessions`.expired
				FROM `survey_unit_sessions`
				LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
				LEFT JOIN `survey_units` ON `survey_unit_sessions`.unit_id = `survey_units`.id
				LEFT JOIN `survey_run_units` ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
				LEFT JOIN `survey_runs` ON `survey_runs`.id = `survey_run_units`.run_id
				WHERE `survey_runs`.id = :run_id
				AND `survey_run_sessions`.run_id = :run_id2
				ORDER BY `survey_run_sessions`.id DESC,`survey_unit_sessions`.id ASC;";

		$query_params = array(':run_id' => $this->run->id, ':run_id2' => $this->run->id);
		$query_obj = $this->fdb->prepare($users_query);
		$query_obj->execute($query_params);

		$SPR = new SpreadsheetReader();
		$download_successfull = $SPR->exportInRequestedFormat($query_obj , $this->run->name . '_user_detail', $this->request->str('format'));
                if (!$download_successfull) {
			alert('An error occured during user detail download.', 'alert-danger');
			redirect_to(admin_run_url($this->run->name, 'user_detail'));
                }
	}
	
	private function createNewTestCodeAction() {
		$run_session = $this->run->makeTestRunSession();
		$sess = $run_session->session;
		$animal = substr($sess, 0,strpos($sess, "XXX"));
		$sess_url = run_url($this->run->name, null, array('code' => $sess));

		//alert("You've created a new guinea pig, ".h($animal).". Use this guinea pig to move through the run like a normal user with special powers (accessibly via the monkey bar at the bottom right). As a guinea pig, you can see more detailed error messages than real users, so it is easier to e.g. debug R problems. If you want someone else to be the guinea pig, just forward them this link: <br><textarea readonly cols='60' rows='3' class='copy_clipboard readonly-textarea'>" . h($sess_url) . "</textarea>", "alert-info");
		redirect_to($sess_url);
	}

	private function createNewNamedSessionAction() {

		if(Request::isHTTPPostRequest()) {
			$code_name = $this->request->getParam('code_name');
			$run_session = $this->run->addNamedRunSession($code_name);

			if($run_session) {
				$sess = $run_session->session;
				$sess_url = run_url($this->run->name, null, array('code' => $sess));

				//alert("You've added a user with the code name '{$code_name}'. <br /> Send them this link to participate <br /> <textarea readonly cols='60' rows='3' class='copy_clipboard readonly-textarea'>" . h($sess_url) . "</textarea>", "alert-info");
				redirect_to(admin_run_url($this->run->name, 'user_overview', array('session' => $sess)));
			}
		}

		$this->renderView('run/create_new_named_session');
		
	}

	private function userDetailAction() {
		$run = $this->run;
		$fdb = $this->fdb;

		$search = '';
		$querystring = array();
		$position_lt = '=';
		if ($this->request->session) {
			$session = str_replace('…', '', $this->request->session);
			$search .= 'AND `survey_run_sessions`.session LIKE :session ';
			$search_session = "%" . $session . "%";
			$querystring['session'] = $session;
		}
		
		if ($this->request->position) {
			$position = $this->request->position;
			if (in_array($this->request->position_lt, array('>', '=', '<'))) {
				$position_lt = $this->request->position_lt;
			}
			$search .= 'AND `survey_run_units`.position '.$position_lt.' :position ';
			$search_position = $position;
			$querystring['position_lt'] = $position_lt;
			$querystring['position'] = $position;
		}

		$user_count_query = "
			SELECT COUNT(`survey_unit_sessions`.id) AS count FROM `survey_unit_sessions` 
			LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
			LEFT JOIN `survey_run_units` ON `survey_unit_sessions`.`unit_id` = `survey_run_units`.`unit_id`
			WHERE `survey_run_sessions`.run_id = :run_id $search";

		$params = array(':run_id' => $run->id);
		if (isset($search_session)) {
			$params[':session'] = $search_session;
		}
		if (isset($search_position)) {
			$params[':position'] = $search_position;
		}

		$user_count = $fdb->execute($user_count_query, $params, true);
		$pagination = new Pagination($user_count, 400, true);
		$limits = $pagination->getLimits();


		$params[':run_id2'] = $params[':run_id'];

		$users_query = "SELECT 
			`survey_run_sessions`.session,
			`survey_unit_sessions`.id AS session_id,
			`survey_runs`.name AS run_name,
			`survey_run_units`.position,
			`survey_run_units`.description,
			`survey_units`.type AS unit_type,
			`survey_unit_sessions`.created,
			`survey_unit_sessions`.ended,
			`survey_unit_sessions`.expired
		FROM `survey_unit_sessions`
		LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
		LEFT JOIN `survey_units` ON `survey_unit_sessions`.unit_id = `survey_units`.id
		LEFT JOIN `survey_run_units` ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
		LEFT JOIN `survey_runs` ON `survey_runs`.id = `survey_run_units`.run_id
		WHERE `survey_runs`.id = :run_id2 AND `survey_run_sessions`.run_id = :run_id $search
		ORDER BY `survey_run_sessions`.id DESC,`survey_unit_sessions`.id ASC LIMIT $limits";

		$users = $fdb->execute($users_query, $params);

		foreach ($users as $i => $userx) {
			if ($userx['expired']) {
				$stay_seconds = strtotime($userx['expired']) - strtotime($userx['created']);
			} else {
				$stay_seconds = ($userx['ended'] ? strtotime($userx['ended']) : time() ) - strtotime($userx['created']);
			}
			$userx['stay_seconds'] = $stay_seconds;
			if($userx['expired']) {
				$userx['ended'] = $userx['expired'] . ' (expired)';
			}
			
			if($userx['unit_type'] != 'Survey') {
				$userx['delete_msg'] = "Are you sure you want to delete this unit session?";
				$userx['delete_title'] = "Delete this waypoint";
			} else {
				$userx['delete_msg'] = "You SHOULDN'T delete survey sessions, you might delete data! <br />Are you REALLY sure you want to continue?";
				$userx['delete_title'] = "Survey unit sessions should not be deleted";
			}

			$users[$i] = $userx;
		}

		$vars = get_defined_vars();
		$this->renderView('run/user_detail', $vars);
	}

	private function uploadFilesAction() {
		$run = $this->run;

		if (!empty($_FILES['uploaded_files'])) {
			if($run->uploadFiles($_FILES['uploaded_files'])) {
				alert('<strong>Success.</strong> The files were uploaded.','alert-success');
				if(!empty($run->messages)) {
					alert(implode($run->messages, ' '), 'alert-info');
				}
				redirect_to(admin_run_url($run->name, 'upload_files'));
			} else {
				alert('<strong>Sorry, files could not be uploaded.</strong><br /> ' . nl2br(implode($run->errors, "\n")),'alert-danger');
			}
		} elseif ($this->request->isHTTPPostRequest()) {
			alert('The size of your request exceeds the allowed limit. Please report this to administrators indicating the size of your files.', 'alert-danger');
		}

		$this->renderView('run/upload_files', array('files' => $run->getUploadedFiles()));
	}

	private function deleteFileAction() {
		$id = $this->request->int('id');
		$filename = $this->request->str('file');
		$deleted = $this->run->deleteFile($id, $filename);
		if ($deleted) {
			alert('File Deleted', 'alert-success');
		} else {
			alert('Unable to delete selected file', 'alert-danger');
		}
		redirect_to(admin_run_url($this->run->name, 'upload_files'));
	}

	private function settingsAction() {
		$osf_projects = array();

		if (($token = OSF::getUserAccessToken($this->user))) {
			$osf = new OSF(Config::get('osf'));
			$osf->setAccessToken($token);
			$response = $osf->getProjects();

			if ($response->hasError()) {
				alert($response->getError(), 'alert-danger');
				$token = null;
			} else {
				foreach ($response->getJSON()->data as $project) {
					$osf_projects[] = array('id' => $project->id, 'name' => $project->attributes->title);
				}
			}
		}

		$this->renderView('run/settings', array(
			'osf_token' => $token,
			'run_selected'=> $this->request->getParam('run'),
			'osf_projects' => $osf_projects,
			'osf_project' => $this->run->osf_project_id,
			'run_id' => $this->run->id,
			'reminders' => $this->run->getSpecialUnits(true, 'ReminderEmail'),
			'service_messages' => $this->run->getSpecialUnits(true, 'ServiceMessagePage'),
			'overview_scripts' => $this->run->getSpecialUnits(true, 'OverviewScriptPage'),
		));
	}

	private function renameRunAction() {
		$run = $this->run;
		if($this->request->isHTTPPostRequest()) {
			$run_name = $this->request->str('new_name');
			if (!$run_name) {
				$error = 'You have to specify a new run name';
			} elseif (!preg_match("/^[a-zA-Z][a-zA-Z0-9-]{2,255}$/", $run_name)) {
				$error = 'The run name can contain <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the hyphen(-) (at least 2 characters, at most 255). It needs to start with a letter.';
			} elseif ($run_name == Run::TEST_RUN || Router::isWebRootDir($run_name) || in_array($run_name, Config::get('reserved_run_names', array())) || Run::nameExists($run_name)) {
				$error = __('The run name "%s" is already taken. Please choose another name', $run_name);
			} else {
				if ($run->rename($run_name)) {
					alert("<strong>Success.</strong> Run was renamed to '{$run_name}'.", 'alert-success');
					redirect_to(admin_run_url($run_name));
				} else {
					$error = 'An error renaming your run please try again';
				}
			}

			if (!empty($error)) {
				alert("<strong>Error:</strong> {$error}", 'alert-danger');
			}
		}

		$this->renderView('run/rename_run');
	}

	private function exportDataAction() {
		$run = $this->run;
		$format = $this->request->str('format');
		$SPR = new SpreadsheetReader();
		SpreadsheetReader::verifyExportFormat($format);

		/* @var $resultsStmt PDOStatement */
		$resultsStmt = $run->getData(true);
		if (!$resultsStmt->columnCount()) {
			alert('No linked data yet', 'alert-info');
			redirect_to(admin_run_url($run->name));
		}

		$filename = $run->name . '_data';
		switch ($format) {
			case 'xlsx':
				$downloaded = $SPR->exportXLSX($resultsStmt, $filename);
			break;
			case 'xls':
				$downloaded = $SPR->exportXLS($resultsStmt, $filename);
			break;
			case 'csv_german':
				$downloaded = $SPR->exportCSV_german($resultsStmt, $filename);
			break;
			case 'tsv':
				$downloaded = $SPR->exportTSV($resultsStmt, $filename);
			break;
			case 'json':
				$downloaded = $SPR->exportJSON($resultsStmt, $filename);
			break;
			default:
				$downloaded = $SPR->exportCSV($resultsStmt, $filename);
			break;
		}

		if (!$downloaded) {
			alert('An error occured during results download.', 'alert-danger');
			redirect_to(admin_run_url($run->name));
		}
	}

	private function exportSurveyResultsAction() {
		$studies = $this->run->getAllSurveys();
		$dir = APPLICATION_ROOT . 'tmp/backups/results';
		if (!$dir) {
			alert('Unable to create run backup directory', 'alert-danger');
			redirect_to(admin_run_url($this->run->name));
		}

		// create study result files
		$SPR = new SpreadsheetReader();
		$errors = $files = $metadata = array();
		$metadata['run'] = array(
			'ID' => $this->run->id,
			'NAME' => $this->run->name,
		);

		foreach ($studies as $study) {
			$survey = Survey::loadById($study['id']);
			$backupFile = $dir . '/' . $this->run->name . '-' . $survey->name . '.tab';
			$backup = $SPR->exportTSV($survey->getResults(null, null, null, $this->run->id, true), $survey->name, $backupFile);
			if (!$backup) {
				$errors[] = "Unable to backup {$survey->name}";
			} else {
				$files[] = $backupFile;
			}
			$metadata['survey:'.$survey->id] = array(
				'ID' => $survey->id,
				'NAME' => $survey->name,
				'RUN_ID' => $this->run->id
			);
		}

		$metafile = $dir . '/' . $this->run->name . '.metadata';
		if (create_ini_file($metadata, $metafile)) {
			$files[] = $metafile;
		}

		// zip files and send to 
		if ($files) {
			$zipfile = $dir . '/' . $this->run->name . '-' . date('d-m-Y') . '.zip';
			
			//create the archive
			if (!create_zip_archive($files, $zipfile)) {
				alert('Unable to create zip archive: ' . basename($zipfile), 'alert-danger');
				redirect_to(admin_run_url($this->run->name));
			}

			$filename = basename($zipfile);
			header("Content-Type: application/zip");
			header("Content-Disposition: attachment; filename=$filename");
			header("Content-Length: " . filesize($zipfile));
			readfile($zipfile);
			// attempt to cleanup files after download
			$files[] = $zipfile;
			deletefiles($files);
			exit;
		} else {
			alert('No files to zip and download', 'alert-danger');
		}
		redirect_to(admin_run_url($this->run->name));
	}

	private function randomGroupsExportAction() {
		$run = $this->run;
		$format = $this->request->str('format');
		$SPR = new SpreadsheetReader();
		SpreadsheetReader::verifyExportFormat($format);

		/* @var $resultsStmt PDOStatement */
		$resultsStmt = $run->getRandomGroups(); //@TODO unset run_name, unit_type, ended, position
		if (!$resultsStmt->columnCount()) {
			alert('No linked data yet', 'alert-info');
			redirect_to(admin_run_url($run->name));
		}

		$filename = "Shuffle_Run_" . $run->name;
		switch ($format) {
			case 'xlsx':
				$downloaded = $SPR->exportXLSX($resultsStmt, $filename);
			break;
			case 'xls':
				$downloaded = $SPR->exportXLS($resultsStmt, $filename);
			break;
			case 'csv_german':
				$downloaded = $SPR->exportCSV_german($resultsStmt, $filename);
			break;
			case 'tsv':
				$downloaded = $SPR->exportTSV($resultsStmt, $filename);
			break;
			case 'json':
				$downloaded = $SPR->exportJSON($resultsStmt, $filename);
			break;
			default:
				$downloaded = $SPR->exportCSV($resultsStmt, $filename);
			break;
		}

		if (!$downloaded) {
			alert('An error occured during results download.', 'alert-danger');
			redirect_to(admin_run_url($run->name));
		}
	}

	private function randomGroupsAction() {
		$run = $this->run;
		$g_users = $run->getRandomGroups();

		$users = array();
		while($userx = $g_users->fetch(PDO::FETCH_ASSOC)) {
			$userx['Unit in Run'] = $userx['unit_type']. " <span class='hastooltip' title='position in run {$userx['run_name']} '>({$userx['position']})</span>";
		#	$userx['Email'] = "<small title=\"{$userx['session']}\">{$userx['email']}</small>";
			$userx['Group'] = "<big title=\"Assigned group\">{$userx['group']}</small>";
			$userx['Created'] = "<small>{$userx['created']}</small>";

			unset($userx['run_name']);
			unset($userx['unit_type']);
			unset($userx['created']);
			unset($userx['ended']);
			unset($userx['position']);
			unset($userx['email']);
			unset($userx['group']);
		#	$user['body'] = "<small title=\"{$user['body']}\">". substr($user['body'],0,50). "…</small>";

			$users[] = $userx;
		}
		$this->renderView('run/random_groups', array('users' => $users));
	}

	private function overviewAction() {
		$run = $this->run;

		$this->renderView('run/overview', array(
			'users' => $run->getNumberOfSessionsInRun(),
			'overview_script' => $run->getOverviewScript(),
			'user_overview' => $run->getUserCounts(),
		));
	}

	private function emptyRunAction() {
		$run = $this->run;
		if ($this->request->isHTTPPostRequest()) {
			if ($this->request->getParam('empty_confirm') === $run->name) {
				$run->emptySelf();
				redirect_to(admin_run_url($run->name, "empty_run"));
			} else {
				alert("<b>Error:</b> You must type the run's name '{$run->name}' to empty it.", 'alert-danger');
			}
		}
		
		$this->renderView('run/empty_run', array(
			'users' => $run->getNumberOfSessionsInRun(),
		));
		
	}

	private function emailLogAction() {
		$run = $this->run;
		$fdb = $this->fdb;

		$email_count_query = "SELECT COUNT(`survey_email_log`.id) AS count
		FROM `survey_email_log`
		LEFT JOIN `survey_unit_sessions` ON `survey_unit_sessions`.id = `survey_email_log`.session_id 
		LEFT JOIN `survey_run_sessions` ON `survey_unit_sessions`.run_session_id = `survey_run_sessions`.id
		WHERE `survey_run_sessions`.run_id = :run_id";

		$email_count = $fdb->execute($email_count_query, array(':run_id' => $run->id), true);
		$pagination = new Pagination($email_count, 50, true);
		$limits = $pagination->getLimits();

		$emails_query = "SELECT 
			`survey_email_accounts`.from_name, 
			`survey_email_accounts`.`from`, 
			`survey_email_log`.recipient AS `to`,
			`survey_email_log`.`sent`,
			`survey_emails`.subject,
			`survey_emails`.body,
			`survey_email_log`.created,
			`survey_run_units`.position AS position_in_run
		FROM `survey_email_log`
		LEFT JOIN `survey_emails` ON `survey_email_log`.email_id = `survey_emails`.id
		LEFT JOIN `survey_run_units` ON `survey_emails`.id = `survey_run_units`.unit_id
		LEFT JOIN `survey_email_accounts` ON `survey_emails`.account_id = `survey_email_accounts`.id
		LEFT JOIN `survey_unit_sessions` ON `survey_unit_sessions`.id = `survey_email_log`.session_id
		LEFT JOIN `survey_run_sessions` ON `survey_unit_sessions`.run_session_id = `survey_run_sessions`.id
		WHERE `survey_run_sessions`.run_id = :run_id
		ORDER BY `survey_email_log`.id DESC LIMIT $limits ;";

		$g_emails = $fdb->execute($emails_query, array(':run_id' => $run->id));
		$emails = array();
		foreach ($g_emails as $email) {
			$email['from'] = "{$email['from_name']}<br><small>{$email['from']}</small>";
			unset($email['from_name']);
			$email['to'] = $email['to']."<br><small>at run position ".$email['position_in_run']."</small>";
			$email['mail'] = $email['subject']."<br><small>". h(substr($email['body'], 0, 100)). "…</small>";
			$email['datetime'] = '<abbr title="'.$email['created'].'">'.timetostr(strtotime($email['created'])).'</abbr> ';
			$email['datetime'] .= $email['sent'] ? '<i class="fa fa-check-circle"></i>' : '<i class="fa fa-times-circle"></i>';
			unset($email['position_in_run'], $email['subject'], $email['body'], $email['created'], $email['sent']);
			$emails[] = $email;
		}

		$vars = get_defined_vars();
		$this->renderView('run/email_log', $vars);
	}

	private function deleteRunAction() {
		$run = $this->run;
		if(isset($_POST['delete']) AND trim($_POST['delete_confirm']) === $run->name) {
			$run->delete();
		} elseif(isset($_POST['delete'])) {
			alert("<b>Error:</b> You must type the run's name '{$run->name}' to delete it.",'alert-danger');
		}

		$this->renderView('run/delete_run', array(
			'users' => $run->getNumberOfSessionsInRun(),
		));
	}

	private function cronLogParsed() {
		$parser = new LogParser();
		$parse = $this->run->name . '.log';
		$vars = get_defined_vars();
		$this->renderView('run/cron_log_parsed', $vars);
	}

	private function cronLogAction() {
		return $this->cronLogParsed();
		// @todo: deprecate code
		$run = $this->run;
		$fdb = $this->fdb;

		$fdb->count('survey_cron_log', array('run_id' => $run->id));
		$cron_entries_count = $fdb->count('survey_cron_log', array('run_id' => $run->id));

		$pagination = new Pagination($cron_entries_count);
		$limits = $pagination->getLimits();

		$cron_query = "SELECT 
			`survey_cron_log`.id,
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
			`survey_cron_log`.message
		FROM `survey_cron_log`
		WHERE `survey_cron_log`.run_id = :run_id
		ORDER BY `survey_cron_log`.id DESC LIMIT $limits;";

		$g_cron = $fdb->execute($cron_query, array(':run_id' => $run->id));

		$cronlogs = array();
		foreach ($g_cron as $cronlog) {
			$cronlog = array_reverse($cronlog, true); 
			$cronlog['Modules'] = '<small>';

			if($cronlog['pauses']>0)
				$cronlog['Modules'] .= $cronlog['pauses'].' <i class="fa fa-pause"></i> ';
			if($cronlog['skipbackwards']>0)
				$cronlog['Modules'] .= 	$cronlog['skipbackwards'].' <i class="fa fa-backward"></i> ';
			if($cronlog['skipforwards']>0)
				$cronlog['Modules'] .= 	$cronlog['skipforwards'].' <i class="fa fa-forward"></i> ';
			if($cronlog['emails']>0)
				$cronlog['Modules'] .= 	$cronlog['emails'].' <i class="fa fa-envelope"></i> ';
			if($cronlog['shuffles']>0)
				$cronlog['Modules'] .= 	$cronlog['shuffles'].' <i class="fa fa-random"></i>';
			$cronlog['Modules'] .=	'</small>';
			$cronlog['took'] = '<small>'.round($cronlog['time_in_seconds']/60, 2). 'm</small>';
			$cronlog['time'] = '<small title="'.$cronlog['created'].'">'.timetostr(strtotime($cronlog['created'])). '</small>';
			$cronlog = array_reverse($cronlog, true);
			unset($cronlog['created']);
			unset($cronlog['time_in_seconds']);
			unset($cronlog['skipforwards']);
			unset($cronlog['skipbackwards']);
			unset($cronlog['pauses']);
			unset($cronlog['emails']);
			unset($cronlog['shuffles']);
			unset($cronlog['run_id']);
			unset($cronlog['id']);

			$cronlogs[] = $cronlog;
		}

		$vars = get_defined_vars();
		$this->renderView('run/cron_log', $vars);
	}
	
	private function setRun($name) {
		if (!$name) {
			return;
		}

		$run = new Run($this->fdb, $name);
		if (!$run->valid) {
			formr_error(404, 'Not Found', 'Requested Run does not exist or has been moved');
		} elseif (!$this->user->created($run)) {
			formr_error(401, 'Unauthorized', 'You do not have access to modify this run');
		}
		$this->run = $run;
	}

	private function exportAction() {
		$formats = array('json');
		$run = $this->run;
		$site = $this->site;

		if (($units = (array)json_decode($site->request->str('units'))) && ($name = $site->request->str('export_name')) && preg_match('/^[a-z0-9-\s]+$/i', $name)) {
			$format = $this->request->getParam('format');
			$inc_survey = $this->request->getParam('include_survey_details') === 'true';
			if (!in_array($format, $formats)) {
				alert('Invalid Export format selected', 'alert-danger');
				redirect_to(admin_run_url($run->name));
			}

			if (!($export = $run->export($name, $units, $inc_survey))) {
				bad_request_header();
				echo $site->renderAlerts();
			} else {
				$SPR = new SpreadsheetReader();
				$SPR->exportJSON($export, $name);
			}
		} else {
			alert('Run Export: Missing run units or invalid run name enterd.', 'alert-danger');
			redirect_to(admin_run_url($run->name));
		}
	}

	private function importAction() {
		if ($run_file = $this->request->getParam('run_file_name')) {
			$file = Config::get('run_exports_dir') . '/' .  $run_file;
		} elseif (!empty($_FILES['run_file'])) {
			$file = $_FILES['run_file']['tmp_name'];
		}

		if (empty($file)) {
			alert('Please select a run file or upload one', 'alert-danger');
			return redirect_to(admin_run_url($this->run->name));
		}

		if (!file_exists($file)) {
			alert('The corresponding import file could not be found or is not readable', 'alert-danger');
			return redirect_to(admin_run_url($this->run->name));
		}

		$json_string = file_get_contents($file);
		if (!$json_string) {
			alert('Unable to extract JSON object from file', 'alert-danger');
			return redirect_to(admin_run_url($this->run->name));
		}

		$start_position = 10;
		if ($this->run->importUnits($json_string, $start_position)) {
			alert('Run modules imported successfully!', 'alert-success');
		}

		redirect_to(admin_run_url($this->run->name));
	}

	private function createRunUnitAction() {
		$redirect = $this->request->redirect ? admin_run_url($this->run->name, $this->request->redirect) : admin_run_url($this->run->name);
		$unit = $this->createRunUnit();
		if ($unit->valid) {
			$unit->addToRun($this->run->id, $unit->position);
			alert('Run unit created', 'alert-success');
		} else {
			alert('An unexpected error occured. Unit could not be created', 'alert-danger');
		}
		redirect_to(str_replace(':::', '#', $redirect));
	}

	private function deleteRunUnitAction() {
		$id = (int)$this->request->unit_id;
		if (!$id) {
			throw new Exception('Missing Parameter');
		}
		$redirect = $this->request->redirect ? admin_run_url($this->run->name, $this->request->redirect) : admin_run_url($this->run->name);
		$unit = $this->createRunUnit($id);
		if ($unit->valid) {
			$unit->run_unit_id = $id;
			$unit->removeFromRun($this->request->special);
			alert('Run unit deleted', 'alert-success');
		} else {
			alert('An unexpected error occured. Unit could not be deleted', 'alert-danger');
		}
		redirect_to(str_replace(':::', '#', $redirect));
	}

	private function panicAction() {
		$settings = array(
			'locked' => 1,
			'cron_active' => 0,
			'public' => 0,
			//@todo maybe do more
		);
		$updated = $this->fdb->update('survey_runs', $settings, array('id' => $this->run->id));
		if ($updated) {
			$msg = array("Panic mode activated for '{$this->run->name}'");
			$msg[] = " - Only you can access this run";
			$msg[] = " - The cron job for this run has been deactivated";
			$msg[] = " - The run has been 'locked' for editing";
			alert(implode("\n", $msg), 'alert-success');
		}
		redirect_to("admin/run/{$this->run->name}");
	}

	private function showPanicButton() {
		$on = $this->run->locked === 1 &&
				$this->run->cron_active === 0 &&
				$this->run->public === 0;
		return !$on;
	}
}
