#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for bin/import-results.php: exported results go back
 * into a run as the sessions a participant's would be.
 *
 *   - a CSV of four rows: two participants new to the run (one with two
 *     sessions), and one live in the run; ended, expired and unfinished rows;
 *   - every session gets its placement, run iteration, study iteration, the
 *     row's answers and times, in the display rows (what R, the results
 *     page and exports read) and the results row;
 *   - added participants are ended at the survey; the live participant's
 *     run session and current session are untouched;
 *   - imported again, every row is skipped; a dry run writes nothing; an
 *     unknown column, a time that is not one or a value its column refuses
 *     stops the import with nothing written; an XLSX with date cells works.
 *
 * Run: docker exec formr_app php /var/www/formr/bin/test_import_results_smoke.php
 * Creates and removes its own run, study, participants and files.
 */
require_once dirname(__FILE__) . '/../setup.php';
if (php_sapi_name() !== 'cli') { die("CLI only\n"); }

$db = DB::getInstance();
$owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
if (!$owner) { fwrite(STDERR, "No survey_users row to own the test run.\n"); exit(2); }
$GLOBALS['user'] = new User((int) $owner['id']);

$failures = 0;
function ok($cond, string $label, $detail = null): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}" . ($detail !== null ? ' — ' . var_export($detail, true) : '') . "\n";
    if (!$cond) { $failures++; }
}
// The script as an admin runs it: its exit code and what it said.
function import(array $args): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APPLICATION_ROOT . 'bin/import-results.php');
    foreach ($args as $arg) { $cmd .= ' ' . escapeshellarg($arg); }
    exec($cmd . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
}
function csv(string $path, array $rows): string {
    $fh = fopen($path, 'w');
    foreach ($rows as $row) { fputcsv($fh, $row); }
    fclose($fh);
    return $path;
}

