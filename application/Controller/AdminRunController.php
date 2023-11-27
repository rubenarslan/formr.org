<?php

class AdminRunController extends AdminController {

    public function __construct(Site &$site) {
        parent::__construct($site);
    }

    public function indexAction($run_name = '', $private_action = '') {
        $this->setRun($run_name);
        if ($private_action) {
            if (empty($this->run) || !$this->run->valid) {
                throw new Exception("You cannot access this page with no valid run");
            }

            if (strpos($private_action, 'ajax') !== false) {
                $this->response = AdminAjaxController::call($private_action, $this);
                return $this->sendResponse();
            }

            $privateAction = $this->getPrivateAction($private_action);
            return $this->$privateAction();
        }

        if (empty($this->run)) {
            $this->request->redirect('admin/run/add_run');
        }

        $vars = array(
            'show_panic' => $this->showPanicButton(),
            'add_unit_buttons' => $this->getUnitAddButtons(),
        );

        $this->setView('run/index', $vars);
        return $this->sendResponse();
    }

    public function listAction() {
        $vars = array(
            'runs' => $this->user->getRuns('id DESC', null),
        );

        $this->setView('run/list', $vars);
        return $this->sendResponse();
    }

    public function addRunAction() {
        if ($this->request->isHTTPPostRequest()) {
            $run_name = $this->request->str('run_name');
            if (!$run_name) {
                $error = 'You have to specify a run name';
            } elseif (!preg_match("/^[a-zA-Z][a-zA-Z0-9-]{2,255}$/", $run_name)) {
                $error = 'The run name can contain <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the hyphen(-) (at least 2 characters, at most 255). It needs to start with a letter.';
            } elseif ($run_name == Run::TEST_RUN || Router::isWebRootDir($run_name) || in_array($run_name, Config::get('reserved_run_names', array())) || Run::nameExists($run_name)) {
                $error = __('The run name "%s" is already taken. Please choose another name', $run_name);
            } else {
                $run = new Run();
                $run->create([
                    'run_name' => $run_name,
                    'user_id' => $this->user->id
                ]);
                if ($run->valid) {
                    alert("<strong>Success.</strong> Run '{$run->name}' was created.", 'alert-success');
                    $this->request->redirect(admin_run_url($run->name));
                } else {
                    $error = 'An error creating your run please try again';
                }
            }

            if (!empty($error)) {
                alert("<strong>Error:</strong> {$error}", 'alert-danger');
            }
        }

        $this->setView('run/add_run');
        return $this->sendResponse();
    }

    private function userOverviewAction() {
        $run = $this->run;
        $fdb = $this->fdb;
        $querystring = array();
        $queryparams = array('run_id' => $run->id, 'position_operator' => '=');

        if ($this->request->position_lt && in_array($this->request->position_lt, array('=', '>', '<'))) {
            $queryparams['position_operator'] = $this->request->position_lt;
            $querystring['position_lt'] = $queryparams['position_operator'];
        }

        if ($this->request->session) {
            $session = str_replace("…", "", $this->request->session);
            $queryparams['session'] = "%" . $session . "%";
            $querystring['session'] = $session;
        }

        if ($this->request->position) {
            $queryparams['position'] = $this->request->position;
            $querystring['position'] = $queryparams['position'];
        }

        if ($this->request->sessions) {
            $sessions = array();
            foreach (explode("\n", $this->request->sessions) as $session) {
                $session = $this->fdb->quote($session);
                if ($session) {
                    $sessions[] = $session;
                }
            }
            $queryparams['sessions'] = $sessions;
            $querystring['sessions'] = $this->request->sessions;
        }

        $queryparams['admin_code'] = $this->user->user_code;
        $helper = new RunHelper($run, $fdb, $this->request);
        $table = $helper->getUserOverviewTable($queryparams);

        $this->setView('run/user_overview', array(
            'users' => $table['data'],
            'pagination' => $table['pagination'],
            'position_lt' => $queryparams['position_operator'],
            'currentUser' => $this->user,
            'unit_types' => $run->getAllUnitTypes(),
            'reminders' => $this->run->getSpecialUnits(false, 'ReminderEmail'),
            'querystring' => $querystring,
        ));
        
        return $this->sendResponse();
    }

