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
            $this->changeSettings((new Request($_POST))->getParams());
            return $this->request->redirect(admin_study_url($this->study->name));
        }

        if (empty($this->study)) {
            return $this->request->redirect(admin_url('survey/add_survey'));
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
        
        if (Request::isHTTPPostRequest()) {

            if ($this->request->google_sheet && $this->request->survey_name) {
                // Google sheet was used
                $file = google_download_survey_sheet($this->request->survey_name, $this->request->google_sheet);
                if (!$file) {
                    alert("Unable to download the file at '{$this->request->google_sheet}'", 'alert-danger');
                    return $this->request->redirect('admin/survey');
                }
            } elseif (isset($_FILES['uploaded'])) {
                // Excel file was uploaded
                $file = $_FILES['uploaded'];
            } else {
                // Nothing was uploaded
                alert('<strong>Error:</strong> You have to select an item table file here.', 'alert-danger');
                return $this->request->redirect('admin/survey');
            }
            
            
            if ($this->validateUploadedFile($file)) {
                $study = new SurveyStudy(null);
                if ($study->createFromFile($file)) {
                    // upload items
                    if ($study->uploadItems($file, true, true)) {
                        alert('<strong>Success!</strong> New survey created!', 'alert-success');
                        $redirect = admin_study_url($study->name, 'show_item_table');
                    } else {
                        alert('<strong>Bugger!</strong> A new survey was created, but there were problems with your item table. Please fix them and try again.', 'alert-danger');
                        $redirect = admin_study_url($study->name, 'upload_items');
                    }
                    
                    delete_tmp_file($file);
                    return $this->request->redirect($redirect);
                }
                
            } else {
                delete_tmp_file($file);
                return $this->request->redirect('admin/survey');
            }
            
        }

        $vars = array('google' => array());
        $this->setView('survey/add_survey', $vars);

        return $this->sendResponse();
    }
    
    protected function validateUploadedFile($file, $editing = false) {
        if (empty($file['name'])) {
            return false;
        }

        // Define the list of allowed extensions
        $allowedExtensions = array('xls', 'xlsx', 'ods', 'xml', 'txt', 'csv');
        // Get the file extension
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        // Check if the extension is in the allowed list
        if(!in_array($fileExtension, $allowedExtensions)){
            alert("<strong>Error:</strong> The format must be one of .xls, .xlsx, .ods, .xml, .txt, or .csv.", 'alert-danger');
            return false;
        }
        
        $name = preg_filter("/^([a-zA-Z][a-zA-Z0-9_]{2,64})(-[a-z0-9A-Z]+)?\.[a-z]{3,4}$/", "$1", basename($file['name']));
        if (!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,64}/", (string)$name)) {
            alert("<strong>Error:</strong> The study name (the name of the file you uploaded) can only contain the characters from <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore.
                    The name has to at least 2, at most 64 characters long. It needs to start with a letter. No dots, no spaces, no dashes, no umlauts please. 
                    The file can have version numbers after a dash, like this <code>survey_1-v2.xlsx</code>, but they will be ignored.", 'alert-danger');
            return false;
        }
        
        $allowed_size = Config::get('admin_maximum_size_of_uploaded_files');
        if ($allowed_size && $file['size'] > $allowed_size * 1024 * 1024) {
            alert("File exceeds allowed size of {$allowed_size} MB", 'alert-danger');
            return false;
        }
        
        if ($this->fdb->entry_exists('survey_studies', array('name' => $name, 'user_id' => $this->user->id)) && $editing === false) {
            alert(__("<strong>Error:</strong> The survey name %s is already taken.", h($name)), 'alert-danger');
            return false;
        }
        
        return true;
    }

    private function uploadItemsAction() {
        $updates = array();
        $study = $this->study;
        $google_id = $study->google_file_id;
        
        $vars = array(
            'study_name' => $study->name,
            'google' => array(
                'id' => $google_id, 
                'link' => google_get_sheet_link($google_id), 
                'name' => $study->name
            ),
        );

        if (Request::isHTTPPostRequest()) {
            $can_delete = false;
            if ($this->request->delete_confirm) {
                if ($this->request->delete_confirm !== $study->name) {
                    alert("<strong>Error:</strong> You confirmed the deletion of the study's results but your input did not match the study's name. Update aborted.", 'alert-danger');
                    return $this->request->redirect(admin_study_url($study->name, 'upload_items'));
                }
                
                $can_delete = true;
            }
            
            if ($this->request->google_sheet) {
                // Google sheet was used
                $file = google_download_survey_sheet($study->name, $this->request->google_sheet);
                if (!$file) {
                    alert("Unable to download the file at '{$this->request->google_sheet}'", 'alert-danger');
                    return $this->request->redirect(admin_study_url($study->name, 'upload_items'));
                }
                
                if ($study->google_file_id != $file['google_file_id']) {
                    $study->google_file_id = $file['google_file_id'];
                    $study->save();
                }
            } elseif (isset($_FILES['uploaded'])) {
                // Excel file was uploaded
                $file = $_FILES['uploaded'];
            } else {
                // Nothing was uploaded
                alert('<strong>Error:</strong> You have to select an item table file here.', 'alert-danger');
                return $this->request->redirect(admin_study_url($study->name, 'upload_items'));
            }
            
            
            if ($this->validateUploadedFile($file, true)) {
                $survey_name = preg_filter("/^([a-zA-Z][a-zA-Z0-9_]{2,64})(-[a-z0-9A-Z]+)?\.[a-z]{3,4}$/", "$1", basename($file['name']));
                if ($study->name !== $survey_name) {
                    alert('<strong>Error:</strong> The uploaded file name <code>' . htmlspecialchars($survey_name) . '</code> did not match the study name <code>' . $study->name . '</code>.', 'alert-danger');
                    delete_tmp_file($file);
                    return $this->request->redirect(admin_study_url($study->name, 'upload_items'));
                }
                
                if (($filename = $study->getOriginalFileName()) && files_are_equal($file['tmp_name'], Config::get('survey_upload_dir') . '/' . $filename)) {
                    alert("Uploaded item table was identical to last uploaded item table. <br /> No changes carried out.", 'alert-info');
                    delete_tmp_file($file);
                    return $this->request->redirect(admin_study_url($study->name, 'upload_items'));
                }

                if ($study->uploadItems($file, $can_delete, false)) {
                    // upload items
                    alert('<strong>Success!</strong> Survey items uploaded!', 'alert-success');
                    delete_tmp_file($file);
                    return $this->request->redirect(admin_study_url($study->name, 'upload_items'));
                }
                
            } else {
                delete_tmp_file($file);
                return $this->request->redirect('admin/survey');
            } 
 
        }

        $this->setView('survey/upload_items', $vars);
        return $this->sendResponse();
    }

    private function accessAction() {
        Session::set('test_study_data', array(
            'study_id' => $this->study->id,
            'study_name' => $this->study->name,
            'unit_id' => $this->study->id,
            'data' => $this->study->getItems('id, name, type'),
        ));

        alert("<strong>Go ahead.</strong> You can test the study " . $this->study->name . " now.", 'alert-info');
        $this->request->redirect(run_url(Run::TEST_RUN));
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
        if ($this->study->hide_results) {
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
        if ($this->study->hide_results) {
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
        $old_name = $study->name;

        if ($new_name && $new_name !== $study->name) {
            if (!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,64}/", $new_name) || 
                    $this->fdb->entry_exists('survey_studies', array('name' => $new_name, 'user_id' => $this->user->id))) {

                alert('A study with this name already exists', 'alert-danger');
                return $this->request->redirect(admin_study_url($study->name, 'rename_study'));
            }

            $study->name = $new_name;
            $study->save();
            alert("<strong>Success.</strong> Successfully renamed study from '{$old_name}' to {$study->name}.", 'alert-success');
            
            return $this->request->redirect(admin_study_url($study->name, 'rename_study'));
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
        if ($this->study->hide_results) {
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
            $this->request->redirect(admin_study_url($study->name, 'show_results'));
        }
    }

    private function exportResultsAction() {
        if ($this->study->hide_results) {
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
    
    private function changeSettings($settings) {
        $errors = false;
        array_walk($settings, function (&$value, $key) {
            if ($key !== 'google_file_id') {
                $value = (int) $value;
            }
        });
        if (isset($settings['maximum_number_displayed']) && $settings['maximum_number_displayed'] > 3000 || $settings['maximum_number_displayed'] < 0) {
            alert("Maximum number displayed has to be between 1 and 3000", 'alert-warning');
            $errors = true;
        }

        if (isset($settings['displayed_percentage_maximum']) && $settings['displayed_percentage_maximum'] > 100 || $settings['displayed_percentage_maximum'] < 1) {
            alert("Percentage maximum has to be between 1 and 100.", 'alert-warning');
            $errors = true;
        }

        if (isset($settings['add_percentage_points']) && $settings['add_percentage_points'] > 100 || $settings['add_percentage_points'] < 0) {
            alert("Percentage points added has to be between 0 and 100.", 'alert-warning');
            $errors = true;
        }

        $settings['enable_instant_validation'] = (int) (isset($settings['enable_instant_validation']) && $settings['enable_instant_validation'] == 1);
        $settings['hide_results'] = (int) (isset($settings['hide_results']) && $settings['hide_results'] === 1);
        $settings['use_paging'] = (int) (isset($settings['use_paging']) && $settings['use_paging'] === 1);
        $settings['unlinked'] = (int) (isset($settings['unlinked']) && $settings['unlinked'] === 1);

        // user can't revert unlinking
        if ($settings['unlinked'] < $this->study->unlinked) {
            alert("Once a survey has been unlinked, it cannot be relinked.", 'alert-warning');
            $errors = true;
        }

        // user can't revert preventing results display
        if ($settings['hide_results'] < $this->study->hide_results) {
            alert("Once results display is disabled, it cannot be re-enabled.", 'alert-warning');
            $errors = true;
        }

        // user can't revert preventing results display
        if ($settings['use_paging'] < $this->study->use_paging) {
            alert("Once you have enabled the use of custom paging, you can't revert this setting.", 'alert-warning');
            $errors = true;
        }

        if (isset($settings['expire_after']) && $settings['expire_after'] > 3153600) {
            alert("Survey expiry time (in minutes) has to be below 3153600.", 'alert-warning');
            $errors = true;
        }

        if ($errors) {
            return false;
        }
        
        $this->study->update($settings);

        alert('Survey settings updated', 'alert-success', true);
    }

    private function setStudy($name) {
        if (!$name) {
            return;
        }

        $study = SurveyStudy::loadByUserAndName($this->user, $name);
        if (!$study->valid) {
            formr_error(404, 'Not Found', 'Requested Survey does not exist or has been moved');
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
