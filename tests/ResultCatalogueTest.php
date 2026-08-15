<?php

/**
 * CI guard for tests/fixtures/result_catalogue.php.
 *
 * The catalogue is the declared inventory of every value that can land in
 * `survey_unit_sessions.result`, and it is the checklist the browser
 * coverage lane works through (tests/e2e/result-coverage-*.spec.js, scored
 * by bin/result_coverage_report.php). An inventory nobody checks rots
 * within a release, so this scans the source and fails when the two
 * disagree in either direction:
 *
 *   - a result literal exists in the code but not in the catalogue — a new
 *     outcome shipped without deciding how it is reached or tested;
 *   - a catalogue entry no longer appears in the code — a renamed or
 *     deleted result whose entry is now a lie.
 *
 * Deliberately grep-based rather than DB-driven: it has to run in the fast
 * unit lane, and the question it answers ("does the source still say what
 * the catalogue claims?") is a source question.
 */
class ResultCatalogueTest extends \PHPUnit\Framework\TestCase
{
    private const APP = __DIR__ . '/../application';

    private function catalogue(): array
    {
        return require __DIR__ . '/fixtures/result_catalogue.php';
    }

    /** Every result literal the source can write, scraped from the code. */
    private function literalsInSource(): array
    {
        $found = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::APP));
        foreach ($rii as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());

            // getLogMessage('x'), 'result' => 'x', $this->result = 'x',
            // COALESCE(`result`, 'x'), logResult(..., "x")
            $patterns = [
                "/getLogMessage\(\s*'([a-z_0-9]+)'/",
                "/'result'\s*=>\s*'([a-z_0-9]+)'/",
                "/->result\s*=\s*'([a-z_0-9]+)'/",
                "/COALESCE\(\s*`?(?:us\.)?result`?\s*,\s*'([a-z_0-9]+)'/i",
                "/logResult\([^;]*?,\s*\"([a-z_0-9]+)\"/",
            ];
            foreach ($patterns as $re) {
                if (preg_match_all($re, $src, $m)) {
                    foreach ($m[1] as $hit) {
                        $found[$hit] = true;
                    }
                }
            }
        }

        // Shuffle writes group_1..group_N; the catalogue carries one
        // pattern entry for the family.
        foreach (array_keys($found) as $k) {
            if (preg_match('/^group_/', $k)) {
                unset($found[$k]);
                $found['group_N'] = true;
            }
        }

        return array_keys($found);
    }

    public function testEveryResultInTheSourceIsCatalogued(): void
    {
        $catalogue = $this->catalogue();
        $missing = array_diff($this->literalsInSource(), array_keys($catalogue));

        $this->assertSame([], array_values($missing),
            "These result values are written by the code but absent from "
            . "tests/fixtures/result_catalogue.php. Add them, with how they "
            . "are reached, and add a tests/e2e/result-coverage-*.spec.js "
            . "scenario if they are reachable: " . implode(', ', $missing));
    }

    /**
     * Reverse check. Deliberately a plain quoted-literal search rather than
     * the structured patterns above: results reach the row as ternaries
     * ("survey_ended" : "external_ended"), as SQL string literals inside an
     * UPDATE, and as call arguments (end('moved')). Enumerating those forms
     * precisely is how the forward check earns its keep; for "is this name
     * still referenced at all", the loose search is the correct instrument
     * and cannot produce a false failure.
     */
    public function testEveryCataloguedResultStillExistsInTheSource(): void
    {
        $haystack = '';
        foreach ([self::APP, __DIR__ . '/../bin'] as $root) {
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($rii as $file) {
                if (!$file->isDir() && $file->getExtension() === 'php') {
                    $haystack .= file_get_contents($file->getPathname());
                }
            }
        }

        // group_N is a family (group_1..group_N), matched by its prefix.
        $stale = [];
        foreach (array_keys($this->catalogue()) as $result) {
            $needle = $result === 'group_N' ? "'group_' ." : "'{$result}'";
            if (strpos($haystack, $needle) === false
                && strpos($haystack, '"' . $result . '"') === false) {
                $stale[] = $result;
            }
        }

        $this->assertSame([], array_values($stale),
            "These catalogue entries no longer appear in the source — the "
            . "result was renamed or removed and the catalogue is now wrong: "
            . implode(', ', $stale));
    }

    /** Each entry must say enough to be actionable. */
    public function testEveryEntryDeclaresAUnitAndAReachability(): void
    {
        $valid = ['web', 'cron', 'smtp', 'admin', 'unreachable', 'dead'];
        foreach ($this->catalogue() as $result => $meta) {
            $this->assertArrayHasKey('unit', $meta, "$result: no owning unit");
            $this->assertContains($meta['reach'] ?? null, $valid, "$result: bad reach");

            if (in_array($meta['reach'], ['unreachable', 'dead'], true)) {
                $this->assertNotEmpty($meta['why'] ?? null,
                    "$result is declared {$meta['reach']} but gives no reason. "
                    . "An unreachable result is a claim about the code; it has "
                    . "to say why so it can be re-checked.");
            } else {
                $this->assertNotEmpty($meta['how'] ?? null,
                    "$result is declared reachable but does not say how to trigger it");
            }
        }
    }
}
