#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for the per-study iteration (survey_unit_sessions.
 * study_iteration, survey_studies.last_iteration, the wide table's
 * `iteration`): one number per session of a study, never handed out twice.
 *
 *   - a counter behind a number a session already holds (a session without
 *     a wide row, which the wide table's UNIQUE key does not see) still
 *     allocates above it;
 *   - a second session cannot take a number another holds (patch 118's
 *     key), and a session from before patch 108 whose wide row's number is
 *     taken gets a fresh one instead of an error;
 *   - a re-upload that gives a table from before 2024-07 its iteration
 *     column keeps every number: sessions numbered since patch 108 carry
 *     theirs into the wide table, older ones are numbered after them;
 *   - and where that table's `iteration` column held an item's answers
 *     (the name was allowed then), the re-upload that deletes the item
 *     replaces the column instead of numbering the answers;
 *   - of two first loads of one session, the one that creates its results
 *     row records the start, also when the other won the number;
 *   - "Delete results" numbers the next session 1 again;
 *   - seeding a counter at 0 skips the numbers of rows deleted from the top
 *     of the results table, as its AUTO_INCREMENT did;
 *   - with form_v2_dual_write_results off the results table is still read:
 *     a session from before patch 108 keeps its number, a new one is
 *     numbered above them all.
 *
 * Run: docker exec formr_app php /var/www/formr/bin/test_study_iteration_smoke.php
 * Creates and removes its own run, study and sessions.
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
/**
 * A first load of a unit session that a concurrent first load overtakes:
 * between this request's allocation and its stamp, the other one stamps
 * its own number ($between), as a double click or a restored tab does.
 */
class OvertakenUnitSession extends UnitSession {
    public $between;
    public function allocateStudyIteration($studyId) {
        $n = parent::allocateStudyIteration($studyId);
        if ($this->between) {
            $between = $this->between;
            $this->between = null;
            $between();
        }
        return $n;
    }
}

function setConfig(string $key, $value): void {
    $p = new ReflectionProperty(Config::class, 'config');
    $p->setAccessible(true);
    $all = $p->getValue();
    $all[$key] = $value;
    Config::initialize($all);
}

$owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
if (!$owner) { fwrite(STDERR, "No survey_users row to own the test run.\n"); exit(2); }
$GLOBALS['user'] = new User((int) $owner['id']);

$suffix = bin2hex(random_bytes(3));
$runId = $db->insert('survey_runs', ['user_id' => (int) $owner['id'], 'name' => 'itersmoke_' . $suffix,
    'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0]);
$run = new Run(null, $runId);
$study = null; $codes = []; $oldStudy = null; $run2 = null; $itemStudy = null; $run3 = null;
$codeOf = [];
$start = function () use ($db, $runId, &$codes, &$survey, &$codeOf) {
    $code = 'ITERSMOKE' . bin2hex(random_bytes(8));
    $codes[] = $code;
    $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => $code, 'created' => mysql_now(), 'position' => 10]);
    $rs = new RunSession($code, new Run(null, $runId));
    $rs->position = 10;
    $rs->createUnitSession($survey);
    $rs->currentUnitSession->createSurveyStudyRecord();
    $codeOf[(int) $rs->currentUnitSession->id] = $code;
    return (int) $rs->currentUnitSession->id;
};
$iteration = function (int $usId) use ($db) {
    return (int) $db->execute('SELECT study_iteration FROM survey_unit_sessions WHERE id = :id', ['id' => $usId], true);
};
try {
    echo "== study iteration smoke ==\n";
    $run->importUnits(json_encode(['units' => [
        ['type' => 'Survey', 'position' => 10, 'description' => 's', 'survey_data' => ['name' => "itersmoke_{$suffix}", 'items' => [
            ['name' => 'q1', 'type' => 'text', 'label' => 'Q1', 'optional' => 1, 'order' => 1, 'item_order' => 1],
        ]]],
        ['type' => 'Endpage', 'position' => 20, 'description' => 'end', 'body' => 'Done.'],
    ]]));
    $survey = RunUnitFactory::make($run, ['id' => (int) $run->getAllUnitIds()[0]['unit_id']]);
    $study = $survey->surveyStudy;
    $duplicates = function () use ($db, $study) {
        return (int) $db->execute('SELECT COUNT(*) FROM (SELECT study_iteration FROM survey_unit_sessions
            WHERE unit_id = :s AND study_iteration IS NOT NULL GROUP BY study_iteration HAVING COUNT(*) > 1) d', ['s' => $study->id], true);
    };

    echo "\n-- a counter behind a number a session holds --\n";
    $a = $start(); $b = $start(); $c = $start();
    ok([$iteration($a), $iteration($b), $iteration($c)] === [1, 2, 3], 'sessions numbered 1, 2, 3', [$iteration($a), $iteration($b), $iteration($c)]);
    // c keeps its number but has no wide row, and the counter fell behind it.
    $db->exec("DELETE FROM `{$study->results_table}` WHERE session_id = :s", ['s' => $c]);
    $db->exec('UPDATE survey_studies SET last_iteration = 2 WHERE id = :id', ['id' => $study->id]);
    $d = $start();
    ok($iteration($d) === 4, 'the next session gets 4, not the 3 a session holds', $iteration($d));
    ok($duplicates() === 0, 'no number is held twice');
    ok((int) $db->execute('SELECT last_iteration FROM survey_studies WHERE id = :id', ['id' => $study->id], true) === 4, 'the counter caught up');

    echo "\n-- one number per session --\n";
    $refused = false;
    try {
        $db->exec('UPDATE survey_unit_sessions SET study_iteration = :n WHERE id = :id', ['n' => $iteration($a), 'id' => $b]);
    } catch (PDOException $e) {
        $refused = (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
    ok($refused, 'a second session cannot take a number another holds');
    // A session from before patch 108: no number of its own yet, and the
    // number its wide row carries (3) is held by c.
    $e = $start();
    $db->exec('UPDATE survey_unit_sessions SET study_iteration = NULL WHERE id = :id', ['id' => $e]);
    $db->exec("UPDATE `{$study->results_table}` SET iteration = 3 WHERE session_id = :s", ['s' => $e]);
    $us = (new RunSession($codeOf[$e], new Run(null, $runId)))->getCurrentUnitSession();
    $us->createSurveyStudyRecord();
    ok($iteration($e) > 4 && $iteration($e) !== $iteration($c), 'a session whose wide number is taken gets a fresh one', $iteration($e));
    ok($duplicates() === 0, 'still no number held twice');

    echo "\n-- a re-upload adds an old table's iteration column without renumbering --\n";
    $runId2 = $db->insert('survey_runs', ['user_id' => (int) $owner['id'], 'name' => 'itersmokeold_' . $suffix,
        'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0]);
    $run2 = new Run(null, $runId2);
    $run2->importUnits(json_encode(['units' => [
        ['type' => 'Survey', 'position' => 10, 'description' => 's', 'survey_data' => ['name' => "itersmokeold_{$suffix}", 'items' => [
            ['name' => 'q1', 'type' => 'text', 'label' => 'Q1', 'optional' => 1, 'order' => 1, 'item_order' => 1],
        ]]],
        ['type' => 'Endpage', 'position' => 20, 'description' => 'end', 'body' => 'Done.'],
    ]]));
    $old = RunUnitFactory::make($run2, ['id' => (int) $run2->getAllUnitIds()[0]['unit_id']]);
    $oldStudy = $old->surveyStudy;
    $t = $oldStudy->results_table;
    // A table from before 2024-07: no iteration column.
    $db->query("ALTER TABLE `{$t}` DROP INDEX IF EXISTS `iteration`, DROP COLUMN IF EXISTS `iteration`");
    SurveyStudy::forgetWideTableColumns($t);
    $startOld = function () use ($db, $runId2, &$codes, $old) {
        $code = 'ITERSMOKE' . bin2hex(random_bytes(8));
        $codes[] = $code;
        $db->insert('survey_run_sessions', ['run_id' => $runId2, 'session' => $code, 'created' => mysql_now(), 'position' => 10]);
        $rs = new RunSession($code, new Run(null, $runId2));
        $rs->position = 10;
        $rs->createUnitSession($old);
        $rs->currentUnitSession->createSurveyStudyRecord();
        return (int) $rs->currentUnitSession->id;
    };
    $wideOf = function (int $usId) use ($db, $t) {
        return (int) $db->execute("SELECT iteration FROM `{$t}` WHERE session_id = :s", ['s' => $usId], true);
    };
    // Two sessions from before patch 108 (no number), the counter as patch
    // 108 found it, then two sessions numbered since.
    $h1 = $startOld(); $h2 = $startOld();
    $db->exec("UPDATE survey_unit_sessions SET study_iteration = NULL WHERE id IN ({$h1}, {$h2})");
    $db->exec('UPDATE survey_studies SET last_iteration = 0 WHERE id = :id', ['id' => $oldStudy->id]);
    $p1 = $startOld(); $p2 = $startOld();
    ok([$iteration($p1), $iteration($p2)] === [1, 2], 'the sessions since patch 108 are numbered 1 and 2', [$iteration($p1), $iteration($p2)]);
    $alter = new ReflectionMethod(SurveyStudy::class, 'alterResultsTable');
    $alter->setAccessible(true);
    $alter->invoke(new SurveyStudy($oldStudy->id), [], []);
    ok([$wideOf($p1), $wideOf($p2)] === [1, 2], 'their wide rows carry those numbers', [$wideOf($p1), $wideOf($p2)]);
    ok([$wideOf($h1), $wideOf($h2)] === [3, 4] && [$iteration($h1), $iteration($h2)] === [3, 4],
        'the older sessions are numbered after them, in both places', [$wideOf($h1), $wideOf($h2), $iteration($h1), $iteration($h2)]);
    $col = $db->execute("SHOW COLUMNS FROM `{$t}` LIKE 'iteration'", [], false, true);
    ok($col && stripos((string) $col['Extra'], 'auto_increment') !== false && $col['Null'] === 'NO', 'the column is NOT NULL AUTO_INCREMENT', $col);
    $n = $startOld();
    ok($iteration($n) === 5 && $wideOf($n) === 5, 'the next session gets 5, in both places', [$iteration($n), $wideOf($n)]);

    echo "\n-- an old table whose survey had an item named iteration --\n";
    // Before 2024-07 `iteration` was an allowed item name: its answers sit
    // in an `iteration` column of their own. A re-upload deletes the item
    // (the name is refused now); the column must go, and the real one come.
    $runId3 = $db->insert('survey_runs', ['user_id' => (int) $owner['id'], 'name' => 'itersmokeitem_' . $suffix,
        'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0]);
    $run3 = new Run(null, $runId3);
    $run3->importUnits(json_encode(['units' => [
        ['type' => 'Survey', 'position' => 10, 'description' => 's', 'survey_data' => ['name' => "itersmokeitem_{$suffix}", 'items' => [
            ['name' => 'q1', 'type' => 'text', 'label' => 'Q1', 'optional' => 1, 'order' => 1, 'item_order' => 1],
        ]]],
        ['type' => 'Endpage', 'position' => 20, 'description' => 'end', 'body' => 'Done.'],
    ]]));
    $itemUnit = RunUnitFactory::make($run3, ['id' => (int) $run3->getAllUnitIds()[0]['unit_id']]);
    $itemStudy = $itemUnit->surveyStudy;
    $t3 = $itemStudy->results_table;
    $db->query("ALTER TABLE `{$t3}` DROP INDEX IF EXISTS `iteration`, DROP COLUMN IF EXISTS `iteration`");
    $db->query("ALTER TABLE `{$t3}` ADD COLUMN `iteration` VARCHAR(255) NULL DEFAULT NULL");
    SurveyStudy::forgetWideTableColumns($t3);
    $answered = [];
    foreach (['yes', 'no'] as $answer) {
        $code = 'ITERSMOKE' . bin2hex(random_bytes(8));
        $codes[] = $code;
        $db->insert('survey_run_sessions', ['run_id' => $runId3, 'session' => $code, 'created' => mysql_now(), 'position' => 10]);
        $rs = new RunSession($code, new Run(null, $runId3));
        $rs->position = 10;
        $rs->createUnitSession($itemUnit);
        $rs->currentUnitSession->createSurveyStudyRecord();
        $usId = (int) $rs->currentUnitSession->id;
        $db->exec("UPDATE `{$t3}` SET `iteration` = :a WHERE session_id = :s", ['a' => $answer, 's' => $usId]);
        $answered[] = $usId;
    }
    $healed = true;
    try {
        $alter->invoke(new SurveyStudy($itemStudy->id), [], ['iteration' => 'iteration VARCHAR(255) NULL DEFAULT NULL']);
    } catch (Throwable $e) {
        $healed = $e->getMessage();
    }
    ok($healed === true, 'the re-upload goes through', $healed);
    SurveyStudy::forgetWideTableColumns($t3);
    $col = $db->execute("SHOW COLUMNS FROM `{$t3}` LIKE 'iteration'", [], false, true);
    ok($col && stripos((string) $col['Extra'], 'auto_increment') !== false, 'the table has the real iteration column', $col);
    $pairs = (array) $db->execute("SELECT us.study_iteration AS si, w.iteration AS wi FROM `{$t3}` w
        JOIN survey_unit_sessions us ON us.id = w.session_id WHERE w.session_id IN (" . implode(',', $answered) . ")");
    ok(count($pairs) === 2 && !array_filter($pairs, function ($p) { return (string) $p['si'] !== (string) $p['wi']; }),
        "each session's wide iteration is its study_iteration, not the item's answer", $pairs);

    echo "\n-- two first loads of one session record its start once --\n";
    // The other load wins the number but this one creates the results row:
    // the start must still be recorded, by the one that creates the row.
    $code = 'ITERSMOKE' . bin2hex(random_bytes(8));
    $codes[] = $code;
    $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => $code, 'created' => mysql_now(), 'position' => 10]);
    $rs = new RunSession($code, new Run(null, $runId));
    $rs->position = 10;
    $rs->createUnitSession($survey);
    $raced = (int) $rs->currentUnitSession->id;
    $late = new OvertakenUnitSession($rs, $survey, ['id' => $raced, 'load' => true]);
    $late->between = function () use ($db, $rs, $survey, $raced, $study) {
        $other = new UnitSession($rs, $survey, ['id' => $raced, 'load' => true]);
        $db->exec('UPDATE survey_unit_sessions SET study_iteration = :n WHERE id = :id AND study_iteration IS NULL',
            ['n' => $other->allocateStudyIteration($study->id), 'id' => $raced]);
    };
    $late->createSurveyStudyRecord();
    $row = $db->execute('SELECT result, study_iteration FROM survey_unit_sessions WHERE id = :id', ['id' => $raced], false, true);
    ok($row['result'] === 'survey_started', 'the start is recorded', $row);
    ok((int) $db->execute("SELECT iteration FROM `{$study->results_table}` WHERE session_id = :s", ['s' => $raced], true) === (int) $row['study_iteration'],
        'the results row carries the number the other load won');

    echo "\n-- deleting the results starts the numbering again --\n";
    $cleared = new SurveyStudy($study->id);
    ok($cleared->deleteResults() === true, 'the results are deleted', $cleared->errors);
    ok((int) $db->execute('SELECT last_iteration FROM survey_studies WHERE id = :id', ['id' => $study->id], true) === 0, 'the counter is back at 0');
    $first = $start();
    ok($iteration($first) === 1, 'the next session is number 1, as the truncated table numbered it', $iteration($first));

    echo "\n-- a number deleted before patch 108 is not handed out again --\n";
    // The counter as patch 108 found it (0), after the participant holding
    // the highest number was deleted: the table's AUTO_INCREMENT went past it.
    $second = $start(); $third = $start();
    $db->exec('DELETE FROM survey_unit_sessions WHERE id = :id', ['id' => $third]);
    $db->exec('UPDATE survey_studies SET last_iteration = 0 WHERE id = :id', ['id' => $study->id]);
    $next = $start();
    ok([$iteration($second), $iteration($next)] === [2, 4], 'the next session gets 4, not the deleted 3', [$iteration($second), $iteration($next)]);

    echo "\n-- with form_v2_dual_write_results off, the results table's history counts --\n";
    // Before patch 108: no session has a number yet and the counter is at 0,
    // while the results table numbered them all.
    $wide = function (int $usId) use ($db, $study) {
        return (int) $db->execute("SELECT iteration FROM `{$study->results_table}` WHERE session_id = :s", ['s' => $usId], true);
    };
    $historic = $start();
    $historicNumber = $wide($historic);
    $db->exec('UPDATE survey_unit_sessions SET study_iteration = NULL WHERE unit_id = :s', ['s' => $study->id]);
    $db->exec('UPDATE survey_studies SET last_iteration = 0 WHERE id = :id', ['id' => $study->id]);
    $highest = (int) $db->execute("SELECT MAX(iteration) FROM `{$study->results_table}`", [], true);
    setConfig('form_v2_dual_write_results', false);
    try {
        $fresh = $start();
        ok($iteration($fresh) > $highest, 'a new session is numbered above the results table, not 1', [$iteration($fresh), $highest]);
        $back = (new RunSession($codeOf[$historic], new Run(null, $runId)))->getCurrentUnitSession();
        $back->createSurveyStudyRecord();
        ok($iteration($historic) === $historicNumber, 'a session from before patch 108 keeps its number', [$iteration($historic), $historicNumber]);
    } finally {
        setConfig('form_v2_dual_write_results', true);
    }
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    $failures++;
}
try {
    foreach ($codes as $c) { $db->exec('DELETE FROM survey_run_sessions WHERE session = :c', ['c' => $c]); }
    foreach ([$study, $oldStudy, $itemStudy] as $s) { if ($s && $s->valid) { $s->delete(); } }
    $run->delete();
    if ($run2) { $run2->delete(); }
    if ($run3) { $run3->delete(); }
} catch (Throwable $e) {
    echo "  teardown: " . $e->getMessage() . "\n";
}
echo "\n" . ($failures ? "\e[31m{$failures} failure(s)\e[0m" : "\e[32mall passed\e[0m") . "\n";
exit($failures ? 1 : 0);
