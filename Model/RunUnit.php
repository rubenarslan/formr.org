<?php
require_once INCLUDE_ROOT."Model/DB.php";

class RunUnit {
	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	
	public function __construct($fdb, $session = null, $unit = null) 
	{
		$this->dbh = $fdb;
		$this->session = $session;
		$this->unit = $unit;
	}
	public function create($type)
	{
		$c_unit = $this->dbh->prepare("INSERT INTO `survey_units` 
			SET type = :type ;");
		$c_unit->bindParam(':type', $type);
		
		$c_unit->execute() or die(print_r($c_unit->errorInfo(), true));
		
		return $this->dbh->lastInsertId();
	}
	public function addToRun($run_id, $position = 1)
	{
		$d_run_unit = $this->dbh->prepare("INSERT INTO `survey_run_units` SET unit_id = :id, run_id = :run_id;");
		$d_run_unit->bindParam(':id', $this->id);
		$d_run_unit->bindParam(':run_id', $run_id);
		$d_run_unit->execute() or $this->errors = $d_run_unit->errorInfo();
		
		return $d_run_unit->rowCount();
	}
	public function removeFromRun($run_id)
	{
		$d_run_unit = $this->dbh->prepare("DELETE FROM `survey_run_units` WHERE unit_id = :id AND run_id = :run_id;");
		$d_run_unit->bindParam(':id', $this->id);
		$d_run_unit->bindParam(':run_id', $run_id);
		$d_run_unit->execute() or $this->errors = $d_run_unit->errorInfo();
		
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
	public function end()
	{
		$finish_unit = $this->dbh->prepare("UPDATE `survey_unit_sessions` SET `ended` = NOW()
			WHERE 
			`id` = :session_id AND 
			`unit_id` = :unit_id AND 
			`ended` IS NULL
		LIMIT 1;");
		$finish_unit->bindParam(":session_id", $this->session->id);
		$finish_unit->bindParam(":unit_id", $this->id);
		$finish_unit->execute() or die(print_r($finish_unit->errorInfo(), true));
		if($finish_unit->rowCount() === 1):
			return true;
		else:
			return false;
		endif;
	}
	public function runDialog($dialog,$icon = NULL,$position = NULL)
	{
		if($position == null AND isset($this->unit))
			$position = $unit['position'];
		
		return '
		<div class="run_unit row">
			<div>
				<div class="span1 run_unit_position">
					<input type="hidden" value="'.$this->id.'" name="unit_id">
					<input value="'.(($position!==NULL)?$position:'').'" style="width:53px" type="number" name="position" class="position" step="1" max="127" min="-127"><br>
					<div class="btn-group">
						<a href="ajax_remove_unit_from_run" class="remove_unit_from_run btn btn-small hastooltip" title="Remove unit from run"><i class="icon-remove"></i> Remove</a>
					</div>
				</div>
				<div class="span1 run_unit_icon">'.(($icon!==NULL)?$icon:'').'</div>
			</div>
			<div class="span7 run_unit_dialog">'.$dialog.'</div>
		</div>';
	}
	public function displayForRun($position = null,$prepend = '')
	{
		return parent::runDialog($prepend,'<i class=""></i>',$position);
	}
}