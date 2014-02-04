<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";

class Branch extends RunUnit {
	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	protected $condition = null;
	protected $if_true = null;
	protected $automatically_jump = 1;
	protected $automatically_go_on = 1;
	public $type = 'Branch';
	public $icon = 'fa-code-fork fa-flip-vertical';
	
	public function __construct($fdb, $session = null, $unit = null) 
	{
		parent::__construct($fdb,$session,$unit);

		if($this->id):
			$data = $this->dbh->prepare("SELECT `id`, `condition`, `if_true`, `automatically_jump`, `automatically_go_on` FROM `survey_branches` WHERE id = :id LIMIT 1");
			$data->bindParam(":id",$this->id);
			$data->execute() or die(print_r($data->errorInfo(), true));
			$vars = $data->fetch(PDO::FETCH_ASSOC);
			
			if($vars):
				array_walk($vars,"emptyNull");
				$this->condition = $vars['condition'];
				$this->if_true = $vars['if_true'];
				$this->automatically_jump = $vars['automatically_jump'];
				$this->automatically_go_on = $vars['automatically_go_on'];
		
				$this->valid = true;
			endif;
		endif;
	}
	public function create($options)
	{
		$this->dbh->beginTransaction();
		if(!$this->id)
			$this->id = parent::create($this->type);
		else
			$this->modify($this->id);
		
		if(isset($options['condition']))
		{
			array_walk($options,"emptyNull");
			$this->condition = $options['condition'];
			if(isset($options['if_true']))
				$this->if_true = $options['if_true'];
			if(isset($options['automatically_jump']))
				$this->automatically_jump = $options['automatically_jump'];
			if(isset($options['automatically_go_on']))
				$this->automatically_go_on = $options['automatically_go_on'];
			
		}
		$this->condition = cr2nl($this->condition);
		
		$create = $this->dbh->prepare("INSERT INTO `survey_branches` (`id`, `condition`, if_true, automatically_jump, automatically_go_on)
			VALUES (:id, :condition, :if_true, :automatically_jump, :automatically_go_on)
		ON DUPLICATE KEY UPDATE
			`condition` = :condition2, 
			`if_true` = :if_true2,
			`automatically_jump` = :automatically_jump2, 
			`automatically_go_on` = :automatically_go_on2
		;");
		$create->bindParam(':id',$this->id);
		$create->bindParam(':condition',$this->condition);
		$create->bindParam(':condition2',$this->condition);
		$create->bindParam(':if_true',$this->if_true);
		$create->bindParam(':if_true2',$this->if_true);
		$create->bindParam(':automatically_jump',$this->automatically_jump);
		$create->bindParam(':automatically_jump2',$this->automatically_jump);
		$create->bindParam(':automatically_go_on',$this->automatically_go_on);
		$create->bindParam(':automatically_go_on2',$this->automatically_go_on);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();
		$this->valid = true;
		
		return true;
	}
	public function displayForRun($prepend = '')
	{
		$dialog = '<div class="padding-below">
			<label>if… <br>
				<textarea data-editor="r" class="form-control" style="width:350px" name="condition" rows="4" class="col-md-5" placeholder="Condition: You can use R here: survey1$item2 == 2">'.$this->condition.'</textarea>
			</label><br>
			<select style="width:120px" name="automatically_jump">
			<option value="1" '.($this->automatically_jump?'selected':'').'>automatically</option>
			<option value="0" '.($this->automatically_jump?'':'selected').'>if user reacts</option>
			</select>
			<label>skip forward to
			<input type="number" class="form-control" style="width:70px" name="if_true" max="32000" min="'.($this->position+2).'" step="1" value="'.$this->if_true.'">
			</label><br>
			<strong>else</strong>
			<select style="width:120px" name="automatically_go_on">
			<option value="1" '.($this->automatically_go_on?'selected':'').'>automatically</option>
			<option value="0" '.($this->automatically_go_on?'':'selected').'>if user reacts</option>
			</select>
			<strong>go on</strong>
		</div>';
		$dialog .= '<p class="btn-group">
				<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=SkipForward">Save.</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=SkipForward">Test</a></p>';
		

		$dialog = $prepend . $dialog;
		
		return parent::runDialog($dialog);
	}
	public function removeFromRun($run_id)
	{
		return $this->delete();
	}
	public function test()
	{
		$q = "SELECT `survey_run_sessions`.session,`survey_run_sessions`.id,`survey_run_sessions`.position FROM `survey_run_sessions`

		WHERE 
			`survey_run_sessions`.run_id = :run_id

		ORDER BY `survey_run_sessions`.position DESC,RAND()

		LIMIT 20";
		$get_sessions = $this->dbh->prepare($q); // should use readonly
		$get_sessions->bindParam(':run_id',$this->run_id);

		$get_sessions->execute() or die(print_r($get_sessions->errorInfo(), true));
		if($get_sessions->rowCount()>=1):
			$results = array();
			while($temp = $get_sessions->fetch())
				$results[] = $temp;
		else:
			echo 'No data to compare to yet.';
			return false;
		endif;

		$openCPU = $this->makeOpenCPU();
		$this->run_session_id = current($results)['id'];

		$openCPU->addUserData($this->getUserDataInRun(
			$this->dataNeeded($this->dbh,$this->condition)
		));
		echo $openCPU->evaluateAdmin($this->condition);

		echo '<table class="table table-striped">
				<thead><tr>
					<th>Code (Position)</th>
					<th>Test</th>
				</tr></thead>
				<tbody>"';
		foreach($results AS $row):
			$openCPU = $this->makeOpenCPU();
			$this->run_session_id = $row['id'];

			$openCPU->addUserData($this->getUserDataInRun(
				$this->dataNeeded($this->dbh,$this->condition)
			));

			echo "<tr>
					<td style='word-wrap:break-word;max-width:150px'><small>".$row['session']." ({$row['position']})</small></td>
					<td>".stringBool($openCPU->evaluate($this->condition) )."</td>
				</tr>";
		endforeach;
		echo '</tbody></table>';
		$this->run_session_id = null;
	}
	public function exec()
	{
		$openCPU = $this->makeOpenCPU();

		$openCPU->addUserData($this->getUserDataInRun(
			$this->dataNeeded($this->dbh,$this->condition)
		));
		$result = (bool)$openCPU->evaluate($this->condition);
				
		 // if condition is true and we're set to jump automatically, or if the user reacted
		if($result AND ($this->automatically_jump OR !$this->called_by_cron)):
			global $run_session;
			if($run_session->session):
				$this->end();
				$run_session->runTo($this->if_true);
			endif;
		elseif(!$result AND ($this->automatically_go_on OR !$this->called_by_cron)): // the condition is false and it goes on
			$this->end();
			return false;
		else: // we wait for the condition to turn true or false, depends.
			return true;
		endif;
	}
}