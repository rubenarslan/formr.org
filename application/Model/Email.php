<?php

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

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
                $this->cron_only = (int) $vars['cron_only'];

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
            $email_accounts = Site::getCurrentUser()->getEmailAccounts();
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

    public function getSubject() {
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
        $run_name = null;
        if (isset($this->run_name)) {
            $run_name = $this->run_name;
            $sess = isset($this->session) ? $this->session : "TESTCODE";
        }

        $body = do_run_shortcodes($body, $run_name, $sess);
        return $body;
    }

    protected function getBody($embed_email = true) {
        if ($this->run_session_id):
            $response = $this->getParsedBody($this->body, true);
            if ($response === false):
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

        $recips = array(array("id" => $this->mostrecent, "text" => $this->mostrecent));
        while ($res = $get_recips->fetch(PDO::FETCH_ASSOC)):
            $email = $res['survey'] . "$" . $res['item'];
            $recips[] = array("id" => $email, "text" => $email);
        endwhile;
        return $recips;
    }

    public function displayForRun($prepend = '') {
        $dialog = Template::get($this->getUnitTemplatePath(), array(
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

    public function getRecipientField($return_format = 'json', $return_session = false) {
        if (empty($this->recipient_field) || $this->recipient_field === $this->mostrecent) {
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

            $get_recip = $this->dbh->prepare($recent_email_query);
            $get_recip->bindValue(':run_id', $this->run_id);
            $get_recip->bindValue(':run_session_id', $this->run_session_id);
            $get_recip->execute();

            $res = $get_recip->fetch(PDO::FETCH_ASSOC);
            $recipient = array_val($res, 'email', null);
        } else {
            $opencpu_vars = $this->getUserDataInRun($this->recipient_field);
            $recipient = opencpu_evaluate($this->recipient_field, $opencpu_vars, $return_format, null, $return_session);
        }

        return $recipient;
    }

    public function sendMail($who = NULL) {
        $this->mail_sent = false;
        $this->recipient = $who !== null ? $who : $this->getRecipientField();

        if ($this->recipient == null) {
            //formr_log("Email recipient could not be determined from this field definition " . $this->recipient_field);
            alert("We could not find an email recipient. Session: {$this->session}", 'alert-danger');
            return false;
        }

        if ($this->account_id === null) {
            alert("The study administrator (you?) did not set up an email account. <a href='" . admin_url('mail') . "'>Do it now</a> and then select the account in the email dropdown.", 'alert-danger');
            return false;
        }

        $run_session = $this->run_session;

        $testing = !$run_session || $run_session->isTesting();

        $acc = new EmailAccount($this->dbh, $this->account_id, null);
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
            $error = "Session: {$this->session}:\n {$error}";
            alert(nl2br($error), 'alert-danger');
            return false;
        }

        if ($warning !== null) {
            $warning = "Session: {$this->session}:\n {$warning}";
            alert(nl2br($warning), 'alert-info');
        }

        // if formr is configured to use the email queue then add mail to queue and return
        if (Config::get('email.use_queue', false) === true && filter_var($this->recipient, FILTER_VALIDATE_EMAIL)) {
            $this->mail_sent = $this->dbh->insert('survey_email_queue', array(
                'subject' => $this->getSubject(),
                'message' => $this->getBody(),
                'recipient' => $this->recipient,
                'created' => mysql_datetime(),
                'account_id' => (int) $this->account_id,
                'meta' => json_encode(array(
                    'session_id' => $this->session_id,
                    'email_id' => $this->id,
                    'embedded_images' => $this->images,
                    'attachments' => ''
                )),
            ));
            return $this->mail_sent;
        }

        $mail = $acc->makeMailer();

//		if($this->html)
        $mail->IsHTML(true);

        $mail->AddAddress($this->recipient);
        $mail->Subject = $this->getSubject();
        $mail->Body = $this->getBody();

        if (filter_var($this->recipient, FILTER_VALIDATE_EMAIL) AND $mail->Body !== false AND $mail->Subject !== false):
            foreach ($this->images AS $image_id => $image):
                $local_image = APPLICATION_ROOT . 'tmp/' . uniqid() . $image_id;
                copy($image, $local_image);
                register_shutdown_function(create_function('', "unlink('{$local_image}');"));

                if (!$mail->AddEmbeddedImage($local_image, $image_id, $image_id, 'base64', 'image/png')):
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
            if (!filter_var($this->recipient, FILTER_VALIDATE_EMAIL)):
                alert('Intended recipient was not a valid email address: ' . $this->recipient, 'alert-danger');
            endif;
            if ($mail->Body === false):
                alert('Email body empty or could not be dynamically generated.', 'alert-danger');
            endif;
            if ($mail->Subject === false):
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
        if ($this->knittingNeeded($this->subject)) {
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

    protected function sessionCanReceiveMails() {
        // If not executing under a run session or no_mail is null the user can receive email
        if (!$this->run_session || $this->run_session->no_mail === null) {
            return true;
        }

        // If no mail is 0 then user has choose not to receive emails
        if ((int) $this->run_session->no_mail === 0) {
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
        $this->execPushNotifications();
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

    private function execPushNotifications() {
        error_log('email unit execPushNotifications: ' . $this->run_session->session);

        $push_auth = Config::get('push_auth');

        $stmt = $this->dbh->prepare('SELECT * FROM survey_push_subscriptions WHERE run_id = ? and session LIKE ?');
        $stmt->execute(array($this->run->id, $this->run_session->session));

        if ($stmt->rowCount() > 0) {
            error_log('Datenbankeinträge Push-Subscriptions: ' . $stmt->rowCount());

            $notifications = array();
            foreach ($stmt->fetchAll() as $row) {
               error_log('Push-Sub: ' . $row['endpoint']);
               $notifications[] = [
                   'subscription' => Subscription::create([
                       'endpoint' => $row['endpoint'],
                       'publicKey' => $row['p256dh'],
                       'authToken' => $row['auth']
                   ]),
                   'payload' => '{msg: "Please continue your survey!", url: "' . run_url($this->run->name) . '"}'
               ];
            }
    
            $webPush = new WebPush($push_auth);
            foreach ($notifications as $notification) {
                $webPush->sendNotification($notification['subscription'], $notification['payload']);
            }
            foreach ($webPush->flush() as $report) {
               $endpoint = $report->getRequest()->getUri()->__toString();
    
               if ($report->isSuccess()) {
                  error_log("[v] Message sent successfully for subscription {$endpoint}.");
               } else {
                  error_log("[x] Message failed to sent for subscription {$endpoint}: {$report->getReason()}");
               }
           }
       }
    }
}
