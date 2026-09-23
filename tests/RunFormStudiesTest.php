<?php

/**
 * Run::getAllSurveys() / getAllLinkedSurveys() must see the study behind a
 * Form unit. A v1 Survey unit shares its id with its survey_studies row, so
 * the historical join was `survey_studies.id = survey_run_units.unit_id`;
 * a Form unit (form_v2) has its own survey_units row and points at the
 * study through survey_units.form_study_id (patch 083), which that join
 * never matched — so R saw "object not found" for a Form's data in later
 * units, and the run's export/API skipped the study.
 */
class RunFormStudiesTest extends \PHPUnit\Framework\TestCase {

    private const RUN_ID = 1;

    /** @var DB */
    private $db;

    protected function setUp(): void {
        $this->db = DB::getInstance();
        $this->dropTables();

        $this->db->exec('CREATE TABLE survey_run_units (id INTEGER PRIMARY KEY, run_id INTEGER, unit_id INTEGER, position INTEGER)');
        $this->db->exec('CREATE TABLE survey_units (id INTEGER PRIMARY KEY, type TEXT, form_study_id INTEGER)');
        $this->db->exec('CREATE TABLE survey_studies (id INTEGER PRIMARY KEY, name TEXT, results_table TEXT, unlinked INTEGER DEFAULT 0)');

        $r = self::RUN_ID;
        // 20: v1 Survey unit (unit id == study id)
        // 31: Form unit rendering study 30
        // 32: a Page — no study
        // 41: v1 Survey unit for an unlinked study
        $this->db->exec("INSERT INTO survey_units (id, type, form_study_id) VALUES
            (20, 'Survey', NULL), (31, 'Form', 30), (32, 'Page', NULL), (41, 'Survey', NULL)");
        $this->db->exec("INSERT INTO survey_studies (id, name, results_table, unlinked) VALUES
            (20, 'intro', 's20_intro', 0), (30, 'form_study', NULL, 0), (41, 'old', 's41_old', 1)");
        $this->db->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position) VALUES
            (100, $r, 20, 10), (101, $r, 31, 20), (102, $r, 32, 30), (103, $r, 41, 40),
            (200, 2, 20, 10)");
    }

    protected function tearDown(): void {
        $this->dropTables();
    }

    private function dropTables(): void {
        $this->db->execute('SELECT 1');
        foreach (array('survey_run_units', 'survey_units', 'survey_studies') as $t) {
            $this->db->exec("DROP TABLE IF EXISTS $t");
        }
    }

    private function makeRun(): Run {
        $run = new Run();
        $run->id = self::RUN_ID;
        return $run;
    }

    private static function ids(array $rows): array {
        $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
        sort($ids);
        return $ids;
    }

    public function testGetAllSurveysIncludesFormStudies() {
        $rows = $this->makeRun()->getAllSurveys();
        $this->assertSame(array(20, 30, 41), self::ids($rows));

        $byId = array_column($rows, null, 'id');
        $this->assertSame('s20_intro', $byId[20]['results_table']);
        $this->assertSame('form_study', $byId[30]['results_table'], 'results_table falls back to the study name');
        $this->assertSame('form_study', $byId[30]['name']);
    }

    public function testGetAllLinkedSurveysExcludesUnlinkedButKeepsForms() {
        $this->assertSame(array(20, 30), self::ids($this->makeRun()->getAllLinkedSurveys()));
    }

    public function testStudyPlacedTwiceIsListedOnce() {
        $this->db->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position) VALUES (104, " . self::RUN_ID . ", 31, 50)");
        $this->assertSame(array(20, 30, 41), self::ids($this->makeRun()->getAllSurveys()));
    }
}
