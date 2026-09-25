#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for form_v2's server side after the navigation refactor
 * (documentation/agent_doc/plan_form_v2_navigation_state.md):
 *
 *   - FormRenderer renders every item on every page, all pages hidden, with a
 *     state block and no per-session inputs;
 *   - FormSubmissionService::apply stores a page and replies with the stored
 *     state; hidden ids are recorded over the whole form (hide → retract,
 *     unhide); a validation error writes nothing;
 *   - the final submit computes the remaining calculate items, refuses to end
 *     while a required item is unanswered (`missing`), and ends the unit
 *     session once nothing is;
 *   - a GET never completes the form (Form::getUnitSessionOutput renders).
 *
 * Run: docker exec formr_app php /var/www/formr/bin/test_form_v2_navigation_smoke.php
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
$runId = $db->insert('survey_runs', [
    'user_id' => (int) $owner['id'], 'name' => 'navsmoke_' . $suffix,
    'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0,
]);
$run = new Run(null, $runId);
$studyName = 'navsmoke_' . $suffix;
$item = function ($order, $name, $type, array $extra = []) {
    return $extra + ['name' => $name, 'type' => $type, 'label' => ucfirst($name), 'order' => $order, 'item_order' => $order];
};
$bundle = ['units' => [[
    'type' => 'Form', 'position' => 10, 'description' => 'nav smoke',
    'survey_data' => ['name' => $studyName, 'items' => [
        // page 1
        $item(1, 'q1', 'mc', ['choice_list' => 'yn', 'choices' => ['1' => 'yes', '2' => 'no']]),
        $item(2, 'q2', 'text', ['optional' => 1, 'showif' => 'q1 == 1']),
        $item(3, 's1', 'submit', ['label' => '']),
        // page 2
        $item(4, 'q3', 'text'),
        $item(5, 's2', 'submit', ['label' => '']),
        // page 3: a calculate and a trailing optional item, no submit row
        $item(6, 'score', 'calculate', ['value' => '1 + 1']),
        $item(7, 'q4', 'text', ['optional' => 1]),
    ]],
]]];
$study = null; $rs = null; $us = null; $unitId = null;
function teardown() {
    global $db, $study, $run, $rs, $unitId;
    try { if ($rs && $rs->id) { $db->exec('DELETE FROM survey_run_sessions WHERE id = :id', ['id' => $rs->id]); } } catch (Throwable $e) {}
    try { if ($study && $study->valid) { $db->query('DELETE FROM survey_r_calls WHERE study_id = ' . (int) $study->id); $study->delete(); } } catch (Throwable $e) {}
    try { if ($run && $run->id) { $run->delete(); } } catch (Throwable $e) {}
    try { if ($unitId) { $db->exec('DELETE FROM survey_units WHERE id = :id', ['id' => $unitId]); } } catch (Throwable $e) {}
}

