<?php

class Email extends RunUnit {

    public $errors = array();
    public $id = null;
    public $unit = null;
    public $icon = "fa-envelope";
    public $type = "Email";
    
    protected $mail_queued = false;
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
    protected $subject_parsed = null;
    protected $mostrecent = "most recent reported address";

    /**
     * An array of unit's exportable attributes
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special', 'subject', 'account_id', 'recipient_field', 'body', 'cron_only');

    public function __construct(Run $run, array $props = []) {
        parent::__construct($run, $props);

        if ($this->id) {
            $vars = $this->db->findRow('survey_emails', array('id' => $this->id));
            if ($vars) {
                $vars['html'] = 1;
                $this->assignProperties($vars);
                $this->valid = true;
            }
        }
    }

    public function create($options = []) {
        parent::create($options);

        $parsedown = new ParsedownExtra();
        if (isset($options['body'])) {
            if (isset($options['account_id']) && is_numeric($options['account_id'])) {
               $options['account_id'] = (int) $options['account_id'];
            }
            $options['cron_only'] = (int)isset($options['cron_only']);
            $options['html'] = 1;
            $this->assignProperties($options);
        }
        
        if ($this->account_id === null) {
            $email_accounts = Site::getCurrentUser()->getEmailAccounts();
            if (count($email_accounts) > 0) {
                $this->account_id = current($email_accounts)['id'];
            }
        }

        if (!knitting_needed($this->body)) {
            $this->body_parsed = $parsedown->text($this->body);
        }

        $this->db->insert_update('survey_emails', array(
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

        return $this;
    }
    
    public function displayForRun($prepend = '') {
        $dialog = Template::get($this->getTemplatePath(), array(
                    'email' => $this,
                    'prepend' => $prepend,
                    'email_accounts' => Site::getCurrentUser()->getEmailAccounts(),
                    'body' => $this->body,
                    'subject' => $this->subject,
                    'account_id' => $this->account_id,
                    'cron_only' => $this->cron_only,
                    'recipient_field' => $this->recipient_field,
                    'potentialRecipientFields' => $this->getPotentialRecipientFields(),
        ));

        return parent::runDialog($dialog);
    }
    
    protected function getPotentialRecipientFields() {
        $stmt = $this->db->prepare("
            SELECT survey_studies.name AS survey,survey_items.name AS item FROM survey_items
                LEFT JOIN survey_studies ON survey_studies.id = survey_items.study_id
                LEFT JOIN survey_run_units ON survey_studies.id = survey_run_units.unit_id
                LEFT JOIN survey_runs ON survey_runs.id = survey_run_units.run_id
            WHERE survey_runs.id = :run_id AND survey_items.type = 'email'"
        );
        
        // fixme: if the last reported email thing is known to work, show only linked email addresses here.
        $stmt->bindValue(':run_id', $this->run->id);
        $stmt->execute();

        $recips = [['id' => $this->mostrecent, 'text' => $this->mostrecent]];
        while ($res = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $email = $res['survey'] . "$" . $res['item'];
            $recips[] = ["id" => $email, "text" => $email];
        }
        
        return $recips;
    }

    public function getSubject(UnitSession $unitsession = null) {
        if ($this->subject_parsed === null) {
            if (knitting_needed($this->subject)) {
                if ($unitsession !== null) {
                    $this->subject_parsed = $this->getParsedText($this->subject, $unitsession);
                } else {
                    return false;
                }
            } else {
                $this->subject_parsed = $this->subject;
            }
        }
        
        return $this->subject_parsed;
    }

    protected function editParsedBody(UnitSession $unitsession = null) {
        $sess = null;
        $run_name = null;
        if ($unitsession !== null) {
            $run_name = $unitsession->runSession->getRun()->name;
            $sess = $unitsession->runSession->session;
        }

        return do_run_shortcodes($this->body_parsed, $run_name, $sess);
    }

    protected function getBody(UnitSession $unitsession = null) {
        $response = $this->getParsedBody($this->body, $unitsession, ['email_embed' => true]);
        if (isset($response['body'])) {
            $this->body_parsed = $response['body'];
        }
        
        if (isset($response['images'])) {
            $this->images = $response['images'];
        }
        
        $this->body_parsed = $this->editParsedBody($unitsession);
        return $this->body_parsed;
    }



    public function sendMail(UnitSession $unitSession, $who = null) {
        $this->mail_queued = $this->mail_sent = false;
        $this->recipient = $who !== null ? $who : $unitSession->runSession->getRecipientEmail($this->recipient_field, false, $unitSession);

        if ($this->recipient == null) {
            $error = opencpu_last_error();
            alert("We could not find an email recipient. Session: {$unitSession->runSession->session}.", 'alert-danger');
            $this->errors['log'] = $this->getLogMessage(
                'no_recipient', 
                "We could not find an email recipient. Session: {$unitSession->runSession->session}." . ($error ? " Error: {$error}" : "")
            );
            notify_study_admin($unitSession, 'Email unit: could not find an email recipient for session ' . $unitSession->runSession->session, 'error');
            return false;
        }

        if ($this->account_id === null) {
            alert("The study administrator (you?) did not set up an email account. <a href='" . admin_url('mail') . "'>Do it now</a> and then select the account in the email dropdown.", 'alert-danger');
            $this->errors['log'] = $this->getLogMessage('no_sender', "The study administrator (you?) did not set up an email account.");
            notify_study_admin($unitSession, 'Email unit: no sender account configured in study', 'error');
            return false;
        }

        $run_session = $unitSession->runSession;
        $testing = !$run_session || $run_session->isTesting();

        $acc = new EmailAccount($this->account_id, null);
        $mailing_themselves = (is_array($acc->account) && $acc->account["from"] === $this->recipient) ||
                (($user = Site::getCurrentUser()) && $user->email === $this->recipient) ||
                ($this->run && $this->run->getOwner()->email === $this->recipient);

        // Use the new RateLimitService for rate limiting
        $rateLimit = new RateLimitService($this->db, $testing, $mailing_themselves);
        $result = $rateLimit->isAllowedToSend($this->recipient, 'survey_email_log');

        if (!$result['allowed']) {
            $this->errors['log'] = $this->getLogMessage('error_send_eligible', $result['message']);
            $error = "Session: {$unitSession->runSession->session}:\n {$result['message']}";
            alert(nl2br($error), 'alert-danger');
            notify_study_admin($unitSession, 'Email unit: rate limit prevented sending email. ' . $result['message'], 'error');
            return false;
        }

        if ($result['message'] !== null) {
            $this->messages['log'] = $this->getLogMessage(null, $result['message']);
            $warning = "Session: {$unitSession->runSession->session}:\n {$result['message']}";
            alert(nl2br($warning), 'alert-info');
        }

        $subject = $this->getSubject($unitSession);
        if($subject === null || $subject === false || $subject === '') {
            $error = opencpu_last_error();
            $this->errors['log'] = $this->getLogMessage('no_email_subject', 'No email subject set. ' . $error);
            alert('Email subject empty or could not be dynamically generated.', 'alert-danger');
            return false;
        }
        
        $body = $this->getBody($unitSession);
        if($body === null || $body === false || $body === '') {
            $error = opencpu_last_error();
            $this->errors['log'] = $this->getLogMessage('no_email_body', 'No email body set. ' . $error);
            alert('Email body empty or could not be dynamically generated.', 'alert-danger');
            return false;
        }

        if (!filter_var($this->recipient, FILTER_VALIDATE_EMAIL)) {
            $this->errors['log'] = $this->getLogMessage('invalid_email', 'Recipient email address is not valid: ' . $this->recipient);
            alert('Intended recipient was not a valid email address: ' . $this->recipient, 'alert-danger');
            return false;
        }

        // if formr is configured to use the email queue then add mail to queue and return
        if (Config::get('email.use_queue', false) === true) {
            return $this->queueNow($acc, $subject, $body, $unitSession);
        } else {
            return $this->sendNow($acc, $subject, $body, $unitSession);
        }

        
    }

    protected function queueNow(EmailAccount $acc, string $subject, string $body, UnitSession $unitSession) {
        $this->mail_queued = $this->db->insert('survey_email_log', array(
            'subject' => $subject,
            'status' => 0,
            'session_id' => $unitSession->id,
            'email_id' => $this->id,
            'message' => $body,
            'recipient' => $this->recipient,
            'created' => mysql_datetime(),
            'account_id' => (int) $this->account_id,
            'meta' => json_encode(array(
                'embedded_images' => $this->images,
                'attachments' => ''
            )),
        ));
        
        return $this->mail_queued;
    }

    protected function sendNow(EmailAccount $acc, string $subject, string $body, UnitSession $unitSession) {
        $mail = $acc->makeMailer();

//		if($this->html)
        $mail->IsHTML(true);

        $mail->AddAddress($this->recipient);
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        foreach ($this->images as $image_id => $image) {
            $local_image = APPLICATION_ROOT . 'tmp/' . uniqid() . $image_id;
            copy($image, $local_image);
            register_shutdown_function(function() use ($local_image) {
                unlink($local_image);
            });

            if (!$mail->AddEmbeddedImage($local_image, $image_id, $image_id, 'base64', 'image/png')) {
                alert("Could not embed image with id '{$image_id}'", 'alert-danger');
            }
        }
        
        if ($mail->Send()) {
            $this->mail_sent = true;
            $this->logMail($unitSession); 
        } else {
            alert('Email with the subject "' . h($mail->Subject) . '" was not sent to ' . h($this->recipient) . ':<br>' . $mail->ErrorInfo, 'alert-danger');
        }

        return $this->mail_sent;
    }

    protected function numberOfEmailsSent() {
        $log = $this->db->prepare("SELECT
			SUM(created > DATE_SUB(NOW(), INTERVAL 1 MINUTE)) AS in_last_1m,
			SUM(created > DATE_SUB(NOW(), INTERVAL 10 MINUTE)) AS in_last_10m,
			SUM(created > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS in_last_1h,
			SUM(created > DATE_SUB(NOW(), INTERVAL 1 DAY)) AS in_last_1d,
			SUM(1) AS in_last_1w
			FROM `survey_email_log`
			WHERE recipient = :recipient AND `status` = 1 AND created > DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $log->bindParam(':recipient', $this->recipient);
        $log->execute();
        
        return $log->fetch(PDO::FETCH_ASSOC);
    }

    protected function logMail(UnitSession $unitSession) {
        $query = "INSERT INTO `survey_email_log` (session_id, email_id, created, recipient) VALUES (:session_id, :email_id, NOW(), :recipient)";
        $this->db->exec($query, array(
            'session_id' => $unitSession->id,
            'email_id' => $this->id,
            'recipient' => $this->recipient,
        ));
    }

    public function test() {
        if (!($unitSession = $this->grabRandomSession())) {
            $this->noTestSession();
            return null;
        }
 
        $user = Site::getCurrentUser();
        $receiver = $user->getEmail();

        $output = "<h4>Recipient</h4>";
        $recipient_field = $unitSession->runSession->getRecipientEmail($this->recipient_field, true, $unitSession);
        if ($recipient_field instanceof OpenCPU_Session) {
            $output .= opencpu_debug($recipient_field, null, 'text');
        } else {
            $output .= $this->mostrecent . ": " . $recipient_field;
        }

        $output .= "<h4>Subject</h4>";
        if (knitting_needed($this->subject)) {
            $output .= $this->getParsedText($this->subject, $unitSession, ['admin' => true]);
        } else {
            $output .= $this->getSubject($unitSession);
        }

        $output .= "<h4>Body</h4>";
        $output .= $this->getParsedBody($this->body, $unitSession, ['admin' => true]);

        $output .= "<h4>Attempt to send email</h4>";
        if ($this->sendMail($unitSession, $receiver)) {
            $output .= "<p>An email was sent to your own email address (" . h($receiver) . ").</p>";
        } else {
            $output .= "<p>No email sent.</p>";
        }

        $results = $this->getSampleSessions();
        if ($results) {
            if (empty($this->recipient_field)) {
                $this->recipient_field = 'survey_users$email';
            }

            $test_tpl = '
				<table class="table table-striped">
					<thead>
						<tr>
							<th>Code (Position)</th>
							<th>Test</th>
						</tr>
						<tbody>%{rows}</tbody>
					</thead>
				</table>
			';

            $row_tpl = '
				<tr>
					<td style="word-wrap:break-word;max-width:150px"><small>%{session} (%{position})</small></td>
					<td class="%{class}">%{result}</td>
				<tr>
			';

            $rows = '';
            foreach ($results as $unitSession) {
                $email = stringBool($unitSession->runSession->getRecipientEmail($this->recipient_field, false, $unitSession));
                $class = filter_var($email, FILTER_VALIDATE_EMAIL) ? '' : 'text-warning';
                $rows .= Template::replace($row_tpl, array(
                            'session' => $unitSession->runSession->session,
                            'position' => $unitSession->runSession->position,
                            'result' => stringBool($email),
                            'class' => $class,
                ));
            }

            $output .= Template::replace($test_tpl, ['rows' => $rows]);
        }

        return $output;
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        // If emails should be sent only when cron is active and unit is not called by cron, then end it and move on
        $data = [];
        if ($this->cron_only && !$unitSession->isExecutedByCron()) {
            $data['log'] = $this->getLogMessage('email_skipped_user_active');
            $data['end_session'] = true;
            return $data;
        }

        // Check if user is enabled to receive emails
        if (!$unitSession->runSession->canReceiveMails()) {
            $data['log'] = $this->getLogMessage('email_skipped_user_disabled', "User {$unitSession->runSession->session} disabled receiving emails at this time");
            $data['content'] = "<p>User <code>{$unitSession->runSession->session}</code> disabled receiving emails at this time </p>";
            return $data;
        }

        // Try to send email
        $err = $this->sendMail($unitSession);
        if ($this->mail_sent || $this->mail_queued) {
            $log = array_val($this->messages, 'log', ['result_log' => null]);
            $data['log'] = $this->getLogMessage(($this->mail_queued ? 'email_queued' : 'email_sent'), $log['result_log']);
            $data['end_session'] = $data['move_on'] = true;
        } else {
            $data['log'] = array_val($this->errors, 'log', $this->getLogMessage('error_email'));
            $data['content'] = $err;
        }
  
        return $data;
    }

}
