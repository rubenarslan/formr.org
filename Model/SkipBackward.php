<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";
require_once INCLUDE_ROOT."Model/Branch.php";

class SkipBackward extends Branch {
	public $type = 'SkipBackward';
	public $icon = 'fa-backward';
	
	public function displayForRun($prepend = '')
	{
		$dialog = '<p>
			<label>if… <br>
				<textarea style="width:388px;" data-editor="r" class="form-control col-md-5" name="condition" rows="4" placeholder="Condition: You can use R here: survey1$item2 == 2">'.$this->condition.'</textarea>
			</label>
		</p>
		<div class="row col-md-12">
			<label>…skip backward to
			<input type="number" class="form-control" style="width:100px" name="if_true" max="'.($this->position-1).'" min="-32000" step="1" value="'.$this->if_true.'">
			</label>
			
		</div>';
		$dialog .= '<p class="btn-group">
				<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=SkipBackward">Save.</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=SkipBackward">Test</a></p>';
		

		$dialog = $prepend . $dialog;
		
		return parent::runDialog($dialog);
	}
	public function exec()
	{
		$openCPU = $this->makeOpenCPU();

		$openCPU->addUserData($this->getUserDataInRun(
			$this->dataNeeded($this->dbh,$this->condition)
		));
		$result = (bool)$openCPU->evaluate($this->condition);
	
		if($result AND $this->if_true >= $this->position): // the condition is true and it skips forward
			global $run_session;
			if($run_session->session):
				$this->end();
				$run_session->runTo($this->if_true);
			endif;
		elseif(!$result AND $this->if_true === $this->position): // the condition is true and it stays here, waits for the user
			return true;
		else: // the condition is false
			$this->end();
		endif;
		
		return false;
	}
}