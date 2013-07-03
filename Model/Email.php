<?php
// todo: should check email log so that it never sends more than three emails to the same user in a short amount of time.
require_once INCLUDE_ROOT."Model/RunUnit.php";
require_once INCLUDE_ROOT. 'Markdown/Michelf/Markdown.php';
use \Michelf\Markdown AS Markdown;

class Email extends RunUnit {
	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	private $mail_sent = false;
	
	private $body = null;
	protected $body_parsed = null;
	private $subject = null;
	private $html = null;
	
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
				$this->recipient_field = $vars['recipient_field'];
				$this->body = $vars['body'];
				$this->body_parsed = $vars['body_parsed'];
				$this->subject = $vars['subject'];
				$this->html = $vars['html'] ? 1:0;
		
				$this->valid = true;
			endif;
		endif;
	}
	public function create($options)
	{
		$this->dbh->beginTransaction();
		if(!$this->id)
			$this->id = parent::create('Email');
		else
			$this->modify($this->id);
		
		if(isset($options['body']))
		{
			$this->recipient_field = $options['recipient_field'];
			$this->body = $options['body'];
			$this->subject = $options['subject'];
			if(isset($options['account_id']))
				$this->account_id = $options['account_id'];
			$this->html = $options['html'] ? 1:0;
		}
		
		$this->body_parsed = Markdown::defaultTransform($this->body); // transform upon insertion into db instead of at runtime
		
		$create = $this->dbh->prepare("
		INSERT INTO `survey_emails` (
		`id` ,
		`account_id` ,
		`subject` ,
		`recipient_field` ,
		`body` ,
		`body_parsed` ,
		`html`
		)
			VALUES (:id, :account_id, :recipient_field, :body, :body_parsed, :subject, :html)
		ON DUPLICATE KEY UPDATE
			`recipient_field` = :recipient_field, 
			`account_id` = :account_id,
			`body` = :body, 
			`body_parsed` = :body_parsed, 
			`subject` = :subject, 
			`html` = :html
		;");
		$create->bindParam(':id',$this->id);
		$create->bindParam(':account_id',$this->account_id);
		$create->bindParam(':recipient_field',$this->recipient_field);
		$create->bindParam(':body',$this->body);
		$create->bindParam(':body_parsed',$this->body_parsed);
		$create->bindParam(':subject',$this->subject);
		$create->bindParam(':html',$this->html);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();
		$this->valid = true;
		
		return true;
	}
	private function getBody()
	{
		if(isset($this->run_name))		
			$login_link = WEBROOT."{$this->run_name}?code={$this->session}";
		else $login_link = WEBROOT;

		if($this->html):
			$login_link = "<a href='$login_link'>Login link</a>";
			$this->body_parsed = str_replace("{{login_link}}", $login_link , $this->getParsedBody($this->body) );
			return $this->body_parsed;
		else:
			$this->body = str_replace("{{login_link}}", $login_link , $this->body);
			return $this->body;
		endif;
	}
	public function displayForRun($prepend = '')
	{
		$accs = $this->dbh->prepare("SELECT `id`,`from` FROM `survey_email_accounts` WHERE user_id = :user_id");
		global $user;
		$accs->bindParam(':user_id',$user->id);
		$accs->execute();
		$results = array();
		while($acc = $accs->fetch(PDO::FETCH_ASSOC))
			$results[] = $acc;
		
		if(!empty($results)):
			$dialog = '<div class="control-group"><label>Account:
			<select class="select2" name="account_id" style="width:300px">
			<option value=""></option>';
			foreach($results as $acc):
				if(isset($this->account_id) AND $this->account_id == $acc['id'])
				    $dialog .= "<option selected value=\"{$acc['id']}\">{$acc['from']}</option>";
				else
				    $dialog .= "<option value=\"{$acc['id']}\">{$acc['from']}</option>";
			endforeach;
			$dialog .= "</select>";
			$dialog .= '</label></div>';
		else:
			$dialog = "<h5>No email accounts. Add some first</h5>";
		endif;
		$dialog .= '<p><label>Subject: <br>
			<input type="text" placeholder="Email subject" name="subject" value="'.$this->subject.'">
		</label></p>
		<p><label>Recipient-Field: <br>
					<input type="text" placeholder="survey_users.email" name="recipient_field" value="'.$this->recipient_field.'">
				</label></p>
		<p><label>Body: <br>
			<textarea placeholder="You can use Markdown" name="body" rows="4" cols="60" class="span5">'.$this->body.'</textarea></label><br>
			<code>{{login_link}}</code> will be replaced by a personalised link to this run.</p>
		<p><input type="hidden" name="html" value="0"><label><input type="checkbox" name="html" value="1"'.($this->html ?' checked ':'').'> send HTML emails (may worsen spam rating)</label></p>';
		$dialog .= '<p class="btn-group"><a class="btn unit_save" href="ajax_save_run_unit?type=Email">Save.</a>
		<a class="btn unit_test" href="ajax_test_unit?type=Email">Test</a></p>';
		
		$dialog = $prepend . $dialog;
		return parent::runDialog($dialog,'icon-envelope');
	}
	public function getRecipientField()
	{
		if($this->recipient_field === null OR trim($this->recipient_field)=='')
			$this->recipient_field = '`survey_users`.email';

		$join = join_builder($this->dbh, $this->recipient_field);
			
$q = "SELECT {$this->recipient_field} AS email_field FROM `survey_run_sessions`
	
$join

WHERE `survey_run_sessions`.id = :run_session_id

ORDER BY IF(ISNULL(email_field),1,0), `survey_unit_sessions`.id DESC

LIMIT 1";

#pr($q);
#pr($this->run_session_id);

		$g_email = $this->dbh->prepare($q); // should use readonly
		$g_email->bindParam(":run_session_id", $this->run_session_id);

		$g_email->execute() or die(print_r($g_email->errorInfo(), true));
		if($g_email->rowCount()===1):
			$temp = $g_email->fetch(PDO::FETCH_ASSOC);
			$email = $temp['email_field'];
		else:
			$email = '';
		endif;
		return $email;
	}
	public function sendMail($who = NULL)
	{
		if($who===null):
			$this->recipient = $this->getRecipientField();
		else:
			$this->recipient = $who;
		endif;
		require_once INCLUDE_ROOT. 'Model/EmailAccount.php';
		
		$acc = new EmailAccount($this->dbh, $this->account_id, null);
		$mail = $acc->makeMailer();
		
		if($this->html)
			$mail->IsHTML(true);  
		
		$mail->AddAddress($this->recipient);
		$mail->Subject = $this->subject;
		$mail->Body    = $this->getBody();
		
		if(!$mail->Send())
		{
			$this->mail_sent = false;
			alert($mail->ErrorInfo,'alert-error');
		}
		else 
		{
			$this->mail_sent = true;
	    	$this->logMail();
		}
	}
	private function logMail()
	{
		$log = $this->dbh->prepare("INSERT INTO `survey_email_log` (session_id, email_id, created, recipient)
			VALUES (:session_id, :email_id, NOW(), :recipient)");
		$log->bindParam(':session_id', $this->session_id);
		$log->bindParam(':email_id', $this->id);
		$log->bindParam(':recipient', $this->recipient);
		$log->execute();
	}
	public function test()
	{
		$RandReceiv = bin2hex(openssl_random_pseudo_bytes(5));
		$receiver = $RandReceiv . '@mailinator.com';
		$this->sendMail($receiver);
		$link = "{$RandReceiv}.mailinator.com";
		
		echo "<h4>{$this->subject}</h4>";
		echo "<p><a href='http://$link'>$link</a></p>";
		
		echo $this->getBody();
		
		if($this->recipient_field === null OR trim($this->recipient_field)=='')
			$this->recipient_field = '`survey_users`.email';
		
		$join = join_builder($this->dbh, $this->recipient_field);
			
$q = "SELECT DISTINCT {$this->recipient_field} AS email,`survey_run_sessions`.session FROM `survey_run_sessions`

$join

WHERE `survey_run_sessions`.run_id = :run_id
AND email IS NOT NULL

ORDER BY RAND()
LIMIT 20";
#echo $q;
		$g_email = $this->dbh->prepare($q); // should use readonly
		$g_email->bindParam(':run_id',$this->run_id);

		$g_email->execute() or die(print_r($g_email->errorInfo(), true));
		if($g_email->rowCount()>=1):
			$results = array();
			while($temp = $g_email->fetch())
				$results[] = $temp;
		else:
			echo 'Nothing found';
			return false;
		endif;
		
		echo '<table class="table table-striped">
				<thead><tr>
					<th>Code</th>
					<th>Email</th>
				</tr></thead>
				<tbody>"';
		foreach($results AS $row):
			echo "<tr>
					<td><small>{$row['session']}</small></td>
					<td>".h($row['email'])."</td>
				</tr>";
		endforeach;
		echo '</tbody></table>';
	}
	public function remind($who)
	{
		$err = $this->sendMail($who);
		if($this->mail_sent):
			return true;
		else:
			return array('body'=>$err);
		endif;
	}
	public function exec()
	{
		$err = $this->sendMail();
		if($this->mail_sent):
			$this->end();
			return false;
		else:
			return array('body'=>$err);
		endif;
	}
}