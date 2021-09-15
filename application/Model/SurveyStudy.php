<?php

//fixme:
// attack vector against unlinked surveys
// - delete user data one by one, see which one disappears
// - calculate survey_run_sessions$session in the unlinked surveys
// - calculate private data from unlinked survey in linked survey
// - check user_overview to see which users have already made it to the unlinked survey
class SurveyStudy extends Model {

    public $id = null;
    public $user_id = null;
    public $name = null;
    public $results_table = null;
    public $valid = null;

    // Settings
    public $maximum_number_displayed = 0;
    public $displayed_percentage_maximum = 100;
    public $add_percentage_points = 0;
    public $expire_after = 0;
    public $expire_invitation_after = 0;
    public $expire_invitation_grace = 0;
    public $enable_instant_validation = 0;
    public $original_file = '';
    public $google_file_id = '';
    public $unlinked = 0;
    public $hide_results = 0;
    public $use_paging = 0;

    public $created = null;
    public $modified = null;
    
    protected $valid_name_pattern = "/[a-zA-Z][a-zA-Z0-9_]{2,64}/";
    
    protected $table = 'survey_studies';

    private $result_count = null;
    
    protected $user_defined_columns = [
        'name', 'label', 'label_parsed', 'type', 'type_options', 'choice_list', 
        'optional', 'class', 'showif', 'value', 'block_order', 'item_order', 'order',
        // study_id is not among the user_defined columns
    ];
    
    protected $can_delete = false;
    protected $is_new = false;

    /*
    public $run_name = null;
    public $items = array();
    public $items_validated = array();
    public $session = null;
    
    public $run_session_id = null;
    public $settings = array();
    public $valid = false;
    public $public = false;
    public $errors = array();
    public $validation_errors = array();
    public $messages = array();
    public $warnings = array();
    public $position;
    public $rendered_items = array();
    private $SPR;
    public $openCPU = null;
    
    private $confirmed_deletion = false;
    private $created_new = false;
    public $item_factory = null;
    public $unanswered = array();
    public $to_render = array();
    public $study_name_pattern = "/[a-zA-Z][a-zA-Z0-9_]{2,64}/";
    private $result_count = null;
     */

    /**
     * Counts for progress computation
     * @var int {collection}
     */
    /*
    public $progress = 0;
    public $progress_counts = array(
        'already_answered' => 0,
        'not_answered' => 0,
        'hidden_but_rendered' => 0,
        'not_rendered' => 0,
        'visible_on_current_page' => 0,
        'hidden_but_rendered_on_current_page' => 0,
        'not_answered_on_current_page' => 0
    );
     * */


    /**
     * You can initiate a survey by using the ID or passing a couple of options to be used in finding the survey
     *
     * @param int $id
     * @param array $options
     */
    public function __construct($id = null, $options = []) {
        parent::__construct();
        $this->assignProperties($options);
        $this->load($id, $options);
    }

    /**
     * Get survey by id
     *
     * @param int $id
     * @return SurveyStudy
     */
    public static function loadById($id) {
        return new SurveyStudy((int) $id);
    }

    /**
     * Get survey by name
     *
     * @param string $name
     * @return SurveyStudy
     */
    public static function loadByName($name) {
        $options = ['name' => $name];
        return new SurveyStudy(null, $options);
    }

    /**
     * 
     * @param User $user
     * @param string $name
     * @return \SurveyStudy
     */
    public static function loadByUserAndName(User $user, $name) {
        $options = [
            'name' => $name,
            'user_id' => $user->id,
        ];
        return new SurveyStudy(null, $options);
    }

    protected function load($id, $options) {
        if (!$options || !is_array($options)) {
            $options = [];
        }
        
        if ($id) {
            $options['id'] = (int) $id;
        }

        if ($options && ($vars = $this->db->findRow('survey_studies', $options))) {
            $this->assignProperties($vars);
            if (!$this->results_table) {
                $this->results_table = $this->name;
            }
            
            $this->valid = true;
        }
    }
 
    /**
     * 
     * @param array $file
     *
     * @return boolean
     */
    public function createFromFile($file) {
        // Create the corresponding entry in survey_units to get the ID
        $id = RunUnitFactory::make(new Run(), ['type' => 'Survey'])->create()->id;
        $this->assignProperties($file);
        
        $this->id = $id;
        $this->name = preg_filter("/^([a-zA-Z][a-zA-Z0-9_]{2,64})(-[a-z0-9A-Z]+)?\.[a-z]{3,4}$/", "$1", basename($file['name']));
        $this->results_table = substr("s" . $this->id . '_' . $this->name, 0, 64);
        $this->created = mysql_now();
        $this->modified = mysql_now();
        $this->user_id = Site::getCurrentUser()->id;

        $results_table = substr("s" . $this->id . '_' . $this->name, 0, 64);

        if ($this->db->table_exists($results_table)) {
            alert("Results table name conflict. This shouldn't happen. Please alert the formr admins.", 'alert-danger');
            return false;
        }
        $this->results_table = $results_table;
        $this->save();

        return true;
    }
    
