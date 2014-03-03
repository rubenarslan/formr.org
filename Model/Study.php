<?php
require_once INCLUDE_ROOT . "Model/DB.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";
require_once INCLUDE_ROOT."Model/Item.php";
require_once INCLUDE_ROOT . "vendor/erusev/parsedown/Parsedown.php";

// this is actually just the admin side of the survey thing, but because they have different DB layers, it may make sense to keep thems separated
class Study extends RunUnit
{
	public $id = null;
	public $name = null;
	public $valid = false;
	public $public = false;
	public $settings = array();
	public $errors = array();
	public $messages = array();
	public $warnings = array();
	public $position;
	private $SPR;
	public $icon = "fa-pencil-square-o";
	
	public function __construct($fdb, $session = NULL,$unit = NULL) 
	{
		parent::__construct($fdb,$session,$unit);
		$study_data = $this->dbh->prepare("SELECT * FROM `survey_studies` WHERE id = :id OR name = :name LIMIT 1");
		$study_data->bindParam(":id",$this->id);
		$study_data->bindParam(":name",$this->unit['name']);
		$study_data->execute() or die(print_r($study_data->errorInfo(), true));
		$vars = $study_data->fetch(PDO::FETCH_ASSOC);
			
		if($vars):
			$this->id = $vars['id'];
			$this->name = $vars['name'];
			$this->logo_name = $vars['logo_name'];
			$this->user_id = (int)$vars['user_id'];
			
			$this->getSettings();
			
			$this->valid = true;
		endif;
	}
	protected function getSettings()
	{
		$study_settings = $this->dbh->prepare("SELECT `key`, `value` FROM `survey_settings` WHERE study_id = :study_id");
		$study_settings->bindParam(":study_id",$this->id);
		$study_settings->execute() or die(print_r($study_settings->errorInfo(), true));
		while($setting = $study_settings->fetch(PDO::FETCH_ASSOC))
			$this->settings[$setting['key']] = $setting['value'];

		return $this->settings;
	}
	public function changeSettings($key_value_pairs)
	{
		$this->dbh->beginTransaction() or die(print_r($this->dbh->errorInfo(), true));
		$post_form = $this->dbh->prepare("INSERT INTO `survey_settings` (`study_id`, `key`, `value`)
																		  VALUES(:study_id, :key, :value) 
				ON DUPLICATE KEY UPDATE `value` = :value2;");
		
	    $post_form->bindParam(":study_id", $this->id);
		foreach($key_value_pairs AS $key => $value)
		{
		    $post_form->bindParam(":key", $key);
		    $post_form->bindParam(":value", $value);
		    $post_form->bindParam(":value2", $value);
			$post_form->execute() or die(print_r($post_form->errorInfo(), true));
		}

		$this->dbh->commit() or die(print_r($answered->errorInfo(), true));
		
		$this->getSettings();
	}
	public function uploadItemTable($file)
	{
		umask(0002);
		ini_set('memory_limit', '256M');
		$target = $_FILES['uploaded']['tmp_name'];
		$filename = $_FILES['uploaded']['name'];

		$this->messages[] = "File <b>$filename</b> was uploaded.";
		$this->messages[] = "Survey name was determined to be <b>{$this->name}</b>.";
		
		require_once INCLUDE_ROOT.'Model/SpreadsheetReader.php';

		$SPR = new SpreadsheetReader();
		$SPR->readItemTableFile($target);
		$this->errors = array_merge($this->errors, $SPR->errors);
		$this->warnings =  array_merge($this->warnings, $SPR->warnings);
		$this->messages =  array_merge($this->messages, $SPR->messages);
		$this->messages = array_unique($this->messages);
		$this->warnings = array_unique($this->warnings);
		
		// if items are ok, make actual survey
	    if (empty($this->errors) AND $this->createSurvey($SPR) ):
			
			if(!empty($this->warnings))
				alert('<ul><li>' . implode("</li><li>",$this->warnings).'</li></ul>','alert-warning');
			
			if(!empty($this->messages))
				alert('<ul><li>' . implode("</li><li>",$this->messages).'</li></ul>','alert-info');
			
			return true;
		else:
			alert('<ul><li>' . implode("</li><li>",$this->errors).'</li></ul>','alert-danger');
			return false;
		endif;
	}
	protected function existsByName($name)
	{
		if(!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,64}/",$name)) return;
		
		$exists = $this->dbh->prepare("SELECT name FROM `survey_studies` WHERE name = :name LIMIT 1");
		$exists->bindParam(':name',$name);
		$exists->execute() or die(print_r($create->errorInfo(), true));
		if($exists->rowCount())
			return true;
		
		$reserved = $this->dbh->query("SHOW TABLES LIKE '$name';");
		if($reserved->rowCount())
			return true;

		return false;
	}
	
	/* ADMIN functions */
	
	public function create($type = null)
	{
	    $name = trim($this->unit['name']);
	    if($name == ""):
			alert(_("<strong>Error:</strong> The study name (the name of the file you uploaded) can only contain the characters from <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore. The name has to at least 2, at most 64 characters long. It needs to start with a letter. No dots, no spaces, no dashes, no umlauts please. The file can have version numbers after a dash, like this <code>survey_1-v2.xlsx</code>, but they will be ignored."), 'alert-danger');
			return false;
		elseif(!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,64}/",$name)):
			alert('<strong>Error:</strong> The study name (the name of the file you uploaded) can only contain the characters from a to Z, 0 to 9 and the underscore. It needs to start with a letter. The file can have version numbers after a dash, like this <code>survey_1-v2.xlsx</code>.','alert-danger');
			return false;
		elseif($this->existsByName($name)):
			alert(__("<strong>Error:</strong> The survey name %s is already taken.",h($name)), 'alert-danger');
			return false;
		endif;

		$this->dbh->beginTransaction();
		$this->id = parent::create('Survey');
		$create = $this->dbh->prepare("INSERT INTO `survey_studies` (id, user_id,name) VALUES (:run_item_id, :user_id,:name);");
		$create->bindParam(':run_item_id',$this->id);
		$create->bindParam(':user_id',$this->unit['user_id']);
		$create->bindParam(':name',$name);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();
		
		$this->name = $name;
		
		$this->changeSettings(array
			(
//				"logo" => "hu.gif",
				"title" => "Survey",
				"description" => "",
				"problem_text" => 'If you run into problems, please contact <strong><a href="mailto:%s">%s</a></strong>.',
				"problem_email" => "problems@example.com",
				"displayed_percentage_maximum" => 100,
				"add_percentage_points" => 0,
				"submit_button_text" => '<i class="fa fa-arrow-circle-right pull-left fa-2x"></i> Go on to the<br>next page!',
				"form_classes" => '', // unspaced_rows
//				"fileuploadmaxsize" => "100000",
//				"closed_user_pool" => 0,
//				"timezone" => "Europe/Berlin",
//				"debug" => 0,
//				"primary_color" => "#ff0000",
//				"secondary_color" => "#00ff00",
//				'custom_styles' => ''
			)
		);
		
		return true;
	}
	protected $user_defined_columns = array(
		'name', 'label', 'label_parsed', 'type',  'type_options', 'choice_list', 'optional', 'class' ,'showif', 'value', 'order' // study_id is not among the user_defined columns
	);
	protected $choices_user_defined_columns = array(
		'list_name', 'name', 'label', 'label_parsed' // study_id is not among the user_defined columns
	);
	protected function getChoices()
	{
		$get_item_choices = $this->dbh->prepare("SELECT list_name, name, label FROM `survey_item_choices` WHERE `survey_item_choices`.study_id = :study_id 
		ORDER BY `survey_item_choices`.id ASC;");
		$get_item_choices->bindParam(":study_id", $this->id); // delete cascades to item display
		$get_item_choices->execute() or die(print_r($get_item_choices->errorInfo(), true));
		$choice_lists = array();
		while($row = $get_item_choices->fetch(PDO::FETCH_ASSOC)):
			if(!isset($choice_lists[ $row['list_name'] ]))
				$choice_lists[ $row['list_name'] ] = array();
			
			$choice_lists[ $row['list_name'] ][$row['name']] = $row['label'];
		endwhile;
		return $choice_lists;	
	}
	public function createSurvey($SPR) {
		$this->SPR = $SPR;
		
		
		$this->dbh->beginTransaction();
		
		$old_syntax = $this->getOldSyntax();
		
		$this->addChoices();
		
		$delete_old_items = $this->dbh->prepare("DELETE FROM `survey_items` WHERE `survey_items`.study_id = :study_id");
		$delete_old_items->bindParam(":study_id", $this->id); // delete cascades to item display
		$delete_old_items->execute() or die(print_r($delete_old_items->errorInfo(), true));
		
	
		$add_items = $this->dbh->prepare('INSERT INTO `survey_items` (
			study_id,
	        name,
	        label,
			label_parsed,
	        type,
			type_options,
			choice_list,
	        optional,
	        class,
	        showif,
	        value,
			`order`
		) VALUES (
			:study_id,
			:name,
			:label,
			:label_parsed,
			:type,
			:type_options,
			:choice_list,
			:optional,
			:class,
			:showif,
			:value,
			:order
			)');
	
		$result_columns = array();
		
		$add_items->bindParam(":study_id", $this->id);
		
		$choice_lists = $this->getChoices();
		$item_factory = new ItemFactory($choice_lists);
		
		foreach($this->SPR->survey as $row_number => $row) 
		{
			$item = $item_factory->make($row);
			
			if(!$item):
				$this->errors[] = __("Row %s: Type %s is invalid.",$row_number,$this->SPR->survey[$row_number]['type']);
				unset($this->SPR->survey[$row_number]);
				continue;
			else:
				$val_errors = $item->validate();
		
				if(!empty($val_errors)):
					$this->errors = $this->errors + $val_errors;
					unset($this->SPR->survey[$row_number]);
					continue;
				else:
					if(!$this->knittingNeeded($item->label)): // if the parsed label is constant
						$markdown = Parsedown::instance()
						    ->set_breaks_enabled(true)
						    ->parse($item->label); // transform upon insertion into db instead of at runtime

						if(mb_substr_count($markdown,"</p>")===1 AND preg_match("@^<p>(.+)</p>$@",trim($markdown),$matches)):
							$item->label_parsed = $matches[1];
						else:
							$item->label_parsed = $markdown;
						endif;
					endif;
				endif;
			endif;

			foreach ($this->user_defined_columns as $param) 
			{
				$add_items->bindValue(":$param", $item->$param);
			}
			$result_columns[] = $item->getResultField();
			
			$add_items->execute() or die(print_r($add_items->errorInfo(), true));
		}
		
		$unused = $item_factory->unusedChoiceLists();
		if(! empty( $unused ) ):
			$this->warnings[] = __("These choice lists were not used: '%s'", implode("', '",$unused));
		endif;
	
		$new_syntax = $this->getResultsTableSyntax($result_columns);
		
		if(!empty($this->errors))
		{
			$this->dbh->rollBack();
			$this->errors[] = "All changes were rolled back";
			return false;
		}
		elseif ($this->dbh->commit()) 
		{
			$this->messages[] = $delete_old_items->rowCount() . " old items were replaced with " . count($this->SPR->survey) . " new items.";
			
			if($new_syntax !== $old_syntax)
			{
				$this->messages[] = "A new results table was created.";
				return $this->createResultsTable($new_syntax);
			}
			else
			{
				$this->messages[] = "The old results table was kept.";
				return true;
			}
		}
		return false;
	}
	public function getItemsWithChoices()
	{
		$choice_lists = $this->getChoices();
		$item_factory = new ItemFactory($choice_lists);
		
		$raw_items = $this->getItems();
		
		
		$items = array();
		foreach($raw_items as $row) 
		{
			$item = $item_factory->make($row);
			$items[$item->name] = $item;
		}
		return $items;
	}
	private function addChoices()
	{
		$delete_old_choices = $this->dbh->prepare("DELETE FROM `survey_item_choices` WHERE `survey_item_choices`.study_id = :study_id");
		$delete_old_choices->bindParam(":study_id", $this->id); // delete cascades to item display
		$delete_old_choices->execute() or die(print_r($delete_old_choices->errorInfo(), true));
	

		$add_choices = $this->dbh->prepare('INSERT INTO `survey_item_choices` (
			study_id,
	        list_name,
			name,
	        label,
			label_parsed
		) VALUES (
			:study_id,
			:list_name,
			:name,
			:label,
			:label_parsed
		)');
		$add_choices->bindParam(":study_id", $this->id);
		
		foreach($this->SPR->choices AS $choice)
		{
			if(!$this->knittingNeeded( $choice['label'] )): // if the parsed label is constant
				$markdown = Parsedown::instance()
    ->set_breaks_enabled(true)
    ->parse($choice['label']); // transform upon insertion into db instead of at runtime

				if(mb_substr_count($markdown,"</p>")===1 AND preg_match("@^<p>(.+)</p>$@",trim($markdown),$matches)):
					$choice['label_parsed'] = $matches[1];
				else:
					$choice['label_parsed'] = $markdown;
				endif;
			endif;
			
			foreach ($this->choices_user_defined_columns as $param) 
			{
				$add_choices->bindParam(":$param", $choice[ $param ]);
			}
			$add_choices->execute() or die(print_r($add_choices->errorInfo(), true));
		}
		$this->messages[] = $delete_old_choices->rowCount() . " old choices deleted.";
		$this->messages[] = count($this->SPR->choices) . " choices were successfully loaded.";
		
		return true;
	}
	private function getResultsTableSyntax($columns)
	{
		$columns = array_filter($columns); // remove NULL, false, '' values (note, fork, submit, ...)
		if(empty($columns))
			return null;
		
		$columns = implode(",\n", $columns);
		
		$create = "CREATE TABLE `{$this->name}` (
		  `session_id` INT UNSIGNED NOT NULL ,
		  `study_id` INT UNSIGNED NOT NULL ,
		  `modified` DATETIME NULL DEFAULT NULL ,
		  `created` DATETIME NULL DEFAULT NULL ,
		  `ended` DATETIME NULL DEFAULT NULL ,
	
		  $columns,
		  
		  INDEX `fk_survey_results_survey_unit_sessions1_idx` (`session_id` ASC) ,
		  INDEX `fk_survey_results_survey_studies1_idx` (`study_id` ASC) ,
		  PRIMARY KEY (`session_id`) ,
		  CONSTRAINT `fk_{$this->name}_survey_unit_sessions1`
		    FOREIGN KEY (`session_id` )
		    REFERENCES `survey_unit_sessions` (`id` )
		    ON DELETE CASCADE
		    ON UPDATE NO ACTION,
		  CONSTRAINT `fk_{$this->name}_survey_studies1`
		    FOREIGN KEY (`study_id` )
		    REFERENCES `survey_studies` (`id` )
		    ON DELETE NO ACTION
		    ON UPDATE NO ACTION)
		ENGINE = InnoDB";
		return $create;
	}
	private function getOldSyntax()
	{
		$resC = $this->getResultCount();
		if($resC == array('finished' => 0, 'begun' => 0)):
			$this->messages[] = __("The results table was empty.",array_sum($resC));
			return null;
		endif;
		
		$old_items = $this->getItems();
		require_once INCLUDE_ROOT."Model/Item.php";
		
		$choice_lists = $this->getChoices();
		$item_factory = new ItemFactory($choice_lists);
		
		$old_result_columns = array();
		foreach($old_items AS $row)
		{
			$item = $item_factory->make($row);
			if(!$item)
			{
				alert("While trying to recreate old results table: Item type ".h($row['type']) . " not found.", 'alert-danger');
				return false;
			}
			$old_result_columns[] = $item->getResultField();
		}
		
		return $this->getResultsTableSyntax($old_result_columns);
	}
	private function createResultsTable($syntax)
	{
		if($this->deleteResults()):
			$drop = $this->dbh->query("DROP TABLE IF EXISTS `{$this->name}` ;");
			$drop->execute();
		else:
			return false;
		endif;
		
		$create_table = $this->dbh->query($syntax) or die(print_r($this->dbh->errorInfo(), true));
		if($create_table)
			return true;
		else return false;
	}
	public function getItems()
	{
		$get_items = $this->dbh->prepare("SELECT id,study_id,type,choice_list,type_options,name,label,label_parsed,optional,class,showif,value,`order` FROM `survey_items` WHERE `survey_items`.study_id = :study_id ORDER BY id ASC");
		$get_items->bindParam(":study_id", $this->id);
		$get_items->execute() or die(print_r($get_items->errorInfo(), true));

		$results = array();
		while($row = $get_items->fetch(PDO::FETCH_ASSOC))
			$results[] = $row;
		
		return $results;
	}
	public function countResults()
	{
		$get = "SELECT COUNT(*) AS count FROM `{$this->name}`";
		$get = $this->dbh->query($get) or die(print_r($this->dbh->errorInfo(), true));
		$results = array();
		$row = $get->fetch(PDO::FETCH_ASSOC);
		$this->result_count = $row['count'];
		return $row['count'];
	}
	public function getResults()
	{ // fixme: shouldnt be using wildcard operator here.
		$get = "SELECT `survey_run_sessions`.session, `{$this->name}`.* FROM `{$this->name}`
		LEFT JOIN `survey_unit_sessions`
		ON  `{$this->name}`.session_id = `survey_unit_sessions`.id
		LEFT JOIN `survey_run_sessions`
		ON `survey_unit_sessions`.run_session_id = `survey_run_sessions`.id";
		$get = $this->dbh->query($get) or die(print_r($this->dbh->errorInfo(), true));
		$results = array();
		while($row = $get->fetch(PDO::FETCH_ASSOC))
			$results[] = $row;
		
		return $results;
	}
	public function getItemDisplayResults()
	{
		$get = "SELECT `survey_run_sessions`.session,`survey_items`.name, 
		`survey_items_display`.id,
		`survey_items_display`.item_id,
		`survey_items_display`.session_id,
		`survey_items_display`.created,
		`survey_items_display`.modified,
		`survey_items_display`.answered_time,
		`survey_items_display`.answered,
		`survey_items_display`.displaycount
		 
		FROM `survey_items_display` 
		
		LEFT JOIN `survey_unit_sessions`
		ON `survey_unit_sessions`.id = `survey_items_display`.session_id
		
		LEFT JOIN `survey_run_sessions`
		ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
		
		LEFT JOIN `survey_items`
		ON `survey_items_display`.item_id = `survey_items`.id
		
		WHERE `survey_items`.study_id = :study_id
		ORDER BY `survey_run_sessions`.session, `survey_run_sessions`.created, `survey_items_display`.item_id";
		$get = $this->dbh->prepare($get) or die(print_r($this->dbh->errorInfo(), true));
		$get->bindParam(':study_id',$this->id);
		$get->execute() or die(print_r($this->dbh->errorInfo(), true));
		$results = array();
		while($row = $get->fetch(PDO::FETCH_ASSOC))
			$results[] = $row;
		
		return $results;
	}
	public function deleteResults()
	{
		$resC = $this->getResultCount();
		if($resC['finished'] > 10):
			if($this->backupResults()):
				$this->warnings[] = __("%s results rows were backed up.",array_sum($resC));
			else:
				$this->errors[] = __("Backup of %s result rows failed. Deletion cancelled.",array_sum($resC));
				return false;
			endif;
		elseif($resC == array('finished' => 0, 'begun' => 0)):
			return true;		
		else:
			$this->messages[] = __("%s results rows were deleted.",array_sum($resC));
		endif;
		
		$delete = $this->dbh->query("TRUNCATE TABLE `{$this->name}`") or die(print_r($this->dbh->errorInfo(), true));
		
		$delete_sessions = $this->dbh->prepare ( "DELETE FROM `survey_unit_sessions` 
		WHERE `unit_id` = :study_id" ) or die(print_r($this->dbh->errorInfo(), true));
		$delete_sessions->bindParam(':study_id',$this->id);
		$delete_sessions->execute();
		
		return $delete;
	}
	public function backupResults()
	{
        $filename = INCLUDE_ROOT ."backups/results/".$this->name . date('YmdHis') . ".tab";
		require_once INCLUDE_ROOT . 'Model/SpreadsheetReader.php';

		$SPR = new SpreadsheetReader();
		return $SPR->backupTSV( $this->getResults() , $filename);
	}
	public function getResultCount()
	{
		if($this->dbh->table_exists($this->name)):
			$get = "SELECT SUM(`{$this->name}`.ended IS NULL) AS begun, SUM(`{$this->name}`.ended IS NOT NULL) AS finished FROM `{$this->name}` 
			LEFT JOIN `survey_unit_sessions`
			ON `survey_unit_sessions`.id = `{$this->name}`.session_id";
			$get = $this->dbh->query($get) or die(print_r($this->dbh->errorInfo(), true));
			return $get->fetch(PDO::FETCH_ASSOC);
		else:
			return array('finished' => 0, 'begun' => 0);
		endif;
	}
	public function getAverageTimeItTakes()
	{
		$get = "SELECT AVG( ended - created) FROM `{$this->name}`";
		$get = $this->dbh->query($get) or die(print_r($this->dbh->errorInfo(), true));
		$time = $get->fetch(PDO::FETCH_NUM);
		$time = round($time[0] / 60, 2); # seconds to minutes
		
		return $time;
	}
	public function delete()
	{
		$delete_results = $this->dbh->query("DROP TABLE IF EXISTS `{$this->name}`") or die(print_r($this->dbh->errorInfo(), true));
		
		return parent::delete();
	}
	public function displayForRun($prepend = '')
	{
		if($this->id):
			$resultCount = $this->getResultCount();
			$time = $this->getAverageTimeItTakes();
			
			$dialog = "<h3>
				<strong>Survey:</strong> <a href='".WEBROOT."admin/survey/{$this->name}/index'>{$this->name}</a><br>
			<small>".(int)$resultCount['finished']." complete results,
		".(int)$resultCount['begun']." begun</small><br>
			<small>Takes on average $time minutes</small>
			</h3>
			<p>
			<p class='btn-group'>
				<a class='btn' href='".WEBROOT."admin/survey/{$this->name}/show_results'>View results</a>
				<a class='btn' href='".WEBROOT."admin/survey/{$this->name}/show_item_table'>View items</a>
				<a class='btn' href='".WEBROOT."admin/survey/{$this->name}/access'>Test</a>
			</p>";
		else:
			$dialog = '';
			$g_studies = $this->dbh->prepare("SELECT * FROM `survey_studies` WHERE user_id = :user_id");
			global $user;
			$g_studies->bindValue(':user_id',$user->id);
			$g_studies->execute();
			
			
			$studies = array();
			while($study = $g_studies->fetch())
				$studies[] = $study;
			if($studies):
				$dialog = '<div class="form-group">
				<select class="select2" name="unit_id" style="width:300px">
				<option value=""></option>';
				foreach($studies as $study):
				    $dialog .= "<option value=\"{$study['id']}\">{$study['name']}</option>";
				endforeach;
				$dialog .= "</select>";
				$dialog .= '<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Survey">Add to this run.</a></div>';
			else:
				$dialog .= "<h5>No studies. Add some first</h5>";
			endif;
		endif;
		$dialog = $prepend . $dialog;
		return parent::runDialog($dialog,'fa-pencil-square');
	}
}