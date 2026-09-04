<?php

/**
 * Render a form for a unit sessions based on rendered and validated items
 *
 * @author ctata
 */
class SpreadsheetRenderer {

    /**
     * Prefix of the sibling element every tryCatch-wrapped showif / dynamic
     * value writes its conditionMessage() into, so a single broken author
     * expression stays isolated *and* stays loud. See
     * processDynamicValuesAndShowIfs() and reportRExpressionErrors().
     */
    const R_ERROR_PREFIX = 'fmr_err.';

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
    /**
     * Whether getRenderedStudyItems honours SurveyStudy.maximum_number_displayed.
     * True for v1 (per-request cap = items per page); FormRenderer sets false
     * because its toRender spans the whole document (see the loop comment).
     */
    protected $applyMaxDisplayedCap = true;
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
		$user = Site::getCurrentUser();

        $tpl_vars = array(
            'action' => $action,
            'class' => 'form-horizontal main_formr_survey' . ($this->study->enable_instant_validation ? ' ws-validate' : ''),
            'enctype' => $enctype,
            'session_id' => $this->unitSession->id,
            'name_user_code' => Session::REQUEST_USER_CODE,
            'name_cookie' => Session::REQUEST_NAME,
            'user_code' => $user ? h($user->user_code) : '', //h($cookie->getData('code')),
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
				     (survey_items.deleted IS NULL) AND
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
        }
        $items = array_merge($items, $this->unitSession->validatedStudyItems);

