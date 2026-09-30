<?php

class Email extends RunUnit {

    public $errors = array();
    public $id = null;
    public $unit = null;
    public $icon = "fa-envelope";
    public $type = "Email";
    
    protected $mail_queued = false;
    protected $mail_sent = false;
    /** set by sendNow() when the mail guard refused the recipient (terminal, no retry) */
    protected $mail_guard_refused = false;
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
            // `(int) isset(...)` read PRESENCE, not value. That works for the
            // admin form, which posts the checkbox only when it is ticked, but
            // it inverts every caller that sends the field explicitly: the v1
            // API, a run import, the MCP. `cron_only: 0` became 1. And since
            // cron_only is in $export_attribs, exporting a run and importing it
            // back turned every Email in it cron-only — after which a
            // participant who reached one in their browser was skipped with
            // `email_skipped_user_active` instead of being mailed. Absence
            // still means 0, so the form behaves exactly as before.
            $options['cron_only'] = isset($options['cron_only']) ? (int) (bool) $options['cron_only'] : 0;
            $options['html'] = 1;
            $this->assignProperties($options);
        }

        // The email account must belong to the run owner: a structure-import
        // body or an ajax_save_run_unit POST could otherwise re-point this
        // unit at any other admin's survey_email_accounts row by id and have
        // sendMail() dispatch through the victim's SMTP credentials. One gate
        // for every source — posted (parent::create() assigned it), imported,
        // or re-assigned from $options in the body branch above. sendMail()
        // scopes its own load to the run owner as well, for rows written
        // before this gate existed.
        if ($this->account_id !== null && !$this->isOwnedBy('survey_email_accounts', (int) $this->account_id, (int) $this->run->user_id)) {
            $this->account_id = null;
        }

        if ($this->account_id === null) {
            $email_accounts = Site::getCurrentUser()->getEmailAccounts();
            if (count($email_accounts) > 0) {
                $this->account_id = current($email_accounts)['id'];
            }
        }

        if (!knitting_needed($this->body)) {
            $this->body_parsed = parsedown_text_safe($parsedown, $this->body, 'the email body');
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
        // Walk from the run's units to their studies: a Survey or Form unit
        // IS its study (survey_run_units.unit_id = survey_studies.id).
        // DISTINCT: a study placed twice in the run must not list its email
        // item twice.
        $stmt = $this->db->prepare("
            SELECT DISTINCT survey_studies.name AS survey, survey_items.name AS item
            FROM survey_run_units
                INNER JOIN survey_studies ON survey_studies.id = survey_run_units.unit_id
                INNER JOIN survey_items ON survey_items.study_id = survey_studies.id
            WHERE survey_run_units.run_id = :run_id AND survey_items.type = 'email'"
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



    /**
     * @param bool $isTest true when the admin's "Test" button drives this.
     *   The test borrows a REAL participant's unit session for R context
     *   (grabRandomSession) but sends to the admin, so the resulting
     *   survey_email_log row must not carry that participant's session_id —
     *   see queueNow().
     */
    public function sendMail(UnitSession $unitSession, $who = null, $isTest = false) {
        $this->mail_queued = $this->mail_sent = $this->mail_guard_refused = false;
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

        // Scoped to the run owner: create()'s gate only covers rows written
        // since it existed, and this is where the account's SMTP credentials
        // are actually decrypted and used.
        $acc = new EmailAccount($this->account_id, $this->run ? (int) $this->run->user_id : null);
        if (!$acc->valid) {
            alert("The email account selected for this unit does not belong to the study owner. Select one of your own accounts in the email unit.", 'alert-danger');
            $this->errors['log'] = $this->getLogMessage('no_sender', "The email account selected for this unit does not belong to the study owner.");
            notify_study_admin($unitSession, 'Email unit: the sender account is not owned by the study owner', 'error');
            return false;
        }
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
            return $this->queueNow($acc, $subject, $body, $unitSession, $isTest);
        } else {
            return $this->sendNow($acc, $subject, $body, $unitSession, $isTest);
        }

        
    }

    protected function queueNow(EmailAccount $acc, string $subject, string $body, UnitSession $unitSession, $isTest = false) {
        // v1.8.0: a test send must not be attributed to the participant whose
        // unit session it borrowed for R context. EmailQueue::logResult()
        // UPDATEs survey_unit_sessions by session_id alone — no unit_id, no
        // `ended IS NULL` — so the daemon would stamp result='email_sent',
        // result_log=NULL onto that participant's row, ended or not. On a row
        // that had recorded email_skipped_user_disabled that is a false record
        // of mailing someone who opted out; on a row still inside its retry
        // window it also matches getUnitSessionOutput()'s terminal-result
        // allowlist, so the run daemon then ADVANCES that participant past an
        // email never sent to them. A null session_id makes the daemon skip
        // the unit-session UPDATE entirely (its `if ($session_id)` guard).
        //
        // The key is randomised for the same reason: keyed on the borrowed
        // session it would collide with that participant's real email and
        // report a phantom success (affected = 0 still sets mail_queued).
        if ($isTest) {
            $session_id = null;
            $idempotency_key = "emailtest:{$this->id}:" . crypto_token(16);
        } else {
            $session_id = $unitSession->id;
            $idempotency_key = null; // set below
        }

        // Track A A4 — closes R5. The idempotency_key is unique per
        // (unit_session, email_id), so a SIGKILL'd daemon's restart
        // re-attempt collides on UNIQUE and the second INSERT is a
        // silent no-op via ON DUPLICATE KEY UPDATE id=id. Without this,
        // restart re-queues the same email for the mail_daemon to send
        // a second time. The v0.25.7 terminal-result guard in
        // getUnitSessionOutput catches the case where this row INSERTed
        // and result was set; this guard catches the gap between row
        // INSERT and result UPDATE. See REFACTOR_QUEUE_PLAN.md A4.
        if ($idempotency_key === null) {
            $idempotency_key = "email:{$unitSession->id}:{$this->id}";
        }

        $affected = $this->db->exec(
            "INSERT INTO `survey_email_log`
                (`subject`, `status`, `session_id`, `email_id`, `message`, `recipient`, `created`, `account_id`, `meta`, `idempotency_key`)
             VALUES
                (:subject, 0, :session_id, :email_id, :message, :recipient, :created, :account_id, :meta, :idempotency_key)
             ON DUPLICATE KEY UPDATE `id` = `id`",
            [
                'subject'         => $subject,
                'session_id'      => $session_id,
                'email_id'        => $this->id,
                'message'         => $body,
                'recipient'       => $this->recipient,
                'created'         => mysql_datetime(),
                'account_id'      => (int) $this->account_id,
                'meta'            => json_encode([
                    'embedded_images' => $this->images,
                    'attachments'     => '',
                ]),
                'idempotency_key' => $idempotency_key,
            ]
        );

        // affected = 1 → fresh INSERT. affected = 0 → duplicate, no-op.
        // Either way the queued state is "an email row exists for this
        // (us, email)" which is the contract callers care about.
        $this->mail_queued = $affected !== false;
        return $this->mail_queued;
    }

    protected function sendNow(EmailAccount $acc, string $subject, string $body, UnitSession $unitSession, $isTest = false) {
        // Audit F21 (2026-07): claim before sending, mirroring queueNow,
        // so a crash between $mail->Send() and the audit write cannot
        // permit a duplicate send on retry. The claim row IS the log row
        // (idempotency_key = "email:{unit_session}:{email_id}", the same
        // UNIQUE key queueNow uses). Claimed at STATUS_SENDING — NOT 0 —
        // because the mail daemon polls status=0 and would otherwise pick
        // the claim up during the SMTP window and send the same email a
        // second time (use_queue=false host with the daemon running).
        // 'handled' = the row is already SENT, or QUEUED (daemon owns it).
        //
        // v1.8.0: same reasoning as queueNow() — a test send borrows a real
        // participant's unit session for R context but must not be recorded
        // against it, and must not adopt or collide with that participant's
        // claim (which would report a phantom success via 'handled').
        $idempotency_key = $isTest
            ? "emailtest:{$this->id}:" . crypto_token(16)
            : "email:{$unitSession->id}:{$this->id}";
        $claim = EmailQueue::claimSyncSend([
            'subject'         => $subject,
            'session_id'      => $isTest ? null : $unitSession->id,
            'email_id'        => $this->id,
            'message'         => $body,
            'recipient'       => $this->recipient,
            'account_id'      => (int) $this->account_id,
            'idempotency_key' => $idempotency_key,
        ]);
        if ($claim === 'handled') {
            $this->mail_sent = true;
            return true;
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
            register_shutdown_function(function() use ($local_image) {
                unlink($local_image);
            });

            // The knitted HTML references the figure as cid:<URLencode(basename)>
            // (see opencpu_knit_email's upload.fun), so the Content-ID we
            // register must be the encoded form too. Registering the raw file
            // name broke every figure whose chunk label contained a space
            // ("my picture" -> cid:my%20picture-1.png vs "my picture-1.png"; #290).
            $cid = self::embeddedImageCid($image_id);
            if (!$mail->AddEmbeddedImage($local_image, $cid, $image_id, 'base64', 'image/png')) {
                alert("Could not embed image with id '{$image_id}'", 'alert-danger');
            }
        }

        if ($mail->Send()) {
            $this->mail_sent = true;
            // Mark the claim row sent for the audit trail (sent stamp
            // mirrors the daemon's logResult).
            $this->db->exec(
                "UPDATE `survey_email_log` SET `status` = " . EmailQueue::STATUS_SENT . ", `sent` = NOW()
                 WHERE `idempotency_key` = :k LIMIT 1",
                ['k' => $idempotency_key]
            );
        } elseif (!empty($mail->guard_blocked)) {
            // Refused by the mail guard (suppressed recipient / dead domain):
            // terminal, exactly as the daemon path records it — retrying
            // can't change the verdict, so the claim is kept as the audit
            // row rather than released into a backoff loop.
            $this->mail_guard_refused = true;
            $this->db->exec(
                "UPDATE `survey_email_log` SET `status` = " . EmailQueue::STATUS_INVALID_RECIPIENT . "
                 WHERE `idempotency_key` = :k AND `status` = " . EmailQueue::STATUS_SENDING . " LIMIT 1",
                ['k' => $idempotency_key]
            );
            $this->errors['log'] = $this->getLogMessage('error_email_guard_refused', (string) $mail->guard_outcome);
            alert('Email with the subject "' . h($mail->Subject) . '" was not sent to ' . h($this->recipient) . ':<br>' . $mail->ErrorInfo, 'alert-danger');
            if (!$isTest) {
                notify_study_admin($unitSession, 'Email unit: the mail guard refused sending to ' . $this->recipient . ' (' . $mail->guard_outcome . '); the participant moved on.', 'error');
            }
        } else {
            // Release the claim so a later retry can send (the failure
            // was delivery, not a duplicate). Only drop OUR in-flight claim.
            $this->db->exec(
                "DELETE FROM `survey_email_log` WHERE `idempotency_key` = :k AND `status` = " . EmailQueue::STATUS_SENDING . " LIMIT 1",
                ['k' => $idempotency_key]
            );
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
        if ($this->sendMail($unitSession, $receiver, true)) {
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
        // Idempotency guard: a successful prior send (sync via sendNow,
        // queued via queueNow, or admin-skipped) leaves the row's result
        // set. Don't re-fire sendMail — that would deliver a duplicate
        // SMTP message / re-insert into survey_email_log. The position-
        // recheck guard in RunSession::execute should already prevent
        // the duplicate-cascade path that would re-execute this row;
        // this is belt-and-braces. See tests/e2e/double-expiry.spec.js.
        if (in_array($unitSession->result, ['email_sent', 'email_queued', 'email_skipped_user_active', 'email_skipped_user_disabled'], true)) {
            return ['end_session' => true, 'move_on' => true];
        }

        // If emails should be sent only when cron is active and unit is not called by cron, then end it and move on
        $data = [];
        if ($this->cron_only && !$unitSession->isExecutedByCron()) {
            $data['log'] = $this->getLogMessage('email_skipped_user_active');
            $data['end_session'] = true;
            return $data;
        }

        // Check if user is enabled to receive emails
        if (!$unitSession->runSession->canReceiveMails()) {
            // A disabled recipient is a terminal SKIP: end the unit session and
            // advance, exactly as the cron_only skip above does.
            //
            // v1.8.0: this branch used to return log+content only. That halted
            // the run — executeUnitSession() returns the content as the page
            // body BEFORE reaching its fall-through moveOn(), leaving the row
            // at queued=0, which the daemon pickup cannot see. On the web path
            // the participant got a dead-end page; on a CRON cascade nothing
            // rescheduled it at all, so every snoozed / no_email participant
            // parked on their Email unit until bin/sweep_stalled_unit_sessions
            // healed it (~30-100 min where that job is scheduled — hourly via
            // ofelia in the docker stack — and never on installs driven by
            // config/formr_crontab, which does not schedule the sweep).
            //
            // The cron_only sibling avoids this only because it sets NO
            // `content`, so it falls through to the final moveOn(). Setting
            // move_on explicitly is clearer than relying on that, and matches
            // the send-success branch below. The `content` string is dropped
            // deliberately: it was participant-facing debug text, and with
            // move_on set it is unreachable anyway (move_on is tested first).
            $data['log'] = $this->getLogMessage('email_skipped_user_disabled', "User {$unitSession->runSession->session} disabled receiving emails at this time");
            $data['end_session'] = $data['move_on'] = true;
            return $data;
        }

        // Try to send email
        $err = $this->sendMail($unitSession);
        if ($this->mail_sent || $this->mail_queued) {
            $log = array_val($this->messages, 'log', ['result_log' => null]);
            $data['log'] = $this->getLogMessage(($this->mail_queued ? 'email_queued' : 'email_sent'), $log['result_log']);
            $data['end_session'] = $data['move_on'] = true;
        } elseif ($this->mail_guard_refused) {
            // A guard verdict is terminal for this recipient: the queued path
            // has already moved the participant on by the time the daemon
            // records the same refusal, so the synchronous path ends the unit
            // the same way instead of re-arming a backoff that can't succeed.
            $data['log'] = $this->errors['log'];
            $data['end_session'] = $data['move_on'] = true;
        } else {
            $data['log'] = array_val($this->errors, 'log', $this->getLogMessage('error_email'));
            $data['content'] = $err;
            $data['queue'] = $this->retryBackoffQueue();
        }

        return $data;
    }

    // retryBackoffQueue() moved to RunUnit in v1.8.0 — External's failed
    // R-address re-arm uses the identical flat tier, and keeping one copy
    // means upgrading the backoff upgrades both unit types together.
    // Email-specific context for the v1.7.1 rationale: a rate-limited
    // recipient (thresholds are per address, instance-wide: 1/min, 10/day,
    // 60/week — an ESM design or a shared lab address reaches them
    // legitimately), an SMTP outage, or a missing recipient all used to
    // produce the ~15 s hot loop the shared docblock describes.

    /**
     * Content-ID under which a knitted figure is embedded. Mirrors R's
     * URLencode(basename(x)) used by opencpu_knit_email's upload.fun, so the
     * cid: reference in the HTML and the registered attachment agree even
     * when the chunk label (and hence the file name) contains a space.
     *
     * Not rawurlencode(): R's URLencode(reserved = FALSE) leaves the RFC 3986
     * reserved set (!$&'()*+,;=:/?@#) and square brackets alone as well as the unreserved
     * characters, whereas rawurlencode() escapes everything outside
     * A-Za-z0-9-._~. A chunk label such as "plot(1)" would otherwise be
     * referenced as cid:plot(1)-1.png but registered as plot%281%29-1.png.
     */
    public static function embeddedImageCid($imageId) {
        return preg_replace_callback(
            '/[^A-Za-z0-9._~!$&\'()*+,;=:\/?@#\[\]-]/',
            function ($m) {
                return sprintf('%%%02X', ord($m[0]));
            },
            (string) $imageId
        );
    }
}
