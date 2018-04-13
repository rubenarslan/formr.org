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

		if ($this->request->account_id) {
			$vars['form_title'] = 'Edit Mail Account';
			$acc = new EmailAccount($this->fdb, $this->request->account_id, $this->user->id);
			if (!$acc->valid || !$this->user->created($acc)) {
				alert("<strong>Error:</strong> Not your email account.", 'alert-danger');
				$this->redirect(null);
			}
		} else {
			$acc = new EmailAccount($this->fdb, null, $this->user->id);
		}

		if (Request::isHTTPPostRequest()) {
			if ($acc->id && $this->request->account_id == $acc->id) {
				// we are editing
				if ($this->request->test_account) {
					$acc->test();
					$this->redirect($acc);
				} else {
					$acc->changeSettings($this->request->getParams());
					alert('<strong>Success!</strong> Your email account settings were changed!', 'alert-success');
					$this->redirect($acc);
				}
			} else {
				//we are creating
				if ($acc->create()) {
					$acc->changeSettings($this->request->getParams());
					alert('<strong>Success!</strong> You added a new email account!', 'alert-success');
					if ($this->request->test_account) {
						$acc->test();
						$this->redirect($acc);
					} else {
						$this->redirect($acc);
					}
				} else {
					alert(implode($acc->errors), 'alert-danger');
				}
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
		$this->redirect(null);
	}

	protected function redirect(EmailAccount $acc) {
		if ($acc === null) {
			redirect_to('admin/mail');
		} else {
			redirect_to('admin/mail', array('account_id' => $acc->id));
		}
	}

}
