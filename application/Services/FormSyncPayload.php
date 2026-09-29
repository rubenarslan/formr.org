<?php

/**
 * Envelope validation for the form_v2 offline-queue drain endpoint
 * (`RunController::formSyncAction`, POST /{run}/form-sync).
 *
 * This covers ONLY the scalar fields that land verbatim in
 * `survey_form_submissions` columns — uuid, page, unit_session_id, client_ts.
 * The answers themselves (`data`, `item_views`) keep going through
 * `UnitSession::updateSurveyStudyRecord`, which owns per-item validation.
 *
 * Why it is a separate class rather than inline `if`s in the controller:
 *
 *   1. It has to run BEFORE any state change. The original endpoint validated
 *      `uuid` up front but read `client_ts` straight out of the payload and
 *      only bound it at INSERT time — i.e. AFTER the answers had already been
 *      applied. A client_ts MariaDB couldn't parse (an ISO-8601 `…T…Z`
 *      timestamp is the realistic case, since that is exactly what a stale
 *      IndexedDB entry carries) therefore raised at the INSERT, which 500s
 *      the request after the apply but before the uuid ledger row exists.
 *      The client treats a 5xx as transient and re-drains the same entry —
 *      and with no ledger row the dedupe pre-check misses, so the answers are
 *      applied a second time. Same story for `page`: the column is
 *      SMALLINT UNSIGNED, so 70000 or -1 is an out-of-range error (1264) in
 *      the same position.
 *   2. RunController is not constructible in the SQLite unit lane (it needs a
 *      Site, a session and a live run), so validation that lives inside the
 *      action cannot be unit tested. A pure static helper can — see
 *      tests/FormSyncPayloadTest.php.
 */
class FormSyncPayload {

    /** RFC 4122-ish 8-4-4-4-12 hex. Same expression the endpoint has always used. */
    const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** `survey_form_submissions`.`page` is SMALLINT UNSIGNED NOT NULL (patch 103). */
    const MAX_PAGE = 65535;

    /** MariaDB DATETIME literal, no fractional part, no timezone suffix. */
    const DATETIME_PATTERN = '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/';

    /**
     * @param mixed $uuid          raw `uuid` from the JSON body / $_POST
     * @param mixed $page          raw `page` (absent → 1, the historic default)
     * @param mixed $unitSessionId raw `unit_session_id` (absent → 0 = "old client, don't gate")
     * @param mixed $clientTs      raw `client_ts` (absent / '' / null → stored NULL)
     * @return array{ok:bool, error?:string, field?:string, uuid?:string, page?:int, unit_session_id?:int, client_ts?:?string}
     */
    public static function validate($uuid, $page, $unitSessionId, $clientTs) {
        if (!is_string($uuid) || !preg_match(self::UUID_PATTERN, $uuid)) {
            return self::fail('uuid', 'Malformed uuid');
        }

        $parsedPage = self::intOrNull($page, 1);
        if ($parsedPage === null || $parsedPage < 1 || $parsedPage > self::MAX_PAGE) {
            return self::fail('page', 'Malformed page');
        }

        $parsedUsid = self::intOrNull($unitSessionId, 0);
        if ($parsedUsid === null || $parsedUsid < 0) {
            return self::fail('unit_session_id', 'Malformed unit_session_id');
        }

        $parsedTs = self::datetimeOrFalse($clientTs);
        if ($parsedTs === false) {
            return self::fail('client_ts', 'Malformed client_ts (expected YYYY-MM-DD HH:MM:SS)');
        }

        return array(
            'ok' => true,
            'uuid' => $uuid,
            'page' => $parsedPage,
            'unit_session_id' => $parsedUsid,
            'client_ts' => $parsedTs,
        );
    }

    private static function fail($field, $error) {
        return array('ok' => false, 'field' => $field, 'error' => $error);
    }

    /**
     * Strict non-negative integer. Absent/null falls back to $default (keeps the
     * pre-existing lenient defaults for clients that omit the field); anything
     * present but not a whole number is a hard error rather than a silent
     * `(int)` cast to 0 — a cast is how "page": "two" used to become page 1.
     *
     * @return int|null null = present but not an integer
     */
    private static function intOrNull($value, $default) {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return ($value == (int) $value) ? (int) $value : null;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }
        return null;
    }

    /**
     * MariaDB DATETIME literal or nothing at all.
     *
     * Deliberately strict — no `T` separator, no `Z`, no fractional seconds, no
     * "best effort" strtotime() rescue. The client has a single canonical
     * producer for this value (`mysqlDatetime()` in
     * webroot/assets/form/js/lib/time.js) and normalises legacy queue entries
     * through it before draining, so anything else on the wire is a forged or
     * broken payload and should be told so at the door — not coerced into a
     * plausible-looking timestamp that misdates the paradata.
     *
     * @return string|null|false  the literal, null when absent, false when invalid
     */
    private static function datetimeOrFalse($value) {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match(self::DATETIME_PATTERN, $value, $m)) {
            return false;
        }
        list(, $y, $mo, $d, $h, $mi, $s) = $m;
        // DATETIME's supported range starts at 1000-01-01; checkdate covers the
        // month/day pairing (incl. leap years).
        if ((int) $y < 1000 || !checkdate((int) $mo, (int) $d, (int) $y)) {
            return false;
        }
        if ((int) $h > 23 || (int) $mi > 59 || (int) $s > 59) {
            return false;
        }
        return $value;
    }
}
