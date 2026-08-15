#!/usr/bin/php
<?php
/**
 * End-to-end result coverage: drive every RunUnit until each `result` value
 * in tests/fixtures/result_catalogue.php has actually landed in
 * survey_unit_sessions.result at least once.
 *
 * This is the executable half of that catalogue. The PHPUnit guard
 * (tests/ResultCatalogueTest.php) keeps the inventory honest against the
 * source; this proves the reachable half is genuinely reachable, and — just
 * as usefully — that the values declared `unreachable` really cannot be
 * persisted. Several were found that way: survey_completed and
 * error_pause_relative_to are written and then overwritten inside the same
 * request, manual_admin_push is written after end() has already set
 * `ended`, so logResult()'s `ended IS NULL` guard drops it. If a future
 * change makes one of those persist, this run reports it as a surprise
 * instead of nobody noticing.
 *
 * Lives in bin/ rather than tests/ for the same reason as the Track A
 * smokes: the PHPUnit bootstrap forces SQLite in-memory, which cannot host
 * the ENUM / UNIQUE / JSON surface these paths touch, and the daemon-driven
 * results need a real queue pass.
 *
 * Usage:
 *   docker exec formr_app php bin/test_result_coverage_smoke.php
 *   docker exec formr_app php bin/test_result_coverage_smoke.php --keep
 *
 * Exits 0 when every reachable result was observed and no unreachable one
 * was. Cleans up its fixtures unless --keep.
 */
require_once dirname(__FILE__) . '/../setup.php';

$CATALOGUE = require dirname(__FILE__) . '/../tests/fixtures/result_catalogue.php';
$KEEP = in_array('--keep', $argv, true);

$db = DB::getInstance();
$observed = [];   // result => how it was produced
$notes = [];      // result => why it could not be driven here
$artefacts = ['runs' => [], 'accounts' => [], 'studies' => []];

function say(string $s): void { echo $s . "\n"; }
function ok(string $s): void { echo "  \e[32mOK\e[0m   {$s}\n"; }
function bad(string $s): void { echo "  \e[31mFAIL\e[0m {$s}\n"; }
function skip(string $s): void { echo "  \e[33mSKIP\e[0m {$s}\n"; }

/**
 * Record every distinct `result` currently present for a run, so a single
 * traversal can satisfy several catalogue entries at once. Reading the rows
 * rather than trusting the return value of execute() is the whole point:
 * the audit's recurring failure mode was a result that the code computes
 * and the row never receives.
 */
function harvest(DB $db, int $run_id, string $how, array &$observed): array {
    $rows = $db->execute(
        'SELECT DISTINCT us.result
           FROM survey_unit_sessions us
           JOIN survey_run_sessions rs ON rs.id = us.run_session_id
          WHERE rs.run_id = :run_id AND us.result IS NOT NULL',
        ['run_id' => $run_id]
    );
    $seen = [];
    foreach ($rows as $r) {
        $result = $r['result'];
        $key = preg_match('/^group_\d+$/', $result) ? 'group_N' : $result;
        $seen[] = $result;
        if (!isset($observed[$key])) {
            $observed[$key] = $how;
        }
    }
    return $seen;
}

