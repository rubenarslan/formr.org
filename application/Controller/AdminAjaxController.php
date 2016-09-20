<?php

/**
 * Group Admin Ajax requests here
 * Takes in a controller of type AdminController
 * which already have most required 'global' variables defined
 * 
 */
class AdminAjaxController {

	/**
	 *
	 * @var AdminController
	 */
	protected $controller;

	/**
	 *
	 * @var Site
	 */
	protected $site;

	/**
	 * @var Request
	 */
	protected $request;

	/**
	 *
	 * @var DB
	 */
	protected $dbh;

	public function __construct(AdminController $controller) {
		$this->controller = $controller;
		$this->site = $controller->getSite();
		$this->dbh = $controller->getDB();
		$this->request = new Request();
	}

	public static function call($method, AdminController $controller) {
		$self = new self($controller);
		$action = $self->getPrivateAction($method);
		return $self->$action();
	}

	private function ajaxCreateRunUnit() {
		if (is_ajax_request()):
			$unit = $this->controller->createRunUnit();
			if ($unit->valid):
				$unit->addToRun($this->controller->run->id, $unit->position);
				alert('<strong>Success.</strong> ' . ucfirst($unit->type) . ' unit was created.', 'alert-success');
				echo $unit->displayForRun($this->site->renderAlerts());
				exit;
			endif;
		endif;

		bad_request_header();
		$alert_msg = "'<strong>Sorry.</strong> '";
		if (isset($unit)) {
			$alert_msg .= implode($unit->errors);
		}
		alert($alert_msg, 'alert-danger');
		echo $this->site->renderAlerts();
	}

	private function ajaxGetUnit() {
		$run = $this->controller->run;
		$dbh = $this->dbh;

		if (is_ajax_request()) :
			if ($run_unit_id = $this->request->getParam('run_unit_id')):
				$special = $this->request->getParam('special');

				$unit_info = $run->getUnitAdmin($run_unit_id, $special);
				$unit_factory = new RunUnitFactory();
				$unit = $unit_factory->make($dbh, null, $unit_info, null, $run);

				echo $unit->displayForRun();
				exit;
			endif;
		endif;

		bad_request_header();
		$alert_msg = "<strong>Sorry, missing unit.</strong> ";
		if (isset($unit)) {
			$alert_msg .= implode($unit->errors);
		}
		alert($alert_msg, 'alert-danger');
		echo $this->site->renderAlerts();
	}

	private function ajaxRemind() {
		$run = $this->controller->run;
		// find the last email unit
		$email = $run->getReminder($this->request->getParam('reminder'), $this->request->getParam('session'), $this->request->getParam('run_session_id'));
		$email->run_session = new RunSession($this->dbh, $run->id, null, $this->request->getParam('session'), $run);
		if ($email->exec() !== false) {
			alert('<strong>Something went wrong with the reminder.</strong> Run: ' . $run->name, 'alert-danger');
		} else {
			alert('Reminder sent!', 'alert-success');
		}
		$email->end();

		if (is_ajax_request()) {
			echo $this->site->renderAlerts();
			exit;
		} else {
			redirect_to("admin/run/" . $run->name . "/user_overview");
		}
	}
	

	private function ajaxToggleTesting() {
		$run = $this->controller->run;
		$dbh = $this->dbh;

		$run_session = new RunSession($dbh, $run->id, null, $this->request->getParam('session'), $run);
		
		$status = $this->request->getParam('toggle_on') ? 1: 0;
		$run_session->setTestingStatus( $status );
		
		if (is_ajax_request()):
			echo $this->site->renderAlerts();
			exit;
		else:
			redirect_to("admin/run/" . $run->name . "/user_overview");
		endif;
	}

