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
 *
 * The scanning logic itself lives in FormV2CompatScanner so the admin UI
 * (AdminSurveyController::formV2CompatScanAction) can reuse it without
 * shelling out to php-cli.
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

$report = FormV2CompatScanner::scan((int) $study['id']);

echo "Study '{$study['name']}' (id={$study['id']}, rendering_mode="
    . ($study['rendering_mode'] ?? 'null') . ")\n\n";

if (!empty($report['flagged'])) {
    echo "Flagged items (likely need r(...) wrapping):\n";
    echo str_repeat('-', 110) . "\n";
    foreach ($report['flagged'] as $r) {
        echo sprintf("  item %s %-20s [%s] %s\n",
            $r['id'], $r['name'], $r['column'], implode(', ', $r['problems']));
        echo "    source:     {$r['source']}\n";
        if ($r['transpiled'] !== $r['source']) {
            echo "    transpiled: {$r['transpiled']}\n";
        }
        echo "    suggested:  r({$r['source']})\n\n";
    }
} else {
    echo "No problematic expressions found.\n\n";
}

echo "Summary:\n";
foreach (['showif', 'value'] as $col) {
    $c = $report['counts'][$col];
    $total = array_sum($c);
    echo "  $col ($total items):\n";
    echo "    empty:              {$c['empty']}\n";
    echo "    r(...) wrapped:     {$c['r_wrapped']}\n";
    echo "    JS-transpile OK:    {$c['js_ok']}\n";
    echo "    needs r(...) wrap:  {$c['needs_wrap']}\n";
}

exit(empty($report['flagged']) ? 0 : 2);
