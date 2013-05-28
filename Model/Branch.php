<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";

class Branch extends RunUnit {
	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	private $condition = '';
	private $if_true = '';
	private $if_false = '';
	
	public function __construct($fdb, $session = null, $unit = null) 
	{
		parent::__construct($fdb,$session,$unit);

		if($this->id):
			$data = $this->dbh->prepare("SELECT * FROM `survey_branches` WHERE id = :id LIMIT 1");
			$data->bindParam(":id",$this->id);
			$data->execute() or die(print_r($data->errorInfo(), true));
			$vars = $data->fetch(PDO::FETCH_ASSOC);
			
			if($vars):
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
			$this->condition = $options['condition'];
			$this->if_true = $options['if_true'];
			$this->if_false = $options['if_false'];
		}
		
		$create = $this->dbh->prepare("INSERT INTO `survey_branches` (`id`, `condition`, if_true, if_false)
			VALUES (:id, :condition, :if_true, :if_false)
		ON DUPLICATE KEY UPDATE
			`condition` = :condition, 
			`if_true` = :if_true, 
			`if_false` = :if_false
		;");
		$create->bindParam(':id',$this->id);
		$create->bindParam(':condition',$this->condition);
		$create->bindParam(':if_true',$this->if_true);
		$create->bindParam(':if_false',$this->if_false);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();
		$this->valid = true;
		
		return true;
	}
	public function displayForRun($prepend = '')
	{
		$dialog = '<p><label>Condition: <br>
			<textarea name="condition" rows="4" class="span5">'.$this->condition.'</textarea></label></p>
		<div class="row"><p class="span2"><label>…if this evaluates to <code>true</code> <i class="icon-hand-right"></i> <input type="number" class="span1" name="if_true" max="127" min="-127" step="1" value="'.$this->if_true.'"></p>
		<p class="span1"><i class="icon-code-fork icon-flip-vertical icon-4x icon-muted"></i></p>
		<p class="span2"><label>…if this evaluates to <code>false</code> <i class="icon-hand-right"></i> <input type="number" class="span1" name="if_false" max="127" min="-127" step="1" value="'.$this->if_false.'"></p></div>';
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
		$join = join_builder($this->dbh, $this->condition);
$q = "SELECT DISTINCT ( {$this->condition} ) AS test,`survey_run_sessions`.session FROM `survey_run_sessions`

$join

WHERE 
	`survey_run_sessions`.run_id = :run_id

ORDER BY IF(ISNULL(test),1,0), RAND()

LIMIT 20";

		echo "<pre>$q</pre>";
		$evaluate = $this->dbh->prepare($q); // should use readonly
		$evaluate->bindParam(':run_id',$this->run_id);

		$evaluate->execute() or die(print_r($evaluate->errorInfo(), true));
		if($evaluate->rowCount()>=1):
			$results = array();
			while($temp = $evaluate->fetch())
				$results[] = $temp;
		else:
			echo 'Nothing found';
			return false;
		endif;
		
		echo '<table class="table table-striped">
				<thead><tr>
					<th>Code</th>
					<th>Test</th>
				</tr></thead>
				<tbody>"';
		foreach($results AS $row):
			echo "<tr>
					<td><small>{$row['session']}</small></td>
					<td>".h((int)$row['test'])."</td>
				</tr>";
		endforeach;
		echo '</tbody></table>';
	} 
	public function exec()
	{
		$join = join_builder($this->dbh, $this->condition);
		$q = "SELECT ( {$this->condition} ) AS test FROM `survey_run_sessions`
		
		$join
		
		WHERE 
		`survey_run_sessions`.`id` = :run_session_id

		ORDER BY IF(ISNULL( ( {$this->condition} ) ),1,0), `survey_unit_sessions`.id DESC
		
		LIMIT 1";
		
#		pr($q);
		$evaluate = $this->dbh->prepare($q); // should use readonly
		$evaluate->bindParam(":run_session_id", $this->run_session_id);

		$evaluate->execute() or die(print_r($evaluate->errorInfo(), true));
		if($evaluate->rowCount()===1):
			$temp = $evaluate->fetch();
			$result = (bool)$temp['test'];
		else:
			$result = false;
		endif;
#		pr($temp);
#		pr($this->run_session_id);
		
		// evaluate condition
		$position = $result ? $this->if_true : $this->if_false;
#		$run_to_id = $this->getUnitIdAtPosition( $run_to  );
		
#		$run_session = new RunSession($this->dbh, $this->run_id, $this->user_id, $this->session);
		global $run_session;
		if($run_session->session):
			$this->end();
			$run_session->runTo($position);
		endif;
		
		return false;
	}
}