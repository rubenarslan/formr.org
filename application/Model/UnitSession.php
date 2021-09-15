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
    public $pending = true;

    /**
     * @var RunSession
     */
    public $runSession;

    /**
     * @var RunUnit
     */
    public $runUnit;
    protected $execResults = [];

    /**
     * @var Item[];
     */
    protected $unanswered = [];
    protected $toRender = [];
    protected $validatedItems = [];
    protected $progressCounts = array(
        'progress' => 0,
        'already_answered' => 0,
        'not_answered' => 0,
        'hidden_but_rendered' => 0,
        'not_rendered' => 0,
        'visible_on_current_page' => 0,
        'hidden_but_rendered_on_current_page' => 0,
        'not_answered_on_current_page' => 0
    );

    /**
     * A UnitSession needs a RunUnit to operate and belongs to a RunSession
     *
     * @param RunSession $runSession
     * @param RunUnit $runUnit
     * @param array $options An array of other options used to fetch a unit ID
     */
    public function __construct(RunSession $runSession, RunUnit $runUnit, $options = []) {
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
            formr_log('Insert to DB . ' . print_r($session, 1));
            $this->id = $this->db->insert('survey_unit_sessions', $session);
            formr_log('Inserted' . $this->id);
            if ($this->run_session_id !== null && $new_current_unit) {
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
        if ($this->id !== null) {
            $vars = $this->db->findRow('survey_unit_sessions', ['id' => $this->id], 'id, created, unit_id, run_session_id, ended');
        } else {
            $vars = $this->db->select('id, created, unit_id, run_session_id, ended')
                    ->from('survey_unit_sessions')
                    ->where(['run_session_id' => $this->run_session_id, 'unit_id' => $this->unit_id])
                    ->where('ended IS NULL AND expired IS NULL')
                    ->order('created', 'desc')->limit(1)
                    ->fetch();
        }

        $this->assignProperties($vars);
    }

    public function __sleep() {
        return array('id', 'session', 'unit_id', 'created');
    }

    public function exec() {
        $this->execResults = [];
        // Check if session has expired by getting relevant unit data
        if ($this->isExpired()) {
            $this->expire();
            $this->execResults['move_on'] = true;
            return $this->execResults;
        }

        if (($output = $this->runUnit->getUnitSessionOutput($this))) {
            // @TODO
            // - check redirect
            // - check waits
            //
            $this->execResults['output'] = $output;
        }

        if ($this->isQueuable()) {
            $this->queue();
        }

        return $this->execResults;
    }

    protected function isExpired() {
        $expirationData = $this->runUnit->getUnitSessionExpirationData($this);
        formr_log($expirationData);
        if (empty($expirationData['expires'])) {
            return false;
        } elseif ($expirationData['expires'] < time()) {
            return true;
        } else {
            $this->execResults['queued'] = $expirationData;
        }
    }

    /**
     * Check if unit session should be queued
     * ** ALWAYS CALL AFTER $this->isExpired() ***
     * @return boolean
     */
    protected function isQueuable() {
        return !empty($this->execResults['queued']);
    }

    public function expire() {
        $unit = $this->runUnit;
        $query = "UPDATE `{$unit->surveyStudy->results_table}` SET `expired` = NOW() WHERE `session_id` = :session_id AND `study_id` = :study_id AND `ended` IS null";
        $params = ['session_id' => $this->id, 'study_id' => $unit->surveyStudy->id];
        $this->db->exec($query, $params);
                
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
                $this->result = "ended_by_queue";
            } else {
                $this->result = "ended_other";
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
        UnitSessionQueue::addItem($this, $this->runUnit, $this->execResults['add_to_queue']);
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

    public function processSurveyStudyRequest() {
        try {
            $run = $this->runSession->getRun();
            $study = $this->runUnit->surveyStudy;

            $request = new Request($_POST);
            //$cookie = Request::getGlobals('COOKIE');
            //check if user session has a valid form token for POST requests
            //if (Request::isHTTPPostRequest() && $cookie && !$cookie->canValidateRequestToken($request)) {
            //	redirect_to(run_url($this->run_name));
            //}
            if (Request::isHTTPPostRequest() && !Session::canValidateRequestToken($request)) {
                return ['redirect' => run_url($run->name)];
            }

            $this->createSurveyStudyRecord();

            // Use SurveyHelper if study is configured to use pages
            if ($this->runUnit->surveyStudy->use_paging) {
                $surveyHelper = new SurveyHelper(new Request(array_merge($_POST, $_FILES)), $this);
                $surveyHelper->savePageItems();
                if (($renderSurvey = $surveyHelper->renderSurvey()) !== false) {
                    return array('body' => $renderSurvey);
                } else {
                    $this->result = "survey_completed";
                    $this->logResult();
                    // Survey ended
                    return ['end_session' => true, 'move_on' => true];
                }
            }

            // POST items only if request is a post request
            if (Request::isHTTPPostRequest()) {
                $posted = $this->updateSurveyStudyRecord(array_merge($request->getParams(), $_FILES));
                if ($posted) {
                    $this->result = "survey_filling_out";
                    $this->logResult();
                    return ['redirect' => run_url($run->name)];
                }
            }

            $loops = 0;
            while (($items = $this->getNextStudyItems())) {
                // exit loop if it has ran more than x times and log remaining items
                $loops++;
                if ($loops > Config::get('allowed_empty_pages', 80)) {
                    alert('Too many empty pages in this survey. Please alert an administrator.', 'alert-danger');
                    formr_log("Survey::exec() '{$run->name} > {$study->name}' terminated with an infinite loop for items: ");
                    formr_log(array_keys($items));
                    break;
                }
                // process automatic values (such as get, browser)
                $items = $this->processAutomaticItems($items);
                // process showifs, dynamic values for these items
                $items = $this->processDynamicValuesAndShowIfs($items);
                // If no items survived all the processing then move on
                if (!$items) {
                    continue;
                }
                $lastItem = end($items);

                // If no items ended up to be on the page but for a submit button, make it hidden and continue
                // else render processed items
                if (count($items) == 1 && $lastItem->type === 'submit') {
                    $sess_item = array(
                        'session_id' => $this->id,
                        'item_id' => $lastItem->id,
                    );
                    $this->db->update('survey_items_display', array('hidden' => 1), $sess_item);
                    continue;
                } else {
                    $this->toRender = $this->processDynamicLabelsAndChoices($items);
                    break;
                }
            }

            if ($this->getStudyProgress() === 1) {
                $this->result = "survey_completed";
                $this->logResult();
                $this->end();
                return ['end_session' => true, 'move_on' => true];
            }

            $renderedItems = $this->getRenderedStudyItems();
            $renderer = new FormRenderer($this, $renderedItems, $this->validatedItems, $this->progressCounts, $this->errors);

            return ['body' => $renderer->render()];
        } catch (Exception $e) {
            $this->result = "error_survey";
            $this->result_log = $e->getMessage();
            $this->logResult();
            formr_log_exception($e, __CLASS__);
            return ['body' => ''];
        }
    }

    /**
     * Get the next items to be possibly displayed in the survey
     *
     * @return array Returns items that can be possibly shown on current page
     */
    protected function getNextStudyItems() {
        $this->unanswered = [];

        $select = $this->db->select('
				`survey_items`.id,
				`survey_items`.study_id,
				`survey_items`.type,
				`survey_items`.choice_list,
				`survey_items`.type_options,
				`survey_items`.name,
				`survey_items`.label,
				`survey_items`.label_parsed,
				`survey_items`.optional,
				`survey_items`.class,
				`survey_items`.showif,
				`survey_items`.value,

				`survey_items_display`.displaycount, 
				`survey_items_display`.session_id,
				`survey_items_display`.`display_order`,
				`survey_items_display`.`hidden`,
				`survey_items_display`.answered')
                ->from('survey_items')
                ->leftJoin('survey_items_display', 'survey_items_display.session_id = :session_id', 'survey_items.id = survey_items_display.item_id')
                ->where('(survey_items.study_id = :study_id) AND 
				     (survey_items_display.saved IS null) AND 
				     (survey_items_display.hidden IS NULL OR survey_items_display.hidden = 0)')
                ->order('`survey_items_display`.`display_order`', 'asc')
                ->order('survey_items.`order`', 'asc') // only needed for transfer
                ->order('survey_items.id', 'asc');

        $get_items = $select->bindParams(array('session_id' => $this->id, 'study_id' => $this->runUnit->surveyStudy->id))->statement();

        // We initialise item factory with no choice list because we don't know which choices will be used yet.
        // This assumes choices are not required for show-ifs and dynamic values (hope so)
        $itemFactory = new ItemFactory(array());
        $pageItems = array();
        $inPage = true;

        while ($item = $get_items->fetch(PDO::FETCH_ASSOC)) {
            /* @var $oItem Item */
            $oItem = $itemFactory->make($item);
            if (!$oItem) {
                continue;
            }

            $this->unanswered[$oItem->name] = $oItem;

            // If no user input is required and item can be on current page, then save it to be shown
            if ($inPage) {
                $pageItems[$oItem->name] = $oItem;
            }

            if ($oItem->type === 'submit') {
                $inPage = false;
            }
        }

        return $pageItems;
    }

    /**
     * All items that don't require connecting to openCPU and don't require user input are posted immediately.
     * Examples: get parameters, browser, ip.
     *
     * @param Item[] $items
     * @return array Returns items that may have to be sent to openCPU or be rendered for user input
     */
    protected function processAutomaticItems($items) {
        $hiddenItems = array();
        foreach ($items as $name => $item) {
            if (!$item) {
                continue;
            }

            if (!$item->requiresUserInput() && !$item->needsDynamicValue()) {
                $hiddenItems[$name] = $item->getComputedValue();
                unset($items[$name]);
                continue;
            }
        }

        // save these values
        if ($hiddenItems) {
            $this->updateSurveyStudyRecord($hiddenItems, true);
        }

        // return possibly shortened item array
        return $items;
    }

    /**
     * Process show-ifs and dynamic values for a given set of items in survey
     * @note: All dynamic values are processed (even for those we don't know if they will be shown)
     *
     * @param Item[] $items
     * @return array
     */
    protected function processDynamicValuesAndShowIfs(&$items) {
        // In this loop we gather all show-ifs and dynamic-values that need processing and all values.
        $code = array();

        /* @var $item Item */
        foreach ($items as $name => &$item) {
            if (!$item) {
                continue;
            }

            // 1. Check item's show-if
            $showif = $item->getShowIf();
            if ($showif) {
                $siname = "si.{$name}";
                $showif = str_replace("\n", "\n\t", $showif);
                $code[$siname] = "{$siname} = (function(){
	{$showif}
})()";
            }

            // 2. Check item's value
            if ($item->needsDynamicValue()) {
                $val = str_replace("\n", "\n\t", $item->getValue($this));
                $code[$name] = "{$name} = (function(){
{$val}
})()";
                if ($showif) {
                    $code[$name] = "if({$siname}) {
	" . $code[$name] . "
}";
                }
                // If item is to be shown (rendered), return evaluated dynamic value, else keep dynamic value as string
            }
        }

        if (!$code) {
            return $items;
        }

        $ocpu_session = opencpu_multiparse_showif($this, $code, true);
        if (!$ocpu_session || $ocpu_session->hasError()) {
            notify_user_error(opencpu_debug($ocpu_session), "There was a problem evaluating showifs using openCPU.");
            foreach ($items as $name => &$item) {
                $item->alwaysInvalid();
            }
        } else {
            print_hidden_opencpu_debug_message($ocpu_session, "OpenCPU debugger for dynamic values and showifs.");
            $results = $ocpu_session->getJSONObject();
            $updateVisibility = $this->db->prepare("UPDATE `survey_items_display` SET hidden = :hidden WHERE item_id = :item_id AND session_id = :session_id");
            $updateVisibility->bindValue(":session_id", $this->id);

            $save = array();

            $definitelyShownItems = 0;
            foreach ($items as $item_name => &$item) {
                // set show-if visibility for items
                $siname = "si.{$item->name}";
                $isVisible = $item->setVisibility(array_val($results, $siname));
                // three possible states: 1 = hidden, 0 = shown, null = depends on JS on the page, render anyway
                if ($isVisible === null) {
                    // we only render it, if there are some items before it on which its display could depend
                    // otherwise it's hidden for good
                    $hidden = $definitelyShownItems > 0 ? null : 1;
                } else {
                    $hidden = (int) !$isVisible;
                }
                $updateVisibility->bindValue(":item_id", $item->id);
                $updateVisibility->bindValue(":hidden", $hidden);
                $updateVisibility->execute();

                if ($hidden === 1) { // gone for good
                    unset($items[$item_name]); // we remove items that are definitely hidden from consideration
                    continue; // don't increment counter
                } else {
                    // set dynamic values for items
                    $val = array_val($results, $item->name, null);
                    $item->setDynamicValue($val);
                    // save dynamic value
                    // if a. we have a value b. this item does not require user input (e.g. calculate)
                    if (array_key_exists($item->name, $results) && !$item->requiresUserInput()) {
                        $save[$item->name] = $item->getComputedValue();
                        unset($items[$item_name]); // we remove items that are immediately written from consideration
                        continue; // don't increment counter
                    }
                }
                $definitelyShownItems++; // track whether there are any items certain to be shown
            }
            $this->updateSurveyStudyRecord($save, false);
        }

        return $items;
    }

    protected function processDynamicLabelsAndChoices(&$items) {
        $study = $this->runUnit->surveyStudy;

        // Gather choice lists
        $lists_to_fetch = $strings_to_parse = array();
        $session_labels = array();

        foreach ($items as $name => &$item) {
            if (!$item) {
                continue;
            }

            if ($item->choice_list) {
                $lists_to_fetch[] = $item->choice_list;
            }

            if ($item->needsDynamicLabel($this)) {
                $items[$name]->label_parsed = opencpu_string_key(count($strings_to_parse));
                $strings_to_parse[] = $item->label;
            }
        }

        // gather and format choice_lists and save all choice labels that need parsing
        $choices = $study->getChoices($lists_to_fetch, null);
        $choice_lists = array();
        foreach ($choices as $i => $choice) {
            if ($choice['label_parsed'] === null) {
                $choices[$i]['label_parsed'] = opencpu_string_key(count($strings_to_parse));
                $strings_to_parse[] = $choice['label'];
            }

            if (!isset($choice_lists[$choice['list_name']])) {
                $choice_lists[$choice['list_name']] = array();
            }
            $choice_lists[$choice['list_name']][$choice['name']] = $choices[$i]['label_parsed'];
        }

        // Now that we have the items and the choices, If there was anything left to parse, we do so here!
        if ($strings_to_parse) {
            $parsed_strings = opencpu_multistring_parse($this, $strings_to_parse);
            // Replace parsed strings in $choice_list array
            opencpu_substitute_parsed_strings($choice_lists, $parsed_strings);
            // Replace parsed strings in unanswered items array
            opencpu_substitute_parsed_strings($items, $parsed_strings);
        }

        // Merge parsed choice lists into items
        foreach ($items as $name => &$item) {
            $choice_list = $item->choice_list;
            if (isset($choice_lists[$choice_list])) {
                $list = $choice_lists[$choice_list];
                $list = array_filter($list, 'is_formr_truthy');
                $items[$name]->setChoices($list);
            }
            //$items[$name]->refresh($item, array('label_parsed'));
            $session_labels[$name] = $item->label_parsed;
        }

        Session::set('labels', $session_labels);
        return $items;
    }

    protected function getRenderedStudyItems() {
        $study = $this->runUnit->surveyStudy;

        $this->db->beginTransaction();

        $view_query = "
			UPDATE `survey_items_display`
			SET displaycount = COALESCE(displaycount,0) + 1, created = COALESCE(created, NOW())
			WHERE item_id = :item_id AND session_id = :session_id";
        $view_update = $this->db->prepare($view_query);
        $view_update->bindValue(":session_id", $this->id);

        $itemsDisplayed = 0;

        $renderedItems = array();

        try {
            foreach ($this->toRender as &$item) {
                if ($study->maximum_number_displayed && $study->maximum_number_displayed === $itemsDisplayed) {
                    break;
                } else if ($item->isRendered()) {
                    // if it's rendered, we send it along here or update display count
                    $view_update->bindParam(":item_id", $item->id);
                    $view_update->execute();

                    if (!$item->hidden) {
                        $itemsDisplayed++;
                    }

                    $renderedItems[] = $item;
                }
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            formr_log_exception($e, __CLASS__);
        }

        return $renderedItems;
    }

    protected function getStudyProgress() {
        $study = $this->runUnit->surveyStudy;

        $answered = $this->db->select(array('COUNT(`survey_items_display`.saved)' => 'count', 'study_id', 'session_id'))
                ->from('survey_items')
                ->leftJoin('survey_items_display', 'survey_items_display.session_id = :session_id', 'survey_items.id = survey_items_display.item_id')
                ->where('survey_items_display.session_id IS NOT NULL')
                ->where('survey_items.study_id = :study_id')
                ->where("survey_items.type NOT IN ('submit')")
                ->where("`survey_items_display`.saved IS NOT NULL")
                ->bindParams(array('session_id' => $this->id, 'study_id' => $study->id))
                ->fetch();

        $this->progressCounts['already_answered'] = $answered['count'];

        /** @var Item $item */
        foreach ($this->unanswered as $item) {
            // count only rendered items, not skipped ones
            if ($item && $item->isRendered()) {
                $this->progressCounts['not_answered']++;
            }
            // count those items that were hidden but rendered (ie. those relying on missing data for their showif)
            if ($item && $item->isHiddenButRendered()) {
                $this->progressCounts['hidden_but_rendered']++;
            }
        }
        /** @var Item $item */
        foreach ($this->toRender as $item) {
            // On current page, count only rendered items, not skipped ones
            if ($item && $item->isRendered()) {
                $this->progressCounts['visible_on_current_page']++;
            }
            // On current page, count those items that were hidden but rendered (ie. those relying on missing data for their showif)
            if ($item && $item->isHiddenButRendered()) {
                $this->progressCounts['hidden_but_rendered_on_current_page']++;
            }
        }

        $this->progressCounts['not_answered_on_current_page'] = $this->progressCounts['not_answered'] - $this->progressCounts['visible_on_current_page'];

        $all_items = $this->progressCounts['already_answered'] + $this->progressCounts['not_answered'];

        if ($all_items !== 0) {
            $this->progressCounts['progress'] = $this->progressCounts['already_answered'] / $all_items;
        } else {
            $this->errors[] = _('Something went wrong, there are no items in this survey!');
            $this->progressCounts['progress'] = 0;
        }

        // if there only hidden items, that have no way of becoming visible (no other items)
        if ($this->progressCounts['not_answered'] === $this->progressCounts['hidden_but_rendered']) {
            $this->progressCounts['progress'] = 1;
        }

        return $this->progressCounts['progress'];
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

        if (isset($posted["_item_views"]["shown"])):
            $posted["_item_views"]["shown"] = array_filter($posted["_item_views"]["shown"]);
            $posted["_item_views"]["shown_relative"] = array_filter($posted["_item_views"]["shown_relative"]);
            $posted["_item_views"]["answered"] = array_filter($posted["_item_views"]["answered"]);
            $posted["_item_views"]["answered_relative"] = array_filter($posted["_item_views"]["answered_relative"]);
        endif;

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
            $this->validatedItems = $items;
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
            foreach ($posted AS $name => $value) {
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
                    throw new Exception("Survey item '$name' could not be saved with value '$value' in table '{$study->results_table}' (FieldType: {$this->unanswered[$name]->getResultField()})");
                }
                unset($this->unanswered[$name]); //?? FIX ME
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
        } catch (Exception $e) {
            $this->db->rollBack();
            notify_user_error($e, 'An error occurred while trying to save your survey data. Please notify the author of this survey with this date and time');
            formr_log_exception($e, __CLASS__);
            //$redirect = false;
            return false;
        }

        return true;
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
            if ($runSession->id === null && !in_array($results_table, get_db_non_user_tables())) { // todo: what to do with session_id tables in faketestrun
                $where = " WHERE `$results_table`.session_id = :session_id"; // just for testing surveys
            } else {
                $where = " WHERE  `survey_run_sessions`.id = :run_session_id";
                if ($survey_name === "externals") {
                    $where .= " AND `survey_units`.`type` = 'External'";
                }
            }

            if (!in_array($results_table, get_db_non_user_tables())) {
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
            if ($runSession->id === null) {
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
        $results = $this->runSession->getRun()->getAllSurveys();

        // also add some "global" formr tables
        $non_user_tables = array_keys(get_db_non_user_tables());
        $tables = $non_user_tables;
        $table_ids = $non_user_tables;
        $results_tables = array_combine($non_user_tables, $non_user_tables);
        if (isset($results_tables['externals'])) {
            $results_tables['externals'] = 'survey_unit_sessions';
        }

        if ($token_add !== null) {  // send along this table if necessary, always as the first one, since we attach it
            $table_ids[] = $this->id;
            $tables[] = $this->name;
            $results_tables[$this->name] = $this->results_table;
        }

        // map table ID to the name that the user sees (because tables in the DB are prefixed with the user ID, so they're unique)
        foreach ($results as $res) {
            if ($res['name'] !== $token_add):
                $table_ids[] = $res['id'];
                $tables[] = $res['name']; // FIXME: ID can overwrite the non_user_tables
                $results_tables[$res['name']] = $res['results_table'];
            endif;
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
            if (array_key_exists($table_name, get_db_non_user_tables())) {
                $variable_names_in_table[$table_name] = get_db_non_user_tables()[$table_name];
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

        $variables = [];
        if (preg_match("/\btime_passed\b/", $q)) {
            $variables[] = 'formr_last_action_time';
        }
        if (preg_match("/\bnext_day\b/", $q)) {
            $variables[] = 'formr_last_action_date';
        }
        if (strstr($q, '.formr$login_code') !== false) {
            $variables[] = 'formr_login_code';
        }
        if (preg_match("/\buser_id\b/", $q)) {
            $variables[] = 'user_id';
        }
        if (strstr($q, '.formr$login_link') !== false) {
            $variables[] = 'formr_login_link';
        }
        if (strstr($q, '.formr$nr_of_participants') !== false) {
            $variables[] = 'formr_nr_of_participants';
        }
        if (strstr($q, '.formr$session_last_active') !== false) {
            $variables[] = 'formr_session_last_active';
        }

        return compact("matches", "matches_results_tables", "matches_variable_names", "token_add", "variables");
    }

    public function getCachedReportUrl() {
        return $this->db->findValue('survey_reports', array(
                    'unit_id' => $this->runUnit->id,
                    'session_id' => $this->id,
                    'created >=' => $this->runUnit->modified // if the definition of the unit changed, don't use old reports
                        ), array('opencpu_url'));
    }

}
