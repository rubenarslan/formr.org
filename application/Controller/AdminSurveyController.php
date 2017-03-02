<?php

class AdminSurveyController extends AdminController {

	public function __construct(Site &$site) {
		parent::__construct($site);
	}

	public function indexAction($survey_name = '', $private_action = '') {
		$this->setStudy($survey_name);

		if ($private_action) {
			if (empty($this->study) || !$this->study->valid) {
				throw new Exception("You cannot access this page with no valid study");
			}
			$privateAction = $this->getPrivateAction($private_action);
			return $this->$privateAction();
		}

		if (!empty($_POST)) {
			$this->study->changeSettings($_POST);
			redirect_to(admin_study_url($this->study->name));
		}

		if (empty($this->study)) {
			redirect_to(admin_url('survey/add_survey'));
		}
		$this->renderView('survey/index', array('survey_id' => $this->study->id));
	}

	public function addSurveyAction() {
		$settings = $updates = array();
		if (Request::isHTTPPostRequest() && $this->request->google_sheet && $this->request->survey_name) {
			$file = google_download_survey_sheet($this->request->survey_name, $this->request->google_sheet);
			if (!$file) {
				alert("Unable to download the file at '{$this->request->google_sheet}'", 'alert-danger');
			} else {
				$updates['google_file_id'] = $file['google_id'];
			}
		} elseif (Request::isHTTPPostRequest() && !isset($_FILES['uploaded'])) {
			alert('<strong>Error:</strong> You have to select an item table file here.', 'alert-danger');
		} elseif (isset($_FILES['uploaded'])) {
			$file = $_FILES['uploaded'];
		}

		if (!empty($file)) {
			unset($_SESSION['study_id']);
			unset($_GET['study_name']);
			
			$allowed_size = Config::get('admin_maximum_size_of_uploaded_files');
			if ($allowed_size && $file['size'] > $allowed_size * 1024 * 1024):
				alert("File exceeds allowed size of {$allowed_size} MB", 'alert-danger');
			else:
				$filename = basename($file['name']);
				$survey_name = preg_filter("/^([a-zA-Z][a-zA-Z0-9_]{2,64})(-[a-z0-9A-Z]+)?\.[a-z]{3,4}$/", "$1", $filename); // take only the first part, before the dash if present or the dot

				$study = new Survey($this->fdb, null, array(
					'name' => $survey_name,
					'user_id' => $this->user->id
				), null, null);

				if ($study->createIndependently($settings, $updates)) {
					$confirmed_deletion = true;
					$created_new = true;
					if ($study->uploadItemTable($file, $confirmed_deletion, $updates, $created_new)) {
						alert('<strong>Success!</strong> New survey created!', 'alert-success');
						delete_tmp_file($file);
						redirect_to(admin_study_url($study->name, 'show_item_table'));
					} else {
						alert('<strong>Bugger!</strong> A new survey was created, but there were problems with your item table. Please fix them and try again.', 'alert-danger');
						delete_tmp_file($file);
						redirect_to(admin_study_url($study->name, 'upload_items'));
					}
				}
			endif;
			delete_tmp_file($file);
		}

		$vars = array('google' => array());
		$this->renderView('survey/add_survey', $vars);
	}


