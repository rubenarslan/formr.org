#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for the survey export → re-import round trip
 * (SurveyStudy::getItemsForSheet / getChoicesForSheet / getSettings and the
 * SpreadsheetReader item-table exports):
 *
 *   - a v2 study created from documentation/example_surveys/all_widgets_v2.json
 *     is exported as xlsx and xls through the real download methods
 *     (exportItemTableXLSX / exportItemTableXLS, run in a child process since
 *     they write to php://output and exit);
 *   - SpreadsheetReader::readItemTableFile reads each file back with no
 *     errors: the same items (name, type, options, choice list, label,
 *     optional, class, showif, value, order), the same choices, and a
 *     settings sheet carrying every exported setting, rendering_mode v2
 *     included;
 *   - a NEW survey uploaded from the exported xlsx (createFromFile +
 *     uploadItems, as "Add a new survey" does) comes back on v2 with the same
 *     item table and choices as the original;
 *   - the JSON download (exportItemTableJSON, the exact bytes) imported as a
 *     new survey (createFromData, as the JSON upload does) does too —
 *     including a small study whose values look numeric but are text
 *     (choice names "01"/"02", a label "007", a value "1.50").
 *
 * Claims that fail because of a reported, unfixed bug go through bug(): they
 * print KNOWN BUG and do not fail the smoke (FIXED once they pass). Today:
 * the sheet export leaves every `optional = 0` blank (a required
 * mc_multiple_button comes back optional); it writes `type options list`
 * while the reader takes a list only right after the type (a text pattern
 * with a message list comes back with an unmatchable pattern); and the JSON
 * download's JSON_NUMERIC_CHECK turns "007" into 7 and "2.50" into 2.5.
 *
 * Run: docker exec formr_app php /var/www/formr/bin/test_survey_export_roundtrip_smoke.php
 * Creates and removes its own studies and files.
 */
require_once dirname(__FILE__) . '/../setup.php';
if (php_sapi_name() !== 'cli') { die("CLI only\n"); }

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label, $detail = null): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}" . ($detail !== null ? ' — ' . var_export($detail, true) : '') . "\n";
    if (!$cond) { $failures++; }
}

// A claim that currently fails because of a reported bug: printed, counted
// apart, and not failing the smoke. Once fixed it prints FIXED — turn it
// into ok() then.
$knownBugs = 0;
function bug($cond, string $label, $detail = null): void {
    global $knownBugs;
    echo $cond ? "  \e[32mFIXED\e[0m {$label} (known bug no longer reproduces: make this an ok())\n"
               : "  \e[33mKNOWN BUG\e[0m {$label}" . ($detail !== null ? ' — ' . var_export($detail, true) : '') . "\n";
    if (!$cond) { $knownBugs++; }
}

$owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
if (!$owner) { fwrite(STDERR, "No survey_users row to own the test studies.\n"); exit(2); }
$GLOBALS['user'] = new User((int) $owner['id']);
$suffix = bin2hex(random_bytes(3));
$tmpDir = APPLICATION_ROOT . 'tmp/exportsmoke_' . $suffix;
@mkdir($tmpDir, 0775, true);
$studies = [];   // every study created, for teardown
$files = [];

