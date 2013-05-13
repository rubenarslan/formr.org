<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";
require_once INCLUDE_ROOT. 'Markdown/Michelf/Markdown.php';
use \Michelf\Markdown AS Markdown;

class Email extends RunUnit {
	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	
	private $body = '';
	private $body_parsed = '';
	private $subject = '';
	private $html = false;
	
	public function __construct($fdb, $session = null, $unit = null) 
	{
		parent::__construct($fdb,$session,$unit);

		if($this->id):
			$data = $this->dbh->prepare("SELECT * FROM `survey_emails` WHERE id = :id LIMIT 1");
			$data->bindParam(":id",$this->id);
			$data->execute() or die(print_r($data->errorInfo(), true));
			$vars = $data->fetch(PDO::FETCH_ASSOC);
			
			if($vars):
				$this->account_id = $vars['account_id'];
				$this->body = $vars['body'];
				$this->body_parsed = $vars['body_parsed'];t
				$this->subject = $vars['subject'];
				$this->can_end = $vars['end'];
		
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
		
		if(isset($options['body']))
		{
			$this->body = $options['body'];
			$this->subject = $options['subject'];
			$this->can_end = $options['end'];
		}
		
		$this->body_parsed = Markdown::defaultTransform($this->body); // transform upon insertion into db instead of at runtime
		
		$create = $this->dbh->prepare("INSERT INTO `survey_pages` (`id`, `body`, `body_parsed`, `subject`, `end`)
			VALUES (:id, :body, :body_parsed, :subject, :end)
		ON DUPLICATE KEY UPDATE
			`body` = :body, 
			`body_parsed` = :body_parsed, 
			`subject` = :subject, 
			`end` = :end
		;");
		$create->bindParam(':id',$this->id);
		$create->bindParam(':body',$this->body);
		$create->bindParam(':body_parsed',$this->body_parsed);
		$create->bindParam(':subject',$this->subject);
		$create->bindParam(':end',$this->can_end);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();
		$this->valid = true;
		
		return true;
	}
	public function displayForRun($prepend = '')
	{
		if($this->id):
			$dialog = '<p><label>Subject: <br>
				<input type="text" placeholder="Email subject" name="subject" value="'.$this->subject.'">
			</label></p>
			<p><label>Body: <br>
				<textarea placeholder="You can use Markdown" name="body" rows="4" cols="60" style="width:399px">'.$this->body.'</textarea></label></p>
			<p><input type="hidden" name="html" value="0"><label><input type="checkbox" name="html" value="1"'.($this->html ?' checked ':'').'> send HTML emails (may worsen spam rating)</label></p>';
			$dialog .= '<p><a class="btn unit_save" href="ajax_save_run_unit?type=Email">Save.</a></p>';
			$dialog .= '<p><a class="btn unit_test" href="ajax_test_unit?type=Email">Preview.</a></p>';
			
		else:
			$dialog = '';
			$g_studies = $this->dbh->query("SELECT * FROM `survey_email_accounts`");
			$accs = array();
			while($acc = $g_studies->fetch())
				$accs[] = $acc;
			if($accs):
				$dialog = '<div class="control-group">
				<select class="select2" name="account_id" style="width:300px">
				<option value=""></option>';
				foreach($accs as $acc):
				    $dialog .= "<option value=\"{$acc['id']}\">{$acc['from']}</option>";
				endforeach;
				$dialog .= "</select>";
				$dialog .= '<a class="btn unit_save" href="ajax_save_run_unit?type=Email">Add to this run.</a></div>';
			else:
				$dialog .= "<h5>No email accounts. Add some first</h5>";
			endif;
		endif;
		$dialog = $prepend . $dialog;
		return parent::runDialog($dialog,'icon-envelope');
	}
	public function removeFromRun($run_id)
	{
		return $this->delete();		
	}
	public function test()
	{
		echo $this->body_parsed;
		if(!$this->can_end)
		{
			$ret = '<form method="post" accept-charset="utf-8">';
			$ret = '<input type="button" class="btn btn-success" value="Weiter!" name="page_submit">';
			$ret .= '</form>';
			echo $ret;
		}
			
	} 
	public function exec()
	{
		$this->sendMail();
		return false;
	}
}