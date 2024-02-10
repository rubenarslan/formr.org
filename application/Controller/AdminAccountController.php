<?php

use RobThree\Auth\TwoFactorAuth;

class AdminAccountController extends Controller {

    public function __construct(Site &$site) {
        parent::__construct($site);
        if (!Request::isAjaxRequest()) {
            $default_assets = get_default_assets('admin');
            $this->registerAssets($default_assets);
        }
    }

    public function indexAction() {
        if (!$this->user->loggedIn()) {
            alert('You need to be logged in to go here.', 'alert-info');
            $this->request->redirect('login');
        }

        $vars = array('showform' => false);
        if ($this->request->isHTTPPostRequest()) {
            $redirect = false;
            $oldEmail = $this->user->email;

            if (isset($_POST['deleteAccBtn'])) {
                if($this->request->str('confirm-delete')=="yes"){
                    //Delete Account
                    $this->user->carefulDelete();

                    alert('<strong>Success!</strong> Your account was deleted!', 'alert-success');
                    $this->logoutAction();
                    return;
                }else{
                    alert('Please type "yes" to confirm!', 'alert-danger');
                    $this->setView('admin/account/index', $vars);
                    return $this->sendResponse();
                }
            }

            // Change basic info + email
            $change = $this->user->changeData($this->request->str('password'), $this->request->getParams());
            if (!$change) {
                alert(nl2br(implode("\n", $this->user->errors)), 'alert-danger');
                $vars['showform'] = 'show-form';
            } elseif ($oldEmail != $this->request->str('new_email')) {
                $redirect = 'logout';
            }

            // Change password
            $passwords = array(
                'email' => $this->user->email,
                'password' => $this->request->str('password'),
                'new_password' => $this->request->str('new_password'),
            );
            if ($passwords['new_password']) {
                if ($this->request->str('new_password') !== $this->request->str('new_password_c')) {
                    alert('The new passwords do not match', 'alert-danger');
                    $vars['showform'] = 'show-form';
                } elseif ($this->user->changePassword($passwords)) {
                    alert('<strong>Success!</strong> Your password was changed! Please sign-in with your new password.', 'alert-success');
                    $redirect = 'logout';
                } else {
                    alert(implode($this->user->errors), 'alert-danger');
                    $vars['showform'] = 'show-form';
                }
            }

            if ($redirect) {
                $this->request->redirect($redirect);
            }
        }

        $vars['user'] = $this->user;
        $vars['joined'] = date('jS F Y', strtotime($this->user->created));
        $vars['studies'] = $this->fdb->count('survey_runs', array('user_id' => $this->user->id));
        $vars['names'] = sprintf('%s %s', $this->user->first_name, $this->user->last_name);
        if ('' === trim($vars['names'])) {
            $vars['names'] = $this->user->email;
        }
        $vars['affiliation'] = $this->user->affiliation ? $this->user->affiliation : '(no affiliation specified)';
        $vars['api_credentials'] = OAuthHelper::getInstance()->getClient($this->user);
        $vars['survey_count'] = $this->fdb->count('survey_studies', ['user_id' => $this->user->id]);
        $vars['run_count'] = $this->fdb->count('survey_runs', ['user_id' => $this->user->id]);
        $vars['mail_count'] = $this->fdb->count('survey_email_accounts', ['user_id' => $this->user->id, 'deleted' => 0]);

        //$this->registerAssets('bootstrap-material-design');
        $this->setView('admin/account/index', $vars);
        return $this->sendResponse();
    }

    public function loginAction() {
        if ($this->user->loggedIn()) {
            $this->request->redirect('admin/account');
        }

        if ($this->request->str('email') && $this->request->str('password') && filter_var($this->request->str('email'), FILTER_VALIDATE_EMAIL)) {
            $info = array(
                'email' => $this->request->str('email'),
                'password' => $this->request->str('password'),
            );
            if ($this->user->login($info)) {
                if($this->user->is2FAenabled()) {
                    // 2fa enabled, redirect to 2fa page
                    // temporary store user info in session until we confirm 2FA
                    Session::set('user_temp', serialize($this->user));
                    $this->request->redirect('admin/account/twoFactor');
                } else {
                    // 2fa not enabled, log user in
                    alert('<strong>Success!</strong> You were logged in!', 'alert-success');
                    Session::set('user', serialize($this->user));
                    Session::setAdminCookie($this->user);

                    $redirect = $this->user->isAdmin() ? 'admin' : 'admin/account';
                    $this->request->redirect($redirect);
                }

            } else {
                alert(implode($this->user->errors), 'alert-danger');
            }
        }

        $this->registerAssets('bootstrap-material-design');
        $this->setView('admin/account/login', array('alerts' => $this->site->renderAlerts()));
        return $this->sendResponse();
    }

    public function twoFactorAction() {
        $tfa = new TwoFactorAuth();
        $this->registerAssets('bootstrap-material-design');
        if($this->request->str('2facode')){
            if($tfa->verifyCode($this->user->get2FASecret(), $this->request->str('2facode'))) {
                $this->user = unserialize(Session::get('user_temp'));
                Session::set('user', serialize($this->user));
                Session::setAdminCookie($this->user);
                SESSION::delete('user_temp');

                $this->request->redirect('admin');
            } else {
                alert('Please enter a correct 2FA code!', 'alert-danger');
            }
        }
        $this->setView('admin/account/two_factor', array('alerts' => $this->site->renderAlerts()));
        return $this->sendResponse();
    }

