<?php
require_once INCLUDE_ROOT."Model/DB.php";

class RunUnit {
	public $errors = array();
	public $id = null;
	public $user_id = null;
	public $session = null;
	public $unit = null;
	public $ended = false;
	public $position;
	public $called_by_cron = false;
	public $knitr = false;
	public $session_id = null;
	public $type = '';
	public $icon = 'fa-wrench';
	
	public function __construct($fdb, $session = null, $unit = null) 
	{
		$this->dbh = $fdb;
		$this->session = $session;
		$this->unit = $unit;
		
		if(isset($unit['run_id']))
			$this->run_id = $unit['run_id'];
		
		if(isset($unit['run_name']))
			$this->run_name = $unit['run_name'];

		if(isset($unit['session_id']))
			$this->session_id = $unit['session_id'];

		if(isset($unit['run_session_id']))
			$this->run_session_id = $unit['run_session_id'];

		if(isset($this->unit['unit_id'])) 
			$this->id = $this->unit['unit_id'];
		
		if(isset($this->unit['position'])) 
			$this->position = (int)$this->unit['position'];
		
		
		if(isset($this->unit['cron'])) 
			$this->called_by_cron = true;
		
	}
	public function create($type)
	{
		$c_unit = $this->dbh->prepare("INSERT INTO `survey_units` 
			SET type = :type,
		 created = NOW(),
	 	 modified = NOW();");

		$c_unit->bindParam(':type', $type);
		
		$c_unit->execute() or die(print_r($c_unit->errorInfo(), true));
		
		return $this->dbh->lastInsertId();
	}
	public function modify($id)
	{
		$c_unit = $this->dbh->prepare("UPDATE `survey_units` 
			SET 
	 	 modified = NOW()
	 WHERE id = :id;");
		$c_unit->bindParam(':id', $id);
		
		$success = $c_unit->execute() or die(print_r($c_unit->errorInfo(), true));
		
		return $success;
	}
	public function addToRun($run_id, $position = 1)
	{
		if($position=='NaN') $position = 1;
		$this->position = (int)$position;
		$s_run_unit = $this->dbh->prepare("SELECT id FROM `survey_run_units` WHERE unit_id = :id AND run_id = :run_id;");
		$s_run_unit->bindParam(':id', $this->id);
		$s_run_unit->bindParam(':run_id', $run_id);
		$s_run_unit->execute() or ($this->errors = $s_run_unit->errorInfo());
		
		if($s_run_unit->rowCount()===0):
			$d_run_unit = $this->dbh->prepare("INSERT INTO `survey_run_units` SET 
				unit_id = :id, 
			run_id = :run_id,
			position = :position
		;");
			$d_run_unit->bindParam(':id', $this->id);
			$d_run_unit->bindParam(':run_id', $run_id);
			$d_run_unit->bindParam(':position', $this->position);
			$d_run_unit->execute() or $this->errors = $d_run_unit->errorInfo();
			return $d_run_unit->rowCount();
		endif;
		return $s_run_unit->rowCount();
	}
	public function removeFromRun($run_id)
	{
		$d_run_unit = $this->dbh->prepare("DELETE FROM `survey_run_units` WHERE unit_id = :id AND run_id = :run_id;");
		$d_run_unit->bindParam(':id', $this->id);
		$d_run_unit->bindParam(':run_id', $run_id);
		$d_run_unit->execute() or ($this->errors = $d_run_unit->errorInfo());
		
		return $d_run_unit->rowCount();
	}
	public function delete()
	{
		$d_unit = $this->dbh->prepare("DELETE FROM `survey_units` WHERE id = :id;");
		$d_unit->bindParam(':id', $this->id);
		
		$d_unit->execute() or $this->errors = $d_unit->errorInfo();
		
		$affected = $d_unit->rowCount();
		if($affected): // remove from all runs
			$d_run_unit = $this->dbh->prepare("DELETE FROM `survey_run_units` WHERE unit_id = :id;");
			$d_run_unit->bindParam(':id', $this->id);
			$d_run_unit->execute() or $this->errors = $d_run_unit->errorInfo();
			
			$affected +=  $d_run_unit->rowCount();
		endif;
		
		return $affected;
	}
	public function end() // todo: logically this should be part of the Unit Session Model, but I messed up my logic somehow
	{
		$finish_unit = $this->dbh->prepare("UPDATE `survey_unit_sessions` 
			SET `ended` = NOW()
			WHERE 
			`id` = :session_id AND 
			`unit_id` = :unit_id AND 
			`ended` IS NULL
		LIMIT 1;");
		$finish_unit->bindParam(":session_id", $this->session_id);
		$finish_unit->bindParam(":unit_id", $this->id);
		$finish_unit->execute() or die(print_r($finish_unit->errorInfo(), true));

		if($finish_unit->rowCount() === 1):
			$this->ended = true;
			return true;
		else:
			return false;
		endif;
	}
	private function howManyReachedIt()
	{
		$reached_unit = $this->dbh->prepare("SELECT SUM(`survey_unit_sessions`.ended IS NULL) AS begun, SUM(`survey_unit_sessions`.ended IS NOT NULL) AS finished FROM `survey_unit_sessions` 
			WHERE 
			`survey_unit_sessions`.`unit_id` = :unit_id;");
		$reached_unit->bindParam(":unit_id", $this->id);
		$reached_unit->execute() or die(print_r($reached_unit->errorInfo(), true));
		$reached = $reached_unit->fetch(PDO::FETCH_ASSOC);
		return "<span class='hastooltip badge' title='Number of unfinished sessions'>".(int)$reached['begun']."</span> <span class='hastooltip badge badge-success' title='Number of finished sessions'>".(int)$reached['finished']."</span>";
	}
	public function runDialog($dialog)
	{
		if(isset($this->position))
			$position = $this->position;
		elseif(isset($this->unit) AND isset($this->unit['position']))
			$position = $this->unit['position'];
		else 
		{
			$pos = $this->dbh->prepare("SELECT position FROM survey_run_units WHERE unit_id = :unit_id");
			$pos->bindParam(":unit_id",$this->id);
			$pos->execute();
			$position = $pos->fetch();
			$position = $position[0];
		}

		return '
		<div class="col-xs-12 row run_unit_inner '. $this->type .'">
				<div class="col-xs-3 run_unit_position">
					<h1><i class="muted fa fa-2x '.$this->icon.'"></i></h1>
					'.$this->howManyReachedIt().' <button href="ajax_remove_unit_from_run" class="remove_unit_from_run btn btn-xs hastooltip" title="Remove unit from run"><i class="fa fa-times"></i></button>
<br>
					<input class="position" value="'.$position.'" type="number" name="position['.$this->id.']" step="1" max="32000" min="-32000"><br>
				</div>
			<div class="col-xs-9 run_unit_dialog">
				<input type="hidden" value="'.$this->id.'" name="unit_id">'.$dialog.'
			</div>
		</div>';
	}
	public function displayForRun($prepend = '')
	{
		return parent::runDialog($prepend,'<i class="fa fa-puzzle-piece"></i>');
	}
	protected function getUserDataInRun($surveys)
	{
		$results = array();
		foreach($surveys AS $survey_name): // fixme: shouldnt be using wildcard operator here.
			$q1 = "SELECT `survey_run_sessions`.session, `$survey_name`.* FROM `$survey_name` 
			";

			$q3
				 = "
			WHERE  `survey_run_sessions`.id = :run_session_id;";
			
			if(!in_array($survey_name,array('survey_users','survey_unit_sessions'))):
				$q2 = "left join `survey_unit_sessions`
					on `$survey_name`.session_id = `survey_unit_sessions`.id
					left join `survey_run_sessions`
					on `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
				";
				
			elseif($survey_name == 'survey_unit_sessions'):
				$q2 = "left join `survey_run_sessions`
					on `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
				";
			elseif($survey_name == 'survey_users'):
				$q2 = "left join `survey_run_sessions`
					on `survey_users`.id = `survey_run_sessions`.user_id
				";
			endif;
			
			$q = $q1 . $q2 . $q3;

			$get_results = $this->dbh->prepare($q);
			
			$get_results->bindParam(':run_session_id', $this->run_session_id);
			$get_results->execute();
			$results[$survey_name] = array();
			while($res = $get_results->fetch(PDO::FETCH_ASSOC)):
				foreach($res AS $var => $val):
					
					if(!isset($results[$survey_name][$var]))
						$results[$survey_name][$var] = array();
					
					$results[$survey_name][$var][] = $val;
					
				endforeach;
			endwhile;
		endforeach;
		return $results;
	}
	protected function makeOpenCPU()
	{
		require_once INCLUDE_ROOT . "Model/OpenCPU.php";

		global $css,$js;
		$css = '<link rel="stylesheet" href="'.WEBROOT.'assets/knitr.css" type="text/css">';
		$js = '<script type="text/javascript" src="'.WEBROOT.'assets/knitr.js"></script>';

		global $settings;
		$openCPU = new OpenCPU($settings['opencpu_instance']);
		return $openCPU;
	}
	protected function knittingNeeded($source)
	{
		if(mb_strpos($source,'`r ')!==false OR mb_strpos($source,'```{r')!==false)
			 return true;
		 else
			return false;
	}
	protected function dataNeeded($fdb,$q)
	{
		$matches = $tables = array();
		$result_tables = $fdb->query("SELECT name FROM `survey_studies`");
		while($res = $result_tables->fetch(PDO::FETCH_ASSOC)):
			$tables[] = $res['name'];
		endwhile;
		$tables[] = 'survey_users';
		$tables[] = 'survey_unit_sessions';
		$tables[] = 'survey_email_log';
		
		foreach($tables AS $result):
			if(preg_match("/\b$result\b)/",$q)): // study name appears as word, matches nrow(survey), survey$item, survey[row,], but not survey_2
				$matches[] = $result;
			endif;
		endforeach;
	
		return $matches;
	}
	public function getParsedBodyAdmin($source,$email_embed = false)
	{
		if($this->knittingNeeded($source)):
			$q = "SELECT `survey_run_sessions`.session,`survey_run_sessions`.id,`survey_run_sessions`.position FROM `survey_run_sessions`

			WHERE 
				`survey_run_sessions`.run_id = :run_id

			ORDER BY `survey_run_sessions`.position DESC,RAND()

			LIMIT 1";
			$get_sessions = $this->dbh->prepare($q); // should use readonly
			$get_sessions->bindParam(':run_id',$this->run_id);
		
			$get_sessions->execute() or die(print_r($get_sessions->errorInfo(), true));
		
			if($get_sessions->rowCount()>=1):
				$temp_user = $get_sessions->fetch(PDO::FETCH_ASSOC);
				$this->run_session_id = $temp_user['id'];
			else:
				echo 'No data to compare to yet.';
				return false;
			endif;
			
			
			$openCPU = $this->makeOpenCPU();
			
			$openCPU->addUserData($this->getUserDataInRun(
				$this->dataNeeded($this->dbh,$source)
			));
			
			if($email_embed):
				return $openCPU->knitEmail($source); # currently not caching email reports
			else:
				$report = $openCPU->knitForAdminDebug($source);
			endif;
			
			return $report;
			
		else:
			if($email_embed):
				return array('body'=>$this->body_parsed,'images'=>array());
			else:
				return $this->body_parsed;
			endif;
		endif;
	}
	public function getParsedBody($source,$email_embed = false)
	{
		if(!$this->knittingNeeded($source))
		{ // knit if need be
			if($email_embed):
				return array('body'=>$this->body_parsed,'images'=>array());
			else:
				return $this->body_parsed;
			endif;
		}
		else
		{
			if(!$email_embed)
			{
				$get_report = $this->dbh->prepare("SELECT `body_knit` FROM `survey_reports` WHERE 
					`session_id` = :session_id AND 
					`unit_id` = :unit_id");
				$get_report->bindParam(":unit_id",$this->id);
				$get_report->bindParam(":session_id",$this->session_id);
				$get_report->execute();
			
				if($get_report->rowCount() > 0) 
				{
					$report = $get_report->fetch(PDO::FETCH_ASSOC);
					return $report['body_knit'];
				}
			}
			
			$openCPU = $this->makeOpenCPU();
			$openCPU->addUserData($this->getUserDataInRun(
				$this->dataNeeded($this->dbh,$source)
			));
			
			
			if($email_embed):
				return $openCPU->knitEmail($source); # currently not caching email reports
			else:
				$report = $openCPU->knitForUserDisplay($source);
			endif;
			
			if($report):
				$set_report = $this->dbh->prepare("INSERT INTO `survey_reports` 
					(`session_id`, `unit_id`, `body_knit`, `created`,	`last_viewed`) 
			VALUES  (:session_id, :unit_id, :body_knit,  NOW(), 	NOW() ) ");
				$set_report->bindParam(":unit_id",$this->id);
				$set_report->bindParam(":body_knit",$report);
				$set_report->bindParam(":session_id",$this->session_id);
				$set_report->execute();
				return $report;
			endif;
		}
	}
	// when body is changed, delete all survey reports?
}