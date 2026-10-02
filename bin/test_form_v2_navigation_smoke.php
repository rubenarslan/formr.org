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
 *   - the final submit refuses to end while a required item is unanswered
 *     (`missing`) and ends the unit session once nothing is; the server
 *     computes nothing itself (a hidden item's R value is the browser's to
 *     resolve and submit);
 *   - the final submit ends the form under the run-session lock: with the
 *     lock held elsewhere it fails (a 5xx the browser retries) and leaves
 *     the form open; a second, racing end is a no-op (one next unit);
 *   - a study with a `calculate` item cannot become a v2 Form's study;
 *   - a v2 multiple choice stores its value "0", and a column sized before
 *     that is widened when the study moves to v2; an answer the participant
 *     cleared is withdrawn from both tables;
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
        // page 3: a hidden item with an R value and a trailing optional
        // item, no submit row
        $item(6, 'score', 'hidden', ['value' => '1 + 1']),
        $item(7, 'q4', 'text', ['optional' => 1]),
    ]],
], [
    'type' => 'Endpage', 'position' => 20, 'description' => 'end', 'body' => 'Done.',
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
    ok(count($units) === 2, 'a Form and an end page', $units);
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
    ok(strpos($html, 'name="score"') !== false, 'hidden item rendered');
    ok(strpos($html, '1 + 1') === false, 'its R source is not in the page');
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
        return $db->execute('SELECT answer, saved, hidden, answered, answered_relative FROM survey_items_display WHERE session_id = :s AND item_id = :i', ['s' => $us->id, 'i' => $ids[$name]], false, true);
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
    ok($row('q2')['answered'] !== null, 'q2 has the time it was answered');

    echo "\n-- hide again: the answer is retracted --\n";
    $r = $submit(['q1' => '2'], [$ids['q2']]);
    ok((int) $row('q2')['hidden'] === 1 && $row('q2')['answer'] === null && $row('q2')['saved'] === null, 'q2 cleared in the display table', $row('q2'));
    ok($row('q2')['answered'] === null && $row('q2')['answered_relative'] === null, 'and so is the time it was answered, as when an answer is withdrawn', $row('q2'));
    $wide = $db->execute("SELECT q2 FROM `{$study->results_table}` WHERE session_id = :s", ['s' => $us->id], false, true);
    ok($wide && $wide['q2'] === null, 'q2 cleared in the results table', $wide);
    ok(isset($r['items']['q2']) && $r['items']['q2']['answered'] === false, 'the reply echoes q2, retracted', $r['items']['q2'] ?? null);

    echo "\n-- a hidden id on an item without a showif is ignored --\n";
    $submit(['q1' => '2'], [$ids['q2'], $ids['q3']]);
    ok((int) $row('q3')['hidden'] === 0, 'q3 (no showif) not hidden');

    echo "\n-- state block after a 'reload' --\n";
    $renderer = new FormRenderer($study, $us);
    $renderer->processItems();
    $state = $renderer->stateBlock();
    ok($state['items']['q1']['answered'] === true && $state['items']['q1']['value'] === '2', 'q1 value survives a reload', $state['items']['q1']);
    ok($state['items']['q2']['hidden'] === true && $state['items']['q2']['answered'] === false, 'q2 hidden after reload', $state['items']['q2']);
    $reloaded = $renderer->render();
    ok(substr_count($reloaded, '<section class="fmr-page"') === 3, 'still three pages after answering page 1');
    // Recorded hidden is the last submit's showif outcome, not "never show":
    // the item stays in the document so its showif can reveal it again.
    ok(preg_match('/name="q2(\[\])?"/', $reloaded) === 1, 'q2, recorded hidden, is still in the document');

    echo "\n-- final submit with q3 missing: stored, not ended, names the gap --\n";
    $missing = $us->unansweredRequiredItems();
    ok(array_keys($missing) === ['q3'], 'q3 is the only unanswered required item', array_keys($missing));
    $r = $submit(['q4' => 'trailing'], [$ids['q2']], true, 3);
    ok($r['status'] === 'ok' && !isset($r['complete']), 'not complete', $r);
    ok(isset($r['missing']['q3']) && count($r['missing']) === 1, 'missing names q3', $r['missing'] ?? null);
    ok($r['items']['q4']['value'] === 'trailing', 'the page itself was stored');
    $ended = $db->execute('SELECT ended FROM survey_unit_sessions WHERE id = :id', ['id' => $us->id], false, true);
    ok($ended['ended'] === null, 'unit session still open');
    ok($row('score')['answer'] === null, 'the server computed nothing for the hidden item');
    ok(!isset($r['computed']), 'no computed values in the reply');

    echo "\n-- a GET does not complete the form --\n";
    $out = $form->getUnitSessionOutput($us);
    ok(empty($out['end_session']) && !empty($out['content']) && !empty($out['form_v2']), 'render, not end_session', array_keys($out));

    echo "\n-- final submit with q3 answered: ended --\n";
    $r = $submit(['q3' => 'done'], [$ids['q2']], true, 2);
    ok($r['status'] === 'ok' && !empty($r['complete']) && !isset($r['missing']), 'complete', $r);
    $ended = $db->execute('SELECT ended, state FROM survey_unit_sessions WHERE id = :id', ['id' => $us->id], false, true);
    ok(!empty($ended['ended']), 'unit session ended', $ended);
    $rs2 = new RunSession($rs->session, $run);
    $rs2->position = 10;
    ok(!$rs2->getCurrentUnitSession(), 'no current unit session afterwards');
    ok($us->unansweredRequiredItems() === [], 'nothing required left');
    // (That the next unit is also executed is checked over HTTP in
    // v2-write-endpoints.spec.js: from the CLI a Page waits for a visitor.)
    $next = $db->execute('SELECT position FROM survey_run_sessions WHERE id = :id', ['id' => $rsId], false, true);
    ok((int) $next['position'] === 20, 'the run session moved on to the Endpage', $next);
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
}
echo "\n-- the final submit ends the form once, under the run-session lock --\n";
$rs2 = null;
try {
    $rs2Id = $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => 'NAVSMOKE' . bin2hex(random_bytes(8)), 'created' => mysql_now(), 'position' => 10]);
    $rs2 = new RunSession($db->execute('SELECT session FROM survey_run_sessions WHERE id = :id', ['id' => $rs2Id], false, true)['session'], $run);
    $rs2->position = 10;
    $rs2->createUnitSession($form);
    $us2 = $rs2->currentUnitSession;
    $us2->createSurveyStudyRecord();
    $svc2 = new FormSubmissionService($us2, $study);
    $final = ['page' => 3, 'unit_session_id' => 0, 'data' => ['q1' => '2', 'q3' => 'x'], 'item_views' => [], 'hidden' => [$ids['q2']], 'final' => true];
    $unitSessions = function () use ($db, $rs2Id) {
        return (int) $db->execute('SELECT COUNT(*) AS n FROM survey_unit_sessions WHERE run_session_id = :r', ['r' => $rs2Id], false, true)['n'];
    };

    // Another process (a racing request, the daemon) holds the lock.
    $cfg = (array) Config::get('database');
    $dsn = 'mysql:' . http_build_query(array_filter(['host' => $cfg['host'], 'dbname' => $cfg['database'], 'charset' => $cfg['encoding'], 'port' => $cfg['port'] ?? null]), '', ';');
    $other = new PDO($dsn, $cfg['login'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $lock = 'run_session_' . (int) $rs2Id;
    ok((int) $other->query("SELECT GET_LOCK('{$lock}', 0)")->fetchColumn() === 1, 'the lock is held by another connection');
    $threw = false;
    try { $svc2->apply($final); } catch (RuntimeException $e) { $threw = true; }
    ok($threw, 'the final submit fails while the lock is held (the browser gets a 5xx and retries)');
    $open = $db->execute('SELECT ended FROM survey_unit_sessions WHERE id = :id', ['id' => $us2->id], false, true);
    ok($open['ended'] === null, 'the form is still open');
    ok($unitSessions() === 1, 'the run did not move on');
    $other->query("SELECT RELEASE_LOCK('{$lock}')");

    $r = $svc2->apply($final);
    ok(!empty($r['complete']), 'with the lock free, the retry ends the form', $r);
    ok($unitSessions() === 2, 'the run moved on to the end page', $unitSessions());
    ok($rs2->endFormUnitSession($us2) === true && $unitSessions() === 2, 'a second, racing end is a no-op: still one next unit');
    $pos = $db->execute('SELECT position FROM survey_run_sessions WHERE id = :id', ['id' => $rs2Id], false, true);
    ok((int) $pos['position'] === 20, 'position advanced once', $pos);
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
}
try { if ($rs2 && $rs2->id) { $db->exec('DELETE FROM survey_run_sessions WHERE id = :id', ['id' => $rs2->id]); } } catch (Throwable $e) {}

echo "\n-- a multiple choice keeps its 0; a cleared answer is withdrawn --\n";
$zeroStudy = null;
try {
    $zeroName = 'navsmoke_zero_' . $suffix;
    $run->importUnits(json_encode(['units' => [[
        'type' => 'Form', 'position' => 30, 'description' => 'zero smoke',
        'survey_data' => ['name' => $zeroName, 'items' => [
            $item(1, 'mm', 'mc_multiple', ['optional' => 1, 'choice_list' => 'zero', 'choices' => ['0' => 'none', '1' => 'one', '2' => 'two', '3' => 'three']]),
            $item(2, 'tt', 'text', ['optional' => 1]),
        ]],
    ]]]));
    $zeroStudy = SurveyStudy::loadByUserAndName($GLOBALS['user'], $zeroName);
    ok($zeroStudy && $zeroStudy->rendering_mode === 'v2', 'linked to a Form: on v2');
    $colLen = function () use ($db, $zeroStudy) {
        return (int) $db->execute('SELECT CHARACTER_MAXIMUM_LENGTH AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $zeroStudy->results_table, 'c' => 'mm'], false, true)['n'];
    };
    ok($colLen() === 10, 'the column fits "0, 1, 2, 3"', $colLen());
    // A study whose table predates this: sized without the 0.
    $db->query("ALTER TABLE `{$zeroStudy->results_table}` MODIFY `mm` VARCHAR(7) DEFAULT NULL");
    $w = $zeroStudy->widenMultipleChoiceColumns();
    ok($w === ['mm'] && $colLen() === 10, 'moving to v2 widens it', [$w, $colLen()]);
    ok($zeroStudy->widenMultipleChoiceColumns() === [], 'and never twice');

    $zRow = $db->execute('SELECT ru.unit_id, ru.position FROM survey_run_units ru WHERE ru.run_id = :r AND ru.unit_id = :s',
        ['r' => $runId, 's' => $zeroStudy->id], false, true);
    $zUnit = RunUnitFactory::make($run, ['id' => (int) $zRow['unit_id']]);
    $zrsId = $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => 'NAVSMOKE' . bin2hex(random_bytes(8)), 'created' => mysql_now(), 'position' => (int) $zRow['position']]);
    $zrs = new RunSession($db->execute('SELECT session FROM survey_run_sessions WHERE id = :id', ['id' => $zrsId], false, true)['session'], $run);
    $zrs->position = (int) $zRow['position'];
    $zrs->createUnitSession($zUnit);
    $zus = $zrs->currentUnitSession;
    $zus->createSurveyStudyRecord();
    $zsvc = new FormSubmissionService($zus, $zeroStudy);
    $zsvc->apply(['page' => 1, 'unit_session_id' => 0, 'data' => ['mm' => ['', '0', '2'], 'tt' => 'abc'], 'item_views' => [], 'hidden' => [], 'final' => false]);
    $wideRow = function () use ($db, $zeroStudy, $zus) {
        return $db->execute("SELECT mm, tt FROM `{$zeroStudy->results_table}` WHERE session_id = :s", ['s' => $zus->id], false, true);
    };
    ok($wideRow()['mm'] === '0, 2', 'the 0 is stored', $wideRow());

    // The participant empties tt and deselects everything; autosave posts that.
    $items = $zeroStudy->getItemsWithChoices(null, ['field' => 'name', 'values' => ['mm', 'tt']]);
    $gone = $zsvc->withdrawAnswers(FormSubmissionService::withdrawnNames(['mm' => [''], 'tt' => ''], $items));
    sort($gone);
    ok($gone === ['mm', 'tt'], 'both answers withdrawn', $gone);
    ok($wideRow() === ['mm' => null, 'tt' => null], 'gone from the results table', $wideRow());
    $disp = $db->execute('SELECT COUNT(*) AS n FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id WHERE d.session_id = :s AND (d.answer IS NOT NULL OR d.saved IS NOT NULL)', ['s' => $zus->id], false, true);
    ok((int) $disp['n'] === 0, 'and from the display table');
    ok($zsvc->withdrawAnswers(['tt']) === [], 'withdrawing again changes nothing');
    $db->exec('DELETE FROM survey_run_sessions WHERE id = :id', ['id' => $zrsId]);
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
}

