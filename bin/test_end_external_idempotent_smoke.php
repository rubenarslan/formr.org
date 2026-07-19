#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for the V1 end_external API contract (review 2026-07,
 * item 16).
 *
 * External services retry callbacks and deliver them late — a repeat
 * end_external after the unit already ended must stay a SUCCESS no-op (as it
 * was before audit F20 made endLastExternal honest about 0-row updates), not
 * flip to 400 and trip third-party retry/alert handlers. F20's actual fix
 * (end only the NEWEST live External, by id) is kept and asserted here.
 *
 * Drives the real SessionResource::performSessionAction via a test subclass
 * that supplies the JSON body (php://input can't be faked under CLI).
 *
 * Asserts:
 *  A. one live External -> 200 and the row is ended (F20 behaviour intact);
 *  B. repeat call (nothing live) -> 200 idempotent no-op (red pre-fix: 400).
 *
 * Usage:  docker exec formr_app php bin/test_end_external_idempotent_smoke.php
 */
require_once dirname(__FILE__) . '/../setup.php';

class SmokeSessionResource extends SessionResource {
    protected function getJsonBody() {
        return ['action' => 'end_external'];
    }
}

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

$uid = null; $run_id = null; $unit_id = null; $rs_id = null; $us_id = null;
try {
    $email = 'zzext' . getmypid() . '@example.invalid';
    // admin level 2 so ApiBase's canAccessApi() use-time gate admits the
    // smoke's token user (the constructor hydrates the user by email)
    $db->exec("INSERT INTO survey_users (user_code, email, created, admin) VALUES (:c, :e, NOW(), 2)",
        ['c' => bin2hex(random_bytes(24)), 'e' => $email]);
    $uid = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_runs (user_id, created, modified, name, public, cron_active)
               VALUES (:u, NOW(), NOW(), :n, 0, 0)", ['u' => $uid, 'n' => 'zzext' . getmypid()]);
    $run_id = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_units (type, created, modified) VALUES ('External', NOW(), NOW())");
    $unit_id = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_externals (id) VALUES (:id)", ['id' => $unit_id]);
    $db->exec("INSERT INTO survey_run_units (run_id, unit_id, position) VALUES (:r, :u, 1)",
        ['r' => $run_id, 'u' => $unit_id]);
    $session = bin2hex(random_bytes(32));
    $db->exec("INSERT INTO survey_run_sessions (run_id, session, created) VALUES (:r, :s, NOW())",
        ['r' => $run_id, 's' => $session]);
    $rs_id = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_unit_sessions (unit_id, run_session_id, created) VALUES (:u, :rs, NOW())",
        ['u' => $unit_id, 'rs' => $rs_id]);
    $us_id = (int) $db->lastInsertId();

    $run = new Run(null, $run_id);
    $runSession = new RunSession($session, $run, ['user' => new User(null, null, ['cron' => true])]);

    $callEndExternal = function () use ($db, $runSession, $email) {
        $res = new SmokeSessionResource(new Request(), $db, ['scope' => 'session:write', 'user_id' => $email]);
        $m = new ReflectionMethod(SessionResource::class, 'performSessionAction');
        $m->setAccessible(true);
        $m->invoke($res, $runSession);
        return $res->getData();
    };

    echo "== A: first callback ends the live External (F20 intact) ==\n";
    $data = $callEndExternal();
    ok((int) ($data['statusCode'] ?? 0) === 200, "first call -> 200 (got " . ($data['statusCode'] ?? '?') . ")");
    $ended = $db->execute("SELECT ended FROM survey_unit_sessions WHERE id = :id", ['id' => $us_id], true);
    ok($ended !== null && $ended !== false, "the External unit session is ended");

    echo "\n== B: repeat/late callback is an idempotent success ==\n";
    $data2 = $callEndExternal();
    ok((int) ($data2['statusCode'] ?? 0) === 200,
        "repeat call -> 200 no-op, not 400 (got " . ($data2['statusCode'] ?? '?') . ")");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    $failures++;
} finally {
    if ($rs_id) {
        $db->exec("DELETE FROM survey_unit_sessions WHERE run_session_id = :id", ['id' => $rs_id]);
        $db->exec("DELETE FROM survey_run_sessions WHERE id = :id", ['id' => $rs_id]);
    }
    if ($run_id) {
        $db->exec("DELETE FROM survey_run_units WHERE run_id = :id", ['id' => $run_id]);
        $db->exec("DELETE FROM survey_run_metrics WHERE run_id = :id", ['id' => $run_id]);
    }
    if ($unit_id) {
        $db->exec("DELETE FROM survey_externals WHERE id = :id", ['id' => $unit_id]);
        $db->exec("DELETE FROM survey_units WHERE id = :id", ['id' => $unit_id]);
    }
    if ($run_id) {
        $db->exec("DELETE FROM survey_runs WHERE id = :id", ['id' => $run_id]);
    }
    if ($uid) {
        $db->exec("DELETE FROM survey_users WHERE id = :id", ['id' => $uid]);
    }
}

echo "\n" . ($failures === 0 ? "\e[32mALL PASSED\e[0m\n" : "\e[31m{$failures} FAILURE(S)\e[0m\n");
exit($failures === 0 ? 0 : 1);