	private function ajaxSendToPosition() {
		$run = $this->controller->run;
		$dbh = $this->dbh;

		$run_session = new RunSession($dbh, $run->id, null, $_POST['session'], $run);
		$new_position = $this->request->int('new_position');
		$_POST = array();

		if (!$run_session->forceTo($new_position)):
			alert('<strong>Something went wrong with the position change.</strong> Run: ' . $run->name, 'alert-danger');
			bad_request_header();
		endif;

		if (is_ajax_request()):
			echo $this->site->renderAlerts();
			exit;
		else:
			redirect_to("admin/run/" . $run->name . "/user_overview");
		endif;
	}
	private function ajaxNextInRun() {
		$run = $this->controller->run;
		$dbh = $this->dbh;

		$run_session = new RunSession($dbh, $run->id, null, $_GET['session'], $run);

		if (!$run_session->endUnitSession()):
			alert('<strong>Something went wrong with the unpause.</strong> in run ' . $run->name, 'alert-danger');
			bad_request_header();
		endif;

		if (is_ajax_request()):
			echo $this->site->renderAlerts();
			exit;
		else:
			redirect_to("admin/run/" . $run->name . "/user_overview");
		endif;
	}

	private function ajaxSnipUnitSession() {
		$run = $this->controller->run;
		$dbh = $this->dbh;
		$run_session = new RunSession($dbh, $run->id, null, $this->request->getParam('session'), $run);

		$unit_session = $run_session->getUnitSession();
		if($unit_session):
			$deleted = $dbh->delete('survey_unit_sessions', array('id' => $unit_session->id));
			if($deleted):
				alert('<strong>Success.</strong> You deleted the data at the current position.','alert-success');
			else:
				alert('<strong>Couldn\'t delete.</strong>', 'alert-danger');
				bad_request_header();
			endif;
		else:
			alert("No unit session found", 'alert-danger');
		endif;

		if (is_ajax_request()):
			echo $this->site->renderAlerts();
			exit;
		else:
			redirect_to("admin/run/" . $run->name . "/user_overview");
		endif;
	}

	private function ajaxDeleteUser() {
		$run = $this->controller->run;
		$deleted = $this->dbh->delete('survey_run_sessions', array('id' => $this->request->getParam('run_session_id')));
		if ($deleted) {
			alert('User with session ' . h($_GET['session']) . ' was deleted.', 'alert-info');
		} else {
			alert('User with session ' . h($_GET['session']) . ' could not be deleted.', 'alert-warning');
			bad_request_header();
		}

		if (!is_ajax_request()) {
			redirect_to(admin_run_url($run->name, 'user_overview'));
		}
		echo $this->site->renderAlerts();
		exit;
	}

	private function ajaxDeleteUnitSession() {
		$run = $this->controller->run;
		$del = $this->dbh->prepare('DELETE FROM `survey_unit_sessions` WHERE id = :id');
		$del->bindParam(':id', $_GET['session_id']);

		if($del->execute()):
			alert('<strong>Success.</strong> You deleted this unit session.','alert-success');
		else:
			alert('<strong>Couldn\'t delete.</strong> Sorry. <pre>'. print_r($del->errorInfo(), true).'</pre>','alert-danger');
			bad_request_header();
		endif;

		if (is_ajax_request()):
			echo $this->site->renderAlerts();
			exit;
		else:
			redirect_to("admin/run/" . $run->name . "/user_detail");
		endif;

	}

