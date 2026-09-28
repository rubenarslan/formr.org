<?php

/**
 * Re-saving a Form unit from the run editor must keep survey_run_units
 * pointing at the Form's own survey_units row.
 *
 * AdminAjaxController::ajaxSaveRunUnit builds the unit with its id, so the
 * constructor's find() loads the study into $surveyStudy (Form::getStudy via
 * form_study_id). Survey::create then reaches its "persist the study link"
 * step, which for a v1 Survey means re-pointing survey_run_units.unit_id at
 * the study id — turning the Form into a v1 Survey unit on every save.
 * Form overrides that step (persistStudyLink) to write the description only.
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
        $this->db->exec('CREATE TABLE survey_units (id INTEGER PRIMARY KEY, type TEXT, form_study_id INTEGER, created TEXT, modified TEXT)');
        $this->db->exec('CREATE TABLE survey_run_units (id INTEGER PRIMARY KEY, run_id INTEGER, unit_id INTEGER, position INTEGER, description TEXT)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS survey_runs (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->exec('CREATE TABLE survey_studies (id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, results_table TEXT, unlinked INTEGER DEFAULT 0, rendering_mode TEXT DEFAULT "v1")');
        // Form::create asks the study whether v2 can run it (formV2UnsupportedItems).
        $this->db->exec('CREATE TABLE survey_items (id INTEGER PRIMARY KEY, study_id INTEGER, name TEXT, type TEXT, type_options TEXT, choice_list TEXT, label TEXT, label_parsed TEXT, optional INTEGER, class TEXT, showif TEXT, showif_js TEXT, value TEXT, item_order INTEGER, `order` INTEGER, block_order INTEGER, deleted TEXT)');

        $u = self::USER_ID;
        // 20: v1 Survey unit (unit id == study id); 30: a study rendered by Forms
        $this->db->exec("INSERT INTO survey_units (id, type, form_study_id) VALUES (20, 'Survey', NULL)");
        $this->db->exec("INSERT INTO survey_studies (id, user_id, name, results_table, rendering_mode) VALUES
            (20, $u, 'intro', 's20_intro', 'v1'), (30, $u, 'form_study', 's30_form_study', 'v2')");

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
        foreach (array('survey_run_units', 'survey_units', 'survey_studies', 'survey_items') as $t) {
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

    public function testResavingAFormKeepsTheRunUnitOnTheFormRow() {
        $form = new Form($this->makeRun(), ['type' => 'Form']);
        $form->create(['type' => 'Form', 'position' => 10, 'study_id' => 30]);
        $form_id = (int) $form->id;
        $run_unit_id = (int) $form->run_unit_id;
        $this->assertSame($form_id, (int) $this->runUnitRow($run_unit_id)['unit_id']);

        $saved = $this->resave('Form', $form_id, ['study_id' => 30, 'description' => 'renamed', 'position' => 10]);
        $this->assertInstanceOf(Form::class, $saved);
        $this->assertTrue($saved->valid);
        $this->assertSame(30, (int) $saved->surveyStudy->id, 'find() loaded the study before create() ran');

        $row = $this->runUnitRow($run_unit_id);
        $this->assertSame($form_id, (int) $row['unit_id'], 'survey_run_units must not be re-pointed at the study');
        $this->assertSame('renamed', $row['description']);
        $this->assertSame(30, (int) $this->db->findValue('survey_units', ['id' => $form_id], 'form_study_id'));
        $this->assertSame('Form', $this->db->findValue('survey_units', ['id' => $form_id], 'type'));

        // a second save with no changes is just as harmless
        $this->resave('Form', $form_id, ['study_id' => 30, 'position' => 10]);
        $this->assertSame($form_id, (int) $this->runUnitRow($run_unit_id)['unit_id']);
    }

    public function testResavingAFormCanSwitchItsStudy() {
        $this->db->exec("INSERT INTO survey_studies (id, user_id, name, results_table) VALUES (40, " . self::USER_ID . ", 'other', 's40_other')");
        $form = new Form($this->makeRun(), ['type' => 'Form']);
        $form->create(['type' => 'Form', 'position' => 10, 'study_id' => 30]);

        $this->resave('Form', (int) $form->id, ['study_id' => 40, 'position' => 10]);
        $this->assertSame((int) $form->id, (int) $this->runUnitRow((int) $form->run_unit_id)['unit_id']);
        $this->assertSame(40, (int) $this->db->findValue('survey_units', ['id' => $form->id], 'form_study_id'));
        $this->assertSame('v2', $this->db->findValue('survey_studies', ['id' => 40], 'rendering_mode'));
    }

    public function testResavingASurveyStillRePointsTheRunUnitAtTheStudy() {
        // the v1 contract Form opts out of: a Survey unit IS its study
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
