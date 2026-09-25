<?php

/**
 * FormRenderer — single-document renderer for form_v2.
 *
 * Reuses SpreadsheetRenderer's item loading, automatic items, validation
 * plumbing and item view tracking, and replaces the rest: the form wrapper
 * is v2-flavoured (BS5, data-submit-url, progress bar) and every page of the
 * form lands in one document, each in a `<section data-fmr-page>`, so the
 * client-side runtime switches pages without a round-trip. No R runs at
 * render time.
 *
 * The document is the same whatever the participant has done so far: every
 * item of the study is rendered on its page, and a state block (stateBlock)
 * carries what is stored per item. The browser fills inputs from it and
 * decides which page to show (webroot/assets/form/js/state.js) — see
 * documentation/agent_doc/plan_form_v2_navigation_state.md.
 *
 * Architectural rules (see documentation/agent_doc/plan_form_v2.md §3 / §5):
 *
 *   1. `showif` is JavaScript, evaluated only in the browser (Alpine). The
 *      server does not run it: an item with a showif renders hidden, and
 *      the client reveals it. The browser knows every input of the form and
 *      every stored answer (state block); anything else (a run variable,
 *      another survey) reaches a showif through a hidden item whose
 *      `value` computes it in R.
 *   2. `value` is the R path: literals pass through; anything else,
 *      including `sticky`, is recorded into `survey_r_calls`
 *      (slot='value') and resolved by the client through
 *      `/form-render-page` when the item's page is shown — the first page
 *      exactly like every later one.
 *   3. Dynamic labels (Item::needsDynamicLabel), note_iframe documents and
 *      R in choice labels record into `survey_r_calls` (slots 'label' and
 *      'choice_label') and resolve through the same page request.
 *   4. There is no server-side computation: `calculate` items, and any
 *      other item that takes no input but carries R in `value`, are not
 *      supported (Item::formV2Unsupported). What the form needs is a
 *      `hidden` item with that value; scores and groups for feedback or
 *      later units are computed where they are used.
 */
class FormRenderer extends SpreadsheetRenderer {

    /**
     * v1's items-per-page cap must not apply: toRender spans ALL pages here,
     * so the inherited per-request break would truncate the whole document
     * and strand the participant after the last in-DOM page.
     */
    protected $applyMaxDisplayedCap = false;


    /** @var bool|null memoized isAuthorFacingSession() */
    protected $authorFacingSession = null;

    /** @var array<string, true> item name => the session has stored a value for it (getStudyItems) */
    protected $storedItems = [];

    /**
     * Is this render being watched by the study's author rather than by a
     * participant?
     *
     * Two cases count: the admin survey preview (RunSession::isTestingStudy() —
     * Run::TEST_RUN, or the synthetic id === -1 session) and a run session
     * flagged `testing` (the "use run as yourself" flow, RunSession::isTesting()).
     * Cron-driven sessions count too: nobody is looking, but the notice/error
     * would land in the admin's log rather than in a participant's browser.
     *
     * Everything author-facing in the rendered document (spreadsheet validation
     * errors, the unverified-item-types banner) must be gated on this: the
     * participant subdomain is a separate origin, so we cannot ask "is an admin
     * logged in" — but a participant must never be shown study-authoring
     * diagnostics either way.
     */
    protected function isAuthorFacingSession() {
        if ($this->authorFacingSession !== null) {
            return $this->authorFacingSession;
        }
        $this->authorFacingSession = false;
        $runSession = $this->unitSession ? $this->unitSession->runSession : null;
        if ($runSession) {
            $this->authorFacingSession = (bool) (
                $runSession->isTestingStudy()
                || $runSession->isTesting()
                || $runSession->isCron()
            );
        }
        return $this->authorFacingSession;
    }

