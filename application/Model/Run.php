<?php

/**
 * Types of run units
 * 
 * branches 
 * (these evaluate a condition and go to one position in the run, can be used for allowing access)
 * 
 * feedback 
 * (atm just markdown pages with a title and body, but will have to use these for making graphs etc at some point)
 * (END POINTS, does not automatically lead to next run unit in list, but doesn't have to be at the end because of branches)
 * 
 * pauses
 * (go on if it's the next day, a certain date etc., so many days after beginning etc.)
 * 
 * emails
 * (send reminders, invites etc.)
 * 
 * surveys 
 * (main component, upon completion give up steering back to run)
 * 
 * external 
 * (formerly forks, can redirect internally to other runs too)
 * 
 * social network (later)
 * lab date selector (later)
 */
class Run {

	public $id = null;
	public $name = null;
	public $valid = false;
	public $public = false;
	public $cron_active = true;
	public $live = false;
	private $api_secret_hash = null;
	public $being_serviced = false;
	public $locked = false;
	public $errors = array();
	public $messages = array();
	public $custom_css_path = null;
	public $custom_js_path = null;
	public $header_image_path = null;
	public $title = null;
	public $description = null;
	public $osf_project_id = null;
	private $description_parsed = null;
	public $footer_text = null;
	private $footer_text_parsed = null;
	public $public_blurb = null;
	private $public_blurb_parsed = null;
	private $run_settings = array(
		"header_image_path", "title", "description",
		"footer_text", "public_blurb", "custom_css",
		"custom_js", "cron_active", "osf_project_id",
	);

	/**
	 * @var DB
	 */
	private $dbh;

	const TEST_RUN = 'fake_test_run';

	public function __construct($fdb, $name, $options = null) {
		$this->dbh = $fdb;

		if ($name == self::TEST_RUN):
			$this->name = $name;
			$this->valid = true;
			$this->user_id = -1;
			$this->id = -1;
			return true;
		endif;

		if ($name !== null OR ( $name = $this->create($options))):
			$this->name = $name;
			$columns = "id, user_id, name, api_secret_hash ,public,cron_active, locked, header_image_path, title,description, description_parsed, footer_text, footer_text_parsed, public_blurb, public_blurb_parsed, custom_css_path, custom_js_path, osf_project_id";
			$vars = $this->dbh->findRow('survey_runs', array('name' => $this->name), $columns);

			if ($vars):
				$this->id = $vars['id'];
				$this->user_id = (int) $vars['user_id'];
				$this->api_secret_hash = $vars['api_secret_hash'];
				$this->public = $vars['public'];
				$this->cron_active = $vars['cron_active'];
				$this->locked = $vars['locked'];
				$this->header_image_path = $vars['header_image_path'];
				$this->title = $vars['title'];
				$this->description = $vars['description'];
				$this->description_parsed = $vars['description_parsed'];
				$this->footer_text = $vars['footer_text'];
				$this->footer_text_parsed = $vars['footer_text_parsed'];
				$this->public_blurb = $vars['public_blurb'];
				$this->public_blurb_parsed = $vars['public_blurb_parsed'];
				$this->custom_css_path = $vars['custom_css_path'];
				$this->custom_js_path = $vars['custom_js_path'];
				$this->osf_project_id = $vars['osf_project_id'];
				$this->valid = true;
			endif;
		endif;
	}

	public function getCronDues() {
		$sessions = $this->dbh->select('session')
				->from('survey_run_sessions')
				->where(array('run_id' => $this->id))
				->order('RAND')
				->statement();
		$dues = array();
		while ($run_session = $sessions->fetch(PDO::FETCH_ASSOC)) {
			$dues[] = $run_session['session'];
		}
		return $dues;
	}

	/* ADMIN functions */

	public function getApiSecret($user) {
		if ($user->isAdmin()) {
			return $this->api_secret_hash;
		}
		return false;
	}

	public function hasApiAccess($secret) {
		return $this->api_secret_hash === $secret;
	}

