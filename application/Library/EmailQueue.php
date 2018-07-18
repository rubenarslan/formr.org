<?php

// Process emails from email_queue
class EmailQueue {

	/**
	 * 
	 * @var DB
	 */
	protected $db;

	/**
	 * Interval in seconds for which loop should be rested
	 *
	 * @var int
	 */
	protected $loopInterval;

	/**
	 * Flag used to exit or stay in run loop
	 *
	 * @var boolean
	 */
	protected $out = false;

	/**
	 * Number of times mailer is allowed to sleep before exiting
	 *
	 * @var int
	 */
	protected $allowedSleeps = 120;

	/**
	 * Number of seconds mailer should sleep before checking if there is something in queue
	 *
	 * @var int
	 */
	protected $sleep = 15;
	protected $itemTtl;
	protected $itemTries;

	/**
	 *
	 * @var array
	 */
	protected $failures = array();

	/**
	 *
	 * @var PHPMailer[]
	 */
	protected $connections = array();

	public function __construct(DB $db) {
		$this->db = $db;
		$this->loopInterval = Config::get('email.queue_loop_interval', 5);
		$this->itemTtl = Config::get('email.queue_item_ttl', 20 * 60);
		$this->itemTries = Config::get('email.queue_item_tries', 4);
		// Register signal handlers that should be able to kill the cron in case some other weird shit happens 
		// apart from cron exiting cleanly
		// declare signal handlers
		if (extension_loaded('pcntl')) {
			declare(ticks = 1);

			pcntl_signal(SIGINT, array(&$this, 'interrupt'));
			pcntl_signal(SIGTERM, array(&$this, 'interrupt'));
			pcntl_signal(SIGUSR1, array(&$this, 'interrupt'));
		} else {
			self::$dbg = true;
			self::dbg('pcntl extension is not loaded');
		}
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
			if (isset($account['username'])) {
				$mail->Username = $account['username'];
				$mail->Password = $account['password'];
			} else {
				$mail->SMTPAuth = false;
				$mail->SMTPSecure = false;
			}
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

	private function rested() {
		static $last_access;
		if (!is_null($last_access) && $this->loopInterval > ($usleep = (microtime(true) - $last_access))) {
			usleep(1000000 * ($this->loopInterval - $usleep));
		}

		$last_access = microtime(true);
		return true;
	}

	private static function dbg($str) {
		$args = func_get_args();
		if (count($args) > 1) {
			$str = vsprintf(array_shift($args), $args);
		}

		$str = date('Y-m-d H:i:s') . ' Email-Queue: ' . $str . PHP_EOL;
		if (DEBUG) {
			echo $str;
			return;
		}
		return error_log($str, 3, get_log_file('email-queue.log'));
	}

	/**
	 * Signal handler
	 *
	 * @param integer $signo
	 */
	public function interrupt($signo) {
		switch ($signo) {
			// Set terminated flag to be able to terminate program securely
			// to prevent from terminating in the middle of the process
			// Use Ctrl+C to send interruption signal to a running program
			case SIGINT:
			case SIGTERM:
				$this->out = true;
				self::dbg("%s Received termination signal", getmypid());
				break;

			// switch the debug mode on/off
			// @example: $ kill -s SIGUSR1 <pid>
			case SIGUSR1:
				if ((self::$dbg = !self::$dbg)) {
					self::dbg("\nEntering debug mode...\n");
				} else {
					self::dbg("\nLeaving debug mode...\n");
				}
				break;
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
