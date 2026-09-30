<?php

/**
 * FormV2CompatScanner::scan() over a study's item rows (SQLite stand-in for
 * survey_items): which bucket each showif and value lands in, what gets
 * flagged with which fix, and that soft-deleted rows are neither counted nor
 * flagged nor treated as names the form knows. Plus each R-token check of the
 * needs-rewrite heuristic on its own (detectRTokens), with the string-literal
 * guard that keeps labels from false-positiving.
 */
class FormV2CompatScannerScanTest extends \PHPUnit\Framework\TestCase {

    private const STUDY_ID = 31;

    /** @var DB */
    private $db;

    protected function setUp(): void {
        $this->db = DB::getInstance();
        $this->db->exec('DROP TABLE IF EXISTS survey_items');
        $this->db->exec('CREATE TABLE survey_items (
            id INTEGER PRIMARY KEY, study_id INTEGER, name TEXT, type TEXT,
            choice_list TEXT, type_options TEXT, label TEXT, label_parsed TEXT,
            optional INTEGER DEFAULT 1, class TEXT, showif TEXT,
            value TEXT, "order" INTEGER, item_order INTEGER, block_order TEXT,
            deleted TEXT DEFAULT NULL)');
    }

    protected function tearDown(): void {
        $this->db->exec('DROP TABLE IF EXISTS survey_items');
    }

