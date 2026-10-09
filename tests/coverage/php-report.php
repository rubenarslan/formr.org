<?php
// Merge the PHP lanes of the coverage pass into one report.
//
//   docker exec -e XDEBUG_MODE=coverage formr_app \
//       php /var/www/formr/tests/coverage/php-report.php [/var/www/formr/.coverage]
//
// Reads <dir>/php/*.json (per-lane executed lines written by prepend.php) and
// <dir>/php/unit.cov (PHPUnit --coverage-php). Writes:
//   <dir>/php-summary.txt  text report, per class
//   <dir>/php-html/        HTML report
//   <dir>/php-methods.tsv  one row per method/function: file, name, start
//                          line, executable/executed lines, lanes that ran it
//   <dir>/php-uncovered.tsv  per loaded file: runs of lines no lane ran
// Scope: application/, templates/, setup.php, webroot/index.php. Xdebug must
// be in coverage mode (the report library insists on a driver).

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as HtmlReport;
use SebastianBergmann\CodeCoverage\Report\Text as TextReport;
use SebastianBergmann\CodeCoverage\Report\Thresholds;

ini_set('display_errors', 'stderr');
ini_set('memory_limit', '2G');
$root = dirname(__DIR__, 2) . '/';
require $root . 'vendor/autoload.php';
$out = rtrim($argv[1] ?? $root . '.coverage', '/');
$in = $out . '/php';

// file => [line => true] per lane.
$lanes = [];
foreach (glob($in . '/*.json') as $f) {
    $lanes[basename($f, '.json')] = json_decode((string) file_get_contents($f), true) ?: [];
}
if (is_file($in . '/unit.cov')) {
    $unit = include $in . '/unit.cov';
    foreach ($unit->getData()->lineCoverage() as $file => $lines) {
        foreach ($lines as $line => $tests) {
            if ($tests) $lanes['unit'][$file][$line] = 1;
        }
    }
}
if (!$lanes) {
    fwrite(STDERR, "no coverage data under $in\n");
    exit(1);
}

$files = [];
$dirs = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . 'application', FilesystemIterator::SKIP_DOTS));
foreach ($dirs as $f) if ($f->getExtension() === 'php') $files[] = $f->getPathname();
$dirs = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . 'templates', FilesystemIterator::SKIP_DOTS));
foreach ($dirs as $f) if ($f->getExtension() === 'php') $files[] = $f->getPathname();
$files[] = $root . 'setup.php';
$files[] = $root . 'webroot/index.php';
$inScope = array_flip($files);

$union = [];
foreach ($lanes as $data) {
    foreach ($data as $file => $lines) {
        if (!isset($inScope[$file])) continue;
        foreach ($lines as $line => $_) $union[$file][(int) $line] = 1;
    }
}

// The lanes record executed lines only, and the report library takes the
// lines it is handed as the file's executable lines — every loaded file
// would read 100%. Mark the rest of each file's executable lines (its own
// static analysis) as not executed.
$analyser = new SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingFileAnalyser(true, false);
foreach ($union as $file => $lines) {
    foreach (array_keys($analyser->executableLinesIn($file)) as $line) {
        if (!isset($union[$file][$line])) $union[$file][$line] = -1;
    }
}

$filter = new Filter();
$filter->includeFiles($files);
$coverage = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
$coverage->includeUncoveredFiles();
$coverage->append(RawCodeCoverageData::fromXdebugWithoutPathCoverage($union), 'all');

file_put_contents($out . '/php-summary.txt', (new TextReport(Thresholds::default(), true, false))->process($coverage, false));
(new HtmlReport())->process($coverage, $out . '/php-html/');

$rows = ["file\tname\tline\texecutable\texecuted\tlanes"];
$ran = static function ($file, $start, $end) use ($lanes) {
    $hit = [];
    foreach ($lanes as $label => $data) {
        foreach ($data[$file] ?? [] as $line => $_) {
            if ($line >= $start && $line <= $end) { $hit[] = $label; break; }
        }
    }
    return implode(',', $hit);
};
// The report node iterates its whole tree.
foreach ($coverage->getReport() as $node) {
    if (!$node instanceof SebastianBergmann\CodeCoverage\Node\File) continue;
    $file = $node->pathAsString();
    $rel = substr($file, strlen($root));
    foreach ([$node->classes(), $node->traits()] as $units) {
        foreach ($units as $class => $info) {
            foreach ($info['methods'] as $name => $m) {
                if ($m['executableLines'] === 0) continue;
                $rows[] = implode("\t", [$rel, $class . '::' . $name, $m['startLine'], $m['executableLines'], $m['executedLines'], $ran($file, $m['startLine'], $m['endLine'])]);
            }
        }
    }
    foreach ($node->functions() as $name => $fn) {
        if ($fn['executableLines'] === 0) continue;
        $rows[] = implode("\t", [$rel, $name, $fn['startLine'], $fn['executableLines'], $fn['executedLines'], $ran($file, $fn['startLine'], $fn['endLine'])]);
    }
}
file_put_contents($out . '/php-methods.tsv', implode("\n", $rows) . "\n");

// Runs of executable lines no lane ran, per file.
$gaps = ["file\tuncovered_lines"];
ksort($union);
foreach ($union as $file => $lines) {
    ksort($lines);
    $runs = [];
    foreach ($lines as $line => $hit) {
        if ($hit !== -1) continue;
        $last = count($runs) - 1;
        if ($last >= 0 && $runs[$last][1] === $line - 1) $runs[$last][1] = $line; else $runs[] = [$line, $line];
    }
    if ($runs) {
        $gaps[] = substr($file, strlen($root)) . "\t" . implode(',', array_map(static fn ($r) => $r[0] === $r[1] ? "$r[0]" : "$r[0]-$r[1]", $runs));
    }
}
file_put_contents($out . '/php-uncovered.tsv', implode("\n", $gaps) . "\n");
echo "lanes: " . implode(', ', array_keys($lanes)) . "\nwrote $out/php-summary.txt, php-html/, php-methods.tsv, php-uncovered.tsv\n";
