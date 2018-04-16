<?php

class AdminMailController extends AdminController {

	public function __construct(Site &$site) {
		parent::__construct($site);
	}

	public function indexAction() {
		$vars = array(
			'accs' => $this->user->getEmailAccounts(),
			'form_title' => 'Add Mail Account',
		);
		$acc = new EmailAccount($this->fdb, null, $this->user->id);

		if ($this->request->account_id) {
			$acc = new EmailAccount($this->fdb, $this->request->account_id, $this->user->id);
			if (!$acc->valid || !$this->user->created($acc)) {
				formr_error(401, 'Unauthorized', 'You do not have access to modify this email account');
			}
			$vars['form_title'] = "Edit Mail Account ({$acc->account['username']})";
		}

		if (Request::isHTTPPostRequest()) {
			if ($acc->id && $this->request->account_id == $acc->id) {
				// we are editing
				$this->edit($acc);
			} else {
				//we are creating
				$this->create($acc);
			}
		}

		$vars['acc'] = $acc;
		$this->renderView('mail/index', $vars);
	}

	// @todo
	public function deleteAction() {
		if ($this->request->account_id) {
			$acc = new EmailAccount($this->fdb, $this->request->account_id, $this->user->id);
			if ($acc->valid && $this->user->created($acc)) {
				$email = $acc->account['from'];
				$acc->delete();
				alert("<strong>Success:</strong> Account with email '{$email}' was deleted", 'alert-success');
			}
		};
		$this->redirect();
	}

	protected function create(EmailAccount $acc) {
		if ($acc->create()) {
			$this->edit($acc);
		} else {
			alert(implode($acc->errors), 'alert-danger');
		}
	}

	protected function edit(EmailAccount $acc) {
		$acc->changeSettings($this->request->getParams());
		alert('<strong>Success!</strong> Your email account settings were saved!', 'alert-success');

		if ($this->request->test_account) {
			$acc->test();
		}

		$this->redirect($acc);
	}

	protected function redirect(EmailAccount $acc = null) {
		if ($acc === null) {
			redirect_to('admin/mail');
		} else {
			redirect_to('admin/mail', array('account_id' => $acc->id));
		}
	}

}
