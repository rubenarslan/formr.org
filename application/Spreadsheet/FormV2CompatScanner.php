<?php

/**
 * Classify each non-empty `showif` / `value` on a SurveyStudy for form_v2
 * rendering compatibility. Originally a CLI-only helper (see
 * bin/form_v2_compat_scan.php); extracted to a class so the admin UI can
 * surface the same report without shelling out.
 *
 * In form_v2 a showif is JavaScript and runs only in the browser: an item
 * with a showif renders hidden, and the browser reveals it. So an expression
 * the browser cannot evaluate — leftover R, or a name that is not an item of
 * this form (a run variable, another survey's item) — leaves its item hidden
 * for good. Both are flagged, as are items v2 cannot run at all
 * (Item::formV2Unsupported: `calculate` and other server-computed values),
 * which also block the switch to v2. Heuristic; useful for
 * upgrade-readiness overviews and as a CI gate (the CLI exits 2 when anything
 * is flagged).
 */
class FormV2CompatScanner {

    /**
     * @param int $studyId
     * @return array{
     *     counts: array{
     *         showif: array{empty:int, js_ok:int, needs_js_rewrite:int, not_on_form:int},
     *         unsupported: int,
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
        $formNames = [];
        foreach ((array) $items as $row) {
            if (empty($row['deleted'])) {
                $formNames[(string) $row['name']] = true;
            }
        }

        $counts = [
            // showif is JS-only: R that doesn't transpile cleanly is
            // `needs_js_rewrite` (rewrite it in JS, or move the R into a
            // hidden item's value and reference that name).
            // `not_on_form`: names the browser has no value for (bridge them
            // through a hidden item).
            'showif' => ['empty' => 0, 'js_ok' => 0, 'needs_js_rewrite' => 0, 'not_on_form' => 0],
            // Items v2 cannot run at all (Item::formV2Unsupported: calculate
            // and other server-computed values). The switch to v2 refuses a
            // study with any of these.
            'unsupported' => 0,
            // value is R-only — every non-empty, non-numeric value is
            // routed through the allowlist as R. Buckets describe how the
            // entry will be handled, not whether it's valid.
            'value'  => ['empty' => 0, 'literal' => 0, 'r' => 0],
        ];
        $flagged = [];

        foreach ((array) $items as $row) {
            if (!empty($row['deleted'])) {
                continue; // soft-deleted: not rendered, nothing to fix
            }
            $raw_showif = trim((string) ($row['showif'] ?? ''));
            $raw_value = trim((string) ($row['value'] ?? ''));

            // ---- the item itself ----
            try {
                $typed = $itemFactory->make($row);
            } catch (Throwable $e) {
                $typed = null;
            }
            if ($typed && ($why = $typed->formV2Unsupported())) {
                $counts['unsupported']++;
                $flagged[] = [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'type' => (string) $row['type'],
                    'column' => 'type',
                    'source' => $raw_value,
                    'transpiled' => '',
                    'problems' => [$why],
                    'suggested_fix' => 'use_hidden',
                ];
            }

            // ---- showif ----
            if ($raw_showif === '') {
                $counts['showif']['empty']++;
            } else {
                $transpiled = $raw_showif;
                $item = null;
                try {
                    $item = $itemFactory->make($row);
                    $transpiled = $item && isset($item->js_showif) ? (string) $item->js_showif : $raw_showif;
                } catch (Throwable $e) { /* keep $raw_showif */ }
                $flag = function ($problems, $fix) use (&$flagged, $row, $raw_showif, $transpiled) {
                    $flagged[] = [
                        'id' => (int) $row['id'],
                        'name' => (string) $row['name'],
                        'type' => (string) $row['type'],
                        'column' => 'showif',
                        'source' => $raw_showif,
                        'transpiled' => $transpiled,
                        'problems' => $problems,
                        'suggested_fix' => $fix,
                    ];
                };
                if ($problems = self::detectRTokens($transpiled)) {
                    $counts['showif']['needs_js_rewrite']++;
                    $flag($problems, 'rewrite_in_js');
                } elseif ($unknown = self::namesNotOnForm($transpiled, $formNames)) {
                    $counts['showif']['not_on_form']++;
                    $flag(['not an item of this form: ' . implode(', ', $unknown)], 'bridge_hidden_item');
                } else {
                    $counts['showif']['js_ok']++;
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
     * Identifiers a transpiled showif may use besides the form's item names:
     * JavaScript's own, plus the fmrForm helpers (Item::SHOWIF_HELPERS).
     */
    const JS_KNOWN_NAMES = [
        'true', 'false', 'null', 'undefined', 'NaN', 'Infinity', 'typeof', 'instanceof', 'in', 'of',
        'new', 'this', 'void', 'Math', 'Number', 'String', 'Boolean', 'Date', 'Array', 'Object', 'JSON',
        'parseInt', 'parseFloat', 'isNaN', 'isFinite', 'RegExp',
        ...Item::SHOWIF_HELPERS,
    ];

    /**
     * The names a (transpiled) showif reads that are not items of this form.
     * The browser only knows the form's own items (answers saved on earlier
     * pages included), so such a showif can never become true there.
     * Function calls are left to detectRTokens(); property accesses (`.length`)
     * are not names.
     *
     * @param string $expr
     * @param array<string, bool> $formNames
     * @return list<string>
     */
    public static function namesNotOnForm($expr, array $formNames) {
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', ' ', (string) $expr);
        $code = preg_replace('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|`[^`]*`/', '""', $code);
        preg_match_all('/(?<![\w.$])([A-Za-z_$][A-Za-z0-9_$]*)(?![\w$])(?!\s*\()/', $code, $m);
        $unknown = [];
        foreach (array_unique($m[1]) as $name) {
            if (!isset($formNames[$name]) && !in_array($name, self::JS_KNOWN_NAMES, true)) {
                $unknown[] = $name;
            }
        }
        return $unknown;
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
