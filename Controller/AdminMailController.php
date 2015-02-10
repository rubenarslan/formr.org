<?php

class AdminMailController extends AdminController {

	public function __construct(Site &$site) {
		parent::__construct($site);
	}

	public function indexAction() {
		$vars = array();
		if (!empty($_POST) AND isset($_POST['create'])) {
			$acc = new EmailAccount($this->fdb, null, $this->user->id);
			if ($acc->create()) {
				alert('<strong>Success!</strong> You added a new email account!', 'alert-success');
				redirect_to("admin/mail/edit/?account_id=" . $acc->id);
			} else {
				alert(implode($acc->errors), 'alert-danger');
			}
			$vars['acc'] = $acc;
		}
		$this->renderView('mail/index', $vars);
	}

	public function editAction() {
		$vars = array();
		$acc = new EmailAccount($this->fdb, $_GET['account_id'], $this->user->id);

		if(!$this->user->created($acc)):
			alert("<strong>Error:</strong> Not your email account.",'alert-danger');
			redirect_to("/admin/mail/index");
		endif;

		if(!empty($_POST) AND isset($_POST['test_account'])) {
			$acc->test();
		} elseif(!empty($_POST)) {
			$acc->changeSettings($_POST);
			alert('<strong>Success!</strong> Your email account settings were changed!','alert-success');
			redirect_to("/admin/mail/edit?account_id=".$_GET['account_id']);
		}

		$vars['acc'] = $acc;
		$this->renderView('mail/edit', $vars);
	}

}