    public function uploadItems($file, $can_delete = false, $is_new = false) {
        umask(0002);
        ini_set('memory_limit', Config::get('memory_limit.survey_upload_items'));
        
        $filepath = $file['tmp_name'];
        $filename = $file['name'];
        $this->can_delete = $can_delete;
        $this->is_new = $is_new;

        $this->messages[] = "Items sheet ({$filename}) uploaded to survey <b>{$this->name}</b>.";

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9_]{2,64})(-[a-z0-9A-Z]+)?\.json$/', $filepath)) {
            // upload via JSON
            $reader = new JsonReader();
        } else {
            // upload via Spreadsheet
            $reader = new SpreadsheetReader();
        }

        if (!$reader || !is_object($reader)) {
            alert('Spreadsheet object could not be created!', 'alert-danger');
            return false;
        }

        $reader->readItemTableFile($filepath);
        
        $this->errors = array_merge($this->errors, $reader->errors);
        $this->warnings = array_unique(array_merge($this->warnings, $reader->warnings));
        $this->messages = array_unique(array_merge($this->messages, $reader->messages));

        // if items are ok, make actual survey
        if (empty($this->errors) && $this->saveUploadedItemsFromReader($reader)) {
            if (!empty($this->warnings)) {
                alert('<ul><li>' . implode("</li><li>", $this->warnings) . '</li></ul>', 'alert-warning');
            }

            if (!empty($this->messages)) {
                alert('<ul><li>' . implode("</li><li>", $this->messages) . '</li></ul>', 'alert-info');
            }

            // save original survey sheet
            $filename = 'formr-survey-' . Site::getCurrentUser()->id . '-' . $filename;
            $file = Config::get('survey_upload_dir') . '/' . $filename;
            
            if (file_exists($filepath) && (move_uploaded_file($filepath, $file) || rename($filepath, $file))) {
                $this->original_file = $filename;
                $this->modified = mysql_datetime();
                $this->save();
            } else {
                alert('Unable to save original uploaded file', 'alert-warning');
            }

            return true;
        } else {
            alert('<ul><li>' . implode("</li><li>", $this->errors) . '</li></ul>', 'alert-danger');
            return false;
        }
    }

    protected function resultsTableExists() {
        return $this->db->table_exists($this->results_table);
    }
    
    protected function toArray() {
        return [
           'id' => $this->id, 
           'user_id' => $this->user_id,
           'name' => $this->name,
           'results_table' => $this->results_table,
           'valid' => $this->valid,
           'maximum_number_displayed' => $this->maximum_number_displayed, 
           'displayed_percentage_maximum' => $this->displayed_percentage_maximum,
           'add_percentage_points' => $this->add_percentage_points,
           'expire_after' => $this->expire_after,
           'expire_invitation_after' => $this->expire_invitation_after,
           'expire_invitation_grace' => $this->expire_invitation_grace,
           'enable_instant_validation' => $this->enable_instant_validation,
           'original_file' => $this->original_file,
           'google_file_id' => $this->google_file_id, 
           'unlinked' => $this->unlinked,
           'hide_results' => $this->hide_results,
           'created' => $this->created,
           'modified' => $this->modified,
        ];
    }

    /**
     * Get All choice lists in this survey with associated items
     *
     * @param array $specific An array if list_names which if defined, only lists specified in the array will be returned
     * @param string $label
     * @return $array Returns an array indexed by list name;
     */
    public function getChoices($specific = null, $label = 'label') {
        $select = $this->db->select('list_name, name, label, label_parsed');
        $select->from('survey_item_choices');
        $select->where(array('study_id' => $this->id));

        if (!$specific && $specific !== null) {
            return array();
        } elseif ($specific !== null) {
            $select->whereIn('list_name', $specific);
        }
        $select->order('id', 'ASC');
        
        $lists = array();
        $stmt = $select->statement();

        // If we are not hunting for a particular field name return list as is
        if (!$label) {
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($lists[$row['list_name']])) {
                $lists[$row['list_name']] = array();
            }
            $lists[$row['list_name']][$row['name']] = $row[$label];
        }

        return $lists;
    }

    public function getChoicesForSheet() {
        return $this->db->select('list_name, name, label')
                        ->from('survey_item_choices')
                        ->where(array('study_id' => $this->id))
                        ->order('id', 'ASC')->fetchAll();
    }

    /**
     * Create a new survey using items uploaded by the spreadsheet reader
     * Cases:
     * 0. new creation -> do it
     * 1. is the upload identical to the existing -> do nothing
     * 2. are there any results at all? -> nuke existing shit, do fresh start
     * 3. does the upload entail text/minor changes to the item table -> simply do changes
     * 4. does the upload entail changes to results (i.e. will there be deletion) -> confirm deletion
     *    4a. deletion confirmed: backup results, modify results table, drop stuff
     *    4b. deletion not confirmed: cancel the whole shit.
     * 
     * @param SpreadsheetReader|JsonReader $reader
     * @return boolean
     */
    protected function saveUploadedItemsFromReader($reader) {

        // Get old choice lists for getting old items
        $choiceLists = $this->getChoices();
        $itemFactory = new ItemFactory($choiceLists);

        // Get old items, mark them as false meaning all are vulnerable for delete.
        // When the loop over survey items ends you will know which should be deleted.
        $old_items = array();
        $old_items_in_results = array();

        foreach ($this->getItems() as $item) {
            if (($object = $itemFactory->make($item)) !== false) {
                $old_items[$item['name']] = $object->getResultField();
                if ($object->isStoredInResultsTable()) {
                    $old_items_in_results[] = $item['name'];
                }
            }
        }

        try {
            
            $this->db->beginTransaction();
            $data = $this->addItems($reader);
            $new_items = $data['new_items'];
            $result_columns = $data['result_columns'];

            $added = array_diff_assoc($new_items, $old_items);
            $deleted = array_diff_assoc($old_items, $new_items);
            $unused = $itemFactory->unusedChoiceLists();
            
            if ($unused) {
                $this->warnings[] = __("These choice lists were not used: '%s'", implode("', '", $unused));
            }

            // If there are items to delete, check if user confirmed deletion and if so check if back up succeeded
            if (count($deleted) > 0) {
                if ($this->hasRealData() && !$this->can_delete) {
                    $deleted_columns_string = implode(", ", array_keys($deleted));
                    $this->errors[] = "<strong>No permission to delete data</strong>. Enter the survey name, if you are okay with data being deleted from the following items: " . $deleted_columns_string;
                }
                if ($this->hasRealData() && $this->can_delete && !$this->backupResults($old_items_in_results)) {
                    $this->errors[] = "<strong>Back up failed.</strong> Deletions would have been necessary, but backing up the item table failed, so no modification was carried out.</strong>";
                }
            }

            // If there are errors at this point then terminate to rollback all changes inluding adding choices and inserting items
            if (!empty($this->errors)) {
                throw new Exception('Process terminated prematurely due to errors');
            }

            $actually_deleted = array_diff(array_keys($deleted), array_keys($added));
            if ($deleted && $actually_deleted) {
                // some items were just re-typed, they only have to be deleted from the wide format table which has inflexible types
                $toDelete = implode(',', array_map(array($this->db, 'quote'), $actually_deleted));
                $studyId = (int) $this->id;
                $delQ = "DELETE FROM survey_items WHERE `name` IN ($toDelete) AND study_id = $studyId";
                $this->db->query($delQ);
            }

            // we start fresh if it's a new creation, no results table exist or it is completely empty
            if ($this->is_new || !$this->resultsTableExists() || !$this->hasData()) {
                if ($this->is_new && $this->resultsTableExists()) {
                    throw new Exception("Results table name conflict. This shouldn't happen. Please alert the formr admins");
                }
                // step 2
                $this->messages[] = "The results table was newly created, because there were no results and test sessions.";
                // if there is no results table or no existing data at all, drop table, create anew
                // i.e. where possible prefer nuclear option
                $new_syntax = $this->getResultsTableSyntax($result_columns);
                if (!$this->createResultsTable($new_syntax)) {
                    throw new Exception('Unable to create a data table for survey results');
                }
            } else {
                // this will never happen if deletion was not confirmed, because this would raise an error
                // 2 and 4a
                $merge = $this->alterResultsTable($added, $deleted);
                if (!$merge) {
                    throw new Exception('Required modifications could not be made to the survey results table');
                }
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->errors[] = 'Error: ' . $e->getMessage();
            formr_log_exception($e, __CLASS__, $this->errors);
            return false;
        }
    }

    /**
     * Prepares the statement to insert new items and returns an associative containing
     * the SQL definition of the new items and the new result columns
     *
     * @param SpreadsheetReader|JsonReader $reader
     * @return array array(new_items, result_columns)
     */
    protected function addItems($reader) {
        // Save new choices and re-build the item factory
        $this->addChoices($reader);
        $choice_lists = $this->getChoices();
        $itemFactory = new ItemFactory($choice_lists);
        
        $definedColumns = [
            'name', 'label', 'label_parsed', 'type', 'type_options', 'choice_list', 
            'optional', 'class', 'showif', 'value', 'block_order', 'item_order', 'order',
            // study_id is not among the user_defined columns
        ];

        // Prepare SQL statement for adding items
        $UPDATES = implode(', ', get_duplicate_update_string($definedColumns));
        $addStmt = $this->db->prepare(
            "INSERT INTO `survey_items` (study_id, name, label, label_parsed, type, type_options, choice_list, optional, class, showif, value, `block_order`,`item_order`, `order`) 
			VALUES (:study_id, :name, :label, :label_parsed, :type, :type_options, :choice_list, :optional, :class, :showif, :value, :block_order, :item_order, :order) 
			ON DUPLICATE KEY UPDATE $UPDATES");
        $addStmt->bindParam(":study_id", $this->id);

        $ret = array(
            'new_items' => array(),
            'result_columns' => array(),
        );

        foreach ($reader->survey as $row_number => $row) {
            $item = $itemFactory->make($row);
            if (!$item) {
                $this->errors[] = __("Row %s: Type %s is invalid.", $row_number, array_val($reader->survey[$row_number], 'type'));
                unset($reader->survey[$row_number]);
                continue;
            }

            $val_results = $item->validate();
            if (!empty($val_results['val_errors'])) {
                $this->errors = $this->errors + $val_results['val_errors'];
                unset($reader->survey[$row_number]);
                continue;
            }
            if (!empty($val_results['val_warnings'])) {
                $this->warnings = $this->warnings + $val_results['val_warnings'];
            }

            // if the parsed label is constant or exists
            if (!knitting_needed($item->label) && !$item->label_parsed) {
                $markdown = $reader->parsedown->text($item->label);
                $item->label_parsed = $markdown;
                if (mb_substr_count($markdown, "</p>") === 1 AND preg_match("@^<p>(.+)</p>$@", trim($markdown), $matches)) {
                    $item->label_parsed = $matches[1];
                }
            }

            foreach ($definedColumns as $param) {
                $addStmt->bindValue(":$param", $item->$param);
            }

            $result_field = $item->getResultField();
            $ret['new_items'][$item->name] = $result_field;
            $ret['result_columns'][] = $result_field;

            $addStmt->execute();
        }

        return $ret;
    }

    public function getItemsWithChoices($columns = null, $whereIn = null) {
        if ($this->resultsTableExists()) {
            $choice_lists = $this->getChoices();
            $itemFactory = new ItemFactory($choice_lists);

            $raw_items = $this->getItems($columns, $whereIn);

            $items = array();
            foreach ($raw_items as $row) {
                $item = $itemFactory->make($row);
                $items[$item->name] = $item;
            }
            return $items;
        } else {
            return array();
        }
    }

    public function getItemsInResultsTable() {
		if (($existingColumns = $this->db->getTableDefinition($this->results_table, 'Field'))) {
			$existingColumns = array_keys($existingColumns);
		}

        $items = $this->getItems();
        $names = array();
        $itemFactory = new ItemFactory(array());
        foreach ($items as $item) {
            $item = $itemFactory->make($item);
            if ($item->isStoredInResultsTable() && in_array($item->name, $existingColumns)) {
                $names[] = $item->name;
            }
        }
        return $names;
    }

    private function addChoices($reader) {
        // delete cascades to item display ?? FIXME so maybe not a good idea to delete then
        $definedColumns = ['list_name', 'name', 'label', 'label_parsed'];
        $deleted = $this->db->delete('survey_item_choices', array('study_id' => $this->id));
        $addChoiceStmt = $this->db->prepare(
             'INSERT INTO `survey_item_choices` (study_id, list_name, name, label, label_parsed) 
			 VALUES (:study_id, :list_name, :name, :label, :label_parsed )'
        );
        $addChoiceStmt->bindParam(":study_id", $this->id);

        foreach ($reader->choices as $choice) {
            $choice['label_parsed'] = null;

            if (isset($choice['list_name']) && isset($choice['name']) && isset($choice['label'])) {
                if (!knitting_needed($choice['label']) && empty($choice['label_parsed'])) { // if the parsed label is constant
                    $markdown = $reader->parsedown->text($choice['label']); // transform upon insertion into db instead of at runtime
                    $choice['label_parsed'] = $markdown;
                    if (mb_substr_count($markdown, "</p>") === 1 AND preg_match("@^<p>(.+)</p>$@", trim($markdown), $matches)) {
                        $choice['label_parsed'] = $matches[1];
                    }
                }

                foreach ($definedColumns as $param) {
                    $addChoiceStmt->bindValue(":$param", $choice[$param]);
                }
                $addChoiceStmt->execute();
            }
        }

        return true;
    }

    private function getResultsTableSyntax($columns) {
        $columns = array_filter($columns); // remove null, false, '' values (note, fork, submit, ...)

        if (empty($columns)) {
            $columns_string = ''; # create a results tabel with only the access times
        } else {
            $columns_string = implode(",\n", $columns) . ",";
        }

        $create = "
		CREATE TABLE `{$this->results_table}` (
		  `session_id` INT UNSIGNED NOT NULL ,
		  `study_id` INT UNSIGNED NOT NULL ,
		  `created` DATETIME NULL DEFAULT NULL ,
		  `modified` DATETIME NULL DEFAULT NULL ,
		  `ended` DATETIME NULL DEFAULT NULL ,
	
		  $columns_string

		  PRIMARY KEY (`session_id`) ,
		  INDEX `idx_survey_results_survey_studies` (`study_id` ASC) ,
		  INDEX `idx_ending` (`session_id` DESC, `study_id` ASC, `ended` ASC) ,
		  CONSTRAINT
		    FOREIGN KEY (`session_id` )
		    REFERENCES `survey_unit_sessions` (`id` )
		    ON DELETE CASCADE
		    ON UPDATE NO ACTION,
		  CONSTRAINT
		    FOREIGN KEY (`study_id` )
		    REFERENCES `survey_studies` (`id` )
		    ON DELETE NO ACTION
		    ON UPDATE NO ACTION)
		ENGINE = InnoDB";
        return $create;
    }

    private function createResultsTable($syntax) {
        if ($this->deleteResults()) {
            $drop = $this->db->query("DROP TABLE IF EXISTS `{$this->results_table}` ;");
            $drop->execute();
        } else {
            return false;
        }

        $create_table = $this->db->query($syntax);
        if ($create_table) {
            return true;
        }
        return false;
    }

    public function getItems($columns = null, $whereIn = null) {
        if ($columns === null) {
            $columns = "id, study_id, type, choice_list, type_options, name, label, label_parsed, optional, class, showif, value, block_order,item_order";
        }

        $select = $this->db->select($columns);
        $select->from('survey_items');
        $select->where(array('study_id' => $this->id));
        if ($whereIn) {
            $select->whereIn($whereIn['field'], $whereIn['values']);
        }
        $select->order("item_order");
        return $select->fetchAll();
    }

    public function getItemsForSheet() {
        $get_items = $this->db->select('type, type_options, choice_list, name, label, optional, class, showif, value, block_order, item_order')
                ->from('survey_items')
                ->where(array('study_id' => $this->id))
                ->order("`survey_items`.order")
                ->statement();

        $results = array();
        while ($row = $get_items->fetch(PDO::FETCH_ASSOC)) {
            $row["type"] = $row["type"] . " " . $row["type_options"] . " " . $row["choice_list"];
            unset($row["choice_list"], $row["type_options"]); //FIXME: why unset here?
            $results[] = $row;
        }

        return $results;
    }

    public function getResults($items = null, $filter = null, array $paginate = null, $runId = null, $rstmt = false) {
        if ($this->resultsTableExists()) {
            ini_set('memory_limit', Config::get('memory_limit.survey_get_results'));

            $results_table = $this->results_table;
            if (!$items) {
                $items = $this->getItemsInResultsTable();
            }

            $count = $this->getResultCount();
            $get_all = true;
            if ($this->unlinked && $count['real_users'] <= 10) {
                if ($count['real_users'] > 0) {
                    alert("<strong>You cannot see the real results yet.</strong> It will only be possible after 10 real users have registered.", 'alert-warning');
                }
                $get_all = false;
            }
            if ($this->unlinked) {
                $columns = array();
                // considered showing data for test sessions, but then researchers could set real users to "test" to identify them
                /* 				$columns = array(
                  "IF(survey_run_sessions.testing, survey_run_sessions.session, '') AS session",
                  "IF(survey_run_sessions.testing, `{$results_table}`.`created`, '') AS created",
                  "IF(survey_run_sessions.testing, `{$results_table}`.`modified`, '') AS modified",
                  "IF(survey_run_sessions.testing, `{$results_table}`.`ended`, '') AS ended",
                  );
                 */
            } else {
                $columns = array('survey_run_sessions.session', "`{$results_table}`.`created`", "`{$results_table}`.`modified`", "`{$results_table}`.`ended`, `survey_unit_sessions`.`expired`");
            }
            foreach ($items as $item) {
                $columns[] = "{$results_table}.{$item}";
            }

            $select = $this->db->select($columns)
                    ->from($results_table)
                    ->leftJoin('survey_unit_sessions', "{$results_table}.session_id = survey_unit_sessions.id")
                    ->leftJoin('survey_run_sessions', 'survey_unit_sessions.run_session_id = survey_run_sessions.id');
            if (!$get_all) {
                $select->where('survey_run_sessions.testing = 1');
            }

            if ($runId !== null) {
                $select->where("survey_run_sessions.run_id = {$runId}");
            }

            if ($paginate && isset($paginate['offset'])) {
                $order = isset($paginate['order']) ? $paginate['order'] : 'asc';
                $order_by = isset($paginate['order_by']) ? $paginate['order_by'] : '{$results_table}.session_id';
                if ($this->unlinked) {
                    $order_by = "RAND()";
                }
                $select->order($order_by, $order);
                $select->limit($paginate['limit'], $paginate['offset']);
            }

            if (!empty($filter['session'])) {
                $session = $filter['session'];
                strlen($session) == 64 ? $select->where("survey_run_sessions.session = '$session'") : $select->like('survey_run_sessions.session', $session, 'right');
            }

            if (!empty($filter['results']) && ($res_filter = $this->getResultsFilter($filter['results']))) {
                $res_where = Template::replace($res_filter['query'], array('table' => $results_table));
                $select->where($res_where);
            }

            $stmt = $select->statement();
            if ($rstmt === true) {
                return $stmt;
            }

            $results = array();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                unset($row['study_id']);
                $results[] = $row;
            }

            return $results;
        } else {
            return array();
        }
    }

    /**
     * Get Results from the item display table
     *
     * @param array $items An array of item names that are required in the survey
     * @param string $session If specified, only results of that particular session will be returned
     * @param array $paginate Pagination parameters [offset, limit]
     * @param boolean $rstmt If TRUE, PDOStament will be returned instead
     * @return array|PDOStatement
     */
    public function getItemDisplayResults($items = array(), $filter = null, array $paginate = null, $rstmt = false) {
        ini_set('memory_limit', Config::get('memory_limit.survey_get_results'));

        $count = $this->getResultCount();
        if ($this->settings['unlinked']) {
            if ($count['real_users'] > 0) {
                alert("<strong>You cannot see the long-form results yet.</strong> It will only be possible after 10 real users have registered.", 'alert-warning');
            }
            return array();
        }

        $select = $this->db->select("`survey_run_sessions`.session,
		`survey_items_display`.`session_id` as `unit_session_id`,
		`survey_items_display`.`item_id`,
		`survey_items`.`name` as `item_name`,
		`survey_items_display`.`answer`,
		`survey_items_display`.`created`,
		`survey_items_display`.`saved`,
		`survey_items_display`.`shown`,
		`survey_items_display`.`shown_relative`,
		`survey_items_display`.`answered`,
		`survey_items_display`.`answered_relative`,
		`survey_items_display`.`displaycount`,
		`survey_items_display`.`display_order`,
		`survey_items_display`.`hidden`");

        $select->from('survey_items_display')
                ->leftJoin('survey_unit_sessions', 'survey_unit_sessions.id = survey_items_display.session_id')
                ->leftJoin('survey_run_sessions', 'survey_run_sessions.id = survey_unit_sessions.run_session_id')
                ->leftJoin('survey_items', 'survey_items_display.item_id = survey_items.id')
                ->where('survey_items.study_id = :study_id')
                ->order('survey_run_sessions.session')
                ->order('survey_run_sessions.created')
                ->order('survey_unit_sessions.created')
                ->order('survey_items_display.display_order')
                ->bindParams(array('study_id' => $this->id));

        if ($items) {
            $select->whereIn('survey_items.name', $items);
        }

        $session = array_val($filter, 'session', null);
        if ($session) {
            if (strlen($session) == 64) {
                $select->where("survey_run_sessions.session = :session");
            } else {
                $select->where("survey_run_sessions.session LIKE :session");
                $session .= "%";
            }
            $select->bindParams(array("session" => $session));
        }

        if ($paginate && isset($paginate['offset'])) {
            $select->limit($paginate['limit'], $paginate['offset']);
        }

        if ($rstmt === true) {
            return $select->statement();
        }
        return $select->fetchAll();
    }

    public function getResultsByItemsPerSession($items = array(), $filter = null, array $paginate = null, $rstmt = false) {
        if ($this->settings['unlinked']) {
            return array();
        }
        ini_set('memory_limit', Config::get('memory_limit.survey_get_results'));

        $filter_select = $this->db->select('session_id');
        $filter_select->from($this->results_table);
        $filter_select->leftJoin('survey_unit_sessions', "{$this->results_table}.session_id = survey_unit_sessions.id");
        $filter_select->leftJoin('survey_run_sessions', 'survey_unit_sessions.run_session_id = survey_run_sessions.id');

        if (!empty($filter['session'])) {
            $session = $filter['session'];
            strlen($session) == 64 ? $filter_select->where("survey_run_sessions.session = '$session'") : $filter_select->like('survey_run_sessions.session', $session, 'right');
        }

        if (!empty($filter['results']) && ($res_filter = $this->getResultsFilter($filter['results']))) {
            $res_where = Template::replace($res_filter['query'], array('table' => $this->results_table));
            $filter_select->where($res_where);
        }
        $filter_select->order('session_id');
        if ($paginate && isset($paginate['offset'])) {
            $filter_select->limit($paginate['limit'], $paginate['offset']);
        }
        $stmt = $filter_select->statement();
        $session_ids = '';
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $session_ids .= "{$row['session_id']},";
        }
        $session_ids = trim($session_ids, ',');

        $select = $this->db->select("
		`survey_run_sessions`.session,
		`survey_items_display`.`session_id` as `unit_session_id`,
		`survey_items`.`name` as `item_name`,
		`survey_items_display`.`item_id`,
		`survey_items_display`.`answer`,
		`survey_items_display`.`created`,
		`survey_items_display`.`saved`,
		`survey_items_display`.`shown`,
		`survey_items_display`.`shown_relative`,
		`survey_items_display`.`answered`,
		`survey_items_display`.`answered_relative`,
		`survey_items_display`.`displaycount`,
		`survey_items_display`.`display_order`,
		`survey_items_display`.`hidden`");

        $select->from('survey_items_display')
                ->leftJoin('survey_unit_sessions', 'survey_unit_sessions.id = survey_items_display.session_id')
                ->leftJoin('survey_run_sessions', 'survey_run_sessions.id = survey_unit_sessions.run_session_id')
                ->leftJoin('survey_items', 'survey_items.id = survey_items_display.item_id')
                ->where('survey_items.study_id = :study_id')
                ->where('survey_items_display.session_id IN (' . $session_ids . ')')
                ->order('survey_items_display.session_id')
                ->order('survey_items_display.display_order')
                ->bindParams(array('study_id' => $this->id));

        if ($items) {
            $select->whereIn('survey_items.name', $items);
        }

        if ($rstmt === true) {
            return $select->statement();
        }
        return $select->fetchAll();
    }

    /**
     * Get Results from the item display table
     *
     * @param array $items An array of item names that are required in the survey
     * @param array $sessions If specified, only results of that particular session will be returned
     * @return array
     */
    public function getResultsByItemAndSession($items = array(), $sessions = null) {
        $select = $this->db->select('
		`survey_run_sessions`.session,
		`survey_items`.name,
		`survey_items_display`.answer');

        $select->from('survey_items_display')
                ->leftJoin('survey_unit_sessions', 'survey_unit_sessions.id = survey_items_display.session_id')
                ->leftJoin('survey_run_sessions', 'survey_run_sessions.id = survey_unit_sessions.run_session_id')
                ->leftJoin('survey_items', 'survey_items_display.item_id = survey_items.id')
                ->where('survey_items.study_id = :study_id')
                ->order('survey_run_sessions.session')
                ->order('survey_run_sessions.created')
                ->order('survey_unit_sessions.created')
                ->order('survey_items_display.display_order')
                ->bindParams(array('study_id' => $this->id));

        if (!empty($items)) {
            $select->whereIn('survey_items.name', $items);
        }

        if (!empty($sessions)) {
            $select->whereIn('survey_items.name', $sessions);
        }

        return $select->fetchAll();
    }

    protected function hasData() {
        $this->result_count = $this->getResultCount();
        if (($this->result_count["real_users"] + $this->result_count['testers']) > 0) {
            return true;
        } else {
            return false;
        }
    }

    protected function hasRealData() {
        $this->result_count = $this->getResultCount();
        if ($this->result_count["real_users"] > 1) {
            return true;
        } else {
            return false;
        }
    }

    public function deleteResults($run_id = null) {
        $this->result_count = $this->getResultCount($run_id);

        if (array_sum($this->result_count) === 0) {
            return true;
        } elseif ($run_id !== null) {
            //@todo implement deleting results only for a particular run
            $this->error[] = 'Deleting run specific results for a survey is not yet implemented';
            return false;
        } elseif ($this->backupResults()) {
            $delete = $this->db->query("TRUNCATE TABLE `{$this->results_table}`");
            $delete_item_disp = $this->db->delete('survey_unit_sessions', array('unit_id' => $this->id));
            return $delete && $delete_item_disp;
        } else {
            $this->errors[] = __("Backup of %s result rows failed. Deletion cancelled.", array_sum($this->result_count));
            return false;
        }
    }

    public function backupResults($itemNames = null) {
        $this->result_count = $this->getResultCount();
        if ($this->hasRealData()) {
            $this->messages[] = __("<strong>Backed up.</strong> The old results were backed up in a file (%s results)", array_sum($this->result_count));

            $filename = $this->results_table . date('YmdHis') . ".tab";
            if (isset($this->user_id)) {
                $filename = "user" . $this->user_id . $filename;
            }
            $filename = APPLICATION_ROOT . "tmp/backups/results/" . $filename;

            $SPR = new SpreadsheetReader();
            return $SPR->backupTSV($this->getResults($itemNames), $filename);
        } else { // if we have no real data, no need for backup
            return true;
        }
    }

    public function getResultCount($run_id = null, $filter = array()) {
        // If there is no filter and results have been saved in a previous operation then that
        if ($this->result_count !== null && !$filter) {
            return $this->result_count;
        }

        $count = array('finished' => 0, 'begun' => 0, 'testers' => 0, 'real_users' => 0);
        if ($this->resultsTableExists()) {
            $results_table = $this->results_table;
            $select = $this->db->select(array(
                        "SUM(`survey_run_sessions`.`testing` IS NOT NULL AND `survey_run_sessions`.`testing` = 0 AND `{$results_table}`.ended IS null)" => 'begun',
                        "SUM(`survey_run_sessions`.`testing` IS NOT NULL AND `survey_run_sessions`.`testing` = 0 AND `{$results_table}`.ended IS NOT NULL)" => 'finished',
                        "SUM(`survey_run_sessions`.`testing` IS NULL OR `survey_run_sessions`.`testing` = 1)" => 'testers',
                        "SUM(`survey_run_sessions`.`testing` IS NOT NULL AND `survey_run_sessions`.`testing` = 0)" => 'real_users'
                    ))->from($results_table)
                    ->leftJoin('survey_unit_sessions', "survey_unit_sessions.id = {$results_table}.session_id")
                    ->leftJoin('survey_run_sessions', "survey_unit_sessions.run_session_id = survey_run_sessions.id");

            if ($run_id) {
                $select->where("survey_run_sessions.run_id = {$run_id}");
            }
            if (!empty($filter['session'])) {
                $session = $filter['session'];
                strlen($session) == 64 ? $select->where("survey_run_sessions.session = '$session'") : $select->like('survey_run_sessions.session', $session, 'right');
            }

            if (!empty($filter['results']) && ($res_filter = $this->getResultsFilter($filter['results']))) {
                $res_where = Template::replace($res_filter['query'], array('table' => $results_table));
                $select->where($res_where);
            }

            $count = $select->fetch();
        }

        return $count;
    }

    public function getAverageTimeItTakes() {
        if ($this->resultsTableExists()) {
            $get = "SELECT AVG(middle_values) AS 'median' FROM (
			  SELECT took AS 'middle_values' FROM
				(
				  SELECT @row:=@row+1 as `row`, (x.ended - x.created) AS took
			      FROM `{$this->results_table}` AS x, (SELECT @row:=0) AS r
				  WHERE 1
				  -- put some where clause here
				  ORDER BY took
				) AS t1,
				(
				  SELECT COUNT(*) as 'count'
			      FROM `{$this->results_table}` x
				  WHERE 1
				  -- put same where clause here
				) AS t2
				-- the following condition will return 1 record for odd number sets, or 2 records for even number sets.
				WHERE t1.row >= t2.count/2 and t1.row <= ((t2.count/2) +1)) AS t3;";

            $get = $this->db->query($get, true);
            $time = $get->fetch(PDO::FETCH_NUM);
            $time = round($time[0] / 60, 3); # seconds to minutes

            return $time;
        }
        return '';
    }

    public function delete() {
        if ($this->deleteResults()) {
            $this->db->query("DROP TABLE IF EXISTS `{$this->results_table}`");
            if (($filename = $this->getOriginalFileName())) {
                @unlink(Config::get('survey_upload_dir') . '/' . $filename);
            }
            
            $this->db->query('DELETE FROM survey_items WHERE study_id = ' . $this->id);
            return $this->db->query('DELETE FROM survey_units WHERE id = ' . $this->id);
        }
        
        return false;
    }

    /**
     * Merge survey items. Each parameter is an associative array indexed by the names of the items in the survey, with the 
     * mysql field definition as the value.
     * new items are added, old items are deleted, items that changed type are deleted from the results table but not the item_display_table
     * All non null entries represent the MySQL data type definition of the fields as they should be in the survey results table
     * NOTE: All the DB queries here should be in a transaction of calling function
     *
     * @param array $newItems
     * @param array $deleteItems
     * @return bool;
     */
    private function alterResultsTable(array $newItems, array $deleteItems) {
        $actions = $toAdd = $toDelete = array();
        $deleteQuery = $addQuery = array();
        $addQ = $delQ = null;

        // just for safety checking that there is something to be deleted (in case of aborted earlier tries)
        $existingColumns = $this->db->getTableDefinition($this->results_table, 'Field');

        // Create query to drop items in existing table
        foreach ($deleteItems as $name => $result_field) {
            if ($result_field !== null && isset($existingColumns[$name])) {
                $deleteQuery[] = " DROP `{$name}`";
            }
            $toDelete[] = $name;
        }
        // Create query for adding items to existing table
        foreach ($newItems as $name => $result_field) {
            if ($result_field !== null) {
                $addQuery[] = " ADD $result_field";
            }
            $toAdd[] = $name;
        }

        // prepare these strings for feedback
        $added_columns_string = implode(", ", $toAdd);
        $deleted_columns_string = implode(", ", $toDelete);


        // if something should be deleted
        if ($deleteQuery) {
            $q = "ALTER TABLE `{$this->results_table}`" . implode(',', $deleteQuery);
            $this->db->query($q);
            $actions[] = "Deleted columns: $deleted_columns_string.";
        }

        // we only get here if the deletion stuff was harmless, allowed or did not happen
        if ($addQuery) {
            $q = "ALTER TABLE `{$this->results_table}`" . implode(',', $addQuery);
            $this->db->query($q);
            $actions[] = "Added columns: $added_columns_string.";
        }

        if (!empty($actions)) {
            $this->messages[] = "<strong>The results table was modified.</strong>";
            $this->messages = array_merge($this->messages, $actions);
        } else {
            $this->messages[] = "The results table did not need to be modified.";
        }

        return true;
    }

    public function getOriginalFileName() {
        return $this->original_file;
        //return $this->db->findValue('survey_studies', array('id' => $this->id), 'original_file');
    }

    public function getGoogleFileId() {
        return $this->google_file_id;
        //return $this->db->findValue('survey_studies', array('id' => $this->id), 'google_file_id');
    }

    public function getResultsFilter($f = null) {
        $filter = array(
            'all' => array(
                'title' => 'Show All',
                'query' => null,
            ),
            'incomplete' => array(
                'title' => 'Incomplete',
                'query' => '(%{table}.created <> %{table}.modified or %{table}.modified is null) and %{table}.ended is null',
            ),
            'complete' => array(
                'title' => 'Complete',
                'query' => '%{table}.ended is not null',
            ),
        );

        return $f !== null ? array_val($filter, $f, null) : $filter;
    }
    
    public function getOrderedItemsIds() {
        $get_items = $this->db->select('
				`survey_items`.id,
				`survey_items`.`type`,
				`survey_items`.`item_order`,
				`survey_items`.`block_order`')
                ->from('survey_items')
                ->where("`survey_items`.`study_id` = :study_id")
                ->order("`survey_items`.order")
                ->bindParams(array('`study_id`' => $this->id))
                ->statement();

        // sort blocks randomly (if they are consecutive), then by item number and if the latter are identical, randomly
        $block_segment = $block_order = $item_order = $random_order = $block_numbers = $item_ids = array();
        $types = array();

        $last_block = "";
        $block_nr = 0;
        $block_segment_i = 0;

        while ($item = $get_items->fetch(PDO::FETCH_ASSOC)) {
            if ($item['block_order'] == "") { // not blocked
                $item['block_order'] = ""; // ? why is this necessary
                $block_order[] = $block_nr;
            } else {
                if (!array_key_exists($item['block_order'], $block_numbers)) { // new block
                    if ($last_block === "") { // new segment of blocks
                        $block_segment_i = 0;
                        $block_segment = range($block_nr, $block_nr + 10000); // by choosing this range, the next non-block segment is forced to follow
                        shuffle($block_segment);
                        $block_nr = $block_nr + 10001;
                    }

                    $rand_block_number = $block_segment[$block_segment_i];
                    $block_numbers[$item['block_order']] = $rand_block_number;
                    $block_segment_i++;
                }
                $block_order[] = $block_numbers[$item['block_order']]; // get stored block order
            } // sort the blocks with each other
            // but keep the order within blocks if desired
            $item_order[] = $item['item_order']; // after sorting by block, sort by item order 
            $item_ids[] = $item['id'];
            $last_block = $item['block_order'];

            $types[$item['id']] = $item['type'];
        }

        $random_order = range(1, count($item_ids)); // if item order is identical, sort randomly (within block)
        shuffle($random_order);
        array_multisort($block_order, $item_order, $random_order, $item_ids);
        // order is already sufficiently defined at least by random_order, but this is a simple way to sort $item_ids is sorted accordingly

        return array($item_ids, $types);
    }

}
