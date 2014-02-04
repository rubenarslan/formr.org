<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";
#require_once INCLUDE_ROOT. 'vendor/michelf/php-markdown/Michelf/Markdown.php';
require_once INCLUDE_ROOT . "vendor/erusev/parsedown/Parsedown.php";

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
	public $type = "Pause";
	public $icon = "fa-pause";
	
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
			$this->id = parent::create($this->type);
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
		
		$this->body_parsed = Parsedown::instance()
    ->set_breaks_enabled(true)
    ->parse($this->body); // transform upon insertion into db instead of at runtime
		
		$create = $this->dbh->prepare("INSERT INTO `survey_pauses` (`id`, `body`, `body_parsed`, `wait_until_time`, `wait_until_date` , `wait_minutes`, `relative_to`)
			VALUES (:id, :body, :body_parsed, :wait_until_time, :wait_until_date, :wait_minutes, :relative_to)
		ON DUPLICATE KEY UPDATE
			`body` = :body2, 
			`body_parsed` = :body_parsed2, 
			`wait_until_time` = :wait_until_time2, 
			`wait_until_date` = :wait_until_date2, 
			`wait_minutes` = :wait_minutes2, 
			`relative_to` = :relative_to2
		;");
		$create->bindParam(':id',$this->id);
		$create->bindParam(':body',$this->body);
		$create->bindParam(':body_parsed',$this->body_parsed);
		$create->bindParam(':wait_until_time',$this->wait_until_time);
		$create->bindParam(':wait_until_date',$this->wait_until_date);
		$create->bindParam(':wait_minutes',$this->wait_minutes);
		$create->bindParam(':relative_to',$this->relative_to);
		$create->bindParam(':body2',$this->body);
		$create->bindParam(':body_parsed2',$this->body_parsed);
		$create->bindParam(':wait_until_time2',$this->wait_until_time);
		$create->bindParam(':wait_until_date2',$this->wait_until_date);
		$create->bindParam(':wait_minutes2',$this->wait_minutes);
		$create->bindParam(':relative_to2',$this->relative_to);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();
		$this->valid = true;
		
		return true;
	}
	public function displayForRun($prepend = '')
	{
		$dialog = '<p>
				
				<label class="inline hastooltip" title="Leave empty so that this does not apply">wait until time: 
				<input style="width:200px" class="form-control" type="time" placeholder="daybreak" name="wait_until_time" value="'.$this->wait_until_time.'">
				</label> <strong>and</strong>
				
				</p>
				<p>
				<label class="inline hastooltip" title="Leave empty so that this does not apply">wait until date: 
				<input style="width:200px" class="form-control" type="date" placeholder="the next day" name="wait_until_date" value="'.$this->wait_until_date.'">
				</label> <strong>and</strong>
				
				</p>
				<p class="well well-sm">
					<span class="input-group">
						<input class="form-control" type="number" placeholder="wait this many minutes" name="wait_minutes" value="'.$this->wait_minutes.'">
				        <span class="input-group-btn">
					
							<button class="btn btn-default from_days hastooltip" title="Enter a number of days and press this button to convert them to minutes (*60*24)"><small>convert days</small></button>
						</span>
					</span>
					
				 <label class="inline">relative to 
					<input class="form-control" type="text" placeholder="survey1$created" name="relative_to" value="'.$this->relative_to.'">
					</label
				</p> 
		<p><label>Text to show while waiting: <br>
			<textarea data-editor="markdown" class="form-control" placeholder="You can use Markdown" name="body" rows="10" style="width:350px">'.$this->body.'</textarea>
		</label></p>
			';
		$dialog .= '<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Pause">Save.</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=Pause">Test</a></p>';
		

		$dialog = $prepend . $dialog;
		
		return parent::runDialog($dialog,'fa-pause');
	}
	public function removeFromRun($run_id)
	{
		return $this->delete();		
	}
	public function test()
	{
		echo "<h3>Pause message</h3>";
		
		echo $this->getParsedBodyAdmin($this->body);
		
		echo "<h3>Pause relative to</h3>";
		
		if($this->relative_to=== null OR trim($this->relative_to)=='')
		{
			$this->relative_to = 'survey_unit_sessions$created';
		}
		
		
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
			$this->dataNeeded($this->dbh,$this->relative_to)
		));

		echo $openCPU->evaluateAdmin($this->relative_to);
		
		echo '<table class="table table-striped">
				<thead><tr>
					<th>Code</th>
					<th>Relative to</th>
					<th>Test</th>
				</tr></thead>
				<tbody>"';
				
		foreach($results AS $row):
			$conditions = array();
		
			$wait_minutes_true = !($this->wait_minutes === null OR trim($this->wait_minutes)=='');
			$relative_to_true = !($this->relative_to === null OR trim($this->relative_to)=='');
		
			// disambiguate what user meant
			if($wait_minutes_true AND !$relative_to_true):  // user said wait minutes relative to, implying a relative to
				$this->relative_to = 'tail(na.omit(survey_unit_sessions$created),1)'; // we take this as implied
				$relative_to_true = true;
			endif;
		
			if($relative_to_true): // if a relative_to has been defined by user or automatically, we need to retrieve its value
				$openCPU = $this->makeOpenCPU();
				$this->run_session_id = $row['id'];

				$openCPU->addUserData($this->getUserDataInRun(
					$this->dataNeeded($this->dbh,$this->relative_to)
				));
		
				$relative_to = $openCPU->evaluate($this->relative_to);
			endif;
		
			if(!$wait_minutes_true AND $relative_to_true): // if no wait minutes but a relative to was defined, we just use this as the param (useful for complex R expressions)
				$conditions['relative_to'] = ":relative_to <= NOW()";
			elseif($wait_minutes_true): 		// if a wait minutes was defined by user, we need to add it's condition
				$conditions['minute'] = "DATE_ADD(:relative_to, INTERVAL :wait_minutes MINUTE) <= NOW()";
			endif;
		
			if($this->wait_until_date AND $this->wait_until_date != '0000-00-00'):
				$conditions['date'] = "CURDATE() >= :wait_date";
			endif;
			if($this->wait_until_time AND $this->wait_until_time != '00:00:00'):
				$conditions['time'] = "CURTIME() >= :wait_time";
			endif;
			
			if(!empty($conditions)):
				$condition = implode($conditions," AND ");
		
				$order = str_replace(array(':wait_minutes',':wait_date',':wait_time',':relative_to'),array(':wait_minutes2',':wait_date2',':wait_time2',':relative_to2'),$condition);
		
	$q = "SELECT DISTINCT ( {$condition} ) AS test,`survey_run_sessions`.session FROM `survey_run_sessions`

		left join `survey_unit_sessions`
			on `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id

	WHERE 
		`survey_run_sessions`.id = :run_session_id

	ORDER BY IF(ISNULL($order),1,0), RAND()

	LIMIT 1";
		
				$evaluate = $this->dbh->prepare($q); // should use readonly
				if(isset($conditions['minute'])):
					$evaluate->bindParam(':wait_minutes',$this->wait_minutes);
					$evaluate->bindParam(':wait_minutes2',$this->wait_minutes);
					$evaluate->bindParam(':relative_to',$relative_to);
					$evaluate->bindParam(':relative_to2',$relative_to);
				endif;
				if(isset($conditions['relative_to'])):
					$evaluate->bindParam(':relative_to',$relative_to);
					$evaluate->bindParam(':relative_to2',$relative_to);	
				endif;
				if(isset($conditions['date'])): 
					$evaluate->bindParam(':wait_date',$this->wait_until_date);
					$evaluate->bindParam(':wait_date2',$this->wait_until_date);
				endif;
				if(isset($conditions['time'])): 
					$evaluate->bindParam(':wait_time',$this->wait_until_time);
					$evaluate->bindParam(':wait_time2',$this->wait_until_time);
				endif;
				$evaluate->bindValue(':run_session_id',$this->run_session_id);

				$evaluate->execute() or die(print_r($evaluate->errorInfo(), true));
				if($evaluate->rowCount()===1):
					$temp = $evaluate->fetch();
					$result = $temp['test'];
				endif;
			else:
				$result = true;
			endif;
			
			echo "<tr>
					<td style='word-wrap:break-word;max-width:150px'><small>".$row['session']." ({$row['position']})</small></td>
					<td><small>".stringBool($relative_to )."</small></td>
					<td>".stringBool($result )."</td>
				</tr>";

		endforeach;
		echo '</tbody></table>';
	}
	public function exec()
	{
		$conditions = array();
		
		$wait_minutes_true = !($this->wait_minutes === null OR trim($this->wait_minutes)=='');
		$relative_to_true = !($this->relative_to === null OR trim($this->relative_to)=='');
		
		// disambiguate what user meant
		if($wait_minutes_true AND !$relative_to_true):  // user said wait minutes relative to, implying a relative to
			$this->relative_to = 'tail(na.omit(survey_unit_sessions$created),1)'; // we take this as implied
			$relative_to_true = true;
		endif;
		
		if($relative_to_true): // if a relative_to has been defined by user or automatically, we need to retrieve its value
			$openCPU = $this->makeOpenCPU();

			$openCPU->addUserData($this->getUserDataInRun(
				$this->dataNeeded($this->dbh,$this->relative_to)
			));
		
			$relative_to = $openCPU->evaluate($this->relative_to);
		endif;
		
		if(!$wait_minutes_true AND $relative_to_true): // if no wait minutes but a relative to was defined, we just use this as the param (useful for complex R expressions)
			$conditions['relative_to'] = ":relative_to <= NOW()";
		elseif($wait_minutes_true): 		// if a wait minutes was defined by user, we need to add it's condition
			$conditions['minute'] = "DATE_ADD(:relative_to, INTERVAL :wait_minutes MINUTE) <= NOW()";
		endif;
		
		if($this->wait_until_date AND $this->wait_until_date != '0000-00-00'):
			$conditions['date'] = "CURDATE() >= :wait_date";
		endif;
		if($this->wait_until_time AND $this->wait_until_time != '00:00:00'):
			$conditions['time'] = "CURTIME() >= :wait_time";
		endif;

		if(!empty($conditions)):
			$condition = implode($conditions," AND ");

		$order = str_replace(array(':wait_minutes',':wait_date',':wait_time',':relative_to'),array(':wait_minutes2',':wait_date2',':wait_time2',':relative_to2'),$condition);

	$q = "SELECT ( {$condition} ) AS test FROM `survey_run_sessions`
		left join `survey_unit_sessions`
			on `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
	
	WHERE 
	`survey_run_sessions`.`id` = :run_session_id

	ORDER BY IF(ISNULL( ( {$order} ) ),1,0), `survey_unit_sessions`.id DESC
	
	LIMIT 1";
			$evaluate = $this->dbh->prepare($q); // should use readonly
			if(isset($conditions['minute'])):
				$evaluate->bindParam(':wait_minutes',$this->wait_minutes);
				$evaluate->bindParam(':wait_minutes2',$this->wait_minutes);
				$evaluate->bindParam(':relative_to',$relative_to);
				$evaluate->bindParam(':relative_to2',$relative_to);	
			endif;
			if(isset($conditions['relative_to'])):
				$evaluate->bindParam(':relative_to',$relative_to);
				$evaluate->bindParam(':relative_to2',$relative_to);	
			endif;
			if(isset($conditions['date'])): 
				$evaluate->bindParam(':wait_date',$this->wait_until_date);
				$evaluate->bindParam(':wait_date2',$this->wait_until_date);
			endif;
			if(isset($conditions['time'])): 
				$evaluate->bindParam(':wait_time',$this->wait_until_time);
				$evaluate->bindParam(':wait_time2',$this->wait_until_time);
			endif;
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