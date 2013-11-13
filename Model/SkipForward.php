<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";
require_once INCLUDE_ROOT."Model/Branch.php";

class SkipForward extends Branch {
	public $type = 'SkipForward';
	public $icon = 'fa-forward';
	
	public function displayForRun($prepend = '')
	{
		$dialog = '<div class="padding-below">
			<label>if… <br>
				<textarea class="form-control" style="width:350px" name="condition" rows="4" class="col-md-5" placeholder="Condition: You can use R here: survey1$item2 == 2">'.$this->condition.'</textarea>
			</label><br>
			<select style="width:120px">
			<option>automatically</option>
			<option>if user reacts</option>
			</select>
			<label>skip forward to
			<input type="number" class="form-control" style="width:70px" name="if_true" max="32000" min="'.$this->position.'" step="1" value="'.$this->if_true.'">
			</label><br>
			<strong>else</strong>
			<select style="width:120px">
			<option>automatically</option>
			<option>if user reacts</option>
			</select>
			<strong>go on</strong>
		</div>';
		$dialog .= '<p class="btn-group">
				<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=SkipForward">Save.</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=SkipForward">Test</a></p>';
		

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
		$join = join_builder($this->dbh, $this->condition);
	
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