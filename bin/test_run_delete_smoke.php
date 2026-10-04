#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for Run::delete: a deleted run takes its units with it
 * (Run::deleteUnits).
 *
 *   - its placements' units, its special units (the service message
 *     Run::create adds, an overview script) and their unit sessions go;
 *   - a survey stays: its unit row is its study's, which outlives the run;
 *     a Survey unit never linked to one goes;
 *   - a unit another run still places stays, until that run is deleted;
 *   - an older run whose survey_runs row names one of its units (here the
 *     overview script, a foreign key that would block the delete) is
 *     deleted all the same.
 *
 * Run: docker exec formr_app php /var/www/formr/bin/test_run_delete_smoke.php
 * Creates and removes its own runs and study.
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
if (!$owner) { fwrite(STDERR, "No survey_users row to own the test runs.\n"); exit(2); }
$GLOBALS['user'] = new User((int) $owner['id']);

$exists = function (string $table, int $id) use ($db) {
    return (bool) $db->execute("SELECT 1 FROM `{$table}` WHERE id = :id", ['id' => $id], true);
};
$suffix = bin2hex(random_bytes(3));
$runA = new Run();
$runB = new Run();
$study = null;
try {
    echo "== run delete smoke ==\n";
    $runA->create(['run_name' => "rundelsmoke-a-{$suffix}", 'user_id' => (int) $owner['id']]);
    $runB->create(['run_name' => "rundelsmoke-b-{$suffix}", 'user_id' => (int) $owner['id']]);
    ok($runA->id && $runB->id, 'two runs created');
    $item = function ($order, $name, $type) {
        return ['name' => $name, 'type' => $type, 'label' => ucfirst($name), 'order' => $order, 'item_order' => $order, 'optional' => 1];
    };
    ok($runA->importUnits(json_encode(['units' => [
        ['type' => 'Survey', 'position' => 10, 'description' => 's', 'survey_data' => ['name' => "rundelsmoke_{$suffix}", 'items' => [$item(1, 'q1', 'text')]]],
        ['type' => 'Pause', 'position' => 20, 'description' => 'p', 'wait_minutes' => 5],
        ['type' => 'External', 'position' => 30, 'description' => 'x', 'address' => 'https://example.org/'],
        ['type' => 'Endpage', 'position' => 40, 'description' => 'end', 'body' => 'Done.'],
    ]])) !== false, 'units imported into run A');
    $placed = [];
    foreach ($runA->getAllUnitIds() as $u) { $placed[(int) $u['position']] = (int) $u['unit_id']; }
    $study = RunUnitFactory::make($runA, ['id' => $placed[10]])->surveyStudy;
    $service = (int) $db->execute("SELECT id FROM survey_run_special_units WHERE run_id = :r AND type = 'ServiceMessagePage'", ['r' => $runA->id], true);
    ok($service > 0, 'run A has its service message');
    // A Survey unit added but never linked to a survey: its unit row has no study.
    $unlinked = (int) RunUnitFactory::make($runA, ['type' => 'Survey'])->create(['type' => 'Survey', 'position' => 50, 'description' => 'never linked'])->id;
    ok($unlinked > 0 && !$exists('survey_studies', $unlinked), 'run A has an unlinked survey unit');
    $overview = RunUnitFactory::make($runA, RunUnit::getDefaults('OverviewScriptPage'))->create();
    $overviewId = (int) $overview->id;
    // An older run's own column naming a unit (ON DELETE NO ACTION).
    $db->exec('UPDATE survey_runs SET overview_script = :u WHERE id = :r', ['u' => $overviewId, 'r' => $runA->id]);
    // Run B places run A's External too.
    $db->insert('survey_run_units', ['run_id' => $runB->id, 'unit_id' => $placed[30], 'position' => 10, 'description' => 'shared']);

    $code = 'RUNDELSMOKE' . bin2hex(random_bytes(8));
    $db->insert('survey_run_sessions', ['run_id' => $runA->id, 'session' => $code, 'created' => mysql_now(), 'position' => 20]);
    $rs = new RunSession($code, $runA);
    $rs->position = 20;
    $rs->createUnitSession(RunUnitFactory::make($runA, ['id' => $placed[20]]));
    $pauseSession = (int) $rs->currentUnitSession->id;

    echo "\n-- delete run A --\n";
    ok($runA->delete() === true, 'run A is deleted');
    ok(!$exists('survey_runs', (int) $runA->id), 'its row is gone');
    ok(!$exists('survey_units', $placed[20]) && !$exists('survey_pauses', $placed[20]), 'its Pause unit is gone, with its own table row');
    ok(!$exists('survey_units', $placed[40]) && !$exists('survey_pages', $placed[40]), 'its end page is gone');
    ok(!$exists('survey_units', $service), 'its service message is gone');
    ok(!$exists('survey_units', $overviewId), 'its overview script is gone, though the run row named it');
    ok(!$exists('survey_unit_sessions', $pauseSession), 'its unit sessions are gone');
    ok($exists('survey_units', $placed[10]) && $exists('survey_studies', $placed[10]), 'its survey stays, unit row and study');
    ok(!$exists('survey_units', $unlinked), 'its unlinked survey unit is gone: it has no study to stay with');
    ok($exists('survey_units', $placed[30]) && $exists('survey_externals', $placed[30]), 'the External run B places stays');

    echo "\n-- delete run B --\n";
    $serviceB = (int) $db->execute("SELECT id FROM survey_run_special_units WHERE run_id = :r", ['r' => $runB->id], true);
    ok($runB->delete() === true, 'run B is deleted');
    ok(!$exists('survey_units', $placed[30]), 'now the shared External is gone');
    ok(!$exists('survey_units', $serviceB), "and run B's service message");
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    $failures++;
}
foreach ([$runA, $runB] as $r) {
    try { if ($r->id && $exists('survey_runs', (int) $r->id)) { $r->delete(); } } catch (Throwable $e) {}
}
try { if ($study && $study->valid) { $study->delete(); } } catch (Throwable $e) { echo "  teardown: " . $e->getMessage() . "\n"; }
echo "\n" . ($failures ? "\e[31m{$failures} failure(s)\e[0m" : "\e[32mall passed\e[0m") . "\n";
exit($failures ? 1 : 0);
