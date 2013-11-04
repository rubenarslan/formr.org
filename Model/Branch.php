<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";

class Branch extends RunUnit {
	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	private $condition = null;
	private $if_true = null;
	private $if_false = null;
	
	public function __construct($fdb, $session = null, $unit = null) 
	{
		parent::__construct($fdb,$session,$unit);

		if($this->id):
			$data = $this->dbh->prepare("SELECT * FROM `survey_branches` WHERE id = :id LIMIT 1");
			$data->bindParam(":id",$this->id);
			$data->execute() or die(print_r($data->errorInfo(), true));
			$vars = $data->fetch(PDO::FETCH_ASSOC);
			
			if($vars):
				array_walk($vars,"emptyNull");
				$this->condition = $vars['condition'];
				$this->if_true = $vars['if_true'];
				$this->if_false = $vars['if_false'];
		
				$this->valid = true;
			endif;
		endif;
	}
	public function create($options)
	{
		$this->dbh->beginTransaction();
		if(!$this->id)
			$this->id = parent::create('Branch');
		else
			$this->modify($this->id);
		
		if(isset($options['condition']))
		{
			array_walk($options,"emptyNull");
			$this->condition = $options['condition'];
			$this->if_true = $options['if_true'];
			$this->if_false = $options['if_false'];
		}
		
		$create = $this->dbh->prepare("INSERT INTO `survey_branches` (`id`, `condition`, if_true, if_false)
			VALUES (:id, :condition, :if_true, :if_false)
		ON DUPLICATE KEY UPDATE
			`condition` = :condition2, 
			`if_true` = :if_true2, 
			`if_false` = :if_false2
		;");
		$create->bindParam(':id',$this->id);
		$create->bindParam(':condition',$this->condition);
		$create->bindParam(':condition2',$this->condition);
		$create->bindParam(':if_true',$this->if_true);
		$create->bindParam(':if_true2',$this->if_true);
		$create->bindParam(':if_false',$this->if_false);
		$create->bindParam(':if_false2',$this->if_false);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();
		$this->valid = true;
		
		return true;
	}
	public function displayForRun($prepend = '')
	{
		$dialog = '<p><label>Condition: <br>
			<textarea name="condition" rows="4" class="span5">'.$this->condition.'</textarea></label></p>
		<div class="row"><p class="span2"><label>…if this evaluates to <code>true</code> <i class="icon-hand-right"></i> <input type="number" class="span1" name="if_true" max="32000" min="-32000" step="1" value="'.$this->if_true.'"></p>
		<p class="span1"><i class="icon-code-fork icon-flip-vertical icon-4x icon-muted"></i></p>
		<p class="span2"><label>…if this evaluates to <code>false</code> <i class="icon-hand-right"></i> <input type="number" class="span1" name="if_false" max="32000" min="-32000" step="1" value="'.$this->if_false.'"></p></div>';
		$dialog .= '<p class="btn-group"><a class="btn unit_save" href="ajax_save_run_unit?type=Branch">Save.</a>
		<a class="btn unit_test" href="ajax_test_unit?type=Branch">Test</a></p>';
		

		$dialog = $prepend . $dialog;
		
		return parent::runDialog($dialog,'icon-code-fork icon-flip-vertical icon-2-5x');
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
		$join = join_builder($this->dbh, $this->condition);
	
		$position = $result ? $this->if_true : $this->if_false;

		global $run_session;
		if($run_session->session):
			$this->end();
			$run_session->runTo($position);
		endif;
		
		return false;
	}
}