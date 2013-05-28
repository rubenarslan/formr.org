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
		if($affected):
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
	public function runDialog($dialog,$icon = '')
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
			<div>
				<div class="span2 run_unit_position">
				<h1><i class="icon-2x icon-muted '.$icon.'"></i></h1>
					'.$this->howManyReachedIt().' <button href="ajax_remove_unit_from_run" class="remove_unit_from_run btn btn-mini hastooltip" title="Remove unit from run"><i class="icon-remove"></i></button>
<br>
					<input class="position" value="'.$position.'" type="number" name="position['.$this->id.']" step="1" max="32000" min="-32000"><br>
			</div>
			<div class="span7 run_unit_dialog"><input type="hidden" value="'.$this->id.'" name="unit_id">'.$dialog.'</div>';
	}
	public function displayForRun($prepend = '')
	{
		return parent::runDialog($prepend,'<i class="icon-puzzle-piece"></i>');
	}
}