    private function exportUserOverviewAction() {
        $helper = new RunHelper($this->run, $this->fdb, $this->request);
        $queryParams = array('run_id' => $this->run->id, 'admin_code' => $this->user->user_code);
        $exportStmt = $helper->getUserOverviewExportPdoStatement($queryParams);
        $SPR = new SpreadsheetReader();
        $download_successfull = $SPR->exportInRequestedFormat($exportStmt, $this->run->name . '_user_overview', $this->request->str('format'));
        if (!$download_successfull) {
            alert('An error occured during user overview download.', 'alert-danger');
            $this->request->redirect(admin_run_url($this->run->name, 'user_overview'));
        }
    }

    private function userDetailAction() {
        $run = $this->run;
        $fdb = $this->fdb;
        $querystring = array();
        $queryparams = array('run_id' => $run->id, 'position_operator' => '=');

        if ($this->request->position_lt && in_array($this->request->position_lt, array('=', '>', '<'))) {
            $queryparams['position_operator'] = $this->request->position_lt;
            $querystring['position_lt'] = $queryparams['position_operator'];
        }

        if ($this->request->session) {
            $session = str_replace("…", "", $this->request->session);
            $queryparams['session'] = "%" . $session . "%";
            $querystring['session'] = $session;
        }

        if ($this->request->position) {
            $queryparams['position'] = $this->request->position;
            $querystring['position'] = $queryparams['position'];
        }

        $helper = new RunHelper($run, $fdb, $this->request);
        $table = $helper->getUserDetailTable($queryparams);
        $users = $table['data'];

        foreach ($users as $i => $userx) {
            if ($userx['expired']) {
                $stay_seconds = strtotime($userx['expired']) - strtotime($userx['created']);
            } else {
                $stay_seconds = ($userx['ended'] ? strtotime($userx['ended']) : time() ) - strtotime($userx['created']);
            }
            $userx['stay_seconds'] = $stay_seconds;
            if ($userx['expired']) {
                $userx['ended'] = $userx['expired'] . ' (expired)';
            }

            if ($userx['unit_type'] != 'Survey') {
                $userx['delete_msg'] = "Are you sure you want to delete this unit session?";
                $userx['delete_title'] = "Delete this waypoint";
            } else {
                $userx['delete_msg'] = "You SHOULDN'T delete survey sessions, you might delete data! <br />Are you REALLY sure you want to continue?";
                $userx['delete_title'] = "Survey unit sessions should not be deleted";
            }

            $users[$i] = $userx;
        }

        $this->setView('run/user_detail', array(
            'users' => $users,
            'pagination' => $table['pagination'],
            'position_lt' => $queryparams['position_operator'],
            'querystring' => $querystring,
        ));

        return $this->sendResponse();
    }

    private function exportUserDetailAction() {
        $helper = new RunHelper($this->run, $this->fdb, $this->request);
        $queryParams = array(':run_id' => $this->run->id, ':run_id2' => $this->run->id);
        $exportStmt = $helper->getUserDetailExportPdoStatement($queryParams);
        $SPR = new SpreadsheetReader();
        $download_successfull = $SPR->exportInRequestedFormat($exportStmt, $this->run->name . '_user_detail', $this->request->str('format'));
        if (!$download_successfull) {
            alert('An error occured during user detail download.', 'alert-danger');
            $this->request->redirect(admin_run_url($this->run->name, 'user_detail'));
        }
    }

    private function createNewTestCodeAction() {
        $run_session = RunSession::getTestSession($this->run);
        $sess = $run_session->session;
        $sess_url = run_url($this->run->name, null, array('code' => $sess));

        $this->request->redirect($sess_url);
    }

    private function createNewNamedSessionAction() {
        if (Request::isHTTPPostRequest()) {
            $code_name = $this->request->getParam('code_name');
            $run_session = RunSession::getNamedSession($this->run, $code_name);

            if ($run_session) {
                $sess = $run_session->session;
                $sess_url = run_url($this->run->name, null, array('code' => $sess));

                //alert("You've added a user with the code name '{$code_name}'. <br /> Send them this link to participate <br /> <textarea readonly cols='60' rows='3' class='copy_clipboard readonly-textarea'>" . h($sess_url) . "</textarea>", "alert-info");
                $this->request->redirect(admin_run_url($this->run->name, 'user_overview', array('session' => $sess)));
            }
        }

        $this->setView('run/create_new_named_session');
        return $this->sendResponse();
    }

