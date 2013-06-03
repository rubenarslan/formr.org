<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";

class TimeBranch extends RunUnit {
	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	private $if_true = '';
	private $if_false = '';
	private $relative_to = null;
	private $wait_minutes = null;
	private $wait_until_time = null;
	private $wait_until_date = null;
	public $ended = false;
	
	public function __construct($fdb, $session = null, $unit = null) 
	{
		parent::__construct($fdb,$session,$unit);

		if($this->id):
			$data = $this->dbh->prepare("SELECT * FROM `survey_time_branches` WHERE id = :id LIMIT 1");
			$data->bindParam(":id",$this->id);
			$data->execute() or die(print_r($data->errorInfo(), true));
			$vars = $data->fetch(PDO::FETCH_ASSOC);
			
			if($vars):
				$this->if_true = $vars['if_true'];
				$this->if_false = $vars['if_false'];
				$this->wait_until_time = $vars['wait_until_time'];
				$this->wait_until_date = $vars['wait_until_date'];
				$this->wait_minutes = $vars['wait_minutes'];
				$this->relative_to = $vars['relative_to'];
		
				$this->valid = true;
			endif;
		endif;
	}
	public function create($options)
	{
		$this->dbh->beginTransaction();
		if(!$this->id)
			$this->id = parent::create('TimeBranch');
		else
			$this->modify($this->id);
		
		if(isset($options['if_true']))
		{
			$this->if_true = $options['if_true'];
			$this->if_false = $options['if_false'];
			$this->wait_until_time = $options['wait_until_time'];
			$this->wait_until_date = $options['wait_until_date'];
			$this->wait_minutes = $options['wait_minutes'];
			$this->relative_to = $options['relative_to'];
		}
		
		$create = $this->dbh->prepare("INSERT INTO `survey_time_branches` (`id`, if_true, if_false, `wait_until_time`, `wait_until_date` , `wait_minutes`, `relative_to`)
			VALUES (:id, :if_true, :if_false, :wait_until_time, :wait_until_date, :wait_minutes, :relative_to)
		ON DUPLICATE KEY UPDATE
			`if_true` = :if_true, 
			`if_false` = :if_false,
			`wait_until_time` = :wait_until_time, 
			`wait_until_date` = :wait_until_date, 
			`wait_minutes` = :wait_minutes, 
			`relative_to` = :relative_to
			
		;");
		$create->bindParam(':id',$this->id);
		$create->bindParam(':if_true',$this->if_true);
		$create->bindParam(':if_false',$this->if_false);
		$create->bindParam(':wait_until_time',$this->wait_until_time);
		$create->bindParam(':wait_until_date',$this->wait_until_date);
		$create->bindParam(':wait_minutes',$this->wait_minutes);
		$create->bindParam(':relative_to',$this->relative_to);
		
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();
		$this->valid = true;
		
		return true;
	}
	public function displayForRun($prepend = '')
	{
		$dialog = '<p>
				<label class="inline hastooltip" title="Leave empty so that this does not apply">until time: 
				<input type="time" placeholder="daybreak" name="wait_until_time" value="'.$this->wait_until_time.'">
				</label> <strong>and</strong>
				
				</p>
				<p>
				<label class="inline hastooltip" title="Leave empty so that this does not apply">until date: 
				<input type="date" placeholder="the next day" name="wait_until_date" value="'.$this->wait_until_date.'">
				</label> <strong>and</strong>
				
				</p>
				<p>
				<span class="input-append">
				<input type="number" class="span2" placeholder="" name="wait_minutes" value="'.$this->wait_minutes.'"><button class="btn from_days hastooltip" title="Enter a number of days and press this button to convert them to minutes (*60*24)"><small>convert days</small></button>
				</span>
				 minutes <label class="inline">relative to 
					<input type="text" class="span2" placeholder="Survey.DateField" name="relative_to" value="'.$this->relative_to.'">
					</label
				</p> 
			';
		$dialog .= '
			<div class="row">
				<p class="span2"><label>…if there <strong>still is time</strong> <br><i class="icon-hand-right"></i> <input type="number" class="span1" name="if_false" max="32000" min="-32000" step="1" value="'.$this->if_false.'"></p>
				<p class="span1"><i class="icon-fast-forward icon-flip-vertical icon-3x icon-muted"></i></p>
				<p class="span2"><label>…if the time is <strong>up</strong> <br><i class="icon-hand-right"></i> <input type="number" class="span1" name="if_true" max="32000" min="-32000" step="1" value="'.$this->if_true.'"></p>
			</div>
			';
			$dialog .= '<p class="btn-group"><a class="btn unit_save" href="ajax_save_run_unit?type=TimeBranch">Save.</a>
			<a class="btn unit_test" href="ajax_test_unit?type=TimeBranch">Test</a></p>';

		$dialog = $prepend . $dialog;
		
		return parent::runDialog($dialog,'icon-fast-forward');
	}
	public function removeFromRun($run_id)
	{
		return $this->delete();
	}
	public function test()
	{
		if($this->relative_to=== null OR trim($this->relative_to)=='')
		{
			$this->relative_to = '`survey_unit_sessions`.created';
		}
		$join = join_builder($this->dbh, $this->relative_to);
		

		$conditions = array();
		
		
		if($this->wait_minutes AND $this->wait_minutes!='')
			$conditions['minute'] = "DATE_ADD({$this->relative_to}, INTERVAL :wait_minutes MINUTE) <= NOW()";
		if($this->wait_until_date AND $this->wait_until_date != '0000-00-00') 
			$conditions['date'] = "CURDATE() >= :wait_date";
		if($this->wait_until_time AND $this->wait_until_time != '00:00:00')
			$conditions['time'] = "CURTIME() >= :wait_time";

		if(isset($conditions['time']) AND !isset($conditions['date']) AND !isset($conditions['minute']))
			$conditions['date'] = "DATE_ADD({$this->relative_to}, INTERVAL 1 DAY) >= CURDATE()";
		
		if(!empty($conditions)):
			$condition = implode($conditions," AND ");
			
$q = "SELECT DISTINCT ( {$condition} ) AS test,`survey_run_sessions`.session FROM `survey_run_sessions`

$join

WHERE 
	`survey_run_sessions`.run_id = :run_id

ORDER BY IF(ISNULL(test),1,0), RAND()

LIMIT 20";
		
			echo "<pre>$q</pre>";
			$evaluate = $this->dbh->prepare($q); // should use readonly
			if(isset($conditions['minute'])) $evaluate->bindParam(':wait_minutes',$this->wait_minutes);
			if(isset($conditions['date'])) $evaluate->bindParam(':wait_date',$this->wait_until_date);
			if(isset($conditions['time'])) $evaluate->bindParam(':wait_time',$this->wait_until_time);
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
		else:
			$result = true;
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
		if($this->relative_to=== null OR trim($this->relative_to)=='')
		{
			$this->relative_to = '`survey_unit_sessions`.created';
		}
		$join = join_builder($this->dbh, $this->relative_to);
		

		
		$conditions = array();
		if($this->wait_minutes AND $this->wait_minutes!='')
			$conditions['minute'] = "DATE_ADD({$this->relative_to}, INTERVAL :wait_minutes MINUTE) <= NOW()";
		if($this->wait_until_date AND $this->wait_until_date != '0000-00-00') 
			$conditions['date'] = "CURDATE() >= :wait_date";
		if($this->wait_until_time AND $this->wait_until_time != '00:00:00')
			$conditions['time'] = "CURTIME() >= :wait_time";

		if(isset($conditions['time']) AND !isset($conditions['date']) AND !isset($conditions['minute']))
			$conditions['date'] = "DATE_ADD({$this->relative_to}, INTERVAL 1 DAY) >= CURDATE()";
		
		if(!empty($conditions)):
			$condition = implode($conditions," AND ");

	$q = "SELECT ( {$condition} ) AS test FROM `survey_run_sessions`
	
	$join
	
	WHERE 
	`survey_run_sessions`.`id` = :run_session_id

	ORDER BY IF(ISNULL( ( {$condition} ) ),1,0), `survey_unit_sessions`.id DESC
	
	LIMIT 1";
#	pr($q);
			$evaluate = $this->dbh->prepare($q); // should use readonly
			if(isset($conditions['minute'])) $evaluate->bindParam(':wait_minutes',$this->wait_minutes);
			if(isset($conditions['date'])) $evaluate->bindParam(':wait_date',$this->wait_until_date);
			if(isset($conditions['time'])) $evaluate->bindParam(':wait_time',$this->wait_until_time);
			$evaluate->bindParam(":run_session_id", $this->run_session_id);
		

			$evaluate->execute() or die(print_r($evaluate->errorInfo(), true));
			if($evaluate->rowCount()===1):
				$temp = $evaluate->fetch();
				$result = (bool)$temp['test'];
			else:
				$result = false;
			endif;
		else:
			$result = true;
		endif;

		$position = $result ? $this->if_true : $this->if_false;
		
		
		if($result OR !$this->called_by_cron):
			global $run_session;
			if($run_session->session):
				$this->end();
				$run_session->runTo($position);
			endif;
		else:
			return true; // if the cron job is knocking and the wait time is not over yet, stop. we're waiting for the real user.
		endif;
	}
}