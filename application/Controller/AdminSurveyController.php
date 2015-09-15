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
			redirect_to(WEBROOT . "admin/survey/{$this->study->name}");
		}

		if (empty($this->study)) {
			redirect_to(WEBROOT . 'admin/survey/add_survey');
		}
		$this->renderView('survey/index');
	}

	public function addSurveyAction() {
		$settings = $params = array();
		if (Request::isHTTPPostRequest() && $this->request->google_sheet && $this->request->survey_name) {
			preg_match('/spreadsheets\/d\/(.*)\/edit/', $this->request->google_sheet, $matches);
			if (!empty($matches[1])) {
				$google_id = $matches[1];
				$destination = Config::get('survey_upload_dir') . '/google-' . $google_id . '.xlsx';
				$sheet = download_google_sheet($google_id, $destination);
				if ($sheet) {
					$params['google_file_id'] = $sheet['google_id'];
					$file = array(
						'name' => $this->request->survey_name . '.xlsx',
						'tmp_name' => $sheet['file'],
						'size' => filesize($sheet['file'])
					);
				} else {
					alert("Unable to download the file at '{$this->request->google_sheet}'", 'alert-danger');
				}
			}

		} elseif (Request::isHTTPPostRequest() && !isset($_FILES['uploaded'])) {
			alert('<strong>Error:</strong> You have to select an item table file here.', 'alert-danger');
		} elseif (isset($_FILES['uploaded'])) {
			$file = $_FILES['uploaded'];
		}

		if (!empty($file)) {
			unset($_SESSION['study_id']);
			unset($_GET['study_name']);

			$filename = basename($file['name']);
			$survey_name = preg_filter("/^([a-zA-Z][a-zA-Z0-9_]{2,64})(-[a-z0-9A-Z]+)?\.[a-z]{3,4}$/", "$1", $filename); // take only the first part, before the dash if present or the dot

			$study = new Survey($this->fdb, null, array(
				'name' => $survey_name,
				'user_id' => $this->user->id
			), null, null);
			if ($study->createIndependently($settings, $params)) {
				if ($study->uploadItemTable($file, $survey_name)) {
					alert('<strong>Success!</strong> New survey created!', 'alert-success');
					redirect_to("admin/survey/{$study->name}/show_item_table");
				} else {
					alert('<strong>Bugger!</strong> A new survey was created, but there were problems with your item table. Please fix them and try again.', 'alert-danger');
					redirect_to("admin/survey/{$study->name}/upload_items");
				}
			}
		}

		$this->renderView('survey/add_survey');
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

	private function uploadItemsAction() {
		$study = $this->study;
		$vars = array(
			'google_id' => $study->getGoogleFileId()
		);
		if (Request::isHTTPPostRequest() && $this->request->google_id) {
			$google_id = $this->request->google_id;
			$destination = Config::get('survey_upload_dir') . '/google-' . $google_id . '.xlsx';
			$sheet = download_google_sheet($google_id, $destination);
			if ($sheet) {
				$params['google_file_id'] = $sheet['google_id'];
				$file = array(
					'name' => $study->name . '.xlsx',
					'tmp_name' => $sheet['file'],
					'size' => filesize($sheet['file'])
				);
			} else {
				alert("Unable to download the file at '{$this->request->google_sheet}'", 'alert-danger');
			}
		} elseif (Request::isHTTPPostRequest() && !isset($_FILES['uploaded'])) {
			alert('<strong>Error:</strong> You have to select an item table file here.', 'alert-danger');
		} elseif (isset($_FILES['uploaded'])) {
			$filename = basename($_FILES['uploaded']['name']);
			$survey_name = preg_filter("/^([a-zA-Z][a-zA-Z0-9_]{2,64})(-[a-z0-9A-Z]+)?\.[a-z]{3,4}$/", "$1", $filename); // take only the first part, before the dash if present or the dot if present

			if ($study->name !== $survey_name) {
				alert('<strong>Error:</strong> The uploaded file name <code>' . htmlspecialchars($survey_name) . '</code> did not match the study name <code>' . $study->name . '</code>.', 'alert-danger');
			} else {
				$file = $_FILES['uploaded'];
			}
		}

		if (!empty($file) && $study->uploadItemTable($file, $_POST['delete_confirm'])) {
			redirect_to(admin_study_url($study->name, 'show_item_table'));
		}
		$this->renderView('survey/upload_items', $vars);
	}

	private function showItemTableAction() {
		$this->renderView('survey/show_item_table');
	}

	private function showItemdisplayAction() {
		$this->renderView('survey/show_itemdisplay', array(
			'resultCount' => $this->study->getResultCount(),
			'results' => $this->study->getItemDisplayResults(),
		));
	}

	private function showResultsAction() {
		$this->renderView('survey/show_results', array(
			'resultCount' => $this->study->getResultCount(),
			'results' => $this->study->getResults(),
		));
	}

	private function deleteResultsAction() {
		$study = $this->study;

		if (isset($_POST['delete']) AND trim($_POST['delete_confirm']) === $study->name) {
			if ($study->deleteResults()):
				alert("<strong>Success.</strong> All results in '{$study->name}' were deleted.", 'alert-success');
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
			redirect_to(WEBROOT . "admin/index");
		} elseif (isset($_POST['delete'])) {
			alert("<b>Error:</b> You must type the study's name '{$study->name}' to delete it.", 'alert-danger');
		}

		$this->renderView('survey/delete_study', array(
			'resultCount' => $study->getResultCount(),
		));
	}

	private function exportItemTableAction() {
		$study = $this->study;

		$format = $this->request->getParam('format');
		if (!$format || !in_array($format, array("xlsx", "xls", "json", "original"))) {
			die("invalid format");
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
			die("Nothing to export");
		}

		$SPR = new SpreadsheetReader();

		if (!isset($_GET['format']) OR ! in_array($_GET['format'], $SPR->exportFormats)):
			alert("Invalid format requested.", "alert-danger");
			bad_request();
		endif;
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

		$study = new Survey($this->fdb, null, array('name' => $name), null, null);
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
