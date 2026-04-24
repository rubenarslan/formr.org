<?php

/**
 * FormRenderer — single-document renderer for form_v2 (Phase 1).
 *
 * Inherits the v1 processing pipeline (OpenCPU-evaluated showif/value/Rmd,
 * validation, item view tracking) from SpreadsheetRenderer, and replaces
 * only the HTML output: the form wrapper is v2-flavoured (BS5, data-submit-url,
 * progress bar) and all items land inside a `<section data-fmr-page>` so the
 * client-side runtime can switch pages without a round-trip.
 *
 * Phase 1 groups all items into a single page. Multi-page support using
 * survey_items_display.page is a follow-up.
 */
class FormRenderer extends SpreadsheetRenderer {

    /**
     * v1 `SpreadsheetRenderer::processItems` chunks items at each submit and only
     * renders the first chunk, because v1 renders one page at a time. The v2
     * client renders all pages up-front, so we process every unanswered item in
     * one batch (one OpenCPU call for showifs/values, one for labels/choices)
     * and let `render()` group by `survey_items_display.page` afterwards.
     */
    public function processItems() {
        $items = $this->getAllUnansweredItems();
        if ($items) {
            $items = $this->processAutomaticItems($items);
            // Phase 3: detect r(...) wrapped showifs. We populate survey_r_calls
            // here rather than at import so the allowlist auto-updates when an
            // admin edits the spreadsheet. We mutate $item->showif to the
            // unwrapped R so OpenCPU doesn't try to call a non-existent r()
            // function, and stash the call id on parent_attributes so render()
            // emits data-fmr-r-call without touching Item.php.
            foreach ($items as $item) {
                if (!$item || empty($item->showif)) continue;
                $inner = RAllowlistExtractor::unwrap($item->showif);
                if ($inner === null) continue;
                $callId = RAllowlistExtractor::record(
                    $this->db, $this->study->id, 'showif', $inner, $item->id
                );
                $item->showif = $inner;
                // Item::__construct transpiled the *wrapped* showif into js_showif.
                // That garbage would crash the client if emitted, so clear it —
                // r()-wrapped showifs have no client-side evaluation path.
                $item->js_showif = null;
                $item->parent_attributes['data-fmr-r-call'] = (string) $callId;
            }
            // Phase 4: same treatment for r(...)-wrapped value columns. Unwrap,
            // record in survey_r_calls with slot='value', then blank the value
            // so needsDynamicValue() returns false (Item::needsDynamicValue
            // trims empty to falsy) and the OpenCPU batch skips it entirely.
            // The client POSTs to /form-fill with the recorded call_id and
            // sets the input's value from the response.
            foreach ($items as $item) {
                if (!$item) continue;
                $raw = isset($item->value) ? trim((string) $item->value) : '';
                if ($raw === '') continue;
                $inner = RAllowlistExtractor::unwrap($raw);
                if ($inner === null) continue;
                $callId = RAllowlistExtractor::record(
                    $this->db, $this->study->id, 'value', $inner, $item->id
                );
                $item->value = '';
                $item->parent_attributes['data-fmr-fill-id'] = (string) $callId;
                // classes_wrapper is protected; rather than reach into it,
                // the client tags wrappers with .fmr-fill-pending on init
                // before the fetch fires.
            }
            $items = $this->processDynamicValuesAndShowIfs($items);
            if ($items) {
                // Hide any submit items — v2 provides its own nav.
                foreach ($items as $name => $item) {
                    if ($item->type === 'submit') {
                        $this->db->update('survey_items_display', ['hidden' => 1], [
                            'session_id' => $this->unitSession->id,
                            'item_id' => $item->id,
                        ]);
                        unset($items[$name]);
                    }
                }
                $items = $this->processDynamicLabelsAndChoices($items);
            }
        }
        $this->toRender = $items ?: [];
        $this->renderedItems = $this->getRenderedStudyItems();
    }

