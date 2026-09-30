<?php

/**
 * RCallService — the form_v2 server-side R evaluation engine.
 *
 * Extracted from RunController, where ~600 lines of allowlist lookup, result
 * caching, OpenCPU marshalling and R code-gen had accreted as controller
 * statics with two divergent single-call/batch implementations. One service,
 * one cache path, testable without HTTP, reusable from admin tooling.
 *
 * Three concerns live here:
 *
 * 1. ALLOWLIST with implicit revocation. survey_r_calls rows are created
 *    lazily at render (RAllowlistExtractor::record) and never deleted — so a
 *    call id alone must not stay evaluable forever. A call is LIVE only if
 *    its item still exists (not soft-deleted) and the item's CURRENT column
 *    content still yields this exact expression (compared with the item's
 *    value or label). Editing or removing the R in the sheet therefore
 *    revokes historic call ids instantly, with no reconciliation pass and
 *    no schema change.
 *
 * 2. OVERLAY hygiene. Client-sent `answers` are overlaid onto tail(survey, 1)
 *    before evaluation. Keys are restricted to the study's own live item
 *    names — without that, a participant could spoof admin-computed columns
 *    (eligibility flags, scores) or plumbing fields (session_id) into the
 *    evaluation row.
 *
 * 3. RESULT CACHE (survey_r_call_results, patches 105 + 107) keyed on
 *    (call_id, unit_session_id, sha256(normalized answers)) with per-slot
 *    TTLs, plus bounded write-time eviction.
 *
 * All OpenCPU spans are metered into survey_unit_sessions.execution_time so
 * participant-driven reactive R counts against the owner's compute budget
 * (issue #608) exactly like unit execution does.
 */
class RCallService {

    /** @var DB */
    protected $db;
    /** @var UnitSession */
    protected $unitSession;
    /** @var SurveyStudy */
    protected $study;
    /** @var string R-identifier-safe survey frame name ('' if unusable) */
    protected $surveyName;
    /** @var array<string,string>|null lazy cache of live item name => 'numeric'|'character' */
    protected $allowedNames = null;

