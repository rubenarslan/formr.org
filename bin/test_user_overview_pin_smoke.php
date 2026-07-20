#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for the admin-session pin in getUserOverviewTable
 * (review 2026-07, item 21a).
 *
 * The slow-query work replaced an unindexable `ORDER BY session != admin_code`
 * global sort with an indexed `last_access DESC` + LIMIT, then pinned in PHP
 * within the fetched page — so the admin's own test session vanished from
 * page 1 when its last_access was older than the page's worth of newer
 * sessions. The pin is now restored globally via a cheap indexed point-lookup.
 *
 * Asserts: with the admin session's last_access OLDER than a full page of
 * other sessions, it is still row 1 of page 1 (red pre-fix: absent).
 *
 * Usage:  docker exec formr_app php bin/test_user_overview_pin_smoke.php
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

$uid = null; $run_id = null; $rs_ids = [];
$PAGE = 200;
try {
    $db->exec("INSERT INTO survey_users (user_code, email, created) VALUES (:c, :e, NOW())",
        ['c' => bin2hex(random_bytes(24)), 'e' => 'zzpin' . getmypid() . '@example.invalid']);
    $uid = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_runs (user_id, created, modified, name, public, cron_active)
               VALUES (:u, NOW(), NOW(), :n, 0, 0)", ['u' => $uid, 'n' => 'zzpin' . getmypid()]);
    $run_id = (int) $db->lastInsertId();

    $adminCode = 'ADMINCODE' . getmypid();
    // admin's own session: last_access far in the PAST
    $db->exec("INSERT INTO survey_run_sessions (run_id, session, created, last_access, position)
               VALUES (:r, :s, NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 10 DAY, 1)",
        ['r' => $run_id, 's' => $adminCode]);
    $rs_ids[] = (int) $db->lastInsertId();

    // a full page + a few of newer participant sessions
    for ($i = 0; $i < $PAGE + 5; $i++) {
        $db->exec("INSERT INTO survey_run_sessions (run_id, session, created, last_access, position)
                   VALUES (:r, :s, NOW(), NOW() - INTERVAL :m MINUTE, 1)",
            ['r' => $run_id, 's' => bin2hex(random_bytes(32)), 'm' => $i]);
        $rs_ids[] = (int) $db->lastInsertId();
    }

    $helper = new RunHelper(new Run(null, $run_id), $db, new Request());

    echo "== page 1: admin session pinned to the top despite old last_access ==\n";
    $table = $helper->getUserOverviewTable(['run_id' => $run_id, 'admin_code' => $adminCode]);
    $rows = $table['data'];
    ok(!empty($rows) && $rows[0]['session'] === $adminCode,
        "admin session is row 1 of page 1 (got " . (empty($rows) ? 'no rows' : substr($rows[0]['session'], 0, 12)) . ")");
    // present exactly once
    $count = 0;
    foreach ($rows as $r) { if ($r['session'] === $adminCode) { $count++; } }
    ok($count === 1, "admin session appears exactly once on the page (got {$count})");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    $failures++;
} finally {
    foreach ($rs_ids as $rs) {
        $db->exec("DELETE FROM survey_run_sessions WHERE id = :id", ['id' => $rs]);
    }
    if ($run_id) {
        $db->exec("DELETE FROM survey_run_metrics WHERE run_id = :id", ['id' => $run_id]);
        $db->exec("DELETE FROM survey_runs WHERE id = :id", ['id' => $run_id]);
    }
    if ($uid) {
        $db->exec("DELETE FROM survey_users WHERE id = :id", ['id' => $uid]);
    }
}

echo "\n" . ($failures === 0 ? "\e[32mALL PASSED\e[0m\n" : "\e[31m{$failures} FAILURE(S)\e[0m\n");
exit($failures === 0 ? 0 : 1);
