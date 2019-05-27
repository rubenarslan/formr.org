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

        if ($this->request->isHTTPPostRequest()) {
            $request = new Request($_POST);
            $this->study->changeSettings($request->getParams());
            $this->request->redirect(admin_study_url($this->study->name));
        }

        if (empty($this->study)) {
            $this->request->redirect(admin_url('survey/add_survey'));
        }

        $this->setView('survey/index', array('survey_id' => $this->study->id));
        return $this->sendResponse();
    }

    public function listAction() {
        $vars = array('studies' => $this->user->getStudies('id DESC', null),);
        $this->setView('survey/list', $vars);

        return $this->sendResponse();
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
            if ($allowed_size && $file['size'] > $allowed_size * 1024 * 1024) {
                alert("File exceeds allowed size of {$allowed_size} MB", 'alert-danger');
            } else {
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
                        $this->request->redirect(admin_study_url($study->name, 'show_item_table'));
                    } else {
                        alert('<strong>Bugger!</strong> A new survey was created, but there were problems with your item table. Please fix them and try again.', 'alert-danger');
                        delete_tmp_file($file);
                        $this->request->redirect(admin_study_url($study->name, 'upload_items'));
                    }
                }
            }
            delete_tmp_file($file);
        }

        $vars = array('google' => array());
        $this->setView('survey/add_survey', $vars);

        return $this->sendResponse();
    }

    private function uploadItemsAction() {
        $updates = array();
        $study = $this->study;
        $google_id = $study->getGoogleFileId();
        $vars = array(
            'study_name' => $study->name,
            'google' => array('id' => $google_id, 'link' => google_get_sheet_link($google_id), 'name' => $study->name),
        );

        if (Request::isHTTPPostRequest()) {
            $confirmed_deletion = false;
            if (isset($this->request->delete_confirm)) {
                $confirmed_deletion = $this->request->delete_confirm;
            }

            if (trim($confirmed_deletion) == '') {
                $confirmed_deletion = false;
            } elseif ($confirmed_deletion === $study->name) {
                $confirmed_deletion = true;
            } else {
                alert("<strong>Error:</strong> You confirmed the deletion of the study's results but your input did not match the study's name. Update aborted.", 'alert-danger');
                $confirmed_deletion = false;
                $this->request->redirect(admin_study_url($study->name, 'upload_items'));
                return false;
            }
        }

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
            if ($allowed_size && $file['size'] > $allowed_size * 1024 * 1024) {
                alert("File exceeds allowed size of {$allowed_size} MB", 'alert-danger');
                $this->request->redirect(admin_study_url($study->name, 'upload_items'));
                return false;
            }

            if (($filename = $study->getOriginalFileName()) && files_are_equal($file['tmp_name'], Config::get('survey_upload_dir') . '/' . $filename)) {
                alert("Uploaded item table was identical to last uploaded item table. <br>
					No changes carried out.", 'alert-info');
                $success = false;
            } else {
                $success = $study->uploadItemTable($file, $confirmed_deletion, $updates, false);
            }

            delete_tmp_file($file);
            if ($success) {
                $this->request->redirect(admin_study_url($study->name, 'show_item_table'));
            }
        }

        $this->setView('survey/upload_items', $vars);
        return $this->sendResponse();
    }

    private function accessAction() {
        $study = $this->study;
        if ($this->user->created($study)) {
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
            $this->request->redirect(run_url(Run::TEST_RUN));
        } else {
            alert("<strong>Sorry.</strong> You don't have access to this study", 'alert-danger');
            $this->request->redirect("index");
        }
    }

    private function showItemTableAction() {
        $vars = array(
            'google_id' => $this->study->getGoogleFileId(),
            'original_file' => $this->study->getOriginalFileName(),
            'results' => $this->study->getItemsWithChoices(),
            'shortcut' => $this->request->str('to', null)
        );

        if (empty($vars['results'])) {
            alert("No valid item table uploaded so far.", 'alert-warning');
            $this->request->redirect(admin_study_url($this->study->name, 'upload_items'));
        } else {
            $this->setView('survey/show_item_table', $vars);
            return $this->sendResponse();
        }
    }

    private function showItemdisplayAction() {
        if ($this->study->settings['hide_results']) {
            return $this->hideResults();
        }

        $filter = array(
            'session' => $this->request->str('session'),
            'results' => $this->request->str('rfilter'),
        );

        // paginate based on number of items on this sheet so that each
        // run session will have all items for each pagination
        $items = $this->study->getItems('id');
        $ids = array();
        foreach ($items as $item) {
            $ids[] = $item['id'];
        }

        $no_sessions = $this->request->int('sess_per_page', 10);
        $count = $this->study->getResultCount(null, $filter);
        $totalCount = $count['real_users'] + $count['testers'];
        $limit = $no_sessions;
        $page = ($this->request->int('page', 1) - 1);
        $paginate = array(
            'limit' => $limit,
            'page' => $page,
            'offset' => $limit * $page,
            'count' => $totalCount,
        );

        if ($paginate['page'] < 0 || $paginate['limit'] < 0) {
            throw new Exception('Invalid Page number');
        }

        $pagination = new Pagination($paginate['count'], $paginate['limit']);
        $pagination->setPage($paginate['page']);

        $this->setView('survey/show_itemdisplay', array(
            'resultCount' => $this->study->getResultCount(),
            'results' => $totalCount ? $this->study->getResultsByItemsPerSession(null, $filter, $paginate) : array(),
            'pagination' => $pagination,
            'study_name' => $this->study->name,
            //'session' => $session,
            'session' => $this->request->str('session'),
            'rfilter' => $this->request->str('rfilter'),
            'results_filter' => $this->study->getResultsFilter(),
        ));
        
        return $this->sendResponse();
    }

    private function showResultsAction() {
        if ($this->study->settings['hide_results']) {
            return $this->hideResults();
        }

        $filter = array(
            'session' => $this->request->str('session'),
            'results' => $this->request->str('rfilter'),
        );

        $count = $this->study->getResultCount(null, $filter);
        $totalCount = $count['real_users'] + $count['testers'];
        $limit = $this->request->int('per_page', 100);
        $page = ($this->request->int('page', 1) - 1);
        $paginate = array(
            'limit' => $limit,
            'page' => $page,
            'offset' => $limit * $page,
            'order' => 'desc',
            'order_by' => 'session_id',
            'count' => $totalCount,
        );

        if ($paginate['page'] < 0 || $paginate['limit'] < 0) {
            throw new Exception('Invalid Page number');
        }

        $pagination = new Pagination($paginate['count'], $paginate['limit']);
        $pagination->setPage($paginate['page']);

        $this->setView('survey/show_results', array(
            'resultCount' => $count,
            'results' => $totalCount <= 0 ? array() : $this->study->getResults(null, $filter, $paginate),
            'pagination' => $pagination,
            'study_name' => $this->study->name,
            'session' => $this->request->str('session'),
            'rfilter' => $this->request->str('rfilter'),
            'results_filter' => $this->study->getResultsFilter(),
        ));
        
        return $this->sendResponse();
    }

    private function hideResults() {
        $this->setView('survey/show_results', array(
            'resultCount' => $this->study->getResultCount(),
            'results' => array(),
            'pagination' => new Pagination(1),
            'study_name' => $this->study->name,
        ));

        return $this->sendResponse();
    }

    private function deleteResultsAction() {
        $study = $this->study;

        if (Request::isHTTPPostRequest() && $this->request->getParam('delete_confirm') === $study->name) {
            if ($study->deleteResults()) {
                alert(implode($study->messages), 'alert-info');
                alert("<strong>Success.</strong> All results in '{$study->name}' were deleted.", 'alert-success');
            } else {
                alert(implode($study->errors), 'alert-danger');
            }
            $this->request->redirect(admin_study_url($study->name, 'delete_results'));
        } elseif (Request::isHTTPPostRequest()) {
            alert("<b>Error:</b> Survey's name must match '{$study->name}' to delete results.", 'alert-danger');
        }

        $this->setView('survey/delete_results', array(
            'resultCount' => $study->getResultCount(),
        ));
        
        return $this->sendResponse();
    }

    private function deleteStudyAction() {
        $study = $this->study;

        if (Request::isHTTPPostRequest() && $this->request->getParam('delete_confirm') === $study->name) {
            $study->delete();
            alert("<strong>Success.</strong> Successfully deleted study '{$study->name}'.", 'alert-success');
            $this->request->redirect(admin_url());
        } elseif (Request::isHTTPPostRequest()) {
            alert("<b>Error:</b> You must type the study's name '{$study->name}' to delete it.", 'alert-danger');
        }

        $this->setView('survey/delete_study', array(
            'resultCount' => $study->getResultCount(),
        ));
        
        return $this->sendResponse();
    }

    private function renameStudyAction() {
        $study = $this->study;
        $new_name = $this->request->str('new_name');

        if ($new_name && $new_name !== $study->name) {
            $old_name = $study->name;
            if ($study->rename($new_name)) {
                alert("<strong>Success.</strong> Successfully renamed study from '{$old_name}' to {$study->name}.", 'alert-success');
                $this->request->redirect(admin_study_url($study->name, 'rename_study'));
            }
        }

        $this->setView('survey/rename_study', array('study_name' => $study->name));
        return $this->sendResponse();
    }

    private function exportItemTableAction() {
        $study = $this->study;

        $format = $this->request->getParam('format');
        SpreadsheetReader::verifyExportFormat($format);

        $SPR = new SpreadsheetReader();

        if ($format == 'original') {
            $filename = $study->getOriginalFileName();
            $file = Config::get('survey_upload_dir') . '/' . $filename;
            if (!is_file($file)) {
                alert('The original file could not be found. Try another format', 'alert-danger');
                $this->request->redirect(admin_study_url($study->name));
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

    private function verifyThereIsExportableData($resultsStmt) {
        if ($resultsStmt->rowCount() < 1) {
            alert('No data to export!', 'alert-danger');
            $this->request->redirect(admin_study_url($this->study->name, 'show_itemdisplay'));
        }
    }

    private function exportItemdisplayAction() {
        if ($this->study->settings['hide_results']) {
            return $this->hideResults();
        }

        $study = $this->study;
        $format = $this->request->str('format');
        SpreadsheetReader::verifyExportFormat($format);

        /* @var $resultsStmt PDOStatement */
        $resultsStmt = $study->getItemDisplayResults(null, null, null, true);
        $this->verifyThereIsExportableData($resultsStmt);

        $SPR = new SpreadsheetReader();
        $download_successfull = $SPR->exportInRequestedFormat($resultsStmt, $study->name, $format);
        if (!$download_successfull) {
            alert('An error occured during results download.', 'alert-danger');
            $this->request->redirect(admin_study_url($filename, 'show_results'));
        }
    }

    private function exportResultsAction() {
        if ($this->study->settings['hide_results']) {
            return $this->hideResults();
        }

        $study = $this->study;
        $format = $this->request->str('format');


        /* @var $resultsStmt PDOStatement */
        $resultsStmt = $study->getResults(null, null, null, null, true);
        $this->verifyThereIsExportableData($resultsStmt);

        $SPR = new SpreadsheetReader();
        $download_successfull = $SPR->exportInRequestedFormat($resultsStmt, $study->name, $format);

        if (!$download_successfull) {
            alert('An error occured during results download.', 'alert-danger');
            $this->request->redirect(admin_study_url($filename, 'show_results'));
        }
    }

    private function setStudy($name) {
        if (!$name) {
            return;
        }

        $study = new Survey($this->fdb, null, array('name' => $name, 'user_id' => $this->user->id), null, null);
        if (!$study->valid) {
            formr_error(404, 'Not Found', 'Requested Survey does not exist or has been moved');
        } elseif (!$this->user->created($study)) {
            formr_error(401, 'Unauthorized', 'You do not have access to modify this survey');
        }

        $google_id = $study->getGoogleFileId();
        $this->vars['google'] = array(
            'id' => $google_id,
            'link' => google_get_sheet_link($google_id),
            'name' => $study->name
        );
        $this->study = $study;
    }

}
