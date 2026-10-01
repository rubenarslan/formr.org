#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for PageSubmission: a POST is a submission only for the
 * unit session its form was rendered for (the hidden session_id), and only
 * when it comes from a participant's page (Run::exec captures it).
 *
 *   - a v1 survey page's own POST is stored, paged or not;
 *   - a stale tab's POST (another unit session's id) and a POST without an
 *     id store nothing, and the survey renders as on a page load;
 *   - the admin's "send to position" onto a paged v1 survey runs it as on a
 *     page load: no error_survey;
 *   - a v2 form's final submit runs the next unit without handing it the
 *     form's request: a field of the request (`page`) is not stored as the
 *     answer to a v1 item of that name.
 *
 * Run: docker exec formr_app php /var/www/formr/bin/test_page_submission_smoke.php
 * Creates and removes its own runs, studies and sessions.
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
// A request as the engine sees it: method + posted fields; $capture says
// whether it comes through Run::exec (a participant's page).
function request(string $method, array $post, bool $capture): void {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $post;
    $_FILES = [];
    $capture ? PageSubmission::capture() : PageSubmission::forget();
}
$owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
if (!$owner) { fwrite(STDERR, "No survey_users row to own the test runs.\n"); exit(2); }
$GLOBALS['user'] = new User((int) $owner['id']);