    public function processItems() {
        $items = $this->getStudyItems();
        if (!$items) {
            $this->toRender = [];
            $this->renderedItems = $this->getRenderedStudyItems();
            return;
        }

        // Items v2 cannot run (Item::formV2Unsupported: calculate and other
        // server-computed values; rule 4) are refused at upload and at the
        // switch to v2. Should one still be in the study, it is not rendered
        // — never evaluated, and its R source never reaches the page.
        foreach ($items as $name => $item) {
            if ($item && $item->formV2Unsupported()) {
                unset($items[$name]);
            }
        }

        // Automatic items (browser, ip, random, …) are computed and stored
        // the first time the form is rendered; a later render — every item
        // is queried every time — must not recompute them (a `random` would
        // re-roll on each reload). Already-stored ones are simply not
        // rendered.
        foreach ($items as $name => $item) {
            if ($item && !$item->requiresUserInput() && !$item->needsDynamicValue() && isset($this->storedItems[$name])) {
                unset($items[$name]);
            }
        }
        $items = $this->processAutomaticItems($items);
        $pageMap = $this->fetchPageMap();
        $itemPageOf = function ($item) use ($pageMap) {
            return isset($pageMap[(int) $item->id]) ? (int) $pageMap[(int) $item->id] : 1;
        };

        // Step 1: dynamic values. The `value` column carries R that we route
        // through the allowlist; admins can author it bare or wrapped in r(...)
        // (the documented bridge into a JS showif, see documentation/form_v2.md
        // §"Bridging R into a showif"). Item::needsDynamicValue already returns
        // false for empty / numeric values (those stay literal defaults).
        //   - Unwrap r(...) before recording. base+formr R has no r()
        //     function, so leaving the wrapper in survey_r_calls.expr would
        //     make every OpenCPU eval return NA via tryCatch.
        //   - Record the (unwrapped) R text in survey_r_calls slot='value'.
        //     This is what authorizes the client to invoke it via
        //     /form-render-page — the client never gets the source, only the
        //     call_id. `sticky` is recorded as written and expanded at
        //     evaluation (RCallService::expandValueExpr).
        //   - The value is blanked on every page and resolved when its page is
        //     shown; items that take no input carry no R here (see above).
        //   - The survey_r_calls rows are collected here and written in ONE
        //     batch below (see ensureRCalls).
        $pendingCalls = [];   // list of ['slot'=>…, 'expr'=>…, 'item_id'=>…]
        $callTargets = [];    // parallel list of [$item, attribute name]
        foreach ($items as $item) {
            if (!$item || !$item->requiresUserInput() || !$item->needsDynamicValue()) {
                continue;
            }
            $rawValue = trim((string) $item->value);
            if ($rawValue === '') continue;
            $rExpr = RAllowlistExtractor::unwrap($rawValue) ?? $rawValue;
            $pendingCalls[] = ['slot' => 'value', 'expr' => $rExpr, 'item_id' => $item->id];
            $callTargets[] = [$item, 'data-fmr-fill-id'];
            $item->value = '';
        }

        // Step 2: dynamic labels, including note_iframe. The full label text is
        // the allowlisted expression — we don't extract partial Rmd chunks.
        // Every page defers to /form-render-page: label_parsed is blanked and
        // data-fmr-label-id emitted. A note_iframe's label is a whole R
        // Markdown document; NoteIframe_Item::needsDynamicLabel() would knit it
        // right here (at render, against page-1 data), so it is recorded
        // directly instead and knitted when its page is shown.
        foreach ($items as $item) {
            if (!$item) continue;
            $labelSrc = (string) $item->label;
            if ($labelSrc === '') continue;
            $isIframe = (isset($item->type) && $item->type === 'note_iframe');
            if (!$isIframe && !$item->needsDynamicLabel([], $this->study->name)) {
                continue;
            }
            $pendingCalls[] = ['slot' => 'label', 'expr' => $labelSrc, 'item_id' => $item->id];
            $callTargets[] = [$item, 'data-fmr-label-id'];
            $item->label_parsed = '';
        }

        // Step 3: choices for every item (no OpenCPU). A choice label that
        // carries R becomes a 'choice_label' call, resolved with its page.
        $dynamicChoices = $this->attachStaticChoices($items);
        $choiceCallIndex = [];
        foreach ($dynamicChoices as $k => $choice) {
            $choiceCallIndex[$k] = count($pendingCalls);
            $pendingCalls[] = ['slot' => 'choice_label', 'expr' => $choice['expr'], 'item_id' => $choice['item']->id];
        }

        // Write-back: one SELECT of the study's existing allowlist rows (cached
        // per request) and an INSERT only for rows that don't exist yet.
        $callIds = $this->ensureRCalls($pendingCalls);
        foreach ($callTargets as $i => $target) {
            if (!isset($callIds[$i])) continue;
            list($targetItem, $attribute) = $target;
            $targetItem->parent_attributes[$attribute] = (string) $callIds[$i];
        }
        foreach ($dynamicChoices as $k => $choice) {
            $item = $choice['item'];
            if (!isset($callIds[$choiceCallIndex[$k]])) {
                continue; // no call id: the choice keeps its raw source text
            }
            $item->choices[$choice['value']] = sprintf(
                '<span class="fmr-choice-label fmr-fill-pending" data-fmr-choice-id="%d"></span>',
                (int) $callIds[$choiceCallIndex[$k]]
            );
            $item->parent_attributes['data-fmr-choice-item'] = (string) (int) $item->id;
        }

        // Step 4: visibility belongs to the client. Every item with a showif
        // renders hidden (and its inputs disabled) until Alpine evaluates the
        // showif, which then reveals it — so nothing flashes visible or blocks
        // the page before the bundle has run. A showif that did not transpile
        // leaves its item hidden: unknown means not shown.
        //
        // Submit-type items are page boundaries AND the page's advance control;
        // they stay in $items and render as the page's big button, and are kept
        // out of the state block (they carry no answer).
        foreach ($items as $item) {
            if ($item && trim((string) $item->showif) !== '') {
                $item->hideByDefaultPendingClient();
            }
        }

        // A page left with nothing but its submit row (every other item was
        // dropped above) is not a page: its submit is dropped too, so the
        // page does not exist in the document.
        $answerablePages = [];
        foreach ($items as $item) {
            if ($item && $item->type !== 'submit') {
                $answerablePages[$itemPageOf($item)] = true;
            }
        }
        foreach ($items as $name => $item) {
            if ($item && $item->type === 'submit' && !isset($answerablePages[$itemPageOf($item)])) {
                unset($items[$name]);
            }
        }

        $this->toRender = $items;
        $this->renderedItems = $this->getRenderedStudyItems();
    }

