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
			<textarea name="condition" rows="4" cols="60" style="width:399px">'.$this->condition.'</textarea></label></p>
		<p><label>Go to <input type="number" style="width:30px" name="if_true" max="127" min="-127" step="1" value="'.$this->if_true.'"> if this evaluates to <code>true</code>.</p><br><br>
		<p><label>Go to <input type="number" style="width:30px" name="if_false" max="127" min="-127" step="1" value="'.$this->if_false.'"> if this evaluates to <code>false</code>.</p>';
		$dialog .= '<p><a class="btn unit_save" href="ajax_save_run_unit?type=Branch">Save.</a></p>';
		$dialog .= '<p><a class="btn unit_test" href="ajax_test_unit?type=Branch">Test.</a></p>';
		

		$dialog = $prepend . $dialog;
		
		return parent::runDialog($dialog,'icon-code-fork icon-flip-vertical');
	}
	public function removeFromRun($run_id)
	{
		return $this->delete();		
	}
	public function getUnitIdAtPosition($position)
	{
		$data = $this->dbh->prepare("SELECT unit_id FROM `survey_run_units` WHERE position = :position LIMIT 1");
		$data->bindParam(":position",$position);
		$data->execute() or die(print_r($data->errorInfo(), true));
		$vars = $data->fetch(PDO::FETCH_ASSOC);
		if($vars)
			return $vars['unit_id'];
		return false;
	}
	public function test()
	{
		$join = join_builder($this->dbh, $this->condition);
$q = "SELECT DISTINCT ( {$this->condition} ) AS test,`survey_unit_sessions`.session FROM `survey_unit_sessions`

$join

ORDER BY RAND()
LIMIT 10";
		
		echo "<pre>$q</pre>";
		$evaluate = $this->dbh->prepare($q); // should use readonly

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
		$q = "SELECT ( {$this->condition} ) AS test FROM `survey_unit_sessions`
		
		$join
		
		WHERE 
		`survey_unit_sessions`.`session` = :session
		LIMIT 1";
		
		pr($q);
		$evaluate = $this->dbh->prepare($q); // should use readonly
		$evaluate->bindParam(":session", $this->session);

		$evaluate->execute() or die(print_r($evaluate->errorInfo(), true));
		if($evaluate->rowCount()===1):
			$temp = $evaluate->fetch();
			$result = (bool)$temp['test'];
		else:
			$result = false;
		endif;
		
		// evaluate condition
		$goto = $result ? $this->if_true : $this->if_false;
		$goto_id = $this->getUnitIdAtPosition( $goto  );
		
		$session = new UnitSession($this->dbh, $this->session, $goto_id);
		if(!$session->session):
			$session->create($this->session);
		endif;
		$this->end();
		
		return false;
	}
}