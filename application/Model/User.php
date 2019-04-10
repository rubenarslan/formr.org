<?php

class User {

    public $id = null;
    public $email = null;
    public $user_code = null;
    public $first_name = null;
    public $last_name = null;
    public $affiliation = null;
    public $created = 0;
    public $email_verified = 0;
    public $settings = array();
    public $errors = array();
    public $messages = array();
    public $cron = false;
    private $logged_in = false;
    private $admin = false;
    private $referrer_code = null;
    // todo: time zone, etc.

    /**
     * @var DB
     */
    private $dbh;

    public function __construct($fdb, $id = null, $user_code = null) {
        $this->dbh = $fdb;

        if ($id !== null): // if there is a registered, logged in user
            $this->id = (int) $id;
            $this->load(); // load his stuff
        elseif ($user_code !== null):
            $this->user_code = $user_code; // if there is someone who has been browsing the site
        else:
            $this->user_code = crypto_token(48); // a new arrival
        endif;
    }

    public function __sleep() {
        return array('id', 'user_code');
    }

    private function load() {
        $user = $this->dbh->select('id, email, password, admin, user_code, referrer_code, first_name, last_name, affiliation, created, email_verified')
                ->from('survey_users')
                ->where(array('id' => $this->id))
                ->limit(1)
                ->fetch();

        if ($user) {
            $this->logged_in = true;
            $this->email = $user['email'];
            $this->id = (int) $user['id'];
            $this->user_code = $user['user_code'];
            $this->admin = $user['admin'];
            $this->referrer_code = $user['referrer_code'];
            $this->first_name = $user['first_name'];
            $this->last_name = $user['last_name'];
            $this->affiliation = $user['affiliation'];
            $this->created = $user['created'];
            $this->email_verified = $user['email_verified'];
            return true;
        }

        return false;
    }

    public function loggedIn() {
        return $this->logged_in;
    }

    public function isCron() {
        return $this->cron;
    }

    public function isAdmin() {
        return $this->admin >= 1;
    }

    public function isSuperAdmin() {
        return $this->admin >= 10;
    }

    public function getAdminLevel() {
        return $this->admin;
    }

    public function getEmail() {
        return $this->email;
    }

    public function created($object) {
        return (int) $this->id === (int) $object->user_id;
    }

    public function register($info) {
        $email = array_val($info, 'email');
        $password = array_val($info, 'password');
        $referrer_code = array_val($info, 'referrer_code');

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $user_exists = $this->dbh->entry_exists('survey_users', array('email' => $email));
        if ($user_exists) {
            $this->errors[] = 'This e-mail address is already associated to an existing account.';
            return false;
        }

        if ($this->user_code === null) {
            $this->user_code = crypto_token(48);
        }

        $this->referrer_code = $referrer_code;

        if ($hash) {
            $inserted = $this->dbh->insert('survey_users', array(
                'email' => $email,
                'created' => mysql_now(),
                'password' => $hash,
                'user_code' => $this->user_code,
                'referrer_code' => $this->referrer_code
            ));

            if (!$inserted) {
                throw new Exception("Unable create user account");
            }

            $this->email = $email;
            $this->id = $inserted;
            $this->needToVerifyMail();
            return true;

        } else {
            alert('<strong>Error!</strong> Hash error.', 'alert-danger');
            return false;
        }
    }

    public function needToVerifyMail() {
        $token = crypto_token(48);
        $token_hash = password_hash($token, PASSWORD_DEFAULT);
        $this->dbh->update('survey_users', array('email_verification_hash' => $token_hash, 'email_verified' => 0), array('id' => $this->id));

        $verify_link = site_url('verify_email', array(
            'email' => $this->email,
            'verification_token' => $token
        ));

        global $site;
        $mail = $site->makeAdminMailer();
        $mail->AddAddress($this->email);
        $mail->Subject = 'formr: confirm your email address';
        $mail->Body = Template::get_replace('email/verify-email.txt', array(
            'site_url' => site_url(),
            'documentation_url' => site_url('documentation#help'),
            'verify_link' => $verify_link,
        ));

        if (!$mail->Send()) {
            alert($mail->ErrorInfo, 'alert-danger');
        } else {
            alert("You were sent an email to verify your address.", 'alert-info');
        }
        $this->id = null;
    }

