<?php
/**
 * 
 * the email queue takes emails grouped by sending accounts (to minimize opening and closing connections)
 * it sends queued emails and logs the result to the email_log, the user_sessions, and a .log file (the latter is more immediately visible to the researcher)
 * it also takes action if emails fail to send in certain ways
 * - alarms the researcher
 * - turns off the account (to stop expending effort on broken connections)
 */

use PHPMailer\PHPMailer\PHPMailer;

/**
 * EmailQueue
 * Process emails in email_queue
 *
 */
class EmailQueue extends Queue {
    const STATUS_QUEUED = 0;
    const STATUS_SENT = 1;
    // Inline (Email::sendNow) in-flight claim. Deliberately NOT 0: the daemon
    // polls `status = 0` (getEmailAccountsStatement/getEmailsStatement below),
    // and a claim it can see during the inline SMTP window gets sent a second
    // time (review 2026-07, F21 follow-up). See claimSyncSend().
    const STATUS_SENDING = 2;
    const STATUS_INVALID_ATTACHMENT = -5;
    const STATUS_INVALID_SENDER = -5;
    const STATUS_INVALID_RECIPIENT = -4;
    const STATUS_INVALID_SUBJECT = -3;
    const STATUS_FAILED_TO_SEND = -2;

    /**
     * Claim an INLINE (non-queued) send before touching SMTP — the audit row
     * doubles as the idempotency claim (UNIQUE idempotency_key), inserted at
     * STATUS_SENDING so the daemon's status=0 poll can never pick it up
     * mid-send and double-deliver.
     *
     * @param array $fields subject, session_id, email_id, message, recipient,
     *                      account_id, idempotency_key
     * @return string 'own'     — fresh claim, or an orphaned in-flight /
     *                            previously-failed row: the caller owns the
     *                            send (flip to STATUS_SENT on success, delete
     *                            the STATUS_SENDING row on failure)
     *                'handled' — the row is already STATUS_SENT, or
     *                            STATUS_QUEUED (the daemon owns it): the
     *                            caller must NOT send inline
     */
    public static function claimSyncSend(array $fields): string {
        $db = DB::getInstance();
        $affected = $db->exec(
            "INSERT INTO `survey_email_log`
                (`subject`, `status`, `session_id`, `email_id`, `message`, `recipient`, `created`, `account_id`, `idempotency_key`)
             VALUES
                (:subject, " . self::STATUS_SENDING . ", :session_id, :email_id, :message, :recipient, :created, :account_id, :idempotency_key)
             ON DUPLICATE KEY UPDATE `id` = `id`",
            [
                'subject'         => $fields['subject'],
                'session_id'      => $fields['session_id'],
                'email_id'        => $fields['email_id'],
                'message'         => $fields['message'],
                'recipient'       => $fields['recipient'],
                'created'         => mysql_datetime(),
                'account_id'      => (int) $fields['account_id'],
                'idempotency_key' => $fields['idempotency_key'],
            ]
        );
        if ($affected === 1) {
            return 'own'; // fresh claim
        }
        // A row with this idempotency_key already exists — decide by its state.
        $status = $db->findValue('survey_email_log', ['idempotency_key' => $fields['idempotency_key']], 'status');
        if ($status !== false && $status !== null
            && in_array((int) $status, [self::STATUS_SENT, self::STATUS_QUEUED], true)) {
            return 'handled';
        }
        // Orphaned STATUS_SENDING claim (crash between claim and Send) or a
        // failed prior attempt: adopt it and deliver — favour delivery over a
        // silent drop.
        return 'own';
    }


    /**
     *
     * @var PHPMailer[]
     */
    protected $connections = array();

    /**
     *
     * @var int[]
     */
    protected $failures = array();

    /**
     * Number of seconds item should last in queue
     *
     * @var int
     */
    protected $itemTtl;

    /**
     * Number of times the queue can re-try to send the email in case of failures
     * @var int
     */
    protected $itemTries;
    protected $logFile = 'email-queue.log';
    protected $name = 'Email-Queue';
    
    /**
     * An array of IDs containing problematic accounts to skip during email processing
     *
     * @var array
     */
    protected $skipAccounts = array();

