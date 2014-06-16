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
			$data = $this->dbh->prepare("SELECT id, body, body_parsed, wait_until_time, wait_minutes ,wait_until_date, relative_to FROM `survey_pauses` WHERE id = :id LIMIT 1");
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
				<input style="width:200px" class="form-control" type="time" placeholder="e.g. 12:00" name="wait_until_time" value="'.h($this->wait_until_time).'">
				</label> <strong>and</strong>
				
				</p>
				<p>
				<label class="inline hastooltip" title="Leave empty so that this does not apply">wait until date: 
				<input style="width:200px" class="form-control" type="date" placeholder="e.g. 01.01.2000" name="wait_until_date" value="'.h($this->wait_until_date).'">
				</label> <strong>and</strong>
				
				</p>
				<p class="well well-sm">
					<span class="input-group">
						<input class="form-control" type="number" style="width:230px" placeholder="wait this many minutes" name="wait_minutes" value="'.h($this->wait_minutes).'">
				        <span class="input-group-btn">
							<button class="btn btn-default from_days hastooltip" title="Enter a number of days and press this button to convert them to minutes (*60*24)"><small>convert days</small></button>
						</span>
					</span>
					
				 <label class="inline">relative to 
					<textarea data-editor="r" style="width:368px;" rows="2" class="form-control" placeholder="arriving at this pause" name="relative_to">'.h($this->relative_to).'</textarea>
					</label
				</p> 
		<p><label>Text to show while waiting: <br>
			<textarea style="width:388px;"  data-editor="markdown" class="form-control col-md-5" placeholder="You can use Markdown" name="body" rows="10">'.h($this->body).'</textarea>
		</label></p>
			';
		$dialog .= '<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Pause">Save.</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=Pause">Test</a></p>';
		

		$dialog = $prepend . $dialog;
		
		return parent::runDialog($dialog,'fa-pause');
	}
	public function removeFromRun()
	{
		return $this->delete();		
	}
	private function checkRelativeTo()
	{
		$this->wait_minutes_true = !($this->wait_minutes === null OR trim($this->wait_minutes)=='');
		$this->relative_to_true = !($this->relative_to === null OR trim($this->relative_to)=='');
	
		// disambiguate what user meant
		if($this->wait_minutes_true AND !$this->relative_to_true):  // user said wait minutes relative to, implying a relative to
			$this->relative_to = 'tail(survey_unit_sessions$created,1)'; // we take this as implied, this is the time someone arrived at this pause
			$this->relative_to_true = true;
		endif;
	}
	private function checkWhetherPauseIsOver()
	{
		$conditions = array();
	
		if($this->relative_to_true): // if a relative_to has been defined by user or automatically, we need to retrieve its value
			$openCPU = $this->makeOpenCPU();
			
			$openCPU->clearUserData();

			$openCPU->addUserData($this->getUserDataInRun(
				$this->dataNeeded($this->dbh,$this->relative_to)
			));
	
			$this->relative_to_result = $relative_to = $openCPU->evaluate($this->relative_to);
		endif;
	
		$bind_relative_to = false;
	
		if(!$this->wait_minutes_true AND $this->relative_to_true): // if no wait minutes but a relative to was defined, we just use this as the param (useful for complex R expressions)
			if($relative_to === true):
				$conditions['relative_to'] = "1=1";
			elseif($relative_to === false):
				$conditions['relative_to'] = "0=1";
			elseif(strtotime($relative_to)):
				$conditions['relative_to'] = ":relative_to <= NOW()";
				$bind_relative_to = true;
			else:
				alert("Pause {$this->position}: Relative to yields neither true nor false, nor a date, nor a time. ". print_r($relative_to,true), 'alert-warning');
				return false;
			endif;
		elseif($this->wait_minutes_true): 		// if a wait minutes was defined by user, we need to add its condition
			if(strtotime($relative_to)):
				$conditions['minute'] = "DATE_ADD(:relative_to, INTERVAL :wait_minutes MINUTE) <= NOW()";
				$bind_relative_to = true;
			else:
				alert("Pause {$this->position}: Relative to yields neither true nor false, nor a date, nor a time. ". print_r($relative_to,true), 'alert-warning');
				return false;
			endif;
		endif;
	
		if($this->wait_until_date AND $this->wait_until_date != '0000-00-00'):
			$conditions['date'] = "CURDATE() >= :wait_date";
		endif;
		if($this->wait_until_time AND $this->wait_until_time != '00:00:00'):
			$conditions['time'] = "CURTIME() >= :wait_time";
		endif;
		
		if(!empty($conditions)):
			$condition = implode($conditions," AND ");

			$q = "SELECT ( {$condition} ) AS test LIMIT 1";
			
			$evaluate = $this->dbh->prepare($q); // should use readonly
			if(isset($conditions['minute'])):
				$evaluate->bindValue(':wait_minutes',$this->wait_minutes);
			endif;
			if($bind_relative_to):
				$evaluate->bindValue(':relative_to',$relative_to);
			endif;
		
			if(isset($conditions['date'])): 
				$evaluate->bindValue(':wait_date',$this->wait_until_date);
			endif;
			if(isset($conditions['time'])): 
				$evaluate->bindValue(':wait_time',$this->wait_until_time);
			endif;
		
			$evaluate->execute() or die(print_r($evaluate->errorInfo(), true));
			if($evaluate->rowCount()===1):
				$temp = $evaluate->fetch();
				$result = $temp['test'];
			endif;
		else:
			$result = true;
		endif;
		
		return $result;
	}
	public function test()
	{
		if(!$this->knittingNeeded($this->body))
		{
			echo "<h3>Pause message</h3>";
			echo $this->getParsedBodyAdmin($this->body);
		}
		
		// fetch a couple of sample session
		$q = "SELECT `survey_run_sessions`.session,`survey_run_sessions`.id,`survey_run_sessions`.position FROM `survey_run_sessions`

		WHERE 
			`survey_run_sessions`.run_id = :run_id

		ORDER BY `survey_run_sessions`.position DESC,RAND()

		LIMIT 20"; // start with those who are the furthest, because they are most likely to have all the necessary data
		$get_sessions = $this->dbh->prepare($q); // should use readonly
		$get_sessions->bindParam(':run_id',$this->run_id);

		$get_sessions->execute() or die(print_r($get_sessions->errorInfo(), true));
		if($get_sessions->rowCount() > 0):
			$results = array();
			while($temp = $get_sessions->fetch())
				$results[] = $temp;
		else:
			echo 'No data to compare to yet.';
			return false;
		endif;
		
		if($this->knittingNeeded($this->body))
		{
			echo "<h3>Pause message</h3>";
			echo $this->getParsedBodyAdmin($this->body);
		}
		if($this->checkRelativeTo())
		{
			// take the first sample session
			$this->run_session_id = current($results)['id'];
			echo "<h3>Pause relative to</h3>";
	
			$openCPU->addUserData($this->getUserDataInRun(
				$this->dataNeeded($this->dbh,$this->relative_to)
			));
	
			echo $openCPU->evaluateAdmin($this->relative_to);
		}
		if(!empty($results))
		{

			echo '<table class="table table-striped">
					<thead><tr>
						<th>Code</th>';
			if($this->relative_to_true) echo '<th>Relative to</th>';
			echo '<th>Test</th>
					</tr></thead>
					<tbody>';
		
			foreach($results AS $row):
				$this->run_session_id = $row['id'];
			
				$result = $this->checkWhetherPauseIsOver();
				echo "<tr>
						<td style='word-wrap:break-word;max-width:150px'><small>".$row['session']." ({$row['position']})</small></td>";
				if($this->relative_to_true) echo  "<td><small>".stringBool($this->relative_to_result )."</small></td>";
				echo	"<td>".stringBool($result )."</td>
					</tr>";

			endforeach;
			echo '</tbody></table>';
		}
		
	}
	public function exec()
	{
		$this->checkRelativeTo();
		if($this->checkWhetherPauseIsOver())
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