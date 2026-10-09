<?php

/**
 * form_v2: the one path a page submission takes, whether it arrives live
 * (/form-page-submit) or from the offline queue (/form-sync).
 *
 * The controller actions keep the gates (method, session, rate limit,
 * stale-tab check); everything after the gates is here: reading the
 * request into one shape (readPayload), applying a queued entry once
 * (applyQueued), storing the answers,
 * recording which items the browser hides, and — on the final page — the
 * required check and ending the unit session. The reply carries the stored
 * state of the items just written, in the same shape as the page's state
 * block (FormRenderer::stateBlock → itemState), so the browser merges it
 * into its copy and derives the next page itself. Nothing here decides
 * where the participant goes: see
 * documentation/agent_doc/plan_form_v2_navigation_state.md.
 */
class FormSubmissionService {

    /** Seconds a sync waits while another request applies the same entry. */
    const SYNC_LOCK_WAIT = 5;

    /** How many of a tab's earlier documents a write may name (claimWrite). */
    const PREV_DOCS = 5;

    /** @var UnitSession */
    protected $unitSession;
    /** @var SurveyStudy */
    protected $study;
    /** @var DB */
    protected $db;

    public function __construct(UnitSession $unitSession, SurveyStudy $study) {
        $this->unitSession = $unitSession;
        $this->study = $study;
        $this->db = DB::getInstance();
    }

    /**
     * Read a v2 write request into one shape, whatever the transport.
     *
     *   - application/json: {page, unit_session_id, data, item_views, hidden,
     *     final, uuid?, client_ts?}. Scalars are normalised to what $_POST
     *     would carry (strings; nested structures dropped) so item validation
     *     sees the same values on both transports.
     *   - multipart/form-data (a page with a file): flat fields page,
     *     unit_session_id, final, uuid, client_ts, data[name], data[name][],
     *     item_views[bucket][id], hidden[] and files[name] for the blob, which
     *     is merged into data[name] as the {name,type,tmp_name,error,size}
     *     dict File_Item::validateInput expects.
     *
     * @param string $contentType the request's Content-Type
     * @param string $rawBody the request body (JSON transport)
     * @param array $post $_POST (multipart transport)
     * @param array $files $_FILES (multipart transport)
     * @return array|null null when the JSON body is not an object
     */
    public static function readPayload($contentType, $rawBody, array $post, array $files) {
        if (stripos((string) $contentType, 'multipart/form-data') === 0) {
            $src = $post;
            $data = (isset($src['data']) && is_array($src['data'])) ? $src['data'] : array();
            $data = self::mergeUploadedFiles($data, $files);
        } else {
            $src = json_decode((string) $rawBody, true);
            if (!is_array($src)) {
                return null;
            }
            $data = array();
            foreach ((isset($src['data']) && is_array($src['data'])) ? $src['data'] : array() as $k => $v) {
                $v = self::postScalar($v);
                // A multiple choice with nothing ticked arrives as [] here and
                // as '' over multipart (FormData has no empty array): one
                // shape, v1's empty placeholder.
                if ($v === array()) {
                    $v = '';
                }
                if ($v !== null) {
                    $data[$k] = $v;
                }
            }
        }
        $hidden = array();
        if (isset($src['hidden']) && is_array($src['hidden'])) {
            $hidden = array_values(array_unique(array_filter(array_map('intval', $src['hidden']))));
        }
        return array(
            'page' => isset($src['page']) ? (int) $src['page'] : 1,
            'unit_session_id' => isset($src['unit_session_id']) ? (int) $src['unit_session_id'] : 0,
            'data' => $data,
            'item_views' => self::itemViews(isset($src['item_views']) ? $src['item_views'] : null),
            'hidden' => $hidden,
            'final' => !empty($src['final']) && $src['final'] !== 'false' && $src['final'] !== '0',
            'uuid' => isset($src['uuid']) ? (string) $src['uuid'] : '',
            // The form document that wrote and its write number (main.js
            // collectPayload): orders an autosave against page submits.
            'doc' => (isset($src['doc']) && preg_match('/^[A-Za-z0-9-]{1,64}$/', (string) $src['doc'])) ? (string) $src['doc'] : '',
            'seq' => isset($src['seq']) ? (int) $src['seq'] : 0,
            // The version of the form the document was rendered at, and the
            // documents its tab showed before it (claimWrite); base is null
            // when the request names none.
            'base' => (isset($src['base']) && is_scalar($src['base']) && preg_match('/^\d{1,10}$/', (string) $src['base'])) ? (int) $src['base'] : null,
            'prev' => self::docIds(isset($src['prev']) ? $src['prev'] : null),
            'client_ts' => isset($src['client_ts']) && $src['client_ts'] !== '' ? (string) $src['client_ts'] : null,
            // raw envelope values for FormSyncPayload's strict checks
            'raw_page' => isset($src['page']) ? $src['page'] : null,
            'raw_unit_session_id' => isset($src['unit_session_id']) ? $src['unit_session_id'] : null,
        );
    }

