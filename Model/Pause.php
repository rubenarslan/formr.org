<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";
require_once INCLUDE_ROOT. 'Markdown/Michelf/Markdown.php';
use \Michelf\Markdown AS Markdown;

class Pause extends RunUnit {
	
	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	private $body = '';
	protected $body_parsed = '';
	private $relative_to = null;
	private $wait_minutes = null;
	private $wait_until_time = null;
	private $wait_until_date = null;
	public $ended = false;
	
	public function __construct($fdb, $session = null, $unit = null) 
	{
		parent::__construct($fdb,$session,$unit);

		if($this->id):
			$data = $this->dbh->prepare("SELECT * FROM `survey_pauses` WHERE id = :id LIMIT 1");
			$data->bindParam(":id",$this->id);
			$data->execute() or die(print_r($data->errorInfo(), true));
			$vars = $data->fetch(PDO::FETCH_ASSOC);
			
			if($vars):
				array_walk($vars,"emptyNull");
				$this->body = $vars['body'];
				$this->body_parsed = $vars['body_parsed'];
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
			$this->id = parent::create('Pause');
		else
			$this->modify($this->id);
		
		if(isset($options['body']))
		{
			array_walk($options,"emptyNull");
			$this->body = $options['body'];
			$this->wait_until_time = $options['wait_until_time'];
			$this->wait_until_date = $options['wait_until_date'];
			$this->wait_minutes = $options['wait_minutes'];
			$this->relative_to = $options['relative_to'];
		}
		
		$this->body_parsed = Markdown::defaultTransform($this->body); // transform upon insertion into db instead of at runtime
		
		$create = $this->dbh->prepare("INSERT INTO `survey_pauses` (`id`, `body`, `body_parsed`, `wait_until_time`, `wait_until_date` , `wait_minutes`, `relative_to`)
			VALUES (:id, :body, :body_parsed, :wait_until_time, :wait_until_date, :wait_minutes, :relative_to)
		ON DUPLICATE KEY UPDATE
			`body` = :body, 
			`body_parsed` = :body_parsed, 
			`wait_until_time` = :wait_until_time, 
			`wait_until_date` = :wait_until_date, 
			`wait_minutes` = :wait_minutes, 
			`relative_to` = :relative_to
		;");
		$create->bindParam(':id',$this->id);
		$create->bindParam(':body',$this->body);
		$create->bindParam(':body_parsed',$this->body_parsed);
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
				<label class="inline hastooltip" title="Leave empty so that this does not apply">wait until time: 
				<input type="time" placeholder="daybreak" name="wait_until_time" value="'.$this->wait_until_time.'">
				</label> <strong>and</strong>
				
				</p>
				<p>
				<label class="inline hastooltip" title="Leave empty so that this does not apply">wait until date: 
				<input type="date" placeholder="the next day" name="wait_until_date" value="'.$this->wait_until_date.'">
				</label> <strong>and</strong>
				
				</p>
				<p>wait
				<span class="input-append">
				<input type="number" class="span2" placeholder="" name="wait_minutes" value="'.$this->wait_minutes.'"><button class="btn from_days hastooltip" title="Enter a number of days and press this button to convert them to minutes (*60*24)"><small>convert days</small></button>
				</span>
				 minutes <label class="inline">relative to 
					<input type="text" class="span2" placeholder="Survey.DateField" name="relative_to" value="'.$this->relative_to.'">
					</label
				</p> 
		<p><label>Message to show while waiting: <br>
			<textarea placeholder="You can use Markdown" name="body" rows="4" cols="60" class="span5">'.$this->body.'</textarea></label></p>
			';
		$dialog .= '<p class="btn-group"><a class="btn unit_save" href="ajax_save_run_unit?type=Pause">Save.</a>
		<a class="btn unit_test" href="ajax_test_unit?type=Pause">Test</a></p>';
		

		$dialog = $prepend . $dialog;
		
		return parent::runDialog($dialog,'icon-time');
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
		
		echo "<h2>Pause message</h2>";
		
		echo $this->getParsedBodyAdmin($this->body);
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

		if($result)
		{
			$this->end();
			return false;
		}
		else
		{
			return array(
				'title' => 'Pause',
				'body' => $this->getParsedBody($this->body)
			);
		}	
	}
	
}