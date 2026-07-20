#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for Run::reorder() under the patch-064 UNIQUE
 * (run_id, position) key. The SQLite unit lane can't host this — it doesn't
 * enforce the per-row UNIQUE-during-statement semantics that make a naive
 * reorder fail. See documentation/agent_doc/run_engine_audit_2026-07.md (F10).
 *
 * Asserts the two behaviours the review flagged as broken:
 *   A. A permutation that transiently reuses a live position (a swap, a
 *      rotation) must SUCCEED — InnoDB checks UNIQUE per row within a
 *      statement, so a one-row-at-a-time reorder throws 23000 on the swap.
 *   B. A batch that includes a unit at position 0 (or any negative position)
 *      must SUCCEED — position 0 is a valid slot (audit F12), so reorder must
 *      not reject it.
 *
 * Usage:  docker exec formr_app php bin/test_reorder_smoke.php
 * Exits 0 on success. Creates a throwaway run + unit-placements (unit_id NULL,
 * no real units touched) and deletes them in a finally block.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

$user_id = (int) $db->execute("SELECT id FROM survey_users ORDER BY id LIMIT 1", array(), true);
if (!$user_id) { fwrite(STDERR, "no user to own the test run\n"); exit(1); }

$run_id = null;
try {
    $run_name = 'zzreorder' . getmypid();
    $db->exec(
        "INSERT INTO survey_runs (user_id, created, modified, name, public, cron_active)
         VALUES (:uid, NOW(), NOW(), :name, 0, 0)",
        array('uid' => $user_id, 'name' => $run_name)
    );
    $run_id = (int) $db->lastInsertId();

    // Four placements at consecutive positions 1..4 (all positive, so case A
    // isolates the UNIQUE-collision behaviour from the position-0 behaviour).
    // unit_id NULL keeps this independent of any real survey_units row.
    $seed = array(1, 2, 3, 4); // position by insert order
    $ruids = array();
    foreach ($seed as $pos) {
        $db->exec(
            "INSERT INTO survey_run_units (run_id, unit_id, position) VALUES (:rid, NULL, :pos)",
            array('rid' => $run_id, 'pos' => $pos)
        );
        $ruids[$pos] = (int) $db->lastInsertId(); // $ruids[seed position] => run_unit_id
    }

    $positionsOf = function () use ($db, $run_id) {
        $rows = $db->execute(
            "SELECT id, position FROM survey_run_units WHERE run_id = :rid ORDER BY position",
            array('rid' => $run_id)
        );
        $map = array();
        foreach ($rows as $r) { $map[(int) $r['id']] = (int) $r['position']; }
        return $map;
    };

    $run = new Run(null, $run_id);
    ok($run->valid, "test run {$run_id} loaded");

    // ── A: swap two adjacent units (pos 1 <-> pos 2) — transient duplicate ───
    echo "\n== A: swap under UNIQUE(run_id, position) ==\n";
    // full-batch POST like the editor sends: every unit's position, with the
    // units at positions 1 and 2 exchanged. All targets positive, so the only
    // thing that can fail here is the transient UNIQUE collision.
    $swap = array(
        $ruids[1] => 2, // was 1
        $ruids[2] => 1, // was 2
        $ruids[3] => 3,
        $ruids[4] => 4,
    );
    $okSwap = $run->reorder($swap);
    ok($okSwap === true, "reorder() swap returned true (errors: " . implode('; ', $run->errors) . ")");
    $after = $positionsOf();
    ok(($after[$ruids[1]] ?? null) === 2 && ($after[$ruids[2]] ?? null) === 1,
        "swap persisted: unit A->2, unit B->1");

    // ── B: a batch that includes a unit at position 0 ───────────────────────
    echo "\n== B: position 0 is allowed in a reorder batch ==\n";
    $run2 = new Run(null, $run_id);
    // rotate down by one so the lead unit lands on position 0
    $rot = array(
        $ruids[1] => 0, // lands on position 0
        $ruids[2] => 1,
        $ruids[3] => 2,
        $ruids[4] => 3,
    );
    $okZero = $run2->reorder($rot);
    ok($okZero === true, "reorder() with a position-0 target returned true (errors: " . implode('; ', $run2->errors) . ")");
    $afterB = $positionsOf();
    ok(($afterB[$ruids[1]] ?? null) === 0, "unit landed at position 0");

    // ── C: a genuinely duplicate batch is still rejected ─────────────────────
    echo "\n== C: duplicate positions in one batch are rejected ==\n";
    $run3 = new Run(null, $run_id);
    $dupe = array($ruids[1] => 5, $ruids[2] => 5, $ruids[3] => 6, $ruids[4] => 7);
    $okDupe = $run3->reorder($dupe);
    ok($okDupe === false, "reorder() rejects duplicate positions");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    $failures++;
} finally {
    if ($run_id) {
        $db->exec("DELETE FROM survey_run_units WHERE run_id = :rid", array('rid' => $run_id));
        $db->exec("DELETE FROM survey_runs WHERE id = :rid", array('rid' => $run_id));
    }
}

echo "\n" . ($failures === 0 ? "\e[32mALL PASSED\e[0m\n" : "\e[31m{$failures} FAILURE(S)\e[0m\n");
exit($failures === 0 ? 0 : 1);