    private function seed(array $rows, $studyId = self::STUDY_ID): void {
        $stmt = $this->db->pdo()->prepare('INSERT INTO survey_items (study_id, name, type, showif, value, "order", deleted)
            VALUES (:study, :name, :type, :showif, :value, :ord, :deleted)');
        foreach (array_values($rows) as $i => $r) {
            $stmt->execute(array(
                ':study' => $studyId, ':name' => $r[0], ':type' => $r[1],
                ':showif' => $r[2] ?? null, ':value' => $r[3] ?? null,
                ':ord' => $i + 1, ':deleted' => !empty($r[4]) ? '2026-09-01 10:00:00' : null,
            ));
        }
    }

    /** @return array<string, array> flagged rows by "name/column" */
    private function flaggedBy(array $report): array {
        $out = array();
        foreach ($report['flagged'] as $f) {
            $out[$f['name'] . '/' . $f['column']] = $f;
        }
        return $out;
    }

    public function testShowifBucketsAndFixes() {
        $this->seed(array(
            array('q1', 'text'),
            array('ok_js', 'text', 'q1 == "a"'),
            array('ok_isna', 'text', '!isNA(q1)'),
            array('run_var', 'text', 'ran_group == 2'),              // not an item of the form
            array('to_gone', 'text', 'gone == 1'),                   // names a soft-deleted item
            array('r_tail', 'text', 'tail(q1, 2) == "a"'),
            array('gone', 'text', 'survey$q1 == 1', 'paste(1)', true),
        ));
        $report = FormV2CompatScanner::scan(self::STUDY_ID);
        $this->assertSame(array('empty' => 1, 'js_ok' => 2, 'needs_js_rewrite' => 1, 'not_on_form' => 2), $report['counts']['showif']);
        $flagged = $this->flaggedBy($report);
        $this->assertSame(array('run_var/showif', 'to_gone/showif', 'r_tail/showif'), array_keys($flagged));
        $this->assertSame('bridge_hidden_item', $flagged['run_var/showif']['suggested_fix']);
        $this->assertSame(array('not an item of this form: ran_group'), $flagged['run_var/showif']['problems']);
        $this->assertSame(array('not an item of this form: gone'), $flagged['to_gone/showif']['problems'], 'a soft-deleted item is not a name the form knows');
        $this->assertSame('rewrite_in_js', $flagged['r_tail/showif']['suggested_fix']);
        $this->assertSame(array('R-only function call'), $flagged['r_tail/showif']['problems']);
        $this->assertSame('tail(q1, 2) == "a"', $flagged['r_tail/showif']['source']);
    }

    public function testValueBucketsSkipDeletedRowsAndOtherStudies() {
        $this->seed(array(
            array('n_int', 'hidden', null, '42'),
            array('n_dec', 'hidden', null, ' 3.5 '),
            array('r_expr', 'hidden', null, 'q1 + 1'),
            array('r_sticky', 'text', null, 'sticky'),
            array('blank', 'text', null, ''),
            array('gone_val', 'hidden', null, '7', true),
        ));
        $this->seed(array(array('elsewhere', 'hidden', 'survey$x', 'paste(1)')), self::STUDY_ID + 1);
        $report = FormV2CompatScanner::scan(self::STUDY_ID);
        $this->assertSame(array('empty' => 1, 'literal' => 2, 'r' => 2), $report['counts']['value'],
            'a numeric value is a literal, anything else non-empty is R; the deleted row is not counted');
        $this->assertSame(5, $report['counts']['showif']['empty'], 'deleted rows and other studies are not counted at all');
        $this->assertSame(array(), $report['flagged'], 'values are never flagged');
    }

    public function testUnsupportedItemsAreFlaggedWithTheHiddenFix() {
        $this->seed(array(
            array('q1', 'text'),
            array('score', 'calculate', null, 'nchar(q1)'),
            array('old_score', 'calculate', null, 'nchar(q1)', true),
        ));
        $report = FormV2CompatScanner::scan(self::STUDY_ID);
        $this->assertSame(1, $report['counts']['unsupported'], 'the soft-deleted calculate does not block the switch');
        $flagged = $this->flaggedBy($report);
        $this->assertSame(array('score/type'), array_keys($flagged));
        $this->assertSame('use_hidden', $flagged['score/type']['suggested_fix']);
    }

    public function testEachRTokenIsReportedOnItsOwn() {
        $cases = array(
            'tail(q1, 1) > 1' => 'R-only function call',
            'head(q1, 2) == 1' => 'R-only function call',
            'current(q1) == 1' => 'R-only function call',
            'is.na(q1 + 1)' => 'is.na() (use isNA())',
            'q1 == 1 & q2 == 2' => 'R single & / | (use && / ||)',
            'q1 == 1 | q2 == 2' => 'R single & / | (use && / ||)',
            'q1 == TRUE' => 'R TRUE / FALSE (use true / false)',
            'stringr::str_length(q1) > 2' => 'R namespace :: (e.g. stringr::)',
            'q1 %contains% "a"' => 'R-only operator (%in%, %contains%, %%, …)',
            'x <- q1 == 1' => 'R assignment arrow',
            'q1 == NA' => 'R NA constant',
            'q1 == NA_real_' => 'R NA constant',
            'q1 != NA_character_' => 'R NA constant',
            'survey$q1 == 1' => 'R $-member access',
            'q1 %in% 1' => 'R-only operator (%in%, %contains%, %%, …)',
            'ifelse(q1, 1, 2)' => 'R-only function call',
        );
        foreach ($cases as $expr => $problem) {
            $this->assertSame($problem === null ? array() : array($problem), FormV2CompatScanner::detectRTokens($expr), $expr);
        }
    }

    public function testJavaScriptAndStringLiteralsAreNotFlagged() {
        foreach (array(
            'q1 == "NA" || q2 == \'tail(x, 3)\'',   // tokens inside strings
            'q1 === "survey$q1"',
            'q1 > 1 && q2 !== null',
            '(typeof(q1) === \'undefined\') // TRUE & FALSE in a comment',
            'q1 == true && !isNA(q2)',
            'answered(q1) && contains(q2, "x <- y")',
        ) as $expr) {
            $this->assertSame(array(), FormV2CompatScanner::detectRTokens($expr), $expr);
        }
    }

    public function testSeveralProblemsAreAllListed() {
        $this->assertSame(
            array('is.na() (use isNA())', 'R NA constant', 'R $-member access'),
            FormV2CompatScanner::detectRTokens('is.na(survey$q1) || survey$q2 == NA')
        );
    }
    public function testNamesInReadsOnlyTheNamesAShowifUses() {
        // Also what decides which no-input values (random, ip, …) the state
        // block ships (FormSubmissionService::showifNames): words in strings
        // and comments, function names and property accesses are not names.
        $this->assertSame(['q1', 'r_ip', 'x'], FormV2CompatScanner::namesIn(
            "answered(q1) && r_ip == \"a_secret b\" // a_browser\n && x.length > 0 /* a_random */"));
    }
}