try {
    echo "== form_v2 navigation/state smoke ==\n";
    ok($run->importUnits(json_encode($bundle)) !== false, 'run bundle imported');
    $units = $run->getAllUnitIds();
    ok(count($units) === 1, 'one run unit', $units);
    $unitId = (int) $units[0]['unit_id'];
    $form = RunUnitFactory::make($run, ['id' => $unitId]);
    ok($form instanceof Form, 'unit is a Form');
    $study = $form->surveyStudy ?: $form->getStudy(true);
    ok($study && $study->valid && $study->rendering_mode === 'v2', 'study created as v2', $study ? $study->rendering_mode : null);
    $ids = [];
    foreach ((array) $db->execute('SELECT id, name FROM survey_items WHERE study_id = :s', ['s' => $study->id]) as $r) { $ids[$r['name']] = (int) $r['id']; }
    ok(count($ids) === 7, 'seven items', $ids);

    $rsId = $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => 'NAVSMOKE' . bin2hex(random_bytes(8)), 'created' => mysql_now(), 'position' => 10]);
    $rs = new RunSession($db->execute('SELECT session FROM survey_run_sessions WHERE id = :id', ['id' => $rsId], false, true)['session'], $run);
    $rs->position = 10;
    $rs->createUnitSession($form);
    $us = $rs->currentUnitSession;
    ok($us && $us->id, 'unit session created');
    $us->createSurveyStudyRecord();
    $pageOf = [];
    foreach ((array) $db->execute('SELECT i.name, d.page FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id WHERE d.session_id = :s', ['s' => $us->id]) as $r) { $pageOf[$r['name']] = (int) $r['page']; }
    ok($pageOf === ['q1' => 1, 'q2' => 1, 's1' => 1, 'q3' => 2, 's2' => 2, 'score' => 3, 'q4' => 3], 'pages split at submit rows', $pageOf);

    echo "\n-- render: every page, every item, one state block --\n";
    $renderer = new FormRenderer($study, $us);
    $renderer->processItems();
    $html = $renderer->render();
    ok(substr_count($html, '<section class="fmr-page"') === 3, 'three page sections', substr_count($html, '<section class="fmr-page"'));
    ok(substr_count($html, 'data-fmr-page="1" hidden') === 1 && substr_count($html, 'data-fmr-page="3" hidden') === 1, 'every section hidden');
    ok(strpos($html, 'name="q4"') !== false && strpos($html, 'name="q1"') !== false, 'items of every page rendered');
    ok(strpos($html, 'name="score"') === false, 'calculate item not rendered');
    ok(strpos($html, 'class="fmr-state"') !== false && strpos($html, 'fmr-saved-answers') === false && strpos($html, 'name="session_id"') === false, 'state block replaces saved-answers + session_id');
    ok(substr_count($html, 'data-fmr-nav-label') === 3, 'a relabelable advance button on each page', substr_count($html, 'data-fmr-nav-label'));
    $state = $renderer->stateBlock();
    ok((int) $state['unit_session_id'] === (int) $us->id, 'state names the unit session');
    ok(array_keys($state['items']) === ['q1', 'q2', 'q3', 'score', 'q4'], 'state lists items in page order, no submit rows', array_keys($state['items']));
    ok($state['items']['q1']['required'] === true && $state['items']['q2']['required'] === false && $state['items']['score']['required'] === false, 'required follows optional + input type');
    ok($state['items']['q3']['page'] === 2 && $state['items']['q4']['page'] === 3, 'state carries pages');
    ok($state['items']['q1']['answered'] === false && $state['items']['q1']['value'] === null, 'nothing answered yet');
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
    teardown();
    exit(1);
}

