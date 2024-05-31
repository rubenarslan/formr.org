<?php

use PHPMailer\PHPMailer\PHPMailer;

class EmailAccount extends Model {

    public $id = null;
    public $user_id = null;
    public $valid = null;
    public $account = array();

    /**
     * @var DB
     */
    private $dbh;

    const AK_GLUE = ':fmr:';

    public function __construct($id, $user_id) {
        parent::__construct();
        
        $this->id = (int) $id;
        $this->user_id = (int) $user_id;

        if ($id) {
            $this->load();
        }
    }

    protected function load() {
        $this->account = $this->db->findRow('survey_email_accounts', array('id' => $this->id));
        if ($this->account) {
            $this->valid = true;
            $this->user_id = (int) $this->account['user_id'];
            if ($this->account['auth_key']) {
                list($username, $password) = explode(self::AK_GLUE, Crypto::decrypt($this->account['auth_key']), 2);
                $this->account['username'] = $username;
                $this->account['password'] = $password;
            }
        }
    }

    public function create() {
        $this->id = $this->db->insert('survey_email_accounts', array('user_id' => $this->user_id, 'auth_key' => ''));
        $this->load();
        return $this->id;
    }

    public function changeSettings($posted) {
        $old_password = $this->account['password'];
        $this->account = $posted;

        $params = array(
            'id' => $this->id,
            'fromm' => $this->account['from'],
            'from_name' => $this->account['from_name'],
            'host' => $this->account['host'],
            'port' => $this->account['port'],
            'tls' => $this->account['tls'],
            'username' => $this->account['username'],
            'reply_to' => $this->account['reply_to'],
            'password' => $old_password,
        );

        if (trim($posted['password']) != '') {
            $params['password'] = $this->account['password'];
        }

        $params['auth_key'] = Crypto::encrypt(array($params['username'], $params['password']), self::AK_GLUE);
        if (!$params['auth_key']) {
            return false;
        }
        $params['password'] = '';

        $query = "UPDATE `survey_email_accounts` 
			SET `from` = :fromm, `from_name` = :from_name, `host` = :host, `port` = :port, `tls` = :tls, `username` = :username, `reply_to` = :reply_to, `password` = :password, `auth_key` = :auth_key, `status` = 0
			WHERE id = :id LIMIT 1";

        $this->db->exec($query, $params);
        $this->load();
        return true;
    }

    public function test() {
        $receiver = $this->account['from'];
        $mail = $this->makeMailer();

        $mail->AddAddress($receiver);
        $mail->Subject = 'formr: account test success';
        $mail->Body = Template::get_replace('email/test-account.ftpl', array('site_url' => site_url()));

        if (!$mail->Send()) {
            $this->invalidate();
            alert('Account Test Failed: ' . $mail->ErrorInfo, 'alert-danger');
            return false;
        } else {
            $this->validate();
            alert("An email was sent to <b>{$receiver}</b>. Please confirm that you received this email.", 'alert-success');
            return true;
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
        if (!empty($this->account['username'])) {
            $mail->SMTPAuth = true; // turn on SMTP authentication
            $mail->Username = $this->account['username']; // SMTP username
            $mail->Password = $this->account['password']; // SMTP password
        } else {
            $mail->SMTPAuth = false;
            $mail->SMTPSecure = false;
        }
        $mail->From = $this->account['from'];
        $mail->FromName = $this->account['from_name'];
        if(!empty($this->account['reply_to'])) {
            $mail->AddReplyTo($this->account['reply_to'], $this->account['from_name']);
        } else {
            $mail->AddReplyTo($this->account['from'], $this->account['from_name']);
        }

        $mail->CharSet = "utf-8";
        $mail->WordWrap = 65; // set word wrap to 65 characters
        if (is_array(Config::get('email.smtp_options'))) {
            $mail->SMTPOptions = array_merge($mail->SMTPOptions, Config::get('email.smtp_options'));
        }

        return $mail;
    }

    public function invalidate() {
        return $this->db->update('survey_email_accounts', array('status' => -1), array('id' => $this->id));
    }

    public function validate() {
        return $this->db->update('survey_email_accounts', array('status' => 1), array('id' => $this->id));
    }

    public function delete() {
        return $this->db->update('survey_email_accounts', array('deleted' => 1), array('id' => $this->id));
    }

}
