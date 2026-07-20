#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for DB::batchUpdateByKey (review 2026-07 cleanup).
 * Guards the helper that replaced the three hand-rolled CASE/IN builders,
 * on a throwaway table so no real (hot-path) data is at risk.
 *
 * Asserts: per-row CASE values, NULL three-state preserved, a constant bound
 * SET applied to all matched rows, raw COALESCE fragments, WHERE scoping
 * (a non-matching scope updates nothing), and rows outside the key list left
 * untouched.
 *
 * Usage:  docker exec formr_app php bin/test_batch_update_smoke.php
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

try {
    $db->exec("DROP TABLE IF EXISTS zz_batch_update");
    $db->exec("CREATE TABLE zz_batch_update (
        item_id INT NOT NULL, session_id INT NOT NULL,
        answer VARCHAR(255) NULL, hidden TINYINT NULL,
        saved DATETIME NULL, created DATETIME NULL, displaycount INT NULL,
        PRIMARY KEY (item_id, session_id)) ENGINE=InnoDB");
    // session 1: three rows; session 2: one row (scope guard)
    $db->exec("INSERT INTO zz_batch_update (item_id, session_id, answer, hidden, created, displaycount) VALUES
        (10, 1, 'old', 1, '2020-01-01 00:00:00', 5),
        (11, 1, 'old', 1, NULL, NULL),
        (12, 1, 'old', 0, NULL, NULL),
        (10, 2, 'keep', 1, NULL, NULL)");

    $rows = array(
        10 => array('answer' => 'A10', 'hidden' => 1),
        11 => array('answer' => 'A11', 'hidden' => null),  // NULL must persist
        12 => array('answer' => null,  'hidden' => 0),      // NULL answer
    );
    $affected = $db->batchUpdateByKey('zz_batch_update', 'item_id', $rows,
        array('saved' => '2026-07-20 12:00:00'),
        array('session_id' => 1),
        array('created = COALESCE(created, NOW())', 'displaycount = COALESCE(displaycount, 1)')
    );

    $get = function ($item, $sess) use ($db) {
        return $db->execute("SELECT * FROM zz_batch_update WHERE item_id = :i AND session_id = :s",
            ['i' => $item, 's' => $sess], false, true);
    };

    echo "== per-row CASE values + NULL three-state ==\n";
    $r10 = $get(10, 1); $r11 = $get(11, 1); $r12 = $get(12, 1);
    ok($r10['answer'] === 'A10' && (int) $r10['hidden'] === 1, "row 10 set to (A10, hidden 1)");
    ok($r11['answer'] === 'A11' && $r11['hidden'] === null, "row 11 hidden preserved as SQL NULL");
    ok($r12['answer'] === null && (int) $r12['hidden'] === 0, "row 12 answer set to SQL NULL");

    echo "\n== constant bound SET + raw COALESCE fragments ==\n";
    ok($r10['saved'] === '2026-07-20 12:00:00' && $r11['saved'] === '2026-07-20 12:00:00',
        "constant `saved` applied to every matched row");
    ok($r10['created'] === '2020-01-01 00:00:00', "COALESCE keeps an existing created (row 10)");
    ok($r11['created'] !== null && (int) $r11['displaycount'] === 1,
        "COALESCE fills a NULL created/displaycount (row 11)");

    echo "\n== WHERE scoping ==\n";
    $r10s2 = $get(10, 2);
    ok($r10s2['answer'] === 'keep' && $r10s2['saved'] === null,
        "row in session 2 (outside whereEq) untouched");
    ok((int) $affected === 3, "affected rows = 3 (session 1 only)");

    echo "\n== a non-matching scope updates nothing ==\n";
    $n = $db->batchUpdateByKey('zz_batch_update', 'item_id',
        array(10 => array('answer' => 'X')), array(), array('session_id' => 999));
    ok((int) $n === 0, "no rows matched (session 999)");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    $failures++;
} finally {
    $db->exec("DROP TABLE IF EXISTS zz_batch_update");
}

echo "\n" . ($failures === 0 ? "\e[32mALL PASSED\e[0m\n" : "\e[31m{$failures} FAILURE(S)\e[0m\n");
exit($failures === 0 ? 0 : 1);
