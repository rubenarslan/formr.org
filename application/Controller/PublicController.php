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
        $this->setView('public/home');
        return $this->sendResponse();
    }

    public function documentationAction() {
        if (Site::getSettings('content:docu:show', 'true') !== 'true') {
            formr_error(403, 'Not Public', 'Page cannot be displayed');
        }
        $this->setView('public/documentation', array('headerClass' => 'fmr-small-header'));
        return $this->sendResponse();
    }

    public function studiesAction() {
        if (Site::getSettings('content:studies:show', 'true') !== 'true') {
            formr_error(403, 'Not Public', 'Page cannot be displayed');
        }
        
        $this->setView('public/studies', array('runs' => RunHelper::getPublicRuns()));
        return $this->sendResponse();
    }

    public function termsOfServiceAction() {
        if (Site::getSettings('content:terms_of_service') == '') {
            formr_error(403, 'Not Public', 'Page cannot be displayed');
        }
        
        $this->setView('public/terms_of_service', array('runs' => RunHelper::getPublicRuns()));
        return $this->sendResponse();
    }

    public function privacyPolicyAction() {
        if (Site::getSettings('content:privacy_policy') == '') {
            formr_error(403, 'Not Public', 'Page cannot be displayed');
        }
        
        $this->setView('public/privacy_policy', array('runs' => RunHelper::getPublicRuns()));
        return $this->sendResponse();
    }

    public function aboutAction() {
        if (Site::getSettings('content:about:show', 'true') !== 'true') {
            formr_error(403, 'Not Public', 'Page cannot be displayed');
        }
        
        $this->setView('public/about', array(
            'bodyClass' => 'fmr-about',
            'headerClass' => 'fmr-small-header'
        ));
        return $this->sendResponse();
    }

    public function publicationsAction() {
        if (Site::getSettings('content:publications:show', 'true') !== 'true') {
            formr_error(403, 'Not Public', 'Page cannot be displayed');
        }
        
        $this->setView('public/publications', array('headerClass' => 'fmr-small-header'));
        return $this->sendResponse();
    }
    
    public function loginAction() {
        $this->request->redirect('admin/account/login');
    }
    
    public function logoutAction() {
        $this->request->redirect('admin/account/logout');
    }
    
    public function registerAction() {
        $this->request->redirect('admin/account/register');
    }
    
    public function accountAction() {
        $this->request->redirect('admin/account');
    }
    
    public function resetPasswordAction() {
        $this->request->redirect('admin/account/reset-password', array(
            'email' => $this->request->str('email'),
            'reset_token' => $this->request->str('reset_token'),
        ));
    }
    
    public function forgotPasswordAction() {
        $this->request->redirect('admin/account/forgot-password');
    }
    
    public function verifyEmailAction() {
         $this->request->redirect('admin/account/verify-email', array(
            'email' => $this->request->str('email'),
            'verification_token' => $this->request->str('verification_token'),
            'token' => $this->request->str('token', null),
        ));
    }

    public function fileDownloadAction($run_id = 0, $original_filename = '') {
        $path = $this->fdb->findValue('survey_uploaded_files', array('run_id' => (int) $run_id, 'original_file_name' => $original_filename), array('new_file_path'));
        if ($path) {
            return $this->request->redirect(asset_url($path));
        }
        formr_error(404, 'Not Found', 'The requested file does not exist');
    }

}