    /**
     * Per-request memo of a study's survey_r_calls ids, keyed by
     * "<study id>:<study modified>" so an edited/re-imported study never reads
     * a stale id. Static because a single request can render the same study
     * more than once (preview + progress), and because in a long-running
     * daemon it collapses the SELECT too.
     *
     * @var array<string, array<string, int>> studyKey => (callKey => id)
     */
    protected static $rCallIdCache = [];

    /**
     * Resolve survey_r_calls ids for the calls collected in Steps 2 and 3.
     *
     * The allowlist is a pure function of the study definition, so the steady
     * state is "every row already exists": this does ONE SELECT per study
     * (memoized for the request) and writes only rows that are genuinely
     * missing — where the old per-item RAllowlistExtractor::record() upserted,
     * and bumped `modified` on, one row per dynamic item on every participant
     * render.
     *
     * @param array<int, array{slot:string, expr:string, item_id:int|null}> $calls
     * @return array<int, int> same keys as $calls => call id
     */
    protected function ensureRCalls(array $calls) {
        if (!$calls) {
            return [];
        }
        $studyKey = (int) $this->study->id . ':' . (string) ($this->study->modified ?? '');
        $known = isset(self::$rCallIdCache[$studyKey]) ? self::$rCallIdCache[$studyKey] : [];

        $keys = [];
        $missing = [];
        foreach ($calls as $i => $call) {
            $itemId = (isset($call['item_id']) && $call['item_id'] !== null) ? (int) $call['item_id'] : null;
            $key = RAllowlistExtractor::callKey(
                $call['slot'], $itemId, hash('sha256', (string) $call['expr'])
            );
            $keys[$i] = $key;
            if (!isset($known[$key])) {
                $missing[$key] = $call;
            }
        }
        if ($missing) {
            $known = array_merge(
                $known,
                RAllowlistExtractor::ensure($this->db, $this->study->id, array_values($missing))
            );
            // Bound the memo in long-running processes (queue daemons render
            // units for many studies in one PHP process).
            if (count(self::$rCallIdCache) > 20) {
                self::$rCallIdCache = [];
            }
            self::$rCallIdCache[$studyKey] = $known;
        }

        $out = [];
        foreach ($keys as $i => $key) {
            if (isset($known[$key])) {
                $out[$i] = $known[$key];
            }
        }
        return $out;
    }