	private function ajaxRemoveRunUnitFromRun() {
		$run = $this->controller->run;
		$dbh = $this->dbh;

		if (is_ajax_request()) {
			if (($run_unit_id = $this->request->getParam('run_unit_id'))) {
				$special = $this->request->getParam('special');

				$unit_info = $run->getUnitAdmin($run_unit_id, $special);
				$unit_factory = new RunUnitFactory();
				/* @var $unit RunUnit */
				$unit = $unit_factory->make($dbh, null, $unit_info, null, $run);
				if (!$unit) {
					not_found();
				}
				$sess_key = __METHOD__ . $unit->id;
				$results = $unit->howManyReachedItNumbers();
				$has_sessions = $results && (array_val($results, 'begun') || array_val($results, 'finished') || array_val($results, 'expired'));

				if ($has_sessions && !Session::get($sess_key)) {
					Session::set($sess_key, $unit->id);
					echo 'warn';
					exit;
				} elseif (!$has_sessions || (Session::get($sess_key) === $unit->id && $this->request->getParam('confirm') === 'yes')) {
					if ($unit->removeFromRun($special)) {
						alert('<strong>Success.</strong> Unit with ID ' . $this->request->run_unit_id . ' was deleted.', 'alert-success');
					} else {
						bad_request_header();
						$alert_msg = '<strong>Sorry, could not remove unit.</strong> ';
						$alert_msg .= implode($unit->errors);
						alert($alert_msg, 'alert-danger');
					}
				}
			}
		} else {
			not_found();
		}

		Session::delete($sess_key);
		echo $this->site->renderAlerts();
		exit;
	}

	private function ajaxReorder() {
		$run = $this->controller->run;
		if (is_ajax_request()):
			if (isset($_POST['position'])):
				$unit = $run->reorder($_POST['position']);
				exit;
			endif;
		endif;

		bad_request_header();
		$alert_msg = "'<strong>Sorry.</strong> '";
		if (isset($unit)) {
			$alert_msg .= implode($unit->errors);
		}
		alert($alert_msg, 'alert-danger');

		echo $this->site->renderAlerts();
	}

	private function ajaxRunCronToggle() {
		$run = $this->controller->run;
		if (is_ajax_request()):
			if (isset($_POST['on'])):
				if (!$run->toggleCron((bool) $_POST['on']))
					echo 'Error!';
			endif;
		endif;
	}

	private function ajaxRunImport() {
		$run = $this->controller->run;
		$site = $this->site;

		if (!is_ajax_request()) {
			bad_request_header();
			exit;
		}
		// If only showing dialog then show it and exit
		$dialog_only = $site->request->bool('dialog');
		if ($dialog_only) {
			// Read on exported runs from configured directory
			$dir = Config::get('run_exports_dir');
			if (!($exports = (array) get_run_dir_contents($dir))) {
				$exports = array();
			}

			Template::load('admin/run/run_import_dialog', array('exports' => $exports, 'run' => $this->controller->run));
			exit;
		}
	}

	private function ajaxRunLockedToggle() {
		$run = $this->controller->run;
		if (is_ajax_request()):
			if (isset($_POST['on'])):
				if (!$run->toggleLocked((bool) $_POST['on']))
					echo 'Error!';
				$this->site->renderAlerts();
			endif;
		endif;
	}

	private function ajaxRunPublicToggle() {
		$run = $this->controller->run;
		if (is_ajax_request()):
			if (isset($_GET['public'])):
				if (!$run->togglePublic((int) $_GET['public']))
					echo 'Error!';
			endif;
		endif;
	}

	private function ajaxSaveRunUnit() {
		$run = $this->controller->run;
		$dbh = $this->dbh;

		if (is_ajax_request()):

			$unit_factory = new RunUnitFactory();
			if ($run_unit_id = $this->request->getParam('run_unit_id')):
				$special = $this->request->getParam('special');
				$unit_info = $run->getUnitAdmin($run_unit_id, $special);

				$unit = $unit_factory->make($dbh, null, $unit_info, null, $run);

				$unit->create($_POST);
				if ($unit->valid):
					if($unit->hadMajorChanges() OR !empty($this->site->alerts)):
						echo $unit->displayForRun($this->site->renderAlerts());
					else:
						echo '';
					endif;
					exit;
				endif;
			endif;
		endif;
		bad_request_header();
		$alert_msg = "<strong>Sorry.</strong> Something went wrong while saving. Please contact formr devs, if this problem persists.";
		if (isset($unit))
			$alert_msg .= implode($unit->errors);
		alert($alert_msg, 'alert-danger');

		echo $this->site->renderAlerts();
	}

