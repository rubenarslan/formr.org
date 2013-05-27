<?php
require_once INCLUDE_ROOT . "Model/DB.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

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
				ON DUPLICATE KEY UPDATE `value` = :value;");
	    $post_form->bindParam(":study_id", $this->id);
		
		foreach($key_value_pairs AS $key => $value)
		{
		    $post_form->bindParam(":key", $key);
		    $post_form->bindParam(":value", $value);
			$post_form->execute() or die(print_r($post_form->errorInfo(), true));
		}

		$this->dbh->commit() or die(print_r($answered->errorInfo(), true));
		
		$this->getSettings();
	}
	protected function existsByName($name)
	{
		$exists = $this->dbh->prepare("SELECT name FROM `survey_studies` WHERE name = :name LIMIT 1");
		$exists->bindParam(':name',$name);
		$exists->execute() or die(print_r($create->errorInfo(), true));
		if($exists->rowCount())
			return true;
		
		$reserved = $this->dbh->prepare("SHOW TABLES LIKE :name");
		$reserved->bindParam(':name',$name);
		$reserved->execute() or die(print_r($reserved->errorInfo(), true));
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
				"problem_email" => "problems@example.com",
				"displayed_percentage_maximum" => 100,
				"add_percentage_points" => 0,
				"submit_button_text" => 'Weiter',
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
		'variablenname', 'wortlaut', 'altwortlautbasedon', 'altwortlaut', 'typ', 'antwortformatanzahl', 'mcalt1', 'mcalt2', 'mcalt3', 'mcalt4', 'mcalt5', 'mcalt6', 'mcalt7', 'mcalt8', 'mcalt9', 'mcalt10', 'mcalt11', 'mcalt12', 'mcalt13', 'mcalt14', 'optional', 'class' ,'skipif' // study_id is not among the user_defined columns
	);
	public function insertItems($items)
	{
		$this->dbh->beginTransaction();
		
		$delete_old_items = $this->dbh->prepare("DELETE FROM `survey_items` WHERE `survey_items`.study_id = :study_id");
		$delete_old_items->bindParam(":study_id", $this->id);
		$delete_old_items->execute() or die(print_r($delete_old_items->errorInfo(), true));
		
	
		$stmt = $this->dbh->prepare('INSERT INTO `survey_items` (
			study_id,
	        variablenname,
	        wortlaut,
	        altwortlautbasedon,
	        altwortlaut,
	        typ,
	        optional,
	        antwortformatanzahl,
	        MCalt1, MCalt2,	MCalt3,	MCalt4,	MCalt5,	MCalt6,	MCalt7,	MCalt8,	MCalt9,	MCalt10, MCalt11,	MCalt12,	MCalt13,	MCalt14,
	        class,
	        skipif) VALUES (
			:study_id,
			:variablenname,
			:wortlaut,
			:altwortlautbasedon,
			:altwortlaut,
			:typ,
			:optional,
			:antwortformatanzahl,
			:mcalt1, :mcalt2,	:mcalt3,	:mcalt4,	:mcalt5,	:mcalt6,	:mcalt7,	:mcalt8,	:mcalt9,	:mcalt10, :mcalt11,	:mcalt12,	:mcalt13,	:mcalt14,
			:class,
			:skipif
			)');
	
		foreach($items as $row) 
		{
			foreach ($this->user_defined_columns as $param) 
			{
				$stmt->bindParam(":$param", $row[$param]);
			}
			
			$stmt->bindParam(":study_id", $this->id);
			$stmt->execute() or die(print_r($stmt->errorInfo(), true));
		}
	
		if ($this->dbh->commit()) 
		{
			$this->messages[] = $delete_old_items->rowCount() . " old items deleted.";
			$this->messages[] = $stmt->rowCount() . " items were successfully loaded.";
			return true;
		}
		return false;
	}
	public function getItems()
	{
		$get_items = $this->dbh->prepare("SELECT * FROM `survey_items` WHERE `survey_items`.study_id = :study_id ORDER BY id ASC");
		$get_items->bindParam(":study_id", $this->id);
		$get_items->execute() or die(print_r($get_items->errorInfo(), true));

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
	{
		$get = "SELECT `{$this->name}`.* FROM `{$this->name}`";
		$get = $this->dbh->query($get) or die(print_r($this->dbh->errorInfo(), true));
		$results = array();
		while($row = $get->fetch(PDO::FETCH_ASSOC))
			$results[] = $row;
		
		return $results;
	}
	public function getItemDisplayResults()
	{
		$get = "SELECT `survey_run_sessions`.session, `survey_items_display`.* FROM `survey_items_display` 
		
		LEFT JOIN `survey_unit_sessions`
		ON `survey_unit_sessions`.id = `survey_items_display`.session_id
		
		LEFT JOIN `survey_run_sessions`
		ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
		
		WHERE `survey_items_display`.study_id = :id";
		$get = $this->dbh->prepare($get) or die(print_r($this->dbh->errorInfo(), true));
		$get->bindParam(':id',$id);
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
			$this->backupResults();
		elseif($resC['finished']>0):
			$delete = $this->dbh->query("TRUNCATE TABLE `{$this->name}`") or die(print_r($this->dbh->errorInfo(), true));
			$delete_sessions = $this->dbh->prepare ( "DELETE FROM `survey_unit_sessions` 
			WHERE `unit_id` = :study_id" ) or die(print_r($this->dbh->errorInfo(), true));
			$delete_sessions->bindParam(':study_id',$this->id);
			$delete_sessions->execute();
			// todo: rm item displays too
			return $delete;
		endif;
		return false;
	}
	public function backupResults()
	{
        $filename = INCLUDE_ROOT . "admin/results_backups/".$this->name . date('YmdHis') . ".tab";
		require_once INCLUDE_ROOT . 'Model/SpreadsheetReader.php';

		$SPR = new SpreadsheetReader();
		$SPR->saveTSV( $this->getResults() , $filename);
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
	public function createResultsTable($items)
	{
		$this->deleteResults();
		$columns = array();
		foreach($items AS $item)
		{
			$name = $item['variablenname'];
			$item = legacy_translate_item($item);
			$columns[] = $item->getResultField();
		}
		$columns = array_filter($columns); // remove NULL, false, '' values (instruction, fork, submit, ...)
		
		$columns = implode(",\n", $columns);
#		pr($this->name);
		$drop = $this->dbh->query("DROP TABLE IF EXISTS `{$this->name}` ;");
		$drop->execute();
		$create = "CREATE  TABLE `{$this->name}` (
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
#		pr($create);

		$create_table = $this->dbh->query($create) or die(print_r($this->dbh->errorInfo(), true));
		if($create_table)
			return true;
		else return false;
	}
	public function getSubstitutions()
	{
		$subs_query = $this->dbh->prepare ( "SELECT * FROM `survey_substitutions` WHERE `study_id` = :study_id ORDER BY id ASC" ) or die(print_r($this->dbh->errorInfo(), true));	// get all substitutions

		$subs_query->bindParam(':study_id',$this->id);
		$subs_query->execute() or die(print_r($subs_query->errorInfo(), true));	// get all substitutions
	

		$substitutions = array();
		while( $substitution = $subs_query->fetch() )
			$substitutions[] = $substitution; 

		return $substitutions;
		
	}
	public function editSubstitutions($posted)
	{
		$posted = array_unique($posted, SORT_REGULAR);
/*		function addPrefix(&$arr,$key,$study_name)
		{
			if(isset($arr['replace']) AND !preg_match(
			"/^[a-zA-Z0-9_]+\.[a-zA-Z0-9_]+$/"
			,$arr['replace']) AND
			preg_match(
						"/^[a-zA-Z0-9_]+$/"
						,$arr['replace']))
				$arr['replace'] = $study_name . '.' . $arr['replace'];
			if(isset($arr['replace']) AND !preg_match(
			"/^[a-zA-Z0-9_]+\.[a-zA-Z0-9_]+$/"
			,$arr['replace']))
				$arr['replace'] = 'invalid';
		}
		array_walk($posted,"addPrefix",$this->name);*/
		if(isset($posted['new']) AND $posted['new']['search'] != '' AND $posted['new']['replace'] != ''):
		
			$sub_add = $this->dbh->prepare ( "INSERT INTO `survey_substitutions` 
			SET	
				`study_id` = :study_id,
				`search` = :search,
				`replace` = :replace,
				`mode` = :mode
			" ) or die(print_r($this->dbh->errorInfo(), true));

			$sub_add->bindParam(':study_id',$this->id);
			$sub_add->bindParam(':mode',$posted['new']['mode']);
			$sub_add->bindParam(':search',$posted['new']['search']);
			$sub_add->bindParam(':replace',$posted['new']['replace']);
			$sub_add->execute() or die(print_r($sub_add->errorInfo(), true));
			
			unset($posted['new']);
		endif;
		
		$sub_update = $this->dbh->prepare ( "UPDATE `survey_substitutions` 
			SET 
				`search` = :search, 
				`replace` = :replace, 
				`mode` = :mode
		WHERE `study_id` = :study_id AND id = :id" ) or die(print_r($this->dbh->errorInfo(), true));
		$sub_update->bindParam(':study_id',$this->id);
		
		$sub_delete = $this->dbh->prepare ( "DELETE FROM `survey_substitutions` 
		WHERE `study_id` = :study_id AND id = :id" ) or die(print_r($this->dbh->errorInfo(), true));
		$sub_delete->bindParam(':study_id',$this->id);

		foreach($posted AS $id => $val):
			if(isset($val['delete'])):
				$sub_delete->bindParam(':id',$id);
				$sub_delete->execute() or die(print_r($sub_delete->errorInfo(), true));
			elseif(is_array($val) AND isset($val['search']) AND $val['search']!= '' AND $val['replace']!=''):
				$sub_update->bindParam(':id',$id);
				$sub_update->bindParam(':search',$val['search']);
				$sub_update->bindParam(':replace',$val['replace']);
				$sub_update->bindParam(':mode',$val['mode']);
				$sub_update->execute() or die(print_r($sub_update->errorInfo(), true));
			endif;
		endforeach;
	}
	public function delete()
	{
		$this->dbh->beginTransaction() or die(print_r($this->dbh->errorInfo(), true));
		$delete_study = $this->dbh->prepare("DELETE FROM `survey_studies` WHERE id = :study_id") or die(print_r($this->dbh->errorInfo(), true)); // Cascades
		$delete_study->bindParam(':study_id',$this->id);
		$delete_study->execute() or die(print_r($delete_study->errorInfo(), true));
		
		$delete_results = $this->dbh->query("DROP TABLE `{$this->name}`") or die(print_r($this->dbh->errorInfo(), true));
		
		$this->dbh->commit();
	}
	public function displayForRun($prepend = '')
	{
		if($this->id):
			$dialog = "<p>
				<strong>Survey:</strong> <a href='".WEBROOT."admin/{$this->name}/index'>{$this->name}</a>
			</p>
			<p>
				<a class='btn' href='".WEBROOT."admin/{$this->name}/access'>Test</a>
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