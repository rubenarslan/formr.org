#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for form_v2's offline sync (/form-sync →
 * FormSubmissionService::applyQueued): a queued entry is applied once, and a
 * request that dies part way leaves nothing that stops the retry.
 *
 *   - an entry is applied and recorded; sent again, it is answered
 *     already_applied and changes nothing; the browser's times (UTC) are
 *     stored in the server's time zone;
 *   - an entry refused for validation errors is not recorded; the corrected
 *     resend applies;
 *   - while another connection holds the entry's lock the sync answers busy
 *     and writes nothing; once that process is killed the lock is free and
 *     the entry applies;
 *   - a sync killed while its write waits on a row lock leaves no ledger row
 *     and none of its answers, and the retry applies the page.
 *
 * Run: docker exec formr_app php /var/www/formr/bin/test_form_v2_sync_smoke.php
 * Creates and removes its own run, study and sessions. The other connections
 * are this script run again (--hold-lock, --apply), so they can be killed
 * with SIGKILL, as a request dies.
 */
require_once dirname(__FILE__) . '/../setup.php';
if (php_sapi_name() !== 'cli') { die("CLI only\n"); }

$db = DB::getInstance();
$owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
if (!$owner) { fwrite(STDERR, "No survey_users row to own the test run.\n"); exit(2); }
$GLOBALS['user'] = new User((int) $owner['id']);

$page = function (array $data) {
    return ['page' => 1, 'unit_session_id' => 0, 'data' => $data, 'item_views' => [], 'hidden' => [], 'final' => false];
};
// --hold-lock <name>: take the named lock and keep it until killed.
if (($argv[1] ?? '') === '--hold-lock') {
    echo ((int) $db->execute('SELECT GET_LOCK(:n, 0)', ['n' => $argv[2]], true) === 1 ? "held\n" : "refused\n");
    sleep(120);
    exit(0);
}
// --apply <run id> <run session code> <uuid> <q1>: one sync, as formSyncAction runs it.
if (($argv[1] ?? '') === '--apply') {
    $us = (new RunSession($argv[3], new Run(null, (int) $argv[2])))->getCurrentUnitSession();
    (new FormSubmissionService($us, $us->runUnit->v2Study()))->applyQueued($argv[4], null, $page(['q1' => $argv[5]]));
    exit(0);
}

