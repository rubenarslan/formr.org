<?php
use RobThree\Auth\TwoFactorAuth;

class AdminAccountController extends Controller {

    public function __construct(Site &$site) {
        parent::__construct($site);
        if (!Request::isAjaxRequest()) {
            $this->registerAssets('admin');
        }
    }

    public function indexAction() {
        if (!$this->user->loggedIn()) {
            alert('You need to be logged in to go here.', 'alert-info');
            $this->request->redirect('admin/account/login');
        }

        // Check if 2FA is required but not set up
        if (Config::get('2fa.required', false) && !$this->user->is2FAenabled()) {
            alert('Two-factor authentication is required. Please set it up now.', 'alert-warning');
            $this->request->redirect('admin/account/setup-two-factor');
        }

        $vars = array('showform' => false);
        if ($this->request->isHTTPPostRequest()) {
            $redirect = false;
            
            // Handle account deletion
            if ($this->request->str('delete_account')) {
                $errors = [];
                
                // Verify email
                if ($this->request->str('delete_email') !== $this->user->email) {
                    $errors[] = 'The email address you entered does not match your current email.';
                }
                
                // Verify password
                $info = array(
                    'email' => $this->user->email,
                    'password' => $this->request->str('delete_password')
                );
                if (!$this->user->login($info)) {
                    $errors[] = 'Incorrect password.';
                }
                                
                // Verify 2FA if enabled
                if ($this->user->is2FAenabled()) {
                    if (!$this->user->verify2FACode($this->request->str('delete_2fa'))) {
                        $errors[] = 'Invalid 2FA code.';
                    }
                }
                
                // Verify confirmation text
                if ($this->request->str('delete_confirm') !== 'I understand my data will be gone') {
                    $errors[] = 'Please type exactly "I understand my data will be gone" to confirm deletion.';
                }
                                
                if (empty($errors)) {
                    $result = $this->user->carefulDelete();
                    if ($result === '') {
                        alert('Your account has been successfully deleted.', 'alert-success');
                        $this->request->redirect('admin/account/logout');
                        return;
                    } else {
                        alert($result, 'alert-danger');
                    }
                } else {
                    alert('Account deletion failed:<br>' . implode('<br>', $errors), 'alert-danger');
                }
            }

            // Change basic info + email
            if ($this->request->str('password')) {
                $oldEmail = $this->user->email;
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

        $this->setView('admin/account/index', $vars);
        return $this->sendResponse();
    }

    protected function minimumWait($start_time = null, $min_seconds = 1.0) {
        if ($start_time === null) {
            return microtime(true);
        }
        $elapsed = microtime(true) - $start_time;
        if ($elapsed < $min_seconds) {
            usleep((int)(($min_seconds - $elapsed) * 1000000));
        }
    }

    public function loginAction() {
        if ($this->user->loggedIn()) {
            // Check if 2FA is required but not set up
            if (Config::get('2fa.required', false) && !$this->user->is2FAenabled()) {
                alert('Two-factor authentication is required. Please set it up now.', 'alert-warning');
                $this->request->redirect('admin/account/setup-two-factor');
            }
            $this->request->redirect('admin/account');
        }

        if ($this->request->str('email') && $this->request->str('password') && filter_var($this->request->str('email'), FILTER_VALIDATE_EMAIL)) {
            $start = $this->minimumWait(null, 0.3); // faster for login
            $info = array(
                'email' => $this->request->str('email'),
                'password' => $this->request->str('password'),
            );
            if ($this->user->login($info)) {
                if($this->user->is2FAenabled()) {
                    // 2fa enabled, redirect to 2fa page
                    // temporary store user info in session until we confirm 2FA
                    Session::set('user_temp', serialize($this->user));
                    Session::set('2fa_login_started', time()); // Track when 2FA login started
                    $this->minimumWait($start, 0.3);
                    $this->request->redirect('admin/account/two-factor');
                } else if (Config::get('2fa.required', false)) {
                    // 2FA is required but not set up, store user info and redirect to setup
                    Session::set('user', serialize($this->user));
                    Session::setAdminCookie($this->user);
                    alert('Two-factor authentication is required. Please set it up now.', 'alert-warning');
                    $this->minimumWait($start, 0.3);
                    $this->request->redirect('admin/account/setup-two-factor');
                } else {
                    // 2fa not enabled and not required, log user in
                    alert('<strong>Success!</strong> You were logged in!', 'alert-success');
                    Session::set('user', serialize($this->user));
                    Session::setAdminCookie($this->user);

                    $this->minimumWait($start, 0.3);
                    $redirect = $this->user->isAdmin() ? 'admin' : 'admin/account';
                    $this->request->redirect($redirect);
                }
            } else {
                $this->response->setStatusCode(Response::STATUS_UNAUTHORIZED);
                alert(implode($this->user->errors), 'alert-danger');
                $this->minimumWait($start, 0.3);
            }
        }

        $this->registerAssets('material');
        $this->setView('admin/account/login', array('alerts' => $this->site->renderAlerts()));
        return $this->sendResponse();
    }

    public function logoutAction() {
        $user = $this->user;
        if ($user->loggedIn()) {
            alert('You have been logged out!', 'alert-info');
            $alerts = $this->site->renderAlerts();
            $user->logout();
            $this->registerAssets('material');
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

        if ($this->request->isHTTPPostRequest() && 
        $site->request->str('email') && 
        filter_var($this->request->str('email'), FILTER_VALIDATE_EMAIL)) {
            if (!Session::canValidateRequestToken($site->request)) {
                alert('Could not process your request please try again later', 'alert-danger');
                return $this->request->redirect('admin/account/register');
            }
            if(Site::getSettings('signup:allow', 'true') !== 'true') {
                alert('Signing up has been disabled for the moment.', 'alert-info');
                return $this->request->redirect('admin/account/register');
           }

            if(!$this->request->str('agree_tos')) {
                alert('You cannot sign up if you don\'t agree to the terms and conditions.', 'alert-danger');
                return $this->request->redirect('admin/account/register');
            }


            $info = array(
                'email' => $site->request->str('email'),
                'password' => $site->request->str('password'),
                'referrer_code' => $site->request->str('referrer_code'),
            );
            if ($user->register($info)) {
                $this->request->redirect('admin/account/register');
            } else {
                $this->response->setStatusCode(Response::STATUS_BAD_REQUEST);
                alert(implode($user->errors), 'alert-danger');
            }
        }

        $this->registerAssets('material');
        $this->setView('admin/account/register');
        return $this->sendResponse();
    }

    public function verifyEmailAction() {
        $user = $this->user;
        $verification_token = $this->request->str('verification_token');
        $email = $this->request->str('email');

        if ($this->request->isHTTPGetRequest() && $this->request->str('token')) {
            $start = $this->minimumWait(null, 1.0); // slower for security
            $user->resendVerificationEmail($this->request->str('token'));
            $this->minimumWait($start, 1.0);
            $this->request->redirect('admin/account/login');
        } elseif (!$verification_token || !$email) {
            $this->response->setStatusCode(Response::STATUS_BAD_REQUEST);
            alert("You need to follow the link you received in your verification mail.");
            $this->request->redirect('admin/account/login');
        } else {
            $start = $this->minimumWait(null, 1.0); // slower for security
            $user->verifyEmail($email, $verification_token);
            $this->minimumWait($start, 1.0);
            $this->request->redirect('admin/account/login');
        }
    }

    public function forgotPasswordAction() {
        if ($this->user->loggedIn()) {
            $this->request->redirect('index');
        }

        if ($this->request->str('email')) {
            $start = $this->minimumWait(null, 1.0); // slower for security
            if (!filter_var($this->request->str('email'), FILTER_VALIDATE_EMAIL)) {
                $this->response->setStatusCode(Response::STATUS_UNAUTHORIZED);
                alert('Please enter a valid email address.', 'alert-danger');
            } else {
                $result = $this->user->forgotPassword($this->request->str('email'));
                $this->response->setStatusCode(Response::STATUS_UNAUTHORIZED);
                alert('If an account exists for this email address, you will receive password reset instructions.', 'alert-info');
            }
            $this->minimumWait($start, 1.0);
        }

        $this->registerAssets('material');
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
            $this->request->redirect('admin/account/forgot-password');
        } elseif ($this->request->isHTTPPostRequest()) {
            $start = $this->minimumWait(null, 1.0); // slower for security
            $postRequest = new Request($_POST);
            $info = array(
                'email' => $postRequest->str('email'),
                'reset_token' => $postRequest->str('reset_token'),
                'new_password' => $postRequest->str('new_password'),
                'new_password_confirm' => $postRequest->str('new_password_c'),
            );
            if (($done = $user->resetPassword($info))) {
                $this->minimumWait($start, 1.0);
                $this->request->redirect('admin/account/login');
            } else {
                alert('Invalid or expired password reset link. Please request a new one.', 'alert-danger');
                $this->minimumWait($start, 1.0);
                $this->request->redirect('admin/account/forgot-password');
            }
        }

        $this->registerAssets('material');
        $this->setView('admin/account/reset_password', array(
            'reset_data_email' => $this->request->str('email'),
            'reset_data_token' => $this->request->str('reset_token'),
        ));
        return $this->sendResponse();
    }

    public function twoFactorAction() {
        $login_started = Session::get('2fa_login_started');
        if (!$login_started || (time() - $login_started > 300)) { // 5 minute timeout
            Session::delete('user_temp');
            Session::delete('2fa_login_started');
            alert('2FA session expired. Please login again.', 'alert-danger');
            $this->request->redirect('admin/account/login');
        }
        $this->registerAssets('material');

        // First check if we have a temporary user session
        $temp_user_data = Session::get('user_temp');
        if (!$temp_user_data) {
            alert('Invalid authentication session. Please login again.', 'alert-danger');
            $this->request->redirect('admin/account/login');
        }

        // Safely reconstruct the user object from session data
        try {
            $temp_user = unserialize($temp_user_data);
            if (!($temp_user instanceof User)) {
                $this->response->setStatusCode(Response::STATUS_UNAUTHORIZED);
                throw new Exception('Invalid user data');
            }
            // Initialize a fresh user object with the stored credentials
            $this->user = new User($temp_user->id, null);
            if (!$this->user->email) {
                $this->response->setStatusCode(Response::STATUS_UNAUTHORIZED);
                throw new Exception('Invalid user data');
            }
        } catch (Exception $e) {
            Session::delete('user_temp');
            Session::delete('2fa_login_started');
            $this->response->setStatusCode(Response::STATUS_UNAUTHORIZED);
            alert('Invalid session data. Please login again.', 'alert-danger');
            $this->request->redirect('admin/account/login');
        }

        if($this->request->str('2facode')){
            // Check 2FA timeout again before processing code
            if (time() - $login_started > 300) {
                Session::delete('user_temp');
                Session::delete('2fa_login_started');
                alert('2FA session expired. Please login again.', 'alert-danger');
                $this->request->redirect('admin/account/login');
            }
            
            $start = $this->minimumWait(null, 0.3); // faster for 2FA
            
            if($this->user->verify2FACode($this->request->str('2facode'))) {
                alert('<strong>Success!</strong> You were logged in!', 'alert-success');
                // On successful 2FA, store only the minimum required user data
                Session::set('user', serialize($this->user));
                Session::setAdminCookie($this->user);
                Session::delete('user_temp');
                Session::delete('2fa_login_started');

                $this->minimumWait($start, 0.3);
                $this->request->redirect('admin');
            } else {
                $this->response->setStatusCode(Response::STATUS_UNAUTHORIZED);
                alert('Please enter a correct 2FA code!', 'alert-danger');
                $this->minimumWait($start, 0.3);
            }
        }

        $this->setView('admin/account/two_factor', array('alerts' => $this->site->renderAlerts()));
        return $this->sendResponse();
    }

    public function manageTwoFactorAction() {
        // 1. Basic access checks
        if (!$this->user->loggedIn()) {
            alert('You need to be logged in to manage 2FA.', 'alert-info');
            $this->request->redirect('login');
        }

        if (!Config::get('2fa.enabled', true)) {
            alert('Two-factor authentication is not enabled on this instance.', 'alert-info');
            $this->request->redirect('admin/account');
        }

        if (!$this->user->is2FAenabled()) {
            alert('2FA is not enabled for your account.', 'alert-info');
            $this->request->redirect('admin/account/setup-two-factor');
        }

        // Prevent superadmins from disabling their 2FA via web interface
        if ($this->user->isSuperAdmin()) {
            alert('Superadmins cannot disable 2FA through the web interface for security reasons.', 'alert-error');
            $this->request->redirect('admin/account');
        }

        // Handle POST actions
        if ($this->request->isHTTPPostRequest()) {
            $start = $this->minimumWait(null, 0.3);

            // Validate code
            if (!$this->user->verify2FACode($this->request->str('code'))) {
                $this->response->setStatusCode(Response::STATUS_UNAUTHORIZED);
                alert('The authentication code is invalid! Please try again', 'alert-danger');
                $this->minimumWait($start, 0.3);
                return  $this->request->redirect('admin/account/manage-two-factor');
            }

            // Check if what action was requested and act accordingly
            $action = $this->request->str('manage_2fa');
            if ($action == 'reset' && $this->user->reset2FA()) {
                alert('Two factor authentication has been reset. You can set it up again or close this dialog.', 'alert-success');
                return $this->request->redirect('admin/account/setup-two-factor');
            } elseif ($action == 'disable' && $this->user->reset2FA()) {
                alert('Two factor authentication successfully disabled.', 'alert-success');
                $this->minimumWait($start, 0.3);
                return $this->request->redirect('admin/account/#2fa');
            } else {
                alert('An unexpected error occured. Please try again later', 'alert-danger');
                $this->response->setStatusCode(Response::STATUS_BAD_REQUEST);
            }
        }

        $this->registerAssets('material');
        $this->setView('admin/account/manage_two_factor', array(
            'alerts' => $this->site->renderAlerts()
        ));
        return $this->sendResponse();
    }

    public function setupTwoFactorAction() {
        // 1. Basic access checks
        if (!$this->user->loggedIn()) {
            alert('You need to be logged in to setup 2FA.', 'alert-info');
            $this->request->redirect('login');
        }

        if (!Config::get('2fa.enabled', true)) {
            alert('Two-factor authentication is not enabled on this instance.', 'alert-info');
            $this->request->redirect('admin/account');
        }

        if ($this->user->is2FAenabled()) {
            alert('2FA is already enabled for your account.', 'alert-info');
            $this->request->redirect('admin/account/manage-two-factor');
        }

        // Handle POST actions
        if ($this->request->isHTTPPostRequest()) {
            $start = $this->minimumWait(null, 0.3);

            if ($this->request->str('code')) {
                $setup = Session::get('2fa_setup');
                if (!$setup) {
                    $this->response->setStatusCode(Response::STATUS_BAD_REQUEST);
                    alert('Setup session expired. Please try again.', 'alert-danger');
                    $this->minimumWait($start, 0.3);
                    $this->request->redirect('admin/account');
                }

                $tfa = new TwoFactorAuth();
                if ($tfa->verifyCode($setup['secret'], $this->request->str('code'))) {
                    // Save the 2FA setup
                    $this->user->set2FASecret($setup['secret']);
                    $this->user->set2FABackupCodes(implode(';', $setup['backup_codes']));
                    Session::delete('2fa_setup');
                    alert('Two Factor Authentication has been set up successfully!', 'alert-success');
                    alert('IMPORTANT: Save your backup codes in a secure location NOW! Store them in:<br/>
                        - A password manager like 1Password, LastPass, or Bitwarden<br/>
                        - An encrypted file on your computer<br/>
                        - Print them and store in a secure physical location<br/>
                        Your backup codes are: <b>' . implode(' ', $setup['backup_codes']) . '</b><br/>
                        <br/>WARNING: If you lose access to your 2FA device AND your backup codes, you will need to contact an instance administrator to restore access to your account.', 'alert-warning');
                    $this->minimumWait($start, 0.3);
                    $this->request->redirect('admin/account#2fa');
                } else {
                    $this->response->setStatusCode(Response::STATUS_UNAUTHORIZED);
                    alert('Wrong 2FA code, try again!', 'alert-danger');
                    $this->minimumWait($start, 0.3);
                }
            } else if ($this->request->str('setup')) {
                $setup = $this->user->setup2FA();
                Session::set('2fa_setup', $setup);
                $this->minimumWait($start, 0.3);
            }
        }

        $this->registerAssets('material');
        $setup = Session::get('2fa_setup');
        $this->setView('admin/account/setup_two_factor', array(
            'alerts' => $this->site->renderAlerts(),
            'username' => $this->user->email,
            'qr_url' => $setup['qr_url'] ?? null
        ));
        return $this->sendResponse();
    }
}
