<?php

/**
 * A run bundle exported "with survey details" must round-trip a Form unit:
 * Run::export ships the Form's study (resolved through the dialog's
 * study_id or survey_units.form_study_id) and Form::create materialises it
 * via SurveyStudy::createFromData with place_unit=false — study only, no
 * stray Survey unit — then links it through form_study_id. The v2 engine
 * settings travel in getSettings() and are validated on the way back in.
 *
 * Item saving needs MySQL DDL, so the full import runs only against a live
 * instance; this covers the pieces that decide *what* is created.
 */
class FormImportRoundTripTest extends \PHPUnit\Framework\TestCase {

    private const USER_ID = 7;

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
        $this->db->exec('CREATE TABLE survey_items (id INTEGER PRIMARY KEY, study_id INTEGER, type TEXT, choice_list TEXT, type_options TEXT, name TEXT, label TEXT, label_parsed TEXT, optional INTEGER, class TEXT, showif TEXT, showif_js TEXT, value TEXT, block_order TEXT, item_order INTEGER, deleted TEXT)');
        $this->db->exec('CREATE TABLE survey_item_choices (id INTEGER PRIMARY KEY, study_id INTEGER, list_name TEXT, name TEXT, label TEXT, label_parsed TEXT)');

        $this->db->exec("INSERT INTO survey_units (id, type, form_study_id) VALUES (20, 'Survey', NULL), (31, 'Form', 30)");
        $this->db->exec("INSERT INTO survey_studies (id, user_id, name, results_table) VALUES
            (20, " . self::USER_ID . ", 'intro', 's20_intro'), (30, " . self::USER_ID . ", 'form_study', 's30_form_study')");

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
        foreach (array('survey_run_units', 'survey_units', 'survey_studies', 'survey_items', 'survey_item_choices') as $t) {
            $this->db->exec("DROP TABLE IF EXISTS $t");
        }
    }

    private function makeRun(int $id = 1): Run {
        $run = new Run();
        $run->id = $id;
        $run->user_id = self::USER_ID;
        return $run;
    }

    private function exportedStudyId(object $unit): int {
        $m = new ReflectionMethod(Run::class, 'exportedStudyId');
        $m->setAccessible(true);
        return $m->invoke($this->makeRun(), $unit);
    }

    private static function validate(array $settings): array {
        $m = new ReflectionMethod(SurveyStudy::class, 'validateEngineSettings');
        $m->setAccessible(true);
        return $m->invoke(null, $settings);
    }

    private function runUnitRows(): array {
        return $this->db->select('unit_id, run_id, position')->from('survey_run_units')->fetchAll();
    }

    // ---- export side --------------------------------------------------

    public function testSurveyUnitExportsItsOwnId() {
        $this->assertSame(20, $this->exportedStudyId((object) ['type' => 'Survey', 'unit_id' => 20]));
    }

    public function testFormUnitExportsDialogStudyId() {
        // run.js serialize() posts the dialog's study_id select alongside unit_id
        $this->assertSame(30, $this->exportedStudyId((object) ['type' => 'Form', 'unit_id' => 31, 'study_id' => '30']));
    }

    public function testFormUnitFallsBackToFormStudyId() {
        // exportRunStructureAction builds units server-side, without the dialog fields
        $this->assertSame(30, $this->exportedStudyId((object) ['type' => 'Form', 'unit_id' => 31]));
        $this->assertSame(30, $this->exportedStudyId((object) ['type' => 'Form', 'unit_id' => 31, 'study_id' => '']));
        $this->assertSame(30, $this->exportedStudyId((object) ['type' => 'Form', 'unit_id' => 31, 'study_id' => 'x']));
    }

    public function testFormUnitWithoutStudyExportsNothing() {
        $this->db->exec("INSERT INTO survey_units (id, type, form_study_id) VALUES (32, 'Form', NULL)");
        $this->assertSame(0, $this->exportedStudyId((object) ['type' => 'Form', 'unit_id' => 32]));
        $this->assertSame(0, $this->exportedStudyId((object) ['type' => 'Form', 'unit_id' => 999]));
    }

    public function testGetSettingsCarriesEngineSettings() {
        $study = new SurveyStudy();
        $study->rendering_mode = 'v2';
        $study->layout = 'solo';
        $study->language = 'de';
        $settings = $study->getSettings();
        foreach (['rendering_mode', 'offline_mode', 'allow_previous', 'layout', 'language', 'use_paging'] as $key) {
            $this->assertArrayHasKey($key, $settings);
        }
        $this->assertSame('v2', $settings['rendering_mode']);
        $this->assertSame('solo', $settings['layout']);
        $this->assertSame('de', $settings['language']);
    }

    // ---- import side --------------------------------------------------