        // return possibly shortened item array
        return $items;
    }

    /**
     * Whether an item whose server-side showif evaluated to NA (its dependency
     * isn't answered yet) can be resolved client-side at render time. v1 has no
     * client evaluator, so the default is false — the historic
     * $definitelyShownItems heuristic still decides. form_v2 overrides this for
     * items carrying a transpiled js_showif so they're always rendered (initially
     * .hidden) and revealed reactively by the Alpine x-showif directive, instead
     * of being pruned to hidden=1 and dropped for good.
     *
     * @param Item $item
     * @return bool
     */
    protected function naShowifIsClientResolvable($item) {
        return false;
    }

    /**
     * Process show-ifs and dynamic values for a given set of items in survey.
     *
     * @note All dynamic values are processed (even for those we don't know if
     *       they will be shown).
     *
     * Error handling — two competing requirements, both load-bearing:
     *
     *  1. **Isolation.** Before v1.9.4 the generated R was a batch of bare
     *     closures, so one author mistake (`if (NA)`, object-not-found, a typo'd
     *     column) failed the *whole* OpenCPU call: hasError() fired, every item
     *     on the page went alwaysInvalid() and participants got a raw
     *     "problem evaluating showifs" banner. Each expression is therefore
     *     wrapped in its own tryCatch().
     *  2. **Loudness.** A bare `error = function(.e) NA` traded that for silence:
     *     a broken `value` expression resolved to NA and was then persisted as a
     *     NULL answer with no signal anywhere. So the handler returns
     *     conditionMessage(.e) into a sibling `fmr_err.` element instead of
     *     discarding `.e`.
     *
     * The resulting contract per expression:
     *  - showif: `si.<name>` is pre-seeded NA and overwritten on success; on
     *    failure it stays NA (the ordinary unresolved-visibility path) and
     *    `fmr_err.si.<name>` holds the message.
     *  - value: `<name>` is assigned only on success, so a failed expression is
     *    *absent* from the results and is never persisted as NULL;
     *    `fmr_err.<name>` holds the message, and in a debug context the item is
     *    marked alwaysInvalid() so the page cannot be submitted past it.
     *  - Both kinds are always written to the log; admin / test / cron viewers
     *    additionally get the messages on screen (reportRExpressionErrors()).
     *
     * @param Item[] $items
     * @return array
     */
    protected function processDynamicValuesAndShowIfs(&$items) {
        $code = $this->buildShowIfAndValueRCode($items);

        if (!$code) {
            return $items;
        }

        return $this->applyShowIfAndValueResults($items, $code);
    }

    /**
     * Build the R statements for one batch of show-ifs and dynamic values.
     *
     * Split out of processDynamicValuesAndShowIfs() so the generated source —
     * in particular its per-expression error capture, which is the whole
     * difference between "one broken showif is reported" and "one broken showif
     * silently persists a NULL answer" — can be asserted without an OpenCPU
     * round trip. See that method's docblock for the contract.
     *
     * @param Item[] $items
     * @return array<string,string> R statements keyed by the name they assign
     */
    protected function buildShowIfAndValueRCode(&$items) {
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
                $errName = self::R_ERROR_PREFIX . $siname;
                $showif = str_replace("\n", "\n\t", $showif);
                // tryCatch: a single erroring showif (object not found, if(NA)
                // inside author code, …) must degrade to NA — the normal
                // unresolved-visibility path — not fail the whole batch, which
                // marked every item invalid and showed raw R error banners to
                // the participant.
                //
                // The error is NOT swallowed though: the handler returns
                // conditionMessage(.e) into a sibling `fmr_err.si.<name>`
                // element, so PHP can log it and show it to admin/test sessions
                // (see reportRExpressionErrors()). `si.<name>` is pre-seeded to
                // NA and only overwritten on success — tryCatch() evaluates its
                // expression in *this* environment, so the assignment lands in
                // the within() frame. On success the handler never runs and the
                // fmr_err element stays NULL, which within() drops from the
                // returned list.
                $code[$siname] = "{$siname} = NA
{$errName} = tryCatch({
	{$siname} <- (function(){
	{$showif}
})()
NULL
}, error = function(.e) conditionMessage(.e))";
            }

            // 2. Check item's value
            if ($item->needsDynamicValue()) {
                $val = str_replace("\n", "\n\t", $item->getValue($study));
                $code[$name] = "{$name} = (function(){
{$val}
})()";
                if ($showif) {
                    // if(NA) is an R error, so gate on a definite truthy result:
                    // an unresolved showif skips the value (same as FALSE).
                    $code[$name] = "if (isTRUE(as.logical(({$siname})[1]))) {
	" . $code[$name] . "
}";
                }
                // If item is to be shown (rendered), return evaluated dynamic value, else keep dynamic value as string.
                //
                // Same per-expression isolation as the showif above, and the
                // same error capture — but deliberately NO fallback assignment:
                // when the expression throws, `<name>` is never created, so it
                // is absent from $results, array_key_exists() below is false and
                // updateSurveyStudyRecord() does not persist a NULL answer for a
                // broken expression. The error surfaces through
                // fmr_err.<name> instead.
                $code[$name] = self::R_ERROR_PREFIX . "{$name} = tryCatch({
