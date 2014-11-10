<?php

class ApiController extends Controller {

	public function __construct(Site &$site) {
		parent::__construct($site);
		$this->header();
	}

	public function indexAction() {
		redirect('public/index');
	}

	public function createSessionAction() {
		$i= 0;
		$run_session = new RunSession($this->fdb, $this->run->id, null, null);
		if(isset($_POST['code'])):
			if(is_array($_POST['code'])):
				foreach($_POST['code'] AS $code):
					$i += $run_session->create($code);
				endforeach;
			else:
				$i += $run_session->create($_POST['code']);
			endif;
		else:
			$run_session->create() or die('Error when adding  when creating session');
			$i++;
		endif;

		echo 'Success. '. $i. ' users added.';
	}

	public function endLastExternalAction() {
		if(isset($_POST['session'])):
			$run_session = new RunSession($this->fdb, $this->run->id, null, $_POST['session']);

			if($run_session->session !== NULL)
				$run_session->endLastExternal();
			else
				alert('<strong>Error.</strong> Invalid session token.','alert-danger');

		endif;
		echo $site->renderAlerts();
	}

	private function header() {
		if (isset($_GET['run_name'])):
			$run = new Run($fdb, $_GET['run_name']);
			if (!$run->valid):
				alert("<strong>Error:</strong> Run broken.", 'alert-danger');
			elseif (!isset($_POST['api_secret']) OR ! $run->hasApiAccess($_POST['api_secret'])):
				alert("<strong>Error.</strong> Wrong api secret.", 'alert-danger');
			else:

			endif;
		else:
			alert("<strong>Error.</strong> This run does not exist.", 'alert-danger');
		endif;

		$problems = $site->renderAlerts();
		if (!empty($problems)):
			echo $problems;
			exit;
		endif;
		$this->run = $run;
	}

}