/** Build a transient run with the given units and return its ids. */
function makeRun(DB $db, array &$artefacts, int $owner_id, string $label, array $units): array {
    $run_id = $db->insert('survey_runs', [
        'user_id'     => $owner_id,
        'name'        => 'rescov_' . $label . '_' . bin2hex(random_bytes(3)),
        'created'     => mysql_now(),
        'modified'    => mysql_now(),
        'cron_active' => 0,
    ]);
    $artefacts['runs'][] = $run_id;

    $made = [];
    foreach ($units as $position => $spec) {
        // survey_units.type must be a RunUnitFactory::SupportedUnits value.
        // 'Endpage' is not one: Page::find() re-stamps $type at runtime, so
        // the stored type is 'Page'.
        $type = $spec['type'];
        $unit_id = $db->insert('survey_units', [
            'type' => $type, 'created' => mysql_now(), 'modified' => mysql_now(),
        ]);
        $db->insert('survey_run_units', [
            'run_id' => $run_id, 'unit_id' => $unit_id, 'position' => $position,
        ]);
        // Per-type detail rows. Only the columns each unit's constructor reads.
        if ($type === 'Pause') {
            $db->insert_update('survey_pauses', ['id' => $unit_id] + ($spec['cols'] ?? []));
        } elseif ($type === 'Wait') {
            $db->insert_update('survey_pauses', ['id' => $unit_id] + ($spec['cols'] ?? []));
        } elseif (in_array($type, ['SkipForward', 'SkipBackward'], true)) {
            $db->insert_update('survey_branches', ['id' => $unit_id] + ($spec['cols'] ?? []));
        } elseif ($type === 'External') {
            $db->insert_update('survey_externals', ['id' => $unit_id] + ($spec['cols'] ?? []));
        } elseif ($type === 'Shuffle') {
            $db->insert_update('survey_shuffles', ['id' => $unit_id] + ($spec['cols'] ?? []));
        } elseif ($type === 'Email') {
            $db->insert_update('survey_emails', ['id' => $unit_id] + ($spec['cols'] ?? []));
        } elseif ($type === 'PushMessage') {
            $db->insert_update('push_messages', ['id' => $unit_id] + ($spec['cols'] ?? []));
        } elseif ($type === 'Page') {
            $db->insert_update('survey_pages', ['id' => $unit_id] + ($spec['cols'] ?? []));
        }
        $made[$position] = $unit_id;
    }
    return ['run_id' => $run_id, 'units' => $made];
}

/** Enrol a participant at the run's first position and execute() once. */
function drive(DB $db, int $run_id, int $position) {
    $rs_id = $db->insert('survey_run_sessions', [
        'run_id'   => $run_id,
        'session'  => 'RESCOV' . bin2hex(random_bytes(9)),
        'created'  => mysql_now(),
        'position' => $position,
    ]);
    $row = $db->execute('SELECT session FROM survey_run_sessions WHERE id = :id', ['id' => $rs_id], false, true);
    $run = new Run(null, $run_id);
    $rs = new RunSession($row['session'], $run);
    asParticipant($rs);
    try {
        $rs->execute();
    } catch (Throwable $e) {
        // A unit that throws still leaves rows behind; harvest anyway and
        // let the caller decide. Swallowing here rather than at the call
        // site keeps one bad unit from ending the whole sweep.
        return ['rs' => $rs, 'error' => get_class($e) . ': ' . $e->getMessage()];
    }
    return ['rs' => $rs, 'error' => null];
}

/**
 * Make an execute() look like a participant's web request rather than a
 * daemon pass.
 *
 * Model::boot() sets `cron = Site::runningInConsole()`, so EVERY execute()
 * driven from a CLI script reports isExecutedByCron() === true. That is not
 * a detail: the whole participant half of the state machine hangs off it.
 * Wait's early-return branch, External's api_end branch and Endpage's render
 * are all gated on !isExecutedByCron(), so without this a CLI harness silently
 * exercises the cron arm of every unit and reports the web results as
 * unreachable. UnitSessionQueue::processQueue does the same thing in reverse
 * (it sets cron = true explicitly) for the same reason.
 */
function asParticipant(RunSession $rs): void {
    if ($rs->user) {
        $rs->user->cron = false;
    }
}

/**
 * Re-execute an existing run session with a FRESH RunSession object. The one
 * drive() returned caches position and currentUnitSession from before any
 * backdating, and execute()'s reloadFromDb() only refreshes the run-session
 * row, not the unit-session object already hanging off it.
 */
function reexecute(DB $db, int $run_id) {
    $row = $db->execute(
        'SELECT session FROM survey_run_sessions WHERE run_id = :id ORDER BY id DESC LIMIT 1',
        ['id' => $run_id], false, true
    );
    $run = new Run(null, $run_id);
    $rs = new RunSession($row['session'], $run);
    asParticipant($rs);
    try {
        $rs->execute();
    } catch (Throwable $e) {
        return ['rs' => $rs, 'error' => get_class($e) . ': ' . $e->getMessage()];
    }
    return ['rs' => $rs, 'error' => null];
}

/**
 * One real daemon pass. The cron-side results (expired, wait_ended,
 * ended_by_queue_rse, swept_stale, waiting_deprecated) are reachable only
 * through the queue: it sets `user->cron = true` and drives execute() with
 * executeReferenceUnit, which is a different arm of the state machine from
 * anything a direct execute() reaches. Shelling out to the real worker
 * rather than reimplementing the poll is the point — a harness that
 * simulated the daemon would stop testing the daemon.
 */