echo "\n-- a Form unit's survey with a calculate item: its items are refused, as on a v2 upload --\n";
$calcStudy = null;
try {
    $calcName = 'navsmoke_calc_' . $suffix;
    $calcBundle = ['units' => [[
        'type' => 'Form', 'position' => 20, 'description' => 'calc smoke',
        'survey_data' => ['name' => $calcName, 'items' => [
            $item(1, 'c1', 'text'),
            $item(2, 'c_score', 'calculate', ['value' => 'nchar(c1)']),
        ]],
    ]]];
    $run->importUnits(json_encode($calcBundle));
    $calcStudy = SurveyStudy::loadByUserAndName($GLOBALS['user'], $calcName);
    ok($calcStudy && $calcStudy->valid, 'the study itself was created');
    ok($calcStudy && $calcStudy->rendering_mode === 'v2', 'on v2, as its Form unit says', $calcStudy ? $calcStudy->rendering_mode : null);
    $calcItems = $db->execute('SELECT COUNT(*) AS n FROM survey_items WHERE study_id = :s', ['s' => $calcStudy ? $calcStudy->id : 0], false, true);
    ok((int) $calcItems['n'] === 0, 'its item table was refused (calculate)', $calcItems);
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
}
echo "\n-- a soft-deleted item is not in the R data --\n";
try {
    $cols = function ($tag) use ($us, $study) {
        $d = $us->getRunData($study->name . '$q2 # ' . $tag);
        return array_keys((array) ($d['datasets'][$study->name] ?? []));
    };
    ok(in_array('q2', $cols('before'), true), 'q2 is in the R data', $cols('before'));
    $db->exec('UPDATE survey_items SET deleted = NOW() WHERE id = :i', ['i' => $ids['q2']]);
    try {
        ok(!in_array('q2', $cols('after'), true), 'deleted: q2 is not', $cols('after'));
    } finally {
        $db->exec('UPDATE survey_items SET deleted = NULL WHERE id = :i', ['i' => $ids['q2']]);
    }
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
}
echo "\n-- delete all results removes a v2 study's Form sessions and answers --\n";
try {
    // A Form's sessions carry the Form unit's id, not the study's: deleting
    // by the study id alone left every v2 answer in place.
    $formSessions = function () use ($db, $unitId) {
        return (int) $db->execute('SELECT COUNT(*) AS n FROM survey_unit_sessions WHERE unit_id = :u', ['u' => (int) $unitId], false, true)['n'];
    };
    $answers = function () use ($db, $study) {
        return (int) $db->execute('SELECT COUNT(*) AS n FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id WHERE i.study_id = :s', ['s' => (int) $study->id], false, true)['n'];
    };
    ok($formSessions() > 0 && $answers() > 0, 'the form has sessions and answers', [$formSessions(), $answers()]);
    $fresh = new SurveyStudy((int) $study->id);
    ok($fresh->deleteResults() === true, 'delete reports success', $fresh->errors);
    ok($formSessions() === 0, "the Form unit's sessions are gone", $formSessions());
    ok($answers() === 0, 'and their answers', $answers());
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
}
try {
    foreach ((array) $db->execute('SELECT unit_id FROM survey_run_units WHERE run_id = :r', ['r' => $runId]) as $u) {
        $db->exec('DELETE FROM survey_run_units WHERE run_id = :r AND unit_id = :u', ['r' => $runId, 'u' => $u['unit_id']]);
        $db->exec('DELETE FROM survey_units WHERE id = :u AND id <> :keep', ['u' => $u['unit_id'], 'keep' => (int) $unitId]);
    }
    if ($calcStudy && $calcStudy->valid) { $calcStudy->delete(); }
    if ($zeroStudy && $zeroStudy->valid) { $db->query('DELETE FROM survey_r_calls WHERE study_id = ' . (int) $zeroStudy->id); $zeroStudy->delete(); }
} catch (Throwable $e) {}
teardown();
echo "\n" . ($failures ? "\e[31m{$failures} failure(s)\e[0m" : "\e[32mall passed\e[0m") . "\n";
exit($failures ? 1 : 0);