    public function __construct(UnitSession $unitSession, SurveyStudy $study) {
        $this->db = DB::getInstance();
        $this->unitSession = $unitSession;
        $this->study = $study;
        $this->surveyName = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $study->name);
    }

    /**
     * Per-slot cache TTL in seconds. Value fills and labels are one-shot per
     * page show, so they tolerate a long TTL; the floor for an unknown slot
     * stays short.
     */
    public static function ttlForSlot($slot) {
        $ttls = Config::get('form_v2_rcall_ttl', array());
        $defaults = array('value' => 300, 'label' => 300, 'choice_label' => 300);
        $slot = (string) $slot;
        if (is_array($ttls) && isset($ttls[$slot])) {
            return (int) $ttls[$slot];
        }
        return isset($defaults[$slot]) ? $defaults[$slot] : 60;
    }

    /**
     * Revocation check: the recorded expression must still be derivable from
     * the item's CURRENT sheet content. $row needs slot/expr/item_id plus the
     * item_deleted/item_value/item_label join columns.
     */
    public static function callRowIsLive(array $row) {
        if (empty($row['item_id'])) {
            return false; // legacy row with no item anchor — nothing vouches for it
        }
        if (empty($row['item_row_id'])) {
            return false; // item row itself is gone (LEFT JOIN missed)
        }
        if ($row['item_deleted'] !== null) {
            return false; // soft-deleted items don't render, so they can't call
        }
        $expr = trim((string) $row['expr']);
        switch ((string) $row['slot']) {
            case 'value':
                $current = trim((string) $row['item_value']);
                return $current !== '' && $current === $expr;
            case 'label':
                return trim((string) $row['item_label']) === $expr;
            case 'choice_label':
                // The item's choice list must still carry a choice with this
                // exact label; callers pass the list's current labels.
                foreach ((array) ($row['item_choice_labels'] ?? array()) as $label) {
                    if (trim((string) $label) === $expr) {
                        return true;
                    }
                }
                return false;
        }
        return false;
    }

    /**
     * All LIVE value/label/choice-label calls for items scheduled on $pageNum
     * in this unit session. The survey_items_display JOIN limits resolution to
     * items actually on that page in THIS session (a hostile page number
     * resolves nothing); callRowIsLive() then applies the revocation check per
     * row. A choice-label entry also names the choice `values` it labels, so
     * the client can reach an `<option>` (which cannot hold a placeholder).
     *
     * @return array{value: array<int, array>, label: array<int, array>, choice_label: array<int, array>}
     *   call id => {item_id, item_name, item_type, expr[, values]}
     */
    public function callsForPage($pageNum) {
        $stmt = $this->db->prepare(
            'SELECT r.id, r.slot, r.expr, r.item_id,
                    i.id AS item_row_id, i.deleted AS item_deleted,
                    i.value AS item_value,
                    i.label AS item_label,
                    i.name AS item_name, i.type AS item_type, i.choice_list AS item_choice_list
             FROM `survey_r_calls` r
             INNER JOIN `survey_items_display` d ON d.item_id = r.item_id
             LEFT JOIN `survey_items` i ON i.id = r.item_id
             WHERE r.study_id = :study_id
               AND r.slot IN ("value", "label", "choice_label")
               AND d.session_id = :session_id
               AND d.page = :page'
        );
        $stmt->bindValue(':study_id', (int) $this->study->id, PDO::PARAM_INT);
        $stmt->bindValue(':session_id', (int) $this->unitSession->id, PDO::PARAM_INT);
        $stmt->bindValue(':page', (int) $pageNum, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Current choice labels of the lists the choice-label calls point at.
        $lists = array();
        foreach ($rows as $row) {
            if ($row['slot'] === 'choice_label' && (string) $row['item_choice_list'] !== '') {
                $lists[(string) $row['item_choice_list']] = array();
            }
        }
        foreach ($lists ? (array) $this->study->getChoices(array_keys($lists), null) : array() as $choice) {
            $lists[(string) $choice['list_name']][(string) $choice['name']] = (string) $choice['label'];
        }

        $out = array('value' => array(), 'label' => array(), 'choice_label' => array());
        foreach ($rows as $row) {
            $choices = array();
            if ($row['slot'] === 'choice_label') {
                $choices = isset($lists[(string) $row['item_choice_list']]) ? $lists[(string) $row['item_choice_list']] : array();
                $row['item_choice_labels'] = $choices;
            }
            if (!self::callRowIsLive($row)) {
                continue;
            }
            $entry = array(
                'item_id' => (int) $row['item_id'],
                'item_name' => (string) $row['item_name'],
                'item_type' => (string) $row['item_type'],
                'expr' => (string) $row['expr'],
            );
            if ($row['slot'] === 'choice_label') {
                $entry['values'] = array_map('strval', array_keys(array_filter($choices, function ($label) use ($row) {
                    return trim((string) $label) === trim((string) $row['expr']);
                })));
            }
            $out[(string) $row['slot']][(int) $row['id']] = $entry;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Overlay hygiene
    // ------------------------------------------------------------------

    /**
     * Names a participant may overlay, mapped to the R type the survey frame
     * carries for them: the study's live item names. Anything else (other
     * studies' columns, admin-computed values persisted earlier, plumbing
     * fields like session_id) is dropped before code-gen.
     *
     * The type matters because the overlay is assigned into the frame the
     * expression sees. Formatting by value shape instead of by column type
     * silently retypes columns: a `text` answer of '07743' would land as the
     * number 7743 (leading zero gone, character column coerced) and '1e3' as
     * 1000 — so a reactive r() would disagree with the same expression run
     * over the saved data. The column type is SurveyStudy::columnTypes',
     * the long read's too: integer and FLOAT/DOUBLE columns arrive as
     * numbers, everything else — and an item with no results column (note,
     * an unknown type), which cannot silently retype anything — as character.
     *
     * @return array<string, string> item name => 'numeric'|'character'
     */
    protected function allowedOverlayNames() {
        if ($this->allowedNames !== null) {
            return $this->allowedNames;
        }
        $this->allowedNames = array();
        foreach (SurveyStudy::columnTypes($this->study->id) as $name => $type) {
            if ($type['live']) {
                $numeric = $type['column'] === 'int' || $type['column'] === 'float';
                $this->allowedNames[$name] = $numeric ? 'numeric' : 'character';
            }
        }
        return $this->allowedNames;
    }

    /**
     * Build an R `list(name = value, ...)` literal from client answers. Keys
     * must be syntactically safe AND belong to the study (see class doc), and
     * each value is formatted as its own column's R type — never by guessing
     * from the string.
     */
    /**
     * The R statements that put the browser's answers onto the survey
     * frame's last row — the one way the participant's in-flight answers
     * reach R, for values (runBatch) and labels (resolveLabels) alike.
     * `$overlayR` is formatROverlay()'s list; names it carries are columns
     * of the frame (allowedOverlayNames), so the assignment never adds one.
     * Guarded so a session with no row yet is left alone.
     *
     * @param string $indent prefix for each line
     * @return string R, newline-terminated
     */
    public static function overlayStatements($surveyName, $overlayR, $indent = '') {
        return $indent . ".fmr.overlay <- {$overlayR}\n"
             . $indent . "if (exists('{$surveyName}') && nrow({$surveyName}) > 0) {\n"
             . $indent . "  for (.n in names(.fmr.overlay)) {$surveyName}[nrow({$surveyName}), .n] <- .fmr.overlay[[.n]]\n"
             . $indent . "}\n";
    }

    public function formatROverlay(array $answers) {
        $allowed = $this->allowedOverlayNames();
        $parts = array();
        foreach ($answers as $key => $val) {
            if (!is_string($key) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            if (!isset($allowed[$key])) {
                continue;
            }
            $parts[] = $key . ' = ' . self::formatRValue($val, $allowed[$key] === 'numeric');
        }
        return 'list(' . implode(', ', $parts) . ')';
    }

    // ------------------------------------------------------------------
    // Result cache (survey_r_call_results)
    // ------------------------------------------------------------------

    protected static function normalizeAnswersForHash(array $answers) {
        ksort($answers);
        return $answers;
    }

    protected static function argsHash(array $answers) {
        return hash('sha256', json_encode(self::normalizeAnswersForHash($answers), JSON_UNESCAPED_UNICODE));
    }

    /**
     * The cache key of one expression's result: a hash of the answers it can
     * read, so answering something else does not throw the result away. An
     * expression reads the answers it names. One that names the survey
     * itself (nrow(survey), survey$x, tail(survey, 1)) reads the whole
     * frame, and so can the run's own R functions (custom_r), which name
     * nothing here — both key on every answer. Words in strings count as
     * names too: that can only make a key stricter.
     */
    protected function answersKey($expr, array $answers) {
        if ($answers && !$this->readsWholeFrame($expr)) {
            preg_match_all('/[A-Za-z][A-Za-z0-9_]*/', (string) $expr, $m);
            $answers = array_intersect_key($answers, array_flip($m[0]));
        }
        return self::argsHash($answers);
    }

    protected function readsWholeFrame($expr) {
        if ($this->runHasCustomR === null) {
            $run = $this->unitSession->runSession ? $this->unitSession->runSession->getRun() : null;
            $this->runHasCustomR = $run && trim((string) $run->getCustomRFunctions()) !== '';
        }
        return $this->runHasCustomR
            || preg_match('/(?<![A-Za-z0-9_.])' . preg_quote($this->surveyName, '/') . '(?![A-Za-z0-9_])/', (string) $expr);
    }

    /** @var bool|null memoized: the run defines its own R functions */
    protected $runHasCustomR = null;

    /**
     * @param array<int, string> $hashes call_id => the cache key of its result
     * @return array<int, mixed> call_id => cached result (fresh entries only)
     */
    protected function cachedResults(array $hashes, $ttl) {
        $results = array();
        if (!$hashes) {
            return $results;
        }
        $now = time();
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $stmt = $this->db->prepare(
            "SELECT call_id, args_hash, result_json, UNIX_TIMESTAMP(created_at) AS ts
             FROM survey_r_call_results
             WHERE call_id IN ($placeholders) AND unit_session_id = ?"
        );
        $i = 1;
        foreach (array_keys($hashes) as $cid) {
            $stmt->bindValue($i++, (int) $cid, PDO::PARAM_INT);
        }
        $stmt->bindValue($i, (int) $this->unitSession->id, PDO::PARAM_INT);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cid = (int) $row['call_id'];
            if ($row['args_hash'] !== $hashes[$cid] || ($now - (int) $row['ts']) >= $ttl) continue;
            $decoded = json_decode((string) $row['result_json'], true);
            if (is_array($decoded) && array_key_exists('result', $decoded)) {
                $results[$cid] = $decoded['result'];
            }
        }
        return $results;
    }

    protected function writeCache($callId, $argsHash, $result) {
        try {
            $stmt = $this->db->prepare(
                'REPLACE INTO `survey_r_call_results` (call_id, unit_session_id, args_hash, result_json) '
                . 'VALUES (:cid, :usid, :hash, :res)'
            );
            $stmt->bindValue(':cid', (int) $callId);
            $stmt->bindValue(':usid', (int) $this->unitSession->id);
            $stmt->bindValue(':hash', $argsHash);
            $stmt->bindValue(':res', json_encode(array('result' => $result), JSON_UNESCAPED_UNICODE));
            $stmt->execute();
        } catch (PDOException $e) {
            formr_log('r-call cache write failed: ' . $e->getMessage());
        }
    }

    /**
     * Bounded write-time eviction. Max TTL is minutes, so day-old rows are
     * dead weight; LIMIT keeps it from stalling the (already OpenCPU-
     * expensive) request that triggered it.
     */
    public static function evictStale() {
        try {
            DB::getInstance()->exec(
                'DELETE FROM `survey_r_call_results` '
                . 'WHERE created_at < NOW() - INTERVAL 1 DAY LIMIT 500'
            );
        } catch (PDOException $e) {
            formr_log('r-call cache eviction failed: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Evaluation
    // ------------------------------------------------------------------

    /**
     * Resolve expression calls (slot 'value' or 'label') in one
     * OpenCPU batch, cache-first. THE single implementation — the previous
     * controller had a single-call and a batch copy that had already drifted
     * (only one evicted stale rows). Returns [call_id => result]; misses that
     * fail evaluation stay absent.
     *
     * @param array<int, array{expr:string}> $calls
     */
    public function resolveExpressions(array $calls, array $answers, $ttl) {
        if (empty($calls) || $this->surveyName === '') {
            return array();
        }
        $exprs = $hashes = array();
        foreach ($calls as $callId => $entry) {
            $exprs[(int) $callId] = $this->expandValueExpr(
                (string) $entry['expr'],
                isset($entry['item_name']) ? (string) $entry['item_name'] : ''
            );
            $hashes[(int) $callId] = $this->answersKey($exprs[(int) $callId], $answers);
        }
        $results = $this->cachedResults($hashes, $ttl);
        $misses = array_diff_key($exprs, $results);
        if (empty($misses)) {
            return $results;
        }

        $evaluated = $this->evaluateBatch($misses, $answers);
        foreach ($evaluated['results'] as $cid => $val) {
            $results[$cid] = $val;
            $this->writeCache($cid, $hashes[$cid], $val);
        }
        self::evictStale();
        return $results;
    }

    /**
     * `sticky` is the one `value` keyword that is not R: it means "the last
     * non-missing answer this participant gave to this item" (v1's
     * Item::getValue()). The allowlist records the keyword as written, so the
     * revocation check keeps comparing like with like; it is expanded here,
     * at evaluation.
     */
    public function expandValueExpr($expr, $itemName) {
        if (trim((string) $expr) === 'sticky' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', (string) $itemName)) {
            return "tail(na.omit({$this->surveyName}\${$itemName}), 1)";
        }
        return (string) $expr;
    }

    /**
     * Evaluate R expressions in ONE OpenCPU batch against tail(survey, 1) with
     * $answers overlaid, each isolated by tryCatch (a runtime error yields NA
     * for that expression only).
     *
     * A PARSE error in any one expression fails the whole batch, since R parses
     * the batch as a single program. Then each expression is retried on its own,
     * so one author typo cannot blank every other value on the page.
     *
     * @param array<int, string> $exprs key => R expression
     * @return array{results: array<int, mixed>, failed: array<int, string>}
     *   results: key => R result (length-1 vectors unwrapped); failed: key =>
     *   'r' (R refused the expression itself: an author error) or 'transient'
     *   (OpenCPU unreachable: worth retrying later)
     */
    protected function evaluateBatch(array $exprs, array $answers) {
        $out = array('results' => array(), 'failed' => array());
        if (!$exprs || $this->surveyName === '') {
            return $out;
        }
        list($session, $transient) = $this->runBatch($exprs, $answers);
        if ($session) {
            $batched = $session->getJSONObject();
            foreach (is_array($batched) ? $batched : array() as $key => $val) {
                $key = (int) $key;
                if (!isset($exprs[$key])) continue;
                // R length-1 vector unwrap.
                if (is_array($val) && count($val) === 1 && array_keys($val) === array(0)) {
                    $val = $val[0];
                }
                $out['results'][$key] = $val;
            }
            return $out;
        }
        formr_log('RCallService::evaluateBatch OpenCPU error: ' . (string) opencpu_last_error());
        if ($transient || count($exprs) === 1) {
            foreach ($exprs as $key => $_) {
                $out['failed'][$key] = $transient ? 'transient' : 'r';
            }
            return $out;
        }
        foreach ($exprs as $key => $expr) {
            $single = $this->evaluateBatch(array($key => $expr), $answers);
            $out['results'] += $single['results'];
            $out['failed'] += $single['failed'];
        }
        return $out;
    }

    /**
     * @return array{0: OpenCPU_Session|null, 1: bool} the session (null when R
     *   or the transport failed) and whether the failure was the transport.
     *   opencpu_evaluate() returns null either way; only a transport failure
     *   leaves a local error behind (opencpu_note_transport_failure).
     */
    protected function runBatch(array $exprs, array $answers) {
        $listEntries = array();
        foreach ($exprs as $key => $expr) {
            // The key is an int, so the backtick-quoted name is safe; expr is
            // admin-authored R (vetted by the revocation-checked allowlist when
            // a client asked for it).
            // Each expression on its own lines, in its own function — as v1
            // evaluates a `value` (SpreadsheetRenderer): a trailing `# comment`
            // must not swallow the closing brace, and a `return()` must end
            // this expression, not the whole batch.
            $listEntries[] = sprintf("`%d` = tryCatch({\n(function() {\n%s\n})()\n}, error = function(e) NA)", (int) $key, (string) $expr);
        }
        // The browser's answers land on the survey frame first (the same
        // statements the label path knits), so `survey$x` and a bare `x`
        // inside with() see the same values.
        $code = "(function() {\n"
              . self::overlayStatements($this->surveyName, $this->formatROverlay($answers), '  ')
              . "  with(tail({$this->surveyName}, 1), list(\n"
              . "    " . implode(",\n    ", $listEntries) . "\n"
              . "  ))\n"
              . "})()\n";

        $started = hrtime(true);
        try {
            $variables = $this->unitSession->getRunData($code, $this->surveyName);
            $session = opencpu_evaluate($code, $variables, 'json', null, true);
        } finally {
            // Participant-driven reactive R counts against the owner's
            // compute budget like any unit execution (issue #608).
            $this->unitSession->addExecutionTime((hrtime(true) - $started) / 1e9);
        }
        if ($session && !$session->hasError()) {
            return array($session, false);
        }
        return array(null, !empty($GLOBALS['opencpu_local_error']));
    }

    /**
     * Batched label resolution: knit label Rmd sources via OpenCPU (knitr →
     * Markdown, no pandoc), Parsedown PHP-side exactly like the static-label
     * pipeline, delimiter-split back to per-call HTML. Cache-first like
     * resolveExpressions.
     *
     * @param array<int, array{expr:string}> $calls
     * @return array<int, string> call_id => html
     */
    public function resolveLabels(array $calls, array $answers, $ttl) {
        if (empty($calls) || $this->surveyName === '') {
            return array();
        }
        $hashes = array();
        foreach ($calls as $callId => $entry) {
            $hashes[(int) $callId] = $this->answersKey((string) $entry['expr'], $answers);
        }
        $results = $this->cachedResults($hashes, $ttl);
        $misses = array_diff_key($calls, $results);
        if (empty($misses)) {
            return $results;
        }

        // Order matters — results map back by index → call_id.
        $orderedCallIds = array_keys($misses);
        $sources = array();
        foreach ($orderedCallIds as $cid) {
            $sources[] = (string) $misses[$cid]['expr'];
        }

        // Overlay the latest answers onto the survey frame via a hidden knitr
        // chunk (getRunData returns a structured array for opencpu_define_vars;
        // we can't append raw R to it) — the same statements runBatch uses.
        // Then the overlaid last row is attached for bare names
        // (reattachStatement).
        $prelude = empty($answers) ? '' : "```{r overlay,echo=FALSE,results='hide',message=FALSE,warning=FALSE}\n"
            . self::overlayStatements($this->surveyName, $this->formatROverlay($answers))
            . $this->reattachStatement()
            . "```\n";

        $started = hrtime(true);
        try {
            $parts = opencpu_knit_strings($this->unitSession, $this->study->name, $sources, 'markdown', $prelude);
        } finally {
            $this->unitSession->addExecutionTime((hrtime(true) - $started) / 1e9);
        }
        if ($parts === null) {
            formr_log('RCallService::resolveLabels OpenCPU error: ' . (string) opencpu_last_error());
            return $results;
        }
        $parsedown = (new ParsedownExtra())->setBreaksEnabled(true)->setUrlsLinked(true);
        foreach ($orderedCallIds as $idx => $cid) {
            if (!isset($parts[$idx])) continue;
            // Strip a single wrapping <p> so a dynamic label renders inline
            // like a static one. Log-only on failure — this runs during
            // PARTICIPANT rendering, so author-facing alert()s must not reach
            // the participant's session.
            try {
                $html = remove_tag_wrapper($parsedown->text(trim($parts[$idx])));
            } catch (\Throwable $e) {
                formr_log_exception($e, 'PARSEDOWN.TEXT (dynamic label)');
                $html = trim($parts[$idx]);
            }
            $results[$cid] = $html;
            $this->writeCache($cid, $hashes[$cid], $html);
        }
        return $results;
    }


    /**
     * Knit note_iframe items: their label is a whole R Markdown document,
     * rendered by OpenCPU into an HTML file the item frames. Resolved like any
     * other dynamic label — when the item's page is reached, with the latest
     * answers overlaid on the stored ones — so it can show what the
     * participant entered on earlier pages, also before those answers are
     * stored. Cached per unit session under the answers it names.
     *
     * @param array<int, array{expr:string, item_name?:string}> $calls
     * @return array<int, string> call id => the item's label HTML
     */
    public function resolveIframes(array $calls, array $answers, $ttl) {
        if (empty($calls) || $this->surveyName === '') {
            return array();
        }
        $hashes = array();
        foreach ($calls as $callId => $entry) {
            $hashes[(int) $callId] = $this->answersKey((string) $entry['expr'], $answers);
        }
        $results = $this->cachedResults($hashes, $ttl);
        foreach (array_diff_key($calls, $results) as $callId => $entry) {
            $source = (string) $entry['expr'];
            $started = hrtime(true);
            try {
                // The document's settings chunk: the run's data with the
                // frame's last row attached, then the answers overlaid and
                // the overlaid row attached over it (see resolveLabels).
                $vars = opencpu_define_vars($this->unitSession->getRunData($source, $this->study->name), $this->study->name);
                if ($answers) {
                    $vars .= "\n" . self::overlayStatements($this->surveyName, $this->formatROverlay($answers)) . $this->reattachStatement();
                }
                $session = opencpu_knit_iframe($source, $vars, true, $this->study->name);
            } finally {
                $this->unitSession->addExecutionTime((hrtime(true) - $started) / 1e9);
            }
            if ($session && !$session->hasError()) {
                $html = NoteIframe_Item::iframeHtml($session);
                $results[(int) $callId] = $html;
                $this->writeCache((int) $callId, $hashes[(int) $callId], $html);
            } else {
                $name = isset($entry['item_name']) ? $entry['item_name'] : '?';
                formr_log("note_iframe '{$name}': knit failed: " . (string) opencpu_last_error());
                $results[(int) $callId] = NoteIframe_Item::fallbackHtml();
            }
        }
        return $results;
    }

    /**
     * R that attaches the survey frame's (overlaid) last row. The knit
     * settings chunk attaches a copy of it for bare names (`r d_num`) before
     * any overlay runs; attached again after the overlay, the new copy masks
     * the old one, or a knit made before the answers are stored reads the
     * stored ones.
     */
    protected function reattachStatement() {
        return "if (exists('{$this->surveyName}') && nrow({$this->surveyName}) > 0) attach(tail({$this->surveyName}, 1), warn.conflicts = FALSE)\n";
    }

    // ------------------------------------------------------------------
    // R value plumbing (formerly RunController statics)
    // ------------------------------------------------------------------

    /**
     * One R literal for one answer. $numeric says whether the item's own
     * column is numeric (see allowedOverlayNames) — it is NOT inferred from
     * the value, because is_numeric() is true for plenty of answers that are
     * stored as text ('07743', '1e3', '+1', ' 7'), and emitting those bare
     * would retype the column and change the value the expression sees.
     *
     * Defaults to character: quoting can never silently retype a column, so a
     * caller that doesn't know the item type gets the safe shape.
     *
     * @param mixed $v
     * @param bool $numeric true only for items whose results column is INT-family/FLOAT/DOUBLE
     */
    public static function formatRValue($v, $numeric = false) {
        if ($v === null || $v === '') return 'NA';
        if (is_bool($v)) return $v ? 'TRUE' : 'FALSE';
        if (is_int($v) || is_float($v)) {
            return $numeric ? (string) $v : '"' . (string) $v . '"';
        }
        if (is_array($v)) {
            // The survey data.frame stores multi-selects as ", "-joined strings
            // (McMultiple/SelectMultiple::getReply), and the overlay assigns
            // into a single data.frame row — a length-N c(...) errors with
            // "replacement has N rows, data has 1", and c() (NULL) would
            // delete the column. Mirror the stored shape instead.
            $vals = array();
            foreach (array_values($v) as $part) {
                if ($part === null || $part === '') continue;
                $vals[] = (string) $part;
            }
            if (empty($vals)) return 'NA';
            return self::formatRValue(implode(', ', $vals), $numeric);
        }
        if ($numeric && is_numeric($v)) return (string) $v;
        // String: R double-quoted, escape backslash and double-quote.
        $s = str_replace(array('\\', '"'), array('\\\\', '\"'), (string) $v);
        return '"' . $s . '"';
    }

    /**
     * Stringify an R scalar for the client. Empty string for NA / null /
     * empty arrays — a deferred fill should leave the field blank rather
     * than writing "NA" or "null" literally.
     */
    public static function rResultToScalarString($result) {
        if ($result === null) return '';
        if (is_bool($result)) return $result ? 'TRUE' : 'FALSE';
        if (is_int($result) || is_float($result)) {
            if (is_float($result) && is_nan($result)) return '';
            return (string) $result;
        }
        if (is_array($result)) {
            if (empty($result)) return '';
            // Flatten to first scalar; deferred fills target a single input.
            return self::rResultToScalarString(reset($result));
        }
        return (string) $result;
    }
}
