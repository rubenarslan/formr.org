<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";
require_once INCLUDE_ROOT. 'Markdown/Michelf/Markdown.php';
use \Michelf\Markdown AS Markdown;

class Page extends RunUnit {
	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	private $body = '';
	private $body_parsed = '';
	private $title = '';
	private $can_be_ended = 1;
	public $ended = false;
	
	public function __construct($fdb, $session = null, $unit = null) 
	{
		parent::__construct($fdb,$session,$unit);

		if($this->id):
			$data = $this->dbh->prepare("SELECT * FROM `survey_pages` WHERE id = :id LIMIT 1");
			$data->bindParam(":id",$this->id);
			$data->execute() or die(print_r($data->errorInfo(), true));
			$vars = $data->fetch(PDO::FETCH_ASSOC);
			
			if($vars):
				$this->body = $vars['body'];
				$this->body_parsed = $vars['body_parsed'];
				$this->title = $vars['title'];
				$this->can_be_ended = $vars['end'] ? 1:0;
		
				$this->valid = true;
			endif;
		endif;
		
		if(!empty($_POST) AND isset($_POST['page_submit']))
		{
			unset($_POST['page_submit']);
			$this->end();
		}
	}
	public function create($options)
	{
		$this->dbh->beginTransaction();
		if(!$this->id)
			$this->id = parent::create('Page');
		else
			$this->modify($this->id);
		
		if(isset($options['body']))
		{
			$this->body = $options['body'];
			$this->title = $options['title'];
			$this->can_be_ended = $options['end'] ? 1:0;
		}
		
		$this->body_parsed = Markdown::defaultTransform($this->body); // transform upon insertion into db instead of at runtime
		
		$create = $this->dbh->prepare("INSERT INTO `survey_pages` (`id`, `body`, `body_parsed`, `title`, `end`)
			VALUES (:id, :body, :body_parsed, :title, :end)
		ON DUPLICATE KEY UPDATE
			`body` = :body, 
			`body_parsed` = :body_parsed, 
			`title` = :title, 
			`end` = :end
		;");
		$create->bindParam(':id',$this->id);
		$create->bindParam(':body',$this->body);
		$create->bindParam(':body_parsed',$this->body_parsed);
		$create->bindParam(':title',$this->title);
		$create->bindParam(':end',$this->can_be_ended);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();
		$this->valid = true;
		
		return true;
	}
	public function displayForRun($prepend = '')
	{
		$dialog = '<p><label>Title: <br>
			<input type="text" placeholder="Headline" name="title" value="'.$this->title.'"></label></p>
		<p><label>Body: <br>
			<textarea placeholder="You can use Markdown" name="body" rows="4" cols="60" class="span5">'.$this->body.'</textarea></label></p>
		<p><input type="hidden" name="end" value="0"><label><input type="checkbox" name="end" value="1"'.($this->can_be_ended ?' checked ':'').'> allow user to continue after viewing page</label></p>';
		$dialog .= '<p class="btn-group"><a class="btn unit_save" href="ajax_save_run_unit?type=Page">Save.</a>
		<a class="btn unit_test" href="ajax_test_unit?type=Page">Preview</a></p>';
		

		$dialog = $prepend . $dialog;
		
		return parent::runDialog($dialog,'icon-bar-chart icon-1-5x');
	}
	public function removeFromRun($run_id)
	{
		return $this->delete();		
	}
	public function test()
	{
		echo $this->body_parsed;
		if($this->can_be_ended)
		{
			$ret = '<form method="post" accept-charset="utf-8">';
			$ret = '<input type="button" class="btn btn-success" value="Weiter!" name="page_submit">';
			$ret .= '</form>';
			echo $ret;
		}
			
	} 
	public function exec()
	{
		if($this->called_by_cron)
			return true; // never show to the cronjob
		
		if($this->can_be_ended AND $this->ended) return false;
		
		if($this->can_be_ended):
			$action = WEBROOT."{$this->run_name}";
			$ret = '<form action="'.$action.'" method="post" accept-charset="utf-8">';
			$ret .= '<input type="submit" class="btn btn-success" value="Weiter!" name="page_submit">';
			$ret .= '</form>';
			$this->body_parsed .= $ret;
		endif;
		
		return array(
			'title' => $this->title,
			'body' => $this->body_parsed
		);
	}
}