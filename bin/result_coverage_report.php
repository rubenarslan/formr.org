<?php
/**
 * Coverage report for tests/fixtures/result_catalogue.php.
 *
 * The browser lane can't keep a running count the way the retired PHP harness
 * did — each spec is its own process. So specs deposit what they observed
 * (tests/e2e/helpers/results.js `recordObserved`) and this reconciles the
 * union against the catalogue. The catalogue stays the checklist; the number
 * is derived rather than maintained by hand.
 *
 * Reads no database and boots no app: the observations are already evidence
 * that a real traversal produced the row.
 *
 *   php bin/result_coverage_report.php            # summary + what's missing
 *   php bin/result_coverage_report.php --strict   # exit 1 if an `unreachable`
 *                                                 # entry was observed
 *
 * Run the browser lane first, or the report describes an empty run:
 *   npx playwright test --config tests/e2e/playwright.config.js result-coverage
 */

$root = dirname(__DIR__);
$catalogue = require $root . '/tests/fixtures/result_catalogue.php';
$observedPath = $root . '/tests/e2e/.observed-results.json';

$observed = [];
if (is_readable($observedPath)) {
    $observed = json_decode((string) file_get_contents($observedPath), true) ?: [];
}
if (!$observed) {
    fwrite(STDERR, "No observations at tests/e2e/.observed-results.json — run the browser lane first.\n");
}

/** An entry is covered when its name was seen, or when its pattern matched. */
$isCovered = function ($name, array $entry) use ($observed) {
    if (isset($observed[$name])) {
        return true;
    }
    if (!empty($entry['pattern'])) {
        foreach (array_keys($observed) as $seen) {
            if (preg_match($entry['pattern'], $seen)) {
                return true;
            }
        }
    }
    return false;
};

$byReach = [];
$violations = [];
foreach ($catalogue as $name => $entry) {
    $reach = $entry['reach'];
    $covered = $isCovered($name, $entry);
    $byReach[$reach]['total'] = ($byReach[$reach]['total'] ?? 0) + 1;
    $byReach[$reach]['covered'] = ($byReach[$reach]['covered'] ?? 0) + ($covered ? 1 : 0);
    if (!$covered) {
        $byReach[$reach]['missing'][] = $name;
    }
    // An `unreachable` or `dead` entry that shows up is the interesting
    // failure: the audit claimed it cannot be persisted, and something now
    // persists it. That is a finding, not a coverage win.
    if ($covered && in_array($reach, ['unreachable', 'dead'], true)) {
        $violations[] = $name . ' — ' . ($entry['why'] ?? 'declared unreachable');
    }
}

$reachable = ['web', 'cron', 'smtp', 'admin'];
$totalReachable = 0;
$totalCovered = 0;
foreach ($reachable as $reach) {
    $totalReachable += $byReach[$reach]['total'] ?? 0;
    $totalCovered += $byReach[$reach]['covered'] ?? 0;
}

echo "Result coverage — " . $totalCovered . "/" . $totalReachable . " reachable catalogue entries observed\n\n";
foreach ($reachable as $reach) {
    if (empty($byReach[$reach]['total'])) {
        continue;
    }
    printf("  %-6s %2d/%2d\n", $reach, $byReach[$reach]['covered'] ?? 0, $byReach[$reach]['total']);
    foreach ($byReach[$reach]['missing'] ?? [] as $name) {
        echo "         · " . $name . "\n";
    }
}

echo "\n";
$declaredUnreachable = ($byReach['unreachable']['total'] ?? 0) + ($byReach['dead']['total'] ?? 0);
if ($violations) {
    echo "PERSISTED DESPITE BEING DECLARED UNREACHABLE (" . count($violations) . "):\n";
    foreach ($violations as $v) {
        echo "  ! " . $v . "\n";
    }
    echo "\nEither the audit finding was wrong or a change made it reachable.\n"
       . "Update tests/fixtures/result_catalogue.php and the audit together.\n";
} else {
    echo "0 of " . $declaredUnreachable . " unreachable/dead entries were persisted.\n";
}

$strict = in_array('--strict', $argv, true);
exit($strict && $violations ? 1 : 0);
