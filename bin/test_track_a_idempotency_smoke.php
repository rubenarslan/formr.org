#!/usr/bin/php
<?php
/**
 * Track A A4 / R5 — live-DB idempotency smoke.
 *
 * Drives the actual INSERT ... ON DUPLICATE KEY UPDATE path against
 * MariaDB to prove the UNIQUE(idempotency_key) constraints close R5
 * (daemon SIGKILL between row INSERT and result UPDATE → restart
 * re-INSERT → duplicate send). PHPUnit can't host this because the
 * SQLite in-memory bootstrap doesn't carry the UNIQUE behavior or
 * MariaDB-specific INSERT ... ON DUPLICATE KEY UPDATE semantics.
 *
 * Usage:
 *   docker exec formr_app php bin/test_track_a_idempotency_smoke.php
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
$artefacts = ['email_log_ids' => [], 'push_log_ids' => [], 'unit_session_ids' => [], 'run_id' => null, 'rs_id' => null, 'unit_ids' => [], 'email_id' => null];

function assert_eq($actual, $expected, string $label): void {
    global $failures;
    if ($actual === $expected) {
        echo "  \e[32mOK\e[0m  {$label}: " . var_export($actual, true) . "\n";
    } else {
        echo "  \e[31mFAIL\e[0m {$label}: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
        $failures++;
    }
}

function teardown(DB $db, array &$artefacts): void {
    foreach ($artefacts['email_log_ids'] as $id) {
        try { $db->exec('DELETE FROM survey_email_log WHERE id = :id', ['id' => $id]); } catch (Throwable $e) {}
    }
    foreach ($artefacts['push_log_ids'] as $id) {
        try { $db->exec('DELETE FROM push_logs WHERE id = :id', ['id' => $id]); } catch (Throwable $e) {}
    }
    foreach ($artefacts['unit_session_ids'] as $id) {
        try { $db->exec('DELETE FROM survey_unit_sessions WHERE id = :id', ['id' => $id]); } catch (Throwable $e) {}
    }
    if ($artefacts['rs_id']) {
        try { $db->exec('DELETE FROM survey_run_sessions WHERE id = :id', ['id' => $artefacts['rs_id']]); } catch (Throwable $e) {}
    }
    if ($artefacts['email_id']) {
        try { $db->exec('DELETE FROM survey_emails WHERE id = :id', ['id' => $artefacts['email_id']]); } catch (Throwable $e) {}
    }
    if ($artefacts['run_id']) {
        try { $db->exec('DELETE FROM survey_run_units WHERE run_id = :rid', ['rid' => $artefacts['run_id']]); } catch (Throwable $e) {}
        try { $db->exec('DELETE FROM survey_runs WHERE id = :id', ['id' => $artefacts['run_id']]); } catch (Throwable $e) {}
    }
    foreach ($artefacts['unit_ids'] as $id) {
        try { $db->exec('DELETE FROM survey_units WHERE id = :id', ['id' => $id]); } catch (Throwable $e) {}
    }
}

try {
    echo "== Track A idempotency smoke ==\n";

    $owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
    if (!$owner) { fwrite(STDERR, "no users\n"); exit(2); }

    // Minimal fixture: one Run, one Email survey_unit, one
    // survey_run_session, one survey_unit_session, one Email definition.
    $artefacts['run_id'] = $db->insert('survey_runs', [
        'user_id' => (int) $owner['id'],
        'name'    => 'track_a_idemp_' . bin2hex(random_bytes(4)),
        'created' => mysql_now(), 'modified' => mysql_now(),
        'cron_active' => 0,
    ]);
    $emailUnit = $db->insert('survey_units', ['type' => 'Email', 'created' => mysql_now(), 'modified' => mysql_now()]);
    $pushUnit  = $db->insert('survey_units', ['type' => 'PushMessage', 'created' => mysql_now(), 'modified' => mysql_now()]);
    $artefacts['unit_ids'] = [$emailUnit, $pushUnit];

    $db->insert('survey_run_units', ['run_id' => $artefacts['run_id'], 'unit_id' => $emailUnit, 'position' => 10]);
    $db->insert('survey_run_units', ['run_id' => $artefacts['run_id'], 'unit_id' => $pushUnit,  'position' => 20]);

    $artefacts['email_id'] = $db->insert('survey_emails', [
        'id'              => $emailUnit,
        'subject'         => 'idemp test',
        'body'            => 'test',
        'recipient_field' => 'most recent reported address',
    ]);

    $artefacts['rs_id'] = $db->insert('survey_run_sessions', [
        'run_id'   => $artefacts['run_id'],
        'session'  => 'IDEMPXXX' . bin2hex(random_bytes(8)),
        'created'  => mysql_now(),
        'position' => 10,
    ]);

    $unit_session_id = $db->insert('survey_unit_sessions', [
        'run_session_id' => $artefacts['rs_id'],
        'unit_id'        => $emailUnit,
        'created'        => mysql_now(),
    ]);
    $artefacts['unit_session_ids'][] = $unit_session_id;

    echo "\n-- Email idempotency: first INSERT lands, second is no-op via UNIQUE collision --\n";
    $idempKey = "email:{$unit_session_id}:{$emailUnit}";

    $insert1 = $db->exec(
        "INSERT INTO `survey_email_log`
            (`subject`, `status`, `session_id`, `email_id`, `recipient`, `created`, `account_id`, `meta`, `idempotency_key`)
         VALUES
            ('s', 0, :sid, :eid, 'r@example.com', NOW(), NULL, '{}', :key)
         ON DUPLICATE KEY UPDATE `id` = `id`",
        ['sid' => $unit_session_id, 'eid' => $emailUnit, 'key' => $idempKey]
    );
    assert_eq((int) $insert1, 1, 'first INSERT affected 1 row');

    // Capture the row we just inserted so teardown deletes it.
    $r = $db->execute('SELECT id FROM survey_email_log WHERE idempotency_key = :k', ['k' => $idempKey], false, true);
    if ($r) $artefacts['email_log_ids'][] = (int) $r['id'];

    $insert2 = $db->exec(
        "INSERT INTO `survey_email_log`
            (`subject`, `status`, `session_id`, `email_id`, `recipient`, `created`, `account_id`, `meta`, `idempotency_key`)
         VALUES
            ('s', 0, :sid, :eid, 'r@example.com', NOW(), NULL, '{}', :key)
         ON DUPLICATE KEY UPDATE `id` = `id`",
        ['sid' => $unit_session_id, 'eid' => $emailUnit, 'key' => $idempKey]
    );
    assert_eq((int) $insert2, 0, 'second INSERT (duplicate) is silent no-op (0 rows)');

    $count = (int) $db->execute('SELECT COUNT(*) FROM survey_email_log WHERE idempotency_key = :k', ['k' => $idempKey], true);
    assert_eq($count, 1, 'only one survey_email_log row exists for this idempotency_key');

    echo "\n-- Push idempotency: first INSERT lands, second is no-op via UNIQUE collision --\n";
    $pushKey = "push:{$unit_session_id}";
    $pinsert1 = $db->exec(
        "INSERT INTO `push_logs`
            (`unit_session_id`, `run_id`, `message`, `status`, `attempt`, `created`, `idempotency_key`)
         VALUES
            (:us, :rid, 'm', 'queued', 1, NOW(), :key)
         ON DUPLICATE KEY UPDATE `id` = `id`",
        ['us' => $unit_session_id, 'rid' => $artefacts['run_id'], 'key' => $pushKey]
    );
    assert_eq((int) $pinsert1, 1, 'first push INSERT affected 1 row');

    $r = $db->execute('SELECT id FROM push_logs WHERE idempotency_key = :k', ['k' => $pushKey], false, true);
    if ($r) $artefacts['push_log_ids'][] = (int) $r['id'];

    $pinsert2 = $db->exec(
        "INSERT INTO `push_logs`
            (`unit_session_id`, `run_id`, `message`, `status`, `attempt`, `created`, `idempotency_key`)
         VALUES
            (:us, :rid, 'm', 'queued', 1, NOW(), :key)
         ON DUPLICATE KEY UPDATE `id` = `id`",
        ['us' => $unit_session_id, 'rid' => $artefacts['run_id'], 'key' => $pushKey]
    );
    assert_eq((int) $pinsert2, 0, 'second push INSERT (duplicate) is silent no-op (0 rows)');

    $count = (int) $db->execute('SELECT COUNT(*) FROM push_logs WHERE idempotency_key = :k', ['k' => $pushKey], true);
    assert_eq($count, 1, 'only one push_logs row exists for this idempotency_key');

    echo "\n-- Verify the actual Email::queueNow path also dedups end-to-end --\n";
    // Drive Email::queueNow against a fresh unit_session so the path
    // we shipped (not just the SQL idiom) round-trips correctly.
    $second_us = $db->insert('survey_unit_sessions', [
        'run_session_id' => $artefacts['rs_id'],
        'unit_id'        => $emailUnit,
        'created'        => mysql_now(),
    ]);
    $artefacts['unit_session_ids'][] = $second_us;

    $usObj = (new ReflectionClass('UnitSession'))->newInstanceWithoutConstructor();
    $usObj->id = $second_us;
    $emailObj = (new ReflectionClass('Email'))->newInstanceWithoutConstructor();
    $emailObj->id = $emailUnit;
    // Wire the protected db property + the recipient/account_id used by queueNow.
    foreach (['db' => $db, 'recipient' => 'r@example.com', 'account_id' => null, 'images' => []] as $prop => $val) {
        $rp = new ReflectionProperty('Email', $prop);
        $rp->setAccessible(true);
        $rp->setValue($emailObj, $val);
    }
    $accObj = (new ReflectionClass('EmailAccount'))->newInstanceWithoutConstructor();
    $queueNowMethod = (new ReflectionClass('Email'))->getMethod('queueNow');
    $queueNowMethod->setAccessible(true);

    $first  = $queueNowMethod->invoke($emailObj, $accObj, 'subj', 'body', $usObj);
    $second = $queueNowMethod->invoke($emailObj, $accObj, 'subj', 'body', $usObj);

    assert_eq((bool) $first,  true, 'Email::queueNow first call returns truthy');
    assert_eq((bool) $second, true, 'Email::queueNow second call returns truthy (idempotent no-op succeeds)');

    $key2 = "email:{$second_us}:{$emailUnit}";
    $count2 = (int) $db->execute('SELECT COUNT(*) FROM survey_email_log WHERE idempotency_key = :k', ['k' => $key2], true);
    assert_eq($count2, 1, 'Email::queueNow round-trip: only one survey_email_log row exists');
    $r = $db->execute('SELECT id FROM survey_email_log WHERE idempotency_key = :k', ['k' => $key2], false, true);
    if ($r) $artefacts['email_log_ids'][] = (int) $r['id'];

    echo "\n-- A9: PushNotificationService::logPushSuccess UPDATEs claim row (no duplicate audit row) --\n";
    // Seed a claim row, then call logPushSuccess via Reflection and
    // verify exactly ONE push_logs row remains for this key (the claim
    // row, mutated to status='success').
    $sid = $unit_session_id;  // re-use the unit_session from the email path
    $key = "push:{$sid}";
    // Wipe any prior rows for this key so the assertion is clean.
    $db->exec('DELETE FROM push_logs WHERE idempotency_key = :k', ['k' => $key]);
    $db->exec(
        "INSERT INTO `push_logs`
            (`unit_session_id`, `run_id`, `message`, `status`, `attempt`, `created`, `idempotency_key`)
         VALUES (:us, :rid, 'claim', 'queued', 1, NOW(), :k)",
        ['us' => $sid, 'rid' => $artefacts['run_id'], 'k' => $key]
    );

    $svc = (new ReflectionClass(PushNotificationService::class))->newInstanceWithoutConstructor();
    $rp = new ReflectionProperty(PushNotificationService::class, 'db');
    $rp->setAccessible(true); $rp->setValue($svc, $db);
    $runObj = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
    $runObj->id = $artefacts['run_id'];
    $rp = new ReflectionProperty(PushNotificationService::class, 'run');
    $rp->setAccessible(true); $rp->setValue($svc, $runObj);

    $m = new ReflectionMethod(PushNotificationService::class, 'logPushSuccess');
    $m->setAccessible(true);
    $m->invoke($svc, $sid, 'parsed message body');

    $count = (int) $db->execute('SELECT COUNT(*) FROM push_logs WHERE idempotency_key = :k', ['k' => $key], true);
    assert_eq($count, 1, 'logPushSuccess updates claim row in place — exactly one push_logs row for this key');
    $row = $db->execute('SELECT status, message FROM push_logs WHERE idempotency_key = :k', ['k' => $key], false, true);
    assert_eq($row['status'], 'success', 'claim row status flipped to success');
    assert_eq($row['message'], 'parsed message body', 'claim row message replaced with parsed body');
    // Track the row for teardown.
    $r = $db->execute('SELECT id FROM push_logs WHERE idempotency_key = :k', ['k' => $key], false, true);
    if ($r) $artefacts['push_log_ids'][] = (int) $r['id'];

    echo "\n-- A9: logPushFailure on a key with no claim row falls back to INSERT --\n";
    $orphan_sid = $unit_session_id + 9999;  // a sessionId that has no claim
    $orphan_key = "push:{$orphan_sid}";
    $db->exec('DELETE FROM push_logs WHERE idempotency_key = :k', ['k' => $orphan_key]);
    $countBefore = (int) $db->execute('SELECT COUNT(*) FROM push_logs WHERE idempotency_key = :k', ['k' => $orphan_key], true);
    // Need to pass a real unit_session_id (FK constraint) — use the
    // existing one but with a key that points at a phantom claim. The
    // INSERT path in upsertPushLog uses sessionId for both us_id and
    // the key; reset orphan_sid to use the real us_id so FK passes.
    $m = new ReflectionMethod(PushNotificationService::class, 'logPushFailure');
    $m->setAccessible(true);
    // Use an unmatched key indirectly by changing sessionId to a value
    // where no claim exists (use the second unit_session created
    // earlier).
    $m->invoke($svc, $second_us, 'msg', 'transient error', 1);
    $countAfter = (int) $db->execute('SELECT COUNT(*) FROM push_logs WHERE idempotency_key = :k', ['k' => "push:{$second_us}"], true);
    assert_eq($countAfter, 1, 'INSERT-fallback path created one row for the unclaimed sessionId');
    $r = $db->execute('SELECT id FROM push_logs WHERE idempotency_key = :k', ['k' => "push:{$second_us}"], false, true);
    if ($r) $artefacts['push_log_ids'][] = (int) $r['id'];

    echo "\n-- A8: Push terminal-result guard now returns end_session --\n";
    // Round-trip the actual code path: the v0.25.7 guard inside
    // PushMessage::getUnitSessionOutput must include 'end_session' so
    // executeUnitSession transitions the row's state to ENDED (not
    // strand it in PENDING). We probe the shape directly.
    $pushObj = (new ReflectionClass('PushMessage'))->newInstanceWithoutConstructor();
    $usObj   = (new ReflectionClass('UnitSession'))->newInstanceWithoutConstructor();
    $usObj->result = 'sent';
    $output = $pushObj->getUnitSessionOutput($usObj);
    assert_eq($output['end_session'] ?? false, true,  'Push terminal-result guard returns end_session=true');
    assert_eq($output['move_on']     ?? false, true,  'Push terminal-result guard returns move_on=true');

    echo "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    teardown($db, $artefacts);
    exit(2);
}

teardown($db, $artefacts);
echo $failures === 0 ? "\n\e[32mAll Track A idempotency smoke checks passed.\e[0m\n" : "\n\e[31m{$failures} failures.\e[0m\n";
exit($failures === 0 ? 0 : 1);
