<?php

/**
 * A Form unit, like a Survey unit, IS its study: linking it re-points
 * survey_run_units.unit_id at the study id, and the study's survey_units row
 * carries type 'Form' while the study is on v2 (SurveyStudy::syncUnitType),
 * which is what makes the factory build a Form on the next request.
 * AdminAjaxController::ajaxSaveRunUnit builds the unit with its id, so
 * re-saves run through the same path.
 */
class FormUnitSaveTest extends \PHPUnit\Framework\TestCase {

    private const USER_ID = 7;
    private const RUN_ID = 1;

    /** @var DB */
    private $db;

    private $savedUser;

    protected function setUp(): void {
        $this->db = DB::getInstance();
        $this->dropTables();
        $this->db->exec('CREATE TABLE survey_units (id INTEGER PRIMARY KEY, type TEXT, created TEXT, modified TEXT)');
        $this->db->exec('CREATE TABLE survey_run_units (id INTEGER PRIMARY KEY, run_id INTEGER, unit_id INTEGER, position INTEGER, description TEXT)');
        $this->db->exec('CREATE TABLE survey_runs (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->exec('CREATE TABLE survey_studies (id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, results_table TEXT, unlinked INTEGER DEFAULT 0, rendering_mode TEXT DEFAULT "v1")');
        // Form::create asks the study whether v2 can run it (formV2UnsupportedItems).
        $this->db->exec('CREATE TABLE survey_items (id INTEGER PRIMARY KEY, study_id INTEGER, name TEXT, type TEXT, type_options TEXT, choice_list TEXT, label TEXT, label_parsed TEXT, optional INTEGER, class TEXT, showif TEXT, value TEXT, item_order INTEGER, `order` INTEGER, block_order INTEGER, deleted TEXT)');

        $u = self::USER_ID;
        // Every study's unit row: 20 on v1, 30 on v2, 40 on v1.
        $this->db->exec("INSERT INTO survey_units (id, type) VALUES (20, 'Survey'), (30, 'Form'), (40, 'Survey')");
        $this->db->exec("INSERT INTO survey_studies (id, user_id, name, results_table, rendering_mode) VALUES
            (20, $u, 'intro', 's20_intro', 'v1'), (30, $u, 'form_study', 's30_form_study', 'v2'),
            (40, $u, 'other', 's40_other', 'v1')");

        $this->savedUser = $GLOBALS['user'] ?? null;
        $user = new User(null, null, []);
        $user->id = self::USER_ID;
        $GLOBALS['user'] = $user;
    }

    protected function tearDown(): void {
        $GLOBALS['user'] = $this->savedUser;
        $this->dropTables();
    }

    private function dropTables(): void {
        $this->db->execute('SELECT 1');
        foreach (array('survey_run_units', 'survey_runs', 'survey_units', 'survey_studies', 'survey_items') as $t) {
            $this->db->exec("DROP TABLE IF EXISTS $t");
        }
    }

    private function makeRun(): Run {
        $run = new Run();
        $run->id = self::RUN_ID;
        $run->user_id = self::USER_ID;
        return $run;
    }

    private function runUnitRow(int $run_unit_id): array {
        return $this->db->findRow('survey_run_units', ['id' => $run_unit_id]);
    }

    /** Mirrors ajaxSaveRunUnit: the dialog posts unit_id + study_id + description. */
    private function resave(string $type, int $unit_id, array $params): RunUnit {
        $params += ['type' => $type, 'id' => $unit_id];
        $unit = RunUnitFactory::make($this->makeRun(), $params);
        $unit->create($params);
        return $unit;
    }

    private function unitType(int $id) {
        return $this->db->findValue('survey_units', ['id' => $id], 'type');
    }

    public function testLinkingAFormPointsTheRunUnitAtTheStudy() {
        $form = new Form($this->makeRun(), ['type' => 'Form']);
        $form->create(['type' => 'Form', 'position' => 10, 'study_id' => 30]);
        $run_unit_id = (int) $form->run_unit_id;
        $this->assertSame(30, (int) $this->runUnitRow($run_unit_id)['unit_id']);

        $saved = $this->resave('Form', 30, ['study_id' => 30, 'description' => 'renamed', 'position' => 10]);
        $this->assertInstanceOf(Form::class, $saved, 'the study row is type Form');
        $this->assertTrue($saved->valid);
        $this->assertSame(30, (int) $saved->surveyStudy->id, 'find() loaded the study before create() ran');

        $row = $this->runUnitRow($run_unit_id);
        $this->assertSame(30, (int) $row['unit_id']);
        $this->assertSame('renamed', $row['description']);
        $this->assertSame('Form', $this->unitType(30));
    }

    public function testLinkingAV1StudyKeepsItOnV1() {
        $form = new Form($this->makeRun(), ['type' => 'Form']);
        $form->create(['type' => 'Form', 'position' => 10, 'study_id' => 40]);
        $this->assertSame(40, (int) $this->runUnitRow((int) $form->run_unit_id)['unit_id']);
        $this->assertSame('v1', $this->db->findValue('survey_studies', ['id' => 40], 'rendering_mode'), 'only the survey settings change the engine');
        $this->assertSame('Survey', $this->unitType(40));
    }

    public function testResavingCanSwitchToAStudyOnTheOtherEngine() {
        $form = new Form($this->makeRun(), ['type' => 'Form']);
        $form->create(['type' => 'Form', 'position' => 10, 'study_id' => 30]);

        $saved = $this->resave('Survey', 30, ['study_id' => 40, 'position' => 10]);
        $this->assertSame(40, (int) $this->runUnitRow((int) $form->run_unit_id)['unit_id']);
        $this->assertNotInstanceOf(Form::class, RunUnitFactory::make($this->makeRun(), ['id' => 40]), 'a v1 study places a Survey');
    }

    public function testASurveyUnitLinksAV2Study() {
        $this->db->exec("INSERT INTO survey_units (id, type) VALUES (21, 'Survey')");
        $this->db->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position, description) VALUES (100, " . self::RUN_ID . ", 21, 10, 'x')");

        $this->resave('Survey', 21, ['study_id' => 30, 'position' => 10, 'run_unit_id' => 100]);
        $this->assertSame(30, (int) $this->runUnitRow(100)['unit_id']);
        $this->assertInstanceOf(Form::class, RunUnitFactory::make($this->makeRun(), ['id' => 30]), 'the study row makes it a Form');
    }

    public function testResavingASurveyStillRePointsTheRunUnitAtTheStudy() {
        // a Survey unit IS its study
        $this->db->exec("INSERT INTO survey_units (id, type) VALUES (21, 'Survey')");
        $this->db->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position, description) VALUES (100, " . self::RUN_ID . ", 21, 10, 'x')");

        $saved = $this->resave('Survey', 21, ['study_id' => 20, 'position' => 10, 'run_unit_id' => 100]);
        $this->assertInstanceOf(Survey::class, $saved);
        $this->assertNotInstanceOf(Form::class, $saved);
        $this->assertSame(20, (int) $this->runUnitRow(100)['unit_id']);
    }

    public function testFormIsASurveyForTypeChecks() {
        $this->assertInstanceOf(Survey::class, new Form($this->makeRun(), ['type' => 'Form']));
        $this->assertSame(['Survey', 'Form'], Survey::TYPES);
    }
}
