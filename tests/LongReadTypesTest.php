<?php

/**
 * UnitSession::getSurveyDataLong pivots survey_items_display.answer
 * (MEDIUMTEXT) into the column arrays the R overlay is built from, so
 * every value arrives as a PHP string and json_encode ships "42" where the
 * wide results table (typed columns) shipped 42. R then sees character
 * columns: `age > 18` compares lexically, mean()/arithmetic error. The
 * long read must coerce each column to what the item's wide column type
 * would have produced — and only those: a text item holding "7" stays "7".
 */
class LongReadTypesTest extends \PHPUnit\Framework\TestCase {

    private const STUDY_ID = 300;
    private const SESSION_ID = 7;
    private const RUN_SESSION_ID = 5;
    private const WIDE_TABLE = 's300_long_read_test';

    /** @var DB */
    private $db;

    protected function setUp(): void {
        $this->db = DB::getInstance();
        $this->dropTables();
        // The column-type memo is static (request-scoped in the app); fixtures differ per test.
        SurveyStudy::forgetColumnTypes();

        $this->db->exec('CREATE TABLE survey_items (id INTEGER PRIMARY KEY, study_id INTEGER, type TEXT, choice_list TEXT, type_options TEXT, name TEXT, deleted TEXT)');
        $this->db->exec('CREATE TABLE survey_item_choices (id INTEGER PRIMARY KEY, study_id INTEGER, list_name TEXT, name TEXT, label TEXT)');
        $this->db->exec('CREATE TABLE survey_items_display (id INTEGER PRIMARY KEY, session_id INTEGER, item_id INTEGER, answer TEXT, created TEXT, saved TEXT)');
        // `result` is part of the fixture because the long read derives v1's
        // completion semantics from it (UnitSession::completedEndedSql).
        $this->db->exec('CREATE TABLE survey_unit_sessions (id INTEGER PRIMARY KEY, run_session_id INTEGER, ended TEXT, result TEXT, study_iteration INTEGER)');

        $s = self::STUDY_ID;
        // A soft-deleted `age` (text) predates the live `age` (number): the
        // live row must decide the type.
        $this->db->exec("INSERT INTO survey_items (id, study_id, type, choice_list, type_options, name, deleted) VALUES
            (1, $s, 'text',   NULL,    NULL,        'age',     '2026-01-01 00:00:00'),
            (2, $s, 'number', NULL,    '0,120,1',   'age',     NULL),
            (3, $s, 'mc',     'mood',  NULL,        'mood',    NULL),
            (4, $s, 'mc',     'pet',   NULL,        'pet',     NULL),
            (5, $s, 'text',   NULL,    NULL,        'comment', NULL),
            (6, $s, 'number', NULL,    '0,300,0.1', 'weight',  NULL)");
        $this->db->exec("INSERT INTO survey_item_choices (study_id, list_name, name, label) VALUES
            ($s, 'mood', '1', 'bad'), ($s, 'mood', '2', 'meh'), ($s, 'mood', '3', 'good'),
            ($s, 'pet', 'cat', 'Cat'), ($s, 'pet', 'dog', 'Dog')");
        $this->db->exec("INSERT INTO survey_unit_sessions (id, run_session_id, ended, result, study_iteration) VALUES (" . self::SESSION_ID . ", " . self::RUN_SESSION_ID . ", NULL, NULL, NULL)");
        $sid = self::SESSION_ID;
        $this->db->exec("INSERT INTO survey_items_display (session_id, item_id, answer, created, saved) VALUES
            ($sid, 2, '42',   '2026-02-01 10:00:00', '2026-02-01 10:01:00'),
            ($sid, 3, '3',    '2026-02-01 10:00:00', '2026-02-01 10:01:00'),
            ($sid, 4, 'cat',  '2026-02-01 10:00:00', '2026-02-01 10:01:00'),
            ($sid, 5, '7',    '2026-02-01 10:00:00', '2026-02-01 10:01:00'),
            ($sid, 6, '70.5', '2026-02-01 10:00:00', '2026-02-01 10:01:00')");
    }

    protected function tearDown(): void {
        $this->dropTables();
    }

    private function dropTables(): void {
        $this->db->execute('SELECT 1');
        foreach (array('survey_items', 'survey_item_choices', 'survey_items_display', 'survey_unit_sessions', self::WIDE_TABLE) as $t) {
            $this->db->exec("DROP TABLE IF EXISTS $t");
        }
    }

    private function longRead(array $names) {
        // A RunSession without an id selects the "testing" scope, which keys
        // on the unit-session id alone. Built without the constructor so it
        // doesn't try to hydrate from survey_run_sessions.
        $runSession = (new ReflectionClass(RunSession::class))->newInstanceWithoutConstructor();
        $runSession->boot();
        $unitSession = new UnitSession($runSession);
        $unitSession->id = self::SESSION_ID;
        $m = new ReflectionMethod('UnitSession', 'getSurveyDataLong');
        $m->setAccessible(true);
        return $m->invoke($unitSession, self::STUDY_ID, $names);
    }

    public function testNumericItemsComeBackTypedLikeTheWideTable() {
        $cols = $this->longRead(array('age', 'mood', 'pet', 'comment', 'weight', 'iteration'));

        $this->assertSame(array(42), $cols['age'], 'number item (INT column) → int');
        $this->assertSame(array(3), $cols['mood'], 'mc with numeric choices (TINYINT column) → int');
        $this->assertSame(array('cat'), $cols['pet'], 'mc with string choices (VARCHAR column) stays string');
        $this->assertSame(array('7'), $cols['comment'], 'text item stays a string even when it looks numeric');
        $this->assertSame(array('70.5'), $cols['weight'], 'DECIMAL column: PDO returned a string from the wide table too');
        $this->assertSame(array(1), $cols['iteration'], 'iteration (INT column) → int');

        $json = json_encode($cols);
        $this->assertStringContainsString('"age":[42]', $json);
        $this->assertStringContainsString('"comment":["7"]', $json);
    }

    public function testNullAnswersStayNull() {
        $this->db->exec("UPDATE survey_items_display SET answer = NULL WHERE item_id = 2");
        $cols = $this->longRead(array('age'));
        $this->assertSame(array(null), $cols['age']);
    }

    /** Run-scoped read (a real participant's data frame). */
    private function longReadForRun(array $names, $wideIterationTable = null) {
        $runSession = (new ReflectionClass(RunSession::class))->newInstanceWithoutConstructor();
        $runSession->boot();
        $runSession->id = self::RUN_SESSION_ID;
        // isTestingStudy() asks the run; a real (non-test) run keeps the read
        // in run scope.
        $run = new ReflectionProperty(RunSession::class, 'run');
        $run->setAccessible(true);
        $run->setValue($runSession, new class {
            public function isStudyTest() {
                return false;
            }
        });
        $unitSession = new UnitSession($runSession);
        $unitSession->id = self::SESSION_ID;
        $m = new ReflectionMethod('UnitSession', 'getSurveyDataLong');
        $m->setAccessible(true);
        return $m->invoke($unitSession, self::STUDY_ID, $names, $wideIterationTable);
    }

    public function testOperatorForceMoveIsNotACompletion() {
        $sid = self::SESSION_ID;
        // v1 wrote `ended` into the wide row only for real completions;
        // survey_unit_sessions.ended is stamped for a force-move too, so the
        // long read has to reconstruct the distinction from `result`.
        $this->db->exec("UPDATE survey_unit_sessions SET ended = '2026-02-01 10:05:00', result = 'manual_admin_push' WHERE id = $sid");
        $cols = $this->longRead(array('ended'));
        $this->assertSame(array(null), $cols['ended'], 'a force-moved session has not ended');

        $this->db->exec("UPDATE survey_unit_sessions SET result = 'survey_ended' WHERE id = $sid");
        $cols = $this->longRead(array('ended'));
        $this->assertSame(array('2026-02-01 10:05:00'), $cols['ended'], 'a real completion still ends');
    }

    /**
     * survey_items_display keeps a blank reply as '' (getReply of the raw
     * post) where the wide column held NULL (validation NULLs it). Served as
     * '', is.na() turned FALSE and one blank made a numeric column character.
     */
    public function testBlankRepliesReadAsNullLikeTheWideTable() {
        $s = self::STUDY_ID;
        $sid = self::SESSION_ID;
        $this->db->exec("UPDATE survey_items_display SET answer = '' WHERE item_id IN (2, 5)");
        $this->db->exec("INSERT INTO survey_items (id, study_id, type, choice_list, type_options, name, deleted) VALUES (7, $s, 'mc_multiple', 'pet', NULL, 'pets', NULL)");
        $this->db->exec("INSERT INTO survey_items_display (session_id, item_id, answer, created, saved) VALUES ($sid, 7, '', '2026-02-01 10:00:00', '2026-02-01 10:01:00')");

        $cols = $this->longRead(array('age', 'comment', 'pets'));
        $this->assertSame(array(null), $cols['age'], 'a blank number is NA, not ""');
        $this->assertSame(array(null), $cols['comment'], 'a blank optional text is NA, as in the wide column');
        $this->assertSame(array(''), $cols['pets'], 'a multi-valued item stored "" in the wide table too');
    }

    /**
     * A session that began before patch 108 has no study_iteration until the
     * backfill runs; its wide row has the number the wide read gave R.
     */
    public function testPreUpgradeSessionTakesItsIterationFromTheWideRow() {
        $w = self::WIDE_TABLE;
        $this->db->exec("CREATE TABLE $w (session_id INTEGER PRIMARY KEY, study_id INTEGER, created TEXT, modified TEXT, ended TEXT, iteration INTEGER, age INTEGER, comment TEXT)");
        $this->db->exec("INSERT INTO $w (session_id, study_id, created, modified, ended, iteration, age, comment) VALUES (" . self::SESSION_ID . ", " . self::STUDY_ID . ", '2026-02-01 10:00:00', '2026-02-01 10:01:00', NULL, 23, 42, '7')");

        $cols = $this->longReadForRun(array('age', 'iteration'), $w);
        $this->assertSame(array(23), $cols['iteration']);
        $this->assertSame(array(42), $cols['age'], 'the join does not fan the session out');
    }
}