    public function __construct(DB $db, array $config) {
        parent::__construct($db, $config);
        $this->itemTtl = array_val($this->config, 'queue_item_ttl', 20 * 60);
        $this->itemTries = array_val($this->config, 'queue_item_tries', 4);
        $this->skipAccounts = array_val($this->config, 'queue_skip_accounts', array());
    }

    /**
     *
     * @return PDOStatement
     */
    /**
     * SQL fragment selecting rows this pass may attempt: never-tried rows,
     * plus previously-failed rows still inside their retry budget (v1.8.0).
     *
     * `status = -2` used to be terminal by accident — sendEmail() wrote it
     * after two immediate attempts and the poll only ever selected
     * status = 0, so nothing looked at the row again. It now means "failed,
     * possibly retryable", bounded by queue_item_tries and spaced by
     * queue_item_ttl; givingUp() stamps the genuinely-finished ones.
     */
    protected function pickupWhere() {
        $retryAfter = (int) max(60, $this->itemTtl / max(1, $this->itemTries));
        return '(`survey_email_log`.`status` = ' . self::STATUS_QUEUED
            . ' OR (`survey_email_log`.`status` = ' . self::STATUS_FAILED_TO_SEND
            . ' AND `survey_email_log`.`tries` < ' . (int) $this->itemTries
            . ' AND (`survey_email_log`.`last_attempt` IS NULL'
            . ' OR `survey_email_log`.`last_attempt` < NOW() - INTERVAL ' . $retryAfter . ' SECOND)))';
    }

    protected function getEmailAccountsStatement($account_id) {
        $WHERE = 'WHERE ' . $this->pickupWhere() . ' AND `survey_email_accounts`.status = 1';
        if ($account_id) {
            $WHERE .= ' AND account_id = ' . (int) $account_id . ' ';
        }

        $query = "SELECT account_id, `session_id`, `survey_email_accounts`.user_id, `from`, from_name, host, port, tls, username, password, auth_key
				FROM survey_email_log
				LEFT JOIN survey_email_accounts ON survey_email_accounts.id = survey_email_log.account_id
				{$WHERE}
				GROUP BY account_id 
				ORDER BY RAND()
				";
        return $this->db->rquery($query);
    }

    /**
     * 
     * @param int $account_id
     * @return PDOStatement
     */
    protected function getEmailsStatement($account_id) {
        $query = 'SELECT id, `session_id`, subject, message, recipient, created, meta, tries'
            . ' FROM survey_email_log WHERE ' . $this->pickupWhere()
            . ' AND account_id = ' . (int) $account_id;
        return $this->db->rquery($query);
    }

    /**
     * Create an SMTP instance given an 'account' object
     * If the instance is already created and connected then we return it.
     *
     * @param array $account
     * @return PHPMailer
     */
    protected function getSMTPConnection($account, $account_connected = null) {
        $account_id = $account['account_id'];
        if(!isset($this->connections[$account_id])) {
            $account_connected = false;
        } else if($account_connected === false) {
            $this->closeSMTPConnection($account_id);   
        } else {
            $account_connected = true;
        }

        if (!$account_connected) {
            $mailer = new GuardedMailer();
            $mailer->guard_category = MailGuardService::CATEGORY_STUDY;
            $mailer->guard_type = 'study';
            $mailer->guard_user_id = isset($account['user_id']) ? (int) $account['user_id'] : null;
            $mailer->SetLanguage("de", "/");

            $mailer->isSMTP();
            $mailer->SMTPAuth = true;
            $mailer->SMTPKeepAlive = true;
            $mailer->Mailer = "smtp";
            $mailer->Host = $account['host'];
            $mailer->Port = $account['port'];
            if ($account['tls']) {
                $mailer->SMTPSecure = 'tls';
            } else {
                $mailer->SMTPSecure = 'ssl';
            }
            if (!empty($account['username'])) {
                $mailer->Username = $account['username'];
                $mailer->Password = $account['password'];
            } else {
                $mailer->SMTPAuth = false;
                $mailer->SMTPSecure = false;
            }
            $mailer->setFrom($account['from'], $account['from_name']);
            $mailer->AddReplyTo($account['from'], $account['from_name']);
            $mailer->CharSet = "utf-8";
            $mailer->WordWrap = 65;
            $mailer->AllowEmpty = true;
            if (is_array(Config::get('email.smtp_options'))) {
                $mailer->SMTPOptions = array_merge($mailer->SMTPOptions, Config::get('email.smtp_options'));
            }

            $this->connections[$account_id] = $mailer;
        }
        
        return $this->connections[$account_id];
    }

