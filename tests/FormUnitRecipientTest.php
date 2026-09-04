<?php

/**
 * An `email` item answered inside a Form unit must feed the Email unit's
 * recipient logic the same way one answered in a v1 Survey does.
 *
 * RunSession::getRecipientEmail ("most recent reported address") joined
 * survey_units with type = 'Survey' only, so a Form session's answers were
 * never considered. Email::getPotentialRecipientFields resolved the study via
 * survey_run_units.unit_id = survey_studies.id — the v1 shared-PK convention
 * a Form (linked through survey_units.form_study_id) never satisfies, so the
 * admin dropdown could not offer the field either.
 */
class FormUnitRecipientTest extends \PHPUnit\Framework\TestCase {

    private const RUN_ID = 1;
    private const RUN_SESSION_ID = 5;

    /** @var DB */
    private $db;

    protected function setUp(): void {
        $this->db = DB::getInstance();
        $this->dropTables();
        $this->db->exec('CREATE TABLE survey_units (id INTEGER PRIMARY KEY, type TEXT, form_study_id INTEGER)');
        $this->db->exec('CREATE TABLE survey_run_units (id INTEGER PRIMARY KEY, run_id INTEGER, unit_id INTEGER, position INTEGER)');
        $this->db->exec('CREATE TABLE survey_studies (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->exec('CREATE TABLE survey_items (id INTEGER PRIMARY KEY, study_id INTEGER, type TEXT, name TEXT)');
        $this->db->exec('CREATE TABLE survey_unit_sessions (id INTEGER PRIMARY KEY, run_session_id INTEGER, unit_id INTEGER, run_unit_id INTEGER)');
        $this->db->exec('CREATE TABLE survey_items_display (id INTEGER PRIMARY KEY, session_id INTEGER, item_id INTEGER, answer TEXT, answered TEXT)');

        $r = self::RUN_ID;
        // 20: v1 Survey unit for study 20; 31: Form unit for study 30; 32: a Page
        $this->db->exec("INSERT INTO survey_units (id, type, form_study_id) VALUES (20, 'Survey', NULL), (31, 'Form', 30), (32, 'Page', NULL)");
        $this->db->exec("INSERT INTO survey_studies (id, name) VALUES (20, 'intro'), (30, 'form_study')");
        $this->db->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position) VALUES (100, $r, 20, 10), (101, $r, 31, 20), (102, $r, 32, 30)");
        $this->db->exec("INSERT INTO survey_items (id, study_id, type, name) VALUES
            (1, 20, 'email', 'email_v1'), (2, 20, 'text', 'name'), (3, 30, 'email', 'email_v2'), (4, 30, 'text', 'comment')");
    }

    protected function tearDown(): void {
        $this->dropTables();
    }

    private function dropTables(): void {
        $this->db->execute('SELECT 1');
        foreach (array('survey_run_units', 'survey_units', 'survey_studies', 'survey_items', 'survey_unit_sessions', 'survey_items_display') as $t) {
            $this->db->exec("DROP TABLE IF EXISTS $t");
        }
    }

    private function makeRun(): Run {
        $run = new Run();
        $run->id = self::RUN_ID;
        $run->name = 'test_run';
        return $run;
    }

    private function makeRunSession(): RunSession {
        $rs = new RunSession(null, $this->makeRun());
        $rs->id = self::RUN_SESSION_ID;
        return $rs;
    }

    private function answer(int $session_id, int $unit_id, int $run_unit_id, int $item_id, string $answer, string $answered): void {
        $rs = self::RUN_SESSION_ID;
        $this->db->exec("INSERT OR IGNORE INTO survey_unit_sessions (id, run_session_id, unit_id, run_unit_id) VALUES ($session_id, $rs, $unit_id, $run_unit_id)");
        $this->db->insert('survey_items_display', ['session_id' => $session_id, 'item_id' => $item_id, 'answer' => $answer, 'answered' => $answered]);
    }

    public function testMostRecentAddressIsFoundInAFormSession() {
        $this->answer(1000, 31, 101, 3, 'form@example.org', '2026-09-01 10:00:00');
        $this->assertSame('form@example.org', $this->makeRunSession()->getRecipientEmail());
    }

    public function testMostRecentAddressPrefersTheLatestAnswerAcrossEngines() {
        $this->answer(1000, 20, 100, 1, 'old@example.org', '2026-09-01 10:00:00');
        $this->answer(1001, 31, 101, 3, 'new@example.org', '2026-09-01 11:00:00');
        $this->assertSame('new@example.org', $this->makeRunSession()->getRecipientEmail());

        $this->answer(1002, 20, 100, 1, 'newer@example.org', '2026-09-01 12:00:00');
        $this->assertSame('newer@example.org', $this->makeRunSession()->getRecipientEmail());
    }

    public function testAnswersOfOtherRunSessionsAreIgnored() {
        $this->db->exec("INSERT INTO survey_unit_sessions (id, run_session_id, unit_id, run_unit_id) VALUES (2000, 6, 31, 101)");
        $this->db->insert('survey_items_display', ['session_id' => 2000, 'item_id' => 3, 'answer' => 'theirs@example.org', 'answered' => '2026-09-01 10:00:00']);
        $this->assertEmpty($this->makeRunSession()->getRecipientEmail());
    }

    public function testPotentialRecipientFieldsIncludeFormStudies() {
        $email = new Email($this->makeRun(), ['type' => 'Email']);
        $m = new ReflectionMethod(Email::class, 'getPotentialRecipientFields');
        $m->setAccessible(true);
        $ids = array_column($m->invoke($email), 'id');

        $this->assertSame('most recent reported address', $ids[0]);
        $this->assertContains('intro$email_v1', $ids);
        $this->assertContains('form_study$email_v2', $ids, 'the Form-linked study must be offered');
        $this->assertNotContains('intro$name', $ids);
        $this->assertCount(3, $ids);

        // a study placed twice lists its email item once; another run's study not at all
        $this->db->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position) VALUES (103, " . self::RUN_ID . ", 31, 40), (200, 2, 20, 10)");
        $this->assertCount(3, $m->invoke($email));
    }
}
