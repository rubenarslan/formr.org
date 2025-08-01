<?php

/**
 * Types of run units
 * 
 * branches 
 * (these evaluate a condition and go to one position in the run, can be used for allowing access)
 * 
 * feedback 
 * (atm just markdown pages with a title and body, but will have to use these for making graphs etc at some point)
 * (END POINTS, does not automatically lead to next run unit in list, but doesn't have to be at the end because of branches)
 * 
 * pauses
 * (go on if it's the next day, a certain date etc., so many days after beginning etc.)
 * 
 * emails
 * (send reminders, invites etc.)
 * 
 * surveys 
 * (main component, upon completion give up steering back to run)
 * 
 * external 
 * (formerly forks, can redirect internally to other runs too)
 * 
 * social network (later)
 * lab date selector (later)
 */
class Run extends Model {

    public $id = null;
    public $name = null;
    public $valid = false;
    public $public = false;
    public $cron_active = true;
    public $cron_fork = false;
    public $live = false;
    public $user_id = null;
    public $being_serviced = false;
    public $locked = false;
    public $errors = array();
    public $messages = array();
    public $custom_css_path = null;
    public $custom_js_path = null;
    public $manifest_json_path = null;
    public $header_image_path = null;
    public $title = null;
    public $description = null;
    public $osf_project_id = null;
    public $footer_text = null;
    public $public_blurb = null;
    public $use_material_design = false;
    public $expire_cookie = 0;
    public $expire_cookie_value = 0;
    public $expire_cookie_unit;
    public $vapid_public_key = null;
    public $expire_cookie_units = array(
        'seconds' => 'Seconds',
        'minutes' => 'Minutes',
        'hours' => 'Hours',
        'days' => 'Days',
        'months' => 'Months',
        'years' => 'Years',
    );
    public $description_parsed = null;
    public $footer_text_parsed = null;
    protected $public_blurb_parsed = null;
    public $privacy = null;
    public $tos = null;
    protected $privacy_parsed = null;
    protected $tos_parsed = null;
    protected $api_secret_hash = null;
    protected $owner = null;
    protected $run_settings = array(
        "header_image_path", "title", "description",
        "footer_text", "public_blurb", "privacy",
        "tos", "custom_css",
        "custom_js", "manifest_json", "cron_active", "osf_project_id",
        "use_material_design", "expire_cookie",
        "expire_cookie_value", "expire_cookie_unit",
        "expiresOn",
        "vapid_public_key", "vapid_private_key"
    );
    public $renderedDescAndFooterAlready = false;
    public $expiresOn = null;
    public $pwa_icon_path = null;

    /**
     *
     * @var RunSession
     */
    public $activeRunSession;

    const TEST_RUN = 'formr-test-run';

    public function __construct($name = null, $id = null) {
        parent::__construct();
        
        if ($name == self::TEST_RUN) {
            $this->name = $name;
            $this->valid = true;
            $this->user_id = -1;
            $this->id = -1;
            return true;
        }

        if ($name !== null) {
            $this->name = $name;
            $this->load();
        }
        
        if ($id !== null) {
            $this->id = (int) $id;
            $this->load();
        }
    }

    protected function load() {
        if (in_array($this->name, Config::get('reserved_run_names', array()))) {
            return;
        }

        $columns = "id, user_id, created, modified, name, api_secret_hash, public, cron_active, cron_fork, locked, header_image_path, title, description, description_parsed, footer_text, footer_text_parsed, public_blurb, public_blurb_parsed, privacy, privacy_parsed, tos, tos_parsed, custom_css_path, custom_js_path, manifest_json_path, osf_project_id, use_material_design, expire_cookie, expiresOn, vapid_public_key, vapid_private_key, pwa_icon_path";
        $where = $this->id ? array('id' => $this->id) : array('name' => $this->name);
        $vars = $this->db->findRow('survey_runs', $where, $columns);
        
        if($vars && isset($vars['expiresOn']) && $vars['expiresOn'] !== null) {
            $vars['expiresOn'] = date('Y-m-d', strtotime($vars['expiresOn']));
        }

        if ($vars) {
            $this->assignProperties($vars);
            $this->setExpireCookieUnits();
            $this->valid = true;
            if ($this->pwa_icon_path && !empty(trim($this->pwa_icon_path)) && !str_ends_with($this->pwa_icon_path, '/')) {
                $this->pwa_icon_path .= '/';
            }
        }
    }

    public function getCronDues() {
        $sessions = $this->db->select('session')
                ->from('survey_run_sessions')
                ->where(array('run_id' => $this->id))
                ->order('RAND')
                ->statement();
        $dues = array();
        while ($run_session = $sessions->fetch(PDO::FETCH_ASSOC)) {
            $dues[] = $run_session['session'];
        }
        return $dues;
    }

    /* ADMIN functions */

    public function getApiSecret($user) {
        if ($user->isAdmin()) {
            return $this->api_secret_hash;
        }
        return false;
    }

    public function hasApiAccess($secret) {
        return $this->api_secret_hash === $secret;
    }

    public function rename($new_name) {
        $name = trim($new_name);
        $this->db->update('survey_runs', array('name' => $name), array('id' => $this->id));
        return true;
    }

    public function delete() {
        try {
            $this->deleteFiles();

            $this->db->delete('survey_runs', array('id' => $this->id));
            alert("<strong>Success.</strong> Successfully deleted run '{$this->name}'.", 'alert-success');
            return true;
        } catch (Exception $e) {
            formr_log_exception($e, __METHOD__);
            alert(__('Could not delete run %s. This is probably because there are still run units present. For safety\'s sake you\'ll first need to delete each unit individually.', $this->name), 'alert-danger');
            return false;
        }
    }

    public function deleteUnits() {
        $this->db->delete('survey_run_special_units', array('run_id' => $this->id));
        $this->db->delete('survey_run_units', array('run_id' => $this->id));
    }

    public function togglePublic($public) {
        if (!in_array($public, range(0, 3))) {
            return false;
        }

        if($public > 0) {
            $run_expiry = $this->expiresOn;
            $problem = false;
            if($run_expiry === null && Config::get('keep_study_data_for_months_maximum') !== INF) {
                alert("You cannot make this study public yet. First, you need to define when the data can be deleted in the run settings.", 'alert-warning');
                $problem = true;
            } elseif($run_expiry !== null) {
                $expiry_timestamp = strtotime($run_expiry);
                if($expiry_timestamp === false || $expiry_timestamp <= time()) {
                    alert("You cannot make this study public because it has an invalid or past expiry date. Please update the expiry date in run settings.", 'alert-warning');
                    $problem = true;
                }
            }

            // Check if the run has a privacy policy
            $require_privacy = Config::get("require_privacy_policy", false);
            if ($require_privacy AND !$this->hasPrivacy()) {
                alert('You cannot make this study public because it does not have a privacy policy. Define one first in the run settings tab.', 'alert-warning');
                $problem = true;
            }
            if($problem) {
                return false;
            }
        }

        $updated = $this->db->update('survey_runs', array('public' => $public), array('id' => $this->id));
        return $updated !== false;
    }

