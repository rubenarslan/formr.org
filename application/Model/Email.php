<?php

class Email extends RunUnit {

	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	protected $mail_sent = false;
	protected $body = null;
	protected $body_parsed = null;
	protected $account_id = null;
	protected $images = array();
	protected $subject = null;
	protected $recipient_field;
	protected $recipient;
	protected $html = 1;
	protected $cron_only = 0;
	public $icon = "fa-envelope";
	public $type = "Email";
	protected $subject_parsed = null;
	protected $mostrecent = "most recent reported address";

	/**
	 * An array of unit's exportable attributes
	 * @var array
	 */
	public $export_attribs = array('type', 'description', 'position', 'special', 'subject', 'account_id', 'recipient_field', 'body', 'cron_only');


	public function __construct($fdb, $session = null, $unit = null, $run_session = NULL, $run = NULL) {
		parent::__construct($fdb, $session, $unit, $run_session, $run);

		if ($this->id):
			$vars = $this->dbh->findRow('survey_emails', array('id' => $this->id));
			if ($vars):
				$this->account_id = $vars['account_id'];
				$this->recipient_field = $vars['recipient_field'];
				$this->body = $vars['body'];
				$this->body_parsed = $vars['body_parsed'];
				$this->subject = $vars['subject'];
//				$this->html = $vars['html'] ? 1:0;
				$this->html = 1;
				$this->cron_only = (int)$vars['cron_only'];

				$this->valid = true;
			endif;
		endif;
	}

	public function create($options) {
		if (!$this->id) {
			$this->id = parent::create('Email');
		} else {
			$this->modify($options);
		}

		$parsedown = new ParsedownExtra();
		if (isset($options['body'])) {
			$this->recipient_field = $options['recipient_field'];
			$this->body = $options['body'];
			$this->subject = $options['subject'];
			if (isset($options['account_id']) && is_numeric($options['account_id'])) {
				$this->account_id = (int) $options['account_id'];
			}
//			$this->html = $options['html'] ? 1:0;
			$this->html = 1;
			$this->cron_only = isset($options['cron_only']) ? 1 : 0;
		}
		if ($this->account_id === null):
			$email_accounts = $this->getEmailAccounts();
			if (count($email_accounts) > 0):
				$this->account_id = current($email_accounts)['id'];
			endif;
		endif;

		if (!$this->knittingNeeded($this->body)) {
			$this->body_parsed = $parsedown->text($this->body);
		}

		$this->dbh->insert_update('survey_emails', array(
			'id' => $this->id,
			'account_id' => $this->account_id,
			'recipient_field' => $this->recipient_field,
			'body' => $this->body,
			'body_parsed' => $this->body_parsed,
			'subject' => $this->subject,
			'html' => $this->html,
			'cron_only' => $this->cron_only,
		));

		$this->valid = true;

		return true;
	}

	protected function getSubject() {
		if ($this->subject_parsed === NULL):
			if ($this->knittingNeeded($this->subject)):
				if ($this->run_session_id):
					$this->subject_parsed = $this->getParsedText($this->subject);
				else:
					return false;
				endif;
			else:
				return $this->subject;
			endif;
		endif;
		return $this->subject_parsed;
	}
	
	protected function substituteLinks($body) {
		$sess = null;
		if (isset($this->run_name)) {
			$sess = isset($this->session) ? $this->session : "TESTCODE";
			$login_link = site_url("{$this->run_name}?code=".urlencode($sess));
		} else {
			$login_link = site_url();
			alert("Generated a login link, but no run was specified", 'alert-danger');
		}

		$settings_link = site_url("settings/{$this->run_name}/?code=" . urlencode($sess));
		$settings_link_html = '<a href="'. $settings_link . '">Settings link</a>';

		$login_link_html = '<a href="'. $login_link . '">Login link</a>';
		
		$body = str_replace("{{login_link}}", $login_link_html, $body);
		$body = str_replace("{{login_url}}", $login_link, $body);
		$body = str_replace("{{login_code}}", urlencode($sess), $body);
		$body = str_replace("{{settings_link}}", $settings_link_html, $body);
		$body = str_replace("{{settings_url}}", $settings_link, $body);
		$body = str_replace(urlencode("{{login_url}}"), $login_link, $body);
		$body = str_replace(urlencode("{{login_code}}"), urlencode($sess), $body);
		$body = str_replace(urlencode("{{settings_url}}"), $settings_link, $body);
		return $body;
	}