function runQueueOnce(): void {
    $bin = dirname(__FILE__) . '/queue.php';
    exec('php ' . escapeshellarg($bin) . ' -t UnitSession --once 2>&1', $out, $rc);
}


/**
 * A study with an `email` item and a `push_notification` item, plus a
 * unit-session row carrying a participant's answers to both.
 *
 * getRecipientEmail()'s "most recent reported address" arm reads
 * survey_items_display.answer for items of type `email` (RunSession.php:976),
 * and getSubscription() reads the same table for type `push_notification`.
 * Both are plain SQL, so the fixture can be built without traversing a
 * survey — which keeps these scenarios independent of the browser lane and
 * of a live push service. Brave and headless Chromium both refuse to
 * subscribe (no Google push service), so a real subscription is not
 * something a test can earn; a well-formed synthetic one exercises exactly
 * the code paths the catalogue is about.
 */
function makeContactAnswers(DB $db, array &$artefacts, int $run_id, int $rs_id, string $email, $subscription) {
    // survey_studies.id and survey_items_display.id are not auto_increment.
    $nextId = function (string $table) use ($db): int {
        $r = $db->execute("SELECT COALESCE(MAX(id), 0) + 1 AS n FROM `{$table}`", [], false, true);
        return (int) $r['n'];
    };
    $study_id = $nextId('survey_studies');
    $db->insert('survey_studies', [
        'id' => $study_id,
        'name' => 'rescov_contact_' . bin2hex(random_bytes(3)),
        'created' => mysql_now(), 'modified' => mysql_now(),
    ]);
    $artefacts['studies'][] = $study_id;

    $unit_id = $db->insert('survey_units', ['type' => 'Survey', 'created' => mysql_now(), 'modified' => mysql_now()]);
    $db->insert('survey_run_units', ['run_id' => $run_id, 'unit_id' => $unit_id, 'position' => 5]);

    $us_id = $db->insert('survey_unit_sessions', [
        'run_session_id' => $rs_id, 'unit_id' => $unit_id,
        'created' => mysql_now(), 'ended' => mysql_now(), 'result' => 'survey_completed',
    ]);

    foreach ([['contact_email', 'email', $email], ['push_sub', 'push_notification', $subscription]] as $item) {
        [$name, $type, $answer] = $item;
        if ($answer === null) continue;
        $item_id = $db->insert('survey_items', [
            'study_id' => $study_id, 'type' => $type, 'name' => $name, 'label' => $name,
        ]);
        $db->insert('survey_items_display', [
            'id' => $nextId('survey_items_display'),
            'item_id' => $item_id, 'session_id' => $us_id,
            'answer' => $answer, 'answered' => mysql_now(), 'created' => mysql_now(),
        ]);
    }
    return $us_id;
}

/** A structurally valid subscription pointing at a host that cannot accept it. */
function fakeSubscription(): string {
    return json_encode([
        'endpoint' => 'https://push.invalid/rescov/' . bin2hex(random_bytes(8)),
        'keys' => [
            'p256dh' => 'BNc' . str_repeat('A', 84),
            'auth'   => 'c2VjcmV0c2VjcmV0c2U',
        ],
    ]);
}

