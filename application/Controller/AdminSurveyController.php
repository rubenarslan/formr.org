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

            if (isset($_FILES['uploaded'])) {
                // Excel file was uploaded
                $file = $_FILES['uploaded'];
            } elseif ($this->request->google_sheet) {
                // Google sheet was used
                $file = google_download_survey_sheet($this->request->google_sheet);
                if (!$file) {
                    alert("Unable to download the file at '{$this->request->google_sheet}'", 'alert-danger');
                    return $this->request->redirect('admin/survey');
                }
            }  else {
                // Nothing was uploaded
                alert('<strong>Error:</strong> You have to select an item table file here.', 'alert-danger');
                return $this->request->redirect('admin/survey');
            }
            
            
            // The engine chosen on the form, if any; otherwise the file's own
            // settings decide (a JSON "settings" / a spreadsheet's
            // `settings` sheet), and without one the survey is classic v1.
            // The choice is offered where the operator has enabled form_v2
            // authoring (form_v2_enabled, like the run editor's Add Form).
            // Where it is off, every upload arrives on v1, whatever the file
            // says: a v2 survey could not be placed in any run (Survey units
            // refuse it, and Add Form is hidden).
            $engine = in_array($_POST['engine'] ?? '', array('v1', 'v2'), true) ? $_POST['engine'] : null;
            if (!Config::get('form_v2_enabled')) {
                $engine = 'v1';
            }

            if ($this->validateUploadedFile($file)) {
                // JSON survey export → import via createFromData (the same path
                // run-structure imports use). Only reached for new surveys; the
                // edit/re-upload path stays spreadsheet-only.
                if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'json') {
                    $data = json_decode(file_get_contents($file['tmp_name']));
                    if (!is_object($data) || empty($data->name) || empty($data->items)) {
                        alert('<strong>Error:</strong> That is not a valid survey JSON export (it must have "name" and "items").', 'alert-danger');
                        delete_tmp_file($file);
                        return $this->request->redirect('admin/survey');
                    }
                    // The study is named by the JSON's "name", not by the file
                    // name validateUploadedFile() checked, so apply the one
                    // name rule here too — createFromFile() would otherwise
                    // strip "foo-v2" to "foo" or store a NULL name for "My survey".
                    if (!SurveyStudy::isValidName($data->name)) {
                        alert('<strong>Error:</strong> The survey name in the JSON ("name": "' . h(is_scalar($data->name) ? (string) $data->name : '') . '") can only contain the characters from <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore. It has to be 3 to 65 characters long and start with a letter.', 'alert-danger');
                        delete_tmp_file($file);
                        return $this->request->redirect('admin/survey');
                    }
                    // createFromData reuses (and re-uploads items into) a
                    // same-named study of the current user — the run-import
                    // semantics. This form only creates, so refuse instead of
                    // silently rewriting an existing survey's item table.
                    $existing = SurveyStudy::loadByUserAndName($this->user, $data->name);
                    if ($existing->valid) {
                        alert('<strong>Error:</strong> You already have a survey named "' . h($data->name) . '". Rename it in the JSON ("name"), or upload the items into the existing survey from its page.', 'alert-danger');
                        delete_tmp_file($file);
                        return $this->request->redirect('admin/survey');
                    }
                    if ($engine !== null) {
                        if (!isset($data->settings) || !is_object($data->settings)) {
                            $data->settings = new stdClass();
                        }
                        if (($data->settings->rendering_mode ?? null) === 'v2' && $engine === 'v1') {
                            alert('The file is set to v2, but the survey was created on v1'
                                . (Config::get('form_v2_enabled') ? ', as you chose.' : ', because v2 is not available on this formr server.'), 'alert-warning');
                        }
                        $data->settings->rendering_mode = $engine;
                    }
                    if (SurveyStudy::createFromData($data, array('user_id' => $this->user->id))) {
                        alert('<strong>Success!</strong> New survey created from JSON!', 'alert-success');
                        $created = SurveyStudy::loadByUserAndName($this->user, $data->name);
                        $redirect = ($created && $created->valid)
                            ? admin_study_url($created->name, 'show_item_table')
                            : 'admin/survey';
                    } else {
                        alert('<strong>Bugger!</strong> The survey JSON could not be imported. See the messages above.', 'alert-danger');
                        $redirect = 'admin/survey';
                    }
                    delete_tmp_file($file);
                    return $this->request->redirect($redirect);
                }

                $study = new SurveyStudy(null);
                if ($study->createFromFile($file)) {
                    // upload items
                    if ($study->uploadItems($file, true, true, $engine !== null ? array('rendering_mode' => $engine) : array())) {
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

        // Define the list of allowed extensions. A .json survey export is
        // accepted only when adding a NEW survey (createFromData); the edit /
        // re-upload-items path runs the spreadsheet reader, which can't parse it.
        $allowedExtensions = array('xls', 'xlsx', 'ods', 'xml', 'txt', 'csv');
        if (!$editing) {
            $allowedExtensions[] = 'json';
        }
        // Get the file extension
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        // Check if the extension is in the allowed list
        if(!in_array($fileExtension, $allowedExtensions)){
            $formats = $editing ? '.xls, .xlsx, .ods, .xml, .txt, or .csv' : '.xls, .xlsx, .ods, .xml, .txt, .csv, or .json';
            alert("<strong>Error:</strong> The format must be one of {$formats}.", 'alert-danger');
            return false;
        }
        
        $name = preg_filter("/^([a-zA-Z][a-zA-Z0-9_]{2,64})(-[a-z0-9A-Z]+)?\.[a-z]{3,4}$/", "$1", basename($file['name']));
        if (!SurveyStudy::isValidName((string) $name)) {
            alert("<strong>Error:</strong> The study name (the name of the file or Google sheet you uploaded, \"".(string)$file['name']."\") can only contain the characters from <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore.
                    The name has to be at least 3, at most 65 characters long. It needs to start with a letter. No dots, no spaces, no dashes, no umlauts please. 
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
                $file = google_download_survey_sheet($this->request->google_sheet);
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
                    alert('<strong>Error:</strong> The uploaded file name <code>' . htmlspecialchars($file['name']) . '</code> did not match the study name <code>' . $study->name . '</code>.', 'alert-danger');
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
        // The survey test render lives OUTSIDE /admin/ (SurveyTestController),
        // so it escapes the admin CSP (which would break live showif's
        // new Function()) and is never sent the /admin/-scoped admin cookies.
        // Hand the survey identity off via a short-lived signed token in the
        // URL instead of the admin Session — the /survey-test/ path can't read
        // that session (different cookie path).
        $token = SurveyTestController::mintToken($this->study->name, $this->user->id);
        if ($token === null) {
            // A name stored under the old, unanchored rename check can't be
            // minted into the token; sending an empty token only yields a
            // bare 403 on /survey-test/ with no explanation.
            alert('This survey cannot be tested until it is renamed to a plain identifier (letters, digits and underscores, starting with a letter).', 'alert-danger');
            $this->request->redirect(admin_study_url($this->study->name, 'rename_study'));
        }
        $this->request->redirect(site_url('survey-test/' . $token));
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
            'resultItems' => $this->study->getItemsWithChoices(),
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
            'resultItems' => $this->study->getItemsWithChoices(),
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
            'session' => '',
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
            // Anchored: unanchored, any name *containing* a word passed, so a
            // rename could smuggle `|`-separated fields into the survey-test
            // token (SurveyTestController) or arbitrary characters into URLs.
            if (!SurveyStudy::isValidName($new_name) ||
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

    private function exportUploadedFilesAction() {
        $study = $this->study;
        $files = $study->getUploadedFiles()->fetchAll();
        $format = $this->request->getParam('format');

        $SPR = new SpreadsheetReader();

        $SPR->exportJSON($files, $study->name . "_uploaded_files");
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
            $this->request->redirect(admin_study_url($study->name, 'show_results'));
        }
    }
    
    private function changeSettings($settings) {
        $errors = false;

        // Allowlist before any further processing. The previous safety net
        // was an array_walk that int-cast every value except google_file_id
        // — which "worked" because most columns happen to be integer-typed,
        // but it did not protect numeric identity columns like user_id.
        // An attacker submitting &user_id=999 to admin/survey/<name> would
        // have user_id=999 land in $this->study->update(...), which calls
        // assignProperties + save and would change the survey's owner.
        $allowed = [
            'maximum_number_displayed', 'displayed_percentage_maximum',
            'add_percentage_points', 'expire_after',
            'expire_invitation_after', 'expire_invitation_grace',
            'enable_instant_validation', 'hide_results', 'use_paging',
            'unlinked', 'google_file_id',
            // form_v2 per-study flags. Without these in the allowlist the
            // array_intersect_key below would strip them before the v2
            // handling block runs and the saves would silently rewrite
            // the columns to their false/null defaults.
            'offline_mode', 'allow_previous', 'layout', 'language',
            'next_button_label', 'submit_button_label',
        ];
        $settings = array_intersect_key($settings, array_flip($allowed));
        // String-typed columns must opt out of the int cast — google_file_id
        // is a Drive ID; layout is an ENUM('default','solo'); language is a
        // BCP 47 tag.
        $stringCols = ['google_file_id', 'layout', 'language', 'next_button_label', 'submit_button_label'];
        array_walk($settings, function (&$value, $key) use ($stringCols) {
            if (!in_array($key, $stringCols, true)) {
                $value = (int) $value;
            }
        });
        // google_file_id is the one non-integer setting, so it must be
        // validated explicitly — otherwise arbitrary text (quotes, angle
        // brackets) is stored and echoed into admin views. Empty clears it.
        if (isset($settings['google_file_id']) && $settings['google_file_id'] !== ''
                && !google_is_valid_sheet_id($settings['google_file_id'])) {
            alert('The Google Sheet ID is not valid.', 'alert-warning');
            $errors = true;
        }
        // Range checks: the isset() must cover both bounds, otherwise a POST
        // that omits a key reads an undefined index on the `||` side.
        if (isset($settings['maximum_number_displayed']) && ($settings['maximum_number_displayed'] > 3000 || $settings['maximum_number_displayed'] < 0)) {
            alert("Maximum number displayed has to be between 1 and 3000", 'alert-warning');
            $errors = true;
        }

        if (isset($settings['displayed_percentage_maximum']) && ($settings['displayed_percentage_maximum'] > 100 || $settings['displayed_percentage_maximum'] < 1)) {
            alert("Percentage maximum has to be between 1 and 100.", 'alert-warning');
            $errors = true;
        }

        if (isset($settings['add_percentage_points']) && ($settings['add_percentage_points'] > 100 || $settings['add_percentage_points'] < 0)) {
            alert("Percentage points added has to be between 0 and 100.", 'alert-warning');
            $errors = true;
        }

        $settings['enable_instant_validation'] = (int) (isset($settings['enable_instant_validation']) && $settings['enable_instant_validation'] == 1);
        $settings['hide_results'] = (int) (isset($settings['hide_results']) && $settings['hide_results'] === 1);
        $settings['use_paging'] = (int) (isset($settings['use_paging']) && $settings['use_paging'] === 1);
        $settings['unlinked'] = (int) (isset($settings['unlinked']) && $settings['unlinked'] === 1);
        // form_v2 per-study flags — accept only when the study is on the v2
        // pipeline, so checkboxes on a v1 study can't silently mutate data the
        // v1 renderer doesn't read. offline_mode defaults to 1 (opt-out),
        // allow_previous defaults to 0 (opt-in).
        if ($this->study->rendering_mode === 'v2') {
            $settings['offline_mode'] = (int) (isset($settings['offline_mode']) && $settings['offline_mode'] === 1);
            $settings['allow_previous'] = (int) (isset($settings['allow_previous']) && $settings['allow_previous'] === 1);
            $layoutInput = $settings['layout'] ?? 'default';
            $settings['layout'] = in_array($layoutInput, ['default', 'solo'], true) ? $layoutInput : 'default';
            // BCP 47-ish shape gate (patch 114); anything odd falls back to
            // English rather than erroring — the column only picks a string
            // table + lang/dir attributes.
            $langInput = trim((string) ($settings['language'] ?? 'en'));
            $settings['language'] = SurveyStudy::isLanguageTag($langInput) ? $langInput : 'en';
            // Custom navigation wording (#322); empty = built-in.
            foreach (['next_button_label', 'submit_button_label'] as $key) {
                $settings[$key] = SurveyStudy::normalizeButtonLabel($settings[$key] ?? null);
            }
        } else {
            unset($settings['offline_mode'], $settings['allow_previous'], $settings['layout'], $settings['language'],
                $settings['next_button_label'], $settings['submit_button_label']);
        }

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

    /**
     * Switch a study between the v1 and v2 rendering engines. Every
     * placement shares the study's survey_units row, whose type follows the
     * engine (SurveyStudy::save / adoptFormV2), so the switch moves every
     * placement at once: v1 → v2 makes them Form units, v2 → v1 Survey units.
     * Participants mid-form continue on the other engine from where they
     * are; both read and write the same survey_items_display rows. While
     * form_v2 is an alpha, v1 → v2 is refused as long as any run uses the
     * study as a Survey (SurveyStudy::runsUsingAsSurvey).
     */
    private function switchRenderingModeAction() {
        if (!Request::isHTTPPostRequest()) {
            formr_error(405, 'Method Not Allowed');
        }
        $target = isset($_POST['target']) ? (string) $_POST['target'] : '';
        if (!in_array($target, ['v1', 'v2'], true)) {
            formr_error(400, 'Bad Request', 'target must be v1 or v2');
        }

        $toV2 = $target === 'v2' && $this->study->rendering_mode !== 'v2';
        // Upgrading switches the engine of every run that uses the study as a
        // Survey — live runs included. The alpha refuses it while any does:
        // copy the survey (download it, upload it as a new one) to try v2.
        if ($toV2 && !Config::get('form_v2_enabled')) {
            alert('<strong>Not switched.</strong> v2 is not available on this formr server.', 'alert-danger');
            $this->request->redirect(admin_study_url($this->study->name));
            return;
        }
        if ($toV2 && ($usedBy = $this->study->runsUsingAsSurvey())) {
            alert('<strong>Not switched.</strong> This survey runs as a classic Survey in: <strong>' . implode(', ', array_map('h', $usedBy))
                . '</strong>. Switching it would change the engine for those runs\' participants. To try form_v2, copy the survey — download it and upload it as a new survey — and use the copy in a Form unit.', 'alert-danger');
            $this->request->redirect(admin_study_url($this->study->name));
            return;
        }
        $unsupported = $toV2 ? $this->study->formV2UnsupportedItems() : array();
        // The way back has its own rule: items only v2 can run (bot_check,
        // rank: Item::v1Unsupported) are refused at upload into a v1 study,
        // and a switch must not bring them there either — a required
        // bot_check could never be answered on v1.
        $toV1 = $target === 'v1' && $this->study->rendering_mode === 'v2';
        $v1Unsupported = $toV1 ? $this->study->v1UnsupportedItems() : array();
        // A showif the browser cannot evaluate (leftover R, a name that is not
        // on the form) keeps its item hidden for good, silently — the one
        // place v1 studies break on the way to v2. So the scan's showif
        // findings stop the switch too, unless the author confirms them
        // (`force`: the scan is a heuristic and can be wrong).
        // A choice list written in one cell with commas stops it the same way:
        // v2 splits such a list at semicolons, so it would become one choice
        // — unless it is one (`Berlin, Germany`), which the author confirms.
        $showifFindings = $listFindings = array();
        if (!$unsupported && $toV2 && empty($_POST['force'])) {
            foreach (FormV2CompatScanner::scan((int) $this->study->id)['flagged'] as $f) {
                if ($f['column'] === 'showif') {
                    $showifFindings[] = "'{$f['name']}': " . implode('; ', $f['problems']);
                } elseif ($f['suggested_fix'] === 'pack_with_semicolons') {
                    $listFindings[] = "'{$f['name']}': " . implode('; ', $f['problems']);
                }
            }
        }
        if ($unsupported) {
            alert('<strong>Not switched.</strong> This survey has items that v2 cannot run:<ul><li>'
                . implode('</li><li>', array_map('h', $unsupported)) . '</li></ul>', 'alert-danger');
        } elseif ($showifFindings) {
            alert('<strong>Not switched.</strong> These showifs do not work in v2, so their items would stay hidden. The <a href="' . admin_study_url($this->study->name, 'form_v2_compat_scan') . '">compatibility check</a> shows how to fix them, and lets you move the survey anyway if the check is wrong.<ul><li>'
                . implode('</li><li>', array_map('h', $showifFindings)) . '</li></ul>', 'alert-danger');
        } elseif ($listFindings) {
            alert('<strong>Not switched.</strong> These choice lists are written in one cell with commas. v2 separates such a list with semicolons, so each would become a single choice. The <a href="' . admin_study_url($this->study->name, 'form_v2_compat_scan') . '">compatibility check</a> shows how to fix them, and lets you move the survey anyway if a cell is meant as one choice.<ul><li>'
                . implode('</li><li>', array_map('h', $listFindings)) . '</li></ul>', 'alert-danger');
        } elseif ($toV2) {
            $this->study->adoptFormV2();
            alert('The survey now runs on v2. Test it before participants start.', 'alert-success');
        } elseif ($v1Unsupported) {
            alert('<strong>Not switched.</strong> This survey has items that only v2 can run:<ul><li>'
                . implode('</li><li>', array_map('h', $v1Unsupported)) . '</li></ul>', 'alert-danger');
        } elseif ($toV1) {
            $this->study->update(['rendering_mode' => 'v1']);
            alert('Study switched back to the v1 engine; its Form units are Survey units again.', 'alert-success');
        } else {
            alert('Study is already on the requested engine.', 'alert-info');
        }
        $this->request->redirect(admin_study_url($this->study->name));
    }

    /**
     * form_v2 compatibility scan: classify each showif/value expression on the
     * current study (FormV2CompatScanner's buckets: showifs that work as JS,
     * need a rewrite or read names not on the form; values; items v2 cannot
     * run). Rendered on the survey settings page (survey/index) via the
     * "Run v2 compatibility scan" button — reachable for BOTH engines so authors can check
     * readiness BEFORE upgrading. Reuses FormV2CompatScanner so the admin
     * view and the CLI (bin/form_v2_compat_scan.php) agree.
     */
    private function formV2CompatScanAction() {
        if (!$this->study || !$this->study->valid) {
            formr_error(404, 'Not Found', 'Study not found');
        }
        $report = FormV2CompatScanner::scan($this->study->id);
        $this->setView('survey/form_v2_compat_scan', [
            'study' => $this->study,
            'report' => $report,
        ]);
        return $this->sendResponse();
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
