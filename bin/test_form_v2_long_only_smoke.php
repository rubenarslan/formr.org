#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for a v2 study written to survey_items_display alone:
 * form_v2_dual_write_results off, and no wide results table (one that was
 * never created or has been dropped). A page submit and an autosave still
 * store their answers, and the state reply says so; nothing may report
 * success while storing nothing.
 *
 * Run: docker exec formr_app php /var/www/formr/bin/test_form_v2_long_only_smoke.php
 * Creates and removes its own run, study and sessions; switches the setting
 * for itself only.
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

$configProp = new ReflectionProperty('Config', 'config');
$configProp->setAccessible(true);
$savedConfig = (array) $configProp->getValue(null);

$suffix = bin2hex(random_bytes(3));
$runId = $db->insert('survey_runs', ['user_id' => (int) $owner['id'], 'name' => 'longonlysmoke_' . $suffix,
    'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0]);
$run = new Run(null, $runId);
$item = function ($order, $name, $type) {
    return ['name' => $name, 'type' => $type, 'label' => ucfirst($name), 'order' => $order, 'item_order' => $order, 'optional' => 1];
};
$study = null; $code = null;
try {
    echo "== form_v2 long-only smoke ==\n";
    $run->importUnits(json_encode(['units' => [
        ['type' => 'Form', 'position' => 10, 'description' => 'form', 'survey_data' => ['name' => 'longonlysmoke_' . $suffix, 'items' => [
            $item(1, 'q1', 'text'), $item(2, 'q2', 'text'), $item(3, 's1', 'submit'), $item(4, 'q3', 'text'),
        ]]],
        ['type' => 'Endpage', 'position' => 20, 'description' => 'end', 'body' => 'Done.'],
    ]]));
    $form = RunUnitFactory::make($run, ['id' => (int) $run->getAllUnitIds()[0]['unit_id']]);
    $study = $form instanceof Form ? $form->v2Study() : null;
    ok($study && $study->valid, 'a Form on a v2 study');

    Config::initialize(array_merge($savedConfig, ['form_v2_dual_write_results' => false]));
    $db->query('DROP TABLE IF EXISTS `' . $study->results_table . '`');
    ok(!$db->table_exists($study->results_table), 'dual-write off, and no wide results table');

    $code = 'LONGONLY' . bin2hex(random_bytes(8));
    $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => $code, 'created' => mysql_now(), 'position' => 10]);
    $rs = new RunSession($code, $run);
    $rs->position = 10;
    $rs->createUnitSession($form);
    $us = $rs->currentUnitSession;
    $us->createSurveyStudyRecord();
    $stored = function ($name) use ($db, $us) {
        return $db->execute('SELECT d.answer FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id
            WHERE d.session_id = :s AND i.name = :n', ['s' => $us->id, 'n' => $name], true);
    };

    $r = (new FormSubmissionService($us, $study))->apply(['page' => 1, 'unit_session_id' => 0,
        'data' => ['q1' => 'one'], 'item_views' => [], 'hidden' => [], 'final' => false]);
    ok($r['status'] === 'ok', 'a page submit is accepted', $r);
    ok($stored('q1') === 'one', 'and its answer is stored', $stored('q1'));
    ok(!empty($r['items']['q1']['answered']) && $r['items']['q1']['value'] === 'one', 'the reply says so', $r['items']['q1'] ?? null);

    $items = $study->getItemsWithChoices(null, ['field' => 'name', 'values' => ['q2']]);
    ok(count($items) === 1, 'the study hands out its items without a wide table', array_keys((array) $items));
    ok($us->updateSurveyStudyRecord(['q2' => 'two'], true, true, $items) && $stored('q2') === 'two', 'an autosave-style write stores too', $stored('q2'));
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    $failures++;
}
Config::initialize($savedConfig);
try {
    if ($code) { $db->exec('DELETE FROM survey_run_sessions WHERE session = :c', ['c' => $code]); }
    if ($study && $study->valid) { $study->delete(); }
    $run->delete();
} catch (Throwable $e) {
    echo "  teardown: " . $e->getMessage() . "\n";
}
echo "\n" . ($failures ? "\e[31m{$failures} failure(s)\e[0m" : "\e[32mall passed\e[0m") . "\n";
exit($failures ? 1 : 0);
