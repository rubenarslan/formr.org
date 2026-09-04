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
 *    content still yields this exact expression (hash compare against the
 *    unwrapped value or the raw label). Editing or removing the
 *    r(...) in the sheet therefore revokes historic call ids instantly, with
 *    no reconciliation pass and no schema change.
 *
 * 2. OVERLAY hygiene. Client-sent `answers` are overlaid onto tail(survey, 1)
 *    before evaluation. Keys are restricted to the study's own live item
 *    names — without that, a participant could spoof admin-computed columns
 *    (eligibility flags, scores) or plumbing fields (session_id) into the
 *    evaluation row.
 *
 * 3. RESULT CACHE (survey_r_call_results, patches 083 + 085) keyed on
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
        $defaults = array('value' => 300, 'label' => 300);
        $slot = (string) $slot;
        if (is_array($ttls) && isset($ttls[$slot])) {
            return (int) $ttls[$slot];
        }
        return isset($defaults[$slot]) ? $defaults[$slot] : 60;
    }

    /**
     * Fetch a call row and verify it is still LIVE for this service's study
     * (see class doc, concern 1).
     *
     * @return array{ok:bool, status?:int, error?:string, call?:array{id:int, slot:string, expr:string, item_id:int}}
     */
    public function fetchLiveCall($callId, $expectedSlot) {
        $stmt = $this->db->prepare(
            'SELECT r.id, r.study_id, r.slot, r.expr, r.item_id,
                    i.id AS item_row_id, i.deleted AS item_deleted,
                    i.value AS item_value,
                    i.label AS item_label
             FROM `survey_r_calls` r
             LEFT JOIN `survey_items` i ON i.id = r.item_id
             WHERE r.id = :id'
        );
        $stmt->bindValue(':id', (int) $callId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['study_id'] !== (int) $this->study->id) {
            return array('ok' => false, 'status' => 404, 'error' => 'Unknown call_id for this study');
        }
        if ((string) $row['slot'] !== (string) $expectedSlot) {
            return array('ok' => false, 'status' => 400, 'error' => 'call_id slot mismatch');
        }
        if (!self::callRowIsLive($row)) {
            return array('ok' => false, 'status' => 410, 'error' => 'call_id no longer active (item removed or expression edited)');
        }
        return array('ok' => true, 'call' => array(
            'id' => (int) $row['id'],
            'slot' => (string) $row['slot'],
            'expr' => (string) $row['expr'],
            'item_id' => (int) $row['item_id'],
        ));
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
                $raw = trim((string) $row['item_value']);
                $current = RAllowlistExtractor::unwrap($raw);
                $current = $current !== null ? $current : $raw;
                return $current !== '' && trim($current) === $expr;
            case 'label':
                return trim((string) $row['item_label']) === $expr;
        }
        return false;
    }

    /**
     * All LIVE value/label calls for items scheduled on $pageNum in this unit
     * session. The survey_items_display JOIN limits resolution to items
     * actually on that page in THIS session (a hostile page number resolves
     * nothing); callRowIsLive() then applies the revocation check per row.
     *
     * @return array{value: array<int, array{item_id:int, expr:string}>, label: array<int, array{item_id:int, expr:string}>}
     */
    public function callsForPage($pageNum) {
        $stmt = $this->db->prepare(
            'SELECT r.id, r.slot, r.expr, r.item_id,
                    i.id AS item_row_id, i.deleted AS item_deleted,
                    i.value AS item_value,
                    i.label AS item_label
             FROM `survey_r_calls` r
             INNER JOIN `survey_items_display` d ON d.item_id = r.item_id
             LEFT JOIN `survey_items` i ON i.id = r.item_id
             WHERE r.study_id = :study_id
               AND r.slot IN ("value", "label")
               AND d.session_id = :session_id
               AND d.page = :page'
        );
        $stmt->bindValue(':study_id', (int) $this->study->id, PDO::PARAM_INT);
        $stmt->bindValue(':session_id', (int) $this->unitSession->id, PDO::PARAM_INT);
        $stmt->bindValue(':page', (int) $pageNum, PDO::PARAM_INT);
        $stmt->execute();

        $out = array('value' => array(), 'label' => array());
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!self::callRowIsLive($row)) {
                continue;
            }
            $out[(string) $row['slot']][(int) $row['id']] = array(
                'item_id' => (int) $row['item_id'],
                'expr' => (string) $row['expr'],
            );
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
     * over the saved data. See rTypeForResultField().
     *
     * @return array<string, string> item name => 'numeric'|'character'
     */
    protected function allowedOverlayNames() {
        if ($this->allowedNames !== null) {
            return $this->allowedNames;
        }
        $this->allowedNames = array();
        $stmt = $this->db->prepare(
            'SELECT `id`, `name`, `type`, `choice_list`, `type_options`
             FROM `survey_items` WHERE `study_id` = :sid AND `deleted` IS NULL
             ORDER BY `id` DESC'
        );
        $stmt->bindValue(':sid', (int) $this->study->id, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return $this->allowedNames;
        }

        // Choice lists decide the column type for mc/check families
        // (numeric choice names → TINYINT, string names → VARCHAR), exactly
        // as they do on the results-table upload path.
        $choiceLists = array();
        $cstmt = $this->db->prepare(
            'SELECT `list_name`, `name`, `label` FROM `survey_item_choices`
             WHERE `study_id` = :sid ORDER BY `id` ASC'
        );
        $cstmt->bindValue(':sid', (int) $this->study->id, PDO::PARAM_INT);
        $cstmt->execute();
        while ($c = $cstmt->fetch(PDO::FETCH_ASSOC)) {
            $choiceLists[(string) $c['list_name']][(string) $c['name']] = $c['label'];
        }

        $factory = new ItemFactory($choiceLists);
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            if (isset($this->allowedNames[$name])) {
                continue; // highest id wins, like UnitSession::getLongReadCasts
            }
            $item = $factory->make($row);
            $field = ($item === false || !$item->isStoredInResultsTable())
                ? null : $item->getResultField();
            $this->allowedNames[$name] = self::rTypeForResultField($name, $field);
        }
        return $this->allowedNames;
    }

    /**
     * Mirror of UnitSession::getLongReadCasts: the wide results table's column
     * type decides what R sees. Integer families and FLOAT/DOUBLE arrive as
     * numbers; everything else — VARCHAR/TEXT/DATE/… and DECIMAL, which PDO
     * hands back as a string — arrives as character. An item with no results
     * column at all (note, mc_heading, or an unknown type) is not in the frame,
     * so quote it: character is the shape that cannot silently retype anything.
     *
     * @param string $name item name, as prefixed onto the field definition
     * @param string|null $field the item's getResultField(), e.g. "`age` INT UNSIGNED DEFAULT NULL"
     * @return string 'numeric'|'character'
     */
    public static function rTypeForResultField($name, $field) {
        if (!is_string($field)) {
            return 'character';
        }
        $type = strtoupper(ltrim(substr($field, strlen("`{$name}` "))));
        if (preg_match('/^(TINY|SMALL|MEDIUM|BIG)?INT\b/', $type) || preg_match('/^(FLOAT|DOUBLE)\b/', $type)) {
            return 'numeric';
        }
        return 'character';
    }

    /**
     * Build an R `list(name = value, ...)` literal from client answers. Keys
     * must be syntactically safe AND belong to the study (see class doc), and
     * each value is formatted as its own column's R type — never by guessing
     * from the string.
     */
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

    /** @return array<int, mixed> call_id => cached result (fresh entries only) */
    protected function cachedResults(array $callIds, $argsHash, $ttl) {
        $results = array();
        if (!$callIds) {
            return $results;
        }
        $now = time();
        $placeholders = implode(',', array_fill(0, count($callIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT call_id, result_json, UNIX_TIMESTAMP(created_at) AS ts
             FROM survey_r_call_results
             WHERE call_id IN ($placeholders) AND unit_session_id = ? AND args_hash = ?"
        );
        $i = 1;
        foreach ($callIds as $cid) {
            $stmt->bindValue($i++, (int) $cid, PDO::PARAM_INT);
        }
        $stmt->bindValue($i++, (int) $this->unitSession->id, PDO::PARAM_INT);
        $stmt->bindValue($i, $argsHash);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (($now - (int) $row['ts']) >= $ttl) continue;
            $decoded = json_decode((string) $row['result_json'], true);
            if (is_array($decoded) && array_key_exists('result', $decoded)) {
                $results[(int) $row['call_id']] = $decoded['result'];
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
        $argsHash = self::argsHash($answers);
        $results = $this->cachedResults(array_keys($calls), $argsHash, $ttl);
        $misses = array_diff_key($calls, $results);
        if (empty($misses)) {
            return $results;
        }

        $overlayR = $this->formatROverlay($answers);
        $listEntries = array();
        foreach ($misses as $callId => $entry) {
            // call_id is an int, so the backtick-quoted key is safe; expr is
            // admin-authored R vetted by the (revocation-checked) allowlist.
            $listEntries[] = sprintf(
                "`%d` = tryCatch({ %s }, error = function(e) NA)",
                (int) $callId,
                (string) $entry['expr']
            );
        }
        $code = "(function() {\n"
              . "  .fmr.overlay <- {$overlayR}\n"
              . "  .fmr.row <- tail({$this->surveyName}, 1)\n"
              . "  for (.n in names(.fmr.overlay)) .fmr.row[[.n]] <- .fmr.overlay[[.n]]\n"
              . "  with(.fmr.row, list(\n"
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
            $batched = $session->getJSONObject();
            if (is_array($batched)) {
                foreach ($batched as $key => $val) {
                    $cid = (int) $key;
                    if (!isset($misses[$cid])) continue;
                    // R length-1 vector unwrap.
                    if (is_array($val) && count($val) === 1 && array_keys($val) === array(0)) {
                        $val = $val[0];
                    }
                    $results[$cid] = $val;
                    $this->writeCache($cid, $argsHash, $val);
                }
            }
        } else {
            formr_log('RCallService::resolveExpressions OpenCPU error: ' . (string) opencpu_last_error());
        }
        self::evictStale();
        return $results;
    }

    /**
     * Evaluate one call end-to-end: live-allowlist fetch (with revocation),
     * then the shared batch resolver. Shape matches the old controller helper.
     *
     * @return array{ok:bool, status?:int, error?:string, result?:mixed}
     */
    public function evaluateSingle($callId, $expectedSlot, array $answers) {
        if ($callId <= 0) {
            return array('ok' => false, 'status' => 400, 'error' => 'Missing call_id');
        }
        if ($this->surveyName === '') {
            return array('ok' => false, 'status' => 500, 'error' => 'Bad study name');
        }
        $fetched = $this->fetchLiveCall($callId, $expectedSlot);
        if (!$fetched['ok']) {
            return $fetched;
        }
        $call = $fetched['call'];
        $ttl = self::ttlForSlot($expectedSlot);
        if ($expectedSlot === 'label') {
            $results = $this->resolveLabels(array($call['id'] => array('expr' => $call['expr'])), $answers, $ttl);
        } else {
            $results = $this->resolveExpressions(array($call['id'] => array('expr' => $call['expr'])), $answers, $ttl);
        }
        if (!array_key_exists($call['id'], $results)) {
            return array('ok' => false, 'status' => 502, 'error' => 'Evaluation failed');
        }
        return array('ok' => true, 'result' => $results[$call['id']]);
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
        $argsHash = self::argsHash($answers);
        $results = $this->cachedResults(array_keys($calls), $argsHash, $ttl);
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
        $markdown = implode(OpenCPU::STRING_DELIMITER, $sources);

        // Overlay the latest answers onto the survey frame via a hidden knitr
        // chunk (getRunData returns a structured array for opencpu_define_vars;
        // we can't append raw R to it).
        if (!empty($answers)) {
            $overlayR = $this->formatROverlay($answers);
            $overlayChunk = "```{r overlay,echo=FALSE,results='hide',message=FALSE,warning=FALSE}\n"
                          . ".fmr.overlay <- {$overlayR}\n"
                          . "if (exists('{$this->surveyName}') && nrow({$this->surveyName}) > 0) {\n"
                          . "  for (.n in names(.fmr.overlay)) {\n"
                          . "    if (.n %in% names({$this->surveyName})) {\n"
                          . "      {$this->surveyName}[nrow({$this->surveyName}), .n] <- .fmr.overlay[[.n]]\n"
                          . "    }\n"
                          . "  }\n"
                          . "}\n"
                          . "```\n";
            $markdown = $overlayChunk . $markdown;
        }

        $started = hrtime(true);
        try {
            $opencpu_vars = $this->unitSession->getRunData($markdown, $this->study->name);
            $session = opencpu_knitdisplay($markdown, $opencpu_vars, true, $this->study->name, 'markdown');
        } finally {
            $this->unitSession->addExecutionTime((hrtime(true) - $started) / 1e9);
        }
        if ($session && !$session->hasError()) {
            $knitted = $session->getJSONObject();
            if (is_string($knitted)) {
                $parts = explode(OpenCPU::STRING_DELIMITER, $knitted);
                $parsedown = (new ParsedownExtra())->setBreaksEnabled(true)->setUrlsLinked(true);
                foreach ($orderedCallIds as $idx => $cid) {
                    if (!isset($parts[$idx])) continue;
                    // Strip a single wrapping <p> so a dynamic label renders
                    // inline like a static one. Log-only on failure — this
                    // runs during PARTICIPANT rendering, so author-facing
                    // alert()s must not reach the participant's session.
                    try {
                        $html = remove_tag_wrapper($parsedown->text(trim($parts[$idx])));
                    } catch (\Throwable $e) {
                        formr_log_exception($e, 'PARSEDOWN.TEXT (dynamic label)');
                        $html = trim($parts[$idx]);
                    }
                    $results[$cid] = $html;
                    $this->writeCache($cid, $argsHash, $html);
                }
            }
        } else {
            formr_log('RCallService::resolveLabels OpenCPU error: ' . (string) opencpu_last_error());
        }
        return $results;
    }

    // ------------------------------------------------------------------
    // R value plumbing (formerly RunController statics)
    // ------------------------------------------------------------------

    /**
     * One R literal for one answer. $numeric says whether the item's own
     * column is numeric (see rTypeForResultField) — it is NOT inferred from
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

    public static function rResultToBool($result) {
        if (is_bool($result)) return $result;
        if (is_int($result) || is_float($result)) return ((float) $result) != 0.0;
        if (is_string($result)) {
            $l = strtolower(trim($result));
            return $l === 'true' || $l === 't' || $l === '1';
        }
        return (bool) $result;
    }
}