    private function uploadFilesAction() {
        $run = $this->run;

        if (!empty($_FILES['uploaded_files'])) {
            if ($run->uploadFiles($_FILES['uploaded_files'])) {
                alert('<strong>Success.</strong> The files were uploaded.', 'alert-success');
                if (!empty($run->messages)) {
                    alert(implode(' ', $run->messages), 'alert-info');
                }
                $this->request->redirect(admin_run_url($run->name, 'upload_files'));
            } else {
                alert('<strong>Sorry, files could not be uploaded.</strong><br /> ' . nl2br(implode("\n", $run->errors)), 'alert-danger');
            }
        } elseif ($this->request->isHTTPPostRequest()) {
            alert('The size of your request exceeds the allowed limit. Please report this to administrators indicating the size of your files.', 'alert-danger');
        }

        $this->setView('run/upload_files', array('files' => $run->getUploadedFiles()));
        return $this->sendResponse();
    }

    private function deleteFileAction() {
        $id = $this->request->int('id');
        $filename = $this->request->str('file');
        $deleted = $this->run->deleteFile($id, $filename);
        
        if ($deleted) {
            alert('File Deleted', 'alert-success');
        } else {
            alert('Unable to delete selected file', 'alert-danger');
        }
        
        $this->request->redirect(admin_run_url($this->run->name, 'upload_files'));
    }

    private function settingsAction() {
        $osf_projects = array();

        if (($token = OSF::getUserAccessToken($this->user))) {
            $osf = new OSF(Config::get('osf'));
            $osf->setAccessToken($token);
            $response = $osf->getProjects();

            if ($response->hasError()) {
                alert($response->getError(), 'alert-danger');
                $token = null;
            } else {
                foreach ($response->getJSON()->data as $project) {
                    $osf_projects[] = array('id' => $project->id, 'name' => $project->attributes->title);
                }
            }
        }

        $this->setView('run/settings', array(
            'osf_token' => $token,
            'run_selected' => $this->request->getParam('run'),
            'osf_projects' => $osf_projects,
            'osf_project' => $this->run->osf_project_id,
            'run_id' => $this->run->id,
            'reminders' => $this->run->getSpecialUnits(true, 'ReminderEmail'),
            'service_messages' => $this->run->getSpecialUnits(true, 'ServiceMessagePage'),
            'overview_scripts' => $this->run->getSpecialUnits(true, 'OverviewScriptPage'),
        ));
        
        return $this->sendResponse();
    }

    private function renameRunAction() {
        $run = $this->run;
        if ($this->request->isHTTPPostRequest()) {
            $run_name = $this->request->str('new_name');
            if (!$run_name) {
                $error = 'You have to specify a new run name';
            } elseif (!preg_match("/^[a-zA-Z][a-zA-Z0-9-]{2,255}$/", $run_name)) {
                $error = 'The run name can contain <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the hyphen(-) (at least 2 characters, at most 255). It needs to start with a letter.';
            } elseif ($run_name == Run::TEST_RUN || Router::isWebRootDir($run_name) || in_array($run_name, Config::get('reserved_run_names', array())) || Run::nameExists($run_name)) {
                $error = __('The run name "%s" is already taken. Please choose another name', $run_name);
            } else {
                if ($run->rename($run_name)) {
                    alert("<strong>Success.</strong> Run was renamed to '{$run_name}'.", 'alert-success');
                    $this->request->redirect(admin_run_url($run_name));
                } else {
                    $error = 'An error renaming your run please try again';
                }
            }

            if (!empty($error)) {
                alert("<strong>Error:</strong> {$error}", 'alert-danger');
            }
        }

        $this->setView('run/rename_run');
        return $this->sendResponse();
    }