    /**
     * All unanswered items across all pages, no submit-chunking. Mirrors the
     * query in SpreadsheetRenderer::getNextStudyItems but without the
     * `$inPage` short-circuit.
     */
    protected function getAllUnansweredItems() {
        $this->unanswered = [];
        $stmt = $this->db->select('
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
            `survey_items_display`.display_order,
            `survey_items_display`.hidden,
            `survey_items_display`.answered')
            ->from('survey_items')
            ->leftJoin('survey_items_display', 'survey_items_display.session_id = :session_id', 'survey_items.id = survey_items_display.item_id')
            ->where('(survey_items.study_id = :study_id) AND (survey_items_display.saved IS null) AND (survey_items_display.hidden IS NULL OR survey_items_display.hidden = 0)')
            ->order('`survey_items_display`.`display_order`', 'asc')
            ->order('survey_items.`order`', 'asc')
            ->order('survey_items.id', 'asc')
            ->bindParams([
                'session_id' => $this->unitSession->id,
                'study_id' => $this->study->id,
            ])
            ->statement();

        $itemFactory = new ItemFactory([]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $item = $itemFactory->make($row);
            if (!$item) continue;
            $this->unanswered[$item->name] = $item;
            $out[$item->name] = $item;
        }
        return $out;
    }

    public function render($form_action = null, $form_append = null) {
        // Emit data-showif on every item with a showif expression, so the client
        // runtime can re-evaluate on each input change. v1 only set data_showif
        // when the server hid the item; v2 wants reactive visibility without a
        // round-trip. Skip items whose showif was an r() wrap — those go via
        // /form/r-call and have no local JS expression to evaluate.
        foreach ($this->renderedItems as $item) {
            if (!empty($item->parent_attributes['data-fmr-r-call'])) {
                continue;
            }
            if (!empty($item->showif) && !empty($item->js_showif)) {
                $item->data_showif = true;
            }
        }

        $itemsByPage = $this->groupByPage($this->renderedItems);
        $pageCount = count($itemsByPage);

        $html = '<div class="fmr-form-v2-outer study-' . (int) $this->study->id . '">';
        $html .= $this->renderV2Header();

        $i = 0;
        foreach ($itemsByPage as $pageNum => $pageItems) {
            $i++;
            $isLast = ($i === $pageCount);
            $isFirst = ($i === 1);
            $html .= sprintf(
                '<section class="fmr-page" data-fmr-page="%d"%s>',
                (int) $pageNum,
                $isFirst ? '' : ' hidden'
            );

            foreach ($pageItems as $item) {
                if (!empty($this->validationErrors[$item->name])) {
                    $item->error = $this->validationErrors[$item->name];
                }
                if (!empty($this->validatedItems[$item->name])) {
                    $item->value_validated = $this->validatedItems[$item->name]->value_validated;
                }
                // Skip any submit-type items the v1 renderer might emit; v2 draws its own nav.
                if (isset($item->type) && $item->type === 'submit') {
                    continue;
                }
                $html .= $item->render();
            }

            $html .= $this->renderPageNav(!$isFirst, $isLast);
            $html .= '</section>';
        }

        $html .= $this->renderV2Footer();
        $html .= '</div>';
        return $html;
    }

    /**
     * Group rendered items by page number. Pages are delimited at spreadsheet-
     * import time by submit-type items (see UnitSession::createSurveyStudyRecord
     * where the `page` counter bumps whenever a submit item is encountered).
     * We read that map back from survey_items_display and bucket items
     * accordingly, so multi-page forms render all pages in one document with
     * client-side navigation between them.
     *
     * @param Item[] $items
     * @return array<int, Item[]>
     */
    protected function groupByPage(array $items) {
        $pageMap = $this->fetchPageMap();
        $out = [];
        foreach ($items as $item) {
            $p = isset($pageMap[(int) $item->id]) ? (int) $pageMap[(int) $item->id] : 1;
            if (!isset($out[$p])) {
                $out[$p] = [];
            }
            $out[$p][] = $item;
        }
        if (empty($out)) {
            $out[1] = [];
        }
        ksort($out);
        return $out;
    }

    /**
     * @return array<int, int> item_id => page
     */
    protected function fetchPageMap() {
        $rows = $this->db->select('item_id, page')
            ->from('survey_items_display')
            ->where('session_id = :session_id')
            ->bindParams(['session_id' => $this->unitSession->id])
            ->fetchAll();
        $map = [];
        foreach ((array) $rows as $row) {
            if (isset($row['item_id'], $row['page'])) {
                $map[(int) $row['item_id']] = (int) $row['page'];
            }
        }
        return $map;
    }

    protected function renderV2Header() {
        $submitUrl = run_url($this->run->name, 'form-page-submit');
        $rcallUrl = run_url($this->run->name, 'form-r-call');
        $fillUrl = run_url($this->run->name, 'form-fill');
        $syncUrl = run_url($this->run->name, 'form-sync');
        $runUrl = run_url($this->run->name);
        $currentUser = Site::getCurrentUser();
        $userCode = $currentUser ? $currentUser->user_code : '';

        $html = sprintf(
            '<form class="fmr-form-v2" method="post" data-submit-url="%s" data-rcall-url="%s" data-fill-url="%s" data-sync-url="%s" data-run-url="%s" novalidate>',
            htmlspecialchars($submitUrl, ENT_QUOTES),
            htmlspecialchars($rcallUrl, ENT_QUOTES),
            htmlspecialchars($fillUrl, ENT_QUOTES),
            htmlspecialchars($syncUrl, ENT_QUOTES),
            htmlspecialchars($runUrl, ENT_QUOTES)
        );
        $html .= sprintf(
            '<input type="hidden" name="session_id" value="%s">',
            htmlspecialchars((string) $this->unitSession->id, ENT_QUOTES)
        );
        $html .= sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars(Session::REQUEST_USER_CODE, ENT_QUOTES),
            htmlspecialchars((string) $userCode, ENT_QUOTES)
        );

        $html .= '<div class="fmr-progress">'
               . '<div class="fmr-progress-track">'
               . '<div class="fmr-progress-bar" data-fmr-progress-bar role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>'
               . '</div>'
               . '<span class="fmr-progress-label" data-fmr-progress-label></span>'
               . '</div>';

        if (!empty($this->validationErrors)) {
            $html .= '<div class="alert alert-danger fmr-error-messages" role="alert">'
                   . '<ul>' . $this->renderErrors() . '</ul>'
                   . '</div>';
        }

        return $html;
    }

    protected function renderV2Footer() {
        return '</form>';
    }

    protected function renderPageNav($showPrev, $isLast) {
        $nextLabel = $isLast ? 'Submit' : 'Next';
        $nextIcon = $isLast ? 'fa-check' : 'fa-arrow-right';
        $left = $showPrev
            ? '<button type="button" class="btn btn-outline-secondary" data-fmr-prev><i class="fa fa-arrow-left"></i> Previous</button>'
            : '';
        return sprintf(
            '<div class="fmr-page-nav"><div class="fmr-page-nav__left">%s</div>'
            . '<div class="fmr-page-nav__right">'
            . '<button type="submit" class="btn btn-primary" data-fmr-next>%s <i class="fa %s"></i></button>'
            . '</div></div>',
            $left,
            htmlspecialchars($nextLabel, ENT_QUOTES),
            htmlspecialchars($nextIcon, ENT_QUOTES)
        );
    }
}