    public function toggleLocked($on) {
        $on = (int) $on;
        $updated = $this->db->update('survey_runs', array('locked' => $on), array('id' => $this->id));
        return $updated !== false;
    }

    public function create($options) {
        $name = trim($options['run_name']);

        // create run db entry
        $new_secret = crypto_token(66);
        $this->db->insert('survey_runs', array(
            'user_id' => $options['user_id'],
            'name' => $name,
            'title' => $name,
            'created' => mysql_now(),
            'modified' => mysql_now(),
            'api_secret_hash' => $new_secret,
            'cron_active' => 1,
            'use_material_design' => 0,
            'expire_cookie' => 0,
            'expiresOn' => null,
            'public' => 0,
            'footer_text' => "Remember to add your contact info here! Contact the [study administration](mailto:email@example.com) in case of questions.",
            'footer_text_parsed' => "Remember to add your contact info here! Contact the <a href='mailto:email@example.com'>study administration</a> in case of questions.",
        ));
        $this->id = $this->db->pdo()->lastInsertId();
        $this->name = $name;
        $this->load();

        $owner = $this->getOwner();
        $privacy_url = run_url($name, "privacy_policy");
        $tos_url = run_url($name, "terms_of_service");
        $settings_url = run_url($name, "settings");
        $footer = "Contact the [study administration](mailto:{$owner->email}) in case of questions. [Privacy Policy]($privacy_url). [Terms of Service]($tos_url). [Settings]($settings_url).";
        $this->saveSettings(array("footer_text" => $footer));

        // create default run service message
        $props = RunUnit::getDefaults('ServiceMessagePage');
        $unit = RunUnitFactory::make($this, $props)->create();

        return $name;
    }

    public function getUploadedFiles() {
        return $this->db->select('id, created, modified, original_file_name, new_file_path')
                        ->from('survey_uploaded_files')
                        ->where(array('run_id' => $this->id))
                        ->order('created', 'desc')
                        ->fetchAll();
    }

    private $batch_directory;

    private function makeBatchDirectory() {
        if (!isset($this->batch_directory)) {
            // Generate a random directory name for this batch
            $this->batch_directory = 'assets/tmp/admin/' . crypto_token(15, true) . '/';
    
            // Ensure the batch directory exists
            $local_path = APPLICATION_ROOT . 'webroot/';
            $destination_dir = $local_path . $this->batch_directory;
            if (!is_dir($destination_dir)) {
                mkdir($destination_dir, 0755, true);
            }
        }
    
        return $this->batch_directory;
    }

    public function uploadFiles($files) {
        $max_size_upload = Config::get('admin_maximum_size_of_uploaded_files');
        $allowed_file_endings = Config::get('allowed_file_endings_for_run_upload');
    
        // make lookup array
        $existing_files = $this->getUploadedFiles();
        $files_by_names = array();
        foreach ($existing_files as $existing_file) {
            $files_by_names[$existing_file['original_file_name']] = $existing_file['new_file_path'];
        }
    
        // Ensure the batch directory exists
        $local_path = APPLICATION_ROOT . 'webroot/' ;
    
        // loop through files and modify them if necessary
        for ($i = 0; $i < count($files['tmp_name']); $i++) {
            // validate if any error occurred on upload
            if ($files['error'][$i]) {
                $this->errors[] = __("An error occurred uploading file '%s'. ERROR CODE: PFUL-%d", $files['name'][$i], $files['error'][$i]);
                continue;
            }
    
            // validate file size
            $size = (int) $files['size'][$i];
            if (!$size || ($size > $max_size_upload * 1048576)) {
                $this->errors[] = __("The file '%s' is too big or the size could not be determined. The allowed maximum size is %d megabytes.", $files['name'][$i], round($max_size_upload, 2));
                continue;
            }
    
            // validate mime type and file ending
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($files['tmp_name'][$i]);
            $original_file_name = $files['name'][$i];
            $file_extension = pathinfo($original_file_name, PATHINFO_EXTENSION);
    
            // Adjust validation for ambiguous types
            if ($mime == 'text/plain' || $mime == "text/x-asm") {
                // Add additional cases for other ambiguous MIME types
                switch ($file_extension) {
                    case 'css':
                        $mime = 'text/css';
                        break;
                    case 'js':
                        $mime = 'text/javascript';
                        break;
                    case 'svg':
                        $mime = 'image/svg+xml';
                        break;
                    case 'html':
                        $mime = 'text/html';
                        break;
                    case 'xml':
                        $mime = 'application/xml';
                        break;
                    case 'md':
                        $mime = 'text/markdown';
                        break;
                    case 'yaml':
                    case 'yml':
                        $mime = 'application/x-yaml';
                        break;
                    case 'json':
                        $mime = 'application/json';
                        break;
                    case 'rtf':
                        $mime = 'application/rtf';
                        break;
                    case 'php':
                        $mime = 'application/x-httpd-php';
                        break;
                    case 'sh':
                        $mime = 'application/x-sh';
                        break;
                }
            }
    
            if (!isset($allowed_file_endings[$mime]) || $allowed_file_endings[$mime] !== $file_extension) {
                $this->errors[] = __('The file "%s" has an invalid file extension %s. Expected %s for MIME type %s.', $original_file_name, $file_extension, $allowed_file_endings[$mime], $mime);
                continue;
            }

            // Keep old file path if a file of the same name has been uploaded before
            if(array_key_exists($original_file_name, $files_by_names)) {
                $new_file_path = $files_by_names[$original_file_name]; // web, below webroot
                $local_file_path = $local_path . $new_file_path;
            } else {
            // New path name if file name is new
                $batch_directory = $this->makeBatchDirectory();
                $destination_dir = $local_path . $batch_directory;
                // Sanitize file name to remove control characters
                $sanitized_file_name = preg_replace('/[\x00-\x1F\x7F]/u', '', $original_file_name);  // Remove control characters

                // Ensure the destination path is within the intended directory
                $new_file_path = $batch_directory . $sanitized_file_name;
                $local_file_path = $local_path . $new_file_path;
                if (strpos(realpath(dirname($local_file_path)), realpath($destination_dir)) !== 0) {
                    $this->errors[] = __("The file '%s' could not be uploaded due to an invalid file path.", $sanitized_file_name);
                    continue;
                }
            }

            // save file
            if (move_uploaded_file($files['tmp_name'][$i], $local_file_path)) {
                $this->db->insert_update('survey_uploaded_files', array(
                    'run_id' => $this->id,
                    'created' => mysql_now(),
                    'original_file_name' => $original_file_name,
                    'new_file_path' => $new_file_path,
                ), array(
                    'modified' => mysql_now()
                ));
                $this->messages[] = __('The file "%s" was successfully uploaded to %s.', $original_file_name, $new_file_path);
            } else {
                $this->errors[] = __("Unable to move uploaded file '%s' to storage location.", $files['name'][$i]);
            }
        }
    
        // MODIFIED: Return the batch directory path on success (assuming no errors occurred *within* the loop that stopped processing)
        // If _saveUploadedFile encounters errors, it adds to $this->errors. We return false if errors exist.
        if (empty($this->errors)) {
            // Ensure batch_directory was actually set (it should be by makeBatchDirectory)
            return isset($this->batch_directory) ? $this->batch_directory : false; 
        } else {
            return false;
        }
    }

