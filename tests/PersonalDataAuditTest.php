<?php

require_once __DIR__ . '/../setup.php';

/**
 * PersonalDataAudit: the classifier's word-boundary rules and the
 * admin → run → survey grouping of report(), on SQLite fixtures.
 */
class PersonalDataAuditTest extends PHPUnit\Framework\TestCase {

    const TABLES = array(
        'survey_users' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, admin INTEGER DEFAULT 0, user_code TEXT, created TEXT',
        'survey_studies' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, results_table TEXT, created TEXT, modified TEXT',
        'survey_items' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, study_id INTEGER, type TEXT, name TEXT, label TEXT, `order` INTEGER DEFAULT 0',
        'survey_runs' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, public INTEGER DEFAULT 0',
        'survey_units' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT',
        'survey_run_units' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER, unit_id INTEGER, position INTEGER DEFAULT 0',
        'survey_run_sessions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER, testing INTEGER DEFAULT 0',
        'survey_study_metrics' => 'study_id INTEGER PRIMARY KEY, finished INTEGER DEFAULT 0, testers INTEGER DEFAULT 0, real_users INTEGER DEFAULT 0',
    );

    /**
     * The :memory: DB is shared across test classes and several of them
     * CREATE IF NOT EXISTS these tables with their own column sets, so
     * this class owns fresh copies for its duration and removes them
     * again (tearDownAfterClass) so later classes get theirs back.
     */
    public static function setUpBeforeClass(): void {
        $pdo = DB::getInstance()->pdo();
        foreach (self::TABLES as $table => $columns) {
            $pdo->exec("DROP TABLE IF EXISTS $table");
            $pdo->exec("CREATE TABLE $table ($columns)");
        }
    }

    public static function tearDownAfterClass(): void {
        $pdo = DB::getInstance()->pdo();
        foreach (array_keys(self::TABLES) as $table) {
            $pdo->exec("DROP TABLE IF EXISTS $table");
        }
    }

    protected function setUp(): void {
        $pdo = DB::getInstance()->pdo();
        foreach (array_keys(self::TABLES) as $table) {
            $pdo->exec("DELETE FROM $table");
        }
    }

    // ---- classify() -------------------------------------------------

    public function testTypedItemsAreFlaggedWhateverTheirName() {
        $this->assertSame('contact', PersonalDataAudit::classify('email', 'q17', 'Anything')['category']);
        $this->assertSame('contact', PersonalDataAudit::classify('tel', 'q18', '')['category']);
        $this->assertSame('contact', PersonalDataAudit::classify('request_phone', 'x', '')['category']);
        $this->assertSame('location', PersonalDataAudit::classify('geopoint', 'where', '')['category']);
        $this->assertSame('uploads', PersonalDataAudit::classify('file', 'cv', '')['category']);
        $this->assertSame('technical', PersonalDataAudit::classify('ip', 'ip', '')['category']);
        $this->assertSame('technical', PersonalDataAudit::classify('browser', 'ua', '')['category']);
        $this->assertSame('technical', PersonalDataAudit::classify('data_quality', 'dq_p1', '')['category']);
        // An agent's self-description is not a person's data.
        $this->assertNull(PersonalDataAudit::classify('agent_probe', 'dq_agent_p1', ''));
        $this->assertStringContainsString('item type', PersonalDataAudit::classify('email', 'q17', '')['reason']);
    }

    public function testFreeTextNamesMatchAtWordStartsAcrossNamingStyles() {
        $this->assertSame('contact', PersonalDataAudit::classify('text', 'phone_number', '')['category']);
        $this->assertSame('contact', PersonalDataAudit::classify('text', 'phoneNumber', '')['category']);
        $this->assertSame('contact', PersonalDataAudit::classify('text', 'Telefonnummer', '')['category']);
        $this->assertSame('identity', PersonalDataAudit::classify('text', 'first_name', '')['category']);
        $this->assertSame('identity', PersonalDataAudit::classify('text', 'firstname', '')['category']);
        $this->assertSame('identity', PersonalDataAudit::classify('date', 'birthday', '')['category']);
        $this->assertSame('identity', PersonalDataAudit::classify('text', 'dob', '')['category']);
        $this->assertSame('address', PersonalDataAudit::classify('text', 'zip', '')['category']);
        $this->assertSame('ids', PersonalDataAudit::classify('get', 'PROLIFIC_PID', '')['category']);
        $this->assertSame('ids', PersonalDataAudit::classify('hidden', 'workerId', '')['category']);
        $this->assertSame('ids', PersonalDataAudit::classify('text', 'sona_id', '')['category']);
        $this->assertSame('name contains "phone"', PersonalDataAudit::classify('text', 'phone_number', '')['reason']);
    }

