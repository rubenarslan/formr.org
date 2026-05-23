<?php

use PHPUnit\Framework\TestCase;

/**
 * Verifies the SQL composition + response shape of
 * UnitSessionResource::handle(). Auth (scope + run-ownership + per-client
 * allowlist) is covered separately in ApiBaseScopingTest — here we
 * override getRunByName / checkScope to focus on the SQL output.
 */
class _UnitSessionFixture extends UnitSessionResource
{
    /** @var Run|null */
    public $stubRun;

    public function callHandle($runName)
    {
        // Surface the protected handle() method to the test.
        return $this->handle($runName);
    }

    protected function getRunByName($runName)
    {
        return $this->stubRun;
    }

    protected function checkScope($requiredScope)
    {
        // bypass — scope plumbing has its own tests
    }
}

class UnitSessionResourceTest extends TestCase
{
    /** @var \PDO */
    private static $pdo;
    /** @var DB */
    private static $db;

    public static function setUpBeforeClass(): void
    {
        self::$db  = DB::getInstance();
        self::$pdo = self::$db->pdo();

        self::$pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS survey_run_sessions (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                session TEXT NOT NULL,
                testing INTEGER DEFAULT 0
            )
SQL);
        self::$pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS survey_unit_sessions (
                id INTEGER PRIMARY KEY,
                unit_id INTEGER NOT NULL,
                run_session_id INTEGER NOT NULL,
                iteration INTEGER,
                created TEXT NOT NULL,
                expires TEXT,
                ended TEXT,
                expired TEXT,
                result TEXT,
                state TEXT
            )
SQL);
        self::$pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS survey_units (
                id INTEGER PRIMARY KEY,
                type TEXT NOT NULL
            )
SQL);
        self::$pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS survey_run_units (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                unit_id INTEGER NOT NULL,
                position INTEGER,
                description TEXT
            )
SQL);
        self::$pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS survey_run_special_units (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                description TEXT
            )
