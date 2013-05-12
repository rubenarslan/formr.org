<?php
require_once INCLUDE_ROOT . "Model/DB.php";

/*
## types of run units
	* branches 
		(these evaluate a condition and go to one position in the run, can be used for allowing access)
	* feedback 
		(atm just markdown pages with a title and body, but will have to use these for making graphs etc at some point)
		(END POINTS, does not automatically lead to next run unit in list, but doesn't have to be at the end because of branches)
	* breaks
		(go on if it's the next day, a certain date etc., so many days after beginning etc.)
	* emails
		(there should be another unit afterwards, otherwise shows default end page after email was sent)
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
	private $api_secret = null;
	public $settings = array();
	public $errors = array();
	public $messages = array();
	private $dbh;
	
	public function __construct($fdb, $name, $options = null) 
	{
		$this->dbh = $fdb;
		
		if($name !== null OR ($name = $this->create($options))):
			$this->name = $name;
			$run_data = $this->dbh->prepare("SELECT id,owner_id,name,api_secret FROM `survey_runs` WHERE name = :run_name LIMIT 1");
			$run_data->bindParam(":run_name",$this->name);
			$run_data->execute() or die(print_r($run_data->errorInfo(), true));
			$vars = $run_data->fetch(PDO::FETCH_ASSOC);
			
			if($vars):
				$this->id = $vars['id'];
				$this->owner_id = $vars['owner_id'];
				$this->api_secret = $vars['api_secret'];
			
				$this->valid = true;
			endif;
		endif;
	}
	public function create($options)
	{
	    $name = trim($options['run_name']);
	    if($name == ""):
			$this->errors[] = _("You have to specify a run name.");
			return false;
		elseif(!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,20}/",$name)):
			$this->errors[] = _("The run's name has to be between 3 and 20 characters and can't start with a number or contain anything other a-Z_0-9.");
			return false;
		elseif($this->existsByName($name)):
			$this->errors[] = __("The run's name '%s' is already taken.",h($name));
			return false;
		endif;

		$this->dbh->beginTransaction();
		$create = $this->dbh->prepare("INSERT INTO `survey_runs` (owner_id, name, api_secret) VALUES (:owner_id, :name, :api_secret);");
		$create->bindParam(':owner_id',$options['owner_id']);
		$create->bindParam(':name',$name);
		$new_secret = bin2hex(openssl_random_pseudo_bytes(32));
		$create->bindParam(':api_secret',$new_secret);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();

		return $name;
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
	public function getAllUnitIds()
	{
		$g_unit = $this->dbh->prepare(
		"SELECT 
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
	public function getUnitAdmin($id)
	{
		$g_unit = $this->dbh->prepare(
		"SELECT 
			`survey_run_units`.*,
			`survey_units`.*
			
			 FROM `survey_run_units` 
			 
		LEFT JOIN `survey_units`
		ON `survey_units`.id = `survey_run_units`.unit_id
		
		WHERE 
			`survey_run_units`.run_id = :run_id AND
			`survey_run_units`.unit_id = :unit_id
		LIMIT 1
		;");
		$g_unit->bindParam(':run_id',$this->id);
		$g_unit->bindParam(':unit_id',$id);
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));

		$unit = $g_unit->fetch(PDO::FETCH_ASSOC);
		return $unit;
	}
	public function getUnit($user_code)
	{
		$unit = $this->getCurrentUnit($user_code);
		if(!$unit):
			$unit = $this->getNextUnit($user_code);
		endif;

		return $unit;
	}
	public function getCurrentUnit($session)
	{
		$g_unit = $this->dbh->prepare(
		"SELECT 
			`survey_runs`.name AS run_name,
			`survey_runs`.id,
			`survey_runs`.owner_id,
			`survey_run_units`.*,
			`survey_units`.*,
			`survey_unit_sessions`.*
		
			 FROM `survey_run_units` 
		 
		LEFT JOIN `survey_units`
		ON `survey_units`.id = `survey_run_units`.unit_id
	
		LEFT JOIN `survey_runs`
		ON `survey_run_units`.run_id = `survey_runs`.id
	
		LEFT JOIN `survey_unit_sessions`
		ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
		WHERE 
			`survey_run_units`.run_id = :run_id AND
			`survey_unit_sessions`.session = :session AND
			`survey_unit_sessions`.ended IS NULL
		
		ORDER BY `survey_unit_sessions`.created DESC
		LIMIT 1
		;");
		$g_unit->bindParam(':run_id',$this->id);
		$g_unit->bindParam(':session',$session);
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$unit = $g_unit->fetch(PDO::FETCH_ASSOC);
		return $unit;

	}
	public function getLastUnit($session)
	{
		$g_unit = $this->dbh->prepare(
		"SELECT *
			 FROM `survey_run_units` 
			 
		LEFT JOIN `survey_units`
		ON `survey_units`.id = `survey_run_units`.unit_id
		
		LEFT JOIN `survey_unit_sessions`
		ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
		WHERE 
			`survey_run_units`.run_id = :run_id AND
			`survey_unit_sessions`.session = :session AND
			`survey_unit_sessions`.ended IS NOT NULL
			
		ORDER BY `survey_unit_sessions`.created DESC
		LIMIT 1
		;");
		$g_unit->bindParam(':run_id',$this->id);
		$g_unit->bindParam(':session',$session);
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$unit = $g_unit->fetch(PDO::FETCH_ASSOC);
		return $unit;
	}
	public function getNextUnit($session)
	{
		$last_unit = $this->getLastUnit($session);
		$g_unit = $this->dbh->prepare(
		"SELECT 
			`survey_runs`.name AS run_name,
			`survey_runs`.id,
			`survey_runs`.owner_id,
			`survey_run_units`.*,
			`survey_units`.*
			
			 FROM `survey_run_units` 
		LEFT JOIN `survey_units`
		ON `survey_units`.id = `survey_run_units`.unit_id

		LEFT JOIN `survey_runs`
		ON `survey_run_units`.run_id = `survey_runs`.id
		
			WHERE 
			`survey_run_units`.run_id = :run_id AND
			`survey_run_units`.position > :position
		ORDER BY `survey_run_units`.position ASC
		LIMIT 1
		;");
		$g_unit->bindParam(':run_id',$this->id);
		if($last_unit)
			$g_unit->bindValue(':position',$last_unit['position']);
		else
			$g_unit->bindValue(':position',-1000);
		
		$g_unit->execute() or die(print_r($g_unit->errorInfo(), true));
		$unit = $g_unit->fetch(PDO::FETCH_ASSOC);
		return $unit;
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

	
	/* ADMIN functions */
	

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
		$get_items = $this->dbh->prepare("SELECT * FROM `survey_items` WHERE `survey_items`.study_id = :study_id");
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
		$get = "SELECT `survey_unit_sessions`.session, `{$this->name}`.* FROM `{$this->name}` 
		LEFT JOIN `survey_unit_sessions`
		ON `survey_unit_sessions`.id = `{$this->name}`.session_id";
		$get = $this->dbh->query($get) or die(print_r($this->dbh->errorInfo(), true));
		$results = array();
		while($row = $get->fetch(PDO::FETCH_ASSOC))
			$results[] = $row;
		
		return $results;
	}
	public function getItemDisplayResults()
	{
		$get = "SELECT `survey_unit_sessions`.session, `survey_items_display`.* FROM `survey_items_display` 
		LEFT JOIN `survey_unit_sessions`
		ON `survey_unit_sessions`.id = `survey_items_display`.session_id";
		$get = $this->dbh->query($get) or die(print_r($this->dbh->errorInfo(), true));
		$results = array();
		while($row = $get->fetch(PDO::FETCH_ASSOC))
			$results[] = $row;
		
		return $results;
	}
	public function deleteResults()
	{
		$resC = $this->getResultCount();
		if($resC['finished'] > 10)
			$this->backupResults();
		$delete = $this->dbh->query("TRUNCATE TABLE `{$this->name}`") or die(print_r($this->dbh->errorInfo(), true));
		return $delete;
	}
	public function backupResults()
	{
        $filename = INCLUDE_ROOT . "admin/results_backups/".$this->name . date('YmdHis') . ".tab";
		require_once INCLUDE_ROOT . 'Model/SpreadsheetReader.php';

		$SPR = new SpreadsheetReader();
		$SPR->exportCSV( $this->getResults() , $filename);
	}
	public function getResultCount()
	{
		$get = "SELECT SUM(ended IS NULL) AS begun, SUM(ended IS NOT NULL) AS finished FROM `{$this->name}` 
		LEFT JOIN `survey_unit_sessions`
		ON `survey_unit_sessions`.id = `{$this->name}`.session_id";
		$get = $this->dbh->query($get) or die(print_r($this->dbh->errorInfo(), true));
		return $get->fetch(PDO::FETCH_ASSOC);
	}
	public function createResultsTable($items)
	{
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
		$create = "CREATE TABLE IF NOT EXISTS `{$this->name}` (
                    session_id INT NOT NULL,
					study_id INT NOT NULL,
                    modified DATETIME DEFAULT NULL,
                    created DATETIME DEFAULT NULL,
                    ended DATETIME DEFAULT NULL,
					$columns,
                    UNIQUE (
                        session_id
                    )) ENGINE = MyISAM CHARACTER SET utf8 COLLATE utf8_general_ci";

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
		function addPrefix(&$arr,$key,$study_name)
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
		array_walk($posted,"addPrefix",$this->name);
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
}


