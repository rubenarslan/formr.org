<?php

class EmailAccount {

	public $id = null;
	public $user_id = null;
	public $valid = null;

	/**
	 * @var DB
	 */
	private $dbh;

	public function __construct($fdb, $id, $user_id) {
		$this->dbh = $fdb;
		$this->id = (int) $id;
		$this->user_id = (int) $user_id;

		if ($id) {
			$this->account = $this->dbh->findRow('survey_email_accounts', array('id' => $this->id));
			if ($this->account):
				$this->valid = true;
				$this->user_id = (int) $this->account['user_id'];

			endif;
		}
	}

	public function create() {
		$this->id = $this->dbh->insert('survey_email_accounts', array('user_id' => $this->user_id));
		return $this->id;
	}

	public function changeSettings($posted) {
		$this->account = $posted;
		$query = "
			UPDATE `survey_email_accounts` 
			SET `from` = :fromm, `from_name` = :from_name, `host` = :host, `port` = :port, `tls` = :tls, `username` = :username, `password` = :password
			WHERE id = :id LIMIT 1";

		$this->dbh->exec($query, array(
			'id' => $this->id,
			'fromm' => $this->account['from'],
			'from_name' => $this->account['from_name'],
			'host' => $this->account['host'],
			'port' => $this->account['port'],
			'tls' => $this->account['tls'],
			'username' => $this->account['username'],
			'password' => $this->account['password'],
		));
		return true;
	}

	public function test() {
		$RandReceiv = crypto_token(9, true);
		$receiver = $RandReceiv . '@mailinator.com';
		$link = "https://mailinator.com/inbox.jsp?to=".$RandReceiv;

		$mail = $this->makeMailer();

		$mail->AddAddress($receiver);
		$mail->Subject = 'Test';
		$mail->Body = 'You got mail.';

		if (!$mail->Send()) {
			alert($mail->ErrorInfo, 'alert-danger');
		} else {
			redirect_to($link);
		}
	}

	public function makeMailer() {
		$mail = new PHPMailer();
		$mail->SetLanguage("de", "/");

		$mail->IsSMTP();  // telling the class to use SMTP
		$mail->Mailer = "smtp";
		$mail->Host = $this->account['host'];
		$mail->Port = $this->account['port'];
		if ($this->account['tls']) {
			$mail->SMTPSecure = 'tls';
		} else {
			$mail->SMTPSecure = 'ssl';
		}
		$mail->SMTPAuth = true; // turn on SMTP authentication
		$mail->Username = $this->account['username']; // SMTP username
		$mail->Password = $this->account['password']; // SMTP password

		$mail->From = $this->account['from'];
		$mail->FromName = $this->account['from_name'];
		$mail->AddReplyTo($this->account['from'], $this->account['from_name']);
		$mail->CharSet = "utf-8";
		$mail->WordWrap = 65;								 // set word wrap to 50 characters

		return $mail;
	}

}
