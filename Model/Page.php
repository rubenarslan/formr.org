<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";
#require_once INCLUDE_ROOT. 'vendor/michelf/php-markdown/Michelf/Markdown.php';
require_once INCLUDE_ROOT . "vendor/erusev/parsedown/Parsedown.php";

class Page extends RunUnit {
	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	private $body = '';
	protected $body_parsed = '';
	private $title = '';
	private $can_be_ended = 0;
	public $ended = false;
	public $type = 'Endpage';
	public $icon = "fa-stop";
	
	
	public function __construct($fdb, $session = null, $unit = null) 
	{
		parent::__construct($fdb,$session,$unit);

		if($this->id):
			$data = $this->dbh->prepare("SELECT title,body,body_parsed FROM `survey_pages` WHERE id = :id LIMIT 1");
			$data->bindParam(":id",$this->id);
			$data->execute() or die(print_r($data->errorInfo(), true));
			$vars = $data->fetch(PDO::FETCH_ASSOC);
			
			if($vars):
				$this->body = $vars['body'];
				$this->body_parsed = $vars['body_parsed'];
				$this->title = $vars['title'];
#				$this->can_be_ended = $vars['end'] ? 1:0;
				$this->can_be_ended = 0;
		
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
//			$this->can_be_ended = $options['end'] ? 1:0;
			$this->can_be_ended = 0;
		}
		
		$this->body_parsed = Parsedown::instance()
    ->set_breaks_enabled(true)
    ->parse($this->body); // transform upon insertion into db instead of at runtime
		
		$create = $this->dbh->prepare("INSERT INTO `survey_pages` (`id`, `body`, `body_parsed`, `title`, `end`)
			VALUES (:id, :body, :body_parsed, :title, :end)
		ON DUPLICATE KEY UPDATE
			`body` = :body2, 
			`body_parsed` = :body_parsed2, 
			`title` = :title2, 
			`end` = :end2
		;");
		$create->bindParam(':id',$this->id);
		$create->bindParam(':body',$this->body);
		$create->bindParam(':body_parsed',$this->body_parsed);
		$create->bindParam(':title',$this->title);
		$create->bindParam(':end',$this->can_be_ended);
		$create->bindParam(':body2',$this->body);
		$create->bindParam(':body_parsed2',$this->body_parsed);
		$create->bindParam(':title2',$this->title);
		$create->bindParam(':end2',$this->can_be_ended);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();
		$this->valid = true;
		
		return true;
	}
	public function displayForRun($prepend = '')
	{
		$dialog = '<p><label>Title: <br>
			<input class="form-control col-md-5" type="text" placeholder="Headline" name="title" value="'.$this->title.'"></label></p>
		<p><label>Text: <br>
			<textarea data-editor="markdown" style="width:388px;" placeholder="You can use Markdown" name="body" rows="10" cols="60" class="form-control col-md-5">'.$this->body.'</textarea></label></p>';
#			'<p><input type="hidden" name="end" value="0"><label><input type="checkbox" name="end" value="1"'.($this->can_be_ended ?' checked ':'').'> allow user to continue after viewing page</label></p>';
		$dialog .= '<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Page">Save.</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=Page">Preview</a></p>';
		

		$dialog = $prepend . $dialog;
		
		return parent::runDialog($dialog,'fa-stop fa-1-5x');
	}
	public function removeFromRun()
	{
		return $this->delete();		
	}
	public function test()
	{
		
		echo $this->getParsedBodyAdmin($this->body);
#		if($this->can_be_ended)
#		{
#			$ret = '<form method="post" accept-charset="utf-8">';
#			$ret = '<input type="button" class="btn btn-default btn-success" value="Continue!" name="page_submit">';
#			$ret .= '</form>';
#			echo $ret;
#		}
			
	}
	public function exec()
	{
		if($this->called_by_cron):
			$this->getParsedBody($this->body);
			return true; // never show to the cronjob
		endif;
		
#		if($this->can_be_ended AND $this->ended) return false;
		
		$this->body_parsed = $this->getParsedBody($this->body);
		
		if($this->can_be_ended):
			$action = WEBROOT."{$this->run_name}";
			$ret = '<form action="'.$action.'" method="post" accept-charset="utf-8">';
			$ret .= '<input type="submit" class="btn btn-default btn-success" value="Continue!" name="page_submit">';
			$ret .= '</form>';
			$this->body_parsed .= $ret;
		endif;
		
		return array(
			'title' => $this->title,
			'body' => $this->body_parsed
		);
	}
}