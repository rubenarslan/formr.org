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
     * Page-scoped processing for form_v2 (post-PWA-port redesign).
     *
     * Architectural rules (see plan_form_v2.md §3 / §5):
     *
     *   1. `showif` is JS-only. `r(...)` in showif is invalid and surfaces a
     *      validation error to the admin at render time. To run server-side R
     *      and have the result feed a showif, the admin uses a hidden item
     *      with `value: r(...)` and references that field name from the JS
     *      showif.
     *   2. `value` accepts literals + `r(...)` wraps. Bare R (not r-wrapped)
     *      is invalid and surfaces a validation error. r()-wrapped values
     *      record into `survey_r_calls` (slot='value') and resolve via
     *      `/form-render-page` at page-transition time.
     *   3. Dynamic labels (Item::needsDynamicLabel) record into
     *      `survey_r_calls` (slot='label') with the FULL label as `expr` —
     *      we don't extract partial Rmd chunks; the whole label is one
     *      allowlisted call, item-keyed.
     *   4. OpenCPU evaluation is page-scoped: at initial render only the
     *      first VISIBLE page (lowest page number among unanswered items)
     *      is resolved through OpenCPU. Items on later pages get their
     *      `data-fmr-fill-id` / `data-fmr-label-id` placeholders but no
     *      OpenCPU call yet — those resolve when the participant submits
     *      the prior page and the client POSTs `/form-render-page`.
     */
    public function processItems() {
        $items = $this->getAllUnansweredItems();
        if (!$items) {
            $this->toRender = [];
            $this->renderedItems = $this->getRenderedStudyItems();
            return;
        }

        $items = $this->processAutomaticItems($items);

        // Compute the first visible page once. The page-scope decisions
        // below (resolve inline vs. defer to /form-render-page) all key off
        // this number.
        $pageMap = $this->fetchPageMap();
        $firstPage = $this->firstVisiblePageNumberFromMap($items, $pageMap);
        $itemPageOf = function ($item) use ($pageMap) {
            return isset($pageMap[(int) $item->id]) ? (int) $pageMap[(int) $item->id] : 1;
        };

        // Step 1: reject r(...) showifs as invalid. Showifs are JS-only.
        foreach ($items as $item) {
            if (!$item || empty($item->showif)) continue;
            if (RAllowlistExtractor::unwrap($item->showif) !== null) {
                $this->validationErrors[$item->name] =
                    'r(...) is no longer supported in `showif`. '
                    . 'Add a hidden item with `value: r(...)` and reference its '
                    . 'name from the showif (which is now JS-only).';
                $item->showif = '';
                $item->js_showif = null;
            }
        }

        // Step 2: r(...) values. Allowlist + page-scope:
        //   - First-page items get the inner R substituted into $item->value
        //     so the parent's processDynamicValuesAndShowIfs evaluates them
        //     server-side at this render. The participant sees a resolved
        //     value as soon as the page lands.
        //   - Later-page items get their value blanked + data-fmr-fill-id
        //     emitted; client resolves them at page transition via
        //     /form-render-page (with the participant's actual answers).
        foreach ($items as $item) {
            if (!$item) continue;
            $raw = isset($item->value) ? trim((string) $item->value) : '';
            if ($raw === '') continue;
            $inner = RAllowlistExtractor::unwrap($raw);
            if ($inner !== null) {
                $callId = RAllowlistExtractor::record(
                    $this->db, $this->study->id, 'value', $inner, $item->id
                );
                $item->parent_attributes['data-fmr-fill-id'] = (string) $callId;
                if ($itemPageOf($item) === $firstPage) {
                    // Substitute the unwrapped R into $item->value so the
                    // existing OpenCPU batch evaluates it at render time.
                    $item->value = $inner;
                } else {
                    $item->value = '';
                }
                continue;
            }
            // Not r-wrapped. Literal numeric / `sticky` / identifier — let the
            // existing pipeline handle. Otherwise: bare R is no longer valid.
            if (self::looksLikeBareR($raw)) {
                $this->validationErrors[$item->name] =
                    'Bare R in `value` is no longer supported. Wrap the expression in r(...) '
                    . '(e.g. `r(' . $raw . ')`) so it goes through the allowlisted server-side path.';
                $item->value = '';
            }
        }

        // Step 3: dynamic labels. Same page-scoping. The full label text is
        // the allowlisted expression — we don't extract partial Rmd chunks.
        // First-page items keep label_parsed = null so the parent's
        // processDynamicLabelsAndChoices resolves them. Later-page items get
        // label_parsed = '' which short-circuits the parent's "needs parsing"
        // detection; client resolves them at page transition.
        foreach ($items as $item) {
            if (!$item) continue;
            if (!$item->needsDynamicLabel(
                $this->unitSession->getRunData((string) $item->label, $this->study->name),
                $this->study->name
            )) {
                continue;
            }
            $labelSrc = (string) $item->label;
            if ($labelSrc === '') continue;
            $callId = RAllowlistExtractor::record(
                $this->db, $this->study->id, 'label', $labelSrc, $item->id
            );
            $item->parent_attributes['data-fmr-label-id'] = (string) $callId;
            if ($itemPageOf($item) !== $firstPage) {
                $item->label_parsed = '';
            }
        }

        // Step 4: hide submit-type items (v2 supplies its own nav).
        foreach ($items as $name => $item) {
            if ($item && $item->type === 'submit') {
                $this->db->update('survey_items_display', ['hidden' => 1], [
                    'session_id' => $this->unitSession->id,
                    'item_id' => $item->id,
                ]);
                unset($items[$name]);
            }
        }

        // Step 5: page-scoped OpenCPU resolution. Run the parent's batches
        // over first-page items only — later pages have placeholders + IDs
        // and resolve at page transition via /form-render-page.
        $byPage = $this->splitByPageFromMap($items, $firstPage, $pageMap);
        $firstPageItems = $byPage['first'];

        if ($firstPageItems) {
            $firstPageItems = $this->processDynamicValuesAndShowIfs($firstPageItems);
            if ($firstPageItems) {
                $firstPageItems = $this->processDynamicLabelsAndChoices($firstPageItems);
            }
        }

        // Step 6: merge first-page-resolved items back with the placeholder
        // items from later pages, preserving original order.
        $merged = [];
        foreach ($items as $name => $item) {
            if (isset($firstPageItems[$name])) {
                $merged[$name] = $firstPageItems[$name];
            } else {
                $merged[$name] = $item;
            }
        }

        $this->toRender = $merged;
        $this->renderedItems = $this->getRenderedStudyItems();
    }

    /**
     * Smell test for "value column contains R-shaped code that wasn't wrapped
     * in r(...)". Looks for a function-call pattern (`name(`), an R operator
     * pattern, or a `$`-member access. Tolerates plain identifiers (admins
     * occasionally use a bare variable name as a default value via the v1
     * "value column = identifier" sugar) and `sticky` (v1 keyword).
     */
    protected static function looksLikeBareR($raw) {
        if ($raw === 'sticky') return false;
        // Identifier-only (admin's v1 sugar — keep as-is, the existing
        // dynamic-value path resolves these).
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $raw)) return false;
        if (preg_match('/[a-zA-Z_]\w*\s*\(/', $raw)) return true;          // function call
        if (preg_match('/<-|->|%[a-zA-Z_]+%|\$[A-Za-z_]/', $raw)) return true; // R ops
        return false;
    }

    /**
     * Lowest page number among `survey_items_display.page` for the given items.
     * That's the page the participant lands on (the server only emits
     * unanswered items, so this is whatever's currently active).
     */
    protected function firstVisiblePageNumberFromMap(array $items, array $pageMap) {
        $min = null;
        foreach ($items as $item) {
            if (!$item) continue;
            $p = isset($pageMap[(int) $item->id]) ? (int) $pageMap[(int) $item->id] : 1;
            if ($min === null || $p < $min) $min = $p;
        }
        return $min === null ? 1 : $min;
    }

    /**
     * Partition items into [first => first-visible-page, later => everything else].
     */
    protected function splitByPageFromMap(array $items, $firstPage, array $pageMap) {
        $first = [];
        $later = [];
        foreach ($items as $name => $item) {
            $p = isset($pageMap[(int) $item->id]) ? (int) $pageMap[(int) $item->id] : 1;
            if ($p === (int) $firstPage) {
                $first[$name] = $item;
            } else {
                $later[$name] = $item;
            }
        }
        return ['first' => $first, 'later' => $later];
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

    /**
     * Item `type` values whose v2 wiring is incomplete. As of the SW +
     * PWA-port pass, only audio/video remain — both inherit from File_Item
     * and submit through the multipart path in principle, but the
     * getUserMedia capture UX hasn't been smoke-tested cross-browser. The
     * banner in renderUnverifiedTypesNotice prompts admins to verify the
     * capture before relying on it in a live study. See plan_form_v2.md §8
     * P0 for the remaining smoke work.
     */
    protected static $unverifiedTypes = ['audio', 'video'];

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
        $html .= $this->renderUnverifiedTypesNotice();
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

        $offlineMode = !empty($this->study->offline_mode) ? 'on' : 'off';
        $allowPrevious = !empty($this->study->allow_previous) ? 'on' : 'off';
        // `form-horizontal` is v1's class for the selectors in
        // webroot/assets/common/css/custom_item_classes.css (mc_width*,
        // rotate_label*, mc_vertical, mc_block, rating_button_label_width*,
        // …) — the admin-choosable layout modifiers. Keep it on the v2 form
        // so those classes keep working without a parallel scss port.
        $html = sprintf(
            '<form class="fmr-form-v2 form-horizontal" method="post" data-submit-url="%s" data-rcall-url="%s" data-fill-url="%s" data-sync-url="%s" data-run-url="%s" data-offline-mode="%s" data-allow-previous="%s" novalidate>',
            htmlspecialchars($submitUrl, ENT_QUOTES),
            htmlspecialchars($rcallUrl, ENT_QUOTES),
            htmlspecialchars($fillUrl, ENT_QUOTES),
            htmlspecialchars($syncUrl, ENT_QUOTES),
            htmlspecialchars($runUrl, ENT_QUOTES),
            $offlineMode,
            $allowPrevious
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

    /**
     * Notice listing item types that haven't been smoke-tested in v2 yet.
     * Rendered above the form header when the survey contains any such type;
     * omitted entirely otherwise. Intentionally not gated by admin role — the
     * participant subdomain is a separate origin so the admin cookie isn't
     * visible there, and a mild "we haven't verified this everywhere" hint is
     * cheaper than missing real-world UX bugs because nobody saw the banner.
     * Not a hard gate: the items still render and submit through the normal
     * v1-inherited pipeline.
     */
    protected function renderUnverifiedTypesNotice() {
        $present = [];
        foreach ($this->renderedItems as $item) {
            if (!isset($item->type)) continue;
            if (in_array($item->type, self::$unverifiedTypes, true) && !in_array($item->type, $present, true)) {
                $present[] = $item->type;
            }
        }
        if (empty($present)) {
            return '';
        }
        $list = htmlspecialchars(implode(', ', $present), ENT_QUOTES);
        return '<div class="alert alert-warning fmr-unverified-types" role="status">'
             . '<strong>Heads up:</strong> this form uses '
             . '<code>' . $list . '</code> '
             . '— these item types have not yet been end-to-end smoke-tested on form_v2. They render and submit through the same path as File_Item, but capture UX may vary by browser.'
             . '</div>';
    }

    protected function renderPageNav($showPrev, $isLast) {
        $nextLabel = $isLast ? 'Submit' : 'Next';
        $nextIcon = $isLast ? 'fa-check' : 'fa-arrow-right';
        // Previous button is opt-in per form (SurveyStudy.allow_previous).
        $allowPrev = $showPrev && !empty($this->study->allow_previous);
        $left = $allowPrev
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
