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
     *     flagged: list<array{id:int, name:string, type:string, column:string, source:string, problems:list<string>, suggested_fix:string}>,
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

        // With the choice lists: a rule can read an item's choices
        // (SelectOrAddMultiple_Item::formV2Unsupported).
        $choiceLists = [];
        foreach ((array) $db->select('list_name, name, label')->from('survey_item_choices')
                ->where(['study_id' => (int) $studyId])->order('id', 'ASC')->fetchAll() as $c) {
            $choiceLists[$c['list_name']][$c['name']] = $c['label'];
        }
        $itemFactory = new ItemFactory($choiceLists);
        $onV2 = $db->findValue('survey_studies', ['id' => (int) $studyId], 'rendering_mode') === 'v2';
        $formNames = [];
        foreach ((array) $items as $row) {
            if (empty($row['deleted'])) {
                $formNames[(string) $row['name']] = true;
            }
        }

        $counts = [
            // showif is JavaScript, used as written: one with R in it is
            // `needs_js_rewrite` (rewrite it in JS, or move the R into a
            // hidden item's value and reference that name).
            // `not_on_form`: names the browser has no value for (bridge them
            // through a hidden item).
            'showif' => ['empty' => 0, 'js_ok' => 0, 'needs_js_rewrite' => 0, 'not_on_form' => 0],
            // Items v2 cannot run at all (Item::formV2Unsupported: calculate
            // and other server-computed values). The switch to v2 refuses a
            // study with any of these.
            'unsupported' => 0,
            // select_or_add choice lists written in one cell with commas: v1
            // splits them at the commas, v2 at semicolons
            // (SelectOrAddOne_Item::unpackChoices). Only counted on v1, where
            // the switch would turn the list into one choice.
            'comma_lists' => 0,
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

            try {
                $typed = $itemFactory->make($row);
            } catch (Throwable $e) {
                $typed = null;
            }
            $flag = function ($column, $source, $problems, $fix) use (&$flagged, $row) {
                $flagged[] = [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'type' => (string) $row['type'],
                    'column' => $column,
                    'source' => $source,
                    'problems' => $problems,
                    'suggested_fix' => $fix,
                ];
            };

            // ---- the item itself ----
            // The rule the switch to v2 applies (SurveyStudy::formV2UnsupportedReason).
            if (($why = SurveyStudy::formV2UnsupportedReason((string) $row['name'], $typed))) {
                $counts['unsupported']++;
                if (in_array((string) $row['name'], Item::FORM_COMPONENT_NAMES, true)) {
                    $flag('name', (string) $row['name'], [$why], 'rename_item');
                } elseif ($typed && $typed->type === 'select_or_add_multiple') {
                    // A choice with a semicolon; this type takes input, so
                    // the value rule never applies to it.
                    $flag('choice_list', (string) $row['choice_list'], [$why], 'edit_choices');
                } else {
                    $flag('type', $raw_value, [$why], 'use_hidden');
                }
            } elseif (!$onV2 && $typed instanceof SelectOrAddOne_Item) {
                $choices = (array) $typed->choices;
                $only = (string) reset($choices);
                if (count($choices) === 1 && strpos($only, ',') !== false && strpos($only, ';') === false) {
                    $counts['comma_lists']++;
                    $flag('choice_list', (string) $row['choice_list'], [
                        'v1 splits this one-cell list at its commas into ' . count(explode(',', $only))
                        . ' choices; v2 splits at semicolons, so it would be a single choice',
                    ], 'pack_with_semicolons');
                }
            }

            // ---- showif ----
            if ($raw_showif === '') {
                $counts['showif']['empty']++;
            } elseif ($problems = self::detectRTokens($raw_showif)) {
                $counts['showif']['needs_js_rewrite']++;
                $flag('showif', $raw_showif, $problems, 'rewrite_in_js');
            } elseif ($unknown = self::namesNotOnForm($raw_showif, $formNames)) {
                $counts['showif']['not_on_form']++;
                $flag('showif', $raw_showif, ['not in this survey: ' . implode(', ', $unknown)], 'bridge_hidden_item');
            } else {
                $counts['showif']['js_ok']++;
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
     * May the author move the study to v2 over this finding ("Move to v2
     * anyway", `force`)? A showif the heuristic flagged, which can be wrong,
     * and a choice list written in one cell with commas, which can be meant
     * as one choice. Every other finding blocks the switch.
     *
     * @param array $finding One of scan()'s `flagged`.
     * @return bool
     */
    public static function overridable(array $finding) {
        return ($finding['column'] ?? '') === 'showif'
            || ($finding['suggested_fix'] ?? '') === 'pack_with_semicolons';
    }

    /**
     * Identifiers a showif may use besides the form's item names:
     * JavaScript's own, plus the fmrForm helpers (Item::SHOWIF_HELPERS).
     */
    const JS_KNOWN_NAMES = [
        'true', 'false', 'null', 'undefined', 'NaN', 'Infinity', 'typeof', 'instanceof', 'in', 'of',
        'new', 'this', 'void', 'Math', 'Number', 'String', 'Boolean', 'Date', 'Array', 'Object', 'JSON',
        'parseInt', 'parseFloat', 'isNaN', 'isFinite', 'RegExp',
        ...Item::SHOWIF_HELPERS,
    ];

    /**
     * The names a showif reads that are not items of this form.
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
        $unknown = [];
        foreach (self::namesIn($expr) as $name) {
            if (!isset($formNames[$name]) && !in_array($name, self::JS_KNOWN_NAMES, true)) {
                $unknown[] = $name;
            }
        }
        return $unknown;
    }

    /**
     * The names a showif reads: identifiers outside comments and string
     * literals, not called as functions, not property accesses. The one
     * reading for the compatibility scan and for which no-input values the
     * state block ships (FormSubmissionService::showifNames).
     *
     * @param string $expr
     * @return list<string>
     */
    public static function namesIn($expr) {
        $code = self::codeOnly($expr);
        preg_match_all('/(?<![\w.$])([A-Za-z_$][A-Za-z0-9_$]*)(?![\w$])(?!\s*\()/', $code, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * $expr without its comments, and with each string literal reduced to
     * "": what namesIn() and detectRTokens() read, so neither is misled by
     * text that is not code.
     *
     * @param string $expr
     * @return string
     */
    protected static function codeOnly($expr) {
        // One pass, left to right, as a tokenizer reads it: whichever starts
        // first wins, so a `//` inside a string ("https://…") is string, not
        // a comment that would swallow the rest of the line, and quotes in a
        // comment open no string.
        return preg_replace_callback(
            '~"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|`[^`]*`|/\*.*?\*/|//[^\n]*~s',
            function ($m) {
                return $m[0][0] === '/' ? ' ' : '""';
            },
            (string) $expr
        );
    }

    /**
     * Scan a showif for R that won't evaluate as JavaScript in the browser.
     * A v2 showif is used as written, so every R idiom counts — also the
     * ones v1's live preview translates (Item::showifToJs).
     *
     * @param string $expr
     * @return list<string>
     */
    public static function detectRTokens($expr) {
        $problems = [];

        // Strip comments and string literals so tokens inside them don't
        // trigger false positives (e.g. `"tail(x, 1)"` in a user-visible
        // label, `// NA handling`) — the switch to v2 stops on a finding.
        $code = self::codeOnly($expr);

        if (preg_match('/\b(ifelse|c|nrow|ncol|paste|paste0|sprintf|format|grepl|grep|sub|gsub|is\.null|is\.numeric|is\.character|unlist|lapply|sapply|rev|sort|unique|which|rowSums|colSums|tail|head|current)\s*\(/', $code)) {
            $problems[] = 'uses an R function';
        }
        if (preg_match('/\bis\.na\s*\(/', $code)) {
            $problems[] = 'is.na() is R: use isNA()';
        }
        if (preg_match('/%[A-Za-z_]*%/', $code)) {
            $problems[] = 'R operator such as %in% or %contains%';
        }
        if (preg_match('/(?<![&|])[&|](?![&|])/', $code)) {
            $problems[] = 'single & or |: use && or ||';
        }
        if (preg_match('/\b(TRUE|FALSE)\b/', $code)) {
            $problems[] = 'TRUE/FALSE: use true/false';
        }
        if (preg_match('/[A-Za-z_.][A-Za-z0-9_.]*::/', $code)) {
            $problems[] = 'R package function such as stringr::';
        }
        if (preg_match('/<-|->(?!\s*[(a-zA-Z_])/', $code)) {
            $problems[] = 'R assignment (<-)';
        }
        if (preg_match('/\bNA\b|\bNA_real_\b|\bNA_character_\b|\bNA_integer_\b/', $code)) {
            $problems[] = 'NA: use isNA()';
        }
        if (preg_match('/[a-zA-Z_][a-zA-Z_0-9]*\$[a-zA-Z_]/', $code)) {
            $problems[] = 'survey$item: use just the item name';
        }

        return $problems;
    }
}
