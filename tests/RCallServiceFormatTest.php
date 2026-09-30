<?php

/**
 * RCallService::formatROverlay assigns the participant's in-flight answers
 * into the survey frame before a reactive r() is evaluated. It used to format
 * by value shape (is_numeric), so a `text` answer of '07743' reached R as the
 * number 7743 and '1e3' as 1000 — the character column retyped, the leading
 * zero gone — while the SAME expression run after the page is saved sees the
 * stored string. The item's results-table column type is the only thing that
 * may decide this, exactly as UnitSession::getLongReadCasts does for the
 * frame itself.
 */
class RCallServiceFormatTest extends \PHPUnit\Framework\TestCase {

    private const STUDY_ID = 410;

    /** @var DB */
    private $db;

    protected function setUp(): void {
        $this->db = DB::getInstance();
        $this->dropTables();
        SurveyStudy::forgetColumnTypes();

        $this->db->exec('CREATE TABLE survey_items (id INTEGER PRIMARY KEY, study_id INTEGER, type TEXT, choice_list TEXT, type_options TEXT, name TEXT, deleted TEXT)');
        $this->db->exec('CREATE TABLE survey_item_choices (id INTEGER PRIMARY KEY, study_id INTEGER, list_name TEXT, name TEXT, label TEXT)');

        $s = self::STUDY_ID;
        $this->db->exec("INSERT INTO survey_items (id, study_id, type, choice_list, type_options, name, deleted) VALUES
            (1, $s, 'text',           NULL,   NULL,        'code',      NULL),
            (2, $s, 'number',         NULL,   '0,120,1',   'age',       NULL),
            (3, $s, 'textarea',       NULL,   NULL,        'comment',   NULL),
            (4, $s, 'mc',             'mood', NULL,        'mood',      NULL),
            (5, $s, 'mc',             'pet',  NULL,        'pet',       NULL),
            (6, $s, 'number',         NULL,   '0,300,0.1', 'weight',    NULL),
            (7, $s, 'mc_multiple',    'pet',  NULL,        'pets',      NULL),
            (8, $s, 'rating_button',  NULL,   '1,5',       'liking',    NULL),
            (9, $s, 'note',           NULL,   NULL,        'intro',     NULL),
            (10, $s, 'text',          NULL,   NULL,        'gone',      '2026-01-01 00:00:00'),
            (11, $s, 'text',          NULL,   NULL,        'elsewhere', NULL)");
        // `elsewhere` belongs to another study: not overlayable at all.
        $this->db->exec("UPDATE survey_items SET study_id = 411 WHERE id = 11");
        $this->db->exec("INSERT INTO survey_item_choices (study_id, list_name, name, label) VALUES
            ($s, 'mood', '1', 'bad'), ($s, 'mood', '2', 'meh'), ($s, 'mood', '3', 'good'),
            ($s, 'pet', 'cat', 'Cat'), ($s, 'pet', 'dog', 'Dog')");
    }

    protected function tearDown(): void {
        $this->dropTables();
    }

    private function dropTables(): void {
        foreach (array('survey_items', 'survey_item_choices') as $t) {
            $this->db->exec("DROP TABLE IF EXISTS $t");
        }
    }

    /** formatROverlay only touches DB + $study; neither model needs hydrating. */
    private function overlay(array $answers): string {
        $study = (new ReflectionClass(SurveyStudy::class))->newInstanceWithoutConstructor();
        $study->id = self::STUDY_ID;
        $study->name = 'testsurvey';
        $unitSession = (new ReflectionClass(UnitSession::class))->newInstanceWithoutConstructor();
        $service = new RCallService($unitSession, $study);
        return $service->formatROverlay($answers);
    }

    public function testValuesAndLabelsApplyTheOverlayWithTheSameStatements() {
        $r = RCallService::overlayStatements('mystudy', 'list(age = 3)', '  ');
        $this->assertSame(
            "  .fmr.overlay <- list(age = 3)\n"
            . "  if (exists('mystudy') && nrow(mystudy) > 0) {\n"
            . "    for (.n in names(.fmr.overlay)) mystudy[nrow(mystudy), .n] <- .fmr.overlay[[.n]]\n"
            . "  }\n",
            $r
        );
        $this->assertStringStartsWith(".fmr.overlay", RCallService::overlayStatements('s', 'list()'));
    }

    public function testTextAnswersStayStringsEvenWhenTheyLookNumeric() {
        $r = $this->overlay(array('code' => '07743', 'comment' => '1e3'));
        $this->assertStringContainsString('code = "07743"', $r, 'leading zero must survive; text column is character');
        $this->assertStringContainsString('comment = "1e3"', $r, 'scientific notation in a textarea is text, not 1000');
    }

    public function testNumberItemsAreNumeric() {
        $r = $this->overlay(array('age' => '3', 'liking' => '4'));
        $this->assertStringContainsString('age = 3', $r);
        $this->assertStringNotContainsString('age = "3"', $r);
        $this->assertStringContainsString('liking = 4', $r, 'rating_button stores a TINYINT');
    }

    public function testChoiceColumnTypeDecidesNotTheAnswer() {
        $r = $this->overlay(array('mood' => '3', 'pet' => 'cat', 'pets' => array('cat', 'dog')));
        $this->assertStringContainsString('mood = 3', $r, 'mc with numeric choice names → TINYINT → numeric');
        $this->assertStringContainsString('pet = "cat"', $r, 'mc with string choice names → VARCHAR → character');
        $this->assertStringContainsString('pets = "cat, dog"', $r, 'multi-select keeps the ", "-joined stored shape');
    }

    public function testDecimalNumberMatchesTheLongReadWhichLeavesItAString() {
        // UnitSession::getLongReadCasts casts INT-family and FLOAT/DOUBLE only;
        // DECIMAL comes back from PDO as a string, so the frame holds character.
        $r = $this->overlay(array('weight' => '70.5'));
        $this->assertStringContainsString('weight = "70.5"', $r);
    }

    public function testNullAndEmptyStayNA() {
        $r = $this->overlay(array('age' => null, 'code' => '', 'pets' => array()));
        $this->assertStringContainsString('age = NA', $r);
        $this->assertStringContainsString('code = NA', $r);
        $this->assertStringContainsString('pets = NA', $r);
    }

    public function testDisallowedNamesAreStillDropped() {
        $r = $this->overlay(array('session_id' => 'abc', 'gone' => 'x', 'elsewhere' => 'y', 'bad-name' => 'z'));
        $this->assertSame('list()', $r);
    }

    public function testStringsAreEscaped() {
        $r = $this->overlay(array('code' => 'a"b\\c'));
        $this->assertStringContainsString('code = "a\"b\\\\c"', $r);
    }

    public function testItemsWithNoResultsColumnAreQuoted() {
        // A note has no column in the frame; character can't retype anything.
        $r = $this->overlay(array('intro' => '5'));
        $this->assertStringContainsString('intro = "5"', $r);
    }

    /** @dataProvider resultFieldProvider */
    public function testColumnKindDecidesTheRType($field, $expected) {
        $kind = SurveyStudy::columnKind('x', $field);
        $this->assertSame($expected, ($kind === 'int' || $kind === 'float') ? 'numeric' : 'character');
    }

    public static function resultFieldProvider() {
        return array(
            'int' => array('`x` INT UNSIGNED DEFAULT NULL', 'numeric'),
            'tinyint' => array('`x` TINYINT UNSIGNED DEFAULT NULL', 'numeric'),
            'bigint' => array('`x` BIGINT DEFAULT NULL', 'numeric'),
            'float' => array('`x` FLOAT DEFAULT NULL', 'numeric'),
            'double' => array('`x` DOUBLE DEFAULT NULL', 'numeric'),
            'decimal' => array('`x` DECIMAL(5, 1) DEFAULT NULL', 'character'),
            'varchar' => array('`x` VARCHAR (40) DEFAULT NULL', 'character'),
            'text' => array('`x` MEDIUMTEXT DEFAULT NULL', 'character'),
            'date' => array('`x` DATE DEFAULT NULL', 'character'),
            'none' => array(null, 'character'),
        );
    }

    public function testFormatRValueDefaultsToCharacter() {
        $this->assertSame('"07743"', RCallService::formatRValue('07743'));
        // Bare in R source, 07743 parses as the number 7743 — which is what a
        // numeric column would have stored anyway.
        $this->assertSame('07743', RCallService::formatRValue('07743', true));
        $this->assertSame('"12"', RCallService::formatRValue(12));
        $this->assertSame('12', RCallService::formatRValue(12, true));
        $this->assertSame('NA', RCallService::formatRValue(null, true));
        $this->assertSame('TRUE', RCallService::formatRValue(true));
    }
}
