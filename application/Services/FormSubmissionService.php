<?php

/**
 * form_v2: the one path a page submission takes, whether it arrives live
 * (/form-page-submit) or from the offline queue (/form-sync).
 *
 * The controller actions keep the gates (method, session, rate limit,
 * stale-tab check, the sync ledger); everything after the gates is here:
 * reading the request into one shape (readPayload), storing the answers,
 * recording which items the browser hides, and — on the final page — the
 * required check and ending the unit session. The reply carries the stored
 * state of the items just written, in the same shape as the page's state
 * block (FormRenderer::stateBlock → itemState), so the browser merges it
 * into its copy and derives the next page itself. Nothing here decides
 * where the participant goes: see
 * documentation/agent_doc/plan_form_v2_navigation_state.md.
 */
class FormSubmissionService {

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
            'item_views' => (isset($src['item_views']) && is_array($src['item_views'])) ? $src['item_views'] : array(),
            'hidden' => $hidden,
            'final' => !empty($src['final']) && $src['final'] !== 'false' && $src['final'] !== '0',
            'uuid' => isset($src['uuid']) ? (string) $src['uuid'] : '',
            'client_ts' => isset($src['client_ts']) && $src['client_ts'] !== '' ? (string) $src['client_ts'] : null,
            // raw envelope values for FormSyncPayload's strict checks
            'raw_page' => isset($src['page']) ? $src['page'] : null,
            'raw_unit_session_id' => isset($src['unit_session_id']) ? $src['unit_session_id'] : null,
        );
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
     * @param array $payload from readPayload
     * @return array {status:'ok', items, complete?, missing?} or
     *   {status:'errors', errors: name => message}
     */
    public function apply(array $payload) {
        $this->unitSession->createSurveyStudyRecord();
        $posted = $payload['data'];
        $posted['_item_views'] = $payload['item_views'];
        if (!$this->unitSession->updateSurveyStudyRecord($posted, true)) {
            $errors = is_array($this->unitSession->errors) ? $this->unitSession->errors : array();
            return array('status' => 'errors', 'errors' => $errors);
        }
        $this->markHiddenItems($payload['hidden']);

        $response = array('status' => 'ok');
        $names = array_keys($payload['data']);
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
                // position without executing it (as RunSession does for an
                // External's redirect): the browser's redirect to the run URL
                // then executes whatever comes next. Without the move the
                // next GET would start a fresh session of this same Form.
                $this->unitSession->end();
                if ($this->unitSession->runSession) {
                    $this->unitSession->runSession->moveOn(false, false);
                }
                $response['complete'] = true;
            }
        }
        $response['items'] = $this->storedState($names);
        return $response;
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
        $hiddenIds = array_values(array_unique(array_filter(array_map('intval', $hiddenIds))));
        $sid = (int) $this->unitSession->id;
        $join = 'FROM `survey_items_display` `d` JOIN `survey_items` `i` ON `i`.`id` = `d`.`item_id`';
        $showif = '`d`.`session_id` = :sid
               AND `i`.`showif` IS NOT NULL AND TRIM(`i`.`showif`) != \'\'
               AND `i`.`deleted` IS NULL';
        $idList = $hiddenIds ? implode(',', $hiddenIds) : '0';

        $retracted = $this->db->execute(
            'SELECT DISTINCT `i`.`name` ' . $join . ' WHERE ' . $showif
            . ' AND `d`.`item_id` IN (' . $idList . ') AND `d`.`saved` IS NOT NULL',
            array('sid' => $sid)
        );
        $this->db->exec(
            'UPDATE `survey_items_display` `d` JOIN `survey_items` `i` ON `i`.`id` = `d`.`item_id`'
            . ' SET `d`.`hidden` = 1, `d`.`answer` = NULL, `d`.`saved` = NULL'
            . ' WHERE ' . $showif . ' AND `d`.`item_id` IN (' . $idList . ')',
            array('sid' => $sid)
        );
        $this->db->exec(
            'UPDATE `survey_items_display` `d` JOIN `survey_items` `i` ON `i`.`id` = `d`.`item_id`'
            . ' SET `d`.`hidden` = 0'
            . ' WHERE ' . $showif . ' AND `d`.`hidden` = 1 AND `d`.`item_id` NOT IN (' . $idList . ')',
            array('sid' => $sid)
        );

        if (!$retracted || !Config::get('form_v2_dual_write_results', true)) {
            return;
        }
        $columns = SurveyStudy::wideTableColumns($this->study->results_table);
        $set = array();
        foreach ((array) $retracted as $row) {
            $name = (string) $row['name'];
            if (in_array($name, $columns, true) && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) {
                $set[$name] = null;
            }
        }
        if ($set) {
            $this->db->update($this->study->results_table, $set, array('study_id' => $this->study->id, 'session_id' => $sid));
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
        $rows = self::stateRows($this->db, (int) $this->study->id, (int) $this->unitSession->id, $names);
        $factory = new ItemFactory(array());
        $out = array();
        foreach ((array) $rows as $row) {
            $item = $factory->make($row);
            if ($item) {
                $out[(string) $row['name']] = self::itemState($item, $row);
            }
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
     * One item's entry in the state block / submit reply:
     *   id, page, answerable (takes input), required — the study's definition;
     *   answered — a value has been stored (autosave or submit);
     *   value — what was stored, in the shape the live inputs produce
     *     (arrays for multi-value items; an upload's path is not shipped);
     *   hidden — recorded as hidden by the browser's showif.
     */
    public static function itemState(Item $item, array $row) {
        $answer = isset($row['answer']) ? $row['answer'] : null;
        if ($item instanceof File_Item || !$item->save_in_results_table) {
            $value = null;
        } elseif ($answer !== null && $item->acceptsArrayReply() && !($item instanceof Geopoint_Item)) {
            $value = ($answer === '') ? array() : explode(', ', (string) $answer);
        } else {
            $value = $answer;
        }
        $answerable = $item->requiresUserInput() && (bool) $item->save_in_results_table;
        return array(
            'id' => (int) $row['id'],
            'page' => isset($row['page']) ? (int) $row['page'] : 1,
            'answerable' => $answerable,
            'required' => $answerable && empty($item->optional),
            'answered' => ($answer !== null && isset($row['saved'])),
            'value' => $value,
            'hidden' => !empty($row['hidden']),
        );
    }
}