	public function rename($new_name) {
		$name = trim($new_name);
		if ($name == ""):
			$this->errors[] = _("You have to specify a run name.");
			return false;
		elseif (!preg_match("/^[a-zA-Z][a-zA-Z0-9_]{2,255}$/", $name)):
			$this->errors[] = _("The run's name has to be between 3 and 20 characters and can't start with a number or contain anything other a-Z_0-9.");
			return false;
		elseif ($this->existsByName($name)):
			$this->errors[] = __("The run's name '%s' is already taken.", h($name));
			return false;
		endif;

		$this->dbh->update('survey_runs', array('name' => $name), array('id' => $this->id));
		return true;
	}

	public function delete() {
		try {
			$this->dbh->delete('survey_runs', array('id' => $this->id));
			alert("<strong>Success.</strong> Successfully deleted run '{$this->name}'.", 'alert-success');
			redirect_to(admin_url());
		} catch (Exception $e) {
			formr_log_exception($e, __CLASS__);
			alert(__('Could not delete run %s. This is probably because there are still run units present. For safety\'s sake you\'ll first need to delete each unit individually.', $this->name), 'alert-danger');
		}
	}

	public function togglePublic($public) {
		if (!in_array($public, range(0, 3))) {
			return false;
		}

		$updated = $this->dbh->update('survey_runs', array('public' => $public), array('id' => $this->id));
		return $updated !== false;
	}

	public function toggleLocked($on) {
		$on = (int) $on;
		$updated = $this->dbh->update('survey_runs', array('locked' => $on), array('id' => $this->id));
		return $updated !== false;
	}

	public function create($options) {
		$name = trim($options['run_name']);
		if ($name == ""):
			$this->errors[] = _("You have to specify a run name.");
			return false;
		elseif (!preg_match("/^[a-zA-Z][a-zA-Z0-9_]{2,255}$/", $name)):
			$this->errors[] = _("The run's name has to be between 3 and 20 characters and can't start with a number or contain anything other a-Z_0-9.");
			return false;
		elseif ($this->existsByName($name) OR $name == self::TEST_RUN OR Router::isWebRootDir($name)):
			$this->errors[] = __("The run's name '%s' is already taken.", h($name));
			return false;
		endif;

		$new_secret = crypto_token(66);
		$this->dbh->insert('survey_runs', array(
			'user_id' => $options['user_id'],
			'name' => $name,
			'title' => $name,
			'api_secret_hash' => $new_secret,
			'cron_active' => 1,
			'public' => 0,
			'footer_text' => "Remember to add your contact info here! Contact the [study administration](mailto:email@example.com) in case of questions.",
			'footer_text_parsed' => "Remember to add your contact info here! Contact the <a href='mailto:email@example.com'>study administration</a> in case of questions.",
		));
		$this->getServiceMessageId();

		return $name;
	}

	public function getUploadedFiles() {
		return $this->dbh->select('id, created, modified, original_file_name, new_file_path')
					->from('survey_uploaded_files')
					->where(array('run_id' => $this->id))
					->order('created', 'asc')
					->fetchAll();
	}

	public $file_endings = array(
		'image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif', 'image/tiff' => '.tif',
		'video/mpeg' => '.mpg', 'video/quicktime' => '.mov', 'video/x-flv' => '.flv', 'video/x-f4v' => '.f4v', 'video/x-msvideo' => '.avi',
		'audio/mpeg' => '.mp3',
		'application/pdf' => '.pdf',
		'text/csv' => '.csv', 'text/css' => '.css', 'text/tab-separated-values' => '.tsv', 'text/plain' => '.txt'
	);