    /** At most PREV_DOCS document ids (main.js formDocId), the malformed left out. */
    public static function docIds($ids) {
        $out = array();
        foreach (is_array($ids) ? $ids : array() as $id) {
            if (is_string($id) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) && count($out) < self::PREV_DOCS) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The item-view paradata (bucket => item id => value) as the display
     * columns take it: `shown`/`answered` a MariaDB DATETIME literal,
     * `*_relative` a number. Anything else is dropped (the write then stamps
     * the time itself, UnitSession::writeSurveyStudyRecord): a value the
     * column refuses would fail the whole page's write, a 5xx the browser
     * queues and retries with the same value for good.
     *
     * @param mixed $views
     * @return array<string, array<int, string>>
     */
    public static function itemViews($views) {
        $out = array();
        if (!is_array($views)) {
            return $out;
        }
        foreach (array('shown', 'answered', 'shown_relative', 'answered_relative') as $bucket) {
            if (!isset($views[$bucket]) || !is_array($views[$bucket])) {
                continue;
            }
            $relative = substr($bucket, -9) === '_relative';
            foreach ($views[$bucket] as $itemId => $value) {
                if (!is_scalar($value) || !preg_match('/^\d+$/', (string) $itemId)) {
                    continue;
                }
                $value = (string) $value;
                if ($relative) {
                    $ok = is_numeric($value) && is_finite((float) $value);
                } else {
                    $ok = $value !== '' && FormSyncPayload::datetimeOrFalse($value) !== false;
                }
                if ($ok) {
                    $out[$bucket][(int) $itemId] = $value;
                }
            }
        }
        return $out;
    }

    /**
     * A JSON value as $_POST would have carried it: strings stay, numbers and
     * booleans become their form-field string, one level of array is kept
     * (`name[]` fields), anything deeper is not a form value.
     */
    public static function postScalar($value) {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_array($value)) {
            $out = array();
            foreach ($value as $k => $v) {
                if (!is_scalar($v)) {
                    return null;
                }
                $out[$k] = self::postScalar($v);
            }
            return $out;
        }
        return null;
    }

    /**
     * Re-project PHP's per-field $_FILES['files'] layout into one dict per
     * item, beside the page's other answers.
     */
    protected static function mergeUploadedFiles(array $data, array $files) {
        if (!isset($files['files']) || !is_array($files['files']['name'])) {
            return $data;
        }
        $f = $files['files'];
        foreach ($f['name'] as $itemName => $_) {
            if (!is_string($itemName) || $itemName === '') continue;
            $data[$itemName] = array(
                'name' => $f['name'][$itemName] ?? '',
                'type' => $f['type'][$itemName] ?? '',
                'tmp_name' => $f['tmp_name'][$itemName] ?? '',
                'error' => $f['error'][$itemName] ?? UPLOAD_ERR_NO_FILE,
                'size' => $f['size'][$itemName] ?? 0,
            );
        }
        return $data;
    }

