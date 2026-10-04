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
        $this->db->exec('DROP TABLE IF EXISTS survey_item_choices');
        $this->db->exec('CREATE TABLE survey_item_choices (
            id INTEGER PRIMARY KEY, study_id INTEGER, list_name TEXT, name TEXT,
            label TEXT, label_parsed TEXT)');
        $this->db->exec('DROP TABLE IF EXISTS survey_studies');
        $this->db->exec("CREATE TABLE survey_studies (id INTEGER PRIMARY KEY, rendering_mode TEXT DEFAULT 'v1')");
        $this->db->exec('INSERT INTO survey_studies (id) VALUES (' . self::STUDY_ID . ')');
    }

    protected function tearDown(): void {
        foreach (array('survey_items', 'survey_item_choices', 'survey_studies') as $t) {
            $this->db->exec("DROP TABLE IF EXISTS $t");
        }
    }

    private function seedChoices(array $choices): void {
        $stmt = $this->db->pdo()->prepare('INSERT INTO survey_item_choices (study_id, list_name, name, label) VALUES (:study, :list, :name, :label)');
        foreach ($choices as $c) {
            $stmt->execute(array(':study' => self::STUDY_ID, ':list' => $c[0], ':name' => $c[1], ':label' => $c[2]));
        }
    }

    /** Rows: name, type, showif, value, deleted, choice_list. */
    private function seed(array $rows, $studyId = self::STUDY_ID): void {
        $stmt = $this->db->pdo()->prepare('INSERT INTO survey_items (study_id, name, type, choice_list, showif, value, "order", deleted)
            VALUES (:study, :name, :type, :list, :showif, :value, :ord, :deleted)');
        foreach (array_values($rows) as $i => $r) {
            $stmt->execute(array(
                ':study' => $studyId, ':name' => $r[0], ':type' => $r[1], ':list' => $r[5] ?? null,
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
        $this->assertSame(array('not in this survey: ran_group'), $flagged['run_var/showif']['problems']);
        $this->assertSame(array('not in this survey: gone'), $flagged['to_gone/showif']['problems'], 'a soft-deleted item is not a name the form knows');
        $this->assertSame('rewrite_in_js', $flagged['r_tail/showif']['suggested_fix']);
        $this->assertSame(array('uses an R function'), $flagged['r_tail/showif']['problems']);
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

    /**
     * The scan applies the switch's own rule: a v1 study may have an item
     * named like a member of the v2 form (a showif helper, init), which the
     * switch refuses — the scan said nothing and exited 0, and even counted
     * `last > 2` as valid JavaScript.
     */
    public function testAnItemNamedLikeAFormMemberIsFlaggedToRename() {
        $this->seed(array(
            array('last', 'number'),
            array('init', 'text'),
            array('q1', 'text', 'last > 2'),
        ));
        $report = FormV2CompatScanner::scan(self::STUDY_ID);
        $this->assertSame(2, $report['counts']['unsupported']);
        $renamed = array_values(array_filter($report['flagged'], function ($f) { return $f['suggested_fix'] === 'rename_item'; }));
        $this->assertSame(array('last', 'init'), array_column($renamed, 'name'));
        $this->assertSame('name', $renamed[0]['column']);
    }

    public function testASemicolonInASelectOrAddMultipleChoiceIsFlagged() {
        $this->seed(array(
            array('places', 'select_or_add_multiple', null, null, false, 'places'),
            array('cities', 'select_or_add_multiple', null, null, false, 'cities'),
        ));
        $this->seedChoices(array(array('places', '1', 'Berlin; Germany'), array('places', '2', 'Paris'),
            array('cities', '1', 'Berlin, Germany'), array('cities', '2', 'Rome')));
        $report = FormV2CompatScanner::scan(self::STUDY_ID);
        $this->assertSame(1, $report['counts']['unsupported'], 'a comma is fine; the semicolon separates answers');
        $flagged = $this->flaggedBy($report);
        $this->assertSame(array('places/choice_list'), array_keys($flagged));
        $this->assertSame('edit_choices', $flagged['places/choice_list']['suggested_fix']);
        $this->assertStringContainsString('Berlin; Germany', $flagged['places/choice_list']['problems'][0]);
    }

    public function testAOneCellListIsSplitAtSemicolonsInV2() {
        $this->seed(array(
            array('colours', 'select_or_add_multiple', null, null, false, 'colours'),
            array('city', 'select_or_add_one', null, null, false, 'city'),
            array('packed', 'select_or_add_multiple', null, null, false, 'packed'),
        ));
        $this->seedChoices(array(array('colours', '1', 'red,green,blue'), array('city', '1', 'Berlin, Germany'),
            array('packed', '1', 'red;green, light;blue')));
        $report = FormV2CompatScanner::scan(self::STUDY_ID);
        $this->assertSame(0, $report['counts']['unsupported'], 'a one-cell list with semicolons is a list, not a choice with one');
        $this->assertSame(2, $report['counts']['comma_lists']);
        $flagged = $this->flaggedBy($report);
        $this->assertSame(array('colours/choice_list', 'city/choice_list'), array_keys($flagged));
        $this->assertSame('pack_with_semicolons', $flagged['colours/choice_list']['suggested_fix']);
        $this->assertStringContainsString('into 3 choices', $flagged['colours/choice_list']['problems'][0]);

        $this->db->exec("UPDATE survey_studies SET rendering_mode = 'v2'");
        $report = FormV2CompatScanner::scan(self::STUDY_ID);
        $this->assertSame(0, $report['counts']['comma_lists'], 'on v2 a lone "Berlin, Germany" is one choice, as meant');
        $this->assertSame(array(), $report['flagged']);
    }

    public function testUnpackChoicesByEngine() {
        $this->assertSame(array('red', 'green'), SelectOrAddOne_Item::unpackChoices(array(1 => 'red,green'), false));
        $this->assertSame(array(1 => 'red,green'), SelectOrAddOne_Item::unpackChoices(array(1 => 'red,green'), true));
        $this->assertSame(array('Berlin, Germany', 'Paris'), SelectOrAddOne_Item::unpackChoices(array(1 => 'Berlin, Germany; Paris;'), true));
        $two = array(1 => 'a;b', 2 => 'c');
        $this->assertSame($two, SelectOrAddOne_Item::unpackChoices($two, true), 'only a single entry is a one-cell list');
    }

    public function testEachRTokenIsReportedOnItsOwn() {
        $cases = array(
            'tail(q1, 1) > 1' => 'uses an R function',
            'head(q1, 2) == 1' => 'uses an R function',
            'current(q1) == 1' => 'uses an R function',
            'is.na(q1 + 1)' => 'is.na() is R: use isNA()',
            'q1 == 1 & q2 == 2' => 'single & or |: use && or ||',
            'q1 == 1 | q2 == 2' => 'single & or |: use && or ||',
            'q1 == TRUE' => 'TRUE/FALSE: use true/false',
            'stringr::str_length(q1) > 2' => 'R package function such as stringr::',
            'q1 %contains% "a"' => 'R operator such as %in% or %contains%',
            'x <- q1 == 1' => 'R assignment (<-)',
            'q1 == NA' => 'NA: use isNA()',
            'q1 == NA_real_' => 'NA: use isNA()',
            'q1 != NA_character_' => 'NA: use isNA()',
            'survey$q1 == 1' => 'survey$item: use just the item name',
            'q1 %in% 1' => 'R operator such as %in% or %contains%',
            'ifelse(q1, 1, 2)' => 'uses an R function',
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
            array('is.na() is R: use isNA()', 'NA: use isNA()', 'survey$item: use just the item name'),
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