    private function exportDataAction() {
        $run = $this->run;
        $format = $this->request->str('format');
        $SPR = new SpreadsheetReader();
        SpreadsheetReader::verifyExportFormat($format);

        /* @var $resultsStmt PDOStatement */
        $resultsStmt = $run->getData(true);
        if (!$resultsStmt->columnCount()) {
            alert('No linked data yet', 'alert-info');
            $this->request->redirect(admin_run_url($run->name));
        }

        $filename = $run->name . '_data';
        switch ($format) {
            case 'xlsx':
                $downloaded = $SPR->exportXLSX($resultsStmt, $filename);
                break;
            case 'xls':
                $downloaded = $SPR->exportXLS($resultsStmt, $filename);
                break;
            case 'csv_german':
                $downloaded = $SPR->exportCSV_german($resultsStmt, $filename);
                break;
            case 'tsv':
                $downloaded = $SPR->exportTSV($resultsStmt, $filename);
                break;
            case 'json':
                $downloaded = $SPR->exportJSON($resultsStmt, $filename);
                break;
            default:
                $downloaded = $SPR->exportCSV($resultsStmt, $filename);
                break;
        }

        if (!$downloaded) {
            alert('An error occured during results download.', 'alert-danger');
            $this->request->redirect(admin_run_url($run->name));
        }
    }

    private function exportSurveyResultsAction() {
        $studies = $this->run->getAllSurveys();
        $dir = APPLICATION_ROOT . 'tmp/backups/results';
        if (!$dir) {
            alert('Unable to create run backup directory', 'alert-danger');
            $this->request->redirect(admin_run_url($this->run->name));
        }

        // create study result files
        $SPR = new SpreadsheetReader();
        $errors = $files = $metadata = array();
        $metadata['run'] = array(
            'ID' => $this->run->id,
            'NAME' => $this->run->name,
        );

        foreach ($studies as $study) {
            $survey = SurveyStudy::loadById($study['id']);
            $backupFile = $dir . '/' . $this->run->name . '-' . $survey->name . '.tab';
            $backup = $SPR->exportTSV($survey->getResults(null, null, null, $this->run->id, true), $survey->name, $backupFile);
            if (!$backup) {
                $errors[] = "Unable to backup {$survey->name}";
            } else {
                $files[] = $backupFile;
            }
            $metadata['survey:' . $survey->id] = array(
                'ID' => $survey->id,
                'NAME' => $survey->name,
                'RUN_ID' => $this->run->id
            );
        }

        $metafile = $dir . '/' . $this->run->name . '.metadata';
        if (create_ini_file($metadata, $metafile)) {
            $files[] = $metafile;
        }

        // zip files and send to 
        if ($files) {
            $zipfile = $dir . '/' . $this->run->name . '-' . date('d-m-Y') . '.zip';

            //create the archive
            if (!create_zip_archive($files, $zipfile)) {
                alert('Unable to create zip archive: ' . basename($zipfile), 'alert-danger');
                $this->request->redirect(admin_run_url($this->run->name));
            }

            $filename = basename($zipfile);
            header("Content-Type: application/zip");
            header("Content-Disposition: attachment; filename=$filename");
            header("Content-Length: " . filesize($zipfile));
            readfile($zipfile);
            // attempt to cleanup files after download
            $files[] = $zipfile;
            deletefiles($files);
            exit;
        } else {
            alert('No files to zip and download', 'alert-danger');
        }
        $this->request->redirect(admin_run_url($this->run->name));
    }

    private function randomGroupsExportAction() {
        $run = $this->run;
        $format = $this->request->str('format');
        $SPR = new SpreadsheetReader();
        SpreadsheetReader::verifyExportFormat($format);

        /* @var $resultsStmt PDOStatement */
        $resultsStmt = $run->getRandomGroups(); //@TODO unset run_name, unit_type, ended, position
        if (!$resultsStmt->columnCount()) {
            alert('No linked data yet', 'alert-info');
            $this->request->redirect(admin_run_url($run->name));
        }

        $filename = "Shuffle_Run_" . $run->name;
        switch ($format) {
            case 'xlsx':
                $downloaded = $SPR->exportXLSX($resultsStmt, $filename);
                break;
            case 'xls':
                $downloaded = $SPR->exportXLS($resultsStmt, $filename);
                break;
            case 'csv_german':
                $downloaded = $SPR->exportCSV_german($resultsStmt, $filename);
                break;
            case 'tsv':
                $downloaded = $SPR->exportTSV($resultsStmt, $filename);
                break;
            case 'json':
                $downloaded = $SPR->exportJSON($resultsStmt, $filename);
                break;
            default:
                $downloaded = $SPR->exportCSV($resultsStmt, $filename);
                break;
        }

        if (!$downloaded) {
            alert('An error occured during results download.', 'alert-danger');
            $this->request->redirect(admin_run_url($run->name));
        }
    }

