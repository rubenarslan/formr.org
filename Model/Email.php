<?php
class Email extends RunUnit {
	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	private $mail_sent = false;
	
	private $body = null;
	protected $body_parsed = null;
	private $account_id = null;
	private $images = array();
	private $subject = null;
	private $html = 1;
	public $icon = "fa-envelope";
	public $type = "Email";
	private $subject_parsed = null;
	private $admin_usage = false;
 	
	public function __construct($fdb, $session = null, $unit = null, $run_session = NULL) 
	{
		parent::__construct($fdb,$session,$unit, $run_session);

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
//				$this->html = $vars['html'] ? 1:0;
				$this->html = 1;
		
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
		
		$parsedown = new ParsedownExtra();
		if(isset($options['body']))
		{
			$this->recipient_field = $options['recipient_field'];
			$this->body = $options['body'];
			$this->subject = $options['subject'];
			if(isset($options['account_id']) AND is_numeric($options['account_id']))
				$this->account_id = (int)$options['account_id'];
//			$this->html = $options['html'] ? 1:0;
			$this->html = 1;
		}
		if($this->account_id === null):
			$email_accounts = $this->getEmailAccounts();
			if(count($email_accounts)>0):
				$this->account_id = current($email_accounts)['id'];
			endif;
		endif;
		

		$this->body_parsed = $parsedown->text($this->body);
		
		$create = $this->dbh->prepare("
		INSERT INTO `survey_emails` 
		(`id` , `account_id` ,	`subject` ,	`recipient_field` ,	`body` ,`body_parsed` ,	`html`	)
VALUES (:id, :account_id,  :subject, :recipient_field, :body, :body_parsed, :html)
		ON DUPLICATE KEY UPDATE
			`account_id` = :account_id2,
			`recipient_field` = :recipient_field2, 
			`body` = :body2, 
			`body_parsed` = :body_parsed2, 
			`subject` = :subject2, 
			`html` = :html2
		;");
		$create->bindParam(':id',$this->id);
		$create->bindParam(':account_id',$this->account_id);
		$create->bindParam(':recipient_field',$this->recipient_field);
		$create->bindParam(':body',$this->body);
		$create->bindParam(':body_parsed',$this->body_parsed);
		$create->bindParam(':subject',$this->subject);
		$create->bindParam(':html',$this->html);
		
		$create->bindParam(':account_id2',$this->account_id);
		$create->bindParam(':recipient_field2',$this->recipient_field);
		$create->bindParam(':body2',$this->body);
		$create->bindParam(':body_parsed2',$this->body_parsed);
		$create->bindParam(':subject2',$this->subject);
		$create->bindParam(':html2',$this->html);

		$create->execute();
		
		$this->dbh->commit();
		$this->valid = true;
		
		return true;
	}
	private function getSubject()
	{
		if($this->subject_parsed === NULL):
			if($this->knittingNeeded($this->subject)):
				if($this->session_id):
					$this->subject_parsed = $this->getParsedText($this->subject);
				else:
					$this->subject_parsed = $this->getParsedTextAdmin($this->subject);
				endif;
			else:
				return $this->subject;
			endif;
		endif;
		return $this->subject_parsed;
	}
	private function getBody($embed_email = true)
	{
		
		if(isset($this->run_name))
		{
#			if(!$this->session)
#				alert("Generated a login link, but no user session was specified", 'alert-info');
			$login_link = WEBROOT."{$this->run_name}?code={$this->session}";
		}
		else 
		{
			$login_link = WEBROOT;
			alert("Generated a login link, but no run was specified", 'alert-danger');
		}
		if($this->html):
			$login_link = "<a href='$login_link'>Login link</a>";

			if($this->session_id):
				$response = $this->getParsedBody($this->body,true);
				if(isset($response['body'])):
					$this->body_parsed = $response['body'];
				endif;
				if(isset($response['images'])):
					$this->images = $response['images'];
				endif;
			else:
				$response = $this->getParsedBodyAdmin($this->body,$embed_email);
				
				if($embed_email):
					if(isset($response['body'])):
						$this->body_parsed = $response['body'];
					endif;
					if(isset($response['images'])):
						$this->images = $response['images'];
					endif;
				else:
					$this->body_parsed = $response;
				endif;
			endif;
			
			$this->body_parsed = str_replace("{{login_link}}", $login_link , $this->body_parsed );
			$this->body_parsed = str_replace("{{login_code}}", $this->session, $this->body_parsed);
			return $this->body_parsed;
		else:
			$this->body = str_replace("{{login_link}}", $login_link , $this->body);
			$this->body = str_replace("{{login_code}}", $this->session,  $this->body);
			return $this->body;
		endif;
	}
	private function getEmailAccounts()
	{
		$accs = $this->dbh->prepare("SELECT `id`,`from` FROM `survey_email_accounts` WHERE user_id = :user_id");
		global $user;
		$accs->bindParam(':user_id',$user->id);
		$accs->execute();
		$results = array();
		while($acc = $accs->fetch(PDO::FETCH_ASSOC))
			$results[] = $acc;
		return $results;
	}
	public function displayForRun($prepend = '')
	{
		$email_accounts = $this->getEmailAccounts();
		
		if(!empty($email_accounts)):
			$dialog = '<p><label>Account: <br>
			<select class="select2" name="account_id" style="width:350px">
			<option value=""></option>';
			foreach($email_accounts as $acc):
				if(isset($this->account_id) AND $this->account_id == $acc['id'])
				    $dialog .= "<option selected value=\"{$acc['id']}\">{$acc['from']}</option>";
				else
				    $dialog .= "<option value=\"{$acc['id']}\">{$acc['from']}</option>";
			endforeach;
			$dialog .= "</select>";
			$dialog .= '</label></p>';
		else:
			$dialog = "<h5>No email accounts. <a href='". WEBROOT."admin/mail/". "'>Add some here.</a></h5>";
		endif;
		$dialog .= '<p><label>Subject: <br>
			<input class="form-control full_width" type="text" placeholder="Email subject" name="subject" value="'.h($this->subject).'">
		</label></p>
		<p><label>Recipient-Field: <br>
					<input class="form-control full_width" type="text" placeholder="survey_users$email" name="recipient_field" value="'.h($this->recipient_field).'">
				</label></p>
		<p><label>Body: <br>
			<textarea style="width:388px;"  data-editor="markdown" placeholder="You can use Markdown" name="body" rows="7" cols="60" class="form-control col-md-5">'.h($this->body).'</textarea></label><br>
			<code>{{login_link}}</code> will be replaced by a personalised link to this run, <code>{{login_code}}</code> will be replaced with this user\'s session code.</p>';
//		<p><input type="hidden" name="html" value="0"><label><input type="checkbox" name="html" value="1"'.($this->html ?' checked ':'').'> send HTML emails (may worsen spam rating)</label></p>';
		$dialog .= '<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Email">Save.</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=Email">Test</a></p>';
		
		$dialog = $prepend . $dialog;
		return parent::runDialog($dialog,'fa-envelope');
	}
	public function getRecipientField()
	{
		$openCPU = $this->makeOpenCPU();

		$openCPU->admin_usage = $this->admin_usage;

		if($this->recipient_field === null OR trim($this->recipient_field)=='')
			$this->recipient_field = 'survey_users$email';
		
		$openCPU->addUserData($this->getUserDataInRun(
			$this->dataNeeded($this->dbh,$this->recipient_field)
		));

		$result = $openCPU->evaluate($this->recipient_field);
		if($openCPU->anyErrors()) return null; // don't go anywhere, wait for the error to be fixed!

		return $result;
	}
	public function sendMail($who = NULL)
	{
		if($who===null):
			$this->recipient = $this->getRecipientField();
		else:
			$this->recipient = $who;
		endif;
		
		if($this->recipient == null):
			formr_log("Email recipient could not be determined from this field definition ". $this->recipient_field);
			alert("We could not find an email recipient.", 'alert-danger');
			$this->mail_sent = false;
			return false;
		endif;

		if($this->account_id === null):
			alert("The study administrator (you?) did not set up an email account. <a href='".WEBROOT."/admin/mail/'>Do it now</a> and then select the account in the email dropdown.",'alert-danger');
			$this->mail_sent = false;
			return false;
		endif;
		
		$mails_sent = $this->numberOfEmailsSent();
		if($mails_sent['in_last_1m'] > 0):
			alert(sprintf("We already sent %d mail to this recipient in the last minute. No email was sent.",$mails_sent['in_last_1m']), 'alert-warning');
			return false;
		elseif($mails_sent['in_last_10m'] > 0):
			alert(sprintf("We already sent %d mail to this recipient in the last 10 minutes. No email was sent.",$mails_sent['in_last_10m']), 'alert-warning');
			return false;
		elseif($mails_sent['in_last_1h'] > 2):
			alert(sprintf("We already sent %d mails to this recipient in the last hour. No email was sent.",$mails_sent['in_last_1h']), 'alert-warning');
			return false;
		elseif($mails_sent['in_last_1d'] > 5):
			alert(sprintf("We already sent %d mails to this recipient in the last day. No email was sent.",$mails_sent['in_last_1d']), 'alert-warning');
			return false;
		elseif($mails_sent['in_last_1w'] > 20):
			alert(sprintf("We already sent %d mails to this recipient in the last week. No email was sent.",$mails_sent['in_last_1w']), 'alert-warning');
			return false;
		endif;
		
		$acc = new EmailAccount($this->dbh, $this->account_id, null);
		$mail = $acc->makeMailer();
		
//		if($this->html)
			$mail->IsHTML(true);  
		
		$mail->AddAddress($this->recipient);
		$mail->Subject = $this->getSubject();
		$mail->Body = $this->getBody();
		
		foreach($this->images AS $image_id => $image):
			$local_image =  INCLUDE_ROOT . 'tmp/' . uniqid(). $image_id;
			copy($image,$local_image);
			register_shutdown_function(create_function('', "unlink('{$local_image}');")); 
			
	        if (!$mail->AddEmbeddedImage(
	            $local_image,
	            $image_id,
	            $image_id,
	            'base64',
	            'image/png'
	        )) {
				$this->mail_sent = false;
	            alert('Email with the subject ' . $this->subject . ' was not sent to '. $this->recipient. ':<br>' .$mail->ErrorInfo,'alert-danger');
	        }
		endforeach;
		
		if(!$mail->Send())
		{
			$this->mail_sent = false;
            alert('Email with the subject ' . $this->subject . ' was not sent to '. $this->recipient. ':<br>' .$mail->ErrorInfo,'alert-danger');
		}
		else 
		{
			$this->mail_sent = true;
	    	$this->logMail();
		}
	}
	private function numberOfEmailsSent()
	{
		$log = $this->dbh->prepare("SELECT
			SUM(created > DATE_SUB(NOW(), INTERVAL 1 MINUTE)) AS in_last_1m,
			SUM(created > DATE_SUB(NOW(), INTERVAL 10 MINUTE)) AS in_last_10m,
			SUM(created > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS in_last_1h,
			SUM(created > DATE_SUB(NOW(), INTERVAL 1 DAY)) AS in_last_1d,
			SUM(1) AS in_last_1w
		
			
			 FROM `survey_email_log`
				
				WHERE recipient = :recipient
				AND created > DATE_SUB(NOW(), INTERVAL 7 DAY)");
		$log->bindParam(':recipient', $this->recipient);
		$log->execute();
		return $log->fetch(PDO::FETCH_ASSOC);
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
		$this->admin_usage = true;
		$RandReceiv = bin2hex(openssl_random_pseudo_bytes(5));
		$receiver = $RandReceiv . '@mailinator.com';
		
		$this->sendMail($receiver);
		$link = "{$RandReceiv}.mailinator.com";

		echo "<h4>".$this->getSubject()."</h4>";
		echo "<p><a href='http://$link'>Check whether the email arrived properly at a random email address on Mailinator.com</a></p>";
		
		echo $this->getBody(false);
		
		if($this->recipient_field === null OR trim($this->recipient_field)=='')
			$this->recipient_field = 'survey_users$email';
		
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

		echo '<table class="table table-striped">
				<thead><tr>
					<th>Code (Position)</th>
					<th>Test</th>
				</tr></thead>
				<tbody>"';
		foreach($results AS $row):
			$openCPU = $this->makeOpenCPU();
			$this->run_session_id = $row['id'];

			$openCPU->admin_usage = $this->admin_usage;
			$openCPU->addUserData($this->getUserDataInRun(
				$this->dataNeeded($this->dbh,$this->recipient_field)
			));
			$email = stringBool($openCPU->evaluate($this->recipient_field) );
			$good = filter_var( $email, FILTER_VALIDATE_EMAIL) ? '' : 'text-warning';
			echo "<tr>
					<td style='word-wrap:break-word;max-width:150px'><small>".$row['session']." ({$row['position']})</small></td>
					<td class='$good'>".$email."</td>
				</tr>";
		endforeach;
		echo '</tbody></table>';
		$this->run_session_id = null;
	}
	public function exec()
	{
		if($this->beingTestedByOwner()) $this->admin_usage = true;
		
		$err = $this->sendMail();
		if($this->mail_sent):
			$this->end();
			return false;
		else:
			return array('body'=>$err);
		endif;
	}
}