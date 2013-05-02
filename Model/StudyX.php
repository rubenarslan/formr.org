<?php
require_once INCLUDE_ROOT . "Model/DB.php";

class StudyX
{
	public $id = null;
	public $name = null;
	public $valid = false;
	public $errors = array();
	
	public function __construct($id,$options = NULL) {
		$this->dbh = new DB();
		
		if($id === null):
			if(!$this->create($options)):
				$this->valid = false;
			endif;
		else:
			$this->id = $id;
		endif;
		
		$study_data = $this->dbh->prepare("SELECT name FROM `survey_studies` WHERE id = :study_id LIMIT 1");
		$study_data->bindParam(":study_id",$this->id);
		$study_data->execute() or die(print_r($study_data->errorInfo(), true));
		$vars = $study_data->fetch(PDO::FETCH_ASSOC);
		
		if($vars)
			$this->name = $vars['name'];
		else 
			$this->valid = false;
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
	public function create($options)
	{
	    $name = trim($options['name']);
	    if($name == ""):
			$this->errors[] = _("You have to specify a study name.");
			return false;
		elseif(!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,20}/",$name)):
			$this->errors[] = _("The study's name has to be between 3 and 20 characters and can't start with a number or contain anything other a-Z_0-9.");
			return false;
		elseif($this->existsByName($options['name'])):
			$this->errors[] = __("The study's name %s is already taken.",h($name));
			return false;
		endif;
		
		$create = $this->dbh->prepare("INSERT INTO `survey_studies` (user_id,name,prefix) VALUES (:user_id,:name,:name);");
		$create->bindParam(':user_id',$options['user_id']);
		$create->bindParam(':name',$name);
		$create->execute() or die(print_r($create->errorInfo(), true));

		$this->id = $this->dbh->lastInsertId();
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
}
