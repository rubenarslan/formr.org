<?php

/**
 * RAllowlistExtractor — detect `r(...)` wrappers in admin-authored R expressions
 * (form_v2 Phase 3, plan §5.3).
 *
 * An admin opts into server-side R evaluation by wrapping an expression in
 * `r(...)`. At spreadsheet import we strip the wrapper, record the inner R in
 * `survey_r_calls`, and the renderer emits a reference id instead of the raw
 * source — the client never sees R, and the server only evaluates what it
 * pre-recorded.
 *
 * This first pass handles the narrow case "the entire expression is a single
 * top-level r(...)". Admin showifs are usually single expressions, not scripts
 * that mix plain JS with embedded r() calls, so this covers most of the real
 * surface. Labels / page_body Rmd (plan §5.2) need a different extractor that
 * walks markdown; that's deferred to Phase 4.
 */
class RAllowlistExtractor {

    /**
     * Returns the inner R expression if `$expr` is a top-level r(...) wrapper,
     * or null otherwise. Handles nested parens inside the wrapper (e.g.
     * `r(foo(bar(baz)))`), whitespace around `r`, and trailing semicolons.
     */
    public static function unwrap($expr) {
        if (!is_string($expr)) {
            return null;
        }
        $trimmed = trim($expr);
        // Strip an optional trailing semicolon so admins who end their showif
        // with `;` don't accidentally defeat the detection.
        if (substr($trimmed, -1) === ';') {
            $trimmed = rtrim(substr($trimmed, 0, -1));
        }
        if (strlen($trimmed) < 4) {
            return null; // "r()" is the shortest possible, but empty inner isn't useful
        }
        // Must start with `r` (case-sensitive — R is case-sensitive, and we
        // want to avoid matching `R(` used as a variable elsewhere).
        if ($trimmed[0] !== 'r') {
            return null;
        }
        $rest = ltrim(substr($trimmed, 1));
        if ($rest === '' || $rest[0] !== '(') {
            return null;
        }
        // Scan for the matching close paren, respecting nested parens and R
        // string literals. If the close paren isn't the last non-whitespace
        // character, this isn't a single-top-level-r-wrapper.
        $depth = 0;
        $len = strlen($rest);
        $inString = false;
        $stringChar = '';
        $closeIdx = -1;
        for ($i = 0; $i < $len; $i++) {
            $ch = $rest[$i];
            if ($inString) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $i++;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $closeIdx = $i;
                    break;
                }
            }
        }
        if ($closeIdx < 0) {
            return null;
        }
        // Everything after closeIdx must be whitespace for this to count as a
        // top-level wrap — otherwise it's `r(x) + y` or similar, which we
        // don't handle yet.
        $after = trim(substr($rest, $closeIdx + 1));
        if ($after !== '') {
            return null;
        }
        $inner = trim(substr($rest, 1, $closeIdx - 1));
        if ($inner === '') {
            return null;
        }
        return $inner;
    }

    /**
     * Upsert an (study_id, slot, expr) record into survey_r_calls. Returns the
     * id. Dedups by (study_id, slot, expr_hash).
     */
    public static function record(DB $db, $studyId, $slot, $innerExpr, $itemId = null) {
        $hash = hash('sha256', $innerExpr);
        // INSERT ... ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id) lets us
        // recover the existing row's id in one roundtrip when the row already
        // exists (no need for a separate SELECT). `modified` bumps regardless.
        $stmt = $db->prepare("
            INSERT INTO `survey_r_calls` (study_id, slot, item_id, expr, expr_hash)
            VALUES (:study_id, :slot, :item_id, :expr, :expr_hash)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                item_id = VALUES(item_id),
                modified = CURRENT_TIMESTAMP
        ");
        $stmt->bindValue(':study_id', (int) $studyId, PDO::PARAM_INT);
        $stmt->bindValue(':slot', (string) $slot, PDO::PARAM_STR);
        $stmt->bindValue(':item_id', $itemId !== null ? (int) $itemId : null, $itemId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':expr', (string) $innerExpr, PDO::PARAM_STR);
        $stmt->bindValue(':expr_hash', $hash, PDO::PARAM_STR);
        $stmt->execute();
        $id = (int) $db->lastInsertId();
        return $id;
    }
}