$suffix = bin2hex(random_bytes(3));
$name = 'importsmoke_' . $suffix;
$runId = $db->insert('survey_runs', [
    'user_id' => (int) $owner['id'], 'name' => $name,
    'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0,
]);
$run = new Run(null, $runId);
$item = function ($order, $name, $type, array $extra = []) {
    return $extra + ['name' => $name, 'type' => $type, 'label' => ucfirst($name), 'order' => $order, 'item_order' => $order];
};
$bundle = ['units' => [[
    'type' => 'Survey', 'position' => 10, 'description' => 'import smoke',
    'survey_data' => ['name' => $name, 'items' => [
        $item(1, 't1', 'text', ['optional' => 1]),
        $item(2, 'n1', 'number', ['optional' => 1]),
        $item(3, 's1', 'submit', ['label' => '']),
    ]],
], [
    'type' => 'Endpage', 'position' => 20, 'description' => 'end', 'body' => 'Done.',
]]];
$files = [];
$study = null; $unitId = null;
$sessionsOf = function () use ($db, &$study) {
    return (int) $db->execute('SELECT COUNT(*) FROM survey_unit_sessions WHERE unit_id = :s', ['s' => $study->id], true);
};
$runSessionOf = function ($code) use ($db, $runId) {
    return $db->findRow('survey_run_sessions', ['run_id' => $runId, 'session' => $code]);
};
$unitSessionsOf = function ($code) use ($db, $runId) {
    return $db->execute('SELECT us.* FROM survey_unit_sessions us JOIN survey_run_sessions rs ON rs.id = us.run_session_id
        WHERE rs.run_id = :r AND rs.session = :c ORDER BY us.created', ['r' => $runId, 'c' => $code]);
};
$display = function ($usid, $itemName) use ($db, &$study) {
    return $db->execute('SELECT d.answer, d.saved, d.created FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id
        WHERE d.session_id = :s AND i.study_id = :st AND i.name = :n', ['s' => $usid, 'st' => $study->id, 'n' => $itemName], false, true);
};
$wide = function ($usid) use ($db, &$study) {
    return $db->execute("SELECT * FROM `{$study->results_table}` WHERE session_id = :s", ['s' => $usid], false, true);
};

try {
    echo "== import-results smoke ==\n";
    ok($run->importUnits(json_encode($bundle)) !== false, 'run bundle imported');
    $unitId = (int) $run->getAllUnitIds()[0]['unit_id'];
    $study = SurveyStudy::loadById($unitId);
    ok($study->valid && $study->name === $name, 'the survey is placed at 10');
    $placement = (int) $db->execute('SELECT id FROM survey_run_units WHERE run_id = :r AND position = 10', ['r' => $runId], true);

    // A participant live in the run, on the survey.
    $live = crypto_token(48);
    $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => $live, 'created' => mysql_now(), 'position' => 10]);
    $liveRs = new RunSession($live, $run);
    $liveRs->position = 10;
    $liveRs->createUnitSession(RunUnitFactory::make($run, ['id' => $unitId]));
    $liveUs = $liveRs->currentUnitSession;
    $liveUs->createSurveyStudyRecord();

    $new1 = crypto_token(48);
    $new2 = crypto_token(48);
    $files[] = $file = csv(APPLICATION_ROOT . "tmp/{$name}.csv", [
        ['session', 'session_id', 'study_id', 'iteration', 'created', 'modified', 'ended', 'expired', 't1', 'n1'],
        [$new1, '901', '77', '1', '2026-01-05 09:00:00', '2026-01-05 09:10:00', '2026-01-05 09:12:00', '', 'hello', '42'],
        [$new2, '902', '77', '2', '2026-01-06 09:00:00', '2026-01-06 09:05:00', '', '2026-01-07 09:00:00', '', '7'],
        [$live, '903', '77', '3', '2026-01-08 09:00:00', '', '', '', 'partial', ''],
        [$new1, '904', '77', '4', '2026-02-01 09:00:00', '2026-02-01 09:03:00', '2026-02-01 09:04:00', '', 'again', '1'],
    ]);

    echo "\n-- dry run --\n";
    [$rc, $out] = import(['--run=' . $name, '--survey=' . $name, '--file=' . $file, '--dry-run']);
    ok($rc === 0 && strpos($out, 'Would import 4 row(s)') !== false && strpos($out, '2 participant(s) are not in the run') !== false, 'a dry run says what it would do', $out);
    ok($sessionsOf() === 1, 'and writes nothing');

    echo "\n-- import --\n";
    [$rc, $out] = import(['--run-id=' . $runId, '--survey-id=' . $study->id, '--backup-file=' . $file]);
    ok($rc === 0 && strpos($out, 'Imported 4 session(s)') !== false && strpos($out, '2 participant(s) added') !== false, 'imported (the old option names)', $out);
    $rs1 = $runSessionOf($new1);
    ok($rs1 && $rs1['created'] === '2026-01-05 09:00:00' && $rs1['ended'] === '2026-02-01 09:04:00' && (int) $rs1['position'] === 10,
        'a new participant is added, ended at the survey, spanning their sessions', $rs1);
    $us1 = $unitSessionsOf($new1);
    ok(count($us1) === 2, 'with their two sessions', count($us1));
    $first = $us1[0];
    ok((int) $first['run_unit_id'] === $placement && (int) $first['iteration'] === 1 && (int) $us1[1]['iteration'] === 2,
        'at the placement, numbered in the run', [$first['run_unit_id'], $first['iteration'], $us1[1]['iteration']]);
    ok($first['study_iteration'] && $us1[1]['study_iteration'] && $first['study_iteration'] !== $us1[1]['study_iteration'],
        'each with its own study iteration', [$first['study_iteration'], $us1[1]['study_iteration']]);
    ok($first['created'] === '2026-01-05 09:00:00' && $first['ended'] === '2026-01-05 09:12:00' && $first['state'] === 'ENDED'
        && $first['result'] === 'survey_ended' && $first['result_log'] === "imported from {$name}.csv", 'ended, as the row says', $first);
    $t1 = $display($first['id'], 't1');
    $n1 = $display($first['id'], 'n1');
    ok($t1['answer'] === 'hello' && $n1['answer'] === '42' && $t1['saved'] === '2026-01-05 09:10:00' && $t1['created'] === '2026-01-05 09:00:00',
        'its answers in the display rows, stored at `modified`', [$t1, $n1]);
    $long = $db->execute('SELECT ' . UnitSession::longColumnSql('created') . ' AS created, ' . UnitSession::longColumnSql('modified') . ' AS modified
        FROM survey_items_display sid JOIN survey_unit_sessions us ON us.id = sid.session_id WHERE us.id = :id GROUP BY us.id', ['id' => $first['id']], false, true);
    ok($long['created'] === '2026-01-05 09:00:00' && $long['modified'] === '2026-01-05 09:10:00', 'the long read has its times', $long);
    $w = $wide($first['id']);
    ok($w['t1'] === 'hello' && (int) $w['n1'] === 42 && $w['created'] === '2026-01-05 09:00:00' && $w['modified'] === '2026-01-05 09:10:00'
        && $w['ended'] === '2026-01-05 09:12:00' && (int) $w['iteration'] === (int) $first['study_iteration'], 'and in the results row', $w);
    $us2 = $unitSessionsOf($new2)[0];
    ok($us2['expired'] === '2026-01-07 09:00:00' && $us2['ended'] === null && $us2['state'] === 'EXPIRED' && $us2['result'] === 'expired',
        'an expired row expired', $us2);
    $t1 = $display($us2['id'], 't1');
    ok($t1['answer'] === null && $t1['saved'] === null && $display($us2['id'], 'n1')['answer'] === '7', 'an empty cell is no answer', $t1);

    $liveAfter = $runSessionOf($live);
    ok((int) $liveAfter['position'] === 10 && $liveAfter['ended'] === null, "the live participant's run session is untouched", $liveAfter);
    $added = array_values(array_filter($unitSessionsOf($live), function ($u) use ($liveUs) { return (int) $u['id'] !== (int) $liveUs->id; }));
    ok(count($added) === 1 && $added[0]['ended'] === null && $added[0]['expired'] === null
        && (int) $added[0]['queued'] === UnitSessionQueue::QUEUED_SUPERCEDED && $added[0]['state'] === 'SUPERSEDED',
        'an unfinished row is a session nobody resumes', $added);
    $current = (new RunSession($live, $run))->getCurrentUnitSession();
    ok($current && (int) $current->id === (int) $liveUs->id, 'the live session is still their current one', $current ? $current->id : null);
    $counter = (int) $db->findValue('survey_studies', ['id' => $study->id], 'last_iteration');
    $max = (int) $db->execute('SELECT MAX(study_iteration) FROM survey_unit_sessions WHERE unit_id = :s', ['s' => $study->id], true);
    ok($counter >= $max, "the study's counter is past every imported iteration", [$counter, $max]);

    echo "\n-- again --\n";
    $before = $sessionsOf();
    [$rc, $out] = import(['--run=' . $name, '--survey=' . $name, '--file=' . $file]);
    ok($rc === 0 && strpos($out, 'Imported 0 session(s)') !== false && strpos($out, '4 row(s) skipped') !== false, 'imported again, every row is skipped', $out);
    ok($sessionsOf() === $before, 'nothing is added');

    echo "\n-- refused --\n";
    $files[] = $bad = csv(APPLICATION_ROOT . "tmp/{$name}_col.csv", [['session', 'created', 't1', 'nope'], [crypto_token(48), '2026-03-01 10:00:00', 'x', 'y']]);
    [$rc, $out] = import(['--run=' . $name, '--survey=' . $name, '--file=' . $bad]);
    ok($rc === 1 && strpos($out, 'not items of the survey: nope') !== false, 'a column that is not an item', $out);
    $files[] = $bad = csv(APPLICATION_ROOT . "tmp/{$name}_time.csv", [['session', 'created', 't1'], [crypto_token(48), '01/03/2026', 'x']]);
    [$rc, $out] = import(['--run=' . $name, '--survey=' . $name, '--file=' . $bad]);
    ok($rc === 1 && strpos($out, "created '01/03/2026' is not a time") !== false, 'a time that is not one', $out);
    $files[] = $bad = csv(APPLICATION_ROOT . "tmp/{$name}_value.csv", [['session', 'created', 't1', 'n1'],
        [crypto_token(48), '2026-03-02 10:00:00', 'fine', '5'], [crypto_token(48), '2026-03-03 10:00:00', 'refused', 'not a number']]);
    [$rc, $out] = import(['--run=' . $name, '--survey=' . $name, '--file=' . $bad]);
    ok($rc === 1 && strpos($out, 'row 3:') !== false && strpos($out, 'nothing was imported') !== false, 'a value its column refuses', $out);
    ok($sessionsOf() === $before, 'and the row before it is not kept either', $sessionsOf());
    [$rc, $out] = import(['--run=' . $name, '--survey=' . $name, '--file=' . $file, '--position=20']);
    ok($rc === 1 && strpos($out, 'is not placed in run') !== false, 'a position the survey is not at', $out);
    [$rc, $out] = import(['--run=' . $name, '--survey=' . $name]);
    ok($rc === 1 && strpos($out, '--file is required') !== false, 'a missing option', $out);
    [$rc, $out] = import(['--run=' . $name, '--survey=' . $name, '--file=' . $file, '--skip-itemsdisplay']);
    ok($rc === 1 && strpos($out, "unknown option '--skip-itemsdisplay'") !== false, 'an unknown option', $out);

    echo "\n-- xlsx --\n";
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $book->getActiveSheet();
    $xcode = crypto_token(48);
    $sheet->fromArray([['session', 'created', 'ended', 't1', 'n1'], [$xcode, null, null, 'from a spreadsheet', 3.0]]);
    // As a spreadsheet program saves a time it recognised: a date cell.
    $sheet->setCellValue('B2', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTime('2026-04-01 08:30:00')));
    $sheet->setCellValue('C2', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTime('2026-04-01 08:45:00')));
    $files[] = $xlsx = APPLICATION_ROOT . "tmp/{$name}.xlsx";
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($xlsx);
    [$rc, $out] = import(['--run=' . $name, '--survey=' . $name, '--file=' . $xlsx]);
    ok($rc === 0 && strpos($out, 'Imported 1 session(s)') !== false, 'an XLSX is imported', $out);
    $ux = $unitSessionsOf($xcode)[0] ?? null;
    $n1 = $ux ? $display($ux['id'], 'n1') : null;
    ok($ux && $ux['created'] === '2026-04-01 08:30:00' && $ux['ended'] === '2026-04-01 08:45:00' && $n1['answer'] === '3',
        'its date cells are times, its numbers whole', $ux ? [$ux['created'], $ux['ended'], $n1] : null);
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
}
try {
    // The run's participants take their sessions and answers with them.
    $db->exec('DELETE FROM survey_run_sessions WHERE run_id = :r', ['r' => $runId]);
    if ($study && $study->valid) { $study->delete(); }
    if ($run && $run->id) { $run->delete(); }
    if ($unitId) { $db->exec('DELETE FROM survey_units WHERE id = :id', ['id' => $unitId]); }
    foreach ($files as $f) { @unlink($f); }
} catch (Throwable $e) {
    echo "  teardown: " . $e->getMessage() . "\n";
}
echo "\n" . ($failures ? "\e[31m{$failures} failure(s)\e[0m" : "\e[32mall passed\e[0m") . "\n";
exit($failures ? 1 : 0);
