<?php

/**
 * EmailQueue
 * Process emails in email_queue
 *
 */

class EmailQueue extends Queue {

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

	protected static $name = 'Email-Queue';

	public function __construct(DB $db, array $config) {
		parent::__construct($db, $config);
		$this->itemTtl      = array_val($config, 'queue_item_ttl', 20 * 60);
		$this->itemTries    = array_val($config, 'queue_item_tries', 4);
	}

	/**
	 *
	 * @return PDOStatement
	 */
	protected function getEmailAccountsStatement($account_id) {
		$WHERE = '';
		if ($account_id) {
			$WHERE .= 'account_id = ' . (int) $account_id;
		}

		$query = "SELECT account_id, `from`, from_name, host, port, tls, username, password, auth_key 
				FROM survey_email_queue
				LEFT JOIN survey_email_accounts ON survey_email_accounts.id = survey_email_queue.account_id
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
		$query = 'SELECT id, subject, message, recipient, created, meta FROM survey_email_queue WHERE account_id = ' . (int) $account_id;
		return $this->db->rquery($query);
	}

	/**
	 *
	 * @param array $account
	 * @return PHPMailer
	 */
	protected function getSMTPConnection($account) {
		$account_id = $account['account_id'];
		if (!isset($this->connections[$account_id])) {
			$mail = new PHPMailer();
			$mail->SetLanguage("de", "/");

			$mail->isSMTP();
			$mail->SMTPAuth = true;
			$mail->SMTPKeepAlive = true;
			$mail->Mailer = "smtp";
			$mail->Host = $account['host'];
			$mail->Port = $account['port'];
			if ($account['tls']) {
				$mail->SMTPSecure = 'tls';
			} else {
				$mail->SMTPSecure = 'ssl';
			}
			$mail->Username = $account['username'];
			$mail->Password = $account['password'];

			$mail->setFrom($account['from'], $account['from_name']);
			$mail->AddReplyTo($account['from'], $account['from_name']);
			$mail->CharSet = "utf-8";
			$mail->WordWrap = 65;
			$mail->AllowEmpty = true;
			if (is_array(Config::get('email.smtp_options'))) {
				$mail->SMTPOptions = array_merge($mail->SMTPOptions, Config::get('email.smtp_options'));
			}

			$this->connections[$account_id] = $mail;
		}
		return $this->connections[$account_id];
	}

	protected function closeSMTPConnection($account_id) {
		if (isset($this->connections[$account_id])) {
			unset($this->connections[$account_id]);
		}
	}

	protected function processQueue($account_id = null) {
		$emailAccountsStatement = $this->getEmailAccountsStatement($account_id);
		if ($emailAccountsStatement->rowCount() <= 0) {
			$emailAccountsStatement->closeCursor();
			return false;
		}

		while ($account = $emailAccountsStatement->fetch(PDO::FETCH_ASSOC)) {
			if (!filter_var($account['from'], FILTER_VALIDATE_EMAIL)) {
				$this->db->exec('DELETE FROM survey_email_queue WHERE account_id = ' . (int) $account['account_id']);
				continue;
			}

			list($username, $password) = explode(EmailAccount::AK_GLUE, Crypto::decrypt($account['auth_key']), 2);
			$account['username'] = $username;
			$account['password'] = $password;

			$mailer = $this->getSMTPConnection($account);
			$emailsStatement = $this->getEmailsStatement($account['account_id']);
			while ($email = $emailsStatement->fetch(PDO::FETCH_ASSOC)) {
				if (!filter_var($email['recipient'], FILTER_VALIDATE_EMAIL) || !$email['subject']) {
					$this->registerFailure($email);
					continue;
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
							self::dbg("Unable to attach image: " . $mailer->ErrorInfo . ".\n {$debugInfo}");
						}
					}
				}
				// add attachments (attachments MUST be paths to local file
				if (!empty($meta['attachments'])) {
					foreach ($meta['attachments'] as $attachment) {
						$files[] = $attachment;
						if (!$mailer->addAttachment($attachment, basename($attachment))) {
							self::dbg("Unable to add attachment {$attachment} \n" . $mailer->ErrorInfo . ".\n {$debugInfo}");
						}
					}
				}

				// Send mail
				try {
					if (($sent = $mailer->send())) {
						$this->db->exec("DELETE FROM survey_email_queue WHERE id = " . (int) $email['id']);
						$query = "INSERT INTO `survey_email_log` (session_id, email_id, created, recipient, sent) VALUES (:session_id, :email_id, NOW(), :recipient, :sent)";
						$this->db->exec($query, array(
							'session_id' => $meta['session_id'],
							'email_id' => $meta['email_id'],
							'recipient' => $email['recipient'],
							'sent' => (int) $sent,
						));
						self::dbg("Send Success. \n {$debugInfo}");
					} else {
						throw new Exception($mailer->ErrorInfo);
					}
				} catch (Exception $e) {
					//formr_log_exception($e, 'EmailQueue ' . $debugInfo);
					self::dbg("Send Failure: " . $mailer->ErrorInfo . ".\n {$debugInfo}");
					$this->registerFailure($email);
					// reset php mailer object for this account if smtp sending failed. Probably some limits have been hit
					$this->closeSMTPConnection($account['account_id']);
					$mailer = $this->getSMTPConnection($account);
				}

				$mailer->clearAddresses();
				$mailer->clearAttachments();
				$mailer->clearAllRecipients();
				$this->clearFiles($files);
			}
			// close sql emails cursor after processing batch
			$emailsStatement->closeCursor();
			// check if smtp connection is lost and kill object
			if (!$mailer->getSMTPInstance()->connected()) {
				$this->closeSMTPConnection($account['account_id']);
			}
		}
		$emailAccountsStatement->closeCursor();
		return true;
	}

	/**
	 * Register email send failure and/or remove expired emails
	 *
	 * @param array $email @array(id, subject, message, recipient, created, meta)
	 */
	protected function registerFailure($email) {
		$id = $email['id'];
		if (!isset($this->failures[$id])) {
			$this->failures[$id] = 0;
		}
		$this->failures[$id]++;
		if ($this->failures[$id] > $this->itemTries || (time() - strtotime($email['created'])) > $this->itemTtl) {
			$this->db->exec('DELETE FROM survey_email_queue WHERE id = ' . (int) $id);
		}
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

	public function run($account_id = null) {
		// loop forever until terminated by SIGINT
		while (!$this->out) {
			try {
				// loop until terminated but with taking some nap
				$sleeps = 0;
				while (!$this->out && $this->rested()) {
					if ($this->processQueue($account_id) === false) {
						// if there is nothing to process in the queue sleep for sometime
						self::dbg("Sleeping because nothing was found in queue");
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

				self::dbg($e->getMessage() . "[" . $error_code . "]");

				self::dbg("Unable to connect. waiting 5 seconds before reconnect.");
				sleep(5);
			}
		}
	}

}
