<?php

/**
 * Helper class to handle custom survey execution
 *
 * @TODO what should happen if max items displayed is exceeded.
 * A user selecting using this paging option should not even need that setting.
 * 
 * @TODO update displaycount?
 *
 */
class SurveyHelper {

	/**
	 *
	 * @var Request 
	 */
	protected $request;

	/**
	 * @var Run
	 */
	protected $run;

	/**
	 * @var Survey
	 */
	protected $survey;

	/**
	 *
	 * @var DB
	 */
	protected $db;

	/**
	 *
	 * @var UnitSession
	 */
	protected $unitSession;

	protected $errors = array();
	protected $message = null;

	protected $maxPage = null;

	protected $postedValues = array();

	const FMR_PAGE_ELEMENT = 'fmr_unit_page_element';

	public function __construct(Request $rq, Survey $s, Run $r) {
		$this->request = $rq;
		$this->survey = $s;
		$this->run = $r;
		$this->db = $s->dbh;
	}

	/**
	 * Returns HTML page to be rendered for Survey or FALSE if survey ended
	 *
	 * @return string|boolean
	 */
	public function renderSurvey($unitSessionId) {
		$unitSession = $this->getUnitSession($unitSessionId);
		if (!Request::getGlobals('pageNo')) {
			$pageNo = $this->getCurrentPage();
			$this->redirectToPage($pageNo);
		}

		$pageNo = $this->getCurrentPage();
		if ($pageNo < 1) {
			throw new Exception('Invalid Survey Page');
		}

		// Check if user is allowed to enter this page
		if ($prev = $this->emptyPreviousPageExists($pageNo)) {
			alert('There are missing responses in your survey. Please proceed from here', 'alert-warning');
			$this->redirectToPage($prev);
		}

		if ($pageNo > $this->getMaxPage()) {
			$this->survey->end();
			return false;
		}

		$formAction = ''; //run_url($this->run->name, $pageNo);
		$pageElement = $this->getPageElement($pageNo);

		$this->survey->rendered_items = $this->getPageItems($pageNo);
		return $this->survey->render($formAction, $pageElement);
	}

	/**
	 * Save posted page item for specified Unit Session
	 *
	 * @param int $unitSessionId
	 */
	public function savePageItems($unitSessionId) {
		$unitSession = $this->getUnitSession($unitSessionId);
		if (!Request::isHTTPPostRequest()) {
			// Accept only POST requests
			return;
		}

		if ($this->request->getParam(self::FMR_PAGE_ELEMENT) != $this->getCurrentPage()) {
			throw new Exception('Invalid Survey Page');
		}

		$currPage = $this->request->getParam(self::FMR_PAGE_ELEMENT);

		$pageItems = $this->getPageItems($currPage, false);
		$this->postedValues = $this->request->getParams();

		// Mock submit other items that are suppose to be on this page because user is leaving the page anyway and hidden items must have been skipped for this session
		foreach ($pageItems as $name => $item) {
			if (isset($this->postedValues[$name])) {
				continue;
			}
			$item->skip_validation = true;
			//$item->value_validated = null;
			$this->postedValues[$name] = $item;
		}

		unset($this->postedValues['fmr_unit_page_element']);
		$save = $this->survey->post($this->postedValues);
		if ($save) {
			$currPage++;
			$this->redirectToPage($currPage);
		}
	}

