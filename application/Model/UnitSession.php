<?php

class UnitSession extends Model {

    public $id;
    public $unit_id; // survey_units.id
    public $run_session_id;
    public $created;
    public $expires;
    public $queued = 0;
    public $result;
    public $result_log;
    public $ended;
    public $expired;
    public $meta;
    public $queueable = 1;
    /**
     * @var RunSession
     */
    public $runSession;
    /**
     * @var RunUnit
     */
    public $runUnit;
    
    public $validatedStudyItems = [];
    
    protected $execResults = [];
    
    

    /**
     * A UnitSession needs a RunUnit to operate and belongs to a RunSession
     *
     * @param RunSession $runSession
     * @param RunUnit $runUnit
     * @param array $options An array of other options used to fetch a unit ID
     */
    public function __construct(RunSession $runSession, RunUnit $runUnit = null, $options = []) {
        parent::__construct();

        $this->runSession = $runSession;
        $this->runUnit = $runUnit;
        $this->assignProperties($options);
        if (isset($options['id'], $options['load'])) {
            $this->load();
        }
    }

    public function create($new_current_unit = true) {
        // only one can be current unit session at all times
        try {
            $this->db->beginTransaction();
            $session = $this->assignProperties([
                'unit_id' => $this->runUnit->id,
                'run_session_id' => $this->runSession->id > 0 ? $this->runSession->id : null,
                'created' => mysql_now(),
            ]);
            
            $this->id = $this->db->insert('survey_unit_sessions', $session);
            formr_log("Inserted {$this->runUnit->type} " . $this->id);
            if ($this->runSession->id !== null && $new_current_unit) {
                $this->runSession->currentUnitSession = $this;
                $this->db->update('survey_run_sessions', ['current_unit_session_id' => $this->id], ['id' => $this->runSession->id]);

                $this->db->update('survey_unit_sessions', ['queued' => -9], [
                    'run_session_id' => $this->runSession->id,
                    'id <>' => $this->id,
                    'queued >' => 0,
                ]);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
        }

        return $this;
    }

    public function load() {
        $columns = 'id, unit_id, run_session_id, created, expires, queued, result, result_log, ended, expired';
        if ($this->id !== null) {
            $vars = $this->db->findRow('survey_unit_sessions', ['id' => (int)$this->id], $columns);
        } else {
            $run_session_id = $this->runSession ? $this->runSession->id : $this->run_session_id;
            $unit_id = $this->runUnit ? $this->runUnit->id : $this->unit_id;
            $vars = $this->db->findRow('survey_unit_sessions', ['run_session_id' => $run_session_id, 'unit_id' => $unit_id], $columns);
        }
        
        if (!empty($vars['unit_id']) && !$this->runUnit) {
            $this->runUnit = RunUnitFactory::make($this->runSession->getRun(), ['id' => $vars['unit_id']]);
        }

        $this->assignProperties($vars);
        
        return $this;
    }

    public function __sleep() {
        return array('id', 'session', 'unit_id', 'created');
    }

    public function execute() {
        $this->execResults = [];
        // Check if session has expired by getting relevant unit data
        if ($this->isExpired()) {
            $this->execResults['expired'] =true;
            $this->execResults['move_on'] = true;
            $this->execResults['end_session'] = true;
            return $this->execResults;
        }

        if (($output = $this->runUnit->getUnitSessionOutput($this))) {
            if (!empty($output['log'])) {
                $this->assignProperties($output['log']);
                $this->logResult();
            }
            
            foreach ($output as $key => $value) {
                $this->execResults[$key] = $value;
            }
        }

        return $this->execResults;
    }

    protected function isExpired() {
        $expirationData = $this->runUnit->getUnitSessionExpirationData($this);

        if (!empty($expirationData['log'])) {
            $this->assignProperties($expirationData['log']);
            $this->logResult();
        }
        unset($expirationData['log']);
        $this->execResults = array_merge($this->execResults, $expirationData);
            
        if ($this->runUnit instanceof Pause || $this->runUnit instanceof Branch) {
            $expiration_extension = Config::get('unit_session.queue_expiration_extension', '+10 minutes');
            if ($expirationData['check_failed'] === true || $expirationData['expire_relatively'] === false) {
                // check again in x minutes something went wrong with ocpu evaluation
                $expirationData['expires'] = mysql_datetime(strtotime($expiration_extension));
                $expirationData['queued'] = UnitSessionQueue::QUEUED_TO_EXECUTE;
            }
        }

        if (empty($expirationData['expires'])) {
            return false;
        } elseif(!empty($expirationData['end_session'])) {
            $this->execResults['end_session'] = true;
            return true;
        } elseif ($expirationData['expires'] < time()) {
            return true;
        } elseif ($expirationData['queued']) {
            $this->execResults['queue'] = [
                'expires' => $expirationData['expires'],
                'queued' => $expirationData['queued'],
            ];
        }
    }

    /**
     * Check if unit session should be queued
     * ** ALWAYS CALL AFTER $this->isExpired() ***
     *
     * @return boolean
     */
    protected function isQueuable() {
        return !empty($this->execResults['queue']) && $this->runSession->getRun()->cron_active;
    }

    public function expire() {
        $unit = $this->runUnit;
        if ($unit->type === 'Survey') {
            $query = "UPDATE `{$unit->surveyStudy->results_table}` SET `expired` = NOW() WHERE `session_id` = :session_id AND `study_id` = :study_id AND `ended` IS null";
            $params = ['session_id' => $this->id, 'study_id' => $unit->surveyStudy->id];
            $this->db->exec($query, $params);
        }
                
        $expired = $this->db->exec(
            "UPDATE `survey_unit_sessions` SET 
                `expired` = NOW(), 
                `result` = 'expired',
                `queued` = 0 
             WHERE `id` = :id AND `unit_id` = :unit_id AND `ended` IS NULL LIMIT 1",
             ['id' => $this->id, 'unit_id' => $unit->id]
        );

        return $expired === 1;
    }

    public function end($reason = null) {
        $unit = $this->runUnit;
        if ($unit->type == "Survey" || $unit->type == "External") {
            if ($unit->type == "Survey") {
                $query = "UPDATE `{$unit->surveyStudy->results_table}` SET `ended` = NOW() WHERE `session_id` = :session_id AND `study_id` = :study_id AND `ended` IS null";
                $params = array('session_id' => $this->id, 'study_id' => $unit->surveyStudy->id);
                $this->db->exec($query, $params);
                
                $this->result = "survey_ended";
            } else if ($unit->type == "External") {
                $this->result = "external_ended";
            }
        } else {
            if ($reason !== null) {
                $this->result = $reason;
            } else if ($unit->type == "Pause") {
                $this->result = "pause_ended";
            } else if ($unit->type == "Wait") {
                $this->result = "wait_ended";
            } else if ($unit->type == "Endpage") {
                $this->result = $this->isExecutedByCron() ? 'ended_by_queue' : 'ended';
            } else {
                //$this->result = "ended_other";
            }
        }

        // @TODO import end from run unit
        $ended = $this->db->exec(
                "UPDATE `survey_unit_sessions` SET 
                `ended` = NOW(), 
                `result` = :result, 
                `result_log` = :result_log 
                WHERE `id` = :id AND `unit_id` = :unit_id AND `ended` IS NULL LIMIT 1",
                [
                    'id' => $this->id,
                    'unit_id' => $this->runUnit->id,
                    'result' => $this->result,
                    'result_log' => $this->result_log
                ]
        );

        return $ended === 1;
    }

    public function queue($output = null) {
        if ($this->isQueuable()) {
            UnitSessionQueue::addItem($this, $this->runUnit, $this->execResults['queue']);
        }
    }

    public function logResult() {
        $log = $this->db->exec(
                "UPDATE `survey_unit_sessions` SET 
                `result` = :result, 
                `result_log` = :result_log 
                WHERE `id` = :id AND `unit_id` = :unit_id AND `ended` IS NULL LIMIT 1",
                [
                    'id' => $this->id,
                    'unit_id' => $this->runUnit->id,
                    'result' => $this->result,
                    'result_log' => $this->result_log
                ]
        );

        return $log;
    }

    protected function hasOrderedStudyItems() {
        /** @var SurveyStudy $study */
        $study = $this->runUnit->surveyStudy;

        $nr_items = $this->db->count('survey_items', array('study_id' => $study->id), 'id');
        $nr_display_items = $this->db->count('survey_items_display', array('session_id' => $this->id), 'id');

        return $nr_display_items === $nr_items;
    }

    /**
     * Create a study record entry for this session. This is called only when
     * operating on a Survey unit
     *
     * @return boolean
     * @throws Exception
     */
    public function createSurveyStudyRecord() {
        /** @var SurveyStudy $study */
        $study = $this->runUnit->surveyStudy;

        if (!$this->db->table_exists($study->results_table)) {
            alert('A results table for this survey could not be found', 'alert-danger');
            throw new Exception("Results table '{$this->results_table}' not found!");
        }

        $entry = array(
            'session_id' => $this->id,
            'study_id' => $study->id,
        );
        if (!$this->db->entry_exists($study->results_table, $entry)) {
            $entry['created'] = mysql_now();
            $this->db->insert($study->results_table, $entry);
            
            $this->result = 'survey_started';
            $this->logResult();
        } else {
            $this->db->update($study->results_table, array('modified' => mysql_now()), $entry);
        }

        if (!$this->hasOrderedStudyItems()) {
            // get the definition of the order
            list($item_ids, $item_types) = $study->getOrderedItemsIds();

            // define paramers to bind parameters
            $display_order = null;
            $item_id = null;
            $page = 1;
            $created = mysql_datetime();

            $values = '';
            $valuesCount = 0;
            $valuesMax = 60;
            $sql_tpl = "INSERT INTO `survey_items_display` (`item_id`, `session_id`, `display_order`, `page`, `created`)  VALUES %s ON DUPLICATE KEY UPDATE `display_order` = VALUES(`display_order`), `page` = VALUES(`page`)";
            $lastId = end($item_ids);

            foreach ($item_ids as $display_order => $item_id) {
                $values .= '(' . $item_id . ',' . $this->id . ',' . $display_order . ',' . $page . ',' . $this->db->quote($created) . '),';
                $valuesCount++;
                if (($valuesCount >= $valuesMax) || ($item_id == $lastId && $values)) {
                    $query = sprintf($sql_tpl, trim($values, ','));
                    $this->db->query($query);
                    $values = '';
                    $valuesCount = 0;
                }

                //$survey_items_display->execute();
                // set page number when submit button is hit or we reached max_items_per_page for survey
                if ($item_types[$item_id] === 'submit') {
                    $page++;
                }
            }
        }
    }

    /**
     * Save posted survey data to database
     *
     * @param array $posted An array of posted answers
     * @param bool $validate Should items be validated before posted?
     * 
     * @return boolean Returns TRUE if all data was successfully validated and saved or FALSE otherwise
     * @throws Exception
     */
    public function updateSurveyStudyRecord($posted, $validate = true) {
        /** @var SurveyStudy $study */
        $study = $this->runUnit->surveyStudy;

        // remove variables user is not allowed to overrite (they should not be sent to user in the first place if not used in request)
        unset($posted['id'], $posted['session'], $posted['session_id'], $posted['study_id'], $posted['created'], $posted['modified'], $posted['ended']);

        if (!$posted) {
            return false;
        }

        if (isset($posted["_item_views"]["shown"])) {
            $posted["_item_views"]["shown"] = array_filter($posted["_item_views"]["shown"]);
            $posted["_item_views"]["shown_relative"] = array_filter($posted["_item_views"]["shown_relative"]);
            $posted["_item_views"]["answered"] = array_filter($posted["_item_views"]["answered"]);
            $posted["_item_views"]["answered_relative"] = array_filter($posted["_item_views"]["answered_relative"]);
        }

        /**
         * The concept of 'save all possible data' is not so correct
         * ALL data on current page must valid before any database operation or saves are made
         * This should help avoid inconsistencies or having notes and headings spread across pages
         */
        // Get items from database that are related to what is being posted
        $items = $study->getItemsWithChoices(null, array(
            'field' => 'name',
            'values' => array_keys($posted),
        ));

        // Validate items and if any fails return user to same page with all unansered items and error messages
        // This loop also accumulates potential update data
        $update_data = array();
        foreach ($posted as $item_name => $item_value) {
            if (!isset($items[$item_name])) {
                continue;
            }

            /** @var $item Item */
            if ($item_value instanceof Item) {
                $item = $item_value;
                $item_value = $item->value_validated;
            } else {
                $item = $items[$item_name];
            }

            $validInput = ($validate && !$item->skip_validation) ? $item->validateInput($item_value) : $item_value;
            if ($item->save_in_results_table) {
                if ($item->error) {
                    $this->errors[$item_name] = $item->error;
                } else {
                    $update_data[$item_name] = $item->getReply($validInput);
                }
                $item->value_validated = $item_value;
                $items[$item_name] = $item;
            }
        }

        if (!empty($this->errors)) {
            $this->validatedStudyItems = $items;
            return false;
        }

        $survey_items_display = $this->db->prepare(
                "UPDATE `survey_items_display` SET 
				created = COALESCE(created,NOW()),
				answer = :answer, 
				saved = :saved,
				shown = :shown,
				shown_relative = :shown_relative,
				answered = :answered,
				answered_relative = :answered_relative,
				displaycount = COALESCE(displaycount,1),
				hidden = :hidden
			WHERE item_id = :item_id AND session_id = :session_id"); # fixme: displaycount starts at 2
        $survey_items_display->bindParam(":session_id", $this->id);

        try {
            $this->db->beginTransaction();

            // update item_display table for each posted item using prepared statement
            foreach ($posted as $name => $value) {
                if (!isset($items[$name])) {
                    continue;
                }

                /* @var $item Item */
                if ($value instanceof Item) {
                    $item = $value;
                    $value = $item->value_validated;
                } else {
                    $item = $items[$name];
                }

                if (isset($posted["_item_views"]["shown"][$item->id], $posted["_item_views"]["shown_relative"][$item->id])) {
                    $shown = $posted["_item_views"]["shown"][$item->id];
                    $shown_relative = $posted["_item_views"]["shown_relative"][$item->id];
                } else {
                    $shown = mysql_now();
                    $shown_relative = null; // and where this is null, performance.now wasn't available
                }

                if (isset($posted["_item_views"]["answered"][$item->id], // separately to "shown" because of items like "note"
                                $posted["_item_views"]["answered_relative"][$item->id])) {
                    $answered = $posted["_item_views"]["answered"][$item->id];
                    $answered_relative = $posted["_item_views"]["answered_relative"][$item->id];
                } else {
                    $answered = $shown; // this way we can identify items where JS time failed because answered and show time are exactly identical
                    $answered_relative = null;
                }

                $survey_items_display->bindValue(":item_id", $item->id);
                $survey_items_display->bindValue(":answer", $item->getReply($value));
                $survey_items_display->bindValue(":hidden", $item->skip_validation ? (int) $item->hidden : 0); // an item that was answered has to have been shown
                $survey_items_display->bindValue(":saved", mysql_now());
                $survey_items_display->bindParam(":shown", $shown);
                $survey_items_display->bindParam(":shown_relative", $shown_relative);
                $survey_items_display->bindParam(":answered", $answered);
                $survey_items_display->bindParam(":answered_relative", $answered_relative);
                $item_answered = $survey_items_display->execute();

                if (!$item_answered) {
                    throw new Exception("Survey item '$name' could not be saved with value '$value' in table '{$study->results_table}'");
                }

            } //endforeach
            // Update results table in one query
            if ($update_data) {
                $update_where = array(
                    'study_id' => $study->id,
                    'session_id' => $this->id,
                );
                $this->db->update($study->results_table, $update_data, $update_where);
            }
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            notify_user_error($e, 'An error occurred while trying to save your survey data. Please notify the author of this survey with this date and time');
            formr_log_exception($e, __CLASS__);
            //$redirect = false;
            return false;
        }
        
    }

    public function isExecutedByCron() {
        return $this->runSession->isCron();
    }

    /**
     * Get data associated with this unit sessoin based on query text
     *
     * @param string $q Query text to search for variables
     * @param string $required
     * @return array
     */
    public function getRunData($q, $required = null) {
        $runSession = $this->runSession;
        $cache_key = Cache::makeKey(__METHOD__, $q, $required, $this->id, $runSession->id);
        if (($data = Cache::get($cache_key))) {
            return $data;
        }

        $needed = $this->getRunDataNeeded($q, $required);
        $surveys = $needed['matches'];
        $results_tables = $needed['matches_results_tables'];
        $matches_variable_names = $needed['matches_variable_names'];
        $datasets = ['datasets' => []];

        foreach ($surveys as $study_id => $survey_name) {
            if (isset($datasets['datasets'][$survey_name])) {
                continue;
            }

            $results_table = $results_tables[$survey_name];
            $variables = [];
            if (empty($matches_variable_names[$survey_name])) {
                $variables[] = "NULL AS formr_dummy";
            } else {
                if ($results_table === "survey_unit_sessions") {
                    if (($key = array_search('position', $matches_variable_names[$survey_name])) !== false) {
                        unset($matches_variable_names[$survey_name][$key]);
                        $variables[] = '`survey_run_units`.`position`';
                    }
                    if (($key = array_search('type', $matches_variable_names[$survey_name])) !== false) {
                        unset($matches_variable_names[$survey_name][$key]);
                        $variables[] = '`survey_units`.`type`';
                    }
                }

                if (!empty($matches_variable_names[$survey_name])) {
                    foreach ($matches_variable_names[$survey_name] as $k => $v) {
                        $variables[] = DB::quoteCol($v, $results_table);
                    }
                }
            }

            $variables = implode(', ', $variables);
            $select = "SELECT $variables";

            if (($runSession->id === null || $runSession->isTestingStudy()) && !in_array($results_table, get_db_non_session_tables())) { // todo: what to do with session_id tables in faketestrun
                $where = " WHERE `$results_table`.session_id = :session_id"; // just for testing surveys
            } else {
                $where = " WHERE  `survey_run_sessions`.id = :run_session_id";
                if ($survey_name === "externals") {
                    $where .= " AND `survey_units`.`type` = 'External'";
                }
            }

            if (!in_array($results_table, get_db_non_session_tables())) {
                $joins = "
					LEFT JOIN `survey_unit_sessions` ON `$results_table`.session_id = `survey_unit_sessions`.id
					LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
				";
            } elseif ($results_table == 'survey_unit_sessions') {
                $joins = "LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
				LEFT JOIN `survey_units` ON `survey_unit_sessions`.unit_id = `survey_units`.id
				LEFT JOIN `survey_run_units` ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
				LEFT JOIN `survey_runs` ON `survey_runs`.id = `survey_run_units`.run_id
				";
                $where .= " AND `survey_runs`.id = :run_id";
            } elseif ($results_table == 'survey_run_sessions') {
                $joins = "";
            } elseif ($results_table == 'survey_users') {
                $joins = "LEFT JOIN `survey_run_sessions` ON `survey_users`.id = `survey_run_sessions`.user_id";
            }

            $select .= " FROM `$results_table` ";

            $q = $select . $joins . $where . ";";

            $get_results = $this->db->prepare($q);
            if ($runSession->id === null|| $runSession->isTestingStudy()) {
                $get_results->bindValue(':session_id', $this->id);
            } else {
                $get_results->bindValue(':run_session_id', $runSession->id);
            }
            if ($results_table == 'survey_unit_sessions') {
                $get_results->bindValue(':run_id', $this->runSession->getRun()->id);
            }
            $get_results->execute();

            $datasets['datasets'][$survey_name] = array();
            while ($res = $get_results->fetch(PDO::FETCH_ASSOC)) {
                foreach ($res AS $var => $val) {
                    if (!isset($datasets['datasets'][$survey_name][$var])) {
                        $datasets['datasets'][$survey_name][$var] = array();
                    }
                    $datasets['datasets'][$survey_name][$var][] = $val;
                }
            }
        }

        if (!empty($needed['variables'])) {
            if (in_array('formr_last_action_date', $needed['variables']) || in_array('formr_last_action_time', $needed['variables'])) {
                $datasets['.formr$last_action_date'] = "NA";
                $datasets['.formr$last_action_time'] = "NA";
                $last_action = $this->db->execute(
                        "SELECT `survey_unit_sessions`.`created` FROM `survey_unit_sessions` 
					LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
					WHERE `survey_run_sessions`.id  = :run_session_id AND `unit_id` = :unit_id AND `survey_unit_sessions`.`ended` IS NULL LIMIT 1", array('run_session_id' => $runSession->id, 'unit_id' => $this->runUnit->id), true
                );
                if ($last_action !== false) {
                    $last_action_time = strtotime($last_action);
                    if (in_array('formr_last_action_date', $needed['variables'])) {
                        $datasets['.formr$last_action_date'] = "as.POSIXct('" . date("Y-m-d", $last_action_time) . "')";
                    }
                    if (in_array('formr_last_action_time', $needed['variables'])) {
                        $datasets['.formr$last_action_time'] = "as.POSIXct('" . date("Y-m-d H:i:s T", $last_action_time) . "')";
                    }
                }
            }

            if (in_array('formr_login_link', $needed['variables'])) {
                $datasets['.formr$login_link'] = "'" . run_url($runSession->getRun()->name, null, array('code' => $this->runSession->session)) . "'";
            }
            if (in_array('formr_login_code', $needed['variables'])) {
                $datasets['.formr$login_code'] = "'" . $this->runSession->session . "'";
            }
            if (in_array('user_id', $needed['variables'])) {
                $datasets['user_id'] = "'" . $this->runSession->session . "'";
            }
            if (in_array('formr_nr_of_participants', $needed['variables'])) {
                $count = (int) $this->db->count('survey_run_sessions', array('run_id' => $runSession->getRun()->id), 'id');
                $datasets['.formr$nr_of_participants'] = (int) $count;
            }
            if (in_array('formr_session_last_active', $needed['variables']) && $runSession->id) {
                $last_access = $this->db->findValue('survey_run_sessions', array('id' => $runSession->id), 'last_access');
                if ($last_access) {
                    $datasets['.formr$session_last_active'] = "as.POSIXct('" . date("Y-m-d H:i:s T", strtotime($last_access)) . "')";
                }
            }
        }

        if ($needed['token_add'] !== null && !isset($datasets['datasets'][$needed['token_add']])) {
            $datasets['datasets'][$needed['token_add']] = [];
        }

        Cache::set($cache_key, $datasets);
        return $datasets;
    }

    protected function getRunDataNeeded($q, $token_add = null) {
        $matches_variable_names = $variable_names_in_table = $matches = $matches_results_tables = $results_tables = $tables = array();

//		$results = $this->run->getAllLinkedSurveys(); // fixme -> if the last reported email thing is known to work, we can turn this on
        $surveys = $this->runSession->getRun()->getAllSurveys();

        // also add some "global" formr tables
        $nu_tables = get_db_non_user_tables();
        $non_user_tables = array_keys($nu_tables);
        $tables = $non_user_tables;
        $table_ids = $non_user_tables;
        $results_tables = array_combine($non_user_tables, $non_user_tables);
        if (isset($results_tables['externals'])) {
            $results_tables['externals'] = 'survey_unit_sessions';
        }

        if ($token_add !== null) {  // send along this table if necessary, always as the first one, since we attach it
            $study = $this->runUnit->surveyStudy;
            $table_ids[] = $study->id;
            $tables[] = $study->name;
            $results_tables[$study->name] = $study->results_table;
        }

        // map table ID to the name that the user sees (because tables in the DB are prefixed with the user ID, so they're unique)
        foreach ($surveys as $res) {
            if ($res['name'] !== $token_add) {
                $table_ids[] = $res['id'];
                $tables[] = $res['name']; // FIXME: ID can overwrite the non_user_tables
                $results_tables[$res['name']] = $res['results_table'];
            }
        }

        foreach ($tables as $index => $table_name) {
            $study_id = $table_ids[$index];

            // For preg_match, study name appears as word, matches nrow(survey), survey$item, survey[row,], but not survey_2
            if ($table_name == $token_add OR preg_match("/\b$table_name\b/", $q)) {
                $matches[$study_id] = $table_name;
                $matches_results_tables[$table_name] = $results_tables[$table_name];
            }
        }

        // loop through any studies that are mentioned in the command
        foreach ($matches as $study_id => $table_name) {

            // generate a search set of variable names for each study
            if (array_key_exists($table_name, $nu_tables)) {
                $variable_names_in_table[$table_name] = $nu_tables[$table_name];
            } else {
                $items = $this->db->select('name')->from('survey_items')
                        ->where(['study_id' => $study_id])
                        ->where("type NOT IN ('mc_heading', 'note', 'submit', 'block', 'note_iframe')")
                        ->fetchAll();

                $variable_names_in_table[$table_name] = array("created", "modified", "ended"); // should avoid modified, sucks for caching
                foreach ($items as $res) {
                    $variable_names_in_table[$table_name][] = $res['name']; // search set for user defined tables
                }
            }

            $matches_variable_names[$table_name] = array();
            // generate match list for variable names
            foreach ($variable_names_in_table[$table_name] as $variable_name) {
                // try to match scales too, extraversion_1 + extraversion_2 - extraversion_3R - extraversion_4r = extraversion (script might mention the construct name, but not its item constituents)
                $variable_name_base = preg_replace("/_?[0-9]{1,3}R?$/i", "", $variable_name);
                // don't match very short variable name bases
                if (strlen($variable_name_base) < 3) {
                    $variable_name_base = $variable_name;
                }
                // item name appears as word, matches survey$item, survey[, "item"], but not item_2 for item-scale unfortunately
                if (preg_match("/\b$variable_name\b/", $q) || preg_match("/\b$variable_name_base\b/", $q)) {
                    $matches_variable_names[$table_name][] = $variable_name;
                }
            }
        }

        $variables = opencpu_formr_variables($q);

        return compact("matches", "matches_results_tables", "matches_variable_names", "token_add", "variables");
    }

}
