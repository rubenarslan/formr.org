<?php

/**
 * Regression coverage for the D1 same-unit-at-multiple-positions routing
 * bug fixed in v0.26.4.
 *
 * A run may slot the SAME unit (survey_units row) at several positions
 * (e.g. a monthly questionnaire at positions 50, 100, 200). Each
 * placement is its own survey_run_units row; Track A (047) stamps that
 * row's id onto every new unit-session as `run_unit_id`. Pre-fix, two
 * run-engine lookups still matched by unit_id alone:
 *
 *  - RunSession::getCurrentUnitSession() could adopt a session created
 *    at a DIFFERENT placement as "current" (position↔session drift,
 *    surfacing as a jump past the last same-unit placement on moveOn).
 *  - UnitSession::create()'s supersede flip (queued=-9/SUPERSEDED) hit
 *    still-queued sessions of sibling placements, zombifying a
 *    participant's active earlier-occurrence session.
 *
 * These tests seed the minimal schema in the SQLite :memory: lane and
 * probe both lookups directly. A Pause unit is used to keep the
 * RunUnitFactory constructor chain free of SurveyStudy/results-table
 * dependencies.
 */
class RunSessionReusedUnitTest extends \PHPUnit\Framework\TestCase
{
    /** @var DB */
    private $db;

    private const RUN_ID = 1;
    private const RUN_SESSION_ID = 555;
    private const UNIT_ID = 10;          // the shared survey_units row
    private const OTHER_UNIT_ID = 11;    // a different unit downstream
    private const RUN_UNIT_EARLY = 100;  // placement at position 50
    private const RUN_UNIT_LATE = 101;   // placement at position 200
    private const RUN_UNIT_OTHER = 102;  // OTHER_UNIT's placement at position 300