    private function randomGroupsAction() {
        $run = $this->run;
        $pdoStatement = $run->getRandomGroups();

        $this->setView('run/random_groups', array('users' => $pdoStatement->fetchAll(PDO::FETCH_ASSOC)));
        return $this->sendResponse();
    }

    private function overviewAction() {
        $this->setView('run/overview', array(
            'users' => $this->run->getNumberOfSessionsInRun(),
            'overview_script' => $this->run->getOverviewScript(),
            'user_overview' => $this->run->getUserCounts(),
        ));
 
        return $this->sendResponse();
    }

    private function emptyRunAction() {
        $run = $this->run;
        if ($this->request->isHTTPPostRequest()) {
            if ($this->request->getParam('empty_confirm') === $run->name) {
                $run->emptySelf();
                $this->request->redirect(admin_run_url($run->name, "empty_run"));
            } else {
                alert("<b>Error:</b> You must type the run's name '{$run->name}' to empty it.", 'alert-danger');
            }
        }

        $this->setView('run/empty_run', array( 'users' => $run->getNumberOfSessionsInRun()));
        return $this->sendResponse();
        
    }

    private function emailLogAction() {
        $queryparams = array('run_id' => $this->run->id);
        $helper = new RunHelper($this->run, $this->fdb, $this->request);
        $table = $helper->getEmailLogTable($queryparams);

        $this->setView('run/email_log', array(
            'emails' => $table['data'],
            'pagination' => $table['pagination'],
        ));
        
        return $this->sendResponse();
    }

    private function deleteRunAction() {
        $run = $this->run;
        if (Request::isHTTPPostRequest() && $this->request->getParam('delete') && $this->request->getParam('delete_confirm') === $run->name) {
            if($run->delete()) {
                $this->request->redirect(admin_url());
            }
        } elseif (Request::isHTTPPostRequest() && $this->request->getParam('delete')) {
            alert("<b>Error:</b> You must type the run's name '{$run->name}' to delete it.", 'alert-danger');
        }

        $this->setView('run/delete_run', array(
            'users' => $run->getNumberOfSessionsInRun(),
        ));
        return $this->sendResponse();
    }

    private function cronLogAction() {
        $parser = new LogParser();
        $parse = $this->run->name . '.log';
        $vars = get_defined_vars();

        $this->setView('run/cron_log_parsed', $vars);
        return $this->sendResponse();
    }
    
    private function sessionsQueueAction() {
        $this->setView('run/sessions_queue', array(
            'stmt' => UnitSessionQueue::getRunItems($this->run),
            'run_name' => $this->run->name
        ));
        return $this->sendResponse();
    }

    private function setRun($name) {
        if (!$name) {
            return;
        }

        $run = new Run($name);
        if (!$run->valid) {
            formr_error(404, 'Not Found', 'Requested Run does not exist or has been moved');
        } elseif (!$this->user->created($run)) {
            formr_error(401, 'Unauthorized', 'You do not have access to modify this run');
        }
        $this->run = $run;
    }

    private function exportAction() {
        $formats = array('json');
        $run = $this->run;
        $site = $this->site;

        if (($units = (array) json_decode($_POST['units'])) && ($name = $site->request->str('export_name')) && preg_match('/^[a-z0-9-\s]+$/i', $name)) {
            $format = $this->request->getParam('format');
            $inc_survey = $this->request->getParam('include_survey_details') === 'true';
            if (!in_array($format, $formats)) {
                alert('Invalid Export format selected', 'alert-danger');
                $this->request->redirect(admin_run_url($run->name));
            }

            if (!($export = $run->export($name, $units, $inc_survey))) {
                $this->response->setStatusCode(500, 'Bad Request');
                return $this->sendResponse($site->renderAlerts());
            } else {
                $SPR = new SpreadsheetReader();
                $SPR->exportJSON($export, $name);
            }
        } else {
            alert('Run Export: Missing run units or invalid run name enterd.', 'alert-danger');
            $this->request->redirect(admin_run_url($run->name));
        }
    }