    /**
     * Attach choice options to every item that has a choice_list, using the
     * choice labels straight from the DB (no OpenCPU). A label that carries R
     * (label_parsed NULL since upload) is attached as its raw source and
     * returned, so the caller can turn it into a deferred 'choice_label' call.
     *
     * @return array<int, array{item: Item, value: string, expr: string}>
     */
    protected function attachStaticChoices(array &$items) {
        $lists = [];
        foreach ($items as $item) {
            if ($item && $item->choice_list) {
                $lists[] = $item->choice_list;
            }
        }
        if (!$lists) {
            return [];
        }
        $rows = $this->study->getChoices(array_values(array_unique($lists)), null);
        $byList = [];
        $dynamicByList = [];
        foreach ((array) $rows as $row) {
            if (!isset($row['list_name'], $row['name'])) {
                continue;
            }
            $parsed = $row['label_parsed'] ?? null;
            $byList[$row['list_name']][$row['name']] =
                ($parsed !== null && $parsed !== '') ? $parsed : $row['label'];
            if ($parsed === null && trim((string) $row['label']) !== '') {
                $dynamicByList[$row['list_name']][(string) $row['name']] = (string) $row['label'];
            }
        }
        $dynamic = [];
        foreach ($items as $name => $item) {
            if (!$item || !$item->choice_list) {
                continue;
            }
            if (isset($byList[$item->choice_list])) {
                $list = array_filter($byList[$item->choice_list], 'is_formr_truthy');
                $items[$name]->setChoices($list);
                foreach ($dynamicByList[$item->choice_list] ?? [] as $value => $label) {
                    if (isset($list[$value])) {
                        $dynamic[] = ['item' => $items[$name], 'value' => (string) $value, 'expr' => $label];
                    }
                }
            }
        }
        return $dynamic;
    }

