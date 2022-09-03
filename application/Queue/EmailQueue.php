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
    const STATUS_INVALID_ATTACHMENT = -5;
    const STATUS_INVALID_SENDER = -5;
    const STATUS_INVALID_RECIPIENT = -4;
    const STATUS_INVALID_SUBJECT = -3;
    const STATUS_FAILED_TO_SEND = -2;


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
    protected function getEmailAccountsStatement($account_id) {
        $WHERE = 'WHERE `survey_email_log`.`status` = 0 AND `survey_email_accounts`.status = 1';
        if ($account_id) {
            $WHERE .= ' AND account_id = ' . (int) $account_id . ' ';
        }

        $query = "SELECT account_id, `session_id`, `from`, from_name, host, port, tls, username, password, auth_key 
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
        $query = 'SELECT id,  `session_id`, subject, message, recipient, created, meta FROM survey_email_log WHERE `survey_email_log`.`status` = 0 AND account_id = ' . (int) $account_id;
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
            $mailer = new PHPMailer();
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
            if (isset($account['username'])) {
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

    protected function logResult($session_id, $email_id, $status_code, $result, $result_log = null) {
        $this->db->exec(
            'UPDATE `survey_email_log` SET `status` = :status_code, `sent` = NOW() WHERE `id` = :id', 
            ['id' => $email_id, 'status_code' => $status_code]
        );
        
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
                copy($image, $localImage);
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
