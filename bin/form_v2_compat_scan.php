#!/usr/bin/php
<?php
/**
 * form_v2_compat_scan.php — classify a SurveyStudy's `showif` and `value`
 * expressions for form_v2 rendering.
 *
 * Usage:
 *   php bin/form_v2_compat_scan.php <study_id|study_name>
 *
 * showif buckets (showifs are JS-only in form_v2):
 *   - empty            — no expression
 *   - JS-OK            — the v1 transpile (Item.php regex pass) yields an
 *                        expression that looks like valid JS with no
 *                        residual R-only tokens
 *   - needs JS rewrite — residual R tokens the client evaluator won't
 *                        handle; rewrite in JS, or move the R into a
 *                        hidden item's `value` and reference that name
 *   - invalid: r()     — `r(...)` in a showif is rejected at render time;
 *                        same fix: hidden item + `value`
 * value buckets (the value column is the R path):
 *   - empty / literal / R
 *
 * The classification is heuristic, not a guarantee. The client evaluator
 * has a runtime try/catch: on a reference or syntax error the item keeps
 * the visibility the server rendered it with, so a borderline-looking
 * expression may still behave at runtime. Still useful for an
 * upgrade-readiness overview.
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
    echo "Flagged expressions:\n";
    echo str_repeat('-', 110) . "\n";
    foreach ($report['flagged'] as $r) {
        echo sprintf("  item %s %-20s [%s] %s\n",
            $r['id'], $r['name'], $r['column'], implode(', ', $r['problems']));
        echo "    source:     {$r['source']}\n";
        if ($r['transpiled'] !== $r['source']) {
            echo "    transpiled: {$r['transpiled']}\n";
        }
        $fixes = [
            'create_hidden_field' => 'move the R into a hidden item\'s `value` and reference that name from the showif',
            'rewrite_in_js' => 'rewrite the showif in JS (or move the R into a hidden item\'s `value`)',
        ];
        echo "    fix:        " . ($fixes[$r['suggested_fix']] ?? $r['suggested_fix']) . "\n\n";
    }
} else {
    echo "No problematic expressions found.\n\n";
}

echo "Summary:\n";

$cs = $report['counts']['showif'];
echo "  showif (" . array_sum($cs) . " items, JS-only):\n";
echo "    empty:                  {$cs['empty']}\n";
echo "    JS-OK:                  {$cs['js_ok']}\n";
echo "    needs JS rewrite:       {$cs['needs_js_rewrite']}\n";
echo "    r() in showif (invalid): {$cs['invalid_r']}\n";

$cv = $report['counts']['value'];
echo "  value (" . array_sum($cv) . " items, R-only):\n";
echo "    empty:                  {$cv['empty']}\n";
echo "    literal (numeric):      {$cv['literal']}\n";
echo "    R (allowlisted):        {$cv['r']}\n";

exit(empty($report['flagged']) ? 0 : 2);
