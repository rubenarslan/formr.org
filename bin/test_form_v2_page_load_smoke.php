#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for loading a v2 Form's page through the run engine
 * (RunSession::execute → Run::exec → RunController): the result carries the
 * v2 page state (`form_v2`), which is what makes the controller render
 * run/form_index with the form bundle. Without it the page goes through
 * run/index, which loads no form bundle, and every page section stays hidden.
 *
 * A survey with an access window (expire_after, expire_invitation_after) is
 * queued on every page load until its deadline, a separate branch of
 * RunSession::executeUnitSession; each of those loads is checked too.
 *
 * Run: docker exec formr_app php /var/www/formr/bin/test_form_v2_page_load_smoke.php
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
$owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
if (!$owner) { fwrite(STDERR, "No survey_users row to own the test run.\n"); exit(2); }
$GLOBALS['user'] = new User((int) $owner['id']);

$suffix = bin2hex(random_bytes(3));
$runId = $db->insert('survey_runs', ['user_id' => (int) $owner['id'], 'name' => 'pageloadsmoke_' . $suffix,
    'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0]);
$run = new Run(null, $runId);
$item = function ($order, $name, $type) {
    return ['name' => $name, 'type' => $type, 'label' => ucfirst($name), 'order' => $order, 'item_order' => $order, 'optional' => 1];
};
$study = null; $codes = [];
try {
    echo "== form_v2 page load smoke ==\n";
    ok($run->importUnits(json_encode(['units' => [
        ['type' => 'Form', 'position' => 10, 'description' => 'form', 'survey_data' => ['name' => 'pageloadsmoke_' . $suffix, 'items' => [
            $item(1, 'q1', 'text'), $item(2, 's1', 'submit'), $item(3, 'q2', 'text'),
        ]]],
        ['type' => 'Endpage', 'position' => 20, 'description' => 'end', 'body' => 'Done.'],
    ]])) !== false, 'run imported');
    $form = RunUnitFactory::make($run, ['id' => (int) $run->getAllUnitIds()[0]['unit_id']]);
    $study = $form instanceof Form ? $form->v2Study() : null;
    ok($study && $study->valid, 'a Form on a v2 study');

    // expire_invitation_after sets a deadline from the first load on;
    // expire_after only once the participant has submitted a page (Survey::
    // getUnitSessionExpirationData), so that case submits page 1 first.
    $windows = [
        'no access window' => [['expire_after' => null, 'expire_invitation_after' => null], false],
        'expire_invitation_after' => [['expire_after' => null, 'expire_invitation_after' => 60], false],
        'expire_after, after the first page' => [['expire_after' => 60, 'expire_invitation_after' => null], true],
    ];
    $load = function (string $code, string $label) use ($runId) {
        $out = (new RunSession($code, new Run(null, $runId)))->execute();
        ok(is_array($out) && !empty($out['form_v2']), "{$label}: the result carries the v2 page state", is_array($out) ? array_keys($out) : $out);
        ok(is_array($out) && strpos((string) ($out['body'] ?? ''), 'class="fmr-form-v2 ') !== false, "{$label}: and the form itself");
    };
    foreach ($windows as $label => [$settings, $submitFirst]) {
        echo "\n-- {$label} --\n";
        $db->update('survey_studies', $settings, ['id' => $study->id]);
        $code = 'PAGELOAD' . bin2hex(random_bytes(8));
        $codes[] = $code;
        $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => $code, 'created' => mysql_now(), 'position' => 10]);
        $load($code, 'first load');
        if ($submitFirst) {
            // Invited a while ago, page 1 submitted now: the participant has started.
            $db->exec('UPDATE survey_unit_sessions us JOIN survey_run_sessions rs ON rs.id = us.run_session_id
                SET us.created = NOW() - INTERVAL 5 MINUTE WHERE rs.session = :c', ['c' => $code]);
            $us = (new RunSession($code, new Run(null, $runId)))->getCurrentUnitSession();
            $r = (new FormSubmissionService($us, $study))->apply(['page' => 1, 'unit_session_id' => 0, 'data' => ['q1' => 'x'], 'item_views' => [], 'hidden' => [], 'final' => false]);
            ok($r['status'] === 'ok', 'page 1 submitted', $r);
        }
        $load($code, 'reload');
        if ($label !== 'no access window') {
            $queued = (int) $db->execute('SELECT us.queued FROM survey_unit_sessions us JOIN survey_run_sessions rs ON rs.id = us.run_session_id WHERE rs.session = :c', ['c' => $code], true);
            ok($queued > 0, 'the unit session is queued for its deadline (the branch under test)', $queued);
        }
    }
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    $failures++;
}
try {
    foreach ($codes as $c) { $db->exec('DELETE FROM survey_run_sessions WHERE session = :c', ['c' => $c]); }
    if ($study && $study->valid) { $study->delete(); }
    $run->delete();
} catch (Throwable $e) {
    echo "  teardown: " . $e->getMessage() . "\n";
}
echo "\n" . ($failures ? "\e[31m{$failures} failure(s)\e[0m" : "\e[32mall passed\e[0m") . "\n";
exit($failures ? 1 : 0);
