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
     *     counts: array<string, array{empty:int, r_wrapped:int, js_ok:int, needs_wrap:int}>,
     *     flagged: list<array{id:int, name:string, type:string, column:string, source:string, transpiled:string, problems:list<string>}>,
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
            'showif' => ['empty' => 0, 'r_wrapped' => 0, 'js_ok' => 0, 'needs_wrap' => 0],
            'value'  => ['empty' => 0, 'r_wrapped' => 0, 'js_ok' => 0, 'needs_wrap' => 0],
        ];
        $flagged = [];

        foreach ((array) $items as $row) {
            foreach (['showif', 'value'] as $col) {
                $raw = trim((string) ($row[$col] ?? ''));
                if ($raw === '') { $counts[$col]['empty']++; continue; }
                if (preg_match('/^r\s*\(.*\)\s*$/s', $raw)) {
                    $counts[$col]['r_wrapped']++;
                    continue;
                }
                // Only `showif` goes through Item.php's regex transpile. `value`
                // is either a literal or OpenCPU-evaluated; scan raw text.
                $transpiled = $raw;
                if ($col === 'showif') {
                    try {
                        $item = $itemFactory->make($row);
                        $transpiled = $item && isset($item->js_showif) ? (string) $item->js_showif : $raw;
                    } catch (Throwable $e) {
                        $transpiled = $raw;
                    }
                }
                $problems = self::detectRTokens($transpiled);
                if (empty($problems)) {
                    $counts[$col]['js_ok']++;
                } else {
                    $counts[$col]['needs_wrap']++;
                    $flagged[] = [
                        'id' => (int) $row['id'],
                        'name' => (string) $row['name'],
                        'type' => (string) $row['type'],
                        'column' => $col,
                        'source' => $raw,
                        'transpiled' => $transpiled,
                        'problems' => $problems,
                    ];
                }
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