    protected function closeSMTPConnection($account_id) {
        if (isset($this->connections[$account_id])) {
            $this->connections[$account_id]->getSMTPInstance()->quit(true);
            $this->connections[$account_id]->getSMTPInstance()->close();
            unset($this->connections[$account_id]);
        }
    }

    /**
     * Record one delivery attempt (v1.8.0). Called before each SMTP try so
     * the budget is spent even if the process dies mid-send — the
     * alternative (counting on success/failure) lets a row that crashes the
     * daemon retry forever.
     */
    protected function countAttempt($email_id) {
        $this->db->exec(
            'UPDATE `survey_email_log` SET `tries` = `tries` + 1, `last_attempt` = NOW() WHERE `id` = :id',
            ['id' => $email_id]
        );
    }

    /**
     * Has this row exhausted its retry budget or outlived its TTL? (v1.8.0)
     */
    protected function isGivingUp(array $email) {
        $tries = (int) array_val($email, 'tries', 0) + 1; // including the attempt just made
        if ($tries >= (int) $this->itemTries) {
            return true;
        }
        $created = array_val($email, 'created');
        return $created && (time() - strtotime($created)) > (int) $this->itemTtl;
    }

    /**
     * Terminal delivery failure — make giving up a visible, deliberate act
     * (v1.8.0).
     *
     * Previously the row simply stopped being selected: the only trace was a
     * dbg() line in the daemon log, while the unit session had already
     * reported `email_queued` and advanced the run. A study lost an email
     * with no signal anywhere an admin looks.
     *
     * The signal goes on the unit session's result/result_log rather than
     * through notify_study_admin(), which requires a live UnitSession and so
     * a RunSession and Run — an object graph the queue does not have and
     * cannot cheaply build (its non-nullable constructors would throw, which
     * is precisely how the v0 API endpoint came to be permanently broken).
     * result/result_log is where an admin investigating a missing email
     * already looks: the user-detail table renders both, and v1.7.1 added
     * them to its CSV export. Emailing the owner is a sensible follow-up
     * once the queue can resolve a run session cheaply.
     */
    protected function giveUp(array $email, $error) {
        $recipient = array_val($email, 'recipient', '(unknown recipient)');
        $note = 'Gave up after ' . (int) $this->itemTries . ' delivery attempts to ' . $recipient
            . '. The participant never received this email. Last error: ' . $error;

        // Retire the row so giving up is actually terminal. Without this,
        // a row that trips isGivingUp()'s TTL arm with `tries` still under
        // budget stays selectable by pickupWhere() — it would be retried
        // (and could DELIVER) after the unit session already recorded
        // "The participant never received this email", and giveUp() would
        // fire again on every remaining pass. `tries = 255` is the same
        // "exhausted, do not retry" sentinel patch 072's backfill uses.
        if (($email_id = (int) array_val($email, 'id', 0))) {
            $this->db->exec(
                'UPDATE `survey_email_log` SET `tries` = 255, `last_attempt` = NOW() WHERE `id` = :id',
                ['id' => $email_id]
            );
        }

        if (($session_id = (int) array_val($email, 'session_id', 0))) {
            $this->db->exec(
                'UPDATE `survey_unit_sessions` SET `result` = :result, `result_log` = :resultlog WHERE `id` = :id',
                ['id' => $session_id, 'result' => 'email_gave_up', 'resultlog' => truncate_result_log($note)]
            );
        }
        formr_log('EmailQueue: ' . $note . ' (email_log id ' . array_val($email, 'id') . ')');
        $this->dbg('Gave up: ' . $note);
    }