    /**
     * Store one submitted page and answer with what is now stored.
     *
     * Steps: validate + write the answers (UnitSession::updateSurveyStudyRecord,
     * v1's path); record the browser's showif decisions for the whole form;
     * and, when the browser says this was the last page, check the required
     * items and end the unit session. A validation failure writes nothing and returns the errors.
     *
     * A page from a document that has not seen the latest write of the form
     * (claimWrite) is refused — {status:'stale'}, nothing written — and the
     * document reloads. A page from the offline queue ($queued) has no
     * document left to reload: its answers are stored, but not its hidden
     * list, which was taken from a view of the form that is out of date
     * (`stale` in the reply).
     *
     * @param array $payload from readPayload
     * @param bool $queued the page comes from the offline queue (applyQueued)
     * @return array {status:'ok', items, stale?, complete?, redirect?,
     *   missing?}, {status:'errors', errors: name => message} or
     *   {status:'stale'}
     */
    public function apply(array $payload, $queued = false) {
        $current = $this->claimWrite(isset($payload['doc']) ? $payload['doc'] : '', isset($payload['base']) ? $payload['base'] : null,
            isset($payload['prev']) ? $payload['prev'] : array());
        if (!$current) {
            if (!$queued) {
                return array('status' => 'stale');
            }
            $this->countStaleWrite();
        }
        $this->unitSession->ensureSurveyStudyRecord();
        $posted = $payload['data'];
        $posted['_item_views'] = $payload['item_views'];
        if (!$this->unitSession->updateSurveyStudyRecord($posted, true)) {
            $errors = is_array($this->unitSession->errors) ? $this->unitSession->errors : array();
            if (!$errors && ($this->unitSession->ended !== null || $this->unitSession->expired !== null)) {
                // Ended or expired meanwhile (UnitSession::writeSurveyStudyRecord):
                // nothing is stored, and the retry the browser makes of a 5xx
                // is refused by the gate as no longer current (drop_entry).
                throw new RuntimeException('form_v2: the unit session ended or expired before the page was stored');
            }
            if (!$errors) {
                // Not a validation failure (the write itself failed: a value
                // too long for its column, a deadlock). There is nothing for
                // the participant to fix, so it must not look like one: a
                // 5xx makes the browser queue the page and retry (or say it
                // could not be sent), instead of a Next button that silently
                // does nothing.
                throw new RuntimeException('form_v2: the page could not be stored (see the preceding UnitSession exception)');
            }
            return array('status' => 'errors', 'errors' => $errors);
        }
        $retracted = $current ? $this->markHiddenItems($payload['hidden']) : array();

        $response = array('status' => 'ok');
        if (!$current) {
            $response['stale'] = true;
        }
        // The page's items, and any answer hiding just retracted elsewhere in
        // the form — the browser's store must not keep that one as answered.
        $names = array_values(array_unique(array_merge(array_keys($payload['data']), $retracted)));
        if (!empty($payload['final'])) {
            // The page itself is stored either way; `missing` tells the
            // browser which required items still stand between the
            // participant and the end of the form.
            $missing = $this->unitSession->unansweredRequiredItems();
            if ($missing) {
                $response['missing'] = array();
                foreach ($missing as $name => $_) {
                    $response['missing'][$name] = __("You missed entering some required information");
                }
            } else {
                // End the unit session and move the run session to its next
                // position, under the run-session lock (RunSession::
                // endFormUnitSession); the browser's redirect to the run URL
                // then shows whatever comes next — or, when the next unit
                // sends the participant elsewhere (an External's address, a
                // paged survey's first page), goes there.
                $redirect = null;
                if (!$this->unitSession->runSession->endFormUnitSession($this->unitSession, $redirect)) {
                    throw new RuntimeException('form_v2: could not take the run-session lock to end the form');
                }
                $response['complete'] = true;
                if ($redirect !== null) {
                    $response['redirect'] = $redirect;
                }
            }
        }
        $response['items'] = $this->storedState($names);
        return $response;
    }

    /**
     * Apply one entry of the offline queue (/form-sync) at most once.
     *
     * One entry can arrive more than once at the same time: the queue is
     * shared by every tab of the origin, and they all drain when the
     * connection comes back. A named lock on the uuid lets one request
     * through at a time. The uuid is written to survey_form_submissions only
     * once the page has been applied, including, on a final page, the end of
     * the form: a uuid written before a failed end would make every retry
     * skip the end. So a uuid in the ledger means "applied", and a request
     * that dies part way (a PHP fatal, a container restart, a lost database
     * connection) leaves nothing behind: its lock goes with its connection,
     * and the retry applies the page again. Applying it again is safe; a
     * unit session's entries drain in order.
     *
     * @return array apply()'s reply; {status:'ok', already_applied:true} when
     *   the uuid is in the ledger; {status:'busy'} when another request held
     *   the lock for $lockWait seconds.
     */
    public function applyQueued($uuid, $clientTs, array $payload, $lockWait = self::SYNC_LOCK_WAIT) {
        $lock = 'form_sync_' . $uuid;
        if (!$this->db->getLock($lock, $lockWait)) {
            return array('status' => 'busy');
        }
        try {
            if ($this->db->entry_exists('survey_form_submissions', array('uuid' => $uuid))) {
                return array('status' => 'ok', 'already_applied' => true);
            }
            $result = $this->apply($payload, true);
            if ($result['status'] === 'ok') {
                $this->recordApplied($uuid, $clientTs, (int) $payload['page']);
            }
            return $result;
        } finally {
            try {
                $this->db->releaseLock($lock);
            } catch (Throwable $e) {
                // The connection is gone, and its lock went with it.
                formr_log_exception($e, __METHOD__);
            }
        }
    }

