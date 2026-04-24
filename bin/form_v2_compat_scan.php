#!/usr/bin/php
<?php
/**
 * form_v2_compat_scan.php — classify a SurveyStudy's `showif` and `value`
 * expressions for form_v2 rendering.
 *
 * Usage:
 *   php bin/form_v2_compat_scan.php <study_id|study_name>
 *
 * Categories:
 *   - empty            — no expression
 *   - r-wrapped        — `r(...)` wrapped; server-evaluated via /form-r-call
 *                        or /form-fill (no admin action needed)
 *   - JS-OK            — the v1 transpile (Item.php regex pass) yields
 *                        expression that looks like valid JS with no
 *                        residual R-only tokens
 *   - needs r(...) wrap — residual R tokens the client evaluator won't
 *                        handle; admin should wrap the source in `r(...)`
 *                        so it goes through the server path
 *
 * The classification is heuristic, not a guarantee. The client evaluator
 * has a runtime try/catch that falls back to "visible" on reference or
 * syntax errors, so a borderline-looking expression may still work at
 * runtime. Still useful for an upgrade-readiness overview.
 */
require_once dirname(__FILE__) . '/../setup.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/form_v2_compat_scan.php <study_id|study_name>\n");
    exit(1);
}

$arg = $argv[1];
$db = DB::getInstance();

$study = is_numeric($arg)
    ? $db->findRow('survey_studies', ['id' => (int) $arg])
    : $db->findRow('survey_studies', ['name' => $arg]);
if (!$study) {
    fwrite(STDERR, "Study not found: {$arg}\n");
    exit(1);
}

echo "Study '{$study['name']}' (id={$study['id']}, rendering_mode="
    . ($study['rendering_mode'] ?? 'null') . ")\n\n";

$items = $db->select('*')->from('survey_items')
    ->where('study_id = :sid')
    ->bindParams(['sid' => $study['id']])
    ->order('`order`', 'asc')
    ->fetchAll();

$itemFactory = new ItemFactory([]);

$categoryCounts = [
    'showif' => ['empty' => 0, 'r_wrapped' => 0, 'js_ok' => 0, 'needs_wrap' => 0],
    'value'  => ['empty' => 0, 'r_wrapped' => 0, 'js_ok' => 0, 'needs_wrap' => 0],
];
$flaggedRows = [];

foreach ((array) $items as $row) {
    foreach (['showif', 'value'] as $col) {
        $raw = trim((string) ($row[$col] ?? ''));
        if ($raw === '') { $categoryCounts[$col]['empty']++; continue; }

        if (preg_match('/^r\s*\(.*\)\s*$/s', $raw)) {
            $categoryCounts[$col]['r_wrapped']++;
            continue;
        }

        // For `showif`, we can compare against the Item.php transpile output
        // (js_showif). For `value`, v1 doesn't pre-transpile to JS — the
        // value is either a literal or evaluated via OpenCPU. So for value
        // we just scan the raw text for obvious R-only tokens.
        $transpiled = $raw;
        if ($col === 'showif') {
            try {
                $item = $itemFactory->make($row);
                $transpiled = $item && isset($item->js_showif) ? (string) $item->js_showif : $raw;
            } catch (Throwable $e) {
                $transpiled = $raw;
            }
        }

        $problems = detectRTokens($transpiled);
        if (empty($problems)) {
            $categoryCounts[$col]['js_ok']++;
        } else {
            $categoryCounts[$col]['needs_wrap']++;
            $flaggedRows[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'type' => $row['type'],
                'column' => $col,
                'source' => $raw,
                'transpiled' => $transpiled,
                'problems' => $problems,
            ];
        }
    }
}

if (!empty($flaggedRows)) {
    echo "Flagged items (likely need r(...) wrapping):\n";
    echo str_repeat('-', 110) . "\n";
    foreach ($flaggedRows as $r) {
        echo sprintf("  item %s %-20s [%s] %s\n",
            $r['id'], $r['name'], $r['column'], implode(', ', $r['problems']));
        echo "    source:     {$r['source']}\n";
        if ($r['transpiled'] !== $r['source']) {
            echo "    transpiled: {$r['transpiled']}\n";
        }
        $inner = addslashes($r['source']);
        echo "    suggested:  r({$r['source']})\n\n";
    }
} else {
    echo "No problematic expressions found.\n\n";
}

echo "Summary:\n";
foreach (['showif', 'value'] as $col) {
    $c = $categoryCounts[$col];
    $total = array_sum($c);
    echo "  $col ($total items):\n";
    echo "    empty:              {$c['empty']}\n";
    echo "    r(...) wrapped:     {$c['r_wrapped']}\n";
    echo "    JS-transpile OK:    {$c['js_ok']}\n";
    echo "    needs r(...) wrap:  {$c['needs_wrap']}\n";
}

exit(empty($flaggedRows) ? 0 : 2);

/**
 * Scan a (possibly transpiled) expression for tokens that won't evaluate
 * in the client-side JS showif runtime.
 *
 * @return array<string> list of problem labels (empty = looks clean)
 */
function detectRTokens(string $expr): array {
    $problems = [];

    // Strip JS string literals so tokens inside strings don't trigger
    // false positives (e.g. `"tail(x, 1)"` in a user-visible label).
    $code = preg_replace('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/', '""', $expr);

    if (preg_match('/\b(ifelse|c|nrow|ncol|paste|paste0|sprintf|format|grepl|grep|sub|gsub|is\.null|is\.numeric|is\.character|unlist|lapply|sapply|rev|sort|unique|which|rowSums|colSums)\s*\(/', $code)) {
        $problems[] = 'R-only function call';
    }
    // `tail(x, 1)` is transpiled to `x`, but other forms survive.
    if (preg_match('/\b(tail|head)\s*\(/', $code) && !preg_match('/\b(tail|head)\s*\([^)]*,\s*1\s*\)/', $code)) {
        $problems[] = 'tail/head with non-1 arg';
    }
    // `is.na(X)` gets transpiled; leftover after transpile is a red flag.
    if (preg_match('/\bis\.na\s*\(/', $code)) {
        $problems[] = 'is.na() did not transpile';
    }
    if (preg_match('/%in%|%%/', $code)) {
        $problems[] = 'R-only operator (%in% / %%)';
    }
    if (preg_match('/<-|->(?!\s*[(a-zA-Z_])/', $code)) {
        $problems[] = 'R assignment arrow';
    }
    // Bare `NA` / `NULL` R constants — JS has `null`/`undefined` but not these.
    if (preg_match('/\bNA\b|\bNA_real_\b|\bNA_character_\b|\bNA_integer_\b/', $code)) {
        $problems[] = 'R NA constant';
    }
    // `$` member access on a bare identifier: `x$y` in R is `x.y` in JS,
    // but v1's transpile doesn't do this rewrite. Flag it.
    if (preg_match('/[a-zA-Z_][a-zA-Z_0-9]*\$[a-zA-Z_]/', $code)) {
        $problems[] = 'R $-member access';
    }

    return $problems;
}