    public function testLabelsAreASecondPassAndReadThroughMarkup() {
        $hit = PersonalDataAudit::classify('text', 'q3', '<b>What is your name?</b>');
        $this->assertSame('identity', $hit['category']);
        $this->assertSame('label mentions "your name"', $hit['reason']);
        $this->assertSame('contact', PersonalDataAudit::classify('textarea', 'q4', 'Bitte geben Sie Ihre E-Mail-Adresse an')['category']);
        $this->assertSame('ids', PersonalDataAudit::classify('text', 'q5', 'Your IBAN for the reimbursement')['category']);
        // name outranks label
        $this->assertSame('name contains "iban"', PersonalDataAudit::classify('text', 'iban', 'What is your name?')['reason']);
    }

    public function testExactKeywordsAndPrefixesDoNotFireInsideOtherWords() {
        $this->assertNull(PersonalDataAudit::classify('text', 'restlessness', ''));      // ssn
        $this->assertNull(PersonalDataAudit::classify('text', 'personality_1', ''));     // sona
        $this->assertNull(PersonalDataAudit::classify('text', 'zipper_count', ''));      // zip
        $this->assertNull(PersonalDataAudit::classify('text', 'hotel_rating', ''));      // tel
        $this->assertNull(PersonalDataAudit::classify('text', 'template', ''));          // neither "tel" (exact) nor "telep"
        $this->assertNull(PersonalDataAudit::classify('number', 'age', 'How old are you?'));
        $this->assertNull(PersonalDataAudit::classify('text', 'ambankment', ''));        // bank exact
    }

    public function testChoiceAndDisplayItemsAreNeverFlagged() {
        $this->assertNull(PersonalDataAudit::classify('mc', 'phone_owner', 'Do you own a phone?'));
        $this->assertNull(PersonalDataAudit::classify('note', 'intro', 'Please enter your email on the next page'));
        $this->assertNull(PersonalDataAudit::classify('select_one', 'birth_country', ''));
        $this->assertNull(PersonalDataAudit::classify('submit', 'submit', ''));
    }

    // ---- report() ---------------------------------------------------

    private function seed() {
        $db = DB::getInstance();
        $db->insert('survey_users', array('id' => 1, 'email' => 'alice@example.org', 'admin' => 1));
        $db->insert('survey_users', array('id' => 2, 'email' => 'bob@example.org', 'admin' => 2));
        $db->insert('survey_studies', array('id' => 10, 'user_id' => 1, 'name' => 'contact_survey'));
        $db->insert('survey_studies', array('id' => 11, 'user_id' => 2, 'name' => 'geo_survey'));
        $db->insert('survey_studies', array('id' => 12, 'user_id' => 1, 'name' => 'clean_survey'));
        $items = array(
            array(10, 'email', 'em', 'Your email'), array(10, 'tel', 'tel1', ''), array(10, 'text', 'phone_number', 'Phone'),
            array(10, 'number', 'age', 'How old are you?'), array(10, 'get', 'PROLIFIC_PID', ''), array(10, 'file', 'photo', 'Upload'),
            array(10, 'mc', 'phone_owner', 'Do you own a phone?'),
            array(11, 'geopoint', 'loc', ''),
            array(12, 'text', 'q1', 'How are you?'),
        );
        foreach ($items as $i => $it) {
            $db->insert('survey_items', array('study_id' => $it[0], 'type' => $it[1], 'name' => $it[2], 'label' => $it[3], 'order' => $i));
        }
        foreach (array(10 => 'Survey', 11 => 'Survey', 12 => 'Survey', 500 => 'Email') as $id => $type) {
            $db->insert('survey_units', array('id' => $id, 'type' => $type));
        }
        $db->insert('survey_runs', array('id' => 100, 'user_id' => 1, 'name' => 'alice_run', 'public' => 2));
        $db->insert('survey_run_units', array('run_id' => 100, 'unit_id' => 10, 'position' => 10));
        $db->insert('survey_run_units', array('run_id' => 100, 'unit_id' => 500, 'position' => 20));
        $db->insert('survey_run_units', array('run_id' => 100, 'unit_id' => 12, 'position' => 30));
        foreach (array(0, 0, 0, 1) as $testing) {
            $db->insert('survey_run_sessions', array('run_id' => 100, 'testing' => $testing));
        }
        $db->insert('survey_study_metrics', array('study_id' => 10, 'finished' => 2, 'real_users' => 3, 'testers' => 1));
    }

