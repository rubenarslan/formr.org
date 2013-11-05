<?php
require_once INCLUDE_ROOT . "Model/DB.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";
require_once INCLUDE_ROOT."Model/Item.php";
use \Michelf\Markdown AS Markdown;

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
	public $position;
	private $SPR;
	
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
			$this->user_id = $vars['user_id'];
			
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
	protected function existsByName($name)
	{
		if(!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,20}/",$name)) return;
		
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
			$this->errors[] = _("You have to specify a study name.");
			return false;
		elseif(!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,20}/",$name)):
			$this->errors[] = _("The study's name has to be between 3 and 20 characters and can't start with a number or contain anything other a-Z_0-9.");
			return false;
		elseif($this->existsByName($name)):
			$this->errors[] = __("The study's name %s is already taken.",h($name));
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
				"welcome" => "Welcome!",
				"title" => "Survey",
				"description" => "",
				"problem_text" => 'Bei Problemen wende dich bitte an <strong><a href="mailto:%s">%s</a></strong>.',
				"problem_email" => "problems@example.com",
				"displayed_percentage_maximum" => 100,
				"add_percentage_points" => 0,
				"submit_button_text" => 'Weiter',
				"form_classes" => 'unspaced_rows',
//				"fileuploadmaxsize" => "100000",
//				"closed_user_pool" => 0,
//				"timezone" => "Europe/Berlin",
//				"debug" => 0,
//				"skipif_debug" => 0,
//				"primary_color" => "#ff0000",
//				"secondary_color" => "#00ff00",
//				'custom_styles' => ''
			)
		);
		
		return true;
	}
	protected $user_defined_columns = array(
		'name', 'label', 'label_parsed', 'type',  'type_options', 'choice_list', 'optional', 'class' ,'skipif' // study_id is not among the user_defined columns
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
	        skipif
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
			:skipif
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
						$markdown = Markdown::defaultTransform($item->label); // transform upon insertion into db instead of at runtime

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
				$add_items->bindParam(":$param", $item->$param);
			}
			$result_columns[] = $item->getResultField();
			
			$add_items->execute() or die(print_r($add_items->errorInfo(), true));
		}
		
		$unused = $item_factory->unusedChoiceLists();
		if(! empty( $unused ) ):
			$this->messages[] = __("These choice lists were not used: '%s'", implode("', '",$unused));
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
			$this->messages[] = $delete_old_items->rowCount() . " old items deleted.";
			$this->messages[] = count($this->SPR->survey) . " items were successfully loaded.";
			
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
				$markdown = Markdown::defaultTransform($choice['label']); // transform upon insertion into db instead of at runtime

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
		$columns = array_filter($columns); // remove NULL, false, '' values (instruction, fork, submit, ...)
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
		$get_items = $this->dbh->prepare("SELECT * FROM `survey_items` WHERE `survey_items`.study_id = :study_id ORDER BY id ASC");
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
		
		WHERE `survey_items`.study_id = :study_id";
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
				$this->messages[] = __("%s results rows were backed up.",array_sum($resC));
			else:
				$this->errors[] = "Backup of %s result rows failed. Deletion cancelled.";
				return false;
			endif;
		elseif($resC == array('finished' => 0, 'begun' => 0)):
			$this->messages[] = __("The results table was empty.",array_sum($resC));
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
			$g_studies = $this->dbh->query("SELECT * FROM `survey_studies`");
			$studies = array();
			while($study = $g_studies->fetch())
				$studies[] = $study;
			if($studies):
				$dialog = '<div class="control-group">
				<select class="select2" name="unit_id" style="width:300px">
				<option value=""></option>';
				foreach($studies as $study):
				    $dialog .= "<option value=\"{$study['id']}\">{$study['name']}</option>";
				endforeach;
				$dialog .= "</select>";
				$dialog .= '<a class="btn unit_save" href="ajax_save_run_unit?type=Survey">Add to this run.</a></div>';
			else:
				$dialog .= "<h5>No studies. Add some first</h5>";
			endif;
		endif;
		$dialog = $prepend . $dialog;
		return parent::runDialog($dialog,'icon-question');
	}
}