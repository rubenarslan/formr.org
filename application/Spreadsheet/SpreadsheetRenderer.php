<?php

/**
 * Render a form for a unit sessions based on rendered and validated items
 *
 * @author ctata
 */
class SpreadsheetRenderer {

    /**
     * 
     * @var Run
     */
    protected $run;
    /**
     * 
     * @var SurveyStudy
     */
    protected $study;
    /**
     * 
     * @var UnitSession
     */
    protected $unitSession;
    /**
     * 
     * @var DB
     */
    protected $db;
    /**
     * 
     * @var Item[]
     */
    protected $renderedItems = [];
    protected $validationErrors = [];

    /**
     * @var Item[];
     */
    protected $unanswered = [];
    protected $toRender = [];
    protected $validatedItems = [];
    protected $progressCounts = [
        'progress' => 0,
        'already_answered' => 0,
        'not_answered' => 0,
        'hidden_but_rendered' => 0,
        'not_rendered' => 0,
        'visible_on_current_page' => 0,
        'hidden_but_rendered_on_current_page' => 0,
        'not_answered_on_current_page' => 0
    ];
    
    protected $errors = [];

    public function __construct(SurveyStudy $study, UnitSession $unitSession = null) {
        $this->unitSession = $unitSession;
        $this->run = $unitSession->runSession->getRun();
        $this->db = $unitSession->getDbConnection();
        $this->study = $study;
        $this->validatedItems = $unitSession->validatedStudyItems;
    }

    public function processItems() {
        $loops = 0;
        while (($items = $this->getNextStudyItems())) {
            // exit loop if it has ran more than x times and log remaining items
            $loops++;
            if ($loops > Config::get('allowed_empty_pages', 80)) {
                alert('Too many empty pages in this survey. Please alert an administrator.', 'alert-danger');
                formr_log("Survey::exec() '{$this->run->name} > {$this->study->name}' terminated with an infinite loop for items: ");
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
                    'session_id' => $this->unitSession->id,
                    'item_id' => $lastItem->id,
                );
                $this->db->update('survey_items_display', array('hidden' => 1), $sess_item);
                continue;
            } else {
                $this->toRender = $this->processDynamicLabelsAndChoices($items);
                break;
            }
        }
        
