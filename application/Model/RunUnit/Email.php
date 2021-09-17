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
                    $this->subject_parsed = $unitsession->getParsedText($this->subject);
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

    public function getRecipientField(UnitSession $unitSession, $return_session = false) {
        if (!$this->recipient_field || $this->recipient_field === $this->mostrecent) {
            $recent_email_query = "
				SELECT survey_items_display.answer AS email FROM survey_unit_sessions
				LEFT JOIN survey_units ON survey_units.id = survey_unit_sessions.unit_id AND survey_units.type = 'Survey'
				LEFT JOIN survey_run_units ON survey_run_units.unit_id = survey_units.id
				LEFT JOIN survey_items_display ON survey_items_display.session_id = survey_unit_sessions.id
				LEFT JOIN survey_items ON survey_items.id = survey_items_display.item_id
				WHERE
				survey_unit_sessions.run_session_id = :run_session_id AND 
				survey_run_units.run_id = :run_id AND 
				survey_items.type = 'email'
				ORDER BY survey_items_display.answered DESC
				LIMIT 1
			";

            $get_recip = $this->db->prepare($recent_email_query);
            $get_recip->bindValue(':run_id', $this->run->id);
            $get_recip->bindValue(':run_session_id', $unitSession->runSession->id);
            $get_recip->execute();

            $res = $get_recip->fetch(PDO::FETCH_ASSOC);
            $recipient = array_val($res, 'email', null);
        } else {
            $opencpu_vars = $unitSession->getRunData($this->recipient_field);
            $recipient = opencpu_evaluate($this->recipient_field, $opencpu_vars, 'json', null, $return_session);
        }

        return $recipient;
    }

    public function sendMail(UnitSession $unitSession, $who = null) {
        $this->mail_queued = $this->mail_sent = false;
        $this->recipient = $who !== null ? $who : $this->getRecipientField($unitSession);

        if ($this->recipient == null) {
            //formr_log("Email recipient could not be determined from this field definition " . $this->recipient_field);
            alert("We could not find an email recipient. Session: {$unitSession->runSession->session}", 'alert-danger');
            $this->errors['log'] = $this-$this->getLogMessage('no_recipient', 'We could not find an email recipient');
            return false;
        }

        if ($this->account_id === null) {
            alert("The study administrator (you?) did not set up an email account. <a href='" . admin_url('mail') . "'>Do it now</a> and then select the account in the email dropdown.", 'alert-danger');
            $this->errors['log'] = $this-$this->getLogMessage('no_recipient', "The study administrator (you?) did not set up an email account.");
            return false;
        }

        $run_session = $unitSession->runSession;

        $testing = !$run_session || $run_session->isTesting();

        $acc = new EmailAccount($this->account_id, null);
        $mailing_themselves = (is_array($acc->account) && $acc->account["from"] === $this->recipient) ||
                (($user = Site::getCurrentUser()) && $user->email === $this->recipient) ||
                ($this->run && $this->run->getOwner()->email === $this->recipient);

        $mails_sent = $this->numberOfEmailsSent();
        $error = null;
        $warning = null;
        if (!$mailing_themselves):
            if ($mails_sent['in_last_1m'] > 0):
                if ($mails_sent['in_last_1m'] < 3 && $testing):
                    $warning = sprintf("We already sent %d mail to this recipient in the last minute. An email was sent, because you're currently testing, but it would have been delayed for a real user, to avoid allegations of spamming.", $mails_sent['in_last_1m']);
                else:
                    $error = sprintf("We already sent %d mail to this recipient in the last minute. No email was sent.", $mails_sent['in_last_1m']);
                endif;
            elseif ($mails_sent['in_last_10m'] > 1):
                if ($mails_sent['in_last_10m'] < 10 && $testing):
                    $warning = sprintf("We already sent %d mail to this recipient in the last 10 minutes. An email was sent, because you're currently testing, but it would have been delayed for a real user, to avoid allegations of spamming.", $mails_sent['in_last_10m']);
                else:
                    $error = sprintf("We already sent %d mail to this recipient in the last 10 minutes. No email was sent.", $mails_sent['in_last_10m']);
                endif;
            elseif ($mails_sent['in_last_1h'] > 2):
                if ($mails_sent['in_last_1h'] < 10 && $testing):
                    $warning = sprintf("We already sent %d mails to this recipient in the last hour. An email was sent, because you're currently testing, but it would have been delayed for a real user, to avoid allegations of spamming.", $mails_sent['in_last_1h']);
                else:
                    $error = sprintf("We already sent %d mails to this recipient in the last hour. No email was sent.", $mails_sent['in_last_1h']);
                endif;
            elseif ($mails_sent['in_last_1d'] > 9 && !$testing):
                $error = sprintf("We already sent %d mails to this recipient in the last day. No email was sent.", $mails_sent['in_last_1d']);
            elseif ($mails_sent['in_last_1w'] > 60 && !$testing):
                $error = sprintf("We already sent %d mails to this recipient in the last week. No email was sent.", $mails_sent['in_last_1w']);
            endif;
        else:
            if ($mails_sent['in_last_1m'] > 1 || $mails_sent['in_last_1d'] > 100):
                $error = sprintf("Too many emails are being sent to the study administrator, %d mails today. Please wait a little.", $mails_sent['in_last_1d']);
            endif;
        endif;

        if ($error !== null) {
            $this->errors['log'] = $this->getLogMessage('error_send_eligible', $error);
            $error = "Session: {$unitSession->runSession->session}:\n {$error}";
            alert(nl2br($error), 'alert-danger');
            return false;
        }

        if ($warning !== null) {
            $this->messages['log'] = $this->getLogMessage(null, $warning);
            $warning = "Session: {$unitSession->runSession->session}:\n {$warning}";
            alert(nl2br($warning), 'alert-info');
        }

        $subject = $this->getSubject($unitSession);
        if($subject === null || $subject === false || $subject === '') {
            $this->errors['log'] = $this->getLogMessage('no_email_subject', 'No email subject set');
            alert('Email subject empty or could not be dynamically generated.', 'alert-danger');
            return false;
        }
        
        $body = $this->getBody($unitSession);
        if($body === null || $body === false || $body === '') {
            $this->errors['log'] = $this->getLogMessage('no_email_body', 'No email body set');
            alert('Email body empty or could not be dynamically generated.', 'alert-danger');
            return false;
        }

        if (!filter_var($this->recipient, FILTER_VALIDATE_EMAIL)) {
            $this->errors['log'] = $this->getLogMessage('invalid_email', 'No valid email recipient set');
            alert('Intended recipient was not a valid email address: ' . $this->recipient, 'alert-danger');
            return false;
        }

        // if formr is configured to use the email queue then add mail to queue and return
        if (Config::get('email.use_queue', false) === true) {
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

        $mail = $acc->makeMailer();

//		if($this->html)
        $mail->IsHTML(true);

        $mail->AddAddress($this->recipient);
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        foreach ($this->images as $image_id => $image) {
            $local_image = APPLICATION_ROOT . 'tmp/' . uniqid() . $image_id;
            copy($image, $local_image);
            register_shutdown_function(create_function('', "unlink('{$local_image}');"));

            if (!$mail->AddEmbeddedImage($local_image, $image_id, $image_id, 'base64', 'image/png')) {
                alert("Could not embed image with id '{$image_id}'", 'alert-danger');
            }
        }
        
        if ($mail->Send()) {
            $this->mail_sent = true;
            $this->logMail($$unitSession); 
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
        $output = '';
        if (!$this->grabRandomSession()) {
            return $output;
        }

        $user = Site::getCurrentUser();
        $receiver = $user->getEmail();

        $output .= "<h4>Recipient</h4>";
        $recipient_field = $this->getRecipientField('', true);
        if ($recipient_field instanceof OpenCPU_Session) {
            $output .= opencpu_debug($recipient_field, null, 'text');
        } else {
            $output .= $this->mostrecent . ": " . $recipient_field;
        }

        $output .= "<h4>Subject</h4>";
        if (knitting_needed($this->subject)) {
            $output .= $this->getParsedTextAdmin($this->subject);
        } else {
            $output .= $this->getSubject();
        }

        $output .= "<h4>Body</h4>";
        $output .= $this->getParsedBodyAdmin($this->body);

        $output .= "<h4>Attempt to send email</h4>";
        if ($this->sendMail($receiver)) {
            $output .= "<p>An email was sent to your own email address (" . h($receiver) . ").</p>";
        } else {
            $output .= "<p>No email sent.</p>";
        }

        $results = $this->getSampleSessions();
        if ($results) {
            if ($this->recipient_field === null OR trim($this->recipient_field) == '') {
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
            foreach ($results as $row) {
                $this->run_session_id = $row['id'];
                $email = stringBool($this->getRecipientField());
                $class = filter_var($email, FILTER_VALIDATE_EMAIL) ? '' : 'text-warning';
                $rows .= Template::replace($row_tpl, array(
                            'session' => $row['session'],
                            'position' => $row['position'],
                            'result' => stringBool($email),
                            'class' => $class,
                ));
            }

            $output .= Template::replace($test_tpl, array('rows' => $rows));
        }
        $this->run_session_id = null;
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
            $data['log'] = $this->getLogMessage('email_skipped_user_disabled');
            $data['body'] = "<p>User <code>{$unitSession->runSession->session}</code> disabled receiving emails at this time </p>";
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
            $data['body'] = $err;
        }
  
        return $data;
    }

}