    public function deleteFile($id, $filename) {
        $where = array('id' => (int) $id, 'original_file_name' => $filename);
        $filepath = $this->db->findValue('survey_uploaded_files', $where, 'new_file_path');
        $deleted = $this->db->delete('survey_uploaded_files', $where);
        $physicalfile = APPLICATION_ROOT . "webroot/" . $filepath;
        if ($deleted && file_exists($physicalfile)) {
            @unlink($physicalfile);
        }
        return $deleted;
    }

    public function deleteFiles() {
        $where = array('run_id' => (int) $this->id);
        $files_to_delete = $this->db->find('survey_uploaded_files', $where, ['cols' => ['id', 'new_file_path']]);
        $local_path_prefix = APPLICATION_ROOT . "webroot/";

        foreach ($files_to_delete as $file) {
            $physicalfile = $local_path_prefix . $file['new_file_path'];
            if(file_exists($physicalfile)) {
                @unlink($physicalfile);
            }
            $this->db->delete('survey_uploaded_files', ['id' => $file['id']]);
        }

    }

    public static function nameExists($name) {
        return DB::getInstance()->entry_exists('survey_runs', array('name' => $name));
    }

    public function reorder($positions) {
        $run_unit_id = null;
        $pos = null;
        $update = "UPDATE `survey_run_units` SET position = :position WHERE run_id = :run_id AND id = :run_unit_id";
        $reorder = $this->db->prepare($update);
        $reorder->bindParam(':run_id', $this->id);
        $reorder->bindParam(':run_unit_id', $run_unit_id);
        $reorder->bindParam(':position', $pos);

        foreach ($positions as $run_unit_id => $pos) {
            $reorder->execute();
        }
        return true;
    }

    public function getAllUnitIds() {
        return $this->db->select(array('id' => 'run_unit_id', 'unit_id', 'position'))
                        ->from('survey_run_units')
                        ->where(array('run_id' => $this->id))
                        ->order('position')
                        ->fetchAll();
    }
    
    public function getFirstPosition() {
        if ($units = $this->getAllUnitIds()) {
            return $units[0]['position'];
        }
    }
    
    public function getNextPosition($current) {
        $row = $this->db->select('position')
                ->from('survey_run_units')
                ->where(['run_id' => $this->id, 'position >' => $current])
                ->order('position')
                ->limit(1)
                ->fetch();
        
        if ($row) {
            return $row['position'];
        }

        return null;
    }

    public function getParsedPrivacyField($field) {
        return match ($field) {
            'privacy-policy' => $this->privacy_parsed,
            'terms-of-service' => $this->tos_parsed,
            default => "",
        };
    }

    public function hasPrivacyUnit() {
        $select = $this->db->select(array('unit_id'));
        $select->from('survey_run_units');
        $select->join('survey_units', 'survey_units.id = survey_run_units.unit_id');
        $select->where(array('run_id' => $this->id, 'type' => 'Privacy'));

        return $select->fetchColumn() !== false;
    }

    public function hasPrivacy() {
        return $this->privacy !== null AND trim($this->privacy) !== '';
    }

    public function hasToS() {
        return $this->tos !== null AND trim($this->tos) !== '';
    }

    public function getAllUnitTypes() {
        $select = $this->db->select(array('survey_run_units.id' => 'run_unit_id', 'unit_id', 'position', 'type', 'description'));
        $select->from('survey_run_units');
        $select->join('survey_units', 'survey_units.id = survey_run_units.unit_id');
        $select->where(array('run_id' => $this->id))->order('position');

        return $select->fetchAll();
    }

    public function getOverviewScript() {
        return $this->getSpecialUnit('OverviewScriptPage');
    }

    public function getServiceMessage() {
        return $this->getSpecialUnit('ServiceMessagePage');
    }

    public function getNumberOfSessionsInRun() {
        $g_users = $this->db->prepare(
                "SELECT COUNT(`survey_run_sessions`.id) AS sessions, AVG(`survey_run_sessions`.position) AS avg_position
			FROM `survey_run_sessions`
			WHERE `survey_run_sessions`.run_id = :run_id;"
        );
        $g_users->bindParam(':run_id', $this->id);
        $g_users->execute();
        return $g_users->fetch(PDO::FETCH_ASSOC);
    }

    /**
     *
     * @return \User
     */
    public function getOwner() {
        if (!$this->owner) {
            $this->owner = new User($this->user_id);
        }
        return $this->owner;
    }

