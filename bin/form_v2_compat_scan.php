#!/usr/bin/php
<?php
/**
 * form_v2_compat_scan.php — classify a SurveyStudy's `showif` and `value`
 * expressions for form_v2 rendering.
 *
 * Usage:
 *   php bin/form_v2_compat_scan.php <study_id|study_name>
 *
 * showif buckets (in form_v2 a showif is JavaScript, used as written):
 *   - empty            — no expression
 *   - JS-OK            — no R in it
 *   - needs JS rewrite — contains R (&, TRUE, %contains%, is.na(), …) the
 *                        browser can't evaluate; rewrite in JS, or move the
 *                        R into a hidden item's `value` and reference that
 *                        name
 *   - not on the form  — reads a name that is not an item of this form
 *                        (a run variable, another survey's item); bridge
 *                        it through a hidden item
 * not supported in v2 (column `type`): calculate items and other no-input
 *   items with an R `value` (Item::formV2Unsupported); they block the switch
 * value buckets (the value column is the R path):
 *   - empty / literal / R
 *
 * The classification is heuristic, not a guarantee. An item with a showif
 * renders hidden and only the browser reveals it, so on a reference or
 * syntax error the item stays hidden for good — treat every showif flag as
 * work to do before the switch (the admin switch refuses while any stand,
 * unless the author confirms with "Upgrade anyway").
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

// Study names are unique per owner only: a name two owners use is ambiguous.
$matches = is_numeric($arg)
    ? $db->select('*')->from('survey_studies')->where(['id' => (int) $arg])->fetchAll()
    : $db->select('*')->from('survey_studies')->where(['name' => $arg])->fetchAll();
if (!$matches) {
    fwrite(STDERR, "Study not found: {$arg}\n");
    exit(1);
}
if (count($matches) > 1) {
    fwrite(STDERR, "Several studies are named '{$arg}' (ids " . implode(', ', array_column($matches, 'id')) . "); pass the id instead.\n");
    exit(1);
}
$study = $matches[0];

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
        $fixes = [
            'rewrite_in_js' => 'rewrite the showif in JS (or move the R into a hidden item\'s `value`)',
            'bridge_hidden_item' => 'compute the value in a hidden item\'s `value` (R) and reference that item from the showif',
            'use_hidden' => 'replace it: a `hidden` item with the same `value` if the form needs it, else compute it in the R of the unit that uses it',
            'edit_choices' => 'remove the semicolons from the choices in this choice list',
            'pack_with_semicolons' => 'if the cell lists several choices, separate them with semicolons (red;green;blue) or give each its own row; if it is one choice, nothing to do',
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
echo "    not on the form:        {$cs['not_on_form']}\n";
echo "  not supported in v2:      {$report['counts']['unsupported']}  (blocks the switch)\n";
echo "  one-cell comma lists:     {$report['counts']['comma_lists']}\n";

$cv = $report['counts']['value'];
echo "  value (" . array_sum($cv) . " items, R-only):\n";
echo "    empty:                  {$cv['empty']}\n";
echo "    literal (numeric):      {$cv['literal']}\n";
echo "    R (allowlisted):        {$cv['r']}\n";

exit(empty($report['flagged']) ? 0 : 2);
