#!/usr/bin/php
<?php
/**
 * Live smoke (MariaDB + OpenCPU) for how form_v2's server-side R copes with
 * failure and revocation (RCallService):
 *
 *   - one OpenCPU batch evaluates a page's values; when the batch fails (a
 *     parse error in one expression fails the whole program) each expression
 *     is re-evaluated on its own, so the others still resolve and only the
 *     broken one is missing (reported as an R failure, not a transient one);
 *     a runtime error inside one expression stays NA for that one alone;
 *   - a note_iframe whose R Markdown fails to knit resolves to the
 *     "temporarily unavailable" placeholder, and the failure is not cached;
 *     one that knits resolves to its iframe;
 *   - the participant's answers reach every R path (values, labels,
 *     note_iframe documents) as data: an answer that looks like R Markdown
 *     (a newline, then a ``` line and a chunk of its own) arrives exactly as
 *     typed and none of it runs;
 *   - a participant's answers are data: text in them naming
 *     formr_api_authenticate( or a run secret gets the call neither;
 *   - the allowlist: callsForPage returns a call only while its item exists,
 *     is not soft-deleted and still carries the recorded expression (value or
 *     label); a call row with no item is never returned. The pure refusals
 *     (no item anchor, item row gone) are pinned in
 *     tests/RCallServiceAllowlistTest.php as well.
 *
 * Run: docker exec formr_app php /var/www/formr/bin/test_form_v2_rcall_smoke.php
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
    'user_id' => (int) $owner['id'], 'name' => 'rcallsmoke_' . $suffix,
    'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0,
]);
$run = new Run(null, $runId);
$studyName = 'rcallsmoke_' . $suffix;
$item = function ($order, $name, $type, array $extra = []) {
    return $extra + ['name' => $name, 'type' => $type, 'label' => ucfirst($name), 'order' => $order, 'item_order' => $order, 'optional' => 1];
};
$brokenRmd = "Before.\n\n```{r}\nstop('knit boom')\n```\n";
$goodRmd = "Knitted: `r 40 + 2`";
$bundle = ['units' => [[
    'type' => 'Form', 'position' => 10, 'description' => 'rcall smoke',
    'survey_data' => ['name' => $studyName, 'items' => [
        $item(1, 'v_ok', 'hidden', ['value' => '1 + 1']),
        $item(2, 'v_parse', 'hidden', ['value' => '1 +']),         // parse error: fails the batch
        $item(3, 'v_rt', 'hidden', ['value' => 'stop("boom")']),  // runtime error: NA, alone
        $item(4, 'v_ok2', 'hidden', ['value' => 'paste0("a", "b")']),
        $item(5, 'v_edit', 'hidden', ['value' => '3 * 3']),
        $item(6, 'v_del', 'hidden', ['value' => '4 * 4']),
        $item(7, 'ifr_bad', 'note_iframe', ['label' => $brokenRmd]),
        $item(8, 'ifr_ok', 'note_iframe', ['label' => $goodRmd]),
        $item(9, 'q1', 'text'),
        $item(10, 'n1', 'number'),
        $item(11, 'v_echo', 'hidden', ['value' => 'q1']),
        $item(12, 'v_num', 'hidden', ['value' => 'n1 * 2']),
        $item(13, 'lbl_echo', 'note', ['label' => 'Said: `r q1`']),
        $item(14, 'ifr_echo', 'note_iframe', ['label' => 'Echo: `r q1`']),
    ]],
], [
    'type' => 'Endpage', 'position' => 20, 'description' => 'end', 'body' => 'Done.',
]]];
$study = null; $rs = null; $unitId = null; $unitIds = [];
function teardown() {
    global $db, $study, $run, $rs, $unitId, $unitIds;
    try { if ($rs && $rs->id) { $db->exec('DELETE FROM survey_run_sessions WHERE id = :id', ['id' => $rs->id]); } } catch (Throwable $e) {}
    try { if ($study && $study->valid) { $db->query('DELETE FROM survey_r_calls WHERE study_id = ' . (int) $study->id); $study->delete(); } } catch (Throwable $e) {}
    try { if ($run && $run->id) { $run->delete(); } } catch (Throwable $e) {}
    foreach ($unitIds as $u) { try { $db->exec('DELETE FROM survey_units WHERE id = :id', ['id' => $u]); } catch (Throwable $e) {} }
}
/** Counts the charges against the compute budget (each is two writes). */
class CountingUnitSession extends UnitSession {
    public $charges = 0;
    public $charged = 0.0;
    public function addExecutionTime(float $seconds) {
        $this->charges++;
        $this->charged += $seconds;
        parent::addExecutionTime($seconds);
    }
}
// Reach the protected batch evaluator to see which keys failed and why.
function evaluateBatch(RCallService $svc, array $exprs) {
    $m = new ReflectionMethod(RCallService::class, 'evaluateBatch');
    $m->setAccessible(true);
    return $m->invoke($svc, $exprs, []);
}