/*

		$g_unit = $this->dbh->prepare(
		"SELECT `survey_run_units`.*,`survey_unit_sessions`.*,
			 `survey_breaks`.id AS break,
			 `survey_emails`.id AS email,
			 `survey_pages`.id AS page,
			 `survey_externals`.id AS external,
			 `survey_branches`.id AS branch,
			 `survey_studies`.id AS study
			 FROM `survey_run_units` 
		LEFT JOIN `survey_unit_sessions`
		ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
		
		left join	`survey_breaks`
		on 			`survey_breaks`.id =  `survey_run_units`.unit_id
		left join	`survey_emails`
		on 			`survey_emails`.id =  `survey_run_units`.unit_id
		left join	`survey_branches`
		on 			`survey_branches`.id =  `survey_run_units`.unit_id
		left join	`survey_pages`
		on 			`survey_pages`.id =  `survey_run_units`.unit_id
		left join	`survey_studies`
		on 			`survey_studies`.id =  `survey_run_units`.unit_id
		left join	`survey_externals`
		on 			`survey_externals`.id =  `survey_run_units`.unit_id


		WHERE 
			`survey_run_units`.run_id = :run_id AND
			`survey_unit_sessions`.session = :session
		ORDER BY `survey_unit_sessions`.created DESC
		LIMIT 1
		;");
*/