    protected function setUp(): void
    {
        $this->db = DB::getInstance();
        $this->dropTables();

        $this->db->exec('CREATE TABLE survey_units (id INTEGER PRIMARY KEY, type TEXT, created TEXT, modified TEXT)');
        $this->db->exec('CREATE TABLE survey_run_units (id INTEGER PRIMARY KEY, run_id INTEGER, unit_id INTEGER, position INTEGER, description TEXT)');
        $this->db->exec('CREATE TABLE survey_pauses (id INTEGER PRIMARY KEY, body TEXT, body_parsed TEXT, wait_until_time TEXT, wait_minutes TEXT, wait_until_date TEXT, relative_to TEXT)');
        $this->db->exec('CREATE TABLE survey_run_sessions (id INTEGER PRIMARY KEY, current_unit_session_id INTEGER)');
        $this->db->exec('CREATE TABLE survey_unit_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            unit_id INTEGER, run_unit_id INTEGER, iteration INTEGER,
            run_session_id INTEGER, created TEXT, expires TEXT,
            queued INTEGER DEFAULT 0, result TEXT, result_log TEXT,
            ended TEXT, expired TEXT, state TEXT, state_log TEXT,
            idempotency_key TEXT)');

        $this->db->exec("INSERT INTO survey_units (id, type) VALUES (" . self::UNIT_ID . ", 'Pause'), (" . self::OTHER_UNIT_ID . ", 'Pause')");
        $this->db->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position) VALUES
            (" . self::RUN_UNIT_EARLY . ", " . self::RUN_ID . ", " . self::UNIT_ID . ", 50),
            (" . self::RUN_UNIT_LATE . ", " . self::RUN_ID . ", " . self::UNIT_ID . ", 200),
            (" . self::RUN_UNIT_OTHER . ", " . self::RUN_ID . ", " . self::OTHER_UNIT_ID . ", 300)");
        $this->db->exec("INSERT INTO survey_run_sessions (id) VALUES (" . self::RUN_SESSION_ID . ")");
    }

    protected function tearDown(): void
    {
        $this->dropTables();
    }

    private function dropTables(): void
    {
        // Exhaust the DB singleton's retained lastStatement cursor —
        // an unfinalized cursor blocks SQLite DDL ("table is locked").
        $this->db->execute('SELECT 1');
        $this->db->exec('DROP TRIGGER IF EXISTS fail_current_ptr');
        foreach (['survey_units', 'survey_run_units', 'survey_pauses', 'survey_run_sessions', 'survey_unit_sessions'] as $t) {
            $this->db->exec("DROP TABLE IF EXISTS $t");
        }
    }

    /**
     * Build a RunSession pinned at $position without running the
     * constructor's load() path (no survey_run_sessions hydration).
     */
    private function makeRunSession(int $position): RunSession
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $run->id = self::RUN_ID;
        $run->name = 'reused_unit_test_run';

        $rs = (new ReflectionClass(RunSession::class))->newInstanceWithoutConstructor();
        $rs->boot(); // wires $db to the test SQLite instance
        $rs->id = self::RUN_SESSION_ID;
        $rs->position = $position;

        $runProp = new ReflectionProperty(RunSession::class, 'run');
        $runProp->setAccessible(true);
        $runProp->setValue($rs, $run);

        return $rs;
    }

    private function insertUnitSession(int $id, int $runUnitId, int $queued, int $iteration = 1): void
    {
        $this->db->exec("INSERT INTO survey_unit_sessions
            (id, unit_id, run_unit_id, iteration, run_session_id, created, queued)
            VALUES ($id, " . self::UNIT_ID . ", $runUnitId, $iteration, " . self::RUN_SESSION_ID . ", '2026-07-01 10:00:00', $queued)");
    }

    /**
     * getCurrentUnitSession() at position 50 must return the session
     * created for THAT placement — not the newer session belonging to
     * the same unit's placement at position 200. Pre-fix the unit_id
     * match + ORDER BY id DESC returned the position-200 session.
     */
    public function testCurrentUnitSessionPrefersOwnPlacement(): void
    {
        $this->insertUnitSession(1000, self::RUN_UNIT_EARLY, 0);
        $this->insertUnitSession(2000, self::RUN_UNIT_LATE, 0);

        $rs = $this->makeRunSession(50);
        $unitSession = $rs->getCurrentUnitSession();

        $this->assertNotFalse($unitSession, 'expected a current unit session at position 50');
        $this->assertEquals(1000, $unitSession->id, 'adopted the sibling placement\'s session as current');
    }

    /**
     * Legacy rows (pre-047) carry run_unit_id NULL — they must still be
     * found via the unit_id fallback so old in-flight sessions keep
     * working after the upgrade.
     */
    public function testCurrentUnitSessionLegacyNullRowStillMatches(): void
    {
        $this->db->exec("INSERT INTO survey_unit_sessions
            (id, unit_id, run_unit_id, run_session_id, created, queued)
            VALUES (1000, " . self::UNIT_ID . ", NULL, " . self::RUN_SESSION_ID . ", '2026-07-01 10:00:00', 0)");

        $rs = $this->makeRunSession(50);
        $unitSession = $rs->getCurrentUnitSession();

        $this->assertNotFalse($unitSession, 'legacy NULL-run_unit_id session should match by unit_id');
        $this->assertEquals(1000, $unitSession->id);
    }

    /**
     * UnitSession::create() at position 200 must supersede only the
     * still-queued sibling of the SAME placement — the participant's
     * active session at position 50 must keep its queued state. Pre-fix
     * the unit_id-keyed UPDATE flipped both to queued=-9.
     */
    public function testSupersedeIsScopedToPlacement(): void
    {
        $this->insertUnitSession(1000, self::RUN_UNIT_EARLY, 2);           // active, queued, position 50
        $this->insertUnitSession(2000, self::RUN_UNIT_LATE, 2);            // stale sibling of position 200

        $rs = $this->makeRunSession(200);
        $pause = (new ReflectionClass(Pause::class))->newInstanceWithoutConstructor();
        $pause->boot();
        $pause->id = self::UNIT_ID;

        $created = (new UnitSession($rs, $pause))->create(true);

        $this->assertNotEmpty($created->id, 'new unit session should be created');
        $this->assertEquals(self::RUN_UNIT_LATE, $created->run_unit_id);
        $this->assertEquals(2, $created->iteration, 'iteration should count per placement');

        $early = $this->db->findRow('survey_unit_sessions', ['id' => 1000], 'queued');
        $late = $this->db->findRow('survey_unit_sessions', ['id' => 2000], 'queued');
        $this->assertEquals(2, (int) $early['queued'], 'sibling placement\'s active session must NOT be superseded');
        $this->assertEquals(UnitSessionQueue::QUEUED_SUPERCEDED, (int) $late['queued'], 'same placement\'s stale session must be superseded');
    }

    /**
     * UnitSession::load() without an id (the recovery path after a failed
     * create() INSERT) must resolve the row belonging to the CURRENT
     * position's placement — not an arbitrary row of the same unit_id.
     * The wrong-placement row is inserted first (lowest id) because the
     * pre-fix findRow had no ORDER BY and returned scan order.
     */
    public function testLoadFallbackResolvesOwnPlacement(): void
    {
        $this->insertUnitSession(1000, self::RUN_UNIT_LATE, 0);   // pos-200 placement, scan-order first
        $this->insertUnitSession(2000, self::RUN_UNIT_EARLY, 0);  // pos-50 placement

        $rs = $this->makeRunSession(50);
        $pause = (new ReflectionClass(Pause::class))->newInstanceWithoutConstructor();
        $pause->boot();
        $pause->id = self::UNIT_ID;

        $us = (new UnitSession($rs, $pause))->load();
        $this->assertEquals(2000, $us->id, 'load() fallback must pick the current placement\'s row');
        $this->assertEquals(self::RUN_UNIT_EARLY, $us->run_unit_id);
    }

    /**
     * Sampler scenario (RunUnit::getSampleSessions/grabRandomSession):
     * the run session sits at a position hosting a DIFFERENT unit. load()
     * for the sampled unit must return that unit's own (newest) session —
     * never the current position's unit's session, and never nothing.
     * Pins the review finding against the first v0.26.4 iteration, whose
     * placement arm carried no unit_id constraint.
     */
    public function testLoadFallbackForSamplersPastTheUnit(): void
    {
        $this->insertUnitSession(1000, self::RUN_UNIT_EARLY, 0);   // sampled unit's session
        $this->db->exec("INSERT INTO survey_unit_sessions
            (id, unit_id, run_unit_id, iteration, run_session_id, created, queued)
            VALUES (2000, " . self::OTHER_UNIT_ID . ", " . self::RUN_UNIT_OTHER . ", 1, " . self::RUN_SESSION_ID . ", '2026-07-01 11:00:00', 0)");

        $rs = $this->makeRunSession(300); // current position hosts OTHER_UNIT
        $pause = (new ReflectionClass(Pause::class))->newInstanceWithoutConstructor();
        $pause->boot();
        $pause->id = self::UNIT_ID;

        $us = (new UnitSession($rs, $pause))->load();
        $this->assertEquals(1000, $us->id, 'sampler must get the sampled unit\'s session, not the current position\'s');
        $this->assertEquals(self::UNIT_ID, (int) $us->unit_id);
    }

    /**
     * A create() that fails AFTER its INSERT (here: the current-session
     * pointer update hits a missing table) must roll back and return an
     * INVALID object with a null id — not keep the rolled-back phantom
     * auto-increment id or hydrate an arbitrary prior row.
     */
    public function testCreateFailureReturnsInvalidWithoutPhantomId(): void
    {
        $rs = $this->makeRunSession(50);
        $pause = (new ReflectionClass(Pause::class))->newInstanceWithoutConstructor();
        $pause->boot();
        $pause->id = self::UNIT_ID;

        // Fault injection: the current-session pointer UPDATE (which runs
        // AFTER the INSERT assigned $this->id) aborts. SQLite-lane only,
        // like the rest of this file's schema. The SELECT 1 fully consumes
        // the DB singleton's retained lastStatement cursor, which otherwise
        // blocks SQLite DDL with "database table is locked".
        $this->db->execute('SELECT 1');
        $this->db->exec("CREATE TRIGGER fail_current_ptr BEFORE UPDATE ON survey_run_sessions
            BEGIN SELECT RAISE(ABORT, 'injected failure'); END");

        $us = (new UnitSession($rs, $pause))->create(true);
        $this->assertNull($us->id, 'rolled-back create must not keep a phantom id');
        $this->assertFalse($us->valid);
        $this->db->execute('SELECT 1'); // flush the aborted statement cursor
        $count = $this->db->count('survey_unit_sessions', ['run_session_id' => self::RUN_SESSION_ID], 'id');
        $this->assertEquals(0, (int) $count, 'the rolled-back INSERT must not persist');
    }
}
