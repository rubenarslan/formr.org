<?php

/**
 * `data_quality` — the page's record of automation and carelessness
 * indicators.
 *
 * A hidden input that the participant's browser fills
 * (webroot/assets/common/js/data-quality.js) with one flat JSON object of
 * counts, durations, yes/no values and a few names from fixed lists. The item
 * is what switches the recording on: a page without it records nothing, so it
 * never goes on a consent page. One per page, placed before the page's
 * submit.
 *
 * Unlike `bot_check`, whose hidden input also carries something only
 * JavaScript can produce, this item gates nothing: it is always optional,
 * whatever the sheet says, and nothing a browser posts into it can block the
 * page. What is not a record is stored as NULL.
 *
 * Admin documentation: /admin/documentation/data-quality.
 */
class DataQuality_Item extends Item {

    /** A record is about 1.5 KB; anything much larger is not one. */
    const MAX_BYTES = 8192;
    const MAX_FIELDS = 200;
    const MAX_TEXT = 200;

    public $type = 'data_quality';
    public $mysql_field = 'MEDIUMTEXT DEFAULT NULL';
    public $input_attributes = array('type' => 'hidden');
    public $optional = 1;
    protected $unseen = true;

    public function __construct($options = array()) {
        // Always optional: a required one could never be answered where
        // JavaScript does not run, and would block the page.
        $options['optional'] = 1;
        parent::__construct($options);
    }

    protected function setMoreOptions() {
        unset($this->input_attributes['required']);
        // What the recorder looks for (FIELD_SELECTOR).
        $this->input_attributes['data-fmr-dq'] = '1';
        $this->classes_wrapper[] = 'hidden';
    }

    public function validate() {
        if (trim((string) $this->value) !== '') {
            $this->val_errors[] = "'{$this->name}' A data_quality item is filled in by the participant's browser and cannot have a value.";
        }
        return parent::validate();
    }

    protected function render_inner() {
        return $this->render_input();
    }

    /**
     * The stored record goes back into the input (the recorder adds this
     * visit's counts to it), but not into the form_v2 state block a second
     * time: no showif has a use for it.
     */
    public function storedValueForClient($answer) {
        return null;
    }

    /** Never refused: getReply() stores NULL for what is not a record. */
    public function rejectNonScalarReply($reply, $message = null) {
        return false;
    }

    public function validateInput($reply) {
        $this->reply = self::cleanRecord($reply);
        return $this->reply;
    }

    public function getReply($reply) {
        return self::cleanRecord($reply);
    }

    /**
     * The record as it is stored: a flat JSON object of numbers, yes/no
     * values and short strings. Anything else — no JSON, a list, nested
     * values, something too large — is NULL, as is a record that names no
     * version.
     *
     * @param mixed $reply
     * @return string|null
     */
    public static function cleanRecord($reply) {
        if (!is_string($reply) || $reply === '' || strlen($reply) > self::MAX_BYTES) {
            return null;
        }
        $record = json_decode($reply, true, 2);
        if (!is_array($record) || !isset($record['v']) || count($record) > self::MAX_FIELDS) {
            return null;
        }
        $clean = array();
        foreach ($record as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,23}$/', $key)) {
                continue;
            }
            if (is_int($value) || is_float($value) || is_bool($value)) {
                $clean[$key] = $value;
            } elseif (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, self::MAX_TEXT, 'UTF-8');
            }
        }
        if (!isset($clean['v'])) {
            return null;
        }
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }

}