    public function testEngineSettingsValidatedLikeTheAdminForm() {
        $good = self::validate(['rendering_mode' => 'v2', 'offline_mode' => '1', 'allow_previous' => 0, 'layout' => 'solo', 'language' => ' de-CH ']);
        $this->assertSame(['rendering_mode' => 'v2', 'offline_mode' => 1, 'allow_previous' => 0, 'layout' => 'solo', 'language' => 'de-CH'], $good);

        $bad = self::validate(['rendering_mode' => 'v3', 'offline_mode' => 'yes', 'allow_previous' => true, 'layout' => '../x', 'language' => 'en<script>']);
        // invalid enums/strings are dropped (column default applies); flags normalise to 0/1
        $this->assertSame(['offline_mode' => 0, 'allow_previous' => 1], $bad);

        // absent keys stay absent — a v1 bundle must not be stamped with anything
        $this->assertSame(['use_paging' => 1], self::validate(['use_paging' => 1]));
    }

    public function testCreateFromDataRejectsForeignUserId() {
        $data = (object) ['name' => 'intro', 'items' => [(object) ['name' => 'q', 'type' => 'text']]];
        $this->assertFalse(SurveyStudy::createFromData($data, ['user_id' => self::USER_ID + 1, 'run' => $this->makeRun()]));
        $this->assertSame([], $this->runUnitRows());
    }

    /**
     * The Survey import path relies on createFromData placing the unit;
     * the Form path must get the study only. Item saving fails on SQLite
     * (MySQL DDL), which is fine here — placement of an existing study
     * happens before it, and that is what's under test.
     */
    public function testExistingStudyIsPlacedOnlyWhenAsked() {
        $data = (object) ['name' => 'intro', 'items' => [(object) ['name' => 'q', 'type' => 'text']]];
        $base = ['type' => 'Survey', 'position' => 40, 'importing' => true, 'run' => $this->makeRun(), 'user_id' => self::USER_ID];

        SurveyStudy::createFromData($data, $base);
        $rows = $this->runUnitRows();
        $this->assertCount(1, $rows, 'default: a Survey unit for the existing study lands in the run');
        $this->assertSame(20, (int) $rows[0]['unit_id']);
        $this->assertSame(40, (int) $rows[0]['position']);

        $this->db->exec('DELETE FROM survey_run_units');
        SurveyStudy::createFromData($data, ['place_unit' => false] + $base);
        $this->assertSame([], $this->runUnitRows(), 'place_unit=false: the caller places the study itself');

        // no run at all (standalone survey JSON upload): nothing to place, no crash
        $this->db->exec('DELETE FROM survey_run_units');
        SurveyStudy::createFromData($data, ['user_id' => self::USER_ID]);
        $this->assertSame([], $this->runUnitRows());
    }

    /**
     * A study on the form_v2 engine only runs as v2 in a Form unit; a Survey
     * unit would render it on v1. So a Survey unit refuses it — also on the
     * import path, where createFromData places a Survey unit for an
     * existing study.
     */
    public function testASurveyUnitRefusesAStudyOnV2() {
        $this->db->exec("UPDATE survey_studies SET rendering_mode = 'v2' WHERE id = 20");
        $survey = new Survey($this->makeRun(), ['type' => 'Survey']);
        $survey->create(['type' => 'Survey', 'position' => 70, 'study_id' => 20]);
        $this->assertNull($survey->surveyStudy, 'not linked');
        $this->assertNotSame(20, (int) $survey->unit_id);
        foreach ($this->runUnitRows() as $row) {
            $this->assertNotSame(20, (int) $row['unit_id'], 'no run unit points at the v2 study');
        }

        $this->db->exec("UPDATE survey_studies SET rendering_mode = 'v1' WHERE id = 20");
        $v1 = new Survey($this->makeRun(), ['type' => 'Survey']);
        $v1->create(['type' => 'Survey', 'position' => 80, 'study_id' => 20]);
        $this->assertSame(20, (int) $v1->unit_id, 'a v1 study still links');
    }

    public function testFormLinksStudyOnlyWhenRunOwnerOwnsIt() {
        $form = new Form($this->makeRun(), ['type' => 'Form']);
        $form->create(['type' => 'Form', 'position' => 50, 'study_id' => 30]);
        $this->assertTrue($form->valid);
        $this->assertSame(30, (int) $this->db->findValue('survey_units', ['id' => $form->id], 'form_study_id'));
        $this->assertSame('v2', $this->db->findValue('survey_studies', ['id' => 30], 'rendering_mode'));
        $this->assertSame('form_study', $form->description, 'description defaults to the study name');
        $rows = $this->runUnitRows();
        $this->assertCount(1, $rows);
        $this->assertSame((int) $form->id, (int) $rows[0]['unit_id'], 'the run points at the Form row, not the study');

        // another user's study: Form is created but stays unlinked
        $this->db->exec("INSERT INTO survey_studies (id, user_id, name, results_table) VALUES (60, 99, 'theirs', 's60_theirs')");
        $other = new Form($this->makeRun(), ['type' => 'Form']);
        $other->create(['type' => 'Form', 'position' => 60, 'study_id' => 60]);
        $this->assertNull($this->db->findValue('survey_units', ['id' => $other->id], 'form_study_id'));
        $this->assertSame('v1', $this->db->findValue('survey_studies', ['id' => 60], 'rendering_mode'));
    }
}
