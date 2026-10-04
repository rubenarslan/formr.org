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
 * RunSession::executeUnitSession; each of those loads is checked too. The
 * form carries a dynamic label longer than 64 KiB, which its R-call
 * allowlist must store whole (patch 119) or no page renders.
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
/** A first render that loses a deadlock once, as concurrent first renders can. */
class DeadlockedOnceUnitSession extends UnitSession {
    public $deadlocks = 1;
    public function createSurveyStudyRecord() {
        if ($this->deadlocks-- > 0) {
            throw new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
        }
        return parent::createSurveyStudyRecord();
    }
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
            // A dynamic label longer than 64 KiB (an R Markdown page with
            // inline CSS, say): its source is recorded whole as an R call.
            ['name' => 'big', 'type' => 'note', 'label' => str_repeat('Lorem ipsum dolor sit amet. ', 2600) . '`r 1 + 1`',
                'order' => 4, 'item_order' => 4, 'optional' => 1],
            // A submit button labelled in R: the author's words, written in
            // by the browser when the page resolves.
            ['name' => 's2', 'type' => 'submit', 'label' => 'Weiter zu Teil `r 1 + 1`', 'order' => 5, 'item_order' => 5],
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
    $bigLabel = function () use ($db, &$study) {
        return (int) $db->execute("SELECT MAX(LENGTH(expr)) FROM survey_r_calls WHERE study_id = :s AND slot = 'label'", ['s' => $study->id], true);
    };
    foreach ($windows as $label => [$settings, $submitFirst]) {
        echo "\n-- {$label} --\n";
        $db->update('survey_studies', $settings, ['id' => $study->id]);
        $code = 'PAGELOAD' . bin2hex(random_bytes(8));
        $codes[] = $code;
        $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => $code, 'created' => mysql_now(), 'position' => 10]);
        $load($code, 'first load');
        ok($bigLabel() > 65535, 'the dynamic label over 64 KiB is recorded whole', $bigLabel());
        $submitButton = function () use ($code, $runId) {
            $out = (new RunSession($code, new Run(null, $runId)))->execute();
            return preg_match('~<div[^>]*data-fmr-label-id="\d+"[^>]*>(?:(?!</div>).)*?<button[^>]*name="s2"[^>]*>~s', (string) ($out['body'] ?? ''), $m) ? $m[0] : null;
        };
        $button = $submitButton();
        ok($button !== null && strpos($button, 'data-fmr-nav-label') === false,
            'a submit labelled in R awaits its label, not the navigation wording', $button);
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

    echo "\n-- a page stored after its unit session expired is refused --\n";
    // The daemon expires the unit session between the gate's read and the
    // write (it holds the run-session lock, which the v2 endpoints don't).
    $lateCode = 'PAGELOAD' . bin2hex(random_bytes(8));
    $codes[] = $lateCode;
    $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => $lateCode, 'created' => mysql_now(), 'position' => 10]);
    (new RunSession($lateCode, new Run(null, $runId)))->execute();
    $late = (new RunSession($lateCode, new Run(null, $runId)))->getCurrentUnitSession();
    $db->exec('UPDATE survey_unit_sessions SET expired = NOW() WHERE id = :id', ['id' => (int) $late->id]);
    $refused = null;
    try {
        (new FormSubmissionService($late, $study))->apply(['page' => 1, 'unit_session_id' => 0, 'data' => ['q1' => 'too late'], 'item_views' => [], 'hidden' => [], 'final' => false]);
    } catch (RuntimeException $e) {
        $refused = $e->getMessage();
    }
    ok($refused !== null && strpos($refused, 'ended or expired') !== false, 'the write is refused', $refused);
    ok($db->execute("SELECT d.answer FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id WHERE d.session_id = :s AND i.name = 'q1'",
        ['s' => (int) $late->id], true) === null, 'and nothing is stored in it');

    echo "\n-- R sees a Form's sessions as a Survey's --\n";
    // Its unit row's type is 'Form' while the study is on v2, for every
    // session of the study; R code filtering type == "Survey" must not
    // change meaning when a study is switched.
    $typeCode = 'PAGELOAD' . bin2hex(random_bytes(8));
    $codes[] = $typeCode;
    $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => $typeCode, 'created' => mysql_now(), 'position' => 10]);
    (new RunSession($typeCode, new Run(null, $runId)))->execute();
    $typed = (new RunSession($typeCode, new Run(null, $runId)))->getCurrentUnitSession();
    $data = $typed->getRunData('nrow(survey_unit_sessions[survey_unit_sessions$type == "Survey", ])', $study->name);
    // (A data set goes to R by column.)
    $types = array_values(array_unique((array) ($data['datasets']['survey_unit_sessions']['type'] ?? [])));
    ok($types === ['Survey'], 'survey_unit_sessions$type is "Survey"', $types);

    echo "\n-- a render that loses a deadlock is retried --\n";
    $db->update('survey_studies', ['expire_after' => null, 'expire_invitation_after' => null], ['id' => $study->id]);
    $code = 'PAGELOAD' . bin2hex(random_bytes(8));
    $codes[] = $code;
    $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => $code, 'created' => mysql_now(), 'position' => 10]);
    $rs = new RunSession($code, new Run(null, $runId));
    $rs->position = 10;
    $rs->createUnitSession($form);
    $deadlocked = new DeadlockedOnceUnitSession($rs, $form, ['id' => (int) $rs->currentUnitSession->id, 'load' => true]);
    $out = RunUnitFactory::make(new Run(null, $runId), ['id' => (int) $form->id])->getUnitSessionOutput($deadlocked);
    ok(!empty($out['form_v2']) && strpos((string) ($out['content'] ?? ''), 'fmr-form-v2') !== false, 'the form renders on the second try', array_keys((array) $out));

    echo "\n-- a finished form in a run without a Stop is not shown again --\n";
    $runId2 = $db->insert('survey_runs', ['user_id' => (int) $owner['id'], 'name' => 'pageloadsmoke2_' . $suffix,
        'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0]);
    $run2 = new Run(null, $runId2);
    $run2->importUnits(json_encode(['units' => [
        ['type' => 'Form', 'position' => 10, 'description' => 'form', 'survey_data' => ['name' => 'pageloadsmoke2_' . $suffix, 'items' => [
            $item(1, 'q1', 'text'),
        ]]],
    ]]));
    $lone = RunUnitFactory::make($run2, ['id' => (int) $run2->getAllUnitIds()[0]['unit_id']]);
    $loneStudy = $lone->v2Study();
    $code = 'PAGELOAD' . bin2hex(random_bytes(8));
    $codes[] = $code;
    $db->insert('survey_run_sessions', ['run_id' => $runId2, 'session' => $code, 'created' => mysql_now(), 'position' => 10]);
    $first = (new RunSession($code, new Run(null, $runId2)))->execute();
    ok(strpos((string) ($first['body'] ?? ''), 'fmr-form-v2') !== false, 'the form is shown');
    $us = (new RunSession($code, new Run(null, $runId2)))->getCurrentUnitSession();
    $r = (new FormSubmissionService($us, $loneStudy))->apply(['page' => 1, 'unit_session_id' => 0, 'data' => ['q1' => 'x'], 'item_views' => [], 'hidden' => [], 'final' => true]);
    ok(!empty($r['complete']), 'and finished', $r);
    // A later visit: the run session has ended (no unit after the form), and
    // RunSession::execute re-runs its last unit session for display — the
    // web branch, which the CLI cannot take, so it is called as it does.
    $rs2 = new RunSession($code, new Run(null, $runId2));
    ok((bool) $rs2->ended, 'the run session ended with the form');
    $again = RunUnitFactory::make(new Run(null, $runId2), ['id' => (int) $lone->id])
        ->getUnitSessionOutput(new UnitSession($rs2, null, ['id' => (int) $us->id, 'load' => true]));
    ok(strpos((string) ($again['content'] ?? ''), 'fmr-form-v2') === false, 'a later visit does not show it as if it took answers', array_keys((array) $again));
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    $failures++;
}
try {
    foreach ($codes as $c) { $db->exec('DELETE FROM survey_run_sessions WHERE session = :c', ['c' => $c]); }
    if ($study && $study->valid) { $study->delete(); }
    if (!empty($loneStudy) && $loneStudy->valid) { $loneStudy->delete(); }
    $run->delete();
    if (!empty($run2)) { $run2->delete(); }
} catch (Throwable $e) {
    echo "  teardown: " . $e->getMessage() . "\n";
}
echo "\n" . ($failures ? "\e[31m{$failures} failure(s)\e[0m" : "\e[32mall passed\e[0m") . "\n";
exit($failures ? 1 : 0);
