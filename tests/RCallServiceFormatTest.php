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
            (11, $s, 'text',          NULL,   NULL,        'elsewhere', NULL),
            (12, $s, 'random',        NULL,   '1,2',       'cond',      NULL),
            (13, $s, 'ip',            NULL,   NULL,        'ip_address', NULL)");
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

    /**
     * The overlay's values as R receives them: the JSON inside the
     * jsonlite::fromJSON("...") string literal, unescaped and decoded.
     */
    private function values(array $answers): array {
        $r = $this->overlay($answers);
        $this->assertSame(1, preg_match('~^lapply\(jsonlite::fromJSON\("((?:[^"\\\\]|\\\\.)*)", simplifyVector = FALSE\), function\(v\) if \(is\.null\(v\)\) NA else v\)$~', $r, $m), $r);
        $decoded = json_decode(preg_replace('~\\\\(.)~s', '$1', $m[1]), true);
        $this->assertIsArray($decoded, $r);
        return $decoded;
    }

    public function testTextAnswersStayStringsEvenWhenTheyLookNumeric() {
        $v = $this->values(array('code' => '07743', 'comment' => '1e3'));
        $this->assertSame('07743', $v['code'], 'leading zero must survive; text column is character');
        $this->assertSame('1e3', $v['comment'], 'scientific notation in a textarea is text, not 1000');
    }

    public function testNumberItemsAreNumeric() {
        $v = $this->values(array('age' => '3', 'liking' => '4'));
        $this->assertSame(3, $v['age']);
        $this->assertSame(4, $v['liking'], 'rating_button stores a TINYINT');
    }

    public function testChoiceColumnTypeDecidesNotTheAnswer() {
        $v = $this->values(array('mood' => '3', 'pet' => 'cat', 'pets' => array('cat', 'dog')));
        $this->assertSame(3, $v['mood'], 'mc with numeric choice names → TINYINT → numeric');
        $this->assertSame('cat', $v['pet'], 'mc with string choice names → VARCHAR → character');
        $this->assertSame('cat, dog', $v['pets'], 'multi-select keeps the ", "-joined stored shape');
    }

    public function testDecimalNumberMatchesTheLongReadWhichLeavesItAString() {
        // UnitSession::getLongReadCasts casts INT-family and FLOAT/DOUBLE only;
        // DECIMAL comes back from PDO as a string, so the frame holds character.
        $v = $this->values(array('weight' => '70.5'));
        $this->assertSame('70.5', $v['weight']);
    }

    public function testNullAndEmptyStayNA() {
        // JSON null; the lapply turns it into NA (a data.frame cell can't hold NULL).
        $v = $this->values(array('age' => null, 'code' => '', 'pets' => array()));
        $this->assertSame(array('age' => null, 'code' => null, 'pets' => null), $v);
    }

    public function testDisallowedNamesAreStillDropped() {
        $r = $this->overlay(array('session_id' => 'abc', 'gone' => 'x', 'elsewhere' => 'y', 'bad-name' => 'z'));
        $this->assertSame('list()', $r);
    }

    /**
     * What the server assigns is read as stored: a participant posting
     * {"cond": 2} to form-render-page must not see the other arm's labels,
     * nor have a hidden value computed (and submitted) for it.
     */
    public function testServerAssignedItemsCannotBeOverlaid() {
        $this->assertSame('list()', $this->overlay(array('cond' => '2', 'ip_address' => '10.0.0.1')));
        $this->assertSame(array('code' => 'x'), $this->values(array('cond' => '2', 'code' => 'x')));
    }

    public function testStringsRoundTrip() {
        $v = $this->values(array('code' => 'a"b\\c', 'comment' => "zwölf ünd 'quotes'"));
        $this->assertSame('a"b\\c', $v['code']);
        $this->assertSame("zwölf ünd 'quotes'", $v['comment']);
    }

    /**
     * The knitted paths put the overlay inside an R Markdown chunk, and knitr
     * finds a chunk's end line by line before R parses it: a newline and a
     * ``` line in an answer must not reach the document as such.
     */
    public function testAnswersCannotEndTheChunk() {
        $payload = "x\n```\n\n```{r}\ncat('INJECTED')\n```\r\n\u{2028}";
        $r = $this->overlay(array('comment' => $payload));
        $this->assertSame(1, preg_match('~^[\x20-\x7e]+$~', $r), 'one line of printable ASCII: ' . $r);
        $this->assertSame($payload, $this->values(array('comment' => $payload))['comment']);
    }

    public function testItemsWithNoResultsColumnAreStrings() {
        // A note has no column in the frame; character can't retype anything.
        $v = $this->values(array('intro' => '5'));
        $this->assertSame('5', $v['intro']);
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

    public function testOverlayValueDefaultsToCharacter() {
        $this->assertSame('07743', RCallService::overlayValue('07743'));
        // As a number, 07743 is 7743 — which is what a numeric column would
        // have stored anyway.
        $this->assertSame(7743, RCallService::overlayValue('07743', true));
        $this->assertSame(1000.0, RCallService::overlayValue('1e3', true));
        $this->assertSame('12', RCallService::overlayValue(12));
        $this->assertSame(12, RCallService::overlayValue(12, true));
        $this->assertNull(RCallService::overlayValue(null, true));
        $this->assertTrue(RCallService::overlayValue(true));
        $this->assertNull(RCallService::overlayValue('1e400', true), 'no Inf in JSON');
    }
}