    private function importAction() {
        if ($run_file = $this->request->getParam('run_file_name')) {
            $file = Config::get('run_exports_dir') . '/' . $run_file;
        } elseif (!empty($_FILES['run_file'])) {
            $file = $_FILES['run_file']['tmp_name'];
        }

        if (empty($file)) {
            alert('Please select a run file or upload one', 'alert-danger');
            return $this->request->redirect(admin_run_url($this->run->name));
        }

        if (!file_exists($file)) {
            alert('The corresponding import file could not be found or is not readable', 'alert-danger');
            return $this->request->redirect(admin_run_url($this->run->name));
        }

        $json_string = file_get_contents($file);
        if (!$json_string) {
            alert('Unable to extract JSON object from file', 'alert-danger');
            return $this->request->redirect(admin_run_url($this->run->name));
        }

        $start_position = 10;
        if ($this->run->importUnits($json_string, $start_position)) {
            alert('Run modules imported successfully!', 'alert-success');
        }

        $this->request->redirect(admin_run_url($this->run->name));
    }

    private function createRunUnitAction() {
        $redirect = $this->request->redirect ? admin_run_url($this->run->name, $this->request->redirect) : admin_run_url($this->run->name);
        $unit = $this->createRunUnit();
        if ($unit->valid) {
            alert('Run unit created', 'alert-success');
        } else {
            alert('An unexpected error occured. Unit could not be created', 'alert-danger');
        }
        $this->request->redirect(str_replace(':::', '#', $redirect));
    }

    private function deleteRunUnitAction() {
        $id = (int) $this->request->unit_id;
        if (!$id) {
            throw new Exception('Missing Parameter');
        }
        $redirect = $this->request->redirect ? admin_run_url($this->run->name, $this->request->redirect) : admin_run_url($this->run->name);
        $unit = $this->createRunUnit($id);
        if ($unit->valid) {
            $unit->run_unit_id = $id;
            $unit->removeFromRun($this->request->special);
            alert('Run unit deleted', 'alert-success');
        } else {
            alert('An unexpected error occured. Unit could not be deleted', 'alert-danger');
        }
        $this->request->redirect(str_replace(':::', '#', $redirect));
    }

    private function panicAction() {
        $settings = array(
            'locked' => 1,
            'cron_active' => 0,
            'public' => 0,
            //@todo maybe do more
        );
        $updated = $this->fdb->update('survey_runs', $settings, array('id' => $this->run->id));
        if ($updated) {
            $msg = array("Panic mode activated for '{$this->run->name}'");
            $msg[] = " - Only you can access this run";
            $msg[] = " - The cron job for this run has been deactivated";
            $msg[] = " - The run has been 'locked' for editing";
            alert(implode("\n", $msg), 'alert-success');
        }
        $this->request->redirect("admin/run/{$this->run->name}");
    }

    private function showPanicButton() {
        $on = $this->run->locked === 1 &&
                $this->run->cron_active === 0 &&
                $this->run->public === 0;
        return !$on;
    }

    private function getUnitAddButtons() {
        return array(
            'Survey' => array(
                'title' => 'Add Survey',
                'icon' => 'fa-pencil-square',
            ),
            'External' => array(
                'title' => 'Add External Link',
                'icon' => 'fa-external-link-square',
            ),
            'Email' => array(
                'title' => 'Add Email',
                'icon' => 'fa-envelope',
            ),
            'SkipBackward' => array(
                'title' => 'Add a loop (Skip Backwards)',
                'icon' => 'fa-backward',
            ),
            'Pause' => array(
                'title' => 'Add a Pause',
                'icon' => 'fa-pause',
            ),
            'SkipForward' => array(
                'title' => 'Add a jump (Skip Forward)',
                'icon' => 'fa-forward',
            ),
            'Wait' => array(
                'title' => 'Add Waiting Time',
                'icon' => 'fa-hourglass-half',
            ),
            'Shuffle' => array(
                'title' => 'Add shuffle (Randomise Participants)',
                'icon' => 'fa-random',
            ),
            'Page' => array(
                'title' => 'Add a Stop Point',
                'icon' => 'fa-stop',
            ),
        );
    }

}
