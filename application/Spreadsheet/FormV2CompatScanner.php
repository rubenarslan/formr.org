<?php

/**
 * Classify each non-empty `showif` / `value` on a SurveyStudy for form_v2
 * rendering compatibility. Originally a CLI-only helper (see
 * bin/form_v2_compat_scan.php); extracted to a class so the admin UI can
 * surface the same report without shelling out.
 *
 * Heuristic — the client evaluator has a runtime try/catch that falls back
 * to "visible" on reference or syntax errors, so a borderline expression
 * may still work at runtime. Useful for upgrade-readiness overviews and as
 * a CI gate (the CLI exits 2 when anything is flagged).
 */
class FormV2CompatScanner {

    /**
     * @param int $studyId
     * @return array{
     *     counts: array{
     *         showif: array{empty:int, invalid_r:int, js_ok:int, needs_js_rewrite:int},
     *         value: array{empty:int, literal:int, r:int}
     *     },
     *     flagged: list<array{id:int, name:string, type:string, column:string, source:string, transpiled:string, problems:list<string>, suggested_fix:string}>,
     *     itemCount: int
     * }
     */
    public static function scan($studyId) {
        $db = DB::getInstance();
        $items = $db->select('*')->from('survey_items')
            ->where('study_id = :sid')
            ->bindParams(['sid' => (int) $studyId])
            ->order('`order`', 'asc')
            ->fetchAll();

        $itemFactory = new ItemFactory([]);

        $counts = [
            // showif is JS-only. r() in showif is invalid (`invalid_r`);
            // bare R that doesn't transpile cleanly is `needs_js_rewrite`
            // (admin should rewrite in JS or move R into a hidden value).
            'showif' => ['empty' => 0, 'invalid_r' => 0, 'js_ok' => 0, 'needs_js_rewrite' => 0],
            // value is R-only — every non-empty, non-numeric value is
            // routed through the allowlist as R. Buckets describe how the
            // entry will be handled, not whether it's valid.
            'value'  => ['empty' => 0, 'literal' => 0, 'r' => 0],
        ];
        $flagged = [];

        foreach ((array) $items as $row) {
            $raw_showif = trim((string) ($row['showif'] ?? ''));
            $raw_value = trim((string) ($row['value'] ?? ''));

            // ---- showif ----
            if ($raw_showif === '') {
                $counts['showif']['empty']++;
            } elseif (preg_match('/^r\s*\(.*\)\s*$/s', $raw_showif)) {
                $counts['showif']['invalid_r']++;
                $flagged[] = [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'type' => (string) $row['type'],
                    'column' => 'showif',
                    'source' => $raw_showif,
                    'transpiled' => $raw_showif,
                    'problems' => ['r() in showif is no longer supported — migrate to a hidden field with R in its value'],
                    'suggested_fix' => 'create_hidden_field',
                ];
            } else {
                $transpiled = $raw_showif;
                try {
                    $item = $itemFactory->make($row);
                    $transpiled = $item && isset($item->js_showif) ? (string) $item->js_showif : $raw_showif;
                } catch (Throwable $e) { /* keep $raw_showif */ }
                $problems = self::detectRTokens($transpiled);
                if (empty($problems)) {
                    $counts['showif']['js_ok']++;
                } else {
                    $counts['showif']['needs_js_rewrite']++;
                    $flagged[] = [
                        'id' => (int) $row['id'],
                        'name' => (string) $row['name'],
                        'type' => (string) $row['type'],
                        'column' => 'showif',
                        'source' => $raw_showif,
                        'transpiled' => $transpiled,
                        'problems' => $problems,
                        'suggested_fix' => 'rewrite_in_js',
                    ];
                }
            }

            // ---- value (R-only — every non-empty, non-numeric value is R) ----
            if ($raw_value === '') {
                $counts['value']['empty']++;
            } elseif (is_numeric($raw_value)) {
                $counts['value']['literal']++;
            } else {
                // Anything else (bare R, sticky keyword, identifiers,
                // r-wrapped legacy entries) gets allowlisted + evaluated.
                // Not flagged.
                $counts['value']['r']++;
            }
        }

        return [
            'counts' => $counts,
            'flagged' => $flagged,
            'itemCount' => count((array) $items),
        ];
    }

    /**
     * Scan a (possibly-transpiled) expression for tokens that won't evaluate
     * in the client-side JS showif runtime.
     *
     * @param string $expr
     * @return list<string>
     */
    public static function detectRTokens($expr) {
        $problems = [];

        // Strip JS string literals so tokens inside strings don't trigger
        // false positives (e.g. `"tail(x, 1)"` in a user-visible label).
        $code = preg_replace('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/', '""', $expr);

        if (preg_match('/\b(ifelse|c|nrow|ncol|paste|paste0|sprintf|format|grepl|grep|sub|gsub|is\.null|is\.numeric|is\.character|unlist|lapply|sapply|rev|sort|unique|which|rowSums|colSums)\s*\(/', $code)) {
            $problems[] = 'R-only function call';
        }
        if (preg_match('/\b(tail|head)\s*\(/', $code) && !preg_match('/\b(tail|head)\s*\([^)]*,\s*1\s*\)/', $code)) {
            $problems[] = 'tail/head with non-1 arg';
        }
        if (preg_match('/\bis\.na\s*\(/', $code)) {
            $problems[] = 'is.na() did not transpile';
        }
        if (preg_match('/%in%|%%/', $code)) {
            $problems[] = 'R-only operator (%in% / %%)';
        }
        if (preg_match('/<-|->(?!\s*[(a-zA-Z_])/', $code)) {
            $problems[] = 'R assignment arrow';
        }
        if (preg_match('/\bNA\b|\bNA_real_\b|\bNA_character_\b|\bNA_integer_\b/', $code)) {
            $problems[] = 'R NA constant';
        }
        if (preg_match('/[a-zA-Z_][a-zA-Z_0-9]*\$[a-zA-Z_]/', $code)) {
            $problems[] = 'R $-member access';
        }

        return $problems;
    }
}