$suffix = bin2hex(random_bytes(3));
$item = function ($order, $name, $type, array $extra = []) {
    return $extra + ['name' => $name, 'type' => $type, 'label' => ucfirst($name), 'order' => $order, 'item_order' => $order];
};
$made = ['runs' => [], 'studies' => [], 'sessions' => []];
$newRun = function (string $tag, array $units) use ($db, $owner, $suffix, &$made) {
    $runId = $db->insert('survey_runs', [
        'user_id' => (int) $owner['id'], 'name' => "subsmoke_{$tag}_{$suffix}",
        'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0,
    ]);
    $run = new Run(null, $runId);
    $made['runs'][] = $run;
    if ($run->importUnits(json_encode(['units' => $units])) === false) {
        throw new RuntimeException("run {$tag} not imported");
    }
    $byPos = [];
    foreach ($run->getAllUnitIds() as $u) {
        $unit = RunUnitFactory::make($run, ['id' => (int) $u['unit_id']]);
        $byPos[(int) $u['position']] = $unit;
        if (!empty($unit->surveyStudy)) { $made['studies'][] = $unit->surveyStudy; }
    }
    return [$run, $byPos];
};
$newSession = function (Run $run, int $position) use ($db, &$made) {
    $code = 'SUBSMOKE' . bin2hex(random_bytes(8));
    $db->insert('survey_run_sessions', ['run_id' => $run->id, 'session' => $code, 'created' => mysql_now(), 'position' => $position]);
    $made['sessions'][] = $code;
    return $code;
};
$unitSessionsOf = function (string $code) use ($db) {
    return (array) $db->execute('SELECT us.id, u.type, us.unit_id, us.state, us.result FROM survey_unit_sessions us
        JOIN survey_units u ON u.id = us.unit_id JOIN survey_run_sessions rs ON rs.id = us.run_session_id
        WHERE rs.session = :c ORDER BY us.id', ['c' => $code]);
};
$answer = function (SurveyStudy $study, int $unitSessionId, string $name) use ($db) {
    return $db->execute("SELECT `{$name}` FROM `{$study->results_table}` WHERE session_id = :s", ['s' => $unitSessionId], true);
};

try {
    echo "== page submission smoke ==\n";
    [$runA, $unitsA] = $newRun('v1', [
        ['type' => 'Survey', 'position' => 10, 'description' => 'plain', 'survey_data' => ['name' => "subsmoke_plain_{$suffix}", 'items' => [
            $item(1, 'q1', 'text', ['optional' => 1]), $item(2, 'page', 'text', ['optional' => 1]),
            $item(3, 's1', 'submit', ['label' => 'Go']), $item(4, 'q2', 'text'),
        ]]],
        ['type' => 'Survey', 'position' => 20, 'description' => 'paged', 'survey_data' => ['name' => "subsmoke_paged_{$suffix}", 'items' => [
            $item(1, 'p1', 'text', ['optional' => 1]), $item(2, 's1', 'submit', ['label' => 'Go']), $item(3, 'p2', 'text', ['optional' => 1]),
        ]]],
        ['type' => 'Endpage', 'position' => 30, 'description' => 'end', 'body' => 'Done.'],
    ]);
    $plain = $unitsA[10]->surveyStudy;
    $paged = $unitsA[20]->surveyStudy;
    $db->exec('UPDATE survey_studies SET use_paging = 1 WHERE id = :id', ['id' => $paged->id]);

    echo "\n-- a v1 survey page --\n";
    $code = $newSession($runA, 10);
    request('GET', [], true);
    (new RunSession($code, $runA))->execute();
    $usId = (int) $unitSessionsOf($code)[0]['id'];
    request('POST', ['session_id' => (string) $usId, 'q1' => 'given'], true);
    (new RunSession($code, $runA))->execute();
    ok($answer($plain, $usId, 'q1') === 'given', "the page's own POST is stored");
    request('POST', ['session_id' => (string) ($usId + 1000000), 'q1' => 'stale'], true);
    $out = (new RunSession($code, $runA))->execute();
    ok($answer($plain, $usId, 'q1') === 'given', "a stale tab's POST (another unit session) stores nothing");
    ok(is_array($out) && !empty($out['body']), 'and the survey renders as on a page load');
    request('POST', ['page' => 'leaked', 'q1' => 'no id'], true);
    (new RunSession($code, $runA))->execute();
    ok($answer($plain, $usId, 'page') === null && $answer($plain, $usId, 'q1') === 'given', 'a POST without session_id stores nothing');
    ok($unitSessionsOf($code)[0]['result'] !== 'error_survey', 'no error_survey', $unitSessionsOf($code)[0]);

    echo "\n-- admin send to position onto a paged v1 survey --\n";
    $code2 = $newSession($runA, 10);
    request('POST', ['session' => $code2, 'new_position' => '20'], false);
    $rs2 = new RunSession($code2, $runA);
    ok($rs2->forceTo(20), 'moved to the paged survey');
    $rs2->execute();
    $row = array_values(array_filter($unitSessionsOf($code2), function ($r) { return $r['type'] === 'Survey'; }))[0] ?? null;
    ok($row && $row['result'] !== 'error_survey', "it ran as on a page load, no error_survey", $row);
    request('POST', ['session_id' => (string) $row['id'], 'fmr_unit_page_element' => '1', 'p1' => 'one'], true);
    (new RunSession($code2, $runA))->execute();
    ok($answer($paged, (int) $row['id'], 'p1') === 'one', "the paged survey's own page POST is stored");

    echo "\n-- a v2 form's final submit runs the next unit as on a page load --\n";
    [$runB, $unitsB] = $newRun('v2', [
        ['type' => 'Form', 'position' => 10, 'description' => 'form', 'survey_data' => ['name' => "subsmoke_form_{$suffix}", 'items' => [
            $item(1, 'fq', 'text', ['optional' => 1]),
        ]]],
        ['type' => 'Survey', 'position' => 20, 'description' => 'next', 'survey_data' => ['name' => "subsmoke_next_{$suffix}", 'items' => [
            $item(1, 'page', 'text', ['optional' => 1]), $item(2, 'final', 'text', ['optional' => 1]), $item(3, 's1', 'submit', ['label' => 'Go']),
        ]]],
        ['type' => 'Endpage', 'position' => 30, 'description' => 'end', 'body' => 'Done.'],
    ]);
    $formStudy = $unitsB[10]->v2Study();
    $nextStudy = $unitsB[20]->surveyStudy;
    ok($formStudy && $formStudy->rendering_mode === 'v2', 'a Form on a v2 study');
    $codeB = $newSession($runB, 10);
    $rsB = new RunSession($codeB, $runB);
    $rsB->position = 10;
    $rsB->createUnitSession($unitsB[10]);
    $fus = $rsB->currentUnitSession;
    $fus->createSurveyStudyRecord();
    // A multipart final page: its envelope fields are in $_POST.
    request('POST', ['page' => '1', 'final' => 'true', 'unit_session_id' => (string) $fus->id, 'data' => ['fq' => 'x']], false);
    $r = (new FormSubmissionService($fus, $formStudy))->apply(['page' => 1, 'unit_session_id' => 0, 'data' => ['fq' => 'x'], 'item_views' => [], 'hidden' => [], 'final' => true]);
    ok(!empty($r['complete']), 'the final submit ends the form', $r);
    $next = array_values(array_filter($unitSessionsOf($codeB), function ($row) { return $row['type'] === 'Survey'; }))[0] ?? null;
    ok($next !== null, 'and runs the next survey');
    ok($next && $next['result'] !== 'error_survey', 'without error', $next);
    ok($next && $answer($nextStudy, (int) $next['id'], 'page') === null && $answer($nextStudy, (int) $next['id'], 'final') === null,
        "the form's request fields are not stored in it", $next ? [$answer($nextStudy, (int) $next['id'], 'page'), $answer($nextStudy, (int) $next['id'], 'final')] : null);
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    $failures++;
}
request('GET', [], false);
try {
    foreach ($made['sessions'] as $c) { $db->exec('DELETE FROM survey_run_sessions WHERE session = :c', ['c' => $c]); }
    foreach ($made['studies'] as $s) { if ($s && $s->valid) { $s->delete(); } }
    foreach ($made['runs'] as $r) { $r->delete(); }
} catch (Throwable $e) {
    echo "  teardown: " . $e->getMessage() . "\n";
}
echo "\n" . ($failures ? "\e[31m{$failures} failure(s)\e[0m" : "\e[32mall passed\e[0m") . "\n";
exit($failures ? 1 : 0);