    private function recordApplied($uuid, $clientTs, $page) {
        try {
            $this->db->insert('survey_form_submissions', array(
                'uuid' => $uuid,
                'unit_session_id' => (int) $this->unitSession->id,
                'page' => $page,
                // The browser's UTC, in the server's time zone (client_time).
                'client_ts' => $clientTs !== null ? client_time($clientTs) : null,
            ));
        } catch (Throwable $e) {
            // The page is stored. Without its row a resend applies it again,
            // which is safe; failing a write that landed is not.
            formr_log_exception($e, __METHOD__);
        }
    }

    /**
     * Record the browser's showif decisions over the whole form: the posted
     * ids are hidden, every other showif item is not. A hidden item is not
     * answered, whatever it held before (autosave may have stored a value
     * while it was shown), so its answer is cleared in both tables — as in
     * v1, where a hidden item is always missing. Only showif-bearing,
     * live items can be hidden by the browser; a claim on any other item is
     * ignored.
     *
     * @param int[] $hiddenIds
     */
    public function markHiddenItems(array $hiddenIds) {
        $hidden = array_flip(array_filter(array_map('intval', $hiddenIds)));
        $sid = (int) $this->unitSession->id;
        // The session's showif-bearing, live items: one read, then the
        // writes go by key, and only for the rows that change.
        $rows = $this->db->execute(
            'SELECT `d`.`item_id`, `i`.`name`, `d`.`hidden`, `d`.`saved`, `d`.`answer`, `d`.`answered`
             FROM `survey_items_display` `d` JOIN `survey_items` `i` ON `i`.`id` = `d`.`item_id`
             WHERE `d`.`session_id` = :sid
               AND `i`.`showif` IS NOT NULL AND TRIM(`i`.`showif`) != \'\'
               AND `i`.`deleted` IS NULL',
            array('sid' => $sid)
        );
        $hide = $show = $retractedNames = array();
        foreach ((array) $rows as $row) {
            $id = (int) $row['item_id'];
            if (isset($hidden[$id])) {
                // Every submit posts the whole form's hidden list: a row
                // already hidden with nothing stored has nothing to change.
                if ((int) $row['hidden'] !== 1 || $row['saved'] !== null || $row['answer'] !== null || $row['answered'] !== null) {
                    $hide[] = $id;
                }
                if ($row['saved'] !== null) {
                    $retractedNames[] = (string) $row['name'];
                }
            } elseif ((int) $row['hidden'] === 1) {
                $show[] = $id;
            }
        }
        if ($hide) {
            $this->db->exec(
                // As withdrawAnswers: no answer, so no time it was given.
                'UPDATE `survey_items_display` SET `hidden` = 1, `answer` = NULL, `saved` = NULL, `answered` = NULL, `answered_relative` = NULL'
                . ' WHERE `session_id` = :sid AND `item_id` IN (' . implode(',', $hide) . ')',
                array('sid' => $sid)
            );
        }
        if ($show) {
            $this->db->exec(
                'UPDATE `survey_items_display` SET `hidden` = 0'
                . ' WHERE `session_id` = :sid AND `item_id` IN (' . implode(',', $show) . ')',
                array('sid' => $sid)
            );
        }

        $retractedNames = array_values(array_unique($retractedNames));
        $this->clearResultsColumns($retractedNames);
        return $retractedNames;
    }