	private function ajaxSaveSettings() {
		$run = $this->controller->run;
		$post = new Request($_POST);
		if (is_ajax_request()):
			$saved = $run->saveSettings($post->getParams());
			if ($saved):
				alert('Settings saved', 'alert-success');
				echo $this->site->renderAlerts();
				exit;
			else:
				bad_request_header();
				alert('<strong>Error.</strong> ' . implode($run->errors, "<br>"), 'alert-danger');
				echo $this->site->renderAlerts();
			endif;
		endif;
	}

	private function ajaxTestUnit() {
		$run = new Run($this->dbh, $this->controller->run->name);

		if (is_ajax_request()):
			if ($run_unit_id = $this->request->getParam('run_unit_id')):
				$special = $this->request->getParam('special');
				$unit = $run->getUnitAdmin($run_unit_id, $special);
				$unit_factory = new RunUnitFactory();
				$unit = $unit_factory->make($this->dbh, null, $unit, null, $run);

				$unit->test();
				echo $this->site->renderAlerts();
				exit;
			endif;
		endif;

		bad_request_header();
		$alert_msg = "'<strong>Sorry.</strong> '";
		if (isset($unit)) {
			$alert_msg .= implode($unit->errors);
		}
		alert($alert_msg, 'alert-danger');

		echo $this->site->renderAlerts();
	}

	private function ajaxUserBulkActions() {
		if (!is_ajax_request()) {
			redirect_to(admin_url());
		}
		$action = $this->request->str('action');
		$sessions = $this->request->arr('sessions');
		$qs = $res = array();
		if (!$action || !$sessions) {
			bad_request();
			exit;
		}
		foreach ($sessions as $session) {
			$qs[] = $this->dbh->quote($session);
		}
		$count = count($sessions);
		if ($action === 'toggleTest') {
			$query = 'UPDATE survey_run_sessions SET testing = 1 - testing WHERE session IN ('. implode(',', $qs) . ')';
			$this->dbh->query($query);
			alert("{$count} selected session(s) were successfully modified", 'alert-success');
			$res['success'] = true;
		} elseif ($action === 'sendReminder') {
			$run = $this->controller->run;
			$count = 0;
			foreach ($sessions as $sess) {
				$runSession = new RunSession($this->dbh, $run->id, null, $sess, $run);
				$email = $run->getReminder($this->request->int('reminder'), $sess, $runSession->id);
				$email->run_session = $runSession;
				if ($email->exec() === false) {
					$count++;
				}
				$email->end();
			}

			if ($count) {
				alert("{$count} session(s) have been sent the reminder '{$email->getSubject()}'", 'alert-success');
				$res['success'] = true;
			} else {
				$res['error'] = $this->site->renderAlerts();
			}
		} elseif ($action === 'deleteSessions') {
			$query = 'DELETE FROM survey_run_sessions WHERE session IN ('. implode(',', $qs) . ')';
			$this->dbh->query($query);
			alert("{$count} selected session(s) were successfully deleted", 'alert-success');
			$res['success'] = true;
		} elseif ($action === 'positionSessions') {
			$query = 'UPDATE survey_run_sessions SET position = ' . $this->request->int('pos') . ' WHERE session IN ('. implode(',', $qs) . ')';
			$this->dbh->query($query);
			alert("{$count} selected session(s) were successfully moved", 'alert-success');
			$res['success'] = true;
		}

		header('Content-Type: application/json');
		echo json_encode($res);
		exit(0);
	}

	protected function getPrivateAction($name) {
		$parts = array_filter(explode('_', $name));
		$action = array_shift($parts);
		$class = __CLASS__;
		foreach ($parts as $part) {
			$action .= ucwords(strtolower($part));
		}
		if (!method_exists($this, $action)) {
			throw new Exception("Action '$name' is not found in $class.");
		}
		return $action;
	}

}