	public function uploadFiles($files) {
		$max_size_upload = Config::get('admin_maximum_size_of_uploaded_files');
		// make lookup array
		$existing_files = $this->getUploadedFiles();
		$files_by_names = array();
		foreach ($existing_files as $existing_file) {
			$files_by_names[$existing_file['original_file_name']] = $existing_file['new_file_path'];
		}

		// loop through files and modify them if necessary
		for ($i = 0; $i < count($files['tmp_name']); $i++):
			if (filesize($files['tmp_name'][$i]) < $max_size_upload * 1048576) {
				$finfo = new finfo(FILEINFO_MIME_TYPE);
				$mime = $finfo->file($files['tmp_name'][$i]);
				if (!in_array($mime, array_keys($this->file_endings))) {
					$this->errors[] = __('The file "%s" has the MIME type %s and is not allowed to be uploaded.', $files['name'][$i], $mime);
				} else {
					$original_file_name = $files['name'][$i];
					if (isset($files_by_names[$original_file_name])) {
						$new_file_path = $files_by_names[$original_file_name];
					} else {
						$new_file_path = 'assets/tmp/admin/' . crypto_token(33, true) . $this->file_endings[$mime];
					}

					if (move_uploaded_file($files['tmp_name'][$i], INCLUDE_ROOT . "webroot/" . $new_file_path)) {
						$this->dbh->insert_update('survey_uploaded_files', array(
							'run_id' => $this->id,
							'created' => mysql_now(),
							'original_file_name' => $original_file_name,
							'new_file_path' => $new_file_path,
						), array(
							'modified' => mysql_now()
						));
					} else {
						$this->errors[] = __("Unable to move uploaded file '%s' to storage location.", $files['name'][$i]);
					}
				}
			} else {
				$this->errors[] = __("The file '%s' is too big the maximum is %d megabytes.", $files['name'][$i], round($max_size_upload, 2));
			}
		endfor;
		return empty($this->errors);
	}

	protected function existsByName($name) {
		return $this->dbh->entry_exists('survey_runs', array('name' => $name));
	}

	public function reorder($positions) {
		$update = "UPDATE `survey_run_units` SET position = :position WHERE run_id = :run_id AND id = :run_unit_id";
		$reorder = $this->dbh->prepare($update);
		$reorder->bindParam(':run_id', $this->id);

		foreach ($positions AS $run_unit_id => $pos):
			$reorder->bindParam(':run_unit_id', $run_unit_id);
			$reorder->bindParam(':position', $pos);
			$reorder->execute();
		endforeach;
		return true;
	}

	public function getAllUnitIds() {
		return $this->dbh->select(array('id' => 'run_unit_id', 'unit_id', 'position'))
					->from('survey_run_units')
					->where(array('run_id' => $this->id))
					->order('position')
					->fetchAll();
	}

	public function getAllUnitTypes() {
		$select = $this->dbh->select(array('survey_run_units.id' => 'run_unit_id', 'unit_id', 'position', 'type', 'description'));
		$select->from('survey_run_units');
		$select->join('survey_units', 'survey_units.id = survey_run_units.unit_id');
		$select->where(array('run_id' => $this->id))->order('position');
		
		return $select->fetchAll();
	}

	public function getOverviewScript() {
		$id = $this->getOverviewScriptId();
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($this->dbh, null, array('type' => "Page", "unit_id" => $id), null, $this);
		return $unit;
	}

	public function getOverviewScriptId() {
		$id = $this->dbh->findValue('survey_runs', array('id' => $this->id), 'overview_script');
		if (!$id) {
			$id = $this->addOverviewScript();
		}
		return $id;
	}

	protected function addOverviewScript() {
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($this->dbh, null, array('type' => "Page"), null, $this);
		$unit->create(array(
			"title" => "Overview script",
			"body" =>
			"# Intersperse Markdown with R
```{r}
plot(cars)
```"
		));

		if ($unit->valid):
			$this->dbh->update('survey_runs', array('overview_script' => $unit->id), array('id' => $this->id));
			alert('An overview script was auto-created.', 'alert-info');
			return $unit->id;
		else:
			alert('<strong>Sorry.</strong> ' . implode($unit->errors), 'alert-danger');
		endif;
	}

	public function getServiceMessage() {
		$id = $this->getServiceMessageId();
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($this->dbh, null, array('type' => "Page", "unit_id" => $id), null, $this);
		return $unit;
	}