" . $code[$name] . "
NULL
}, error = function(.e) conditionMessage(.e))";
            }
        }
        unset($item);

        return $code;
    }

    /**
     * Run one built batch through OpenCPU and fold the results back into the
     * items: visibility, dynamic values, the batched hidden-update, and the
     * per-expression error reporting.
     *
     * @param Item[] $items
     * @param array<string,string> $code
     * @return array
     */
    protected function applyShowIfAndValueResults(&$items, array $code) {
        $ocpu_session = opencpu_multiparse_showif($this->unitSession, $code, true);
        if (!$ocpu_session || $ocpu_session->hasError()) {
            notify_user_error(opencpu_debug($ocpu_session), "There was a problem evaluating showifs using openCPU.");
            foreach ($items as $name => &$item) {
                $item->alwaysInvalid();
            }
        } else {
            print_hidden_opencpu_debug_message($ocpu_session, "OpenCPU debugger for dynamic values and showifs.");
            $results = $ocpu_session->getJSONObject();

            // Per-expression R errors. The batch as a whole succeeded (that is
            // what the tryCatch wrappers buy us), but individual author
            // expressions may still have thrown — each left its message in a
            // `fmr_err.` sibling. Log them and, for admin/test/cron viewers,
            // show them; a value expression that threw additionally marks its
            // item always-invalid so a test session cannot submit past a broken
            // calculation.
            $rErrors = $this->collectRExpressionErrors($results);
            $this->reportRExpressionErrors($rErrors);
            $valueErrors = $rErrors['value'];

            $save = array();
            $visibilityUpdates = array(); // item_id => hidden (int|null), flushed in one UPDATE below

            $definitelyShownItems = 0;
            foreach ($items as $item_name => &$item) {
                // A `value` expression that threw must not silently render as an
                // empty/NA answer. In a debug context (admin, test session, cron)
                // make the item always-invalid, matching the pre-tryCatch
                // hasError() behaviour, so the breakage is impossible to miss.
                // Real participants are never blocked by an author's R bug —
                // they just get no dynamic value, and the log carries the error.
                if (isset($valueErrors[$item->name]) && $this->isDebugViewer()) {
                    $item->alwaysInvalid();
                }
                // set show-if visibility for items
                $siname = "si.{$item->name}";
                $isVisible = $item->setVisibility(array_val($results, $siname));
                // three possible states: 1 = hidden, 0 = shown, null = depends on JS on the page, render anyway
                if ($isVisible === null) {
                    // NA — the showif's dependency isn't answered yet (or the
                    // server can't resolve it). v1 prunes it to hidden=1 unless
                    // some items precede it. form_v2 overrides naShowifIsClient-
                    // Resolvable() so an item with a transpiled js_showif is always
                    // rendered (hidden=null) and revealed reactively by Alpine —
                    // fixing "gods/kittens showif shows up too late" (the item was
                    // pruned and only reappeared after a server round-trip) and
                    // keeping conditional/block guards in the DOM so the client owns
                    // their visibility. Server-only-variable showifs (e.g. a random
                    // `ran_group == 1`) are NOT NA here — the server resolves them
                    // to 0/1 above — so they still hide/show correctly.
                    if ($this->naShowifIsClientResolvable($item)) {
                        $hidden = null;
                        // ...but render it HIDDEN by default (CSS .hidden + disabled
                        // input). v1's client defaults an unevaluable showif to
                        // hidden (survey.js _hide=true); v2 must too, so an NA item
                        // doesn't flash visible / block before Alpine evaluates it
                        // (the iOS-Safari "page can't be submitted" symptom). Alpine
                        // reveals it the instant its showif is true.
                        $item->hideByDefaultPendingClient();
                    } else {
                        $hidden = $definitelyShownItems > 0 ? null : 1;
                    }
                } else {
                    $hidden = (int) !$isVisible;
                }
                // one batched hidden-update after the loop instead of a round
                // trip per item on the hottest render path (audit SQ-41)
                $visibilityUpdates[(int) $item->id] = $hidden;

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

            if ($visibilityUpdates) {
                // one batched hidden-update; null stays SQL NULL (3-state kept)
                $rows = array();
                foreach ($visibilityUpdates as $itemId => $hidden) {
                    $rows[(int) $itemId] = array('hidden' => $hidden);
                }
                $this->db->batchUpdateByKey('survey_items_display', 'item_id', $rows,
                    array(), array('session_id' => $this->unitSession->id));
            }

            // @TODO remove items from unanswerd if this is successfull
            if ($this->unitSession->updateSurveyStudyRecord($save, false)) {
                $this->answered($save);
            }
        }

        return $items;
    }

    /**
     * Whether the current viewer may see internal error detail: an admin
     * context with no run session at all, a cron/queue run, or a test session.
     * Real participants must never get an R stack detail (or a page-top error
     * banner) because a study author's expression is broken.
     *
     * @return bool
     */
    protected function isDebugViewer() {
        $run_session = Site::getInstance()->getRunSession();
        return !$run_session || $run_session->isCron() || $run_session->isTesting();
    }

    /**
     * Pull the per-expression R errors out of an opencpu_multiparse_showif()
     * result. Every showif / dynamic value is emitted as a tryCatch whose error
     * handler returns conditionMessage(.e) into a sibling element named
     * `fmr_err.si.<item>` (showif) or `fmr_err.<item>` (value); on success that
     * element is NULL and within() drops it, so a key here means "this
     * expression threw".
     *
     * @param mixed $results Decoded JSON object returned by the batch
     * @return array{showif: array<string,string>, value: array<string,string>}
     */
    protected function collectRExpressionErrors($results) {
        $errors = array('showif' => array(), 'value' => array());
        if (!is_array($results)) {
            return $errors;
        }

        $prefixLength = strlen(self::R_ERROR_PREFIX);
        foreach ($results as $key => $value) {
            if (!is_string($key) || strpos($key, self::R_ERROR_PREFIX) !== 0) {
                continue;
            }
            // jsonlite boxes length-1 vectors, so the message arrives as ["…"]
            $message = is_array($value) ? (string) reset($value) : (string) $value;
            $message = trim($message);
            if ($message === '') {
                continue;
            }
            $name = substr($key, $prefixLength);
            if (strpos($name, 'si.') === 0) {
                $errors['showif'][substr($name, 3)] = $message;
            } else {
                $errors['value'][$name] = $message;
            }
        }
        return $errors;
    }

    /**
     * Make per-expression R errors loud. Always logged server-side; shown in
     * full to admin / test / cron viewers via notify_user_error(), which is the
     * same gate NoteIframe_Item uses for its failed knits. Participants get
     * nothing — the tryCatch isolation exists precisely so one broken
     * expression doesn't paint the whole survey red for them.
     *
     * @param array{showif: array<string,string>, value: array<string,string>} $errors
     * @return void
     */
    protected function reportRExpressionErrors(array $errors) {
        $lines = array();
        foreach ($errors['showif'] as $name => $message) {
            $lines[] = "showif of item '{$name}': {$message}";
        }
        foreach ($errors['value'] as $name => $message) {
            $lines[] = "value of item '{$name}': {$message}";
        }
        if (!$lines) {
            return;
        }

        $detail = implode("\n", $lines);
        formr_log(sprintf(
            "R expression errors in study '%s' (unit session %s):\n%s",
            $this->study->name, (string) $this->unitSession->id, $detail
        ));

        if ($this->isDebugViewer()) {
            notify_user_error(
                nl2br(h($detail)),
                sprintf(_('%d expression(s) in this survey could not be evaluated in R.'), count($lines))
            );
        }
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
            if ($item->needsDynamicLabel($vars, $study->name)) {
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

        $itemsDisplayed = 0;

        $renderedItems = array();
        $renderedItemIds = array();

        try {
            foreach ($this->toRender as &$item) {
                // The items-per-page cap is a v1 concept: v1 re-renders per
                // request, so breaking here caps one page. FormRenderer's
                // toRender spans the whole document — the cap would truncate
                // the form and strand the participant — so v2 opts out.
                if ($this->applyMaxDisplayedCap && $study->maximum_number_displayed && $study->maximum_number_displayed === $itemsDisplayed) {
                    break;
                } else if ($item->isRendered()) {
                    // collect the display-count bump; one batched UPDATE below
                    // instead of a round trip per rendered item (audit SQ-42)
                    $renderedItemIds[] = (int) $item->id;

                    if (!$item->hidden) {
                        $itemsDisplayed++;
                    }

                    $renderedItems[] = $item;
                }
            }

            if ($renderedItemIds) {
                $in = implode(',', $renderedItemIds);
                $this->db->exec(
                    "UPDATE `survey_items_display`
                     SET displaycount = COALESCE(displaycount,0) + 1, created = COALESCE(created, NOW())
                     WHERE session_id = :session_id AND item_id IN ($in)",
                    array('session_id' => $this->unitSession->id)
                );
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
                ->where('survey_items.deleted IS NULL')   // soft-deleted items don't count toward progress (patch 090)
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