	/**
	 * Get Items to be displayed on indicated page No
	 *
	 * @param int $pageNo
	 * @param boolean $process
	 * @return Item[]
	 */
	protected function getPageItems($pageNo, $process = true) {
		$select = $this->db->select('
				survey_items.id, survey_items.study_id, survey_items.type, survey_items.choice_list, survey_items.type_options, survey_items.name, survey_items.label, survey_items.label_parsed, survey_items.optional, survey_items.class, survey_items.showif, survey_items.value,
				survey_items_display.displaycount, survey_items_display.session_id, survey_items_display.display_order, survey_items_display.hidden, survey_items_display.answer as value_validated, survey_items_display.answered, survey_items_display.page');
		$select->from('survey_items');
		$select->leftJoin('survey_items_display', 'survey_items_display.session_id = :session_id', 'survey_items_display.item_id = survey_items.id');
		$select->where('survey_items.study_id = :study_id AND survey_items_display.page = :page');
		$select->order('survey_items_display.display_order', 'asc');
		$select->order('survey_items.order', 'asc'); // only needed for transfer
		$select->order('survey_items.id', 'asc');

		$select->bindParams(array(
			'session_id' => $this->unitSession->id,
			'study_id' => $this->survey->id,
			'page' => $pageNo,
		));
		$stmt = $select->statement();

		// We initialise item factory with no choice list because we don't know which choices will be used yet.
		// This assumes choices are not required for show-ifs and dynamic values (hope so)
		$itemFactory = new ItemFactory(array());
		/* @var $pageItems Item[] */
		$pageItems = array();

		while ($item = $stmt->fetch(PDO::FETCH_ASSOC)) {
			/* @var $oItem Item */
			$oItem = $itemFactory->make($item);
			$oItem->hidden = null;
			$pItem = array_val($this->postedValues, $oItem->name, $oItem->value_validated);
			$oItem->value_validated = $pItem instanceof Item ? $pItem->value_validated : $pItem;
			$pageItems[$oItem->name] = $oItem;

			if ($oItem->type === 'submit') {
				break;
			}
		}

		// add a submit button if none exists
		$lastItem = end($pageItems);
		if ($lastItem && $lastItem->type !== 'submit') {
			$pageItems[] = $this->getSubmitButton();
		}

		if (!$pageItems) {
			return array();
		}

		if ($process === false) {
			// Processing is skipped when user has submitted data and we just need to check if what submitted is what was requested
			return $pageItems;
		}

		$pageItems = $this->processAutomaticItems($pageItems);
		$pageItems = $this->processDynamicValuesAndShowIfs($pageItems);
		$pageItems = $this->processDynamicLabelsAndChoices($pageItems);

		//Check if there is any rendered item and if not, dummy post these and move to next page
		if (!$this->displayedItemExists($pageItems)) {
			$this->survey->post($pageItems, false);
			$pageNo++;
			$this->redirectToPage($pageNo);
		}
		return $pageItems;
	}

	protected function getCurrentPage() {
		// Check if page exists in Request::globals();
		if ($page = Request::getGlobals('pageNo')) {
			return $page;
		}

		// If page is not in request then get from DB
		$query = '
			SELECT itms_display.page FROM survey_items_display AS itms_display
			WHERE itms_display.session_id = :unit_session_id AND itms_display.answered IS NULL
			ORDER BY itms_display.display_order ASC
			LIMIT 1;
		';

		$stmt = $this->db->prepare($query);
		$stmt->bindValue('unit_session_id', $this->unitSession->id, PDO::PARAM_INT);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($row && !empty($row['page'])) {
			return $row['page'];
		}

		// If all the above fail then we are on first page
		return 1;
	}

	protected function getMaxPage() {
		if ($this->maxPage === null) {
			$this->maxPage = $this->db->findValue('survey_items_display', array('session_id' => $this->unitSession->id), 'MAX(page) as maxPage');
		}
		return $this->maxPage;
	}

	protected function getSurveyProgress($currentPage) {
		/* @TODO Fix progress counts */
		$maxPage = $this->getMaxPage();
		if (!$maxPage) {
			return;
		}
		$progress = $currentPage / $maxPage;
		$this->survey->progress = $progress;
		$this->survey->progress_counts = array(
			'already_answered' => 0,
			'not_answered' => 0,
			'hidden_but_rendered' => 0,
			'not_rendered' => 0,
			'visible_on_current_page' => 0,
			'hidden_but_rendered_on_current_page' => 0,
			'not_answered_on_current_page' => 0
		);
	}

	/**
	 * Get current unit session accessing the Survey
	 *
	 * @param int $unitSessionId
	 * @return UnitSession
	 */
	protected function getUnitSession($unitSessionId) {
		if (!$this->unitSession) {
			$this->unitSession = new UnitSession($this->db, null, null, $unitSessionId);
		}
		return $this->unitSession;
	}

	/**
	 * 
	 * @return Submit_Item
	 */
	protected function getSubmitButton () {
		$opts = array(
			'label_parsed' => 'Continue  <i class="fa fa-arrow-circle-right pull-left"></i>',
			'class_input' => 'btn-info default_formr_button',
		);
		$submitButton = new Submit_Item($opts);
		$submitButton->input_attributes['value'] = 1;
		return $submitButton;
	}

	protected function getPageElement($pageNo) {
		$tpl = '<div class="col-md-12 text-right" class="fmr-survey-page-count">
					<strong>Page %{page}/%{max_page}</strong>
					<input name="%{name}" value="%{value}" type="hidden" />
				</div>
		';
		return Template::replace($tpl, array(
			'page' => $pageNo,
			'max_page' => $this->getMaxPage(),
			'name' => self::FMR_PAGE_ELEMENT,
			'value' => $pageNo,
		));
	}

	protected function emptyPreviousPageExists($pageNo) {
		$prev = $pageNo - 1;
		if ($prev < 1) {
			return false;
		}

		$query = array(
			'survey_items_display.page <=' => $prev,
			'survey_items_display.session_id' => $this->unitSession->id
		);

		$select = $this->db->select('survey_items_display.item_id, survey_items_display.page');
		$select->from('survey_items_display');
		$select->where($query);
		$select->where('survey_items_display.answered IS NULL');
		$select->order('survey_items_display.page', 'ASC');
		$select->limit(1);
		$row = $select->statement()->fetch(PDO::FETCH_ASSOC);
		if ($row) {
			return $row['page'];
		}
		return false;
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
			if (!$item->requiresUserInput() && !$item->needsDynamicValue()) {
				$hiddenItems[$name] = $item->getComputedValue();
				//unset($items[$name]);
				continue;
			}
		}

		// save these values
		if ($hiddenItems) {
			$this->survey->post($hiddenItems, false);
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
			// 1. Check item's show-if
			$showif = $item->getShowIf();
			if ($showif) {
				$siname = "si.{$name}";
				$showif = str_replace("\n", "\n\t", $showif);
				$code[$siname] = "{$siname} = (function(){ \n {$showif} \n})()";
			}

			// 2. Check item's value
			if ($item->needsDynamicValue()) {
				$val = str_replace("\n", "\n\t", $item->getValue($this->survey));
				$code[$name] = "{$name} = (function(){ \n {$val} \n })()";
				if ($showif) {
					$code[$name] = "if({$siname}) { \n {$code[$name]} \n }";
				}
				// If item is to be shown (rendered), return evaluated dynamic value, else keep dynamic value as string
			}
		}

		if (!$code) {
			return $items;
		}

		$ocpu_session = opencpu_multiparse_showif($this->survey, $code, true);
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
				$updateVisibility->bindValue(':item_id', $item->id);
				$updateVisibility->bindValue(':hidden', $hidden);
				$updateVisibility->execute();

				if ($hidden === 1) { // gone for good
					//unset($items[$item_name]); // we remove items that are definitely hidden from consideration
					continue; // don't increment counter
				} else {
					// set dynamic values for items
					$val = array_val($results, $item->name, null);
					$item->setDynamicValue($val);
					// save dynamic value
					// if a. we have a value b. this item does not require user input (e.g. calculate)
					if (array_key_exists($item->name, $results) && !$item->requiresUserInput()) {
						$save[$item->name] = $item->getComputedValue();
						//unset($items[$item_name]); // we remove items that are immediately written from consideration
						continue; // don't increment counter
					}
					if (!$item->isHiddenButRendered() && $item->isRendered()) {
						$item->parent_attributes['data-show'] = true;
						$item->data_showif = $item->js_showif ? true : false;
					}
				}
				$definitelyShownItems++; // track whether there are any items certain to be shown
			}
			$this->survey->post($save, false);
		}

		return $items;
	}

	protected function processDynamicLabelsAndChoices(&$items) {
		// Gather choice lists
		$lists_to_fetch = $strings_to_parse = array();
		foreach ($items as $name => &$item) {
			if ($item->choice_list) {
				$lists_to_fetch[] = $item->choice_list;
			}

			if ($item->needsDynamicLabel($this->survey)) {
				$items[$name]->label_parsed = opencpu_string_key(count($strings_to_parse));
				$strings_to_parse[] = $item->label;
			}
		}

		// gather and format choice_lists and save all choice labels that need parsing
		$choices = $this->survey->getChoices($lists_to_fetch, null);
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
			$parsed_strings = opencpu_multistring_parse($this->survey, $strings_to_parse);
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
		}

		return $items;
	}

	/**
	 * Checks if a displayed (rendered and visible) item exists in an array of items
	 *
	 * @param Item[] $items
	 * @return boolean
	 */
	protected function displayedItemExists(&$items) {
		foreach ($items as $item) {
			if ($item->isRendered() && !$item->hidden && $item->type !== 'submit') {
				return true;
			}
		}
		return false;
	}

	private function redirectToPage($page) {
		if ($page < 0) {
			$page = 1;
		}
		$params = array_diff_key($_REQUEST, $_POST);
		unset($params['route'], $params['run_name'], $params['code']);
		$redirect = run_url($this->run->name, $page, $params);
		redirect_to($redirect);
	}

}