	public function getServiceMessageId() {
		$id = $this->dbh->findValue('survey_runs', array('id' => $this->id), 'service_message');
		if (!$id) {
			$id = $this->addServiceMessage();
		}
		return $id;
	}

	protected function addServiceMessage() {
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($this->dbh, null, array('type' => "Page"), null, $this);
		$unit->create(array(
			"title" => "Service message",
			"body" =>
			"# Service message
This study is currently being serviced. Please return at a later time."
		));
		if ($unit->valid):
			$this->dbh->update('survey_runs', array('service_message' => $unit->id), array('id' => $this->id));
			alert('A service message was auto-created.', 'alert-info');
			return $unit->id;
		else:
			alert('<strong>Sorry.</strong> ' . implode($unit->errors), 'alert-danger');
		endif;
	}

	public function getNumberOfSessionsInRun() {
		$g_users = $this->dbh->prepare(
			"SELECT COUNT(`survey_run_sessions`.id) AS sessions, AVG(`survey_run_sessions`.position) AS avg_position
			FROM `survey_run_sessions`
			WHERE `survey_run_sessions`.run_id = :run_id;"
		);
		$g_users->bindParam(':run_id', $this->id);
		$g_users->execute();
		return $g_users->fetch(PDO::FETCH_ASSOC);
	}