/** Run one of the download methods in a child process; return what it wrote. */
function download(string $method, SurveyStudy $study, string $to): string {
    $code = 'require ' . var_export(APPLICATION_ROOT . 'setup.php', true) . ';'
          . '$GLOBALS["user"] = new User(' . (int) $study->user_id . ');'
          . '(new SpreadsheetReader())->' . $method . '(SurveyStudy::loadById(' . (int) $study->id . '));';
    $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['file', $to, 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    proc_close($proc);
    return (string) @file_get_contents($to);
}
/** The comparable shape of an item row: every column the sheet carries. */
function itemShape(array $row): array {
    $out = [];
    foreach (['name', 'type', 'type_options', 'choice_list', 'label', 'optional', 'class', 'showif', 'value', 'block_order', 'item_order'] as $k) {
        $v = isset($row[$k]) ? trim((string) $row[$k]) : '';
        $out[$k] = $v;
    }
    $out['optional'] = (string) (int) $out['optional'];
    return $out;
}
function itemsOf(SurveyStudy $s): array {
    $out = [];
    foreach ($s->getItems() as $row) { $out[$row['name']] = itemShape($row); }
    return $out;
}
function choicesOf(array $rows): array {
    $out = [];
    foreach ($rows as $c) { $out[] = $c['list_name'] . '|' . $c['name'] . '|' . $c['label']; }
    sort($out);
    return $out;
}
function sheetOf(SurveyStudy $s): array {
    return array_map(function ($r) { $r['type'] = preg_replace('/\s+/', ' ', trim($r['type'])); return $r; }, $s->getItemsForSheet());
}
function teardown() {
    global $db, $studies, $files, $tmpDir;
    foreach ($studies as $s) {
        try { if ($s && $s->valid) { $db->query('DELETE FROM survey_r_calls WHERE study_id = ' . (int) $s->id); $s->delete(); } } catch (Throwable $e) {}
    }
    foreach ($files as $f) { @unlink($f); }
    @rmdir($tmpDir);
}

try {
    echo "== survey export round-trip smoke ==\n";
    $data = json_decode(file_get_contents(APPLICATION_ROOT . 'documentation/example_surveys/all_widgets_v2.json'));
    $data->name = 'expsmoke_' . $suffix;
    $orig = SurveyStudy::createFromData($data, ['user_id' => (int) $owner['id'], 'place_unit' => false]);
    $studies[] = $orig;
    ok($orig && $orig->valid && $orig->rendering_mode === 'v2', 'original created from all_widgets_v2.json, on v2', $orig ? $orig->rendering_mode : null);
    $origItems = itemsOf($orig);
    $origChoices = choicesOf($orig->getChoicesForSheet());
    ok(count($origItems) === count($data->items) && count($origChoices) > 10, 'it has every item and its choices', [count($origItems), count($origChoices)]);
    $sheet = sheetOf($orig);
    ok(array_column($sheet, 'name') === array_keys($origItems), 'getItemsForSheet lists the items in order');
    $mc = null;
    foreach ($sheet as $r) { if ($r['name'] === 'c_gods') { $mc = $r; } }
    $mcRow = $origItems['c_gods'] ?? null;
    ok($mc && $mcRow && $mc['type'] === trim(preg_replace('/\s+/', ' ', "mc {$mcRow['type_options']} {$mcRow['choice_list']}")), 'the sheet folds options and choice list into type', [$mc['type'] ?? null, $mcRow]);

    // Items whose type options AND choice list are both set (a text pattern
    // with its message list, a range with its end labels): see the bug below.
    $bothOptionsAndList = array_filter($origItems, function ($r) { return $r['type_options'] !== '' && $r['choice_list'] !== ''; });
    ok(isset($bothOptionsAndList['t_pattern'], $bothOptionsAndList['n_range']), 'the fixture has items with options and a choice list', array_keys($bothOptionsAndList));

    // A soft-deleted item stays out of the export.
    $db->exec("UPDATE survey_items SET deleted = NOW() WHERE study_id = :s AND name = 't_letters'", ['s' => $orig->id]);
    ok(!in_array('t_letters', array_column(sheetOf($orig), 'name'), true), 'a soft-deleted item is not exported');
    $db->exec("UPDATE survey_items SET deleted = NULL WHERE study_id = :s AND name = 't_letters'", ['s' => $orig->id]);

    foreach (['xlsx' => 'exportItemTableXLSX', 'xls' => 'exportItemTableXLS'] as $ext => $method) {
        echo "\n-- {$ext}: export, read back --\n";
        $path = $tmpDir . '/expsmoke_' . $suffix . '.' . $ext;
        $files[] = $path;
        $bytes = download($method, $orig, $path);
        ok(strlen($bytes) > 1000, "the {$ext} download wrote a file", strlen($bytes));
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($path);
        ok($reader->errors === [], 'read back without errors', $reader->errors);
        $read = $blankOptional = [];
        foreach ($reader->survey as $row) {
            $read[$row['name']] = itemShape($row);
            if ($row['optional'] === null && $origItems[$row['name']]['optional'] === '0') { $blankOptional[] = $row['name']; }
        }
        // BUG: getSheetsFromArrays writes with PhpSpreadsheet fromArray(), whose
        // loose null comparison leaves an integer 0 cell empty — so every
        // `optional = 0` is exported blank and re-read as "the type's default".
        // (itemShape() maps that blank to '0', so the column check below
        // passes for types whose default is required.)
        bug($blankOptional === [], 'optional 0 is written to the sheet', array_slice($blankOptional, 0, 6));
        ok(array_keys($read) === array_keys($origItems), 'the same items in the same order', array_values(array_diff(array_keys($origItems), array_keys($read))));
        $diff = $listDiff = [];
        foreach ($origItems as $name => $shape) {
            foreach ($shape as $k => $v) {
                if (isset($read[$name]) && $read[$name][$k] !== $v) {
                    $d = "{$name}.{$k}: " . var_export($v, true) . ' → ' . var_export($read[$name][$k], true);
                    if (in_array($k, ['type_options', 'choice_list'], true)) { $listDiff[] = $d; } else { $diff[] = $d; }
                }
            }
        }
        ok($diff === [], 'every other column of every item comes back as it was', array_slice($diff, 0, 8));
        // BUG: the sheet writes `type options list` (getItemsForSheet), the
        // reader takes a list name only as the FIRST token after the type (and
        // only if it is an identifier: timezone's '*' lands in type_options).
        bug($listDiff === [], 'type options and choice list come back in their own columns', $listDiff);
        ok(choicesOf($reader->choices) === $origChoices, 'the same choices', array_slice(array_diff($origChoices, choicesOf($reader->choices)), 0, 5));
        $expected = array_map(function ($v) { return trim((string) $v); }, $orig->getSettings());
        ok(($reader->settings['rendering_mode'] ?? null) === 'v2', 'the settings sheet says rendering_mode v2', $reader->settings);
        ok($reader->settings == $expected, 'and carries every exported setting', array_diff_assoc($expected, $reader->settings));
    }

    echo "\n-- a new survey uploaded from the exported xlsx --\n";
    // "Add a new survey": the study is named by the file.
    $upPath = $tmpDir . '/expsmokeb_' . $suffix . '.xlsx';
    copy($tmpDir . '/expsmoke_' . $suffix . '.xlsx', $upPath);
    $files[] = $upPath;
    $file = ['name' => basename($upPath), 'tmp_name' => $upPath];
    $copy = new SurveyStudy(null);
    ok($copy->createFromFile($file), 'study created from the file name');
    $studies[] = $copy;
    ok($copy->uploadItems($file, true, true), 'the export uploads cleanly', $copy->errors);
    $copy = SurveyStudy::loadById($copy->id);
    $studies[count($studies) - 1] = $copy;
    ok($copy->rendering_mode === 'v2', 'it comes back on v2', $copy->rendering_mode);
    // The item type itself (first token of the sheet's type column) and
    // every other column; the options/list split is the known bug below.
    // An empty cell reads back as '' where the JSON import stored NULL.
    $untyped = function ($rows) { return array_map(function ($r) { $r['type'] = strtok($r['type'], ' '); return array_map('strval', $r); }, $rows); };
    $a = $untyped(sheetOf($orig)); $b = $untyped(sheetOf($copy)); $tableDiff = [];
    $optDiff = [];
    foreach ($a as $i => $row) { foreach ($row as $k => $v) { if (($b[$i][$k] ?? '(missing)') !== $v) {
        $d = "{$row['name']}.{$k}: " . var_export($v, true) . ' → ' . var_export($b[$i][$k] ?? '(missing)', true);
        if ($k === 'optional') { $optDiff[] = $d; } else { $tableDiff[] = $d; }
    } } }
    ok(count($a) === count($b) && $tableDiff === [], 'with the same item table (optional, type options, choice list aside)', array_slice($tableDiff, 0, 8));
    // BUG: see "optional 0 is written" above — a required item of a type
    // that defaults to optional (mc_multiple_button here) comes back optional.
    bug($optDiff === [], 'every item keeps its optional/required flag', $optDiff);
    $sheetType = function ($s, $name) { foreach (sheetOf($s) as $r) { if ($r['name'] === $name) { return $r['type']; } } return null; };
    $rendered = function ($s, $name) { $it = $s->getItemsWithChoices(null, ['field' => 'name', 'values' => [$name]])[$name] ?? null; return $it ? $it->render() : ''; };
    bug(sheetOf($copy) === sheetOf($orig), 'items with options and a list come back unchanged',
        ['t_pattern' => [$sheetType($orig, 't_pattern'), $sheetType($copy, 't_pattern')], 'n_range' => [$sheetType($orig, 'n_range'), $sheetType($copy, 'n_range')]]);
    preg_match('/pattern="([^"]*)"/', $rendered($orig, 't_pattern'), $pOrig);
    preg_match('/pattern="([^"]*)"/', $rendered($copy, 't_pattern'), $pCopy);
    ok(($pOrig[1] ?? null) === '[0-9]{5}', 'the original pattern item renders pattern [0-9]{5}', $pOrig);
    bug(($pCopy[1] ?? null) === '[0-9]{5}', 're-uploaded, the pattern item still renders pattern [0-9]{5}', $pCopy[1] ?? null);
    $poles = function ($s) use ($rendered) { $h = $rendered($s, 'n_range'); return [strpos($h, 'left pole') !== false, strpos($h, 'right pole') !== false]; };
    ok($poles($orig) === [true, true], 'the original range renders its pole labels', $poles($orig));
    ok($poles($copy) === [true, true], 're-uploaded, the range still renders its pole labels (the item recovers its list from the options)', $poles($copy));
    ok(choicesOf($copy->getChoicesForSheet()) === $origChoices, 'and the same choices');
    $copySettings = $copy->getSettings();
    $origSettings = $orig->getSettings();
    ok($copySettings['allow_previous'] == $origSettings['allow_previous'] && $copySettings['offline_mode'] == $origSettings['offline_mode']
        && $copySettings['layout'] === $origSettings['layout'], 'and the same engine settings', [$copySettings, $origSettings]);

    echo "\n-- the JSON download, imported as a new survey --\n";
    $edge = SurveyStudy::createFromData(json_decode(json_encode(['name' => 'expsmokee_' . $suffix, 'settings' => ['rendering_mode' => 'v2'], 'items' => [
        ['type' => 'mc', 'name' => 'zero_codes', 'label' => '007', 'choice_list' => 'zero_codes', 'choices' => ['01' => 'first', '02' => '2.50'], 'optional' => 1],
        ['type' => 'hidden', 'name' => 'fixed', 'label' => 'Fixed', 'value' => '1.50', 'optional' => 1],
        ['type' => 'text', 'name' => 'plain', 'label' => '10', 'optional' => 0],
    ]])), ['user_id' => (int) $owner['id'], 'place_unit' => false]);
    $studies[] = $edge;
    ok($edge && $edge->valid, 'a small study with text that looks numeric');
    foreach (['all_widgets' => $orig, 'numeric-looking text' => $edge] as $what => $src) {
        $jsonPath = $tmpDir . '/' . $src->name . '.json';
        $files[] = $jsonPath;
        $obj = json_decode(download('exportItemTableJSON', $src, $jsonPath));
        ok(is_object($obj) && !empty($obj->items), "{$what}: the JSON download is a survey object");
        if (!is_object($obj)) { continue; }
        ok(($obj->settings->rendering_mode ?? null) === 'v2', "{$what}: it names the v2 engine");
        $obj->name = substr('expsmokej_' . $suffix . '_' . $src->id, 0, 60);
        $imp = SurveyStudy::createFromData($obj, ['user_id' => (int) $owner['id'], 'place_unit' => false]);
        $studies[] = $imp;
        ok($imp && $imp->valid && $imp->rendering_mode === 'v2', "{$what}: imported on v2", $imp ? $imp->rendering_mode : null);
        if (!$imp) { continue; }
        // A JSON export names each item's choice list after the item.
        $lists = $src->getChoices();
        $expect = array_map(function ($r) use ($lists) { if (isset($lists[$r['choice_list']])) { $r['choice_list'] = $r['name']; } return $r; }, itemsOf($src));
        $got = itemsOf($imp);
        $diff = [];
        foreach ($expect as $name => $shape) {
            foreach ($shape as $k => $v) {
                $g = $got[$name][$k] ?? '(missing)';
                if ($g !== $v) { $diff[] = "{$name}.{$k}: " . var_export($v, true) . ' → ' . var_export($g, true); }
            }
        }
        $choices = function ($s) { $o = []; foreach ($s->getItems() as $it) { foreach ((array) ($s->getChoices([$it['choice_list']])[$it['choice_list']] ?? []) as $n => $l) { $o[] = $it['name'] . '|' . $n . '|' . $l; } } sort($o); return $o; };
        $choiceDiff = array_values(array_diff($choices($src), $choices($imp)));
        // BUG: exportItemTableJSON encodes with JSON_NUMERIC_CHECK, which turns
        // every numeric-looking string into a number ("007" → 7, "2.50" → 2.5).
        $claim = $what === 'all_widgets' ? 'ok' : 'bug';
        ok(array_keys($got) === array_keys($expect), "{$what}: the same items in the same order", array_keys($got));
        $claim($diff === [], "{$what}: every column of every item comes back as it was", array_slice($diff, 0, 6));
        $claim($choiceDiff === [], "{$what}: the same choices per item", array_slice($choiceDiff, 0, 5));
    }
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
}
teardown();
$names = array_filter(array_map(function ($s) { return $s ? $s->name : null; }, $studies));
$left = $names ? (int) $db->execute('SELECT COUNT(*) AS n FROM survey_studies WHERE name IN ("' . implode('","', $names) . '")', [], false, true)['n'] : 0;
ok($left === 0 && !is_dir($tmpDir), 'teardown removed every study and file', $left);
echo "\n" . ($failures ? "\e[31m{$failures} failure(s)\e[0m" : "\e[32mall passed\e[0m") . ($knownBugs ? " \e[33m({$knownBugs} known-bug claim(s) still failing)\e[0m" : '') . "\n";
exit($failures ? 1 : 0);
