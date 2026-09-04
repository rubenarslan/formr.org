<?php

/**
 * Switching a study v1 → v2 (AdminSurveyController::switchRenderingModeAction)
 * converts each plain-Survey placement into a Form placement. The placement's
 * unit sessions must move with it: RunSession::getCurrentUnitSession matches
 * a session by `unit_id = the unit at the participant's position`, so a
 * session left on the study id would be invisible and execute() would open a
 * second, empty session while the first dangled open.
 */
class FormUnitSwitchTest extends \PHPUnit\Framework\TestCase {

    private const STUDY_ID = 20;

    /** @var DB */
    private $db;

    protected function setUp(): void {
        $this->db = DB::getInstance();
        $this->dropTables();
        $this->db->exec('CREATE TABLE survey_units (id INTEGER PRIMARY KEY, type TEXT, form_study_id INTEGER, created TEXT, modified TEXT)');
        $this->db->exec('CREATE TABLE survey_run_units (id INTEGER PRIMARY KEY, run_id INTEGER, unit_id INTEGER, position INTEGER)');
        $this->db->exec('CREATE TABLE survey_run_sessions (id INTEGER PRIMARY KEY, run_id INTEGER, position INTEGER)');
        $this->db->exec('CREATE TABLE survey_unit_sessions (id INTEGER PRIMARY KEY, run_session_id INTEGER, unit_id INTEGER, run_unit_id INTEGER, ended TEXT)');

        $s = self::STUDY_ID;
        // study 20 is placed twice in run 1 (positions 10 and 30) and once in run 2;
        // 31 is an existing Form for study 30; 32 is a Page.
        $this->db->exec("INSERT INTO survey_units (id, type, form_study_id) VALUES ($s, 'Survey', NULL), (31, 'Form', 30), (32, 'Page', NULL)");
        $this->db->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position) VALUES
            (100, 1, $s, 10), (101, 1, 32, 20), (102, 1, $s, 30), (103, 1, 31, 40), (200, 2, $s, 10)");
        $this->db->exec("INSERT INTO survey_run_sessions (id, run_id, position) VALUES (5, 1, 10), (6, 1, 30), (7, 1, 30), (8, 2, 10), (9, 1, 40)");
        $this->db->exec("INSERT INTO survey_unit_sessions (id, run_session_id, unit_id, run_unit_id, ended) VALUES
            (1000, 5, $s, 100, NULL),
            (1001, 6, $s, 100, '2026-09-01 10:00:00'),
            (1002, 6, $s, 102, NULL),
            (1003, 7, $s, NULL, NULL),
            (1004, 7, 32, 101, '2026-09-01 10:00:00'),
            (1005, 8, $s, 200, NULL),
            (1006, 9, 31, 103, NULL),
            (1007, 9, $s, NULL, '2026-09-01 10:00:00')");
    }

    protected function tearDown(): void {
        $this->dropTables();
    }

    private function dropTables(): void {
        $this->db->execute('SELECT 1');
        foreach (array('survey_run_units', 'survey_units', 'survey_run_sessions', 'survey_unit_sessions') as $t) {
            $this->db->exec("DROP TABLE IF EXISTS $t");
        }
    }

    private function unitAt(int $run_unit_id): int {
        return (int) $this->db->findValue('survey_run_units', ['id' => $run_unit_id], 'unit_id');
    }

    private function sessionUnit(int $id): int {
        return (int) $this->db->findValue('survey_unit_sessions', ['id' => $id], 'unit_id');
    }

    public function testEveryPlacementGetsItsOwnFormAndSessionsFollow() {
        $converted = AdminSurveyController::convertSurveyPlacementsToForm($this->db, self::STUDY_ID);
        $this->assertSame(3, $converted);

        $f100 = $this->unitAt(100);
        $f102 = $this->unitAt(102);
        $f200 = $this->unitAt(200);
        $this->assertCount(3, array_unique([$f100, $f102, $f200]), 'one Form row per placement');
        foreach ([$f100, $f102, $f200] as $f) {
            $this->assertNotSame(self::STUDY_ID, $f);
            $this->assertSame('Form', $this->db->findValue('survey_units', ['id' => $f], 'type'));
            $this->assertSame(self::STUDY_ID, (int) $this->db->findValue('survey_units', ['id' => $f], 'form_study_id'));
        }
        $this->assertSame(32, $this->unitAt(101), 'other unit types untouched');
        $this->assertSame(31, $this->unitAt(103), 'existing Form placements untouched');
        $this->assertSame('Survey', $this->db->findValue('survey_units', ['id' => self::STUDY_ID], 'type'), 'the study\'s twin row stays');

        // attributed by run_unit_id — live and ended alike
        $this->assertSame($f100, $this->sessionUnit(1000));
        $this->assertSame($f100, $this->sessionUnit(1001));
        $this->assertSame($f102, $this->sessionUnit(1002));
        $this->assertSame($f200, $this->sessionUnit(1005));
        // legacy row (run_unit_id NULL): by the run session's position
        $this->assertSame($f102, $this->sessionUnit(1003));
        // legacy row of a participant who moved past a twice-placed study: unattributable, left alone
        $this->assertSame(self::STUDY_ID, $this->sessionUnit(1007));
        // sessions of other units untouched
        $this->assertSame(32, $this->sessionUnit(1004));
        $this->assertSame(31, $this->sessionUnit(1006));
    }

    public function testConvertingAgainIsANoOp() {
        AdminSurveyController::convertSurveyPlacementsToForm($this->db, self::STUDY_ID);
        $before = $this->db->select('id, unit_id')->from('survey_run_units')->order('id')->fetchAll();
        $this->assertSame(0, AdminSurveyController::convertSurveyPlacementsToForm($this->db, self::STUDY_ID));
        $this->assertSame($before, $this->db->select('id, unit_id')->from('survey_run_units')->order('id')->fetchAll());
        $this->assertSame(0, AdminSurveyController::convertSurveyPlacementsToForm($this->db, 30), 'a Form-born study has no Survey placements');
    }
}
