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
    /**
     * In form_v2 a showif with a transpiled js_showif (and not routed through
     * the server r-call path) is evaluated reactively client-side by Alpine. So
     * when the server-side initial evaluation returns NA (dependency not yet
     * answered), the item must NOT be pruned to hidden=1 / dropped — keep it
     * rendered (initially .hidden) so picking the dependency reveals it instantly
     * without a server round-trip. (This was the "gods/kittens showif shows up
     * too late" bug.)
     */
    protected function naShowifIsClientResolvable($item) {
        if (!$item) {
            return false;
        }
        if (!empty($item->parent_attributes['data-fmr-r-call'])) {
            return false; // server-resolved showif, not a JS expression
        }
        return !empty($item->js_showif);
    }

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

        // Step 2: dynamic values. The `value` column carries R that we
        // route through the allowlist; admins can author it bare or
        // wrapped in r(...) — the latter is the documented bridge for
        // surfacing R into a JS-only showif (see documentation/form_v2.md
        // §"Bridging R into a showif"). v1's Item::needsDynamicValue
        // already returns false for empty / numeric values (those stay
        // as literal defaults).
        //   - Unwrap r(...) before recording. base+formr R has no r()
        //     function, so leaving the wrapper in survey_r_calls.expr
        //     would make every subsequent OpenCPU eval return NA via
        //     tryCatch, silently breaking the documented bridge example.
        //   - Record the (unwrapped) R text in survey_r_calls slot='value'.
        //     This is what authorizes the client to invoke it via
        //     /form-render-page — the client never gets the source, only
        //     the call_id.
        //   - First-page items keep $item->value untouched so the parent's
        //     processDynamicValuesAndShowIfs evaluates it inline at this
        //     render; the participant sees the resolved value immediately.
        //   - Later-page items get $item->value blanked + data-fmr-fill-id
        //     emitted; the client resolves them at page transition.
        foreach ($items as $item) {
            if (!$item) continue;
            if (!$item->needsDynamicValue()) continue;
            $rawValue = trim((string) $item->value);
            if ($rawValue === '') continue;
            $rExpr = RAllowlistExtractor::unwrap($rawValue) ?? $rawValue;
            $callId = RAllowlistExtractor::record(
                $this->db, $this->study->id, 'value', $rExpr, $item->id
            );
            $item->parent_attributes['data-fmr-fill-id'] = (string) $callId;
            if ($itemPageOf($item) !== $firstPage) {
                $item->value = '';
            } elseif ($rExpr !== $rawValue) {
                // First-page item with r(...) wrapper: hand the parent
                // the unwrapped expr so its inline OpenCPU batch evaluates
                // the same code as later-page items get via /form-fill.
                $item->value = $rExpr;
            }
        }

        // Step 3: dynamic labels. The full label text is the allowlisted
        // expression — we don't extract partial Rmd chunks. ALL pages
        // (including first) defer to /form-render-page: blank label_parsed
        // and emit data-fmr-label-id; the client batch-resolves on page
        // show. Costs a brief first-paint flicker on page 1 in exchange for
        // architectural consistency + cache hits via patch 062 — admins who
        // can't tolerate the flicker should keep dynamic content off the
        // first page (plan_form_v2 §8 P2 trade-off).
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
            $item->label_parsed = '';
        }

        // Step 4: submit-type items are page boundaries AND the page's advance
        // control. Keep them in $items so they flow into toRender → render() as
        // their own big centred button (the goal: "submit = a big button, no
        // separate OK"); the solo controller upgrades them to .fmr-solo-bigsubmit
        // and non-solo wires their data-fmr-next to submitPage(). They are kept
        // OUT of the completion count in getAllUnansweredItems() (like block/blank)
        // and excluded from "answered" by getStudyProgress()'s SQL, so rendering
        // them can't block studyCompleted(). (Previously they were marked hidden
        // and stripped here, which left the big-button JS/CSS dead code.)

        // Step 4b: attach choices to ALL items (no OpenCPU). Step 5's
        // first-page-only label/choice pass would otherwise leave later-page
        // mc/select items with an empty choice list — an unanswerable screen.
        $this->attachStaticChoices($items);

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
     * Attach choice options to every item that has a choice_list, using the
     * choice labels straight from the DB (no OpenCPU).
     *
     * The parent's processDynamicLabelsAndChoices does TWO things — parse
     * dynamic labels via OpenCPU *and* call setChoices() — but FormRenderer
     * only runs it on the first visible page (Step 5) to keep OpenCPU
     * page-scoped. The side effect was that later-page mc / mc_heading /
     * select items rendered with an empty `.mc-table` (no radios at all), so a
     * required choice question on page 2+ became an unanswerable dead screen.
     * Choices don't need OpenCPU unless a choice *label* carries dynamic R, so
     * attach them here for ALL pages; first-page items are re-set with the
     * OpenCPU-parsed labels when Step 5 runs. Dynamic choice labels on later
     * pages degrade to their raw source text rather than disappearing.
     */
    protected function attachStaticChoices(array &$items) {
        $lists = [];
        foreach ($items as $item) {
            if ($item && $item->choice_list) {
                $lists[] = $item->choice_list;
            }
        }
        if (!$lists) {
            return;
        }
        $rows = $this->study->getChoices(array_values(array_unique($lists)), null);
        $byList = [];
        foreach ((array) $rows as $row) {
            if (!isset($row['list_name'], $row['name'])) {
                continue;
            }
            $parsed = $row['label_parsed'] ?? null;
            $byList[$row['list_name']][$row['name']] =
                ($parsed !== null && $parsed !== '') ? $parsed : $row['label'];
        }
        foreach ($items as $name => $item) {
            if (!$item || !$item->choice_list) {
                continue;
            }
            if (isset($byList[$item->choice_list])) {
                $list = array_filter($byList[$item->choice_list], 'is_formr_truthy');
                $items[$name]->setChoices($list);
            }
        }
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
            // mc_heading is the disabled header row of an mc matrix (constant
            // hidden value=1, save_in_results_table=false). v2 doesn't render
            // matrices as a unit, so it would otherwise surface as a blank,
            // `required` step that can never be answered and blocks completion.
            // Exclude it from the v2 pipeline entirely (v1 unaffected).
            ->where('(survey_items.study_id = :study_id) AND (survey_items.deleted IS NULL) AND (survey_items.type != \'mc_heading\') AND (survey_items_display.saved IS null) AND (survey_items_display.hidden IS NULL OR survey_items_display.hidden = 0)')
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
            // Display-only items that render but can NEVER be "answered" must be
            // rendered (kept in $out) yet NEVER counted toward completion — else
            // they keep not_answered > 0 forever and the form can't finish:
            //   - `block`: a client-side guard (a required checkbox that can't be
            //     ticked + a JS showif). Its showif can't be trusted server-side
            //     (//js_only items aren't sent to OpenCPU; arithmetic showifs are
            //     mis-evaluated on character answers), and the client gate already
            //     bars a submit while a block is active — "block recurs / can't
            //     finish".
            //   - `blank`: a placeholder that renders only a <div> of text, with
            //     no input to post, so it never gets a `saved` row.
            // (note / note_iframe / mc_heading don't need this: note-likes post a
            // hidden value=1 and mc_heading is filtered out of the query above.)
            //   - `submit`: renders as the page's big advance button (Step 4 above)
            //     but posts no `saved` row (save_in_results_table=false) and is
            //     excluded from "answered" by getStudyProgress()'s SQL — counting
            //     it would keep not_answered > 0 forever and the form could never
            //     finish.
            if (!in_array($item->type, array('block', 'blank', 'submit'), true)) {
                $this->unanswered[$item->name] = $item;
            }
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
        $isSolo = (($this->study->layout ?? 'default') === 'solo');
        foreach ($itemsByPage as $pageNum => $pageItems) {
            $i++;
            $isLast = ($i === $pageCount);
            $isFirst = ($i === 1);
            $html .= sprintf(
                '<section class="fmr-page" data-fmr-page="%d"%s>',
                (int) $pageNum,
                $isFirst ? '' : ' hidden'
            );

            // A submit item on this page IS the page's advance control, so the
            // auto page-nav Next/Submit button below is suppressed (no duplicate).
            $pageHasSubmit = false;
            foreach ($pageItems as $item) {
                if (isset($item->type) && $item->type === 'submit') { $pageHasSubmit = true; break; }
            }

            foreach ($pageItems as $item) {
                if (!empty($this->validationErrors[$item->name])) {
                    $item->error = $this->validationErrors[$item->name];
                }
                if (!empty($this->validatedItems[$item->name])) {
                    $item->value_validated = $this->validatedItems[$item->name]->value_validated;
                }
                // A submit item renders as the page's big centred button. In
                // non-solo, tag it data-fmr-next so main.js routes its click
                // through submitPage() (preventDefault'd → no native POST); in
                // solo the solo controller's wireSubmitStep() owns the click, so
                // we DON'T add data-fmr-next there (avoids a double-bound submit).
                if (isset($item->type) && $item->type === 'submit' && !$isSolo) {
                    $item->input_attributes['data-fmr-next'] = '1';
                }
                $html .= $item->render();
            }

            // Suppress the auto Next/Submit only in non-solo, where the submit
            // item's own (data-fmr-next) button replaces it. In solo the auto-nav
            // button is CSS-hidden anyway and the solo controller drives advance
            // via the .fmr-solo-bigsubmit / footer OK — keep it so the page still
            // carries a [data-fmr-next] hook.
            $html .= $this->renderPageNav(!$isFirst, $isLast, $pageHasSubmit && !$isSolo);
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
        $saveUrl = run_url($this->run->name, 'form-save');
        $runUrl = run_url($this->run->name);
        $currentUser = Site::getCurrentUser();
        $userCode = $currentUser ? $currentUser->user_code : '';

        $offlineMode = !empty($this->study->offline_mode) ? 'on' : 'off';
        $allowPrevious = !empty($this->study->allow_previous) ? 'on' : 'off';
        $layout = in_array($this->study->layout, ['default', 'solo'], true) ? $this->study->layout : 'default';
        // `form-horizontal` is v1's class for the selectors in
        // webroot/assets/common/css/custom_item_classes.css (mc_width*,
        // rotate_label*, mc_vertical, mc_block, rating_button_label_width*,
        // …) — the admin-choosable layout modifiers. Keep it on the v2 form
        // so those classes keep working without a parallel scss port.
        $html = sprintf(
            '<form class="fmr-form-v2 form-horizontal" method="post" data-submit-url="%s" data-rcall-url="%s" data-fill-url="%s" data-sync-url="%s" data-save-url="%s" data-run-url="%s" data-offline-mode="%s" data-allow-previous="%s" data-layout="%s" novalidate>',
            htmlspecialchars($submitUrl, ENT_QUOTES),
            htmlspecialchars($rcallUrl, ENT_QUOTES),
            htmlspecialchars($fillUrl, ENT_QUOTES),
            htmlspecialchars($syncUrl, ENT_QUOTES),
            htmlspecialchars($saveUrl, ENT_QUOTES),
            htmlspecialchars($runUrl, ENT_QUOTES),
            $offlineMode,
            $allowPrevious,
            $layout
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
     *
     * Omitted in the solo (one-item-per-screen) layout: it's an author-facing
     * dev hint with nowhere to sit there without either pushing every step
     * down or eating the participant's first screen.
     */
    protected function renderUnverifiedTypesNotice() {
        if (($this->study->layout ?? 'default') === 'solo') {
            return '';
        }
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

    protected function renderPageNav($showPrev, $isLast, $hasSubmit = false) {
        // Previous button is opt-in per form (SurveyStudy.allow_previous).
        $allowPrev = $showPrev && !empty($this->study->allow_previous);
        $left = $allowPrev
            ? '<button type="button" class="btn btn-outline-secondary" data-fmr-prev><i class="fa fa-arrow-left"></i> Previous</button>'
            : '';
        // When the page already carries a submit item, that item's own button is
        // the (big, centred) advance control — emit no duplicate Next/Submit. Keep
        // a Previous button if the form opted in; otherwise render no nav row.
        if ($hasSubmit) {
            return $left === ''
                ? ''
                : '<div class="fmr-page-nav"><div class="fmr-page-nav__left">' . $left . '</div></div>';
        }
        $nextLabel = $isLast ? 'Submit' : 'Next';
        $nextIcon = $isLast ? 'fa-check' : 'fa-arrow-right';
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
