<?php

class PwaController extends Controller {

   public function __construct(Site &$site) {
      parent::__construct($site);
      if (!Request::isAjaxRequest()) {
         $default_assets = get_default_assets('site');
         $this->registerAssets($default_assets);
      }
   }

   public function pwaAction() {
      error_log('pwaAction in PwaController called');



   }

   public function subscribeAction() {
      $_POST = json_decode(file_get_contents('php://input'), true);
      $run = new Run($this->getDB(), $_POST['runname']);
  
      error_log('Register push subscription: ' . $run->id . ', ' . Site::getCurrentUser()->user_code);
      try {
         $this->getDB()->insert('survey_push_subscriptions', array(
            'run_id' => $run->id,
            'endpoint' => $_POST['endpoint'],
            'auth' => $_POST['auth'],
            'p256dh ' => $_POST['p256dh'],
            'session' => Site::getCurrentUser()->user_code
            ));
   
      } catch (Exception $e) {
         error_log($e->getMessage());
         
      }
      
   }

   public function indexAction() {
      error_log('indexAction of PwaController');
   }


}