    /**
     * Every item of the study, across all pages, in the session's stored
     * order. Answered and hidden items are included: the browser owns which
     * page is shown (documentation/agent_doc/plan_form_v2_navigation_state.md)
     * and fills inputs from the state block, so the document is the same
     * whatever the participant has done so far. Mirrors the query in
     * SpreadsheetRenderer::getNextStudyItems without its progress filters.
     */
    protected function getStudyItems() {
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
            `survey_items`.showif_js,
            `survey_items`.value,
            `survey_items_display`.displaycount,
            `survey_items_display`.session_id,
            `survey_items_display`.display_order,
            `survey_items_display`.hidden,
            `survey_items_display`.saved,
            `survey_items_display`.answered')
            ->from('survey_items')
            ->leftJoin('survey_items_display', 'survey_items_display.session_id = :session_id', 'survey_items.id = survey_items_display.item_id')
            // mc_heading is the disabled header row of an mc matrix (constant
            // hidden value=1, save_in_results_table=false). v2 doesn't render
            // matrices as a unit, so it would otherwise surface as a blank,
            // `required` step that can never be answered and blocks completion.
            // Exclude it from the v2 pipeline entirely (v1 unaffected).
            ->where('(survey_items.study_id = :study_id) AND (survey_items.deleted IS NULL) AND (survey_items.type != \'mc_heading\')')
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
        $this->storedItems = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $item = $itemFactory->make($row);
            if (!$item) continue;
            if (isset($row['saved'])) {
                $this->storedItems[$item->name] = true;
            }
            // $this->unanswered feeds v1's getStudyProgress() only; v2's
            // completion is the final submit's required check
            // (UnitSession::unansweredRequiredItems). Kept in step with v1's
            // exclusions so the inherited render helpers see the same shape.
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
        // round-trip. An item whose showif produced no transpiled js_showif
        // stays hidden (processItems Step 4): unknown means not shown.
        foreach ($this->renderedItems as $item) {
            if (!empty($item->showif) && !empty($item->js_showif)) {
                $item->data_showif = true;
            }
        }

        $itemsByPage = $this->groupByPage($this->renderedItems);

        $html = '<div class="fmr-form-v2-outer study-' . (int) $this->study->id . '">';
        $html .= $this->renderUnverifiedTypesNotice();
        $html .= $this->renderV2Header();

        // Every page is emitted hidden; the browser decides which one to show
        // (pageState in webroot/assets/form/js/state.js), which buttons a
        // page gets and whether its advance button reads Next or Submit.
        $isSolo = (($this->study->layout ?? 'default') === 'solo');
        $lang = !empty($this->study->language) ? $this->study->language : 'en';
        $strings = FormStrings::table($lang, FormStrings::studyOverrides($this->study));
        foreach ($itemsByPage as $pageNum => $pageItems) {
            $html .= sprintf('<section class="fmr-page" data-fmr-page="%d" hidden>', (int) $pageNum);

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
                // A `submit` row with no label is a bare page break: give its
                // button the navigation wording — the study's own (#322) or
                // the built-in one — instead of rendering it empty. Tagged so
                // the browser can swap in the Submit wording on the last
                // shown page (which only it knows).
                if (isset($item->type) && $item->type === 'submit'
                        && trim(strip_tags((string) $item->label_parsed)) === '') {
                    $item->label_parsed = htmlspecialchars($strings['nav.next'], ENT_QUOTES);
                    $item->input_attributes['value'] = $item->label_parsed;
                    $item->input_attributes['data-fmr-nav-label'] = '1';
                }
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
            $html .= $this->renderPageNav($pageHasSubmit && !$isSolo, $strings);
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

    /** @var array<int, int>|null memoized item_id => page (queried by both processItems and groupByPage per render) */
    protected $pageMapCache = null;

    /**
     * @return array<int, int> item_id => page
     */
    protected function fetchPageMap() {
        if ($this->pageMapCache !== null) {
            return $this->pageMapCache;
        }
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
        $this->pageMapCache = $map;
        return $map;
    }

    protected function renderV2Header() {
        $submitUrl = run_url($this->run->name, 'form-page-submit');
        $fillUrl = run_url($this->run->name, 'form-fill');
        $syncUrl = run_url($this->run->name, 'form-sync');
        $saveUrl = run_url($this->run->name, 'form-save');
        $runUrl = run_url($this->run->name);
        $currentUser = Site::getCurrentUser();
        $userCode = $currentUser ? $currentUser->user_code : '';

        $offlineMode = !empty($this->study->offline_mode) ? 'on' : 'off';
        $allowPrevious = !empty($this->study->allow_previous) ? 'on' : 'off';
        $layout = in_array($this->study->layout, ['default', 'solo'], true) ? $this->study->layout : 'default';
        // v1's cross-survey progress stitching (SpreadsheetRenderer::render):
        // this survey's progress is displayed inside the window
        // [add_percentage_points, displayed_percentage_maximum] so runs
        // chaining several surveys can hand each a share of one 0–100 bar.
        // The client interpolates page progress within this window.
        $progressFrom = (int) ($this->study->add_percentage_points ?? 0);
        $progressTo = (int) ($this->study->displayed_percentage_maximum ?? 100);
        if ($progressTo <= 0) {
            $progressTo = 100;
        }
        $progressFrom = max(0, min($progressFrom, 100));
        $progressTo = max($progressFrom, min($progressTo, 100));
        // `form-horizontal` is v1's class for the selectors in
        // webroot/assets/common/css/custom_item_classes.css (mc_width*,
        // rotate_label*, mc_vertical, mc_block, rating_button_label_width*,
        // …) — the admin-choosable layout modifiers. Keep it on the v2 form
        // so those classes keep working without a parallel scss port.
        $html = sprintf(
            '<form class="fmr-form-v2 form-horizontal" method="post" data-submit-url="%s" data-fill-url="%s" data-sync-url="%s" data-save-url="%s" data-run-url="%s" data-offline-mode="%s" data-allow-previous="%s" data-layout="%s" data-fmr-progress-from="%d" data-fmr-progress-to="%d" novalidate>',
            htmlspecialchars($submitUrl, ENT_QUOTES),
            htmlspecialchars($fillUrl, ENT_QUOTES),
            htmlspecialchars($syncUrl, ENT_QUOTES),
            htmlspecialchars($saveUrl, ENT_QUOTES),
            htmlspecialchars($runUrl, ENT_QUOTES),
            $offlineMode,
            $allowPrevious,
            $layout,
            $progressFrom,
            $progressTo
        );
        $html .= sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars(Session::REQUEST_USER_CODE, ENT_QUOTES),
            htmlspecialchars((string) $userCode, ENT_QUOTES)
        );
        $html .= '<script type="application/json" class="fmr-state">'
               . json_encode($this->stateBlock(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)
               . '</script>';

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

    /**
     * The state the browser needs to own navigation: the unit session and,
     * per item, its page, whether it is required, what has been stored for
     * it and whether it is recorded as hidden. The browser fills inputs and
     * the showif scope from `value`, and picks the page to open from
     * `answered` / `required` / `hidden` (state.js pageState). Multi-value
     * answers arrive as arrays, the shape the live inputs produce; an
     * upload's stored path is not shipped (`value` null, `answered` true).
     * Every item is listed, so the browser's copy of the data is complete
     * and the same after a reload as before it.
     *
     * @return array{unit_session_id:int, items: array<string, array>}
     */
    public function stateBlock() {
        $rows = FormSubmissionService::stateRows($this->db, $this->study->id, $this->unitSession->id);
        $factory = new ItemFactory([]);
        $items = [];
        foreach ($rows as $row) {
            $item = $factory->make($row);
            if ($item) {
                $items[(string) $row['name']] = FormSubmissionService::itemState($item, $row);
            }
        }
        return [
            'unit_session_id' => (int) $this->unitSession->id,
            'items' => $items,
        ];
    }

    protected function renderV2Footer() {
        return '</form>';
    }

    /**
     * Notice listing item types that haven't been smoke-tested in v2 yet.
     * Rendered above the form header when the survey contains any such type;
     * omitted entirely otherwise.
     *
     * GATED on isAuthorFacingSession(). The message ("these item types have not
     * yet been end-to-end smoke-tested on form_v2") is addressed to the study
     * author and is meaningless-to-alarming for a participant, who has no way
     * to act on it and reads it as "this survey is broken". The banner was
     * previously ungated on the theory that the participant subdomain is a
     * separate origin so an admin-cookie check is impossible there — true, but
     * the run session itself already tells us whether this is a test/preview
     * render (see isAuthorFacingSession), which is the check we actually
     * needed. Authors still see it in preview and in their own test sessions,
     * which is where they can act on it.
     *
     * Not a hard gate on the items themselves: they still render and submit
     * through the normal v1-inherited pipeline for everyone.
     *
     * Omitted in the solo (one-item-per-screen) layout: it's an author-facing
     * dev hint with nowhere to sit there without either pushing every step
     * down or eating the author's first screen.
     */
    protected function renderUnverifiedTypesNotice() {
        if (!$this->isAuthorFacingSession()) {
            return '';
        }
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

    /**
     * The navigation row of one page: a Previous button (opt-in per form,
     * SurveyStudy.allow_previous) and, unless the page carries a submit item
     * whose own big button is the advance control, a Next button. Every page
     * gets the same row; the browser hides Previous on the first shown page
     * and relabels Next as Submit on the last one (main.js applyNav).
     */
    protected function renderPageNav($hasSubmit = false, array $strings = null) {
        if ($strings === null) {
            $lang = !empty($this->study->language) ? $this->study->language : 'en';
            $strings = FormStrings::table($lang, FormStrings::studyOverrides($this->study));
        }
        $left = !empty($this->study->allow_previous)
            ? '<button type="button" class="btn btn-outline-secondary" data-fmr-prev><i class="fa fa-arrow-left"></i> '
                . htmlspecialchars($strings['nav.previous'], ENT_QUOTES) . '</button>'
            : '';
        if ($hasSubmit) {
            return $left === ''
                ? ''
                : '<div class="fmr-page-nav"><div class="fmr-page-nav__left">' . $left . '</div></div>';
        }
        return sprintf(
            '<div class="fmr-page-nav"><div class="fmr-page-nav__left">%s</div>'
            . '<div class="fmr-page-nav__right">'
            . '<button type="submit" class="btn btn-primary" data-fmr-next data-fmr-nav-label>%s <i class="fa fa-arrow-right"></i></button>'
            . '</div></div>',
            $left,
            htmlspecialchars($strings['nav.next'], ENT_QUOTES)
        );
    }
}