	protected function getBody($embed_email = true) {
		if ($this->run_session_id):
			$response = $this->getParsedBody($this->body, true);
			if($response === false):
				return false;
			else:
				if (isset($response['body'])):
					$this->body_parsed = $response['body'];
				endif;
				if (isset($response['images'])):
					$this->images = $response['images'];
				endif;
			endif;

			$this->body_parsed = $this->substituteLinks($this->body_parsed); // once more, in case it was pre-parsed
			
			return $this->body_parsed;
		else:
			alert("Session ID for email recipient is missing.", "alert-danger");
			return false;
		endif;
	}

	protected function getEmailAccounts() {
		global $user;
		return $this->dbh->select('id, from')
				->from('survey_email_accounts')
				->where(array('user_id' => $user->id))->fetchAll();
	}
	
	protected function getPotentialRecipientFields() {
		$get_recips = $this->dbh->prepare("SELECT survey_studies.name AS survey,survey_items.name AS item FROM survey_items
			LEFT JOIN survey_studies ON survey_studies.id = survey_items.study_id
		LEFT JOIN survey_run_units ON survey_studies.id = survey_run_units.unit_id
		LEFT JOIN survey_runs ON survey_runs.id = survey_run_units.run_id
		WHERE survey_runs.id = :run_id AND
		survey_items.type = 'email'");
		// fixme: if the last reported email thing is known to work, show only linked email addresses here.
		$get_recips->bindValue(':run_id', $this->run_id);
		$get_recips->execute();

		$recips = array( array( "id" => $this->mostrecent, "text" => $this->mostrecent ));
		while($res = $get_recips->fetch(PDO::FETCH_ASSOC)):
			$email = $res['survey'] . "$" . $res['item'];
			$recips[] = array("id" => $email, "text" => $email);
		endwhile;
		return $recips;
	}

	public function displayForRun($prepend = '') {
		$email_accounts = $this->getEmailAccounts();

		if (!empty($email_accounts)):
			$dialog = '<p><label>Account: <br>
			<select class="select2" name="account_id" style="width:350px">
			<option value=""></option>';
			foreach ($email_accounts as $acc):
				if (isset($this->account_id) AND $this->account_id == $acc['id'])
					$dialog .= "<option selected value=\"{$acc['id']}\">{$acc['from']}</option>";
				else
					$dialog .= "<option value=\"{$acc['id']}\">{$acc['from']}</option>";
			endforeach;
			$dialog .= "</select>";
			$dialog .= '</label></p>';
		else:
			$dialog = "<h5>No email accounts. <a href='" . WEBROOT . "admin/mail/" . "'>Add some here.</a></h5>";
		endif;
		$dialog .= '<p><label>Subject: <br>
			<input class="form-control full_width" type="text" placeholder="Email subject" name="subject" value="' . h($this->subject) . '">
		</label></p>
		<p><label>Recipient-Field: <br>
					<input class="form-control full_width select2recipient" type="text" placeholder="survey_users$email" name="recipient_field" value="' . h($this->recipient_field) . '" data-select2init="'.htmlentities(json_encode( $this->getPotentialRecipientFields(), JSON_UNESCAPED_UNICODE)).'">
				</label></p>
		<p><label>Body: <br>
			<textarea style="width:388px;"  data-editor="markdown" placeholder="You can use Markdown" name="body" rows="7" cols="60" class="form-control col-md-5">' . h($this->body) . '</textarea></label><br>
			<code>{{login_link}}</code> will be replaced by a personalised link to this run, <code>{{login_code}}</code> will be replaced with this user\'s session code.</p>';
//		<p><input type="hidden" name="html" value="0"><label><input type="checkbox" name="html" value="1"'.($this->html ?' checked ':'').'> send HTML emails (may worsen spam rating)</label></p>';
		
		$dialog .= '<p><label><input type="checkbox" name="cron_only" value="1"'.($this->cron_only ?' checked ':'').'> Send e-mails only when cron is running</label></p>';
		$dialog .= '<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Email">Save</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=Email">Test</a></p>';

		$dialog = $prepend . $dialog;
		return parent::runDialog($dialog, 'fa-envelope');
	}

	public function getRecipientField($return_format = 'json', $return_session = false) {
		if (empty($this->recipient_field) OR $this->recipient_field === $this->mostrecent) {
			$get_recip = $this->dbh->prepare("SELECT `survey_items_display`.`answer` AS email FROM survey_items_display
			LEFT JOIN survey_items ON survey_items_display.item_id = survey_items.id
			LEFT JOIN survey_studies ON survey_studies.id = survey_items.study_id
			LEFT JOIN survey_run_units ON survey_studies.id = survey_run_units.unit_id
			LEFT JOIN survey_run_sessions ON survey_run_units.run_id = survey_run_sessions.run_id
			WHERE survey_run_units.run_id = :run_id AND
			survey_items.type = 'email' AND
			survey_items_display.answer IS NOT NULL AND
			survey_run_sessions.id = :run_session_id
			ORDER BY survey_items_display.answered DESC
			LIMIT 1");
			$get_recip->bindValue(':run_id', $this->run_id);
			$get_recip->bindValue(':run_session_id', $this->run_session_id);
			$get_recip->execute();

			$recips = array();
			$res = $get_recip->fetch(PDO::FETCH_ASSOC);
			if($res) {
				$email = $res['email'];
				return $email;
			} else {
				return null;
			}
		}

		$opencpu_vars = $this->getUserDataInRun($this->recipient_field);

		return opencpu_evaluate($this->recipient_field, $opencpu_vars, $return_format, null, $return_session);
	}

	public function sendMail($who = NULL) {
		$this->mail_sent = false;
		
		if ($who === null):
			$this->recipient = $this->getRecipientField();
		else:
			$this->recipient = $who;
		endif;

		if ($this->recipient == null):
			//formr_log("Email recipient could not be determined from this field definition " . $this->recipient_field);
			alert("We could not find an email recipient. Session: {$this->session}", 'alert-danger');
			return false;
		endif;

		if ($this->account_id === null):
			alert("The study administrator (you?) did not set up an email account. <a href='" . admin_url('mail') . "'>Do it now</a> and then select the account in the email dropdown.", 'alert-danger');
			return false;
		endif;

		$run_session = $this->run_session;

		$testing = !$run_session || $run_session->isTesting();
		
		$acc = new EmailAccount($this->dbh, $this->account_id, null);
		$mailing_themselves = (is_array($acc->account) && $acc->account["from"] === $this->recipient) ||
							  (Site::getCurrentUser()->email === $this->recipient) ||
							  ($this->run && $this->run->getOwner()->email === $this->recipient);
				
		$mails_sent = $this->numberOfEmailsSent();
		$error = null;
		$warning = null;
		if(!$mailing_themselves):
			if ($mails_sent['in_last_1m'] > 0):
				if($mails_sent['in_last_1m'] < 3 && $testing):
					$warning = sprintf("We already sent %d mail to this recipient in the last minute. An email was sent, because you're currently testing, but it would have been delayed for a real user, to avoid allegations of spamming.", $mails_sent['in_last_1m']);
				else:
					$error = sprintf("We already sent %d mail to this recipient in the last minute. No email was sent.", $mails_sent['in_last_1m']);
				endif;
			elseif ($mails_sent['in_last_10m'] > 0):
				if($mails_sent['in_last_10m'] < 10 && $testing):
					$warning = sprintf("We already sent %d mail to this recipient in the last 10 minutes. An email was sent, because you're currently testing, but it would have been delayed for a real user, to avoid allegations of spamming.", $mails_sent['in_last_10m']);
				else:
					$error = sprintf("We already sent %d mail to this recipient in the last 10 minutes. No email was sent.", $mails_sent['in_last_10m']);
				endif;
			elseif ($mails_sent['in_last_1h'] > 2):
				if($mails_sent['in_last_1h'] < 10 && $testing):
					$warning = sprintf("We already sent %d mails to this recipient in the last hour. An email was sent, because you're currently testing, but it would have been delayed for a real user, to avoid allegations of spamming.", $mails_sent['in_last_1h']);
				else:
					$error = sprintf("We already sent %d mails to this recipient in the last hour. No email was sent.", $mails_sent['in_last_1h']);
				endif;
			elseif ($mails_sent['in_last_1d'] > 5 && !$testing):
				$error = sprintf("We already sent %d mails to this recipient in the last day. No email was sent.", $mails_sent['in_last_1d']);
			elseif ($mails_sent['in_last_1w'] > 20 && !$testing):
				$error = sprintf("We already sent %d mails to this recipient in the last week. No email was sent.", $mails_sent['in_last_1w']);
			endif;
		else:
			if ($mails_sent['in_last_1m'] > 1 || $mails_sent['in_last_1d'] > 100):
				$error = sprintf("Too many emails are being sent to the study administrator, %d mails today. Please wait a little.", $mails_sent['in_last_1d']);
			endif;
		endif;
		
		if ($error !== null) {
			$error = "Session: {$this->session}:\n {$error}";
			alert(nl2br($error), 'alert-danger');
			return false;
		}

		if ($warning !== null) {
			$warning = "Session: {$this->session}:\n {$warning}";
			alert(nl2br($warning), 'alert-info');
		}

		$mail = $acc->makeMailer();

//		if($this->html)
		$mail->IsHTML(true);

		$mail->AddAddress($this->recipient);
		$mail->Subject = $this->getSubject();
		$mail->Body = $this->getBody();

		if(filter_var($this->recipient, FILTER_VALIDATE_EMAIL) AND $mail->Body !== false AND $mail->Subject !== false):
			foreach ($this->images AS $image_id => $image):
				$local_image = INCLUDE_ROOT . 'tmp/' . uniqid() . $image_id;
				copy($image, $local_image);
				register_shutdown_function(create_function('', "unlink('{$local_image}');"));

				if (!$mail->AddEmbeddedImage($local_image, $image_id, $image_id, 'base64', 'image/png' )):
					alert('Email with the subject "' . h($mail->Subject) . '" was not sent to ' . h($this->recipient) . ':<br>' . $mail->ErrorInfo, 'alert-danger');
				endif;
			endforeach;

			if (!$mail->Send()):
				alert('Email with the subject "' . h($mail->Subject) . '" was not sent to ' . h($this->recipient) . ':<br>' . $mail->ErrorInfo, 'alert-danger');
			else:
				$this->mail_sent = true;
				$this->logMail();
			endif;
		else:
			if(!filter_var($this->recipient, FILTER_VALIDATE_EMAIL)):
				alert('Intended recipient was not a valid email address: '. $this->recipient, 'alert-danger');
			endif;
			if($mail->Body === false):
				alert('Email body empty or could not be dynamically generated.', 'alert-danger');
			endif;
			if($mail->Subject === false):
				alert('Email subject empty or could not be dynamically generated.', 'alert-danger');
			endif;
		endif;
		return $this->mail_sent;
	}

	protected function numberOfEmailsSent() {
		$log = $this->dbh->prepare("SELECT
			SUM(created > DATE_SUB(NOW(), INTERVAL 1 MINUTE)) AS in_last_1m,
			SUM(created > DATE_SUB(NOW(), INTERVAL 10 MINUTE)) AS in_last_10m,
			SUM(created > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS in_last_1h,
			SUM(created > DATE_SUB(NOW(), INTERVAL 1 DAY)) AS in_last_1d,
			SUM(1) AS in_last_1w
			FROM `survey_email_log`
			WHERE recipient = :recipient AND created > DATE_SUB(NOW(), INTERVAL 7 DAY)");
		$log->bindParam(':recipient', $this->recipient);
		$log->execute();
		return $log->fetch(PDO::FETCH_ASSOC);
	}

	protected function logMail() {
		if (!$this->session_id && $this->run_session) {
			$unit = $this->run_session->getCurrentUnit();
			$session_id = $unit ? $unit['session_id'] : null;
		} else {
			$session_id = $this->session_id;
		}
		$query = "INSERT INTO `survey_email_log` (session_id, email_id, created, recipient) VALUES (:session_id, :email_id, NOW(), :recipient)";
		$this->dbh->exec($query, array(
			'session_id' => $session_id,
			'email_id' => $this->id,
			'recipient' => $this->recipient,
		));
	}

	public function test() {
		if (!$this->grabRandomSession()) {
			return false;
		}
		global $user;
		
		$receiver = $user->getEmail();

		echo "<h4>Recipient</h4>";
		$recipient_field = $this->getRecipientField('',true);
		if(!is_string($recipient_field) AND get_class($recipient_field) == "OpenCPU_Session") {
			echo opencpu_debug($recipient_field, null, 'text');
		} else {
			echo $this->mostrecent . ": " . $recipient_field;
		}
		echo "<h4>Subject</h4>";
		if($this->knittingNeeded($this->subject)):
			echo $this->getParsedTextAdmin($this->subject);
		else:
			echo $this->getSubject();
		endif;
		echo "<h4>Body</h4>";

		echo $this->getParsedBodyAdmin($this->body);

		echo "<h4>Attempt to send email</h4>";

		if($this->sendMail($receiver)):
			echo "<p>An email was sent to your own email address (". h($receiver). ").</p>";
		else:
			echo "<p>No email sent.</p>";
		endif;


		$results = $this->getSampleSessions();
		if ($results) {

			if ($this->recipient_field === null OR trim($this->recipient_field) == '') {
				$this->recipient_field = 'survey_users$email';
			}

			$output = '
				<table class="table table-striped">
					<thead>
						<tr>
							<th>Code (Position)</th>
							<th>Test</th>
						</tr>
					</thead>
					<tbody>%s</tbody>
				</table>';

			$rows = '';
			foreach ($results AS $row):
				$this->run_session_id = $row['id'];

				$email = stringBool($this->getRecipientField());
				$good = filter_var($email, FILTER_VALIDATE_EMAIL) ? '' : 'text-warning';
				$rows .= "
					<tr>
						<td style='word-wrap:break-word;max-width:150px'><small>" . $row['session'] . " ({$row['position']})</small></td>
						<td class='$good'>" . $email . "</td>
					</tr>";
			endforeach;

			echo sprintf($output, $rows);
		}
		$this->run_session_id = null;
	}

	protected function sessionCanReceiveMails() {
		// If not executing under a run session or no_mail is null the user can receive email
		if (!$this->run_session || $this->run_session->no_mail === null) {
			return true;
		}

		// If no mail is 0 then user has choose not to receive emails
		if ((int)$this->run_session->no_mail === 0) {
			return false;
		}

		// If no_mail is set && the timestamp is less that current time then the snooze period has expired
		if ($this->run_session->no_mail <= time()) {
			// modify subscription settings
			$this->run_session->saveSettings(array('no_email' => '1'), array('no_email' => null));
			return true;
		}

		return false;
	}

	public function exec() {
		// If emails should be sent only when cron is active and unit is not called by cron, then end it and move on
		if ($this->cron_only && !$this->called_by_cron) {
			$this->end();
			return false;
		}

		// Check if user is enabled to receive emails
		if (!$this->sessionCanReceiveMails()) {
			return array('body' => "<p>Session <code>{$this->session}</code> cannot receive mails at this time </p>");
		}

		// Try to send email
		$err = $this->sendMail();
		if ($this->mail_sent) {
			$this->end();
			return false;
		}
		return array('body' => $err);
	}

}