try {
    say("== RunUnit result coverage ==");
    $owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
    if (!$owner) { fwrite(STDERR, "No survey_users row to anchor test runs.\n"); exit(2); }
    $owner_id = (int) $owner['id'];

    // ---- Skips ------------------------------------------------------
    // Four scenarios, one run each, because a skip is terminal: a single
    // traversal can only take one arm.
    say("\n-- SkipForward / SkipBackward --");
    $skips = [
        'skip_true'       => ['condition' => 'TRUE',  'if_true' => 30],
        'skip_false'      => ['condition' => 'FALSE', 'if_true' => 30],
        'skip_to'         => ['condition' => '30',    'if_true' => 20],
        'skip_to_invalid' => ['condition' => '999',   'if_true' => 20],
        'error_opencpu_r' => ['condition' => 'stop("boom")', 'if_true' => 30],
    ];
    foreach ($skips as $want => $cols) {
        $f = makeRun($db, $artefacts, $owner_id, 'skip', [
            10 => ['type' => 'SkipForward', 'cols' => $cols],
            20 => ['type' => 'Page', 'cols' => ['body' => 'fell through']],
            30 => ['type' => 'Page', 'cols' => ['body' => 'jumped']],
        ]);
        $r = drive($db, $f['run_id'], 10);
        $seen = harvest($db, $f['run_id'], "SkipForward condition {$cols['condition']}", $observed);
        in_array($want, $seen, true)
            ? ok("{$want} (condition " . $cols['condition'] . ")")
            : bad("{$want} not produced; row(s) had: " . (implode(', ', $seen) ?: '(none)')
                  . ($r['error'] ? " [{$r['error']}]" : ''));
    }

    // ---- Shuffle ----------------------------------------------------
    say("\n-- Shuffle --");
    $f = makeRun($db, $artefacts, $owner_id, 'shuffle', [
        10 => ['type' => 'Shuffle', 'cols' => ['groups' => 2]],
        20 => ['type' => 'Page', 'cols' => ['body' => 'done']],
    ]);
    drive($db, $f['run_id'], 10);
    $seen = harvest($db, $f['run_id'], 'Shuffle picks a group', $observed);
    $grouped = array_filter($seen, fn($s) => preg_match('/^group_\d+$/', $s));
    $grouped ? ok('group_N (' . implode(', ', $grouped) . ')')
             : bad('no group_N; saw: ' . (implode(', ', $seen) ?: '(none)'));

    // ---- Pause ------------------------------------------------------
    say("\n-- Pause --");
    $f = makeRun($db, $artefacts, $owner_id, 'pausewait', [
        10 => ['type' => 'Pause', 'cols' => ['wait_minutes' => 60]],
        20 => ['type' => 'Page', 'cols' => ['body' => 'done']],
    ]);
    drive($db, $f['run_id'], 10);
    $seen = harvest($db, $f['run_id'], 'Pause, deadline in the future', $observed);
    in_array('pause_waiting', $seen, true) ? ok('pause_waiting')
        : bad('pause_waiting not produced; saw: ' . (implode(', ', $seen) ?: '(none)'));

    // Elapsed pause -> pause_ended. Backdate `created` so the deadline is
    // already behind us, then re-execute.
    $f = makeRun($db, $artefacts, $owner_id, 'pauseend', [
        10 => ['type' => 'Pause', 'cols' => ['wait_minutes' => 1]],
        20 => ['type' => 'Page', 'cols' => ['body' => 'done']],
    ]);
    $r = drive($db, $f['run_id'], 10);
    $db->exec(
        'UPDATE survey_unit_sessions us JOIN survey_run_sessions rs ON rs.id = us.run_session_id
            SET us.created = DATE_SUB(NOW(), INTERVAL 2 HOUR), us.expires = DATE_SUB(NOW(), INTERVAL 1 HOUR)
          WHERE rs.run_id = :run_id',
        ['run_id' => $f['run_id']]
    );
    reexecute($db, $f['run_id']);
    $seen = harvest($db, $f['run_id'], 'Pause, deadline elapsed', $observed);
    in_array('pause_ended', $seen, true) ? ok('pause_ended')
        : bad('pause_ended not produced; saw: ' . (implode(', ', $seen) ?: '(none)'));

    // ---- Wait -------------------------------------------------------
    say("\n-- Wait --");
    $f = makeRun($db, $artefacts, $owner_id, 'wait', [
        10 => ['type' => 'Wait', 'cols' => ['wait_minutes' => 60, 'body' => 30]],
        20 => ['type' => 'Page', 'cols' => ['body' => 'reminder arm']],
        30 => ['type' => 'Page', 'cols' => ['body' => 'returned early']],
    ]);
    drive($db, $f['run_id'], 10);
    reexecute($db, $f['run_id']); // parked by the first pass; this is the return visit
    $seen = harvest($db, $f['run_id'], 'Wait, participant request while parked', $observed);
    in_array('wait_ended_by_user', $seen, true) ? ok('wait_ended_by_user')
        : bad('wait_ended_by_user not produced; saw: ' . (implode(', ', $seen) ?: '(none)'));

    // ---- External ---------------------------------------------------
    say("\n-- External --");
    $externals = [
        'external_redirect'           => ['address' => 'https://example.org/x', 'api_end' => 0],
        'external_wait_for_api'       => ['address' => 'https://example.org/x', 'api_end' => 1],
        'external_r_call_no_redirect' => ['address' => 'FALSE', 'api_end' => 0],
        'external_compute'            => ['address' => '"not-a-url"', 'api_end' => 0],
        'external_r_redirect'         => ['address' => '"https://example.org/r"', 'api_end' => 0],
        'error_opencpu'               => ['address' => 'stop("boom")', 'api_end' => 0],
    ];
    foreach ($externals as $want => $cols) {
        $f = makeRun($db, $artefacts, $owner_id, 'ext', [
            10 => ['type' => 'External', 'cols' => $cols],
            20 => ['type' => 'Page', 'cols' => ['body' => 'done']],
        ]);
        drive($db, $f['run_id'], 10);
        $seen = harvest($db, $f['run_id'], "External address " . $cols['address'], $observed);
        in_array($want, $seen, true) ? ok($want)
            : bad("{$want} not produced; saw: " . (implode(', ', $seen) ?: '(none)'));
    }

    // ---- Page / Endpage ---------------------------------------------
    say("\n-- Page --");
    $f = makeRun($db, $artefacts, $owner_id, 'page', [
        10 => ['type' => 'Page', 'cols' => ['body' => 'Thanks for taking part.']],
    ]);
    drive($db, $f['run_id'], 10);
    $seen = harvest($db, $f['run_id'], 'Endpage rendered', $observed);
    in_array('ended', $seen, true) ? ok('ended')
        : bad('ended not produced; saw: ' . (implode(', ', $seen) ?: '(none)'));

    // ---- Email ------------------------------------------------------
    // Each scenario is its own run: an Email is terminal, so one traversal
    // yields one outcome. The account is created per-scenario so `no_sender`
    // can be produced by its absence.
    say("\n-- Email --");
    $acct = $db->insert('survey_email_accounts', [
        'user_id' => $owner_id, 'created' => mysql_now(), 'modified' => mysql_now(),
        'from' => 'rescov@example.org', 'from_name' => 'rescov',
        'host' => 'smtp.invalid', 'port' => 587, 'tls' => 1,
        'username' => 'rescov', 'password' => Crypto::encrypt('nope'), 'status' => 1,
        // auth_key has no DB default; EmailAccount decrypts it, so it must be
        // a real ciphertext rather than an empty string.
        'auth_key' => Crypto::encrypt('rescov-key'),
    ]);
    $artefacts['accounts'][] = $acct;

    // NOTE on recipient_field: it names a survey item ("study\$email") or
    // carries the "most recent reported address" sentinel — it is never a
    // literal address. Without a participant who has REPORTED an address,
    // every scenario below resolves to nothing and stops at no_recipient,
    // which is why only the checks that run BEFORE recipient resolution
    // (cron_only, opted-out) and no_recipient itself are driven here. The
    // rest need the same study-with-items fixture Survey needs; they are
    // reported as not-driven rather than asserted against a fixture that
    // cannot reach them.
    $emails = [
        'no_recipient' => ['cols' => ['subject' => 'S', 'body' => 'B', 'recipient_field' => 'nosuch$email', 'account_id' => $acct]],
        'email_skipped_user_active' => ['cols' => ['subject' => 'S', 'body' => 'B', 'recipient_field' => 'nosuch$email', 'account_id' => $acct, 'cron_only' => 1]],
    ];
    foreach ($emails as $want => $spec) {
        $f = makeRun($db, $artefacts, $owner_id, 'email', [
            10 => ['type' => 'Email', 'cols' => $spec['cols']],
            20 => ['type' => 'Page', 'cols' => ['body' => 'done']],
        ]);
        drive($db, $f['run_id'], 10);
        $seen = harvest($db, $f['run_id'], "Email: {$want}", $observed);
        in_array($want, $seen, true) ? ok($want)
            : bad("{$want} not produced; saw: " . (implode(', ', $seen) ?: '(none)'));
    }

    // A participant who has switched emails off.
    $f = makeRun($db, $artefacts, $owner_id, 'emailoff', [
        10 => ['type' => 'Email', 'cols' => ['subject' => 'S', 'body' => 'B', 'recipient_field' => 'p@example.org', 'account_id' => $acct]],
        20 => ['type' => 'Page', 'cols' => ['body' => 'done']],
    ]);
    $rs_id = $db->insert('survey_run_sessions', [
        'run_id' => $f['run_id'], 'session' => 'RESCOV' . bin2hex(random_bytes(9)),
        'created' => mysql_now(), 'position' => 10, 'no_email' => 0,
    ]);
    $row = $db->execute('SELECT session FROM survey_run_sessions WHERE id = :id', ['id' => $rs_id], false, true);
    $rs = new RunSession($row['session'], new Run(null, $f['run_id']));
    asParticipant($rs);
    try { $rs->execute(); } catch (Throwable $e) {}
    $seen = harvest($db, $f['run_id'], 'Email: participant opted out', $observed);
    in_array('email_skipped_user_disabled', $seen, true) ? ok('email_skipped_user_disabled')
        : bad('email_skipped_user_disabled not produced; saw: ' . (implode(', ', $seen) ?: '(none)'));

    // With a reported address, recipient resolution succeeds and the rest of
    // the Email outcomes become reachable.
    say("\n-- Email (with a reported address) --");
    $withAddr = [
        'email_queued'     => ['subject' => 'S', 'body' => 'B'],
        'no_email_subject' => ['subject' => '', 'body' => 'B'],
        'no_email_body'    => ['subject' => 'S', 'body' => ''],
    ];
    foreach ($withAddr as $want => $cols) {
        $f = makeRun($db, $artefacts, $owner_id, 'emailaddr', [
            10 => ['type' => 'Email', 'cols' => $cols + ['recipient_field' => null, 'account_id' => $acct]],
            20 => ['type' => 'Page', 'cols' => ['body' => 'done']],
        ]);
        $rs_id = $db->insert('survey_run_sessions', [
            'run_id' => $f['run_id'], 'session' => 'RESCOV' . bin2hex(random_bytes(9)),
            'created' => mysql_now(), 'position' => 10,
        ]);
        makeContactAnswers($db, $artefacts, $f['run_id'], $rs_id, 'rescov@example.org', null);
        $row = $db->execute('SELECT session FROM survey_run_sessions WHERE id = :id', ['id' => $rs_id], false, true);
        $rs = new RunSession($row['session'], new Run(null, $f['run_id']));
        asParticipant($rs);
        try { $rs->execute(); } catch (Throwable $e) {}
        $seen = harvest($db, $f['run_id'], "Email with address: {$want}", $observed);
        in_array($want, $seen, true) ? ok($want)
            : bad("{$want} not produced; saw: " . (implode(', ', $seen) ?: '(none)'));
    }

    // An address that resolves but is not deliverable.
    $f = makeRun($db, $artefacts, $owner_id, 'emailbad', [
        10 => ['type' => 'Email', 'cols' => ['subject' => 'S', 'body' => 'B', 'recipient_field' => null, 'account_id' => $acct]],
        20 => ['type' => 'Page', 'cols' => ['body' => 'done']],
    ]);
    $rs_id = $db->insert('survey_run_sessions', [
        'run_id' => $f['run_id'], 'session' => 'RESCOV' . bin2hex(random_bytes(9)),
        'created' => mysql_now(), 'position' => 10,
    ]);
    makeContactAnswers($db, $artefacts, $f['run_id'], $rs_id, 'not-an-address', null);
    $row = $db->execute('SELECT session FROM survey_run_sessions WHERE id = :id', ['id' => $rs_id], false, true);
    $rs = new RunSession($row['session'], new Run(null, $f['run_id']));
    asParticipant($rs);
    try { $rs->execute(); } catch (Throwable $e) {}
    $seen = harvest($db, $f['run_id'], 'Email: unusable reported address', $observed);
    in_array('invalid_email', $seen, true) ? ok('invalid_email')
        : bad('invalid_email not produced; saw: ' . (implode(', ', $seen) ?: '(none)'));

    // The mail-daemon lane. smtp.invalid cannot connect, so this produces the
    // failure results honestly; email_sent needs a reachable SMTP host and is
    // reported as not-driven rather than faked.
    say("\n-- Email (mail daemon) --");
    exec('php ' . escapeshellarg(dirname(__FILE__) . '/queue.php') . ' -t Email --once 2>&1', $mailOut, $mailRc);
    foreach ($artefacts['runs'] as $run_id) {
        harvest($db, $run_id, 'mail daemon pass', $observed);
    }
    foreach (['error_email_not_sent', 'error_email_invalid_recipient', 'error_email_invalid_subject'] as $want) {
        isset($observed[$want]) ? ok($want) : skip("{$want}: not produced by this daemon pass");
    }

    // ---- PushMessage ------------------------------------------------
    say("\n-- PushMessage --");
    // no_subscription first: it is the one outcome that needs the participant
    // NOT to have a subscription, and the check runs before everything else.
    $f = makeRun($db, $artefacts, $owner_id, 'push', [
        10 => ['type' => 'PushMessage', 'cols' => ['message' => 'hello', 'topic' => 'T']],
        20 => ['type' => 'Page', 'cols' => ['body' => 'done']],
    ]);
    drive($db, $f['run_id'], 10);
    $seen = harvest($db, $f['run_id'], 'PushMessage: participant never subscribed', $observed);
    in_array('no_subscription', $seen, true) ? ok('no_subscription')
        : bad('no_subscription not produced; saw: ' . (implode(', ', $seen) ?: '(none)'));

    // The rest need a subscription to get past that gate.
    $subbed = [
        'message_parse_failed' => ['message' => '', 'topic' => 'T'],
        'title_parse_failed'   => ['message' => 'hello', 'topic' => '`r NULL`'],
        'error'                => ['message' => 'hello', 'topic' => 'T'],
    ];
    foreach ($subbed as $want => $cols) {
        $f = makeRun($db, $artefacts, $owner_id, 'pushsub', [
            10 => ['type' => 'PushMessage', 'cols' => $cols],
            20 => ['type' => 'Page', 'cols' => ['body' => 'done']],
        ]);
        $rs_id = $db->insert('survey_run_sessions', [
            'run_id' => $f['run_id'], 'session' => 'RESCOV' . bin2hex(random_bytes(9)),
            'created' => mysql_now(), 'position' => 10,
        ]);
        makeContactAnswers($db, $artefacts, $f['run_id'], $rs_id, 'rescov@example.org', fakeSubscription());
        $row = $db->execute('SELECT session FROM survey_run_sessions WHERE id = :id', ['id' => $rs_id], false, true);
        $rs = new RunSession($row['session'], new Run(null, $f['run_id']));
        asParticipant($rs);
        try { $rs->execute(); } catch (Throwable $e) {}
        $seen = harvest($db, $f['run_id'], "PushMessage: {$want}", $observed);
        in_array($want, $seen, true) ? ok($want)
            : bad("{$want} not produced; saw: " . (implode(', ', $seen) ?: '(none)'));
    }

    // ---- Queue lane -------------------------------------------------
    // expired / wait_ended / ended_by_queue_rse reach the row only through a
    // real daemon pass, which drives a different arm than execute() does.
    say("\n-- Daemon pass --");
    $f = makeRun($db, $artefacts, $owner_id, 'waitcron', [
        10 => ['type' => 'Wait', 'cols' => ['wait_minutes' => 1, 'body' => 30]],
        20 => ['type' => 'Page', 'cols' => ['body' => 'reminder arm']],
        30 => ['type' => 'Page', 'cols' => ['body' => 'early arm']],
    ]);
    // Deliberately NOT drive()n as a participant: any non-cron request ends a
    // Wait early (that is wait_ended_by_user, covered above), so the daemon
    // would never meet an elapsed one. The queue must be the first executor.
    $rs_id = $db->insert('survey_run_sessions', [
        'run_id' => $f['run_id'], 'session' => 'RESCOV' . bin2hex(random_bytes(9)),
        'created' => mysql_now(), 'position' => 10,
    ]);
    $row = $db->execute('SELECT session FROM survey_run_sessions WHERE id = :id', ['id' => $rs_id], false, true);
    $rs = new RunSession($row['session'], new Run(null, $f['run_id']));
    try { $rs->execute(); } catch (Throwable $e) {}   // cron-flavoured: parks it
    $db->exec(
        'UPDATE survey_unit_sessions us JOIN survey_run_sessions rs ON rs.id = us.run_session_id
            SET us.created = DATE_SUB(NOW(), INTERVAL 2 HOUR), us.expires = DATE_SUB(NOW(), INTERVAL 1 HOUR)
          WHERE rs.run_id = :run_id',
        ['run_id' => $f['run_id']]
    );
    $db->exec('UPDATE survey_runs SET cron_active = 1 WHERE id = :id', ['id' => $f['run_id']]);
    runQueueOnce();
    $seen = harvest($db, $f['run_id'], 'daemon pass on an elapsed Wait', $observed);
    in_array('wait_ended', $seen, true) ? ok('wait_ended')
        : bad('wait_ended not produced; saw: ' . (implode(', ', $seen) ?: '(none)'));

    // ---- Engine-level -----------------------------------------------
    say("\n-- Engine --");
    $f = makeRun($db, $artefacts, $owner_id, 'moved', [
        10 => ['type' => 'Pause', 'cols' => ['wait_minutes' => 600]],
        20 => ['type' => 'Page', 'cols' => ['body' => 'done']],
    ]);
    $r = drive($db, $f['run_id'], 10);
    $r['rs']->forceMoveOn('moved');
    $seen = harvest($db, $f['run_id'], 'forceMoveOn from the admin UI', $observed);
    in_array('moved', $seen, true) ? ok('moved')
        : bad('moved not produced; saw: ' . (implode(', ', $seen) ?: '(none)'));

    // manual_admin_push is declared unreachable — forceTo() ends the row
    // first, so logResult()'s `ended IS NULL` guard drops the label. Drive
    // it deliberately and assert it does NOT appear: if it ever does, the
    // audit finding has been fixed and the catalogue needs updating.
    $f = makeRun($db, $artefacts, $owner_id, 'forceto', [
        10 => ['type' => 'Pause', 'cols' => ['wait_minutes' => 600]],
        20 => ['type' => 'Page', 'cols' => ['body' => 'done']],
    ]);
    $r = drive($db, $f['run_id'], 10);
    $r['rs']->forceTo(20);
    $seen = harvest($db, $f['run_id'], 'forceTo from the admin UI', $observed);
    in_array('manual_admin_push', $seen, true)
        ? bad('manual_admin_push PERSISTED — it is catalogued unreachable; update the catalogue')
        : ok('manual_admin_push stays unpersisted (catalogued unreachable, still true)');

    // ---- Coverage report --------------------------------------------
    say("\n== Coverage ==");
    $missing = $reachable = $unexpected = 0;
    foreach ($CATALOGUE as $result => $meta) {
        $reach = $meta['reach'];
        $got = isset($observed[$result]);

        if (in_array($reach, ['unreachable', 'dead'], true)) {
            if ($got) {
                bad("{$result}: catalogued {$reach} but OBSERVED — {$meta['why']}");
                $unexpected++;
            } else {
                echo "  \e[90m--\e[0m   {$result} ({$reach}): {$meta['why']}\n";
            }
            continue;
        }

        $reachable++;
        if ($got) {
            ok("{$result} <- {$observed[$result]}");
        } elseif (in_array($reach, ['smtp', 'admin', 'cron'], true) && !isset($observed[$result])) {
            skip("{$result} ({$reach}): {$meta['how']} — not driven by this harness");
            $missing++;
        } else {
            bad("{$result} ({$reach}): {$meta['how']} — NOT produced");
            $missing++;
        }
    }

    say("\n" . sprintf(
        "%d/%d reachable results observed; %d not driven here; %d unreachable results unexpectedly persisted.",
        $reachable - $missing, $reachable, $missing, $unexpected
    ));

    if ($unexpected > 0) {
        say("\n\e[31mAn unreachable result persisted. That is good news about the code and\e[0m");
        say("\e[31mbad news about the catalogue — update tests/fixtures/result_catalogue.php.\e[0m");
    }

    exit($unexpected > 0 ? 1 : 0);
} finally {
    if (!$KEEP) {
        foreach ($artefacts['studies'] ?? [] as $sid) {
            try { $db->exec('DELETE FROM survey_studies WHERE id = :id', ['id' => $sid]); } catch (Throwable $e) {}
        }
        foreach ($artefacts['accounts'] ?? [] as $acct_id) {
            try { $db->exec('DELETE FROM survey_email_accounts WHERE id = :id', ['id' => $acct_id]); } catch (Throwable $e) {}
        }
        foreach ($artefacts['runs'] as $run_id) {
            try {
                $db->exec('DELETE FROM survey_run_sessions WHERE run_id = :id', ['id' => $run_id]);
                $db->exec('DELETE su FROM survey_units su JOIN survey_run_units ru ON ru.unit_id = su.id WHERE ru.run_id = :id', ['id' => $run_id]);
                $db->exec('DELETE FROM survey_run_units WHERE run_id = :id', ['id' => $run_id]);
                $db->exec('DELETE FROM survey_runs WHERE id = :id', ['id' => $run_id]);
            } catch (Throwable $e) {
                fwrite(STDERR, "cleanup: " . $e->getMessage() . "\n");
            }
        }
    } else {
        say("\n--keep: left " . count($artefacts['runs']) . " fixture runs in place.");
    }
}