try {
    $service = new FormSubmissionService($us, $study);
    $submit = function (array $data, array $hidden = [], $final = false, $page = 1) use ($service) {
        return $service->apply(['page' => $page, 'unit_session_id' => 0, 'data' => $data, 'item_views' => [], 'hidden' => $hidden, 'final' => $final]);
    };
    $row = function ($name) use ($db, $us, $ids) {
        return $db->execute('SELECT answer, saved, hidden FROM survey_items_display WHERE session_id = :s AND item_id = :i', ['s' => $us->id, 'i' => $ids[$name]], false, true);
    };

    echo "\n-- page 1: validation error writes nothing --\n";
    $r = $submit(['q1' => '9']);
    ok($r['status'] === 'errors' && isset($r['errors']['q1']), 'out-of-range mc rejected', $r);
    ok($row('q1')['saved'] === null, 'nothing stored');

    echo "\n-- page 1: q1=2 hides q2 --\n";
    $r = $submit(['q1' => '2', 'q2' => ''], [$ids['q2']]);
    ok($r['status'] === 'ok' && !isset($r['complete']), 'stored, not complete', $r);
    ok($r['items']['q1']['answered'] === true && $r['items']['q1']['value'] === '2', 'reply carries the stored state of q1', $r['items']['q1'] ?? null);
    ok($r['items']['q2']['hidden'] === true && $r['items']['q2']['answered'] === false, 'reply says q2 is hidden', $r['items']['q2'] ?? null);
    ok((int) $row('q2')['hidden'] === 1, 'q2 recorded hidden');

    echo "\n-- page 1 again (allow_previous): q1=1 shows q2, answered --\n";
    $r = $submit(['q1' => '1', 'q2' => 'because'], []);
    ok($r['status'] === 'ok' && $r['items']['q2']['value'] === 'because' && $r['items']['q2']['hidden'] === false, 'q2 stored and unhidden', $r['items']['q2'] ?? null);
    ok((int) $row('q2')['hidden'] === 0 && $row('q2')['answer'] === 'because', 'q2 row: hidden 0, answer kept');

    echo "\n-- hide again: the answer is retracted --\n";
    $r = $submit(['q1' => '2'], [$ids['q2']]);
    ok((int) $row('q2')['hidden'] === 1 && $row('q2')['answer'] === null && $row('q2')['saved'] === null, 'q2 cleared in the display table', $row('q2'));
    $wide = $db->execute("SELECT q2 FROM `{$study->results_table}` WHERE session_id = :s", ['s' => $us->id], false, true);
    ok($wide && $wide['q2'] === null, 'q2 cleared in the results table', $wide);
    ok(!isset($r['items']['q2']) || $r['items']['q2']['answered'] === false, 'reply does not claim q2 answered');

    echo "\n-- a hidden id on an item without a showif is ignored --\n";
    $submit(['q1' => '2'], [$ids['q2'], $ids['q3']]);
    ok((int) $row('q3')['hidden'] === 0, 'q3 (no showif) not hidden');

    echo "\n-- state block after a 'reload' --\n";
    $renderer = new FormRenderer($study, $us);
    $renderer->processItems();
    $state = $renderer->stateBlock();
    ok($state['items']['q1']['answered'] === true && $state['items']['q1']['value'] === '2', 'q1 value survives a reload', $state['items']['q1']);
    ok($state['items']['q2']['hidden'] === true && $state['items']['q2']['answered'] === false, 'q2 hidden after reload', $state['items']['q2']);
    ok(substr_count($renderer->render(), '<section class="fmr-page"') === 3, 'still three pages after answering page 1');

    echo "\n-- final submit with q3 missing: stored, not ended, names the gap --\n";
    $missing = $us->unansweredRequiredItems();
    ok(array_keys($missing) === ['q3'], 'q3 is the only unanswered required item', array_keys($missing));
    $r = $submit(['q4' => 'trailing'], [$ids['q2']], true, 3);
    ok($r['status'] === 'ok' && !isset($r['complete']), 'not complete', $r);
    ok(isset($r['missing']['q3']) && count($r['missing']) === 1, 'missing names q3', $r['missing'] ?? null);
    ok($r['items']['q4']['value'] === 'trailing', 'the page itself was stored');
    $ended = $db->execute('SELECT ended FROM survey_unit_sessions WHERE id = :id', ['id' => $us->id], false, true);
    ok($ended['ended'] === null, 'unit session still open');
    $calc = $row('score');
    ok($calc['answer'] === '2' || $calc['answer'] === null, 'calculate ran on the final submit (2) or OpenCPU is down (null)', $calc);
    if (isset($r['computed'])) { ok(($r['computed']['score'] ?? null) === '2', 'computed value handed back', $r['computed']); }

    echo "\n-- a GET does not complete the form --\n";
    $out = $form->getUnitSessionOutput($us);
    ok(empty($out['end_session']) && !empty($out['content']) && !empty($out['use_form_v2']), 'render, not end_session', array_keys($out));

    echo "\n-- final submit with q3 answered: ended --\n";
    $r = $submit(['q3' => 'done'], [$ids['q2']], true, 2);
    ok($r['status'] === 'ok' && !empty($r['complete']) && !isset($r['missing']), 'complete', $r);
    $ended = $db->execute('SELECT ended, state FROM survey_unit_sessions WHERE id = :id', ['id' => $us->id], false, true);
    ok(!empty($ended['ended']), 'unit session ended', $ended);
    $rs2 = new RunSession($rs->session, $run);
    $rs2->position = 10;
    ok(!$rs2->getCurrentUnitSession(), 'no current unit session afterwards');
    ok($us->unansweredRequiredItems() === [], 'nothing required left');
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
}
teardown();
echo "\n" . ($failures ? "\e[31m{$failures} failure(s)\e[0m" : "\e[32mall passed\e[0m") . "\n";
exit($failures ? 1 : 0);