    /**
     * Withdraw the stored answers of the named items: the participant
     * cleared the field or deselected the choice, and autosave says so by
     * posting it empty (withdrawnNames). The answer goes, in both tables, as
     * if it had never been given — otherwise the old answer would come back
     * on a reload, or stay in the data if they break off. Only rows with a
     * stored, non-blank answer change: a field submitted blank (an optional
     * item left empty) has nothing to withdraw, and clearing its `saved`
     * stamp would let an R default fill over what the participant left
     * empty (itemState `saved`).
     *
     * @param string[] $names
     * @return string[] the items whose answer was withdrawn
     */
    public function withdrawAnswers(array $names) {
        $names = array_values(array_filter(array_map('strval', $names), function ($n) {
            return (bool) preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $n);
        }));
        if (!$names) {
            return array();
        }
        $sid = (int) $this->unitSession->id;
        $params = array('sid' => $sid, 'study' => (int) $this->study->id);
        $marks = array();
        foreach ($names as $i => $name) {
            $marks[] = ':n' . $i;
            $params['n' . $i] = $name;
        }
        $where = '`d`.`session_id` = :sid AND `i`.`study_id` = :study AND `i`.`deleted` IS NULL'
            . ' AND `i`.`name` IN (' . implode(',', $marks) . ') AND `d`.`saved` IS NOT NULL'
            . ' AND `d`.`answer` IS NOT NULL AND `d`.`answer` <> \'\'';
        $withdrawn = array_map(function ($row) { return (string) $row['name']; }, (array) $this->db->execute(
            'SELECT DISTINCT `i`.`name` FROM `survey_items_display` `d` JOIN `survey_items` `i` ON `i`.`id` = `d`.`item_id` WHERE ' . $where,
            $params
        ));
        if (!$withdrawn) {
            return array();
        }
        $this->db->exec(
            'UPDATE `survey_items_display` `d` JOIN `survey_items` `i` ON `i`.`id` = `d`.`item_id`'
            . ' SET `d`.`answer` = NULL, `d`.`saved` = NULL, `d`.`answered` = NULL, `d`.`answered_relative` = NULL'
            . ' WHERE ' . $where,
            $params
        );
        $this->clearResultsColumns($withdrawn);
        return $withdrawn;
    }

    /**
     * The items an autosave reports as emptied: posted with no value ('' —
     * an emptied field, a deselected choice's placeholder — or no ticked
     * box), taking input, and autosaved at all (autosaveEligible: never
     * uploads or a bot_check, whose inputs are always empty).
     *
     * @param array $data the posted answers (readPayload)
     * @param Item[] $items name => item
     * @return string[]
     */
    public static function withdrawnNames(array $data, array $items) {
        $out = array();
        foreach ($data as $name => $value) {
            $empty = is_array($value)
                ? !array_filter($value, function ($v) { return $v !== '' && $v !== null; })
                : ($value === '' || $value === null);
            $item = isset($items[$name]) ? $items[$name] : null;
            if ($empty && $item instanceof Item && self::isAnswerable($item) && self::autosaveEligible($item)) {
                $out[] = (string) $name;
            }
        }
        return $out;
    }

    /**
     * Items the autosave path must never touch: those whose validation has
     * side effects (Item::validationHasSideEffects). A bot_check's one-shot
     * token would be spent before the real submit; an upload can't travel in
     * the JSON autosave anyway, and an arbitrary string posted under a file
     * item's name must not be persisted as if it were one.
     */
    public static function autosaveEligible(Item $item) {
        return !$item->validationHasSideEffects();
    }

    /**
     * Is any of these accepted autosave values an answer of the
     * participant's? A page's data_quality record goes out with every
     * autosave of the page (main.js collectPayload) and is always accepted;
     * alone, it is nothing to write, and no reason to tell the page that its
     * answers were saved.
     *
     * @param array $accepted name => value, from filterAutosaveAnswers
     * @param array $items name => Item
     * @return bool
     */
    public static function holdsAnAnswer(array $accepted, array $items) {
        foreach ($accepted as $name => $value) {
            if (!(isset($items[$name]) && $items[$name] instanceof DataQuality_Item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The subset of $data (name => value) that autosave may persist: only
     * names that resolve to an eligible item AND whose value passes that
     * item's validateInput(). Validation runs on a clone so the study's item
     * objects (and the later real save) aren't left holding this attempt's
     * reply/error state.
     *
     * @param array $data posted name => value
     * @param array $items name => Item, from SurveyStudy::getItemsWithChoices
     * @return array name => value
     */
    public static function filterAutosaveAnswers(array $data, array $items) {
        $accepted = array();
        foreach ($data as $name => $value) {
            if (!isset($items[$name]) || !($items[$name] instanceof Item)) {
                continue;
            }
            $item = $items[$name];
            if (!self::autosaveEligible($item)) {
                continue;
            }
            // $_POST semantics: a form submit only ever yields strings (or
            // arrays of strings), and the validators assume that — Mc_Item's
            // choice check is `is_string($reply) && !in_array(...)`, so a JSON
            // integer 7 would sail past `mc` with choices 1..2. Normalise before
            // probing so the JSON autosave can't reach a laxer path than submit.
            $value = self::postScalar($value);
            // Nothing to persist for a cleared/empty field — and stamping
            // `saved` on an empty optional item would drop it from the next
            // render. Only real answers are autosaved.
            if ($value === null || $value === '' || $value === array()) {
                continue;
            }
            // An array only belongs to an item whose input is `<name>[]`
            // (mc_multiple, select_multiple, rank, …). Scalar validators aren't
            // written for arrays (Number_Item's trim() throws), and a scalar
            // under an array item is equally malformed.
            $inputName = isset($item->input_attributes['name']) ? (string) $item->input_attributes['name'] : $name;
            $wantsArray = substr($inputName, -2) === '[]';
            if (is_array($value) !== $wantsArray) {
                continue;
            }
            $probe = clone $item;
            $probe->error = null;
            $probe->validateInput($value);
            if ($probe->error) {
                continue;
            }
            $accepted[$name] = $value;
        }
        return $accepted;
    }

    /** Set the named columns of this session's wide results row to NULL. */
    protected function clearResultsColumns(array $names) {
        if (!$names || !Config::get('form_v2_dual_write_results', true)) {
            return;
        }
        $columns = SurveyStudy::wideTableColumns($this->study->results_table);
        $set = array();
        foreach ($names as $name) {
            if (in_array($name, $columns, true) && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) {
                $set[$name] = null;
            }
        }
        if ($set) {
            $this->db->update($this->study->results_table, $set, array('study_id' => $this->study->id, 'session_id' => (int) $this->unitSession->id));
        }
    }

    /**
     * The stored state of the named items, shaped like the page's state
     * block (one shape, one shaping function: itemState).
     *
     * @param string[] $names
     * @return array<string, array>
     */
    protected function storedState(array $names) {
        $names = array_values(array_unique(array_filter(array_map('strval', $names), function ($n) {
            return $n !== '' && $n[0] !== '_';
        })));
        if (!$names) {
            return array();
        }
        return self::stateFor($this->db, (int) $this->study->id, (int) $this->unitSession->id, $names);
    }

    /**
     * Per item name, its state-block entry (itemState) for this unit session:
     * the page's state block (FormRenderer::stateBlock, every item) and a
     * reply's `items` (storedState, the named ones).
     *
     * @param string[]|null $names null = every item
     * @return array<string, array>
     */
    public static function stateFor(DB $db, $studyId, $unitSessionId, array $names = null) {
        return self::stateFromRows(self::stateRows($db, $studyId, $unitSessionId, $names), function () use ($db, $studyId) {
            return self::showifNames($db, (int) $studyId);
        });
    }

    /**
     * The state of the items in $rows (stateRows' shape), given the names the
     * study's showifs read. FormRenderer::stateBlock passes the rows it read
     * with the items it renders.
     *
     * The names only decide whether a no-input item's value (random, ip, …)
     * is shipped, so a callable is asked for them only when such an item is
     * among the rows: a page submit's reply, made of the page's inputs,
     * reads no showif at all.
     *
     * @param array<string, true>|callable $showifNames
     */
    public static function stateFromRows(array $rows, $showifNames) {
        $factory = new ItemFactory(array());
        $names = is_callable($showifNames) ? null : (array) $showifNames;
        $out = array();
        foreach ($rows as $row) {
            $item = $factory->make($row);
            if (!$item) {
                continue;
            }
            if ($names === null && !self::isAnswerable($item)) {
                $names = (array) $showifNames();
            }
            $out[(string) $row['name']] = self::itemState($item, $row, $names === null ? array() : $names);
        }
        return $out;
    }

    /**
     * The rows behind the state block: every live item of the study (or the
     * named ones) with what the session has stored for it. Submit rows and
     * mc_heading rows carry no state.
     *
     * @param string[]|null $names null = every item
     * @return array[] rows for ItemFactory::make + itemState
     */
    public static function stateRows(DB $db, $studyId, $unitSessionId, array $names = null) {
        $params = array('session_id' => (int) $unitSessionId, 'study_id' => (int) $studyId);
        $nameFilter = '';
        if ($names !== null) {
            $marks = array();
            foreach (array_values($names) as $i => $name) {
                $marks[] = ':n' . $i;
                $params['n' . $i] = (string) $name;
            }
            $nameFilter = ' AND i.name IN (' . implode(',', $marks) . ')';
        }
        return (array) $db->execute(
            'SELECT i.id, i.study_id, i.name, i.type, i.choice_list, i.type_options, i.optional,
                    d.answer, d.page, d.hidden, d.saved
             FROM `survey_items` i
             LEFT JOIN `survey_items_display` d ON d.item_id = i.id AND d.session_id = :session_id
             WHERE i.study_id = :study_id AND i.deleted IS NULL
               AND i.type NOT IN (\'mc_heading\', \'submit\')' . $nameFilter . '
             ORDER BY d.page, d.display_order, i.`order`, i.id',
            $params
        );
    }

    /**
     * The names the study's showifs read (FormV2CompatScanner::namesIn), as
     * a set.
     *
     * @return array<string, true>
     */
    public static function showifNames(DB $db, $studyId) {
        $rows = $db->execute(
            'SELECT showif FROM `survey_items` WHERE study_id = :sid AND deleted IS NULL AND showif IS NOT NULL AND showif <> \'\'',
            array('sid' => (int) $studyId)
        );
        return self::showifNamesIn(array_column((array) $rows, 'showif'));
    }

    /**
     * The names these showifs read, as a set. Only names the showif reads —
     * not words in its string literals or comments: each listed name ships
     * that item's value (random, ip, …) to the browser.
     *
     * @param string[] $showifs
     * @return array<string, true>
     */
    public static function showifNamesIn(array $showifs) {
        $names = array();
        foreach ($showifs as $showif) {
            foreach (FormV2CompatScanner::namesIn((string) $showif) as $name) {
                $names[$name] = true;
            }
        }
        return $names;
    }

    /**
     * Count a write of this unit session for a form document — or refuse it,
     * when the document has not seen the latest write. A form can be open in
     * more than one document (two tabs, the installed app and a browser
     * tab), and each acts on its own view of it: a page submit records which
     * items its showifs hide and clears their answers, an autosave withdraws
     * the answers of the fields it shows empty. A view that predates a write
     * made elsewhere would undo that write. So every write is counted
     * (survey_unit_sessions.form_version) and names its document (form_doc);
     * a document carries the version it was rendered at (`base`, from the
     * state block), and it may write while nothing has been written since,
     * or while the latest write was its own — its own writes it has seen —
     * or its tab's: a page reloaded, or gone back to, is rendered before
     * what its predecessor still had on the wire lands (the autosave sent on
     * pagehide, a page submit), and that is the tab's own, not another
     * tab's (`prev`, the documents the tab showed before, main.js).
     *
     * @param string $doc the document (main.js formDocId); '' for none
     * @param int|null $base the version the document was rendered at; null
     *   — a request that is not the form's, which carries no version — is
     *   neither checked nor counted
     * @param string[] $prev the documents its tab showed before it (docIds)
     * @return bool whether the write may go ahead
     */
    public function claimWrite($doc, $base, array $prev = array()) {
        if ($base === null) {
            return true;
        }
        $doc = (string) $doc;
        $params = array('id' => (int) $this->unitSession->id, 'doc' => $doc !== '' ? $doc : null,
            'base' => (int) $base, 'same' => $doc);
        $own = array(':same');
        foreach (self::docIds($prev) as $i => $id) {
            $own[] = ':prev' . $i;
            $params['prev' . $i] = $id;
        }
        return $this->db->exec(
            'UPDATE `survey_unit_sessions` SET `form_version` = `form_version` + 1, `form_doc` = :doc'
            . ' WHERE `id` = :id AND (`form_version` = :base OR `form_doc` IN (' . implode(', ', $own) . '))',
            $params
        ) > 0;
    }

    /**
     * Count a queued page applied from an out-of-date view (apply): no
     * document's view includes it, its own document's included.
     */
    protected function countStaleWrite() {
        $this->db->exec(
            'UPDATE `survey_unit_sessions` SET `form_version` = `form_version` + 1, `form_doc` = NULL WHERE `id` = :id',
            array('id' => (int) $this->unitSession->id)
        );
    }

    /**
     * The version of the form a document rendered now starts from
     * (claimWrite). Read before the stored answers the document is rendered
     * with, so that it never claims to have seen a write they lack.
     */
    public static function formVersion(DB $db, $unitSessionId) {
        return (int) $db->execute(
            'SELECT `form_version` FROM `survey_unit_sessions` WHERE `id` = :id',
            array('id' => (int) $unitSessionId), true
        );
    }

    /**
     * Page submits record, per unit session and form document, the highest
     * write number they carried; an autosave of the same document numbered
     * below it was started before that submit and lands after it — it would
     * store older answers over newer ones. (Kept in the PHP session: the
     * participant's own writes; another tab is another document.)
     */
    public static function recordSubmitSeq($unitSessionId, $doc, $seq) {
        if ($doc === '' || $seq <= 0) {
            return;
        }
        $seen = (array) Session::get('fmr_submit_seq', array());
        $key = (int) $unitSessionId . ':' . $doc;
        if (!isset($seen[$key]) || $seen[$key] < $seq) {
            $seen[$key] = (int) $seq;
            Session::set('fmr_submit_seq', array_slice($seen, -50, null, true));
        }
    }

    public static function autosaveIsStale($unitSessionId, $doc, $seq) {
        if ($doc === '' || $seq <= 0) {
            return false;
        }
        $seen = (array) Session::get('fmr_submit_seq', array());
        $key = (int) $unitSessionId . ':' . $doc;
        return isset($seen[$key]) && $seen[$key] > $seq;
    }

    /** Does the item take an answer the participant gives? */
    public static function isAnswerable(Item $item) {
        return $item->requiresUserInput() && (bool) $item->save_in_results_table && $item->type !== 'blank';
    }

    /**
     * Must the participant answer it before the form can end? The one rule,
     * for the state block (the browser's navigation) and the final submit's
     * check (UnitSession::unansweredRequiredItems) alike. `optional` is the
     * item's resolved flag, so a blank sheet cell takes the type's default
     * (hidden, mc_multiple and the automatic items default to optional). A
     * `blank` renders only text and has nothing to answer.
     */
    public static function isRequired(Item $item) {
        return self::isAnswerable($item) && empty($item->optional);
    }

    /**
     * One item's entry in the state block / submit reply:
     *   id, page, answerable (takes input), required — the study's definition;
     *   answered — a value has been stored (autosave or submit);
     *   saved — something has been stored, a blank answer included;
     *   value — what was stored, in the shape the live inputs produce
     *     (arrays for multi-value items; an upload's path is not shipped);
     *   hidden — recorded as hidden by the browser's showif.
     */
    public static function itemState(Item $item, array $row, array $showifNames = null) {
        $answer = isset($row['answer']) ? $row['answer'] : null;
        // An item without an input (random, ip, browser, referrer, server, get)
        // is not on the page, so its value reaches the browser only through
        // this block — and only if a showif needs it. A random assignment or
        // an IP address has no business in the page source otherwise.
        $private = !self::isAnswerable($item) && $showifNames !== null && !isset($showifNames[(string) $item->name]);
        $value = ($private || !$item->save_in_results_table) ? null : $item->storedValueForClient($answer);
        return array(
            'id' => (int) $row['id'],
            'page' => isset($row['page']) ? (int) $row['page'] : 1,
            'answerable' => self::isAnswerable($item),
            'required' => self::isRequired($item),
            'answered' => ($answer !== null && isset($row['saved'])),
            // Stored at all, an empty answer included (an optional field left
            // blank, a deselected choice): what the participant gave, which an
            // R default must not fill over (main.js resolveAndSubstitutePage).
            'saved' => isset($row['saved']),
            'value' => $value,
            'hidden' => !empty($row['hidden']),
        );
    }
}
