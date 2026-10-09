<?php

/**
 * What a participant's page posted, and the one unit session it is for.
 *
 * The run engine executes units inside whatever request moves the run
 * session: the participant's own page, but also a v2 form's endpoints (the
 * final submit runs the unit after the form), the admin's "send to
 * position", the API's execute and advance, and every unit a cascade
 * reaches after the first. Only the first is a submission, and only for the
 * unit session the page was rendered for, which its form names in the
 * hidden `session_id` (SpreadsheetRenderer). Run::exec, the one way a
 * participant's page (or a survey preview) reaches the engine, records the
 * request here; a unit asks forUnitSession() and gets the posted fields only
 * when they name it. A stale tab's form, a POST without the id and every
 * other request are no submission: the unit runs as on a page load.
 *
 * Units read the posted data through here, never from $_POST, $_FILES or
 * the request method.
 */
class PageSubmission {

    /** @var array|null the page's posted fields, null when it posted none */
    private static $fields = null;
    /** @var array the page's uploads ($_FILES) */
    private static $files = array();
    /** @var bool the page posted more than post_max_size, so PHP dropped it */
    private static $oversized = false;

    /** Run::exec: the request is a participant's page; take what it posted. */
    public static function capture() {
        self::forget();
        if (!Request::isHTTPPostRequest()) {
            return;
        }
        self::$oversized = self::bodyExceedsPostMaxSize();
        self::$fields = $_POST;
        self::$files = $_FILES;
    }

    /**
     * Did this request's body exceed post_max_size, so that PHP dropped
     * $_POST and $_FILES? A limit of 0 (or unset) means no limit.
     *
     * @param string|null $limit post_max_size; null: this PHP's
     * @param int|null $length the body's length; null: this request's
     */
    public static function bodyExceedsPostMaxSize($limit = null, $length = null) {
        $limit = trim((string) ($limit === null ? ini_get('post_max_size') : $limit));
        $max = $limit !== '' ? convertToBytes($limit) : 0;
        if ($length === null) {
            $length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        }
        return $max > 0 && $length > $max;
    }

    /** No page submission in this request (and none recorded by an earlier capture). */
    public static function forget() {
        self::$fields = null;
        self::$files = array();
        self::$oversized = false;
    }

    /**
     * The page's submission when its form was rendered for this unit
     * session: {fields, files}. null otherwise.
     *
     * @return array{fields: array, files: array}|null
     */
    public static function forUnitSession(UnitSession $unitSession) {
        if (self::$fields === null || !isset(self::$fields['session_id'])) {
            return null;
        }
        $id = self::$fields['session_id'];
        if (!is_string($id) || !ctype_digit($id) || (int) $id !== (int) $unitSession->id) {
            return null;
        }
        return array('fields' => self::$fields, 'files' => self::$files);
    }

    /**
     * The page posted more than post_max_size. PHP then drops every field,
     * the session_id with them, so whom it was for is unknown; the unit the
     * participant is on says so (Survey).
     */
    public static function oversized() {
        return self::$oversized;
    }
}