    public function logoutAction() {
        $user = $this->user;
        if ($user->loggedIn()) {
            alert('You have been logged out!', 'alert-info');
            $alerts = $this->site->renderAlerts();
            $user->logout();
            $this->registerAssets('bootstrap-material-design');
            $this->setView('admin/account/login', array('alerts' => $alerts));
            return $this->sendResponse();
        } else {
            Session::destroy();
            $redirect_to = $this->request->getParam('_rdir');
            $this->request->redirect($redirect_to);
        }
    }

    public function registerAction() {
        $user = $this->user;
        $site = $this->site;

        //fixme: cookie problems lead to fatal error with missing user code
        if ($user->loggedIn()) {
            alert('You were already logged in. Please logout before you can register.', 'alert-info');
            $this->request->redirect('index');
        }

        if ($this->request->isHTTPPostRequest() && $site->request->str('email') && filter_var($this->request->str('email'), FILTER_VALIDATE_EMAIL)) {
            if (!Session::canValidateRequestToken($site->request) || Site::getSettings('signup:allow', 'true') !== 'true') {
                alert('Could not process your request please try again later', 'alert-danger');
                return $this->request->redirect('register');
            }


            $info = array(
                'email' => $site->request->str('email'),
                'password' => $site->request->str('password'),
                'referrer_code' => $site->request->str('referrer_code'),
            );
            if ($user->register($info)) {
                $this->request->redirect('admin/account/register');
            } else {
                alert(implode($user->errors), 'alert-danger');
            }
        }

        $this->registerAssets('bootstrap-material-design');
        $this->setView('admin/account/register');
        return $this->sendResponse();
    }

    public function setupTwoFactorAction() {
        if (!$this->user->loggedIn()) {
            alert('You need to be logged in to setup 2FA.', 'alert-info');
            $this->request->redirect('login');
        }
        
        $tfa = new TwoFactorAuth();

        if($this->request->str('reset_code')) {
            if($tfa->verifyCode($this->user->get2FASecret(), $this->request->str('reset_code'))) {
                $this->user->set2FAsecret('');
                alert('2FA reset successfully!', 'alert-success');
                $this->request->redirect('admin/account');
            } else {
                alert('Wrong 2FA code, try again!', 'alert-danger');
            }
        } else if($this->request->str('code')) {
            $secret = Session::get('2fa_secret');
            $result = $tfa->verifyCode($secret, $this->request->str('code'));
            if($result) {
                $this->user->set2FAsecret(Session::get('2fa_secret'));
                Session::delete('2fa_secret');
                alert('2FA setup successfully!', 'alert-success');
                $this->request->redirect('admin/account');
            } else {
                alert('Wrong 2FA code, try again!', 'alert-danger');
            }
        } else {
            Session::set('2fa_secret', $tfa->createSecret());
        }

        $this->registerAssets('bootstrap-material-design');
        if($this->user->is2FAenabled()) {
            $this->setView('admin/account/reset_two_factor', array('alerts' => $this->site->renderAlerts()));
        } else {
            $this->setView('admin/account/setup_two_factor', array('alerts' => $this->site->renderAlerts(), 'username' => $this->user->email));
        }
        return $this->sendResponse();   
    }

    public function verifyEmailAction() {
        $user = $this->user;
        $verification_token = $this->request->str('verification_token');
        $email = $this->request->str('email');

        if ($this->request->isHTTPGetRequest() && $this->request->str('token')) {
            $user->resendVerificationEmail($this->request->str('token'));
            $this->request->redirect('login');
        } elseif (!$verification_token || !$email) {
            alert("You need to follow the link you received in your verification mail.");
            $this->request->redirect('login');
        } else {
            $user->verifyEmail($email, $verification_token);
            $this->request->redirect('login');
        };
    }

    public function forgotPasswordAction() {
        if ($this->user->loggedIn()) {
            $this->request->redirect('index');
        }

        if ($this->request->str('email')) {
            $this->user->forgotPassword($this->request->str('email'));
        }

        $this->registerAssets('bootstrap-material-design');
        $this->setView('admin/account/forgot_password');
        return $this->sendResponse();
    }

    public function resetPasswordAction() {
        $user = $this->user;
        if ($user->loggedIn()) {
            $this->request->redirect('index');
        }

        if ($this->request->isHTTPGetRequest() && (!$this->request->str('email') || !$this->request->str('reset_token')) && !$this->request->str('ok')) {
            alert('You need to follow the link you received in your password reset mail');
            $this->request->redirect('forgot_password');
        } elseif ($this->request->isHTTPPostRequest()) {
            $postRequest = new Request($_POST);
            $info = array(
                'email' => $postRequest->str('email'),
                'reset_token' => $postRequest->str('reset_token'),
                'new_password' => $postRequest->str('new_password'),
                'new_password_confirm' => $postRequest->str('new_password_c'),
            );
            if (($done = $user->resetPassword($info))) {
                $this->request->redirect('forgot_password');
            }
        }

        $this->registerAssets('bootstrap-material-design');
        $this->setView('admin/account/reset_password', array(
            'reset_data_email' => $this->request->str('email'),
            'reset_data_token' => $this->request->str('reset_token'),
        ));
        return $this->sendResponse();
    }
}