$failures = 0;
function ok($cond, string $label, $detail = null): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}" . ($detail !== null ? ' — ' . var_export($detail, true) : '') . "\n";
    if (!$cond) { $failures++; }
}
$children = [];
function spawn(array $args): array {
    global $children;
    $proc = proc_open(array_merge([PHP_BINARY, __FILE__], $args), [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    $children[] = $proc;
    return [$proc, $pipes[1]];
}
function kill9(array $child): void {
    proc_terminate($child[0], 9);
    fclose($child[1]);
    proc_close($child[0]);
}
function readLineWithin($pipe, int $seconds): ?string {
    $r = [$pipe]; $w = null; $e = null;
    return stream_select($r, $w, $e, $seconds) ? trim((string) fgets($pipe)) : null;
}
function waitFor(callable $check, float $seconds) {
    $until = microtime(true) + $seconds;
    while (!($result = $check()) && microtime(true) < $until) { usleep(100000); }
    return $result;
}
$lockFree = function ($name) use ($db) {
    return function () use ($db, $name) { return (int) $db->execute('SELECT IS_FREE_LOCK(:n)', ['n' => $name], true) === 1; };
};
$newUuid = function () {
    $h = bin2hex(random_bytes(16));
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
};
$ledger = function ($uuid) use ($db) {
    return (int) $db->execute('SELECT COUNT(*) FROM survey_form_submissions WHERE uuid = :u', ['u' => $uuid], true);
};

$suffix = bin2hex(random_bytes(3));
$runId = $db->insert('survey_runs', [
    'user_id' => (int) $owner['id'], 'name' => 'syncsmoke_' . $suffix,
    'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0,
]);
$run = new Run(null, $runId);
$item = function ($order, $name, $type, array $extra = []) {
    return $extra + ['name' => $name, 'type' => $type, 'label' => ucfirst($name), 'order' => $order, 'item_order' => $order];
};
$bundle = ['units' => [[
    'type' => 'Form', 'position' => 10, 'description' => 'sync smoke',
    'survey_data' => ['name' => 'syncsmoke_' . $suffix, 'items' => [
        $item(1, 'q1', 'text', ['optional' => 1]),
        $item(2, 'em', 'email', ['optional' => 1]),
        $item(3, 's1', 'submit', ['label' => '']),
        $item(4, 'q2', 'text'),
    ]],
], [
    'type' => 'Endpage', 'position' => 20, 'description' => 'end', 'body' => 'Done.',
]]];
$study = null; $rs = null; $unitId = null; $uuids = [];

try {
    echo "== form_v2 sync smoke ==\n";
    ok($run->importUnits(json_encode($bundle)) !== false, 'run bundle imported');
    $units = $run->getAllUnitIds();
    $unitId = (int) $units[0]['unit_id'];
    $form = RunUnitFactory::make($run, ['id' => $unitId]);
    $study = $form instanceof Form ? $form->v2Study() : null;
    ok($study && $study->valid, 'a Form on a v2 study', $study ? $study->rendering_mode : get_class($form));
    $code = 'SYNCSMOKE' . bin2hex(random_bytes(8));
    $db->insert('survey_run_sessions', ['run_id' => $runId, 'session' => $code, 'created' => mysql_now(), 'position' => 10]);
    $rs = new RunSession($code, $run);
    $rs->position = 10;
    $rs->createUnitSession($form);
    $us = $rs->currentUnitSession;
    $us->createSurveyStudyRecord();
    $svc = new FormSubmissionService($us, $study);
    $answer = function ($name) use ($db, $study, $us) {
        return $db->execute("SELECT `{$name}` FROM `{$study->results_table}` WHERE session_id = :s", ['s' => $us->id], true);
    };

    echo "\n-- applied once --\n";
    $uuids[] = $u1 = $newUuid();
    // The browser's times are UTC: stored in the server's zone (client_time).
    $q1Id = (int) $db->execute('SELECT id FROM survey_items WHERE study_id = :s AND name = :n', ['s' => $study->id, 'n' => 'q1'], true);
    $first = $page(['q1' => 'first']);
    $first['item_views'] = ['shown' => [$q1Id => '2026-10-01 10:00:00'], 'shown_relative' => [$q1Id => '1200.5'],
        'answered' => [$q1Id => '2026-10-01 10:00:07'], 'answered_relative' => [$q1Id => '8200.25']];
    $r = $svc->applyQueued($u1, '2026-10-01 10:00:00', $first);
    ok($r['status'] === 'ok' && empty($r['already_applied']), 'the entry applies', $r);
    ok($answer('q1') === 'first' && $ledger($u1) === 1, 'answer stored, entry recorded');
    $view = $db->execute('SELECT shown, answered FROM survey_items_display WHERE session_id = :s AND item_id = :i', ['s' => $us->id, 'i' => $q1Id], false, true);
    ok($view && $view['shown'] === client_time('2026-10-01 10:00:00') && $view['answered'] === client_time('2026-10-01 10:00:07'),
        "the item's view times are in the server's zone", [$view, client_time('2026-10-01 10:00:00')]);
    ok($db->execute('SELECT client_ts FROM survey_form_submissions WHERE uuid = :u', ['u' => $u1], true) === client_time('2026-10-01 10:00:00'),
        "so is the entry's client_ts");
    $r = $svc->applyQueued($u1, '2026-10-01 10:00:00', $page(['q1' => 'changed']));
    ok($r === ['status' => 'ok', 'already_applied' => true], 'sent again: already_applied', $r);
    ok($answer('q1') === 'first', 'and nothing changed', $answer('q1'));

    echo "\n-- refused for validation errors --\n";
    $uuids[] = $u2 = $newUuid();
    $r = $svc->applyQueued($u2, null, $page(['em' => 'not an address']));
    ok($r['status'] === 'errors' && isset($r['errors']['em']), 'the entry is refused', $r);
    ok($ledger($u2) === 0, 'and not recorded');
    $r = $svc->applyQueued($u2, null, $page(['em' => 'someone@example.org']));
    ok($r['status'] === 'ok' && empty($r['already_applied']) && $ledger($u2) === 1, 'the corrected resend applies', $r);

    echo "\n-- another connection holds the entry's lock --\n";
    $uuids[] = $u3 = $newUuid();
    $holder = spawn(['--hold-lock', 'form_sync_' . $u3]);
    ok(readLineWithin($holder[1], 15) === 'held', 'a second connection holds the lock');
    $t = microtime(true);
    $r = $svc->applyQueued($u3, null, $page(['q1' => 'third']), 0.5);
    ok($r === ['status' => 'busy'], 'the sync answers busy', $r);
    ok(microtime(true) - $t < 3, 'after waiting about the lock wait', microtime(true) - $t);
    ok($ledger($u3) === 0 && $answer('q1') === 'first', 'and writes nothing');
    kill9($holder);
    ok(waitFor($lockFree('form_sync_' . $u3), 15), 'killing the holder frees the lock');
    $r = $svc->applyQueued($u3, null, $page(['q1' => 'third']));
    ok($r['status'] === 'ok' && $answer('q1') === 'third' && $ledger($u3) === 1, 'then the entry applies', $r);

    echo "\n-- a sync killed during its write --\n";
    $uuids[] = $u4 = $newUuid();
    // Hold the results row, so the sync's write waits on it.
    $db->beginTransaction();
    $db->execute("SELECT session_id FROM `{$study->results_table}` WHERE session_id = :s FOR UPDATE", ['s' => $us->id]);
    $sync = spawn(['--apply', (string) $runId, $code, $u4, 'fourth']);
    $conn = waitFor(function () use ($db, $u4) {
        return (int) $db->execute('SELECT IS_USED_LOCK(:n)', ['n' => 'form_sync_' . $u4], true);
    }, 15);
    ok($conn > 0, "the sync took the entry's lock");
    $waiting = $conn && waitFor(function () use ($db, $conn, $study) {
        return (bool) $db->execute('SELECT 1 FROM information_schema.PROCESSLIST WHERE ID = :id AND INFO LIKE :q',
            ['id' => $conn, 'q' => 'UPDATE%' . $study->results_table . '%'], true);
    }, 15);
    ok($waiting, 'its write waits on the results row');
    kill9($sync);
    $db->rollBack();
    ok(waitFor($lockFree('form_sync_' . $u4), 30), "the killed sync's lock is released");
    ok($ledger($u4) === 0, 'the killed sync recorded nothing');
    ok($answer('q1') === 'third', 'and none of its answers stayed', $answer('q1'));
    $r = $svc->applyQueued($u4, null, $page(['q1' => 'fourth']));
    ok($r['status'] === 'ok' && empty($r['already_applied']), 'the retry applies', $r);
    ok($answer('q1') === 'fourth' && $ledger($u4) === 1, 'answer stored, entry recorded');
} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
}
foreach ($children as $proc) {
    if (is_resource($proc) && proc_get_status($proc)['running']) { proc_terminate($proc, 9); }
}
try { $db->rollBack(); } catch (Throwable $e) {}
try {
    foreach ($uuids as $u) { $db->exec('DELETE FROM survey_form_submissions WHERE uuid = :u', ['u' => $u]); }
    if ($rs && $rs->id) { $db->exec('DELETE FROM survey_run_sessions WHERE id = :id', ['id' => $rs->id]); }
    if ($study && $study->valid) { $study->delete(); }
    if ($run && $run->id) { $run->delete(); }
    if ($unitId) { $db->exec('DELETE FROM survey_units WHERE id = :id', ['id' => $unitId]); }
} catch (Throwable $e) {
    echo "  teardown: " . $e->getMessage() . "\n";
}
echo "\n" . ($failures ? "\e[31m{$failures} failure(s)\e[0m" : "\e[32mall passed\e[0m") . "\n";
exit($failures ? 1 : 0);