    public function testReportGroupsFlaggedItemsByAdminRunAndSurvey() {
        $this->seed();
        $report = PersonalDataAudit::report();

        $this->assertSame(array('admins' => 2, 'runs' => 1, 'surveys' => 2, 'items' => 6), array_slice($report['totals'], 0, 4));
        $this->assertSame(array('contact' => 3, 'ids' => 1, 'uploads' => 1, 'location' => 1), $report['totals']['by_category']);
        $this->assertSame(array(1, 2), array_keys($report['admins']), 'sorted by email');

        $alice = $report['admins'][1];
        $this->assertSame('alice@example.org', $alice['email']);
        $this->assertSame(array(), $alice['orphan_surveys']);
        $run = $alice['runs'][100];
        $this->assertSame('alice_run', $run['name']);
        $this->assertSame(3, $run['sessions'], 'tester sessions are not counted');
        $this->assertSame(1, $run['email_units']);
        $this->assertSame(array(10), array_keys($run['surveys']), 'the clean survey is not listed');
        $study = $run['surveys'][10];
        $this->assertSame(3, $study['real_users']);
        $this->assertSame(2, $study['finished']);
        $this->assertSame(array('em', 'tel1', 'phone_number', 'PROLIFIC_PID', 'photo'), array_column($study['items'], 'name'));
        $this->assertSame('name contains "prolific"', $study['items'][3]['reason']);
        $this->assertSame(array('runs' => 1, 'surveys' => 1, 'items' => 5, 'participants' => 3, 'by_category' => array('contact' => 3, 'ids' => 1, 'uploads' => 1)), $alice['summary']);

        $bob = $report['admins'][2];
        $this->assertSame(array('runs' => 0, 'surveys' => 1, 'items' => 1, 'participants' => 0, 'by_category' => array('location' => 1)), $bob['summary']);
        $this->assertSame(array(), $bob['runs']);
        $orphan = $bob['orphan_surveys'][11];
        $this->assertSame('geo_survey', $orphan['name']);
        $this->assertNull($orphan['real_users'], 'no metrics row → unknown, not 0');
        $this->assertSame('location', $orphan['items'][0]['category']);
    }

    public function testASurveyUsedInTwoRunsIsListedUnderEach() {
        $this->seed();
        $db = DB::getInstance();
        $db->insert('survey_runs', array('id' => 101, 'user_id' => 2, 'name' => 'bob_run', 'public' => 0));
        $db->insert('survey_run_units', array('run_id' => 101, 'unit_id' => 10, 'position' => 10));
        $report = PersonalDataAudit::report();
        $this->assertSame(2, $report['totals']['runs']);
        $this->assertSame(2, $report['totals']['surveys'], 'a shared survey is one survey');
        $this->assertArrayHasKey(10, $report['admins'][1]['runs'][100]['surveys']);
        $this->assertArrayHasKey(10, $report['admins'][2]['runs'][101]['surveys']);
        $this->assertSame(0, $report['admins'][2]['runs'][101]['sessions']);
    }

    public function testAStudyPlacedOnlyThroughAFormIsNotAnOrphan() {
        $this->seed();
        $db = DB::getInstance();
        // A v2 study's unit row is type 'Form'; its placement's unit_id is
        // the study id, as for a Survey.
        $db->update('survey_units', array('type' => 'Form'), array('id' => 11));
        $db->insert('survey_runs', array('id' => 102, 'user_id' => 2, 'name' => 'bob_form_run', 'public' => 1));
        $db->insert('survey_run_units', array('run_id' => 102, 'unit_id' => 11, 'position' => 10));
        $report = PersonalDataAudit::report();
        $bob = $report['admins'][2];
        $this->assertSame(array(), $bob['orphan_surveys']);
        $this->assertSame('bob_form_run', $bob['runs'][102]['name']);
        $this->assertArrayHasKey(11, $bob['runs'][102]['surveys']);
    }

    public function testEmptyInstanceReportsNothing() {
        $report = PersonalDataAudit::report();
        $this->assertSame(0, $report['totals']['items']);
        $this->assertSame(array(), $report['admins']);
        $this->assertSame(array(), $report['totals']['by_category']);
    }

}
