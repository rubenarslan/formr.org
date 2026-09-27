<?php

/**
 * RAllowlistExtractor — records the R a v2 form may have evaluated.
 *
 * FormRenderer records every dynamic `value` (the item's R, as written),
 * label and choice label in `survey_r_calls`, and the page carries only the
 * call id — the client never sees R, and the server only evaluates what it
 * recorded (RCallService checks each call against the item's current
 * column content).
 */
class RAllowlistExtractor {

    /**
     * Upsert an (study_id, slot, expr, item) record into survey_r_calls.
     * Returns the id. Dedups by (study_id, expr_hash, slot, item_id) — one
     * row PER ITEM even when expressions are identical, because
     * /form-render-page resolves a page's calls via r.item_id.
     */
    public static function record(DB $db, $studyId, $slot, $expr, $itemId = null) {
        return self::upsert($db, $studyId, $slot, $itemId, (string) $expr, hash('sha256', (string) $expr), true);
    }

    /**
     * Cache/lookup key for one allowlist row. item_id is part of the key
     * because the UNIQUE index is (study_id, expr_hash, slot, item_id) —
     * identical expressions on two items are two rows on purpose (see record).
     */
    public static function callKey($slot, $itemId, $exprHash) {
        return (string) $slot . '|' . ($itemId === null ? '' : (int) $itemId) . '|' . (string) $exprHash;
    }

    /**
     * Every allowlist row a study already has, keyed by callKey().
     * One SELECT; the row count is bounded by the study definition (one row
     * per dynamic value / label item), not by traffic.
     *
     * @return array<string, int> callKey => id
     */
    public static function existingCalls(DB $db, $studyId) {
        $stmt = $db->prepare(
            'SELECT id, slot, item_id, expr_hash FROM `survey_r_calls` WHERE study_id = :study_id'
        );
        $stmt->bindValue(':study_id', (int) $studyId, PDO::PARAM_INT);
        $stmt->execute();
        $out = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $itemId = $row['item_id'] === null ? null : (int) $row['item_id'];
            $out[self::callKey($row['slot'], $itemId, $row['expr_hash'])] = (int) $row['id'];
        }
        return $out;
    }

    /**
     * Bulk variant of record() for the hot render path: ONE SELECT for the
     * whole study, then an INSERT only for rows that are actually missing.
     *
     * record() upserts unconditionally, so it wrote (and bumped `modified` on)
     * one row per dynamic item on EVERY participant render — pure write
     * amplification, since the allowlist is a function of the study definition,
     * not of the session. ensure() leaves existing rows untouched: no
     * `modified` bump, so a steady-state render does zero writes. The
     * per-missing-row INSERT still carries `ON DUPLICATE KEY UPDATE id =
     * LAST_INSERT_ID(id)` so a concurrent render that inserted the same row
     * between our SELECT and INSERT yields its id instead of an error.
     *
     * @param array<int|string, array{slot:string, expr:string, item_id?:int|null}> $calls
     * @return array<string, int> callKey => id (one entry per distinct call)
     */
    public static function ensure(DB $db, $studyId, array $calls) {
        if (!$calls) {
            return array();
        }
        $wanted = array();
        foreach ($calls as $call) {
            $slot = (string) $call['slot'];
            $expr = (string) $call['expr'];
            $itemId = (isset($call['item_id']) && $call['item_id'] !== null) ? (int) $call['item_id'] : null;
            $hash = hash('sha256', $expr);
            $wanted[self::callKey($slot, $itemId, $hash)] = array($slot, $itemId, $expr, $hash);
        }
        $existing = self::existingCalls($db, $studyId);
        $out = array();
        foreach ($wanted as $key => $spec) {
            if (isset($existing[$key])) {
                $out[$key] = $existing[$key];
                continue;
            }
            list($slot, $itemId, $expr, $hash) = $spec;
            $out[$key] = self::upsert($db, $studyId, $slot, $itemId, $expr, $hash, false);
        }
        return $out;
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) recovers the
     * existing row's id in one roundtrip. $touchModified keeps record()'s
     * historic semantics (bump `modified` on every call) while ensure()'s
     * only-missing path never touches an existing row.
     */
    protected static function upsert(DB $db, $studyId, $slot, $itemId, $expr, $hash, $touchModified) {
        $modifiedClause = $touchModified ? ', modified = CURRENT_TIMESTAMP' : '';
        $stmt = $db->prepare("
            INSERT INTO `survey_r_calls` (study_id, slot, item_id, expr, expr_hash)
            VALUES (:study_id, :slot, :item_id, :expr, :expr_hash)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id){$modifiedClause}
        ");
        $stmt->bindValue(':study_id', (int) $studyId, PDO::PARAM_INT);
        $stmt->bindValue(':slot', (string) $slot, PDO::PARAM_STR);
        $stmt->bindValue(':item_id', $itemId !== null ? (int) $itemId : null, $itemId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':expr', (string) $expr, PDO::PARAM_STR);
        $stmt->bindValue(':expr_hash', (string) $hash, PDO::PARAM_STR);
        $stmt->execute();
        return (int) $db->lastInsertId();
    }
}