	private function uploadItemsAction() {
		$updates = array();
		$study = $this->study;
		$google_id = $study->getGoogleFileId();
		$vars = array(
			'study_name' => $study->name,
			'google' => array('id' => $google_id, 'link' => google_get_sheet_link($google_id), 'name' => $study->name),
		);

		if(Request::isHTTPPostRequest()):
			$confirmed_deletion = false;
			if(isset($this->request->delete_confirm)):
				$confirmed_deletion = $this->request->delete_confirm;
			endif;
			if (trim($confirmed_deletion) == ''):
				$confirmed_deletion = false;
			elseif ($confirmed_deletion === $study->name):
				$confirmed_deletion = true;
			else:
				alert("<strong>Error:</strong> You confirmed the deletion of the study's results but your input did not match the study's name. Update aborted.", 'alert-danger');
				$confirmed_deletion = false;
				redirect_to(admin_study_url($study->name, 'upload_items'));
				return false;
			endif;
		endif;

		$file = null;
		if (isset($_FILES['uploaded']) AND $_FILES['uploaded']['name'] !== "") {
			$filename = basename($_FILES['uploaded']['name']);
			$survey_name = preg_filter("/^([a-zA-Z][a-zA-Z0-9_]{2,64})(-[a-z0-9A-Z]+)?\.[a-z]{3,4}$/", "$1", $filename); // take only the first part, before the dash if present or the dot if present
			
			if ($study->name !== $survey_name) {
				alert('<strong>Error:</strong> The uploaded file name <code>' . htmlspecialchars($survey_name) . '</code> did not match the study name <code>' . $study->name . '</code>.', 'alert-danger');
			} else {
				$file = $_FILES['uploaded'];
			}
			
		} elseif (Request::isHTTPPostRequest() && $this->request->google_sheet) {
			$file = google_download_survey_sheet($study->name, $this->request->google_sheet);
			if (!$file) {
				alert("Unable to download the file at '{$this->request->google_id}'", 'alert-danger');
			} else {
				$updates['google_file_id'] = $file['google_id'];
			}
		} elseif (Request::isHTTPPostRequest()) {
			alert('<strong>Error:</strong> You have to select an item table file or enter a Google link here.', 'alert-danger');
		}
		
		if (!empty($file)) {
			$allowed_size = Config::get('admin_maximum_size_of_uploaded_files');
			if ($allowed_size && $file['size'] > $allowed_size * 1024 * 1024):
				alert("File exceeds allowed size of {$allowed_size} MB", 'alert-danger');
				redirect_to(admin_study_url($study->name, 'upload_items'));
				return false;
			endif;
			
			if (($filename = $study->getOriginalFileName()) &&
				files_are_equal($file['tmp_name'], Config::get('survey_upload_dir') . '/' . $filename )) {
				alert("Uploaded item table was identical to last uploaded item table. <br>
					No changes carried out.", 'alert-info');
				$success = false;
			} else {
				$success = $study->uploadItemTable($file, $confirmed_deletion, $updates, false);
			}
			delete_tmp_file($file);
			if($success) {
				redirect_to(admin_study_url($study->name, 'show_item_table'));
			}
		}

		$this->renderView('survey/upload_items', $vars);
	}
	
	private function accessAction() {
		$study = $this->study;
		if ($this->user->created($study)):
			$session = new UnitSession($this->fdb, null, $study->id);
			$session->create();

			Session::set('dummy_survey_session', array(
				"session_id" => $session->id,
				"unit_id" => $study->id,
				"run_session_id" => $session->run_session_id,
				"run_name" => Run::TEST_RUN,
				"survey_name" => $study->name
			));

			alert("<strong>Go ahead.</strong> You can test the study " . $study->name . " now.", 'alert-info');
			redirect_to(run_url(Run::TEST_RUN));
		else:
			alert("<strong>Sorry.</strong> You don't have access to this study", 'alert-danger');
			redirect_to("index");
		endif;
	}

	private function showItemTableAction() {
		$vars = array(
			'google_id' => $this->study->getGoogleFileId(),
			'original_file' => $this->study->getOriginalFileName(),
			'results' => $this->study->getItemsWithChoices()
		);
		if(empty($vars['results'])) {
			alert("No valid item table uploaded so far.", 'alert-warning');
			redirect_to(admin_study_url($this->study->name, 'upload_items'));
		} else {
			$this->renderView('survey/show_item_table', $vars);
		}
	}

	private function showItemdisplayAction() {
		// paginate based on number of items on this sheet so that each
		// run session will have all items for each pagination
		$items = $this->study->getItems('id'); $ids = array();
		foreach ($items as $item) {
			$ids[] = $item['id'];
		}

		$itemsCount = count($ids);
		$result = $this->fdb->query('select count(*) as count from survey_items_display where item_id in ('. implode(',', $ids).')');
		$totalCount = 0;
		if (!empty($result[0]['count'])) {
			$totalCount = $result[0]['count'];
		}

		$session = $this->request->str('session', null);
		// show $no_sessions sessions per_page (so limit = $no_sessions * number of items in a survey)
		$no_sessions = $this->request->int('sess_per_page', 10);
		$limit = $this->request->int('per_page', $no_sessions * $itemsCount);
		$page = ($this->request->int('page', 1) - 1);
		$paginate = array(
			'limit' => $limit,
			'page' => $page,
			'offset' =>  $limit * $page,
			'count' => $totalCount,
		);

		if ($paginate['page'] < 0 || $paginate['limit'] < 0) {
			throw new Exception('Invalid Page number');
		}

		$pagination = new Pagination($paginate['count'], $paginate['limit']);
		$pagination->setPage($paginate['page']);

		$this->registerJS(DEBUG ? 'admin/js/run_users.js' : 'build/js/run_users.min.js');
		$this->renderView('survey/show_itemdisplay', array(
			'resultCount' => $this->study->getResultCount(),
			'results' => $this->study->getItemDisplayResults(null, $session, $paginate),
			'pagination' => $pagination,
			'study_name' => $this->study->name,
			'session' => $session,
		));
	}

	private function showResultsAction() {
		$count = $this->study->getResultCount();
		$totalCount = $count['real_users'] + $count['testers'];
		$limit = $this->request->int('per_page', 100);
		$page = ($this->request->int('page', 1) - 1);
		$paginate = array(
			'limit' => $limit,
			'page' => $page,
			'offset' =>  $limit * $page,
			'order' => 'desc',
			'order_by' => 'session_id',
			'count' => $totalCount,
			'filter' => array(
				'session' => $this->request->str('session'),
			)
		);

		if ($paginate['page'] < 0 || $paginate['limit'] < 0) {
			throw new Exception('Invalid Page number');
		}

		$pagination = new Pagination($paginate['count'], $paginate['limit']);
		$pagination->setPage($paginate['page']);

		$this->renderView('survey/show_results', array(
			'resultCount' => $count,
			'results' =>  $totalCount <= 0 ? array() : $this->study->getResults(null, null, $paginate),
			'pagination' => $pagination,
			'study_name' => $this->study->name,
			'session' => $this->request->str('session'),
		));
	}

	private function deleteResultsAction() {
		$study = $this->study;

		if (isset($_POST['delete']) AND trim($_POST['delete_confirm']) === $study->name) {
			if ($study->deleteResults()):
				alert(implode($study->messages), 'alert-info');
				alert("<strong>Success.</strong> All results in '{$study->name}' were deleted.", 'alert-success');
			else:
				alert(implode($study->errors), 'alert-danger');				
			endif;
			redirect_to(WEBROOT . "admin/survey/{$study->name}/delete_results");
		} elseif (isset($_POST['delete'])) {
			alert("<b>Error:</b> Survey's name must match '{$study->name}' to delete results.", 'alert-danger');
		}

		$this->renderView('survey/delete_results', array(
			'resultCount' => $study->getResultCount(),
		));
	}

	private function deleteStudyAction() {
		$study = $this->study;

		if (isset($_POST['delete']) AND trim($_POST['delete_confirm']) === $study->name) {
			$study->delete();
			alert("<strong>Success.</strong> Successfully deleted study '{$study->name}'.", 'alert-success');
			redirect_to(admin_url());
		} elseif (isset($_POST['delete'])) {
			alert("<b>Error:</b> You must type the study's name '{$study->name}' to delete it.", 'alert-danger');
		}

		$this->renderView('survey/delete_study', array(
			'resultCount' => $study->getResultCount(),
		));
	}

	private function renameStudyAction() {
		$study = $this->study;

		if (isset($_POST['new_name']) && $_POST['new_name'] !== $study->name) {
			$old_name = $study->name;
			if($study->rename($_POST['new_name'])) {
				alert("<strong>Success.</strong> Successfully renamed study from '{$old_name}' to {$study->name}.", 'alert-success');
				redirect_to(admin_study_url($study->name, 'rename_study'));
			}
		}

		$this->renderView('survey/rename_study', array('study_name' => $study->name));
	}

	private function exportItemTableAction() {
		$study = $this->study;

		$format = $this->request->getParam('format');
		if (!$format || !in_array($format, array("xlsx", "xls", "json", "original"))) {
			alert("Invalid format requested", 'alert-danger');
			bad_request();
		}

		$SPR = new SpreadsheetReader();

		if ($format == 'original') {
			$filename = $study->getOriginalFileName();
			$file = Config::get('survey_upload_dir') . '/' . $filename;
			if (!is_file($file)) {
				alert('The original file could not be found. Try another format', 'alert-danger');
				redirect_to(admin_study_url($study->name));
			}

			$type = 'application/vnd.ms-excel';
			//@todo get right type

			header('Content-Disposition: attachment;filename="' . $filename . '"');
			header('Cache-Control: max-age=0');
			header('Content-Type: ' . $type);
			readfile($file);
			exit;
		} elseif ($format == 'xlsx') {
			$SPR->exportItemTableXLSX($study);
		} elseif ($format == 'xls') {
			$SPR->exportItemTableXLS($study);
		} else {
			$SPR->exportItemTableJSON($study);
		}
	}

	private function exportItemdisplayAction() {
		$study = $this->study;

		$results = $study->getItemDisplayResults();
		if (!count($results)) {
			alert("No results to export.", 'alert-danger');
			redirect_to(admin_study_url($this->study->name, 'show_itemdisplay'));
		}

		$SPR = new SpreadsheetReader();

		if (!isset($_GET['format']) || !in_array($_GET['format'], $SPR->exportFormats)) {
			alert("Invalid format requested.", "alert-danger");
			bad_request();
		}
		$format = $_GET['format'];

		if ($format == 'xlsx')
			$SPR->exportXLSX($results, $study->name . "_itemdisplay");
		elseif ($format == 'xls')
			$SPR->exportXLS($results, $study->name . "_itemdisplay");
		elseif ($format == 'csv_german')
			$SPR->exportCSV_german($results, $study->name . "_itemdisplay");
		elseif ($format == 'tsv')
			$SPR->exportTSV($results, $study->name . "_itemdisplay");
		elseif ($format == 'json')
			$SPR->exportJSON($results, $study->name . "_itemdisplay");
		else
			$SPR->exportCSV($results, $study->name . "_itemdisplay");
	}

	private function exportResultsAction() {
		$study = $this->study;
		$results = $study->getResults();
		
		if (!count($results)) {
			alert("No results to export.", 'alert-danger');
			redirect_to(admin_study_url($this->study->name, 'show_results'));
		}
		$SPR = new SpreadsheetReader();

		if (!isset($_GET['format']) OR ! in_array($_GET['format'], $SPR->exportFormats)):
			alert("Invalid format requested.", "alert-danger");
			bad_request();
		endif;
		$format = $_GET['format'];

		if ($format == 'xlsx')
			$SPR->exportXLSX($results, $study->name);
		elseif ($format == 'xls')
			$SPR->exportXLS($results, $study->name);
		elseif ($format == 'csv_german')
			$SPR->exportCSV_german($results, $study->name);
		elseif ($format == 'tsv')
			$SPR->exportTSV($results, $study->name);
		elseif ($format == 'json')
			$SPR->exportJSON($results, $study->name);
		else
			$SPR->exportCSV($results, $study->name);
	}

	private function setStudy($name) {
		if (!$name) {
			return;
		}

		$study = new Survey($this->fdb, null, array('name' => $name, 'user_id' => $this->user->id), null, null);
		if (!$study->valid):
			alert("<strong>Error:</strong> Survey does not exist.", 'alert-danger');
			not_found();
		elseif (!$this->user->created($study)):
			alert("<strong>Error:</strong> Not your survey.", 'alert-danger');
			access_denied();
		endif;
		$this->study = $study;
	}

}