	public function getUserCounts() {
		$g_users = $this->dbh->prepare(
			"SELECT COUNT(`id`) AS users_total,
				SUM(`ended` IS NOT NULL) AS users_finished,
				SUM(`ended` IS NULL AND `last_access` >= DATE_SUB(NOW(), INTERVAL 1 DAY) ) 	AS users_active_today,
				SUM(`ended` IS NULL AND `last_access` >= DATE_SUB(NOW(), INTERVAL 7 DAY) ) 	AS users_active,
				SUM(`ended` IS NULL AND `last_access` < DATE_SUB(NOW(), INTERVAL 7 DAY) ) 	AS users_waiting
			FROM `survey_run_sessions`
			WHERE `survey_run_sessions`.run_id = :run_id;");

		$g_users->bindParam(':run_id', $this->id);
		$g_users->execute();
		return $g_users->fetch(PDO::FETCH_ASSOC);
	}

	public function emptySelf() {
		$surveys = $this->getAllSurveys();
		$unit_factory = new RunUnitFactory();
		foreach ($surveys AS $survey) {
			$unit = $unit_factory->make($this->dbh, null, $survey, null, $this);
			if (!$unit->deleteResults($this->id)) {
				alert('Could not delete results of survey ' . $unit->name, 'alert-danger');
				if (!empty($unit->errors)) {
					alert(implode('<br/>', $unit->errors), 'alert-danger');
				}
				return false;
			}
		}
		$rows = $this->dbh->delete('survey_run_sessions', array('run_id' => $this->id));
		alert('Run was emptied. ' . $rows . ' were deleted.', 'alert-info');
		return $rows;
	}

	public function getReminder($session, $run_session_id) {
		$id = $this->getReminderId();
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($this->dbh, $session, array(
			'type' => "Email",
			"unit_id" => $id,
			"run_name" => $this->name,
			"run_id" => $this->id,
			"run_session_id" => $run_session_id
		), null, $this);
		return $unit;
	}

	public function getReminderId() {
		$id = $this->dbh->findValue('survey_runs', array('id' => $this->id), 'reminder_email');
		if (!$id) {
			$id = $this->addReminder();
		}
		return $id;
	}

	protected function addReminder() {
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($this->dbh, null, array('type' => "Email"), null, $this);
		$unit->create(array(
			"subject" => "Reminder",
			"recipient_field" => '',
			"body" =>
			"Please take part in our study at {{login_link}}.",
		));

		if ($unit->valid):
			$this->dbh->update('survey_runs', array('reminder_email' => $unit->id), array('id' => $this->id));
			alert('A reminder email was auto-created.', 'alert-info');
			return $unit->id;
		else:
			alert('<strong>Sorry.</strong> ' . implode($unit->errors), 'alert-danger');
		endif;
	}

	public function getCustomCSS() {
		if ($this->custom_css_path != null) {
			return $this->getFileContent($this->custom_css_path);
		}

		return "";
	}

	public function getCustomJS() {
		if ($this->custom_js_path != null) {
			return $this->getFileContent($this->custom_js_path);
		}

		return "";
	}

	private function getFileContent($path) {
		$path = new SplFileInfo(INCLUDE_ROOT . "webroot/" . $path);
		$exists = file_exists($path->getPathname());
		if ($exists) {
			$file = $path->openFile('c+');
			$data = '';
			$file->next();
			while ($file->valid()) {
				$data .= $file->current();
				$file->next();
			}
			return $data;
		}

		return '';
	}

	public function saveSettings($posted) {
		$parsedown = new ParsedownExtra();
		$parsedown->setBreaksEnabled(true);
		$successes = array();
		if (isset($posted['description'])):
			$posted['description_parsed'] = $parsedown->text($posted['description']);
			$this->run_settings[] = 'description_parsed';
		endif;
		if (isset($posted['public_blurb'])):
			$posted['public_blurb_parsed'] = $parsedown->text($posted['public_blurb']);
			$this->run_settings[] = 'public_blurb_parsed';
		endif;
		if (isset($posted['footer_text'])):
			$posted['footer_text_parsed'] = $parsedown->text($posted['footer_text']);
			$this->run_settings[] = 'footer_text_parsed';
		endif;

		$updates = array();
		foreach ($posted AS $name => $value):
			$value = trim($value);

			if (!in_array($name, $this->run_settings)) {
				$this->errors[] = "Invalid setting " . h($name);
				continue;
			}

			if ($name == "custom_js" || $name == "custom_css"):
				if ($name == "custom_js") {
					$asset_path = $this->custom_js_path;
					$file_ending = '.js';
				} else {
					$asset_path = $this->custom_css_path;
					$file_ending = '.css';
				}

				$name = $name . "_path";
				$asset_file = INCLUDE_ROOT . "webroot/" . $asset_path;
				// Delete old file if css/js was emptied
				if (!$value && $asset_path) {
					if (file_exists($asset_file) && !unlink($asset_file)) {
						alert("Could not delete old file ({$asset_path}).", 'alert-warning');
					}
					$value = null;
				} elseif ($value) {
					// if $asset_path has not been set it means neither JS or CSS has been entered so create a new path
					if (!$asset_path) {
						$asset_path = 'assets/tmp/admin/' . crypto_token(33, true) . $file_ending;
						$asset_file = INCLUDE_ROOT . 'webroot/' . $asset_path;
					}

					$path = new SplFileInfo($asset_file);
					if (file_exists($path->getPathname())):
						$file = $path->openFile('c+');
						$file->rewind();
						$file->ftruncate(0); // truncate any existing file
					else:
						$file = $path->openFile('c+');
					endif;
					$file->fwrite($value);
					$file->fflush();
					$value = $asset_path;
				}
			endif;

			$updates[$name] = $value;
		endforeach;

		if ($updates) {
			$this->dbh->update('survey_runs', $updates, array('id' => $this->id));
		}

		if (!in_array(false, $successes)) {
			return true;
		}

		return false;
	}

	public function getUnitAdmin($id, $special = false) {
		if (!$special):
			$unit = $this->dbh->select('
				`survey_run_units`.id,
				`survey_run_units`.run_id,
				`survey_run_units`.unit_id,
				`survey_run_units`.position,
				`survey_run_units`.description,
				`survey_units`.type,
				`survey_units`.created,
				`survey_units`.modified')
					->from('survey_run_units')
					->leftJoin('survey_units', 'survey_units.id = survey_run_units.unit_id')
					->where('survey_run_units.run_id = :run_id')
					->where('survey_run_units.id = :id')
					->bindParams(array('run_id' => $this->id, 'id' => $id))
					->limit(1)->fetch();
		else:
			if (!in_array($special, array("service_message", "overview_script", "reminder_email"))) {
				die("Special unit not allowed");
			}

			$unit = $this->dbh->select("
				`survey_runs`.`$special` AS unit_id,
				`survey_runs`.id AS run_id,
				`survey_units`.id,
				`survey_units`.type,
				`survey_units`.created,
				`survey_units`.modified")
					->from('survey_runs')
					->leftJoin('survey_units', "survey_units.id = `survey_runs`.`$special`")
					->where('survey_runs.id = :run_id')
					->where("`survey_runs`.`$special` = :unit_id")
					->bindParams(array('run_id' => $this->id, 'unit_id' => $id))
					->limit(1)->fetch();
			$unit["special"] = $special;
		endif;

		if ($unit === false) { // or maybe we've got a problem
			alert("Missing unit! $id", 'alert-danger');
			return false;
		}


		$unit['run_name'] = $this->name;
		return $unit;
	}

	public function getAllSurveys() {
		// first, generate a master list of the search set (all the surveys that are part of the run)
		return $this->dbh->select(array('COALESCE(`survey_studies`.`results_table`,`survey_studies`.`name`)' => 'results_table', 'survey_studies.name', 'survey_studies.id'))
					->from('survey_studies')
					->leftJoin('survey_run_units', 'survey_studies.id = survey_run_units.unit_id')
					->leftJoin('survey_runs', 'survey_runs.id = survey_run_units.run_id')
					->where('survey_runs.id = :run_id')
					->bindParams(array('run_id' => $this->id))
					->fetchAll();
	}

	public function getRandomGroups() {
		$g_users = $this->dbh->prepare("SELECT 
			`survey_run_sessions`.session,
			`survey_unit_sessions`.id AS session_id,
			`survey_runs`.name AS run_name,
			`survey_run_units`.position,
			`survey_units`.type AS unit_type,
			`survey_unit_sessions`.created,
			`survey_unit_sessions`.ended,
			`shuffle`.group
		FROM `survey_unit_sessions`
		LEFT JOIN `shuffle` ON `shuffle`.session_id = `survey_unit_sessions`.id
		LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
		LEFT JOIN `survey_users` ON `survey_users`.id = `survey_run_sessions`.user_id
		LEFT JOIN `survey_units` ON `survey_unit_sessions`.unit_id = `survey_units`.id
		LEFT JOIN `survey_run_units` ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
		LEFT JOIN `survey_runs` ON `survey_runs`.id = `survey_run_units`.run_id
		WHERE `survey_run_sessions`.run_id = :run_id AND `survey_units`.type = 'Shuffle'
		ORDER BY `survey_run_sessions`.id DESC,`survey_unit_sessions`.id ASC;");

		$g_users->bindParam(':run_id', $this->id);
		$g_users->execute();
		return $g_users;
	}

	private function isFakeTestRun() {
		return $this->name === self::TEST_RUN;
	}

	private function fakeTestRun() {
		if ($session = Session::get('dummy_survey_session')):
			$run_session = $this->makeTestRunSession();
			$unit = new Survey($this->dbh, null, $session, $run_session, $this);
			$output = $unit->exec();

			if (!$output):
				$output['title'] = 'Finish';
				$output['body'] = "
					<h1>Finish</h1>
					<p>You're finished with testing this survey.</p>
					<a href='" . admin_study_url($_SESSION['dummy_survey_session']['survey_name']) . "'>Back to the admin control panel.</a>";

				Session::delete('dummy_survey_session');
			endif;
			return compact("output", "run_session");
		else:
			alert("<strong>Error:</strong> Nothing to test-drive.", 'alert-danger');
			redirect_to("/index");
			return false;
		endif;
	}

	public function makeTestRunSession($testing = 1) {
		$animal_name = AnimalName::haikunate(["tokenLength" => 0, "delimiter" => "",]) . "XXX";
		$animal_name = str_replace(" ", "", $animal_name);
		$test_code = crypto_token(48 - floor(3 / 4 * strlen($animal_name)));
		$test_code = $animal_name . substr($test_code, 0, 64 - strlen($animal_name));
		$run_session = new RunSession($this->dbh, $this->id, NULL, $test_code, $this); // does this user have a session?
		$run_session->create($test_code, $testing);

		return $run_session;
	}
	
	
	public function addNamedRunSession($name, $testing = 0) {
		$name = str_replace(" ", "_", $name);
		if ($name && !preg_match('/^[a-zA-Z0-9_-~]{0,32}$/', $name)) {
			alert("Invalid characters in suggested name. Only a-z, numbers, _ - and ~ are allowed. Spaces are automatically replaced by a _.", 'alert-danger');
			return false;
		}

		if ($name) {
			$name .= 'XXX';
		}

		$new_code = crypto_token(48 - floor(3 / 4 * strlen($name)));
		$new_code = $name . substr($new_code, 0, 64 - strlen($name));
		$run_session = new RunSession($this->dbh, $this->id, null, $new_code, $this); // does this user have a session?
		$run_session->create($new_code, $testing);

		return $run_session;
		
	}

	public function exec($user) {
		if (!$this->valid):
			alert(__("<strong>Error:</strong> Run %s is broken or does not exist.", $this->name), 'alert-danger');
			redirect_to("/index");
			return false;
		elseif ($this->name == self::TEST_RUN):
			extract($this->fakeTestRun());
		else:

			$run_session = new RunSession($this->dbh, $this->id, $user->id, $user->user_code, $this); // does this user have a session?

			if (($user->created($this) OR // owner always has access
				$run_session->isTesting()) OR // testers always have access
				($this->public >= 1 AND $run_session->id) OR // already enrolled
				($this->public >= 2)) { // anyone with link can access
				
				if ($run_session->id === null) {
					$run_session->create($user->user_code, (int) $user->created($this));  // generating access code for those who don't have it but need it
				}

				Session::globalRefresh();
				$output = $run_session->getUnit();
			} else {
				$output = $this->getServiceMessage()->exec();
				$run_session = $this->makeTestRunSession(0);
				alert("<strong>Sorry:</strong> You cannot currently access this run.", 'alert-warning');
			}
		endif;

		if (!$output) {
			return;
		}

		global $site, $title, $css, $js;

		if (isset($output['title'])):
			$title = $output['title'];
		else:
			$title = $this->title ? $this->title : $this->name;
		endif;

		if ($this->custom_css_path) {
			$css = '<link rel="stylesheet" href="' . asset_url($this->custom_css_path) . '" type="text/css" media="screen">';
		}
		if ($this->custom_js_path) {
			$js .= '<script src="' . asset_url($this->custom_js_path) . '"></script>';
		}

		$alerts = $site->renderAlerts();

		$run_content = '';

		if ($run_session->isTesting()) {
			$animal_end = strpos($user->user_code, "XXX");
			if ($animal_end === false) {
				$animal_end = 10;
			}

			$js .= '<script src="' . asset_url('assets/' . (DEBUG ? 'js' : 'minified') . '/run_users.js') . '"></script>';
			$run_content .= Template::get('admin/run/monkey_bar', array(
				'user' => $user,
				'run' => $this,
				'run_session' => $run_session,
				'short_code' => substr($user->user_code, 0, $animal_end),
				'icon' => $user->created($this) ? "fa-user-md" : "fa-stethoscope",
				'disable_class' => $this->isFakeTestRun() ? " disabled " : "",
			));
		}

		if (trim($this->description_parsed)) {
			$run_content .= $this->description_parsed;
		}

		if (isset($output['body'])) {
			$run_content .= $output['body'];
		}
		if (trim($this->footer_text_parsed)) {
			$run_content .= $this->footer_text_parsed;
		}

		return array(
			'title' => $title,
			'css' => $css,
			'js' => $js,
			'alerts' => $alerts,
			'run_session' => $run_session,
			'run_content' => $run_content,
			'run' => $this,
		);
	}

	/**
	 * Export RUN units
	 *
	 * @param string $name The name that will be assigned to export
	 * @param array $units
	 * @param boolean $inc_survey Should survey data be included in export?
	 * @return mixed Returns an array of its two inputs.
	 */
	public function export($name, array $units, $inc_survey) {
		$SPR = new SpreadsheetReader();
		// Save run units
		foreach ($units as $i => &$unit) {
			if ($inc_survey && $unit->type === 'Survey') {
				$survey = Survey::loadById($unit->unit_id);
				$unit->survey_data = $SPR->exportItemTableJSON($survey, true);
			}
			unset($unit->unit_id, $unit->run_unit_id);
		}
		// Save run settings
		$settings = array(
			'header_image_path' => $this->header_image_path,
			'description' => $this->description,
			'footer_text' => $this->footer_text,
			'public_blurb' => $this->public_blurb,
			'cron_active' => (int) $this->cron_active,
			'custom_js' => $this->getCustomJS(),
			'custom_css' => $this->getCustomCSS(),
		);

		// save run files
		$files = array();
		$uploads = $this->getUploadedFiles();
		foreach ($uploads as $file) {
			$files[] = site_url('file_download/' . $this->id . '/' . $file['original_file_name']);
		}

		$export = array(
			'name' => $name,
			'units' => array_values($units),
			'settings' => $settings,
			'files' => $files,
		);
		return $export;
	}

	/**
	 * Import a set of run units into current run by parsing a valid json string.
	 * Existing exported run units are read from configured dir $settings[run_exports_dir]
	 * Foreach unit item there is a check for at least for 'type' and 'position' attributes
	 *
	 * @param string $json_string JSON string of run units
	 * @param int $start_position Start position to be assigned to units. Defaults to 1.
	 * @return array Returns an array on rendered units indexed by position
	 */
	public function importUnits($json_string, $start_position = 0) {
		ini_set('memory_limit', '256M');
		if (!$start_position) {
			$start_position = 0;
		} else {
			$start_position = (int) $start_position - 10;
		}
		$json = json_decode($json_string);
		$existingUnits = $this->getAllUnitIds();
		if ($existingUnits) {
			$last = end($existingUnits);
			$start_position = $last['position'] + 10;
		}

		if (empty($json->units)) {
			alert("<strong>Error</strong> Invalid json string provided.", 'alert-danger');
			return false;
		}

		$units = (array) $json->units;
		$createdUnits = array();
		$runFactory = new RunUnitFactory();

		foreach ($units as $unit) {
			if (isset($unit->position) && !empty($unit->type)) {
				$unit->position = $start_position + $unit->position;
				// for some reason Endpage replaces Page
				if (strpos($unit->type, 'page') !== false) {
					$unit->type = 'Page';
				}

				if (strpos($unit->type, 'Survey') !== false) {
					$unit->mock = true;
				}

				if (strpos($unit->type, 'Skip') !== false) {
					$unit->if_true = $unit->if_true + $start_position;
				}

				if (strpos($unit->type, 'Email') !== false) {
					$unit->account_id = null;
				}

				$unitObj = $runFactory->make($this->dbh, null, (array) $unit, null, $this);
				$unit = (array) $unit;
				$unitObj->create($unit);
				if ($unitObj->valid) {
					$unitObj->addToRun($this->id, $unitObj->position, $unit);
					// @todo check how to manage this because they are echoed only on next page load
					//alert('<strong>Success.</strong> '.ucfirst($unitObj->type).' unit was created.','alert-success');
					$createdUnits[$unitObj->position] = $unitObj->displayForRun(Site::getInstance()->renderAlerts());
				}
			}
		}

		// try importing settings
		if (!empty($json->settings)) {
			$this->saveSettings((array) $json->settings);
		}
		return $createdUnits;
	}

}
