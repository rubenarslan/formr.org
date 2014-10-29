<?php
class EmailAccount
{
	public $id = null;
	public $user_id = null;
	public $valid = null;
	private $dbh;
	
	public function __construct($fdb, $id, $user_id) 
	{
		$this->dbh = $fdb;
		$this->id = (int)$id;
		$this->user_id = (int)$user_id;
		
		if($id)
		{
			$acc = $this->dbh->prepare("SELECT * FROM `survey_email_accounts` WHERE id = :id LIMIT 1");
			$acc->bindParam(":id",$this->id);

			$acc->execute() or die(print_r($acc->errorInfo(), true));
			$this->account = $acc->fetch(PDO::FETCH_ASSOC);
			
			if($this->account):
				$this->valid = true;
				$this->user_id = (int)$this->account['user_id'];
				
			endif;
		}
	}
	public function create()
	{
		$create = $this->dbh->prepare("INSERT INTO `survey_email_accounts` (user_id) VALUES (:user_id);");
		$create->bindParam(":user_id",$this->user_id);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->id = (int)$this->dbh->lastInsertId();
		return $this->id;
	}
	public function changeSettings($posted)
	{
		$this->account = $posted;
		$acc = $this->dbh->prepare("UPDATE `survey_email_accounts` 
			SET
			`from` = :fromm,
			`from_name` = :from_name,
			`host` = :host,
			`port` = :port,
			`tls` = :tls,
			`username` = :username,
			`password` = :password
		WHERE id = :id LIMIT 1");
		$acc->bindParam(":id",$this->id);
		$acc->bindParam(":fromm",$this->account['from']);
		$acc->bindParam(":from_name",$this->account['from_name']);
		$acc->bindParam(":host",$this->account['host']);
		$acc->bindParam(":port",$this->account['port']);
		$acc->bindParam(":tls",$this->account['tls']);
		$acc->bindParam(":username",$this->account['username']);
		$acc->bindParam(":password",$this->account['password']);
			
		$acc->execute() or die(print_r($acc->errorInfo(), true));
		return true;
	}
	public function test()
	{
		$RandReceiv = bin2hex(openssl_random_pseudo_bytes(5));
		$receiver = $RandReceiv . '@mailinator.com';
		$link = "http://{$RandReceiv}.mailinator.com";
	
		$mail = $this->makeMailer();
		
		$mail->AddAddress($receiver);
		$mail->Subject = 'Test';
		$mail->Body = 'You got mail.';
		
		if(!$mail->Send())
		{
			alert($mail->ErrorInfo,'alert-danger');
		}
		else 
		{
			redirect_to($link);
		}
	}
	public function makeMailer()
	{
		$mail = new PHPMailer();
		$mail->SetLanguage("de","/");
	
		$mail->IsSMTP();  // telling the class to use SMTP
		$mail->Mailer = "smtp";
		$mail->Host = $this->account['host'];
		$mail->Port = $this->account['port'];
		if($this->account['tls'])
			$mail->SMTPSecure = 'tls';
		else
			$mail->SMTPSecure = 'ssl';
		$mail->SMTPAuth = true; // turn on SMTP authentication
		$mail->Username = $this->account['username']; // SMTP username
		$mail->Password = $this->account['password']; // SMTP password
	
		$mail->From = $this->account['from'];
		$mail->FromName = $this->account['from_name'];
		$mail->AddReplyTo($this->account['from'],$this->account['from_name']);
		$mail->CharSet = "utf-8";
		$mail->WordWrap = 65;                                 // set word wrap to 50 characters

		return $mail;
	}
}