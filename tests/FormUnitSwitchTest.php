<?php

/**
 * Switching a study between engines switches every placement with it: all
 * placements share the study's survey_units row, whose type follows the
 * engine (SurveyStudy::adoptFormV2 / save → syncUnitType). Placements and
 * unit sessions keep pointing at the study id.
 */
class FormUnitSwitchTest extends \PHPUnit\Framework\TestCase {

    private const STUDY_ID = 20;

    /** @var DB */
    private $db;

    protected function setUp(): void {
        $this->db = DB::getInstance();
        $this->dropTables();
        $this->db->exec('CREATE TABLE survey_units (id INTEGER PRIMARY KEY, type TEXT, created TEXT, modified TEXT)');
        $this->db->exec('CREATE TABLE survey_studies (id INTEGER PRIMARY KEY, rendering_mode TEXT DEFAULT \'v1\', results_table TEXT)');
        $this->db->exec('CREATE TABLE survey_run_units (id INTEGER PRIMARY KEY, run_id INTEGER, unit_id INTEGER, position INTEGER)');

        $s = self::STUDY_ID;
        // study 20 is placed twice in run 1 and once in run 2; 32 is a Page.
        $this->db->exec("INSERT INTO survey_units (id, type) VALUES ($s, 'Survey'), (32, 'Page')");
        $this->db->exec("INSERT INTO survey_studies (id, rendering_mode) VALUES ($s, 'v1')");
        $this->db->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position) VALUES
            (100, 1, $s, 10), (101, 1, 32, 20), (102, 1, $s, 30), (200, 2, $s, 10)");
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

    private function study(): SurveyStudy {
        $study = new SurveyStudy();
        $study->id = self::STUDY_ID;
        $study->rendering_mode = 'v1';
        return $study;
    }

    private function unitType(int $id) {
        return $this->db->findValue('survey_units', ['id' => $id], 'type');
    }

    private function placements(): array {
        return array_map('intval', array_column(
            $this->db->select('unit_id')->from('survey_run_units')->order('id', 'asc')->fetchAll(), 'unit_id'));
    }

    public function testAdoptingV2MakesEveryPlacementAForm() {
        $this->study()->adoptFormV2();
        $this->assertSame('v2', $this->db->findValue('survey_studies', ['id' => self::STUDY_ID], 'rendering_mode'));
        $this->assertSame('Form', $this->unitType(self::STUDY_ID));
        $this->assertSame('Page', $this->unitType(32), 'other units are left alone');
        $this->assertSame([20, 32, 20, 20], $this->placements(), 'placements keep the study id');
    }

    public function testSwitchingBackMakesThemSurveysAgain() {
        $study = $this->study();
        $study->adoptFormV2();
        $study->rendering_mode = 'v1';
        $sync = new ReflectionMethod(SurveyStudy::class, 'syncUnitType');
        $sync->setAccessible(true);
        $sync->invoke($study);
        $this->assertSame('Survey', $this->unitType(self::STUDY_ID));
        $this->assertSame([20, 32, 20, 20], $this->placements());
    }
}