    public function login($info) {
        $email = array_val($info, 'email');
        $password = array_val($info, 'password');

        $user = $this->dbh->select('id, password, admin, user_code, email_verified, email_verification_hash')
                        ->from('survey_users')
                        ->where(array('email' => $email))
                        ->limit(1)->fetch();

        if ($user && !$user['email_verified']) {
            $verification_link = site_url('verify-email', array('token' => $user['email_verification_hash']));
            $this->id = $user['id'];
            $this->email = $email;
            $this->errors[] = sprintf('
                Please verify your email address by clicking on the verification link that was sent to you, 
                <a class="btn btn-raised btn-default" href="%s">Resend Link</a>', 
            $verification_link);
            return false;
        } elseif ($user && password_verify($password, $user['password'])) {
            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                /* Store new hash in db */
                if ($hash) {
                    $this->dbh->update('survey_users', array('password' => $hash), array('email' => $email));
                } else {
                    $this->errors[] = 'An error occurred verifying your password. Please contact site administrators!';
                    return false;
                }
            }

            $this->id = (int) $user['id'];
            $this->load();
            return true;
        } else {
            $this->errors[] = 'Your login credentials were incorrect!';
            return false;
        }
    }

    public function setAdminLevel($level) {
        if (!Site::getCurrentUser()->isSuperAdmin()) {
            throw new Exception("You need more admin rights to effect this change");
        }

        $level = (int) $level;
        $level = max(array(0, $level));
        $level = $level > 1 ? 1 : $level;

        return $this->dbh->update('survey_users', array('admin' => $level), array('id' => $this->id, 'admin <' => 100));
    }

    public function forgotPassword($email) {
        $user_exists = $this->dbh->entry_exists('survey_users', array('email' => $email));

        if ($user_exists) {
            $token = crypto_token(48);
            $hash = password_hash($token, PASSWORD_DEFAULT);

            $this->dbh->update('survey_users', array('reset_token_hash' => $hash, 'reset_token_expiry' => mysql_interval('+2 days')), array('email' => $email));

            $reset_link = site_url('reset_password', array(
                'email' => $email,
                'reset_token' => $token
            ));

            $mail = Site::getCurrentUser()->makeAdminMailer();
            $mail->AddAddress($email);
            $mail->Subject = 'formr: forgot password';
            $mail->Body = Template::get_replace('email/forgot-password.txt', array(
                'site_url' => site_url(),
                'reset_link' => $reset_link,
            ));

            if (!$mail->Send()) {
                alert($mail->ErrorInfo, 'alert-danger');
            } else {
                alert("If the provided email was registered with us, you will receive an email with instructions on how to reset your password.", 'alert-info');
                redirect_to("forgot_password");
            }
        }
    }

    function logout() {
        $this->logged_in = false;
        $this->id = null;
        Session::destroy();
    }

    public function changePassword($password, $new_password) {
        if (!$this->login($this->email, $password)) {
            $this->errors = array('The old password you entered is not correct.');
            return false;
        }

        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        /* Store new hash in db */
        if ($hash) {
            $this->dbh->update('survey_users', array('password' => $hash), array('email' => $this->email));
            return true;
        } else {
            $this->errors[] = 'Unable to generate new password';
            return false;
        }
    }

    public function changeData($password, $data) {
        if (!$this->login($this->email, $password)) {
            $this->errors = array('The old password you entered is not correct.');
            return false;
        }

        $verificationRequired = false;
        $update = array();
        $update['email'] = array_val($data, 'new_email');
        $update['first_name'] = array_val($data, 'first_name');
        $update['last_name'] = array_val($data, 'last_name');
        $update['affiliation'] = array_val($data, 'affiliation');

        if (!$update['email']) {
            $this->errors[] = 'Please provide a valid email address';
            return false;
        } elseif ($update['email'] !== $this->email) {
            $verificationRequired = true;
            $update['email_verified'] = 0;
            // check if email already exists
            $exists = $this->dbh->entry_exists('survey_users', array('email' => $update['email']));
            if ($exists) {
                $this->errors[] = 'The provided email address is already in use!';
                return false;
            }
        }

        $this->dbh->update('survey_users', $update, array('id' => $this->id));
        $this->email = $update['email'];
        if ($verificationRequired) {
            $this->needToVerifyMail();
        }
        $this->load();
        return true;
    }

    public function resetPassword($info) {
        $email = array_val($info, 'email');
        $token = array_val($info, 'reset_token');
        $new_password = array_val($info, 'new_password');
        $new_password_confirm = array_val($info, 'new_password_confirm');

        if ($new_password !== $new_password_confirm) {
            alert('The passwords you entered do not match', 'alert-danger');
            return false;
        }

        $reset_token_hash = $this->dbh->findValue('survey_users', array('email' => $email), array('reset_token_hash'));

        if ($reset_token_hash) {
            if (password_verify($token, $reset_token_hash)) {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $this->dbh->update(
                    'survey_users', 
                    array('password' => $password_hash, 'reset_token_hash' => null, 'reset_token_expiry' => null), 
                    array('email' => $email), 
                    array('str', 'int', 'int')
                );

                $login_anchor = '<a href="' . site_url('login') . '">login</a>';
                alert("Your password was successfully changed. You can now use it to {$login_anchor}.", 'alert-success');
                return true;
            }
        }

        alert("Incorrect token or email address.", "alert-danger");
        return false;
    }

    public function verifyEmail($email, $token) {
        $verify_data = $this->dbh->findRow('survey_users', array('email' => $email), array('email_verification_hash', 'referrer_code'));
        if (!$verify_data) {
            alert('Incorrect token or email address.', 'alert-danger');
            return false;
        }

        if (password_verify($token, $verify_data['email_verification_hash'])) {
            $this->dbh->update('survey_users', array('email_verification_hash' => null, 'email_verified' => 1), array('email' => $email), array('int', 'int'));
            alert('Your email was successfully verified!', 'alert-success');

            if (in_array($verify_data['referrer_code'], Config::get('referrer_codes'))) {
                $this->dbh->update('survey_users', array('admin' => 1), array('email' => $email));
                alert('You now have the rights to create your own studies!', 'alert-success');
            }
            return true;
        } else {
            alert('Your email verification token was invalid or oudated. Please try copy-pasting the link in your email and removing any spaces.', 'alert-danger');
            return false;
        }
    }

    public function resendVerificationEmail($verificationHash) {
        $verify_data = $this->dbh->findRow('survey_users', array('email_verification_hash' => $verificationHash), array('id', 'email_verification_hash', 'email'));
        if (!$verify_data) {
            alert('Incorrect token.', 'alert-danger');
            return false;
        }

        $this->id = (int) $verify_data['id'];
        $this->email = $verify_data['email'];
        $this->needToVerifyMail();
        $this->id = $this->email = null;
        return true;
    }

    public function getStudies($order = 'id DESC', $limit = null) {
        if ($this->isAdmin()) {
            $select = $this->dbh->select();
            $select->from('survey_studies');
            $select->order($order, null);
            if ($limit) {
                $select->limit($limit);
            }
            $select->where(array('user_id' => $this->id));
            return $select->fetchAll();
        }
        return array();
    }

    public function getEmailAccounts() {
        if ($this->isAdmin()) {
            $accs = $this->dbh->find('survey_email_accounts', array('user_id' => $this->id, 'deleted' => 0), array('cols' => 'id, from'));
            $results = array();
            foreach ($accs as $acc) {
                if ($acc['from'] == null) {
                    $acc['from'] = 'New.';
                }
                $results[] = $acc;
            }
            return $results;
        }

        return false;
    }

    public function getRuns($order = 'id DESC', $limit = null) {
        if ($this->isAdmin()) {
            $select = $this->dbh->select();
            $select->from('survey_runs');
            $select->order($order, null);
            if ($limit) {
                $select->limit($limit);
            }
            $select->where(array('user_id' => $this->id));
            return $select->fetchAll();
        }
        return array();
    }

}