    protected function logResult($session_id, $email_id, $status_code, $result, $result_log = null) {
        $this->db->exec(
            'UPDATE `survey_email_log` SET `status` = :status_code, `sent` = NOW() WHERE `id` = :id',
            ['id' => $email_id, 'status_code' => $status_code]
        );
        
        $result_log = truncate_result_log($result_log);

        if($session_id) {
            $this->db->exec(
                'UPDATE `survey_unit_sessions` SET `result` = :result, `result_log` = :resultlog WHERE `id` = :session_id',
                ['session_id' => $session_id, 'result' => $result, 'resultlog' => $result_log]
            );
        }
    }
    
    protected function deactivateAccount($account_id) {
        $this->db->exec('UPDATE `survey_email_accounts` SET `status` = -1 WHERE id = :id', array("id" => (int) $account_id));
    }

    /**
     * 
     * @param \PHPMailer\PHPMailer\PHPMailer $mailer
     * @param array $email [recipient, subject, message, session_id, meta]
     * @param array $account [account_id, from, from_name]
     * @param boolean $first_try Indicates if we are trying to send the email for the first time
     * 
     * @return boolean Returns TRUE is email was sent and FALSE otherwise
     */
    protected function sendEmail($mailer, $email, $account, $first_try = true) {
        if (!filter_var($email['recipient'], FILTER_VALIDATE_EMAIL)) {
            $this->logResult($email['session_id'], $email['id'], self::STATUS_INVALID_RECIPIENT, "error_email_invalid_recipient");
            return false;
        }

        // v1.8.0: spend one try per QUEUE PASS (not per in-process attempt —
        // the $first_try recursion below is one logical try). Counted before
        // the send, so a row that kills the daemon mid-delivery still burns
        // its budget instead of being retried forever.
        if ($first_try) {
            $this->countAttempt($email['id']);
        }
        
        if (!$email['subject']) {
            $this->logResult($email['session_id'], $email['id'], self::STATUS_INVALID_SUBJECT, "error_email_invalid_subject");
            return false;
        }

        $meta = json_decode($email['meta'], true);
        $debugInfo = json_encode(array('id' => $email['id'], 's' => $email['subject'], 'r' => $email['recipient'], 'f' => $account['from']));

        $mailer->Subject = $email['subject'];
        $mailer->msgHTML($email['message']);
        $mailer->addAddress($email['recipient']);
        $files = array();
        
        // add emdedded images
        if (!empty($meta['embedded_images'])) {
            foreach ($meta['embedded_images'] as $imageId => $image) {
                $localImage = APPLICATION_ROOT . 'tmp/formrEA' . uniqid() . $imageId;
                $context = null;
                if ($context_options = Config::get('copy_context')) {
                    $context = stream_context_create($context_options);
                }

                copy($image, $localImage, $context);

                $files[] = $localImage;
                if (!$mailer->addEmbeddedImage($localImage, $imageId, $imageId, 'base64', 'image/png')) {
                    $this->dbg("Unable to attach image: " . $mailer->ErrorInfo . ".\n {$debugInfo}");
                }
            }
        }
        
        // add attachments (attachments MUST be paths to local file
        if (!empty($meta['attachments'])) {
            foreach ($meta['attachments'] as $attachment) {
                $files[] = $attachment;
                if (!$mailer->addAttachment($attachment, basename($attachment))) {
                    $this->dbg("Unable to add attachment {$attachment} \n" . $mailer->ErrorInfo . ".\n {$debugInfo}");
                }
            }
        }

        // Send mail
        try {
            if (($sent = $mailer->send())) {
                $this->logResult($email['session_id'],  $email['id'], self::STATUS_SENT, "email_sent");
                $this->dbg("Send Success. \n {$debugInfo}");
            } elseif (!empty($mailer->guard_blocked)) {
                // Refused by the mail guard (suppressed recipient / dead
                // domain): terminal, never retried — retrying can't change
                // the verdict and would burn the try budget for nothing.
                $this->dbg("Send refused by mail guard ({$mailer->guard_outcome}). \n {$debugInfo}");
                $this->logResult($email['session_id'], $email['id'], self::STATUS_INVALID_RECIPIENT, "error_email_guard_refused", (string) $mailer->guard_outcome);
            } else {
                $this->dbg($mailer->ErrorInfo);
                $this->logResult($email['session_id'],  $email['id'], self::STATUS_FAILED_TO_SEND, "error_email_not_sent", $mailer->ErrorInfo);
                throw new Exception($mailer->ErrorInfo);
            }
        } catch (Exception $e) {
            //formr_log_exception($e, 'EmailQueue ' . $debugInfo);
            $this->dbg("Send Failure: " . $mailer->ErrorInfo . ".\n {$debugInfo}");
            $this->dbg($mailer->ErrorInfo);
            $this->logResult($email['session_id'], $email['id'], self::STATUS_FAILED_TO_SEND, "error_email_not_sent", $mailer->ErrorInfo);

            // Always try a new connection if we encounter an error and smtp client is not connected
            $mailer = $this->getSMTPConnection($account, $mailer->getSMTPInstance()->connected());
            
            // Try sending this particular email one more time otherwise give up
            if($first_try) {
                return $this->sendEmail($mailer, $email, $account, false);
            } else {
                $sent = false;
                // v1.8.0: both in-process attempts failed. The row keeps
                // STATUS_FAILED_TO_SEND, which is no longer terminal — the
                // pickup poll re-selects it while `tries` is under budget and
                // `last_attempt` has aged out. Only when the budget (or the
                // TTL) is spent do we stop, and then we say so.
                if ($this->isGivingUp($email)) {
                    $this->giveUp($email, (string) $mailer->ErrorInfo);
                }
            }
        }

        $mailer->clearAddresses();
        $mailer->clearAttachments();
        $mailer->clearAllRecipients();
        $this->clearFiles($files);
        
        return $sent;
    }