try {
    echo "== form_v2 R failure handling + allowlist smoke ==\n";
    ok($run->importUnits(json_encode($bundle)) !== false, 'run bundle imported');
    $units = $run->getAllUnitIds();
    $unitIds = array_map('intval', array_column($units, 'unit_id'));
    $unitId = (int) $units[0]['unit_id'];
    $form = RunUnitFactory::make($run, ['id' => $unitId]);
    $study = $form->surveyStudy ?: $form->getStudy(true);
    ok($study && $study->valid && $study->rendering_mode === 'v2', 'v2 study created', $study ? $study->rendering_mode : null);
    $ids = [];
    foreach ((array) $db->execute('SELECT id, name FROM survey_items WHERE study_id = :s', ['s' => $study->id]) as $r) { $ids[$r['name']] = (int) $r['id']; }
    ok(count($ids) === 14, 'fourteen items (the broken R was accepted at upload)', array_keys($ids));

    $rsId = $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => 'RCALLSMOKE' . bin2hex(random_bytes(8)), 'created' => mysql_now(), 'position' => 10]);
    $rs = new RunSession($db->execute('SELECT session FROM survey_run_sessions WHERE id = :id', ['id' => $rsId], false, true)['session'], $run);
    $rs->position = 10;
    $rs->createUnitSession($form);
    $us = $rs->currentUnitSession;
    $us->createSurveyStudyRecord();
    // A participant's request, not a test session: knit errors are fatal
    // (error=FALSE) exactly as for a real participant.
    ok(!$rs->isTesting(), 'the run session is a participant, not a tester');
    Site::getInstance()->setRunSession($rs);

    $renderer = new FormRenderer($study, $us);
    $renderer->processItems();
    $renderer->render();
    $svc = new RCallService($us, $study);
    $calls = $svc->callsForPage(1);
    $byName = function (array $slot) {
        $out = [];
        foreach ($slot as $cid => $c) { $out[$c['item_name']] = (int) $cid; }
        ksort($out);
        return $out;
    };
    $valueCalls = $byName($calls['value']);
    ok(array_keys($valueCalls) === ['v_del', 'v_echo', 'v_edit', 'v_num', 'v_ok', 'v_ok2', 'v_parse', 'v_rt'], 'every value is a live call on page 1', array_keys($valueCalls));
    $labelCalls = $byName($calls['label']);
    ok(array_keys($labelCalls) === ['ifr_bad', 'ifr_echo', 'ifr_ok', 'lbl_echo'], 'the iframes and the dynamic label are live label calls', array_keys($labelCalls));

    echo "\n-- a failing batch is retried one expression at a time --\n";
    $values = $svc->resolveExpressions($calls['value'], [], 0);
    $got = function ($name) use ($values, $valueCalls) { return array_key_exists($valueCalls[$name], $values) ? $values[$valueCalls[$name]] : 'ABSENT'; };
    ok($got('v_ok') === 2, 'v_ok resolves beside a parse error', $got('v_ok'));
    ok($got('v_ok2') === 'ab', 'v_ok2 resolves beside a parse error', $got('v_ok2'));
    ok($got('v_edit') === 9 && $got('v_del') === 16, 'the other values resolve too', [$got('v_edit'), $got('v_del')]);
    ok($got('v_parse') === 'ABSENT', 'the unparseable expression is missing, not blank-filled', $got('v_parse'));
    ok($got('v_rt') !== 'ABSENT' && RCallService::rResultToScalarString($got('v_rt')) === '', 'a runtime error is NA for that expression alone', $got('v_rt'));
    $cached = $db->execute('SELECT call_id FROM survey_r_call_results WHERE unit_session_id = :u', ['u' => $us->id]);
    $cachedIds = array_map('intval', array_column((array) $cached, 'call_id'));
    ok(!in_array($valueCalls['v_parse'], $cachedIds, true) && in_array($valueCalls['v_ok'], $cachedIds, true), 'resolved values are cached, the failed one is not', $cachedIds);

    $r = evaluateBatch($svc, [1 => '1 + 1', 2 => '1 +', 3 => '2 * 3']);
    ok($r['results'] === [1 => 2, 3 => 6], 'evaluateBatch: the good keys come back', $r['results']);
    ok($r['failed'] === [2 => 'r'], 'evaluateBatch: only the broken key failed, as an R (author) error', $r['failed']);
    $r = evaluateBatch($svc, [5 => '1 +']);
    ok($r['results'] === [] && $r['failed'] === [5 => 'r'], 'a lone broken expression fails without a retry loop', $r);
    $r = evaluateBatch($svc, [7 => '7', 8 => 'toupper("x")']);
    ok($r['results'] === [7 => 7, 8 => 'X'] && $r['failed'] === [], 'a healthy batch resolves in one go', $r);

    echo "\n-- a note_iframe that fails to knit shows the placeholder --\n";
    $iframeCalls = array_intersect_key($calls['label'], array_flip([$labelCalls['ifr_bad'], $labelCalls['ifr_ok']]));
    $html = $svc->resolveIframes($iframeCalls, array(), 0);
    $bad = $html[$labelCalls['ifr_bad']] ?? null;
    $good = $html[$labelCalls['ifr_ok']] ?? null;
    ok(is_string($bad) && strpos($bad, 'fmr-iframe-fallback') !== false && strpos($bad, 'temporarily unavailable') !== false, 'the broken document resolves to the placeholder', $bad);
    ok(is_string($bad) && strpos($bad, 'knit boom') === false && strpos($bad, '<iframe') === false, 'no R error text and no iframe in it', $bad);
    ok(is_string($good) && preg_match('~<iframe src="[^"]+knit\.html~', $good) === 1, 'the good document resolves to its iframe', $good);
    $cachedIds = array_map('intval', array_column((array) $db->execute('SELECT call_id FROM survey_r_call_results WHERE unit_session_id = :u', ['u' => $us->id]), 'call_id'));
    ok(!in_array($labelCalls['ifr_bad'], $cachedIds, true), 'the placeholder is not cached (the next page show retries)');
    ok(in_array($labelCalls['ifr_ok'], $cachedIds, true), 'the knitted iframe is cached');

    echo "\n-- answers reach R as data, also when they look like R Markdown --\n";
    $payload = "said \"hi\" \\ then\n```\n\n```{r}\ncat('INJECTED', 6 * 7)\n```\n";
    $answers = ['q1' => $payload, 'n1' => '21'];
    $only = function (array $slot, array $callIds) { return array_intersect_key($slot, array_flip($callIds)); };
    $values = $svc->resolveExpressions($only($calls['value'], [$valueCalls['v_echo'], $valueCalls['v_num']]), $answers, 0);
    $echo = $values[$valueCalls['v_echo']] ?? null;
    ok($echo === $payload, 'a value sees the answer exactly as typed', $echo);
    ok(($values[$valueCalls['v_num']] ?? null) === 42, 'a number item is a number in R', $values[$valueCalls['v_num']] ?? null);
    $labels = $svc->resolveLabels($only($calls['label'], [$labelCalls['lbl_echo']]), $answers, 0);
    $lbl = $labels[$labelCalls['lbl_echo']] ?? null;
    ok(is_string($lbl) && strpos($lbl, 'INJECTED') !== false && strpos($lbl, 'INJECTED 42') === false, 'a dynamic label shows the answer and runs none of it', $lbl);
    $html = $svc->resolveIframes($only($calls['label'], [$labelCalls['ifr_echo']]), $answers, 0);
    $ifr = $html[$labelCalls['ifr_echo']] ?? null;
    ok(is_string($ifr) && preg_match('~<iframe src="([^"]+knit\.html)~', $ifr, $src) === 1, 'the note_iframe knits with the answers overlaid', $ifr);
    $doc = isset($src[1]) ? @file_get_contents(html_entity_decode($src[1])) : false;
    ok(is_string($doc) && strpos($doc, 'INJECTED') !== false && strpos($doc, 'INJECTED 42') === false, 'its document shows the answer and runs none of it', is_string($doc) ? strlen($doc) . ' bytes' : $doc);

    echo "\n-- a missing number is no value, not the text NA --\n";
    $na = evaluateBatch($svc, [1 => 'NA_real_', 2 => 'mean(c(1, NA))', 3 => 'NaN', 4 => '1.5', 5 => '"NA"']);
    ok(array_key_exists(1, $na['results']) && $na['results'][1] === null && $na['results'][2] === null && $na['results'][3] === null,
        'NA_real_, a mean over NA and NaN come back as nothing', $na['results']);
    ok(RCallService::rResultToScalarString($na['results'][2]) === '' && $na['results'][4] === 1.5 && $na['results'][5] === 'NA',
        'a number stays a number, and the string "NA" stays a string', $na['results']);

    echo "\n-- a following value replaces the input only for changed answers --\n";
    // v_num (n1 * 2) reads another item, so a new result replaces what the
    // hidden item holds — but only when the answers changed since its last
    // result: evaluated again for the same answers (the cache expired), a
    // draw among them would be drawn anew.
    $num = $valueCalls['v_num'];
    $db->exec('DELETE FROM survey_r_call_results WHERE call_id = :c AND unit_session_id = :u', ['c' => $num, 'u' => $us->id]);
    $changed = function (array $answers) use ($us, $study, $calls, $only, $num) {
        $request = new RCallService($us, $study);
        $request->resolveExpressions($only($calls['value'], [$num]), $answers, 300);
        return $request->answersChangedFor($num);
    };
    ok($changed(['n1' => '3']) === true, 'the first result replaces');
    ok($changed(['n1' => '3']) === false, 'the same answers, cached: no');
    $db->exec('UPDATE survey_r_call_results SET created_at = NOW() - INTERVAL 1 HOUR WHERE call_id = :c AND unit_session_id = :u', ['c' => $num, 'u' => $us->id]);
    ok($changed(['n1' => '3']) === false, 'the same answers, evaluated again after the cache expired: no');
    ok($changed(['n1' => '4']) === true, 'other answers: yes');
    ok($changed(['n1' => '3']) === true, 'and back to the earlier ones: yes again');

    echo "\n-- a page's R is charged once --\n";
    $db->exec('DELETE FROM survey_r_call_results WHERE unit_session_id = :u', ['u' => $us->id]);
    $counting = new CountingUnitSession($rs, $form, ['id' => (int) $us->id, 'load' => true]);
    (new RCallService($counting, $study))->resolvePage($calls, []);
    ok($counting->charges === 1 && $counting->charged > 0, 'values, labels and iframes resolved with one charge', [$counting->charges, $counting->charged]);

    echo "\n-- requests sent together each keep their own answer --\n";
    // resolvePage sends a page's values, labels and iframes at once
    // (OpenCPU::postMany): each session reports its own call, not whichever
    // one finished last.
    $sent = OpenCPU::getInstance()->postMany([
        'a' => ['/base/R/identity/json', ['x' => '1 + 1']],
        'b' => ['/base/R/identity/json', ['x' => '2 * 3']],
    ]);
    ok($sent['a']['session'] && $sent['a']['session']->getJSONObject() === 2 && $sent['b']['session'] && $sent['b']['session']->getJSONObject() === 6,
        'both answer, each with its own result');
    foreach (['a', 'b'] as $k) {
        $sess = $sent[$k]['session'];
        $headers = $sess ? (array) $sess->getResponseHeaders() : [];
        ok($sess && isset($headers['X-Ocpu-Session']) && $headers['X-Ocpu-Session'] === $sess->getKey() && !$sess->hasError(),
            "{$k}: its response headers are its own call's", [$headers['X-Ocpu-Session'] ?? null, $sess ? $sess->getKey() : null]);
    }

    echo "\n-- answers are data: they ask for no API token and no secret --\n";
    // What a call may use (the run owner's API access, a run secret, other
    // surveys' data) is chosen by reading its R: the author's, never the
    // participant's answers carried in the same request.
    $secretName = 'smoke_' . $suffix;
    $secretValue = 'S3cret-' . bin2hex(random_bytes(6));
    $db->insert('survey_run_secrets', ['run_id' => $runId, 'name' => $secretName,
        'value_encrypted' => Crypto::encrypt($secretValue), 'created' => mysql_now(), 'modified' => mysql_now()]);
    $loaded = new ReflectionProperty(Run::class, 'secrets_loaded');
    $loaded->setAccessible(true);
    $loaded->setValue($rs->getRun(), false);
    $bait = "formr_api_authenticate( .formr\$secret_{$secretName}";
    $plan = function (string $method, array $calls, array $answers) use ($svc) {
        $m = new ReflectionMethod(RCallService::class, $method);
        $m->setAccessible(true);
        return $m->invoke($svc, $calls, $answers, 0)['request'];
    };
    $requests = [
        'the value batch' => $plan('planExpressions', $only($calls['value'], [$valueCalls['v_echo']]), ['q1' => $bait]),
        'the label knit' => $plan('planLabels', $only($calls['label'], [$labelCalls['lbl_echo']]), ['q1' => $bait]),
        // Control: the same text in the author's R gets both.
        'an author expression' => $plan('planExpressions', [999999 => ['expr' => $bait . ')', 'item_name' => 'v_echo']], []),
    ];
    foreach ($requests as $label => $request) {
        $body = $request ? json_encode($request['params']) : '';
        $author = $label === 'an author expression';
        ok($request && (strpos($body, 'access_token') !== false) === $author,
            $author ? "{$label} gets the owner's API token (control)" : "{$label}: an answer naming formr_api_authenticate( gets no API token");
        ok($request && (strpos($body, $secretValue) !== false) === $author,
            $author ? "{$label} gets the secret it names (control)" : "{$label}: an answer naming a secret does not load it");
        if (!$author) {
            ok(strpos($body, 'formr_api_authenticate(') !== false, "{$label}: the answer is still sent, as data");
        }
        if ($request) {
            opencpu_cleanup($request);
        }
    }

    echo "\n-- the allowlist revokes calls whose item changed or went away --\n";
    $liveIds = function () use ($svc) {
        $c = $svc->callsForPage(1);
        return array_map('intval', array_merge(array_keys($c['value']), array_keys($c['label'])));
    };
    // A call row with no item anchor (the shape of a legacy row) for an
    // expression that is also live on an item: never returned.
    $orphanId = (int) $db->insert('survey_r_calls', ['study_id' => $study->id, 'slot' => 'value', 'item_id' => null, 'expr' => '1 + 1', 'expr_hash' => hash('sha256', 'orphan' . $suffix)]);
    ok($orphanId > 0 && !in_array($orphanId, $liveIds(), true), 'a call row with no item is not returned');
    ok(in_array($valueCalls['v_edit'], $liveIds(), true), 'v_edit is live before the edit');
    $db->exec('UPDATE survey_items SET value = :v WHERE id = :id', ['v' => '5 * 5', 'id' => $ids['v_edit']]);
    ok(!in_array($valueCalls['v_edit'], $liveIds(), true), 'editing the value revokes the recorded call');
    $db->exec('UPDATE survey_items SET value = :v WHERE id = :id', ['v' => '', 'id' => $ids['v_edit']]);
    ok(!in_array($valueCalls['v_edit'], $liveIds(), true), 'so does blanking it');
    $db->exec('UPDATE survey_items SET deleted = NOW() WHERE id = :id', ['id' => $ids['v_del']]);
    ok(!in_array($valueCalls['v_del'], $liveIds(), true), 'soft-deleting the item revokes its call');
    $db->exec('UPDATE survey_items SET label = :l WHERE id = :id', ['l' => 'Knitted: `r 1`', 'id' => $ids['ifr_ok']]);
    ok(!in_array($labelCalls['ifr_ok'], $liveIds(), true), 'editing a note_iframe document revokes its label call');
    $still = $liveIds();
    ok(in_array($valueCalls['v_ok'], $still, true) && in_array($labelCalls['ifr_bad'], $still, true), 'untouched items stay live', $still);
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
}
teardown();
$left = (int) $db->execute('SELECT COUNT(*) AS n FROM survey_studies WHERE name = :n', ['n' => $studyName], false, true)['n']
      + (int) $db->execute('SELECT COUNT(*) AS n FROM survey_runs WHERE id = :id', ['id' => $runId], false, true)['n']
      + ($unitIds ? (int) $db->execute('SELECT COUNT(*) AS n FROM survey_units WHERE id IN (' . implode(',', $unitIds) . ')', [], false, true)['n'] : 0);
ok($left === 0, 'teardown removed the run, its units and the study', $left);
echo "\n" . ($failures ? "\e[31m{$failures} failure(s)\e[0m" : "\e[32mall passed\e[0m") . "\n";
exit($failures ? 1 : 0);
