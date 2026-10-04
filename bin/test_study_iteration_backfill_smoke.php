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
 *     last_iteration;
 *   - a wide iteration another session of the study holds is not copied:
 *     that session is numbered above everything, in its wide row too;
 *   - a study whose backfill fails is rolled back and makes the run exit 1
 *     (the deploy scripts warn on it);
 *   - a table without the iteration column (before 2024-07) has its
 *     sessions numbered in the order they began, above every number a
 *     session of the study holds, results row or not.
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
$backfill = function (string $args, &$exit = null) {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/backfill_study_iteration.php') . ' ' . $args . ' 2>&1', $out, $exit);
    return implode("\n", $out);
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

    $typo = $backfill("--study={$study->id} --dryrun", $exit);
    ok($exit !== 0 && strpos($typo, 'Unknown argument') !== false, 'a mistyped --dry-run is refused, not run for real', [$exit, trim($typo)]);
    ok(array_filter($sessions(), function ($v) { return $v !== null; }) === [], 'and changes nothing');
    $dry = $backfill("--study {$study->id} --dry-run");
    ok(strpos($dry, 'rows_to_backfill=3') !== false, 'the dry run (--study with a space) counts the three sessions', trim($dry));
    ok(array_filter($sessions(), function ($v) { return $v !== null; }) === [], 'and changes nothing');
    $out = $backfill("--study={$study->id}");
    ok(strpos($out, 'rows_backfilled=3') !== false, 'the backfill fills them', trim($out));
    ok($sessions() === $before, 'each with its wide iteration', [$sessions(), $before]);
    ok((int) $db->execute('SELECT last_iteration FROM survey_studies WHERE id = :id', ['id' => $study->id], true) === max($before), 'last_iteration is raised to the highest');

    echo "\n-- a wide number another session holds is not copied --\n";
    // x holds 3 with no wide row; y has no number, and its wide row carries 3.
    [$x, $y] = [$ids[2], $ids[1]];
    $db->exec("DELETE FROM `{$study->results_table}` WHERE session_id = :s", ['s' => $x]);
    $db->exec('UPDATE survey_unit_sessions SET study_iteration = NULL WHERE id = :id', ['id' => $y]);
    $db->exec("UPDATE `{$study->results_table}` SET iteration = 3 WHERE session_id = :s", ['s' => $y]);
    $out = $backfill("--study={$study->id}");
    $yIteration = (int) $db->execute('SELECT study_iteration FROM survey_unit_sessions WHERE id = :id', ['id' => $y], true);
    $yWide = (int) $db->execute("SELECT iteration FROM `{$study->results_table}` WHERE session_id = :s", ['s' => $y], true);
    ok(strpos($out, '1 numbered anew') !== false, 'the backfill says so', trim($out));
    ok($yIteration > 3 && $yWide === $yIteration, 'y is numbered above the rest, in its wide row too', [$yIteration, $yWide]);
    ok((int) $db->execute('SELECT study_iteration FROM survey_unit_sessions WHERE id = :id', ['id' => $x], true) === 3, 'x keeps 3');
    ok((int) $db->execute('SELECT COUNT(*) FROM (SELECT 1 FROM survey_unit_sessions WHERE unit_id = :s AND study_iteration IS NOT NULL
        GROUP BY study_iteration HAVING COUNT(*) > 1) d', ['s' => $study->id], true) === 0, 'no number is held twice');

    echo "\n-- a study the backfill cannot fill fails the run --\n";
    // The same clash again, but renumbering y overflows its wide column.
    $db->exec('UPDATE survey_unit_sessions SET study_iteration = NULL WHERE id = :id', ['id' => $y]);
    $db->exec("UPDATE `{$study->results_table}` SET iteration = 3 WHERE session_id = :s", ['s' => $y]);
    $db->query("ALTER TABLE `{$study->results_table}` MODIFY `iteration` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT");
    $db->exec('UPDATE survey_studies SET last_iteration = 300 WHERE id = :id', ['id' => $study->id]);
    $out = $backfill("--study={$study->id}", $exit);
    ok($exit === 1 && strpos($out, 'failed=1') !== false, 'the run exits 1 and counts the study as failed', [$exit, trim($out)]);
    ok($db->execute('SELECT study_iteration FROM survey_unit_sessions WHERE id = :id', ['id' => $y], true) === null, 'and the study is rolled back');
    $db->query("ALTER TABLE `{$study->results_table}` MODIFY `iteration` INT UNSIGNED NOT NULL AUTO_INCREMENT");

    echo "\n-- a table from before 2024-07 (no iteration column) --\n";
    // Its sessions have no number yet; one session started with dual-write
    // off holds 1 and has no results row, and the counter is behind it.
    $db->query("ALTER TABLE `{$study->results_table}` DROP INDEX IF EXISTS `iteration`, DROP COLUMN IF EXISTS `iteration`");
    $db->exec("UPDATE survey_unit_sessions SET study_iteration = NULL WHERE id IN ({$marks})");
    $code = 'BACKFILL' . bin2hex(random_bytes(8));
    $codes[] = $code;
    $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => $code, 'created' => mysql_now(), 'position' => 10]);
    $rs = new RunSession($code, $run);
    $rs->position = 10;
    $rs->createUnitSession($survey);
    $live = (int) $rs->currentUnitSession->id;
    $db->exec('UPDATE survey_unit_sessions SET study_iteration = 1 WHERE id = :id', ['id' => $live]);
    $db->exec('UPDATE survey_studies SET last_iteration = 0 WHERE id = :id', ['id' => $study->id]);
    // (x lost its results row above: no history, so no number until its
    // next page load.)
    $out = $backfill("--study={$study->id}", $exit);
    ok($exit === 0 && strpos($out, 'rows_backfilled=2') !== false, 'the two sessions with a results row are numbered', [$exit, trim($out)]);
    $numbers = $sessions();
    ok([$numbers[$ids[0]], $numbers[$ids[1]], $numbers[$x]] === [2, 3, null],
        'above the 1 a session without a results row holds, in the order they began', $numbers);
    ok((int) $db->execute('SELECT study_iteration FROM survey_unit_sessions WHERE id = :id', ['id' => $live], true) === 1, 'which keeps it');
    ok((int) $db->execute('SELECT last_iteration FROM survey_studies WHERE id = :id', ['id' => $study->id], true) === 3, 'and the counter is raised to 3');
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
