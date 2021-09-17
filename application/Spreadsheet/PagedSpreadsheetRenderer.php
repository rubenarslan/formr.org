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
class PagedSpreadsheetRenderer extends SpreadsheetRenderer {

    protected $message = null;
    protected $maxPage = null;
    protected $postedValues = array();
    protected $answeredItems = array();
    protected $completed = false;
    protected $rendered = null;

    const FMR_PAGE_ELEMENT = 'fmr_unit_page_element';

    /**
     * Returns HTML page to be rendered for Survey or FALSE if survey ended
     *
     * @return string|boolean
     */
    public function processItems() {
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
            //alert('There are missing responses in your survey. Please proceed from here', 'alert-warning');
            $this->redirectToPage($prev);
        }

        if ($pageNo > $this->getMaxPage()) {
            $this->completed = true;
            return null;
        }

        $formAction = ''; //run_url($this->run->name, $pageNo);

        $this->renderedItems = $this->getPageItems($pageNo);
        $pageElement = $this->getPageElement($pageNo);
        Session::delete('is-survey-post');
        $this->rendered = parent::render($formAction, $pageElement);
    }
    
    public function studyCompleted() {
        return $this->completed;
    }
    
    public function render() {
        return $this->rendered;
    }

    /**
     * Save posted page item for specified Unit Session
     *
     */
    public function savePageItems() {
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

        // Mock the "posting" other items that are suppose to be on this page because user is leaving the page anyway
        // and hidden items must have been skipped for this session
        foreach ($pageItems as $name => $item) {
            if (isset($this->postedValues[$name])) {
                $oldValue = $item->value_validated;
                $item->value_validated = $this->postedValues[$name];
                if (!$item->requiresUserInput()) {
                    $item->skip_validation = true;
                    $item->value_validated = $oldValue;
                }
            } else {
                $item->skip_validation = true;
                // If item required user input but was not submitted then it was disabled on the page by show-if
                // so set it's value to NULL to revert any previously saved values
                if ($item->requiresUserInput()) {
                    $item->value_validated = null;
                }
            }

            //$item->value_validated = null;
            $this->postedValues[$name] = $item;
        }

        unset($this->postedValues['fmr_unit_page_element']);
        $save = $this->saveSuryeyItems($this->postedValues);
        if ($save) {
            Session::set('is-survey-post', true);
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
            'study_id' => $this->study->id,
            'page' => $pageNo,
        ));
        $stmt = $select->statement();

        // We initialise item factory with no choice list because we don't know which choices will be used yet.
        // This assumes choices are not required for show-ifs and dynamic values (hope so)
        $itemFactory = new ItemFactory(array());
        /* @var $pageItems Item[] */
        $pageItems = array();
        $processShowIfs = true;

        while ($item = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $hidden = $item['hidden'];
            if ($hidden !== null) {
                // show-ifs have been processed for this page
                $processShowIfs = false;
            }
            /* @var $oItem Item */
            $oItem = $itemFactory->make($item);
            $oItem->hidden = null;
            $visibility = $hidden === null ? true : (bool) !$hidden;
            $v = $oItem->type !== 'submit' ? $oItem->setVisibility(array($visibility)) : null;
            if ($hidden === null || Session::get('is-survey-post')) {
                $oItem->hidden = null;
            } else {
                $oItem->hidden = (int) $hidden;
            }

            $this->markItemAsShown($oItem);
            $pItem = array_val($this->postedValues, $oItem->name, $oItem->value_validated);
            $oItem->value_validated = $pItem instanceof Item ? $pItem->value_validated : $pItem;
            $pageItems[$oItem->name] = $oItem;

            if ($item['answered']) {
                $this->answeredItems[$oItem->name] = $oItem->value_validated;
            }

            if ($oItem->type === 'submit') {
                break;
            }
        }

        if (!$pageItems) {
            return array();
        }

        if ($process === false) {
            // Processing is skipped when user has submitted data and we just need to check if what submitted is what was requested
            return $pageItems;
        }

        $pageItems = $this->processAutomaticItems($pageItems);
        // Porcess show-ifs only when necessary i.e when user is not going to a previous page OR page is not being POSTed
        if ($processShowIfs || Session::get('is-survey-post') || $this->request->getParam('_rsi_')) {
            $pageItems = $this->processDynamicValuesAndShowIfs($pageItems);
        }
        $pageItems = $this->processDynamicLabelsAndChoices($pageItems);

        // add a submit button if none exists
        $lastItem = end($pageItems);
        if (($lastItem && $lastItem->type !== 'submit') || ($lastItem && $lastItem->hidden)) {
            $pageItems[] = $this->getSubmitButton();
        }

        //Check if there is any rendered item and if not, dummy post these and move to next page
        if (!$this->displayedItemExists($pageItems)) {
            $this->saveSuryeyItems($pageItems, false);
            Session::set('is-survey-post', true);
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
        $data = array(
            'progress' => $progress,
            'prevProgress' => ($currentPage - 1) / $maxPage,
            'pageProgress' => 1 / $maxPage,
            'page' => $currentPage,
            'maxPage' => $maxPage,
            'pageItems' => count($this->survey->rendered_items),
            'answeredItems' => count($this->answeredItems),
        );
        return $data;
    }

    /**
     * Get current unit session accessing the Survey
     *
     * @return UnitSession
     */
    protected function getUnitSession() {
        return $this->unitSession;
    }

    /**
     * 
     * @return Submit_Item
     */
    protected function getSubmitButton() {
        $opts = array(
            'label_parsed' => 'Continue  <i class="fa fa-arrow-circle-right pull-left"></i>',
            'classes_input' => array('btn-info default_formr_button'),
        );
        $submitButton = new Submit_Item($opts);
        $submitButton->input_attributes['value'] = 1;
        return $submitButton;
    }

    protected function getPageElement($pageNo) {
        $progress = $this->getSurveyProgress($pageNo);
        $progressAttribs = array();
        foreach ($progress as $attr => $value) {
            $progressAttribs[] = sprintf('%s="%s"', 'data-' . $attr, (string) $value);
        }

        $tpl = '<div class="col-md-12 text-right fmr-survey-page-count" %{progress_attributes}>
					<strong><span class="page-text">Page</span> %{page}/%{max_page}</strong>
					<input name="%{name}" value="%{value}" type="hidden" />
					<div class="clearfix"></div>
					<div class="btn-group page-buttons">
						%{buttons}
					</div>
				</div>
		';
        $buttons = '';
        for ($i = 1; $i < $pageNo; $i++) {
            $buttons .= Template::replace('<a class="btn btn-default btn-page-%{page_no}" data-page="%{page_no}" href="%{run_url}">%{page_no}</a>', array(
                        'run_url' => $this->getPageUrl($i),
                        'page_no' => $i
            ));
        }

        return Template::replace($tpl, array(
                    'page' => $pageNo,
                    'max_page' => $this->getMaxPage(),
                    'name' => self::FMR_PAGE_ELEMENT,
                    'value' => $pageNo,
                    'buttons' => $buttons ? '<span class="btn back-text" style="border: none;"> Back to Page </span>' . $buttons : null,
                    'progress_attributes' => implode(' ', $progressAttribs),
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
     * Mark as item as "to be shown"
     *
     * @param Item $item
     * @return Item
     */
    protected function markItemAsShown(&$item) {
        if ($item->hidden === 0) {
            $item->parent_attributes['data-show'] = true;
        }
        $item->data_showif = $item->js_showif ? true : false;
        return $item;
    }

    /**
     * Save Survey Items
     *
     * @param Item[] $items
     * @param boolean $validate
     */
    protected function saveSuryeyItems($items, $validate = true) {
        if (!$items) {
            return false;
        }

        if (!$validate) {
            foreach ($items as &$item) {
                if ($item instanceof Item) {
                    $item->skip_validation = true;
                }
            }
        }

        return $this->unitSession->updateSurveyStudyRecord($items, $validate);
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
        $redirect = $this->getPageUrl($page);
        redirect_to($redirect);
    }

    private function getPageUrl($page) {
        if ($page < 0) {
            $page = 1;
        }
        $params = array_diff_key($_REQUEST, $_POST);
        unset($params['route'], $params['run_name'], $params['code'], $params['_rsi_']);
        return run_url($this->run->name, $page, $params);
    }

}
