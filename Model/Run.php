<?php
require_once INCLUDE_ROOT . "Model/DB.php";
/*
## types of run units
	* branches 
		(these evaluate a condition and go to one position in the run, can be used for allowing access)
	* feedback 
		(atm just markdown pages with a title and body, but will have to use these for making graphs etc at some point)
		(END POINTS, does not automatically lead to next run unit in list, but doesn't have to be at the end because of branches)
	* pauses
		(go on if it's the next day, a certain date etc., so many days after beginning etc.)
	* emails
		(send reminders, invites etc.)
	* surveys 
		(main component, upon completion give up steering back to run)
	* external 
		(formerly forks, can redirect internally to other runs too)
	* social network (later)
	* lab date selector (later)
*/
class Run
{
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
	private $run_settings = array("header_image_path", "title", "description", "footer_text", "public_blurb", "custom_css", "custom_js","cron_active");
	private $dbh;
	public $custom_css_path = null;
	public $custom_js_path = null;
	public $header_image_path = null;
	public $title = null;
	public $description = null;
	private $description_parsed = null;
	public $footer_text = null;
	private $footer_text_parsed = null;
	public $public_blurb = null;
	private $public_blurb_parsed = null;
	
	
	
	public function __construct($fdb, $name, $options = null) 
	{
		$this->dbh = $fdb;
		
		if($name == "fake_test_run"):
			$this->name = $name;
			$this->valid = true;
			$this->user_id = 0;
			
			return true;
		endif;
		
		if($name !== null OR ($name = $this->create($options))):
			$this->name = $name;
			
			$run_data = $this->dbh->prepare("SELECT id,user_id,name,api_secret_hash,public,cron_active,locked, header_image_path,title,description,description_parsed,footer_text,footer_text_parsed,public_blurb,public_blurb_parsed,custom_css_path,custom_js_path FROM `survey_runs` WHERE name = :run_name LIMIT 1");
			$run_data->bindParam(":run_name",$this->name);
			$run_data->execute() or die(print_r($run_data->errorInfo(), true));
			$vars = $run_data->fetch(PDO::FETCH_ASSOC);

			if($vars):
				$this->id = $vars['id'];
				$this->user_id = (int)$vars['user_id'];
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
			
				$this->valid = true;
			endif;
		endif;
	}
	public function getCronDues()
	{
		$g_unit = $this->dbh->prepare(
		"SELECT 
			`survey_run_sessions`.session
		
			 FROM `survey_run_sessions`

		WHERE 
			`survey_run_sessions`.run_id = :run_id
		ORDER BY RAND() 
		;"); // in the order they were added
		$g_unit->bindParam(':run_id',$this->id);
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$dues = array();
		while($run_session = $g_unit->fetch(PDO::FETCH_ASSOC))
			$dues[] = $run_session['session'];
		return $dues;
	}

	/* ADMIN functions */
	
	public function getApiSecret($user)
	{
		if($user->isAdmin())
			return $this->api_secret_hash;
		return false;
	}
	public function hasApiAccess($secret)
	{
		return $this->api_secret_hash === $secret;
	}
	public function rename($new_name)
	{
	    $name = trim($new_name);
	    if($name == ""):
			$this->errors[] = _("You have to specify a run name.");
			return false;
		elseif(!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,255}/",$name)):
			$this->errors[] = _("The run's name has to be between 3 and 20 characters and can't start with a number or contain anything other a-Z_0-9.");
			return false;
		elseif($this->existsByName($name)):
			$this->errors[] = __("The run's name '%s' is already taken.",h($name));
			return false;
		endif;

		$this->dbh->beginTransaction() or die(print_r($this->dbh->errorInfo(), true));
		$rename_run = $this->dbh->prepare("UPDATE `survey_runs` SET `name` = :new_name WHERE id = :run_id") or die(print_r($this->dbh->errorInfo(), true));
		$rename_run->bindParam(':run_id',$this->id);
		$rename_run->bindParam(':new_name',$name);
		$rename_run->execute() or die(print_r($rename_run->errorInfo(), true));
		$this->dbh->commit();
		
		return true;
	}
	public function delete()
	{
		try {
			$this->dbh->beginTransaction() or die(print_r($this->dbh->errorInfo(), true));
			$delete_run = $this->dbh->prepare("DELETE FROM `survey_runs` WHERE id = :run_id") or die(print_r($this->dbh->errorInfo(), true)); // Cascades
			$delete_run->bindParam(':run_id',$this->id);
			$delete_run->execute() or die(print_r($delete_run->errorInfo(), true));
		
			$this->dbh->commit();
			alert("<strong>Success.</strong> Successfully deleted run '{$this->name}'.",'alert-success');
			redirect_to(WEBROOT."admin/index");
		}
		catch (Exception $e)
		{
			alert(__('Could not delete run %s. This is probably because there are still run units present. For safety\'s sake you\'ll first need to delete each unit individually.', $this->name), 'alert-danger');
		}
	}

	public function togglePublic($public)
	{
		if(!in_array($public,range(0,3))) die("not possible");
		$toggle = $this->dbh->prepare("UPDATE `survey_runs` SET public = :public WHERE id = :id;");
		$toggle->bindParam(':id',$this->id);
		$toggle->bindParam(':public', $public );
		$success = $toggle->execute() or die(print_r($toggle->errorInfo(), true));
		return $success;
	}
	public function toggleLocked($on)
	{
		$on = (int)$on;
		$toggle = $this->dbh->prepare("UPDATE `survey_runs` SET locked = :locked WHERE id = :id;");
		$toggle->bindParam(':id',$this->id);
		$toggle->bindParam(':locked', $on );
		$success = $toggle->execute() or die(print_r($toggle->errorInfo(), true));
		return $success;
	}

	public function create($options)
	{
	    $name = trim($options['run_name']);
	    if($name == "" ):
			$this->errors[] = _("You have to specify a run name.");
			return false;
		elseif(!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,255}/",$name) ):
			$this->errors[] = _("The run's name has to be between 3 and 20 characters and can't start with a number or contain anything other a-Z_0-9.");
			return false;
		elseif($this->existsByName($name) OR $name == "fake_test_run"):
			$this->errors[] = __("The run's name '%s' is already taken.",h($name));
			return false;
		endif;

		$this->dbh->beginTransaction();
		$create = $this->dbh->prepare("INSERT INTO `survey_runs` (user_id, name, title, api_secret_hash, cron_active, public) VALUES (:user_id, :name, :title, :api_secret_hash, 1, 0);");
		$create->bindParam(':user_id',$options['user_id']);
		$create->bindParam(':name',$name);
		$create->bindParam(':title',$name);
		$new_secret = bin2hex(openssl_random_pseudo_bytes(32));
		$create->bindParam(':api_secret_hash',$new_secret);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();
		
		$this->getServiceMessageId();

		return $name;
	}
	public function getUploadedFiles()
	{
		$get_files = $this->dbh->prepare("SELECT 
			`survey_uploaded_files`.id,
			`survey_uploaded_files`.created,
			`survey_uploaded_files`.modified,
			`survey_uploaded_files`.original_file_name,
			`survey_uploaded_files`.new_file_path
			
			 FROM `survey_uploaded_files` 
		WHERE 
			`survey_uploaded_files`.run_id = :run_id
			
		ORDER BY `survey_uploaded_files`.created ASC
		;");
		$get_files->bindParam(':run_id',$this->id);
		$get_files->execute() or die(print_r($get_files->errorInfo(), true));
		$files = array();
		while($file = $get_files->fetch(PDO::FETCH_ASSOC))
			$files[] = $file;
		
		return $files;
	}
	public $file_endings = array(
		'image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif', 'image/tiff' => '.tif',
		'video/mpeg' => '.mpg', 'video/quicktime' => '.mov', 'video/x-flv' => '.flv', 'video/x-f4v' => '.f4v', 'video/x-msvideo' => '.avi',
		'audio/mpeg' => '.mp3',
		'application/pdf' => '.pdf',
		'text/csv' => '.csv', 'text/css' =>  '.css', 'text/tab-separated-values' => '.tsv', 'text/plain' => '.txt'
	);
	public function uploadFiles($files)
	{
		global $settings;
		// make lookup array
		$existing_files = $this->getUploadedFiles();
		$files_by_names = array();
		foreach($existing_files AS $existing_file):
			$files_by_names[$existing_file['original_file_name']] = $existing_file['new_file_path'];
		endforeach;
		
		
		// loop through files and modify them if necessary
		for($i = 0; $i < count($files['tmp_name']); $i++):
			if(filesize($files['tmp_name'][$i]) < $settings['admin_maximum_size_of_uploaded_files'] * 1048576)
			{
			    $finfo = new finfo(FILEINFO_MIME_TYPE);
				$mime = $finfo->file($files['tmp_name'][$i]);
				if(!in_array($mime, array_keys($this->file_endings )))
				{
				    $this->errors[] = __('The file "%s" has the MIME type %s and is not allowed to be uploaded.', $files['name'][$i], $mime);
				}
				else
				{
					$original_file_name = $files['name'][$i];
					if(isset($files_by_names[ $original_file_name ]))
						$new_file_path = $files_by_names[ $original_file_name ];
					else
						$new_file_path = 'assets/tmp/admin/'. bin2hex(openssl_random_pseudo_bytes(100)) . $this->file_endings[ $mime ];
				
					if($err = move_uploaded_file($files['tmp_name'][$i],INCLUDE_ROOT ."webroot/".$new_file_path))
					{
						$upload = $this->dbh->prepare("INSERT INTO `survey_uploaded_files` (run_id, created, original_file_name, new_file_path) VALUES (:run_id, NOW(), :original_file_name, :new_file_path)
						ON DUPLICATE KEY UPDATE modified = NOW();");
						$upload->bindParam(':run_id',$this->id);
						$upload->bindParam(':original_file_name',$original_file_name);
						$upload->bindParam(':new_file_path',$new_file_path);
						$upload->execute() or die(print_r($upload->errorInfo(), true));
						
						// cleaning up old files afterwards
						// not necessary anymore
/*						if(isset($files_by_names[ $original_file_name ]))
						{
							if( unlink(INCLUDE_ROOT .'webroot/' . $files_by_names[ $original_file_name ]))
							{
								$this->messages[] = __("'%s' was overwritten.<br>",$original_file_name);
							}
						}
*/					}
					else{
						$this->errors[] = $err;
					}
				}
			}
			else
			{
				$this->errors[] = __("The file '%s' is too big the maximum is %d megabytes.",$files['name'][$i],round($settings['admin_maximum_size_of_uploaded_files'], 2) );
			}
		endfor;
		return empty($this->errors);
	}

	protected function existsByName($name)
	{
		$exists = $this->dbh->prepare("SELECT name FROM `survey_runs` WHERE name = :name LIMIT 1");
		$exists->bindParam(':name',$name);
		$exists->execute() or die(print_r($create->errorInfo(), true));
		if($exists->rowCount())
			return true;
		
		return false;
	}
	public function reorder($positions)
	{
		$reorder = $this->dbh->prepare("UPDATE `survey_run_units` SET
			position = :position WHERE 
			run_id = :run_id AND
			id = :run_unit_id");
		$reorder->bindParam(':run_id',$this->id);
		foreach($positions AS $run_unit_id => $pos):
			$reorder->bindParam(':run_unit_id',$run_unit_id);
			$reorder->bindParam(':position',$pos);
			$reorder->execute() or die(print_r($reorder->errorInfo(), true));
		endforeach;
		return true;
	}
	public function getAllUnitIds()
	{
		$g_unit = $this->dbh->prepare(
		"SELECT 
			`survey_run_units`.id AS run_unit_id,
			`survey_run_units`.unit_id,
			`survey_run_units`.position
			
			 FROM `survey_run_units` 
		WHERE 
			`survey_run_units`.run_id = :run_id
			
		ORDER BY `survey_run_units`.position ASC
		;");
		$g_unit->bindParam(':run_id',$this->id);
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$units = array();
		while($unit = $g_unit->fetch(PDO::FETCH_ASSOC))
			$units[] = $unit;
		
		return $units;
	}
	public function getOverviewScript()
	{
		$id = $this->getOverviewScriptId();
		require_once INCLUDE_ROOT."Model/RunUnit.php";
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($this->dbh,null,array('type' => "Page", "unit_id" => $id));
		return $unit;
	}
	public function getOverviewScriptId()
	{
		$g_unit = $this->dbh->prepare(
		"SELECT `survey_runs`.overview_script
			
			 FROM `survey_runs` 
		WHERE 
			`survey_runs`.id = :run_id;");
		$g_unit->bindParam(':run_id',$this->id);
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$overview_script = $g_unit->fetch(PDO::FETCH_ASSOC);
		$id = $overview_script['overview_script'];
		if($id ==  NULL)
		{
			$id = $this->addOverviewScript();
		}
		return $id;
	}
	protected function addOverviewScript()
	{
		require_once INCLUDE_ROOT."Model/RunUnit.php";
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($this->dbh,null,array('type' => "Page"));
		$unit->create(array(
			"title" => "Overview script",
			"body" =>
"# Intersperse Markdown with R
```{r}
plot(cars)
```"));
		if($unit->valid):
			$add_overview_script = $this->dbh->prepare(
			"UPDATE `survey_runs`
				SET overview_script = :overview_script
			WHERE 
				`survey_runs`.id = :run_id;");
			$add_overview_script->bindParam(':run_id',$this->id);
			$add_overview_script->bindParam(':overview_script',$unit->id);
			$add_overview_script->execute() or die(print_r($add_overview_script->errorInfo(), true));
			alert('An overview script was auto-created.','alert-info');
			return $unit->id;
		else:
			alert('<strong>Sorry.</strong> '.implode($unit->errors),'alert-danger');
		endif;
		
	}
	
	public function getServiceMessage()
	{
		$id = $this->getServiceMessageId();
		require_once INCLUDE_ROOT."Model/RunUnit.php";
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($this->dbh,null,array('type' => "Page", "unit_id" => $id));
		return $unit;
	}
	public function getServiceMessageId()
	{
		$g_unit = $this->dbh->prepare(
		"SELECT `survey_runs`.service_message
			
			 FROM `survey_runs` 
		WHERE 
			`survey_runs`.id = :run_id;");
		$g_unit->bindParam(':run_id',$this->id);
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$service_message = $g_unit->fetch(PDO::FETCH_ASSOC);
		$id = $service_message['service_message'];
		if($id ==  NULL)
		{
			$id = $this->addServiceMessage();
		}
		return $id;
	}
	protected function addServiceMessage()
	{
		require_once INCLUDE_ROOT."Model/RunUnit.php";
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($this->dbh,null,array('type' => "Page"));
		$unit->create(array(
			"title" => "Service message",
			"body" =>
"# Service message
This study is currently being serviced. Please return at a later time."));
		if($unit->valid):
			$add_service_message = $this->dbh->prepare(
			"UPDATE `survey_runs`
				SET service_message = :service_message
			WHERE 
				`survey_runs`.id = :run_id;");
			$add_service_message->bindParam(':run_id',$this->id);
			$add_service_message->bindParam(':service_message',$unit->id);
			$add_service_message->execute() or die(print_r($add_service_message->errorInfo(), true));
			alert('A service message was auto-created.','alert-info');
			return $unit->id;
		else:
			alert('<strong>Sorry.</strong> '.implode($unit->errors),'alert-danger');
		endif;
		
	}

	public function getNumberOfSessionsInRun()
	{
		$g_users = $this->dbh->prepare("SELECT 
			COUNT(`survey_run_sessions`.id) AS sessions,
			AVG(`survey_run_sessions`.position) AS avg_position
	
		FROM `survey_run_sessions`

		WHERE `survey_run_sessions`.run_id = :run_id;");
		$g_users->bindParam(':run_id',$this->id);
		$g_users->execute();
		return $g_users->fetch(PDO::FETCH_ASSOC);
	}
	public function getUserCounts()
	{
		$g_users = $this->dbh->prepare("SELECT 
			COUNT(`id`) 							AS users_total,
			SUM(`ended` IS NOT NULL) 					AS users_finished,
			SUM(`ended` IS NULL AND `last_access` >= DATE_SUB(NOW(), INTERVAL 1 DAY) ) 	AS users_active_today,
			SUM(`ended` IS NULL AND `last_access` >= DATE_SUB(NOW(), INTERVAL 7 DAY) ) 	AS users_active,
			SUM(`ended` IS NULL AND `last_access` < DATE_SUB(NOW(), INTERVAL 7 DAY) ) 	AS users_waiting
			
		FROM `survey_run_sessions`

		WHERE `survey_run_sessions`.run_id = :run_id;");
		$g_users->bindParam(':run_id',$this->id);
		$g_users->execute();
		return $g_users->fetch(PDO::FETCH_ASSOC);
	}
	public function emptySelf()
	{
		$empty_run = $this->dbh->prepare("DELETE FROM 
			`survey_run_sessions`
		WHERE `survey_run_sessions`.run_id = :run_id;");
		$empty_run->bindParam(':run_id',$this->id);
		$empty_run->execute();
		$rows = $empty_run->rowCount();
		alert('Run was emptied. '.$rows.' were deleted.','alert-info');
		
		return $rows;
	}
	public function getReminder($session,$run_session_id)
	{
		$id = $this->getReminderId();
		require_once INCLUDE_ROOT."Model/RunUnit.php";
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($this->dbh, $session ,array(
			'type' => "Email", 
			"unit_id" => $id, 
			"run_name" => $this->name,
			"run_id" => $this->id,
			"run_session_id" => $run_session_id)
		);
		return $unit;
	}
	public function getReminderId()
	{
		$g_unit = $this->dbh->prepare(
		"SELECT `survey_runs`.reminder_email
			
			 FROM `survey_runs` 
		WHERE 
			`survey_runs`.id = :run_id;");
		$g_unit->bindParam(':run_id',$this->id);
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$reminder_email = $g_unit->fetch(PDO::FETCH_ASSOC);
		$id = $reminder_email['reminder_email'];
		if($id ==  NULL)
		{
			$id = $this->addReminder();
		}
		return $id;
	}
	protected function addReminder()
	{
		require_once INCLUDE_ROOT."Model/RunUnit.php";
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($this->dbh,null,array('type' => "Email"));
		$unit->create(array(
			"subject" => "Reminder",
			"recipient_field" => 'survey_users$email',
			"body" =>
"Please take part in our study at {{login_link}}."));
		if($unit->valid):
			$add_reminder_email = $this->dbh->prepare(
			"UPDATE `survey_runs`
				SET reminder_email = :reminder_email
			WHERE 
				`survey_runs`.id = :run_id;");
			$add_reminder_email->bindParam(':run_id',$this->id);
			$add_reminder_email->bindParam(':reminder_email',$unit->id);
			$add_reminder_email->execute() or die(print_r($add_reminder_email->errorInfo(), true));
			alert('A reminder email was auto-created.','alert-info');
			return $unit->id;
		else:
			alert('<strong>Sorry.</strong> '.implode($unit->errors),'alert-danger');
		endif;
		
	}
	public function getCustomCSS()
	{
		
		if($this->custom_css_path != null)
			return $this->getFileContent($this->custom_css_path);
		else
			return "";
	}
	public function getCustomJS()
	{
		if($this->custom_js_path != null)
			return $this->getFileContent($this->custom_js_path);
		else
			return "";
	}
	private function getFileContent($path)
	{
		$path = new SplFileInfo(INCLUDE_ROOT . "webroot/". $path);
		$exists = file_exists($path->getPathname());
		if($exists):
			$file = $path->openFile('c+');
			$data = '';
			$file->next();
			while ($file->valid()) {
				$data .= $file->current();
				$file->next();
			}
			return $data;

//			$data = trim($data);
		endif;
		return '';
	}
	public function saveSettings($posted)
	{
		$parsedown = new ParsedownExtra();
		$parsedown->setBreaksEnabled(true);
		$successes = array();
		if(isset($posted['description'])):
			$posted['description_parsed'] = $parsedown->text($posted['description']);
			$this->run_settings[] = 'description_parsed';
		endif;
		if(isset($posted['public_blurb'])):
			$posted['public_blurb_parsed'] = $parsedown->text($posted['public_blurb']);
			$this->run_settings[] = 'public_blurb_parsed';
		endif;
		if(isset($posted['footer_text'])):
			$posted['footer_text_parsed'] = $parsedown->text($posted['footer_text']);
			$this->run_settings[] = 'footer_text_parsed';
		endif;
		
		foreach($posted AS $name => $value):
			if(in_array($name, $this->run_settings)):
				
				if($name == "custom_js" OR $name == "custom_css"):
					if($name == "custom_js"):
						$old_path = $this->custom_js_path;
						$file_ending = '.js';
					else:
						$old_path = $this->custom_css_path;
						$file_ending = '.css';
					endif;
					if($value == null AND $old_path != null):
						$path = new SplFileInfo(INCLUDE_ROOT . "webroot/". $old_path);
						$exists = file_exists($path->getPathname());
						if($exists):
							// remove any existing file
							try {
								unlink(INCLUDE_ROOT . "webroot/". $old_path);
							} catch (Exception $e) {
								alert("Could not delete old file.", 'alert-warning');
							}
						endif;
					else:
						if($old_path == null)
							$old_path = 'assets/tmp/admin/'. bin2hex(openssl_random_pseudo_bytes(100)).$file_ending;
						$path = new SplFileInfo(INCLUDE_ROOT . "webroot/". $old_path);
						$exists = file_exists($path->getPathname());
						if($exists):
							$file = $path->openFile('c+');
							$file->rewind();
							$file->ftruncate(0);
							// truncate any existing file
						else:
							$file = $path->openFile('c+');
						endif;
						$file->fwrite($value);
						$file->fflush();
						
						$value = $old_path;
					endif;
					$name = $name . "_path";
				endif;
				
				$save_setting = $this->dbh->prepare(
				"UPDATE `survey_runs`
					SET `$name` = :$name
				WHERE 
					`survey_runs`.id = :run_id;");
				$save_setting->bindParam(':run_id',$this->id);
				$save_setting->bindParam(":$name",$value);
				$success = $save_setting->execute() or die(print_r($save_setting->errorInfo(), true));
			else:
				$run->errors[] = "Invalid setting " . h($name);
			endif;
		endforeach;
		if(! in_array(false, $successes))
			return true;
		return false;
	}
	public function getUnitAdmin($id, $special = false)
	{
		if(!$special):
			$g_unit = $this->dbh->prepare(
			"SELECT 
				`survey_run_units`.id,
				`survey_run_units`.run_id,
				`survey_run_units`.unit_id,
				`survey_run_units`.position,
			
				`survey_units`.type,
				`survey_units`.created,
				`survey_units`.modified
			
				 FROM `survey_run_units` 
			 
			LEFT JOIN `survey_units`
			ON `survey_units`.id = `survey_run_units`.unit_id
		
			WHERE 
				`survey_run_units`.run_id = :run_id AND
				`survey_run_units`.id = :id
			LIMIT 1
			;");
			$g_unit->bindParam(':id',$id);
			$g_unit->bindParam(':run_id',$this->id);
		
			$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));

			$unit = $g_unit->fetch(PDO::FETCH_ASSOC);
		else:
			if(!in_array($special, array("service_message","overview_script","reminder_email"))) die("Special unit not allowed");
			$g_unit = $this->dbh->prepare(
			"SELECT 
				`survey_runs`.`$special` AS unit_id,
				`survey_runs`.id AS run_id,
			
				`survey_units`.id,
				`survey_units`.type,
				`survey_units`.created,
				`survey_units`.modified
			
				 FROM `survey_runs` 
			 
			LEFT JOIN `survey_units`
			ON `survey_units`.id = `survey_runs`.`$special`
		
			WHERE 
				`survey_runs`.id = :run_id AND
				`survey_runs`.`$special` = :unit_id
			LIMIT 1
			;");
			$g_unit->bindParam(':run_id',$this->id);
			$g_unit->bindParam(':unit_id',$id);
			
			$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));

			$unit = $g_unit->fetch(PDO::FETCH_ASSOC);
			$unit["special"] = $special;
		endif;
				
		if($unit === false) // or maybe we've got a problem
		{
			alert("Missing unit! $id", 'alert-danger');
			return false;
		}

		
		$unit['run_name'] = $this->name;
		return $unit;
	}
	public function getRandomGroups()
	{
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

		LEFT JOIN `shuffle`
		ON `shuffle`.session_id = `survey_unit_sessions`.id
		LEFT JOIN `survey_run_sessions`
		ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
		LEFT JOIN `survey_users`
		ON `survey_users`.id = `survey_run_sessions`.user_id
		LEFT JOIN `survey_units`
		ON `survey_unit_sessions`.unit_id = `survey_units`.id
		LEFT JOIN `survey_run_units`
		ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
		LEFT JOIN `survey_runs`
		ON `survey_runs`.id = `survey_run_units`.run_id
		WHERE `survey_run_sessions`.run_id = :run_id AND
		`survey_units`.type = 'Shuffle'
		ORDER BY `survey_run_sessions`.id DESC,`survey_unit_sessions`.id ASC;");
		$g_users->bindParam(':run_id',$this->id);
		$g_users->execute();
		return $g_users;
	}
	private function fakeTestRun()
	{
		require_once INCLUDE_ROOT . "Model/Survey.php";
	
		if(isset($_SESSION['dummy_survey_session'])):
			$run_session = $this->makeDummyRunSession("fake_test_run","Survey");
			$unit = new Survey($this->dbh, null, $_SESSION['dummy_survey_session'], $run_session);
			$output = $unit->exec();

			if(!$output):
				$output['title'] = 'Finish';
				$output['body'] = "
					<h1>Finish</h1>
					<p>
					You're finished with testing this survey.</p><a href='".WEBROOT."admin/survey/".$_SESSION['dummy_survey_session']['survey_name']."/index'>Back to the admin control panel.</a>";
			
				unset($_SESSION['dummy_survey_session']);
			endif;
			return compact("output","run_session");
		else:
			alert("<strong>Error:</strong> Nothing to test-drive.",'alert-danger');
			redirect_to("/index");
			return false;
		endif;
	}
	private function makeDummyRunSession($position, $current_unit_type)
	{
		$run_session = (object) "dummy";
		$run_session->position = $position;
		$run_session->current_unit_type = $current_unit_type;
		$run_session->run_owner_id = $this->user_id;
		$run_session->user_id = $this->user_id;
		return $run_session;
	}
	public function exec($user)
	{
		if(!$this->valid):
			alert(__("<strong>Error:</strong> Run %s is broken or does not exist.",$this->name),'alert-danger');
			redirect_to("/index");
			return false;
		elseif($this->name == "fake_test_run"):
			extract($this->fakeTestRun());
		else:
			if($user->loggedIn() AND isset($_SESSION['UnitSession']) AND $user->user_code !== unserialize($_SESSION['UnitSession'])->session):
				alert('<strong>Error.</strong> You seem to have switched sessions.','alert-danger');
				redirect_to('index');
			endif;
			
			require_once INCLUDE_ROOT . 'Model/RunSession.php';
			$run_session = new RunSession($this->dbh, $this->id, $user->id, $user->user_code); // does this user have a session?
			
			if(
				$user->created($this) OR // owner always has access
				($this->public >= 1 AND $run_session->id) OR // already enrolled
				($this->public >= 2) // anyone with link can access
			):
				if( $run_session->id===NULL ):
					$run_session->create($user->user_code);  // generating access code for those who don't have it but need it
				endif;
				
				$output = $run_session->getUnit();
			else:
				$output = $this->getServiceMessage()->exec();
				$run_session = $this->makeDummyRunSession("service_message","Page");
				alert("<strong>Sorry:</strong> You cannot currently access this run.",'alert-warning');
			endif;
		endif;
		
		
		if($output):
			global $site, $title, $css, $js;
			
			if(isset($output['title'])):
				$title = $output['title'];
			else:
				$title = $this->title? $this->title : $this->name;
			endif;
	
			if($this->custom_css_path)
				$css = '<link rel="stylesheet" href="'.WEBROOT.$this->custom_css_path.'" type="text/css" media="screen">';
			if($this->custom_js_path)
				$js .= '<script src="'.WEBROOT.$this->custom_js_path.'"></script>';
	
			require_once INCLUDE_ROOT . 'View/header.php';


			?>
		<div class="row">
			<div class="col-lg-12 run_position_<?=$run_session->position?> run_unit_type_<?=$run_session->current_unit_type?> run_content">
				<header class="run_content_header"><?=$this->header_image_path? '<img src="'.$this->header_image_path.'" alt="'.$this->name.' header image">':''; ?></header>
		<?php
			$alerts = $site->renderAlerts();
			session_over($site, $user);
			
			if(!empty($alerts)):
				echo '
					<div class="row">
						<div class="col-md-6 col-sm-6 all-alerts">';
							echo $alerts;
					echo '</div>
					</div>';
			endif;
			if(trim($this->description_parsed)):
				echo $this->description_parsed;
			endif;
		
			if(isset($output['body']))
				echo $output['body'];

			if(trim($this->footer_text_parsed)):
				echo $this->footer_text_parsed;
			endif;
		
			require_once INCLUDE_ROOT . 'View/footer.php';
		endif;
	}
}