    public function getUserCounts() {
        $g_users = $this->db->prepare(
                "SELECT COUNT(`id`) AS users_total,
				SUM(`ended` IS NOT NULL) AS users_finished,
				SUM(`ended` IS NULL AND `last_access` >= DATE_SUB(NOW(), INTERVAL 1 DAY) ) 	AS users_active_today,
				SUM(`ended` IS NULL AND `last_access` >= DATE_SUB(NOW(), INTERVAL 7 DAY) ) 	AS users_active,
				SUM(`ended` IS NULL AND `last_access` < DATE_SUB(NOW(), INTERVAL 7 DAY) ) 	AS users_waiting
			FROM `survey_run_sessions`
			WHERE `survey_run_sessions`.run_id = :run_id;");

        $g_users->bindParam(':run_id', $this->id);
        $g_users->execute();
        return $g_users->fetch(PDO::FETCH_ASSOC);
    }

    public function emptySelf() {
        $surveys = $this->getAllSurveys();
        foreach ($surveys as $survey) {
            $survey['type'] = 'Survey';
            /* @var $unit Survey */
            $unit = RunUnitFactory::make($this, $survey);
            if (!$unit->surveyStudy->backupResults()) {
                alert('Could not backup results of survey ' . $unit->surveyStudy->name, 'alert-danger');
                return false;
            }
        }
        $rows = $this->db->delete('survey_run_sessions', array('run_id' => $this->id));
        alert('Run was emptied. ' . $rows . ' were deleted.', 'alert-info');
        return $rows;
    }

    public function getSpecialUnit($xtype, $id = null) {
        $units = $this->getSpecialUnits(false, $xtype, $id);
        if (empty($units)) {
            return null;
        }
        
        return RunUnitFactory::make($this, [
            'special' => $xtype,
            'type' => $units[0]['type'],
            'id' => $units[0]['unit_id'],
        ]);
    }

    public function getSpecialUnits($render = false, $xtype = null, $id = null) {
        $cols = array(
            'survey_run_special_units.id' => 'unit_id', 'survey_run_special_units.run_id', 'survey_run_special_units.type' => 'xtype', 'survey_run_special_units.description',
            'survey_units.type', 'survey_units.created', 'survey_units.modified'
        );
        $select = $this->db->select($cols);
        $select->from('survey_run_special_units');
        $select->join('survey_units', 'survey_units.id = survey_run_special_units.id');
        $select->where('survey_run_special_units.run_id = :run_id');
        $select->order('survey_units.id', 'desc');
        $params = array('run_id' => $this->id);
        if ($xtype !== null) {
            $select->where('survey_run_special_units.type = :xtype');
            $params['xtype'] = $xtype;
        }
        if ($id !== null) {
            $select->where('survey_run_special_units.id = :id');
            $params['id'] = $id;
        }
        $select->bindParams($params);

        if ($render === false) {
            return $select->fetchAll();
        } else {
            $units = array();
            foreach ($select->fetchAll() as $unit) {
                $units[] = array(
                    'id' => $unit['unit_id'],
                    'html_units' => array(array(
                            'special' => $unit['xtype'],
                            'run_unit_id' => $unit['unit_id'],
                            'unit_id' => $unit['unit_id']
                        )),
                );
            }
            return $units;
        }
    }

    public function getReminderSession($reminder_id, $session, $run_session_id) {
        // create a unit_session here and get a session_id and pass it when making the unit
        $runUnit = RunUnitFactory::make($this, ['id' => $reminder_id]);
        $runSession = new RunSession($session, $this, ['id' => $run_session_id]);
        $runSession->createUnitSession($runUnit, false);
        
        return $runSession->currentUnitSession;
    }

    public function getCustomCSS() {
        if ($this->custom_css_path != null) {
            return $this->getFileContent($this->custom_css_path);
        }

        return "";
    }

    public function getCustomJS() {
        if ($this->custom_js_path != null) {
            return $this->getFileContent($this->custom_js_path);
        }

        return "";
    }

    public function getManifestJSON() {
        if ($this->manifest_json_path != null) {
            return $this->getFileContent($this->manifest_json_path);
        }

        return "";
    }

    public function getManifestJSONPath() {
        return $this->manifest_json_path;
    }

    /**
     * Get the VAPID public key for this run
     * 
     * @return string|null The VAPID public key or null if not set
     */
    public function getVapidPublicKey() {
        return $this->vapid_public_key;
    }

    public function getPwaIconPath() {
        return $this->pwa_icon_path;
    }

    private function getFileContent($path) {
        $filePath = APPLICATION_ROOT . "webroot/" . $path;
        // Check if path is a readable file before attempting to read
        if (is_file($filePath) && is_readable($filePath)) {
            // Use file_get_contents for simpler error handling
            $content = file_get_contents($filePath);
            return $content !== false ? $content : '';
        }
        return '';
    }

    public function saveSettings($posted) {
        $parsedown = new ParsedownExtra();
        $parsedown->setBreaksEnabled(true);
        $successes = array();
        if (isset($posted['description'])) {
            $posted['description_parsed'] = $parsedown->text($posted['description']);
            $this->run_settings[] = 'description_parsed';
        }
        if (isset($posted['public_blurb'])) {
            $posted['public_blurb_parsed'] = $parsedown->text($posted['public_blurb']);
            $this->run_settings[] = 'public_blurb_parsed';
        }
        if (isset($posted['footer_text'])) {
            $posted['footer_text_parsed'] = $parsedown->text($posted['footer_text']);
            $this->run_settings[] = 'footer_text_parsed';
        }
        if (isset($posted['expiresOn'])) {
            // Handle empty or invalid expiry date
            if (empty($posted['expiresOn'])) {
                $posted['expiresOn'] = null;
            } else {
                $timestamp = strtotime($posted['expiresOn']);
                if ($timestamp === false) {
                    alert('Invalid expiry date format provided. Please use YYYY-MM-DD format.', 'alert-danger');
                    unset($posted['expiresOn']);
                } else {
                    $posted['expiresOn'] = date('Y-m-d', $timestamp);
                    if($timestamp < time()) {
                        alert('The expiry date cannot be set to a past date.', 'alert-danger');

                        $posted['expiresOn'] = null;
                    }
                    if (Config::get('keep_study_data_for_months_maximum') !== INF) {
                        $max_date = strtotime('+' . Config::get('keep_study_data_for_months_maximum') . ' months');
                        if ($timestamp > $max_date) {
                            alert('The expiry date cannot be set to more than ' . Config::get('keep_study_data_for_months_maximum') . ' months in the future.', 'alert-danger');
                            $posted['expiresOn'] = date('Y-m-d', $max_date);
                        }
                    }
                }
            }
        }
        $require_privacy = Config::get("require_privacy_policy", false);
        if ($require_privacy AND ((isset($posted['privacy']) && trim($posted['privacy']) == '')) && $this->public > 0) {
            alert("This run is public, but you have removed the privacy policy. We've set it to private for you. Add a privacy policy before setting the run to public again.", 'alert-danger');
            $this->db->update('survey_runs', array('public' => 0), array('id' => $this->id));
        }
        if (isset($posted['privacy'])) {
            $posted['privacy_parsed'] = $parsedown->text($posted['privacy']);
            $this->run_settings[] = 'privacy_parsed';
        }
        if (isset($posted['tos'])) {
            $posted['tos_parsed'] = $parsedown->text($posted['tos']);
            $this->run_settings[] = 'tos_parsed';
        }

        $cookie_units = array_keys($this->expire_cookie_units);
        if (isset($posted['expire_cookie_value']) && 
                isset($posted['expire_cookie_unit']) && in_array($posted['expire_cookie_unit'], $cookie_units)) {
            if (is_numeric($posted['expire_cookie_value'])) {
                $posted['expire_cookie'] = factortosecs($posted['expire_cookie_value'], $posted['expire_cookie_unit']); 
            } else {
                $posted['expire_cookie'] = 0;
            }
        } elseif (!isset($posted['expire_cookie'])) {
            $posted['expire_cookie'] = $this->expire_cookie;
        }
        unset($posted['expire_cookie_value'], $posted['expire_cookie_unit']);

        $updates = array();
        foreach ($posted as $name => $value) {
            if($name != 'expiresOn') {
                $value = trim((string)$value);
            }

            if (!in_array($name, $this->run_settings)) {
                $this->errors[] = "Invalid setting " . h($name);
                continue;
            }

            if ($name == "custom_js" || $name == "custom_css" || $name == "manifest_json") {
                if ($name == "custom_js") {
                    $asset_path = $this->custom_js_path;
                    $file_ending = '.js';
                } elseif ($name == "custom_css") {
                    $asset_path = $this->custom_css_path;
                    $file_ending = '.css';
                } elseif ($name == "manifest_json") {
                    $asset_path = $this->manifest_json_path;
                    $file_ending = '.json';
                }

                $name = $name . "_path";
                $written_path = $this->writeAssetFile($value, $asset_path, $file_ending);
                if ($written_path === false) {
                    // Skip updating this field if writing failed
                    alert("Failed to save {$name}. Skipping this update.", 'alert-danger');
                    continue;
                }
                $value = $written_path;
            }
            $updates[$name] = $value;
        }

        if ($updates) {
            $updates['modified'] = mysql_now();
            $this->db->update('survey_runs', $updates, array('id' => $this->id));
        }

        return true;
    }

    public function getAllSurveys() {
        // first, generate a master list of the search set (all the surveys that are part of the run)
        return $this->db->select(array('COALESCE(`survey_studies`.`results_table`,`survey_studies`.`name`)' => 'results_table', 'survey_studies.name', 'survey_studies.id'))
                        ->from('survey_studies')
                        ->leftJoin('survey_run_units', 'survey_studies.id = survey_run_units.unit_id')
                        ->leftJoin('survey_runs', 'survey_runs.id = survey_run_units.run_id')
                        ->where('survey_runs.id = :run_id')
                        ->bindParams(array('run_id' => $this->id))
                        ->fetchAll();
    }

    public function getAllLinkedSurveys() {
        // first, generate a master list of the search set (all the surveys that are part of the run)
        return $this->db->select(array('COALESCE(`survey_studies`.`results_table`,`survey_studies`.`name`)' => 'results_table', 'survey_studies.name', 'survey_studies.id'))
                        ->from('survey_studies')
                        ->leftJoin('survey_run_units', 'survey_studies.id = survey_run_units.unit_id')
                        ->leftJoin('survey_runs', 'survey_runs.id = survey_run_units.run_id')
                        ->where('survey_runs.id = :run_id')
                        ->where('survey_studies.unlinked = 0')
                        ->bindParams(array('run_id' => $this->id))
                        ->fetchAll();
    }

    public function getData($rstmt = false) {
        ini_set('memory_limit', Config::get('memory_limit.run_get_data'));

        $collect = $this->db->prepare("SELECT 
			`survey_studies`.name AS survey_name,
			`survey_run_units`.position AS unit_position,
			`survey_unit_sessions`.id AS unit_session_id,
			`survey_run_sessions`.session AS session,
			`survey_items`.type,
			`survey_items`.name AS item_name,
			`survey_items`.label,
			`survey_items`.optional,
			`survey_items`.showif,
			`survey_items_display`.created,
			`survey_items_display`.saved,
			`survey_items_display`.shown,
			`survey_items_display`.shown_relative,
			`survey_items_display`.answer,
			`survey_items_display`.answered,
			`survey_items_display`.answered_relative,
			`survey_items_display`.displaycount,
			`survey_items_display`.display_order,
			`survey_items_display`.hidden
			FROM `survey_items_display`
			LEFT JOIN `survey_unit_sessions` ON `survey_items_display`.session_id = `survey_unit_sessions`.id
			LEFT JOIN `survey_run_sessions` ON `survey_unit_sessions`.run_session_id = `survey_run_sessions`.id
			LEFT JOIN `survey_items` ON `survey_items_display`.item_id = `survey_items`.id
			LEFT JOIN `survey_studies` ON `survey_items`.study_id = `survey_studies`.id
			LEFT JOIN `survey_run_units` ON `survey_studies`.id = `survey_run_units`.unit_id
			WHERE `survey_run_sessions`.run_id = :id 
			AND `survey_studies`.unlinked = 0");
        $collect->bindValue(":id", $this->id);
        $collect->execute();
        if ($rstmt === true) {
            return $collect;
        }

        $results = array();
        while ($row = $collect->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $row;
        }
        return $results;
    }

    public function getRandomGroups() {
        $g_users = $this->db->prepare("SELECT 
			`survey_run_sessions`.session,
			`survey_unit_sessions`.id AS session_id,
			`survey_runs`.name AS run_name,
			`survey_run_units`.position,
			`survey_units`.type AS unit_type,
			`survey_unit_sessions`.created,
			`survey_unit_sessions`.ended,
			`shuffle`.group
		FROM `survey_unit_sessions`
		LEFT JOIN `shuffle` ON `shuffle`.session_id = `survey_unit_sessions`.id
		LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
		LEFT JOIN `survey_users` ON `survey_users`.id = `survey_run_sessions`.user_id
		LEFT JOIN `survey_units` ON `survey_unit_sessions`.unit_id = `survey_units`.id
		LEFT JOIN `survey_run_units` ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
		LEFT JOIN `survey_runs` ON `survey_runs`.id = `survey_run_units`.run_id
		WHERE `survey_run_sessions`.run_id = :run_id AND `survey_units`.type = 'Shuffle'
		ORDER BY `survey_run_sessions`.id DESC,`survey_unit_sessions`.id ASC;");

        $g_users->bindParam(':run_id', $this->id);
        $g_users->execute();
        return $g_users;
    }

    public function isStudyTest() {
        return $this->name === self::TEST_RUN;
    }

    private function testStudy() {
        if (!($data = Session::get('test_study_data'))) {
            formr_error(404, 'Not Found', 'Nothing to Test-Drive');
        }
        
        if (isset($data['unit_id'])) {
            $data['id'] = $data['unit_id'];
        }

        $runUnit = (new Survey($this, $data))->load();
        $runSession = RunSession::getTestSession($this);
        if (!isset($data['unit_session_id'])) {
            $runSession->createUnitSession($runUnit);
            $data['unit_session_id'] = $runSession->currentUnitSession->id;
            Session::set('test_study_data', $data);
        } else {
            $unitSession = new UnitSession($runSession, $runUnit, ['id' => $data['unit_session_id'], 'load'=> true]);
            $runSession->currentUnitSession = $unitSession;
        }
        $output = $runSession->execute();
        
        if (!$output) {
            $output = [
                'title' => 'Finish',
                'body' => "
					<h1>Finish</h1>
					<p>You're finished with testing this survey.</p>
					<a href='" . admin_study_url($data['study_name']) . "'>Back to the admin control panel.</a>"
            ];
            
            Session::delete('test_study_data');
        }
        
        return compact("output", "runSession");
    }

    public function exec(User $user) {
        if (!$this->valid) {
            formr_error(404, 'Not Found', __("Run '%s' is broken or does not exist.", $this->name), 'Study Not Found');
            return false;
        } elseif ($this->isStudyTest()) {
            $test = $this->testStudy();
            extract($test);
        } else {

            $runSession = run_session(new RunSession($user->user_code, $this, ['user' => $user]));

            if (($this->getOwner()->user_code == $user->user_code || // owner always has access
                    $runSession->isTesting()) || // testers always have access
                    ($this->public >= 1 && $runSession->id) || // already enrolled
                    ($this->public >= 2)) { // anyone with link can access

                if ($runSession->id === null) {
                    $runSession->create($user->user_code, (int) $user->created($this));
                }

                Session::globalRefresh();
                $output = $runSession->execute();
            } else {
                $runSession->createUnitSession($this->getServiceMessage(), false, false);
                $output = $runSession->executeTest();
                alert("<strong>Sorry:</strong> You cannot currently access this run.", 'alert-warning');
            }

            $runSession->setLastAccess();
            $this->activeRunSession = $runSession;
        }

        if (!$output) {
            return;
        }

        global $title;
        $css = $js = array();

        if (isset($output['title'])) {
            $title = $output['title'];
        } else {
            $title = $this->title ? $this->title : $this->name;
        }

        if ($this->custom_css_path) {
            $css[] = asset_url($this->custom_css_path);
        }
        if ($this->custom_js_path) {
            $js[] = asset_url($this->custom_js_path);
        }

        $run_content = '';

        if (!$this->renderedDescAndFooterAlready && !empty($this->description_parsed)) {
            $run_content .= $this->description_parsed;
        }

        if (isset($output['body'])) {
            $run_content .= $output['body'];
        }
        if (!$this->renderedDescAndFooterAlready && !empty($this->footer_text_parsed)) {
            $run_content .= $this->footer_text_parsed;
        }

        if ($runSession->isTesting()) {
            $animal_end = strpos($user->user_code, "XXX");
            if ($animal_end === false) {
                $animal_end = 10;
            }

            $run_content .= Template::get('admin/run/monkey_bar', array(
                        'user' => $user,
                        'run' => $this,
                        'run_session' => $runSession,
                        'short_code' => substr($user->user_code, 0, $animal_end),
                        'icon' => $user->created($this) ? "fa-user-md" : "fa-stethoscope",
                        'disable_class' => $this->isStudyTest() ? " disabled " : "",
            ));
        }

        return array(
            'title' => $title,
            'css' => $css,
            'js' => $js,
            'run_session' => $runSession,
            'run_content' => $run_content,
            'redirect' => array_val($output, 'redirect'),
            'run' => $this,
        );
    }

    /**
     * Export RUN units
     *
     * @param string $name The name that will be assigned to export
     * @param array $units
     * @param boolean $inc_survey Should survey data be included in export?
     * @return mixed Returns an array of its two inputs.
     */
    public function export($name, array $units, $inc_survey) {
        $SPR = new SpreadsheetReader();
        // Save run units
        foreach ($units as $i => &$unit) {
            if ($inc_survey && $unit->type === 'Survey') {
                $survey = SurveyStudy::loadById($unit->unit_id);
                $unit->survey_data = $SPR->exportItemTableJSON($survey, true);
            }
            unset($unit->unit_id, $unit->run_unit_id);
        }
        // Save run settings
        $settings = array(
            'header_image_path' => $this->header_image_path,
            'description' => $this->description,
            'footer_text' => $this->footer_text,
            'public_blurb' => $this->public_blurb,
            'privacy' => $this->privacy,
            'tos' => $this->tos,
            'cron_active' => (int) $this->cron_active,
            'custom_js' => $this->getCustomJS(),
            'custom_css' => $this->getCustomCSS(),
            'expiresOn' => $this->expiresOn,
        );

        // save run files
        $files = array();
        $uploads = $this->getUploadedFiles();
        foreach ($uploads as $file) {
            $files[] = site_url('file_download/' . $this->id . '/' . $file['original_file_name']);
        }

        $export = array(
            'name' => $name,
            'units' => array_values($units),
            'settings' => $settings,
            'files' => $files,
        );
        return $export;
    }

    public function exportStructure() {
        $unitIds = $this->getAllUnitTypes();
        $units = array();

        /* @var RunUnit $u */
        foreach ($unitIds as $u) {
            $u['id'] = $u['unit_id'];
            $unit = RunUnitFactory::make($this, $u);
            $ex_unit = $unit->getExportUnit();
            $ex_unit['unit_id'] = $unit->id;
            $units[] = (object) $ex_unit;
        }
        $export = $this->export($this->name, $units, true);

        return $export;
    }

    /**
     * Import a set of run units into current run by parsing a valid json string.
     * Existing exported run units are read from configured dir $settings[run_exports_dir]
     * Foreach unit item there is a check for at least for 'type' and 'position' attributes
     *
     * @param string $json_string JSON string of run units
     * @param int $start_position Start position to be assigned to units. Defaults to 1.
     * @return array Returns an array on rendered units indexed by position
     */
    public function importUnits($json_string, $start_position = 0) {
        ini_set('memory_limit', Config::get('memory_limit.run_import_units'));
        if (!$start_position) {
            $start_position = 0;
        } else {
            $start_position = (int) $start_position - 10;
        }
        $json = json_decode($json_string);
        $existingUnits = $this->getAllUnitIds();
        if ($existingUnits) {
            $last = end($existingUnits);
            $start_position = $last['position'] + 10;
        }

        if (empty($json->units)) {
            alert("<strong>Error</strong> Invalid json string provided.", 'alert-danger');
            return false;
        }

        $units = (array) $json->units;
        $createdUnits = array();

        foreach ($units as $unit) {
            $options = [];
            if (isset($unit->position) && !empty($unit->type)) {
                $unit->position = $start_position + $unit->position;
                // for some reason Endpage replaces Page
                if (strpos($unit->type, 'page') !== false) {
                    $unit->type = 'Page';
                }

                if ($unit->type === 'Survey') {
                    $options = (array) $unit;
                    $options['importing'] = true;
                    $options['run'] = $this;
                }

                if ($unit->type === 'PushMessage') {
                    $options = (array) $unit;
                }

                if ($unit->type === 'SkipBackward' || $unit->type === 'SkipForward') {
                    $unit->if_true = $unit->if_true + $start_position;
                }

                if ($unit->type === 'Email') {
                    $unit->account_id = null;
                }
                
                if ($unit->type === 'Wait') {
                    $unit->body = $unit->body + $start_position;
                }

                $unit = (array) $unit;
                $unitObj = RunUnitFactory::make($this, $unit);
                $unitObj->create($options);
                
                if ($unitObj->valid) {
                    $createdUnits[$unitObj->position] = $unitObj->displayForRun(Site::getInstance()->renderAlerts());
                }
            }
        }

        // try importing settings
        if (!empty($json->settings)) {
            $this->saveSettings((array) $json->settings);
        }
        return $createdUnits;
    }

    protected function setExpireCookieUnits() {
        $unit = secstofactor($this->expire_cookie);
        if ($unit) {
            $this->expire_cookie_unit = $unit[1];
            $this->expire_cookie_value = $unit[0];
        }
    }

    public function isEmpty(): bool {
        $count = $this->db->select('COUNT(*) as count')
            ->from('survey_run_sessions')
            ->where(['run_id' => $this->id])
            ->fetchColumn();
        
        return $count == 0;
    }

    private function writeAssetFile($value, $asset_path, $file_ending) {
        // Delete old file if value is empty but asset_path exists
        if (empty($value) && !empty($asset_path)) {
            $old_file = APPLICATION_ROOT . 'webroot/' . $asset_path;
            if (file_exists($old_file) && !unlink($old_file)) {
                alert("Could not delete old file ({$asset_path}).", 'alert-warning');
            }
            return null;
        }

        if ($value) {
            // if $asset_path has not been set or is null, create a new path
            if (empty($asset_path)) {
                $asset_path = 'assets/tmp/admin/' . crypto_token(33, true) . $file_ending;
            }

            // Ensure the directory exists with proper permissions
            $dir = dirname(APPLICATION_ROOT . 'webroot/' . $asset_path);
            if (!is_dir($dir)) {
                // Use 0755 for more restrictive permissions
                // Owner can read/write/execute, others can read/execute
                if (!mkdir($dir, 0755, true)) {
                    alert("Could not create directory for asset file.", 'alert-warning');
                    return false;
                }
            }

            $asset_file = APPLICATION_ROOT . 'webroot/' . $asset_path;
            $path = new SplFileInfo($asset_file);
            
            try {
                if (file_exists($path->getPathname())):
                    $file = $path->openFile('c+');
                    $file->rewind();
                    $file->ftruncate(0); // truncate any existing file
                else:
                    $file = $path->openFile('c+');
                endif;
                
                $file->fwrite($value);
                $file->fflush();
                $value = $asset_path;
            } catch (Exception $e) {
                alert("Could not write to asset file ({$asset_path}).", 'alert-warning');
                return false;
            }
        }
        return $value;
    }

    /**
     * Generate VAPID keys for the run
     * 
     * @return void
     */
    public function generateVapidKeys() {
        // Check if keys already exist
        $existingPublicKey = $this->getVapidPublicKey();
        if ($existingPublicKey) {
            return; // Keys already exist
        }
    
        // Generate new VAPID keys using web-push library
        $vapidKeys = \Minishlink\WebPush\VAPID::createVapidKeys();
    
        // Encrypt the private key before storage
        $encryptedPrivate = \Crypto::encrypt($vapidKeys['privateKey']);
    
        // Store both keys in the database
        $this->db->update('survey_runs', [
            'vapid_public_key' => $vapidKeys['publicKey'],
            'vapid_private_key' => $encryptedPrivate,
            'modified' => mysql_now()
        ], ['id' => $this->id]);
    
        // Refresh model properties
        $this->vapid_public_key = $vapidKeys['publicKey'];
    }

    /**
     * Generate the manifest file for the run
     * 
     * @return bool Returns true if the manifest was generated successfully, false otherwise
     */
    public function generateManifest() {

        $this->generateVapidKeys();
        // Read the template
        $template_path = APPLICATION_ROOT . 'templates/run/manifest_template.json';
        if (!file_exists($template_path)) {
            return false;
        }

        $template_content = file_get_contents($template_path);
        
        $pwa_icon_base_path_for_manifest = '/assets/pwa/'; // Default path
        $run_pwa_icon_path_val = $this->getPwaIconPath(); 
        if ($run_pwa_icon_path_val && is_dir(APPLICATION_ROOT . 'webroot/' . $run_pwa_icon_path_val)) {
            // Ensure leading slash, remove potential double slashes, ensure trailing slash for placeholder replacement
            $pwa_icon_base_path_for_manifest = '/' . trim($run_pwa_icon_path_val, '/') . '/'; 
        }

        // Replace placeholders
        $manifest_string = str_replace(
            array('{APP_NAME}', '{DESCRIPTION}', '{SCOPE}', '{ID}', '{START_URL}', '{PWA_ICON_BASE_PATH}'),
            array(
                $this->name,
                $this->description ?: '',
                run_url($this->name),
                run_url($this->name),
                run_url($this->name),
                $pwa_icon_base_path_for_manifest 
            ),
            $template_content
        );

        // Generate new asset path if none exists or use existing one
        if ($this->manifest_json_path) {
            // Remove .json extension if it exists, then ensure it's added correctly
            $path = preg_replace('/\.json$/', '', $this->manifest_json_path);
        } else {
            // Generate a new path in the assets directory
            $path = NULL;
        }

        // Write the file using writeAssetFile
        $written_path = $this->writeAssetFile($manifest_string, $path, '.json');
        if ($written_path === false) {
            return false;
        }

        // Update the path in the database
        $this->manifest_json_path = $written_path;
        $this->db->update('survey_runs', ['manifest_json_path' => $this->manifest_json_path], ['id' => $this->id]);

        return $manifest_string;
    }

    /**
     * Sets the provided batch directory path as the official PWA icon path for this run.
     * Cleans up any previously set PWA icon path and its files.
     *
     * @param string $new_pwa_icon_batch_path The webroot-relative path to a directory (typically a batch upload dir from uploadFiles).
     * @return bool True on success, false on failure.
     */
    public function setUploadedPwaIconsPath(string $new_pwa_icon_batch_path) {
        if (!$this->id) {
            $this->errors[] = "Run ID is not set. Cannot set PWA icon path.";
            return false;
        }

        $local_path_prefix = APPLICATION_ROOT . 'webroot/';

        // Ensure the new path ends with a slash
        if (!empty(trim($new_pwa_icon_batch_path)) && !str_ends_with($new_pwa_icon_batch_path, '/')) {
            $new_pwa_icon_batch_path .= '/';
        }

        // 1. Clean up old PWA path and files, if one was set
        if ($this->pwa_icon_path && $this->pwa_icon_path !== $new_pwa_icon_batch_path) {
            $old_path_full_local = $local_path_prefix . $this->pwa_icon_path;
            $old_pwa_files_in_db = $this->db->select('id, new_file_path')
                                        ->from('survey_uploaded_files')
                                        ->where(array(
                                            'run_id' => $this->id,
                                            'new_file_path LIKE' => $this->pwa_icon_path . '%'
                                        ))
                                        ->fetchAll();
            $deleted_db_count = 0;
            foreach ($old_pwa_files_in_db as $old_file_db_entry) {
                // Physical file deletion for these is tricky if old_path_full_local directory itself is deleted.
                // survey_uploaded_files entries will be deleted.
                $this->db->delete('survey_uploaded_files', array('id' => $old_file_db_entry['id']));
                $deleted_db_count++;
            }
            if ($deleted_db_count > 0) {
                 $this->messages[] = "Removed {$deleted_db_count} DB records for old PWA icons.";
            }

            if (is_dir($old_path_full_local)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($old_path_full_local, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iterator as $file) {
                    if ($file->isDir()) {
                        @rmdir($file->getRealPath());
                    } else {
                        @unlink($file->getRealPath());
                    }
                }
                if (@rmdir($old_path_full_local)) {
                    $this->messages[] = "Old PWA icon directory {$this->pwa_icon_path} removed.";
                } else {
                    $this->warnings[] = "Could not remove old PWA icon directory {$this->pwa_icon_path}.";
                }
            }
        }

        // 2. Update to the new path
        // The files themselves are assumed to be already uploaded by uploadFiles() into $new_pwa_icon_batch_path
        // and registered in survey_uploaded_files by uploadFiles().
        if (!is_dir($local_path_prefix . $new_pwa_icon_batch_path)) {
            $this->errors[] = "The new PWA icon path directory does not exist: " . htmlspecialchars($new_pwa_icon_batch_path);
            return false;
        }

        $updated = $this->db->update('survey_runs', array('pwa_icon_path' => $new_pwa_icon_batch_path), array('id' => $this->id));
        if ($updated !== false) {
            $this->pwa_icon_path = $new_pwa_icon_batch_path;
            $this->messages[] = "PWA icon path updated to: " . htmlspecialchars($new_pwa_icon_batch_path);
            return true;
        } else {
            $this->errors[] = "Failed to update PWA icon path in database.";
            return false;
        }
    }

    public function clearPwaIcons() {
        if (!$this->id) {
            $this->errors[] = "Run ID not set.";
            return false;
        }
        if (!$this->pwa_icon_path) {
            $this->messages[] = "No PWA icon path set, nothing to clear.";
            return true; // Not an error, just nothing to do.
        }

        $local_path_prefix = APPLICATION_ROOT . 'webroot/';
        $current_pwa_icon_path = $this->pwa_icon_path; // Use a local var before it's nulled
        $full_local_pwa_dir = $local_path_prefix . $current_pwa_icon_path;

        $existing_pwa_files_in_db = $this->db->select('id, new_file_path')
                                    ->from('survey_uploaded_files')
                                    ->where(array(
                                        'run_id' => $this->id,
                                        'new_file_path LIKE' => $current_pwa_icon_path . '%'
                                    ))
                                    ->fetchAll();
        $deleted_files_count = 0;
        $deleted_db_records_count = 0;

        foreach ($existing_pwa_files_in_db as $pwa_file_db_entry) {
            $physical_file = $local_path_prefix . $pwa_file_db_entry['new_file_path'];
            if (file_exists($physical_file)) {
                if (@unlink($physical_file)) {
                    $deleted_files_count++;
                }
            }
            $this->db->delete('survey_uploaded_files', array('id' => $pwa_file_db_entry['id']));
            $deleted_db_records_count++;
        }

        if (is_dir($full_local_pwa_dir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($full_local_pwa_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            $removed_dir = false;
            try {
                 foreach ($iterator as $fileNode) {
                    if ($fileNode->isDir()) {
                        @rmdir($fileNode->getRealPath());
                    } else {
                        @unlink($fileNode->getRealPath());
                    }
                }
                if (@rmdir($full_local_pwa_dir)) {
                    $removed_dir = true;
                }
            } catch (UnexpectedValueException $e) {
                // This can happen if directory becomes inaccessible during iteration (e.g. due to permissions or external deletion)
                $this->warnings[] = "Could not fully iterate PWA icon directory for deletion: " . $full_local_pwa_dir . " Error: " . $e->getMessage();
            }

            if ($removed_dir) {
                $this->messages[] = "PWA icon directory removed: " . htmlspecialchars($current_pwa_icon_path);
            } else {
                // If rmdir failed but files might have been deleted, it's a partial success / warning state
                if ($deleted_files_count > 0 || $deleted_db_records_count > 0) {
                    $this->warnings[] = "Could not completely remove PWA icon directory: " . htmlspecialchars($current_pwa_icon_path) . ". Some files/records may still have been cleared.";
                } else {
                    $this->errors[] = "Failed to remove PWA icon directory: " . htmlspecialchars($current_pwa_icon_path);
                }
            }
        }

        $updated = $this->db->update('survey_runs', array('pwa_icon_path' => null), array('id' => $this->id));
        if ($updated !== false) {
            $this->pwa_icon_path = null;
            $this->messages[] = "PWA icon path cleared from settings. {$deleted_db_records_count} DB records removed, {$deleted_files_count} physical files unlinked.";
            return true;
        } else {
            $this->errors[] = "Failed to clear PWA icon path in database.";
            return false;
        }
    }
}
