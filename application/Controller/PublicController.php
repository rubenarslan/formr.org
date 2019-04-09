<?php

class PublicController extends Controller {

    public function __construct(Site &$site) {
        parent::__construct($site);
        if (!Request::isAjaxRequest()) {
            $default_assets = get_default_assets('site');
            $this->registerAssets($default_assets);
        }
    }

    public function indexAction() {
        $this->renderView('public/home');
    }

    public function documentationAction() {
        $this->renderView('public/documentation');
    }

    public function studiesAction() {
        $this->renderView('public/studies', array('runs' => RunHelper::getPublicRuns()));
    }

    public function aboutAction() {
        $this->renderView('public/about', array('bodyClass' => 'fmr-about'));
    }

    public function publicationsAction() {
        $this->renderView('public/publications');
    }

    public function accountAction() {
        /**
         * @todo: 
         * - allow changing email address
         * - email address verification
         * - my access code has been compromised, reset? possible problems with external data, maybe they should get their own tokens...
         */
        if (!$this->user->loggedIn()) {
            alert('You need to be logged in to go here.', 'alert-info');
            redirect_to('login');
        }

        $vars = array('showform' => false);
        if ($this->request->isHTTPPostRequest()) {
            $redirect = false;
            $oldEmail = $this->user->email;

            // Change basic info + email
            $change = $this->user->changeData($this->request->str('password'), $this->request->getParams());
            if (!$change) {
                alert(nl2br(implode($this->user->errors, "\n")), 'alert-danger');
                $vars['showform'] = 'show-form';
            } elseif ($oldEmail != $this->request->str('new_email')) {
                $redirect = 'logout';
            }

            // Change password
            if ($this->request->str('new_password')) {
                if ($this->request->str('new_password') !== $this->request->str('new_password_c')) {
                    alert('The new passwords do not match', 'alert-danger');
                    $vars['showform'] = 'show-form';
                } elseif ($this->user->changePassword($this->request->str('password'), $this->request->str('new_password'))) {
                    alert('<strong>Success!</strong> Your password was changed! Please sign-in with your new password.', 'alert-success');
                    $redirect = 'logout';
                } else {
                    alert(implode($this->user->errors), 'alert-danger');
                    $vars['showform'] = 'show-form';
                }
            }

            if ($redirect) {
                redirect_to($redirect);
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

        $this->registerAssets('bootstrap-material-design');
        $this->renderView('public/account', $vars);
    }

    public function loginAction() {
        if ($this->user->loggedIn()) {
            redirect_to('account');
        }

        if ($this->request->str('email') && $this->request->str('password')) {
            $info = array(
                'email' => $this->request->str('email'),
                'password' => $this->request->str('password'),
            );
            if ($this->user->login($info)) {
                alert('<strong>Success!</strong> You were logged in!', 'alert-success');
                Session::set('user', serialize($this->user));
                $redirect = $this->user->isAdmin() ? 'admin' : 'account';
                redirect_to($redirect);
            } else {
                alert(implode($this->user->errors), 'alert-danger');
            }
        }

        $this->registerAssets('bootstrap-material-design');
        $this->renderView('public/login', array('alerts' => $this->site->renderAlerts()));
    }

    public function logoutAction() {
        $user = $this->user;
        if ($user->loggedIn()) {
            alert('You have been logged out!', 'alert-info');
            $alerts = $this->site->renderAlerts();
            $user->logout();
            $this->registerAssets('bootstrap-material-design');
            $this->renderView('public/login', array('alerts' => $alerts));
        } else {
            Session::destroy();
            $redirect_to = $this->request->getParam('_rdir');
            redirect_to($redirect_to);
        }
    }

    public function registerAction() {
        $user = $this->user;
        $site = $this->site;

        //fixme: cookie problems lead to fatal error with missing user code
        if ($user->loggedIn()) {
            alert('You were already logged in. Please logout before you can register.', 'alert-info');
            redirect_to("index");
        }

        if ($this->request->isHTTPPostRequest() && $site->request->str('email')) {
            $info = array(
                'email' => $site->request->str('email'),
                'password' => $site->request->str('password'),
                'referrer_code' => $site->request->str('referrer_code'),
            );
            if ($user->register($info)) {
                //alert('<strong>Success!</strong> You were registered and logged in!','alert-success');
                redirect_to('index');
            } else {
                alert(implode($user->errors), 'alert-danger');
            }
        }

        $this->registerAssets('bootstrap-material-design');
        $this->renderView('public/register');
    }

    public function verifyEmailAction() {
        $user = $this->user;
        $verification_token = $this->request->str('verification_token');
        $email = $this->request->str('email');

        if ($this->request->isHTTPGetRequest() && $this->request->str('token')) {
            $user->resendVerificationEmail($this->request->str('token'));
            redirect_to('login');
        } elseif (!$verification_token || !$email) {
            alert("You need to follow the link you received in your verification mail.");
            redirect_to('login');
        } else {
            $user->verifyEmail($email, $verification_token);
            redirect_to('login');
        };
    }

    public function forgotPasswordAction() {
        if ($this->user->loggedIn()) {
            redirect_to("index");
        }

        if ($this->request->str('email')) {
            $this->user->forgotPassword($this->request->str('email'));
        }

        $this->registerAssets('bootstrap-material-design');
        $this->renderView('public/forgot_password');
    }

    public function resetPasswordAction() {
        $user = $this->user;
        if ($user->loggedIn()) {
            redirect_to('index');
        }

        if ($this->request->isHTTPGetRequest() && (!$this->request->str('email') || !$this->request->str('reset_token')) && !$this->request->str('ok')) {
            alert('You need to follow the link you received in your password reset mail');
            redirect_to('forgot_password');
        } elseif ($this->request->isHTTPPostRequest()) {
            $postRequest = new Request($_POST);
            $info = array(
                'email' => $postRequest->str('email'),
                'reset_token' => $postRequest->str('reset_token'),
                'new_password' => $postRequest->str('new_password'),
                'new_password_confirm' => $postRequest->str('new_password_c'),
            );
            if (($done = $user->resetPassword($info))) {
                redirect_to('forgot_password');
            }
        }

        $this->registerAssets('bootstrap-material-design');
        $this->renderView('public/reset_password', array(
            'reset_data_email' => $this->request->str('email'),
            'reset_data_token' => $this->request->str('reset_token'),
        ));
    }

    public function fileDownloadAction($run_id = 0, $original_filename = '') {
        $path = $this->fdb->findValue('survey_uploaded_files', array('run_id' => (int) $run_id, 'original_file_name' => $original_filename), array('new_file_path'));
        if ($path) {
            return redirect_to(asset_url($path));
        }
        formr_error(404, 'Not Found', 'The requested file does not exist');
    }

}