        $this->renderedItems = $this->getRenderedStudyItems();
    }

    public function render($form_action = null, $form_append = null) {
        $ret = '
		<div class="row study-' . $this->study->id . ' study-name-' . $this->study->name . '">
			<div class="col-md-12">
		';
        $ret .= $this->renderHeader($form_action) .
                $this->renderItems() .
                $form_append .
                $this->renderFooter();
        $ret .= '
			</div> <!-- end of col-md-12 div -->
		</div> <!-- end of row div -->
		';
        //$this->dbh = null;
        return $ret;
    }

    protected function renderHeader($action = null) {
        //$cookie = Request::getGlobals('COOKIE');
        $action = $action !== null ? $action : run_url($this->run->name);
        $enctype = 'multipart/form-data'; # maybe make this conditional application/x-www-form-urlencoded

        $tpl = '
			<form action="%{action}" method="post" class="%{class}" enctype="%{enctype}" accept-charset="utf-8">
				<input type="hidden" name="session_id" value="%{session_id}" />
				<input type="hidden" name="%{name_request_tokens}" value="%{request_tokens}" />
				<input type="hidden" name="%{name_user_code}" value="%{user_code}" />
				<input type="hidden" name="%{name_cookie}" value="%{cookie}" />
				
				<div class="row progress-container">
					<div class="progress">
						<div class="progress-bar" style="width: %{progress}%;" data-percentage-minimum="%{add_percentage_points}" data-percentage-maximum="%{displayed_percentage_maximum}" data-already-answered="%{already_answered}" data-items-left="%{not_answered_on_current_page}" data-items-on-page="%{items_on_page}" data-hidden-but-rendered="%{hidden_but_rendered}">
							%{progress} %
						</div>
					</div>
				</div>

				%{errors_tpl}
		';

        $errors_tpl = '
			<div class="alert alert-danger alert-dismissible form-message fmr-error-messages">
				<i class="fa fa-exclamation-triangle pull-left fa-2x"></i>
				<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				%{errors}
			</div>
		';

        if (!isset($this->study->displayed_percentage_maximum) OR $this->study->displayed_percentage_maximum == 0) {
            $this->study->displayed_percentage_maximum = 100;
        }

        $prog = $this->progressCounts['progress'] * // the fraction of this survey that was completed
                ($this->study->displayed_percentage_maximum - // is multiplied with the stretch of percentage that it was accorded
                $this->study->add_percentage_points);

        if (isset($this->study->add_percentage_points)) {
            $prog += $this->study->add_percentage_points;
        }

        if ($prog > $this->study->displayed_percentage_maximum) {
            $prog = $this->study->displayed_percentage_maximum;
        }

        $prog = round($prog);

        $tpl_vars = array(
            'action' => $action,
            'class' => 'form-horizontal main_formr_survey' . ($this->study->enable_instant_validation ? ' ws-validate' : ''),
            'enctype' => $enctype,
            'session_id' => $this->unitSession->id,
            'name_request_tokens' => Session::REQUEST_TOKENS,
            'name_user_code' => Session::REQUEST_USER_CODE,
            'name_cookie' => Session::REQUEST_NAME,
            'request_tokens' => Session::getRequestToken(), //$cookie->getRequestToken(),
            'user_code' => h(Site::getCurrentUser()->user_code), //h($cookie->getData('code')),
            'cookie' => '', //$cookie->getFile(),
            'progress' => $prog,
            'add_percentage_points' => $this->study->add_percentage_points,
            'displayed_percentage_maximum' => $this->study->displayed_percentage_maximum,
            'already_answered' => $this->progressCounts['already_answered'],
            'not_answered_on_current_page' => $this->progressCounts['not_answered_on_current_page'],
            'items_on_page' => $this->progressCounts['not_answered'] - $this->progressCounts['not_answered_on_current_page'],
            'hidden_but_rendered' => $this->progressCounts['hidden_but_rendered_on_current_page'],
            'errors_tpl' => !empty($this->validationErrors) ? Template::replace($errors_tpl, array('errors' => $this->renderErrors())) : null,
        );

        return Template::replace($tpl, $tpl_vars);
    }

    protected function renderItems() {
        $ret = '';

        foreach ($this->renderedItems as $item) {
            if (!empty($this->validationErrors[$item->name])) {
                $item->error = $this->validationErrors[$item->name];
            }
            if (!empty($this->validatedItems[$item->name])) {
                $item->value_validated = $this->validatedItems[$item->name]->value_validated;
            }
            $ret .= $item->render();
        }

        // if the last item was not a submit button, add a default one
        if (isset($item) && ($item->type !== "submit" || $item->hidden)) {
            $sub_sets = array(
                'label_parsed' => '<i class="fa fa-arrow-circle-right pull-left fa-2x"></i> Go on to the<br>next page!',
                'classes_input' => array('btn-info default_formr_button'),
            );
            $item = new Submit_Item($sub_sets);
            $ret .= $item->render();
        }

        return $ret;
    }

    protected function renderFooter() {
        return '</form>';
    }

    /**
     * 
     * @param Item[] $items
     * @return string
     */
    protected function renderErrors() {
        $labels = Session::get('labels', array());
        $tpl = '
        <li>
			<i class=""></i>
			<b>Question/Code</b>: %{question} <br />
			<b>Error</b>: %{error}
		 </li>
		';
        $errors = '';

        foreach ($this->validationErrors as $name => $error) {
            if ($error) {
                $errors .= Template::replace($tpl, array(
                            'question' => strip_tags(array_val($labels, $name, strtoupper($name))),
                            'error' => $error,
                ));
            }
        }
        Session::delete('labels');
        return '<ul>' . $errors . '</ul>';
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

        $get_items = $select->bindParams(array('session_id' => $this->unitSession->id, 'study_id' => $this->study->id))->statement();

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
        $updated = $this->unitSession->updateSurveyStudyRecord($hiddenItems, true);
        if ($hiddenItems && $updated) {
            $this->answered($hiddenItems);
        } elseif ($hiddenItems && !$updated) {
            $this->validationErrors = $this->unitSession->errors;
            $items = array_merge($items, $this->unitSession->validatedStudyItems);
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
        $study = $this->study;

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
                $val = str_replace("\n", "\n\t", $item->getValue($study));
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

        $ocpu_session = opencpu_multiparse_showif($this->unitSession, $code, true);
        if (!$ocpu_session || $ocpu_session->hasError()) {
            notify_user_error(opencpu_debug($ocpu_session), "There was a problem evaluating showifs using openCPU.");
            foreach ($items as $name => &$item) {
                $item->alwaysInvalid();
            }
        } else {
            print_hidden_opencpu_debug_message($ocpu_session, "OpenCPU debugger for dynamic values and showifs.");
            $results = $ocpu_session->getJSONObject();
            $updateVisibility = $this->db->prepare("UPDATE `survey_items_display` SET hidden = :hidden WHERE item_id = :item_id AND session_id = :session_id");
            $updateVisibility->bindValue(":session_id", $this->unitSession->id);

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
                    unset($item->parent_attributes['data-show']);
                    unset($items[$item_name]); // we remove items that are definitely hidden from consideration
                    continue; // don't increment counter
                } else {
                    if ($hidden === 0) {
                        $item->parent_attributes['data-show'] = "'true'";
                    }
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
            
            // @TODO remove items from unanswerd if this is successfull
            if ($this->unitSession->updateSurveyStudyRecord($save, false)) {
                $this->answered($save);
            }
        }

        return $items;
    }

    protected function processDynamicLabelsAndChoices(&$items) {
        $study = $this->study;

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

            $vars = ($item->type == 'note_iframe') ? $this->unitSession->getRunData($item->label, $study->name) : [];
            if ($item->needsDynamicLabel($vars)) {
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
            $parsed_strings = opencpu_multistring_parse($this->unitSession, $strings_to_parse);
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
        $study = $this->study;

        $this->db->beginTransaction();

        $view_query = "
			UPDATE `survey_items_display`
			SET displaycount = COALESCE(displaycount,0) + 1, created = COALESCE(created, NOW())
			WHERE item_id = :item_id AND session_id = :session_id";
        $view_update = $this->db->prepare($view_query);
        $view_update->bindValue(":session_id", $this->unitSession->id);

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
        $study = $this->study;

        $answered = $this->db->select(array('COUNT(`survey_items_display`.saved)' => 'count', 'study_id', 'session_id'))
                ->from('survey_items')
                ->leftJoin('survey_items_display', 'survey_items_display.session_id = :session_id', 'survey_items.id = survey_items_display.item_id')
                ->where('survey_items_display.session_id IS NOT NULL')
                ->where('survey_items.study_id = :study_id')
                ->where("survey_items.type NOT IN ('submit')")
                ->where("`survey_items_display`.saved IS NOT NULL")
                ->bindParams(array('session_id' => $this->unitSession->id, 'study_id' => $study->id))
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

    public function studyCompleted() {
        return $this->getStudyProgress() === 1;
    }
    
    protected function answered ($items) {
        foreach ($items as $name => $item) {
            unset($this->unanswered[$name]);
        }
    }

}