    protected function processQueue($account_id = null) {
        $emailAccountsStatement = $this->getEmailAccountsStatement($account_id);
        if ($emailAccountsStatement->rowCount() <= 0) {
            $emailAccountsStatement->closeCursor();
            return false;
        }

        while ($account = $emailAccountsStatement->fetch(PDO::FETCH_ASSOC)) {
            if (in_array($account['account_id'], $this->skipAccounts)) {
                $this->deactivateAccount($account['account_id']);
                continue;
            }

            list($username, $password) = explode(EmailAccount::AK_GLUE, Crypto::decrypt($account['auth_key']), 2);
            $account['username'] = $username;
            $account['password'] = $password;


            $emailsStatement = $this->getEmailsStatement($account['account_id']);
            while ($email = $emailsStatement->fetch(PDO::FETCH_ASSOC)) {
                $mail_sent = $this->sendEmail($this->getSMTPConnection($account), $email, $account);
            }
            
            // close sql emails cursor after processing batch
            $emailsStatement->closeCursor();
            
            // close connection after processing all emails for that account
            $this->closeSMTPConnection($account['account_id']);
        }
        
        $emailAccountsStatement->closeCursor();
        return true;
    }

    protected function clearFiles($files) {
        if (!empty($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
    

    public function run() {
        $account_id = array_val($this->config, 'account_id', null);

        // loop forever until terminated by SIGINT
        while (!$this->out) {
            try {
                // loop until terminated but with taking some nap
                $sleeps = 0;
                while (!$this->out && $this->rested()) {
                    // Audit F16 (2026-07): keep this long-lived connection's
                    // session time_zone tracking DST (see DB::syncSessionTimezone).
                    DB::getInstance()->syncSessionTimezone();
                    if ($this->processQueue($account_id) === false) {
                        // if there is nothing to process in the queue sleep for sometime
                        $this->dbg("Sleeping because nothing was found in queue");
                        sleep($this->sleep);
                        $sleeps++;
                    }
                    if ($sleeps > $this->allowedSleeps) {
                        // exit to restart supervisor process
                        $this->out = true;
                    }
                }
            } catch (Exception $e) {
                // if connection disappeared - try to restore it
                $error_code = $e->getCode();
                if ($error_code != 1053 && $error_code != 2006 && $error_code != 2013 && $error_code != 2003) {
                    throw $e;
                }

                $this->dbg($e->getMessage() . "[" . $error_code . "]");

                $this->dbg("Unable to connect. waiting 5 seconds before reconnect.");
                sleep(5);
            }
        }
    }

}
