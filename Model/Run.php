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
	public $cron_active = false;
	private $api_secret_hash = null;
	public $being_serviced = false; // todo: implement service messages
	public $settings = array();
	public $errors = array();
	public $messages = array();
	private $dbh;
	
	public function __construct($fdb, $name, $options = null) 
	{
		$this->dbh = $fdb;
		
		if($name !== null OR ($name = $this->create($options))):
			$this->name = $name;
			$run_data = $this->dbh->prepare("SELECT id,user_id,name,api_secret_hash,public,cron_active,display_service_message FROM `survey_runs` WHERE name = :run_name LIMIT 1");
			$run_data->bindParam(":run_name",$this->name);
			$run_data->execute() or die(print_r($run_data->errorInfo(), true));
			$vars = $run_data->fetch(PDO::FETCH_ASSOC);
			
			if($vars):
				$this->id = $vars['id'];
				$this->user_id = (int)$vars['user_id'];
				$this->api_secret_hash = $vars['api_secret_hash'];
				$this->public = $vars['public'];
				$this->cron_active = $vars['cron_active'];
				$this->being_serviced = $vars['display_service_message'];
			
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
	public function toggleCron($on)
	{
		$on = (int)$on;
		$toggle = $this->dbh->prepare("UPDATE `survey_runs` SET cron_active = :cron_active WHERE id = :id;");
		$toggle->bindParam(':id',$this->id);
		$toggle->bindParam(':cron_active', $on );
		$success = $toggle->execute() or die(print_r($toggle->errorInfo(), true));
		return $success;
	}
	public function togglePublic($on)
	{
		$on = (int)$on;
		$toggle = $this->dbh->prepare("UPDATE `survey_runs` SET public = :public WHERE id = :id;");
		$toggle->bindParam(':id',$this->id);
		$toggle->bindParam(':public', $on );
		$success = $toggle->execute() or die(print_r($toggle->errorInfo(), true));
		return $success;
	}
	public function toggleServiceMessage($on)
	{
		$on = (int)$on;

		if($on) // if it is toggled on, then auto-create if necessary
			$this->getServiceMessageId();
		
		$toggle = $this->dbh->prepare("UPDATE `survey_runs` SET display_service_message = :display_service_message  WHERE id = :id;");
		$toggle->bindParam(':id',$this->id);
		$toggle->bindParam(':display_service_message', $on );
		$success = $toggle->execute() or die(print_r($toggle->errorInfo(), true));
		return $success;
	}
	public function create($options)
	{
	    $name = trim($options['run_name']);
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

		$this->dbh->beginTransaction();
		$create = $this->dbh->prepare("INSERT INTO `survey_runs` (user_id, name, api_secret_hash) VALUES (:user_id, :name, :api_secret_hash);");
		$create->bindParam(':user_id',$options['user_id']);
		$create->bindParam(':name',$name);
		$new_secret = bin2hex(openssl_random_pseudo_bytes(32));
		$create->bindParam(':api_secret_hash',$new_secret);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();

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
					$new_file_name = bin2hex(openssl_random_pseudo_bytes(100)) . $this->file_endings[ $mime ];
				
					if(move_uploaded_file($files['tmp_name'][$i],INCLUDE_ROOT .'webroot/assets/tmp/admin/'.$new_file_name))
					{
						$original_file_name = $files['name'][$i];
						$new_file_path = 'assets/tmp/admin/'.$new_file_name;
						$upload = $this->dbh->prepare("INSERT INTO `survey_uploaded_files` (run_id, created, original_file_name, new_file_path) VALUES (:run_id, NOW(), :original_file_name, :new_file_path)
						ON DUPLICATE KEY UPDATE modified = NOW(),new_file_path = :new_file_path2;");
						$upload->bindParam(':run_id',$this->id);
						$upload->bindParam(':original_file_name',$original_file_name);
						$upload->bindParam(':new_file_path',$new_file_path);
						$upload->bindParam(':new_file_path2',$new_file_path);
						$upload->execute() or die(print_r($upload->errorInfo(), true));
						
						// cleaning up old files afterwards
						if(isset($files_by_names[ $original_file_name ]))
						{
							if( unlink(INCLUDE_ROOT .'webroot/' . $files_by_names[ $original_file_name ]))
							{
								$this->messages[] = __("'%s' was overwritten.<br>",$original_file_name);
							}
						}
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
	
	public function getUnitAdmin($id)
	{
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
		
		if($unit === false) // unit not found in run_units? maybe we're looking for a service message
		{
			$g_unit = $this->dbh->prepare(
			"SELECT 
				`survey_runs`.`service_message` AS unit_id,
				`survey_runs`.id AS run_id,
			
				`survey_units`.id,
				`survey_units`.type,
				`survey_units`.created,
				`survey_units`.modified
			
				 FROM `survey_runs` 
			 
			LEFT JOIN `survey_units`
			ON `survey_units`.id = `survey_runs`.`service_message`
		
			WHERE 
				`survey_runs`.id = :run_id AND
				`survey_runs`.`service_message` = :unit_id
			LIMIT 1
			;");
			$g_unit->bindParam(':run_id',$this->id);
			$g_unit->bindParam(':unit_id',$id);
			
			$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));

			$unit = $g_unit->fetch(PDO::FETCH_ASSOC);
			if($unit["unit_id"])
				return $unit;
			else
				$unit = false;
		}
		
		if($unit === false) // or maybe a reminder email
		{
			$g_unit = $this->dbh->prepare(
			"SELECT 
				`survey_runs`.`reminder_email` AS unit_id,
				`survey_runs`.id AS run_id,
			
				`survey_units`.id,
				`survey_units`.type,
				`survey_units`.created,
				`survey_units`.modified
			
				 FROM `survey_runs` 
			 
			LEFT JOIN `survey_units`
			ON `survey_units`.id = `survey_runs`.`reminder_email`
		
			WHERE 
				`survey_runs`.id = :run_id AND
				`survey_runs`.`reminder_email` = :unit_id
			LIMIT 1
			;");
			$g_unit->bindParam(':run_id',$this->id);
			$g_unit->bindParam(':unit_id',$id);
			
			$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));

			$unit = $g_unit->fetch(PDO::FETCH_ASSOC);
			
			if($unit["unit_id"])
				return $unit;
			else
				$unit = false;
		}
		
		if($unit === false) // or maybe we've got a problem
		{
			alert("Missing unit! $id", 'alert-danger');
			return false;
		}

		
		$unit['run_name'] = $this->name;
		return $unit;
	}
}