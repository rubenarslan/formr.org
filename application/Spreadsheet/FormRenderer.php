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

    public function render($form_action = null, $form_append = null) {
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
        $runUrl = run_url($this->run->name);
        $currentUser = Site::getCurrentUser();
        $userCode = $currentUser ? $currentUser->user_code : '';

        $html = sprintf(
            '<form class="fmr-form-v2" method="post" data-submit-url="%s" data-run-url="%s" novalidate>',
            htmlspecialchars($submitUrl, ENT_QUOTES),
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
