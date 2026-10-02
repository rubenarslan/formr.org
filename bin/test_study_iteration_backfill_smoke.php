#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for bin/backfill_study_iteration.php and the `valid`
 * column it once filtered on.
 *
 *   - a survey created from a file (SurveyStudy::createFromFile, the plain
 *     spreadsheet upload) stores valid = 1 — it stayed NULL, the flag being
 *     set only after the row was saved;
 *   - the backfill fills a study's historical sessions (study_iteration
 *     NULL) from its wide table's iteration, whatever its `valid` column
 *     says: a dry run counts them, the real run fills them and raises
 *     last_iteration.
 *
 * Run: docker exec formr_app php /var/www/formr/bin/test_study_iteration_backfill_smoke.php
 * Creates and removes its own run, studies and sessions.
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
$owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
if (!$owner) { fwrite(STDERR, "No survey_users row to own the test run.\n"); exit(2); }
$GLOBALS['user'] = new User((int) $owner['id']);

$suffix = bin2hex(random_bytes(3));
$fromFile = null; $study = null; $codes = [];
$runId = $db->insert('survey_runs', ['user_id' => (int) $owner['id'], 'name' => 'backfillsmoke_' . $suffix,
    'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0]);
$run = new Run(null, $runId);
$backfill = function (string $args) {
    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/backfill_study_iteration.php') . ' ' . $args . ' 2>&1');
};
try {
    echo "== study iteration backfill smoke ==\n";
    $fromFile = new SurveyStudy();
    ok($fromFile->createFromFile(['name' => "backfillfile_{$suffix}.xlsx"]), 'a survey created from a file');
    ok((string) $db->execute('SELECT valid FROM survey_studies WHERE id = :id', ['id' => $fromFile->id], true) === '1', 'stores valid = 1');

    $run->importUnits(json_encode(['units' => [
        ['type' => 'Survey', 'position' => 10, 'description' => 's', 'survey_data' => ['name' => "backfillsmoke_{$suffix}", 'items' => [
            ['name' => 'q1', 'type' => 'text', 'label' => 'Q1', 'optional' => 1, 'order' => 1, 'item_order' => 1],
        ]]],
        ['type' => 'Endpage', 'position' => 20, 'description' => 'end', 'body' => 'Done.'],
    ]]));
    $survey = RunUnitFactory::make($run, ['id' => (int) $run->getAllUnitIds()[0]['unit_id']]);
    $study = $survey->surveyStudy;
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $code = 'BACKFILL' . bin2hex(random_bytes(8));
        $codes[] = $code;
        $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => $code, 'created' => mysql_now(), 'position' => 10]);
        $rs = new RunSession($code, $run);
        $rs->position = 10;
        $rs->createUnitSession($survey);
        $rs->currentUnitSession->createSurveyStudyRecord();
        $ids[] = (int) $rs->currentUnitSession->id;
    }
    $marks = implode(',', $ids);
    $wide = function () use ($db, $study, $marks) {
        $out = [];
        foreach ((array) $db->execute("SELECT session_id, iteration FROM `{$study->results_table}` WHERE session_id IN ({$marks})") as $r) {
            $out[(int) $r['session_id']] = (int) $r['iteration'];
        }
        ksort($out);
        return $out;
    };
    $sessions = function () use ($db, $marks) {
        $out = [];
        foreach ((array) $db->execute("SELECT id, study_iteration FROM survey_unit_sessions WHERE id IN ({$marks})") as $r) {
            $out[(int) $r['id']] = $r['study_iteration'] === null ? null : (int) $r['study_iteration'];
        }
        ksort($out);
        return $out;
    };
    $before = $wide();
    ok(count($before) === 3 && count(array_unique($before)) === 3, 'three sessions, each with its wide iteration', $before);

    // Their history: no study_iteration yet, and a study whose `valid` column is NULL.
    $db->exec("UPDATE survey_unit_sessions SET study_iteration = NULL WHERE id IN ({$marks})");
    $db->exec('UPDATE survey_studies SET valid = NULL, last_iteration = 0 WHERE id = :id', ['id' => $study->id]);

    $dry = $backfill("--study={$study->id} --dry-run");
    ok(strpos($dry, 'rows_to_backfill=3') !== false, 'the dry run counts the three sessions', trim($dry));
    ok(array_filter($sessions(), function ($v) { return $v !== null; }) === [], 'and changes nothing');
    $out = $backfill("--study={$study->id}");
    ok(strpos($out, 'rows_backfilled=3') !== false, 'the backfill fills them', trim($out));
    ok($sessions() === $before, 'each with its wide iteration', [$sessions(), $before]);
    ok((int) $db->execute('SELECT last_iteration FROM survey_studies WHERE id = :id', ['id' => $study->id], true) === max($before), 'last_iteration is raised to the highest');
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    $failures++;
}
try {
    foreach ($codes as $c) { $db->exec('DELETE FROM survey_run_sessions WHERE session = :c', ['c' => $c]); }
    foreach ([$study, $fromFile] as $s) { if ($s && $s->id) { (new SurveyStudy($s->id))->delete(); } }
    $run->delete();
} catch (Throwable $e) {
    echo "  teardown: " . $e->getMessage() . "\n";
}
echo "\n" . ($failures ? "\e[31m{$failures} failure(s)\e[0m" : "\e[32mall passed\e[0m") . "\n";
exit($failures ? 1 : 0);