SQL);
    }

    protected function setUp(): void
    {
        foreach (['survey_run_sessions', 'survey_unit_sessions', 'survey_units',
                  'survey_run_units', 'survey_run_special_units'] as $t) {
            self::$pdo->exec("DELETE FROM $t");
        }

        // run 1 has two participants ('aaa' real, 'bbb' testing).
        self::$pdo->exec("INSERT INTO survey_run_sessions (id, run_id, session, testing) VALUES
            (10, 1, 'aaa', 0),
            (20, 1, 'bbb', 1)");

        // run structure: position 10 is a Survey, position 20 is a Page.
        self::$pdo->exec("INSERT INTO survey_units (id, type) VALUES
            (100, 'Survey'),
            (200, 'Page'),
            (300, 'Page')");

        self::$pdo->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position, description) VALUES
            (1, 1, 100, 10, 'Intake'),
            (2, 1, 200, 20, 'Thanks')");

        // special unit: OverviewScriptPage. position is NULL on purpose.
        self::$pdo->exec("INSERT INTO survey_run_special_units (id, run_id, description) VALUES
            (300, 1, 'Overview')");

        // unit_sessions: 'aaa' visited Intake then Thanks then the overview;
        // 'bbb' is still at Intake.
        self::$pdo->exec("INSERT INTO survey_unit_sessions
            (id, unit_id, run_session_id, iteration, created, ended, result, state) VALUES
            (1000, 100, 10, 1, '2026-05-01 09:00:00', '2026-05-01 09:05:00', 'survey_ended', 'ENDED'),
            (1001, 200, 10, 1, '2026-05-01 09:05:00', '2026-05-01 09:05:01', 'ended', 'ENDED'),
            (1002, 300, 10, 1, '2026-05-01 09:05:30', NULL,                 NULL,           'PENDING'),
            (2000, 100, 20, 1, '2026-05-02 14:00:00', NULL,                 NULL,           'PENDING')");
    }

    private function makeFixture(array $params = [])
    {
        $stub = new Run(Run::TEST_RUN);
        $stub->id   = 1;
        $stub->name = 'testrun';

        $ref = new ReflectionClass(_UnitSessionFixture::class);
        /** @var _UnitSessionFixture $obj */
        $obj = $ref->newInstanceWithoutConstructor();
        $obj->stubRun = $stub;

        $baseRef = new ReflectionClass(ApiBase::class);
        foreach (['db' => self::$db, 'request' => new Request($params)] as $name => $val) {
            $prop = $baseRef->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($obj, $val);
        }
        return $obj;
    }

    private function rows($fixture, $runName = 'testrun')
    {
        $result = $fixture->callHandle($runName);
        $data = $result->getData();
        $this->assertSame(200, $data['statusCode']);
        return $data['response'];
    }

    public function testReturnsAllUnitSessionsOrderedBySessionThenCreated()
    {
        $rows = $this->rows($this->makeFixture());
        $this->assertCount(4, $rows);

        // aaa's three rows first (ordered by created), then bbb's one.
        $this->assertSame('aaa', $rows[0]['session']);
        $this->assertSame('aaa', $rows[1]['session']);
        $this->assertSame('aaa', $rows[2]['session']);
        $this->assertSame('bbb', $rows[3]['session']);

        $this->assertLessThanOrEqual($rows[1]['created'], $rows[0]['created'] . ' 99');
        $this->assertSame($rows[0]['created'], '2026-05-01 09:00:00');
        $this->assertSame($rows[1]['created'], '2026-05-01 09:05:00');
        $this->assertSame($rows[2]['created'], '2026-05-01 09:05:30');
    }

    public function testProjectsPositionsAndDescriptionsCorrectly()
    {
        $rows = $this->rows($this->makeFixture());

        // Survey at position 10 ('Intake')
        $this->assertSame(10, $rows[0]['position']);
        $this->assertSame('Survey', $rows[0]['unit_type']);
        $this->assertSame('Intake', $rows[0]['unit_description']);

        // Page at position 20 ('Thanks')
        $this->assertSame(20, $rows[1]['position']);
        $this->assertSame('Thanks', $rows[1]['unit_description']);

        // OverviewScriptPage: position is NULL (special unit), description
        // comes from survey_run_special_units.
        $this->assertNull($rows[2]['position']);
        $this->assertSame('Overview', $rows[2]['unit_description']);
    }

    public function testCastsTypesCleanly()
    {
        $rows = $this->rows($this->makeFixture());
        $row = $rows[0];
        $this->assertIsInt($row['unit_session_id']);
        $this->assertIsInt($row['unit_id']);
        $this->assertIsInt($row['position']);
        $this->assertIsInt($row['iteration']);
        $this->assertIsBool($row['testing']);
        $this->assertFalse($row['testing']);
        // bbb's row (testing=1) is the last
        $this->assertTrue($rows[3]['testing']);
    }

    public function testFiltersBySingleSessionCode()
    {
        $rows = $this->rows($this->makeFixture(['session' => 'bbb']));
        $this->assertCount(1, $rows);
        $this->assertSame('bbb', $rows[0]['session']);
    }

    public function testFiltersByCommaListOfSessionCodes()
    {
        $rows = $this->rows($this->makeFixture(['session' => 'aaa,bbb']));
        $this->assertCount(4, $rows);
    }

    public function testFiltersByTestingFlag()
    {
        $real = $this->rows($this->makeFixture(['testing' => '0']));
        foreach ($real as $r) {
            $this->assertFalse($r['testing']);
        }
        $this->assertCount(3, $real);

        $tst = $this->rows($this->makeFixture(['testing' => '1']));
        foreach ($tst as $r) {
            $this->assertTrue($r['testing']);
        }
        $this->assertCount(1, $tst);
    }

    public function testRejectsNonGetMethods()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            $result = $this->makeFixture()->callHandle('testrun');
            $data = $result->getData();
            $this->assertSame(405, $data['statusCode']);
        } finally {
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
    }

    public function testEmptyResponseWhenRunHasNoUnitSessions()
    {
        self::$pdo->exec('DELETE FROM survey_unit_sessions');
        $rows = $this->rows($this->makeFixture());
        $this->assertSame([], $rows);
    }
}
