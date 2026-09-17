<?php

/**
 * The tests that would have caught the v1.10.3 regression (fixed v1.10.4).
 *
 * v1.10.3 added RunUnit::UNASSIGNABLE_PROPS to stop a request props array
 * from overwriting the authorised Run object. The first cut stripped inside
 * an assignProperties() OVERRIDE — but find() marks a placed unit valid by
 * setting `valid` THROUGH assignProperties(), and each subclass constructor
 * sets `body_parsed` the same way. So every find()-hydrated unit came back
 * valid=false and Pause/Email/… lost their parsed body. runTo(),
 * deleteRunUnitAction(), getReminderSession() and run import all gate on
 * ->valid, so admin "send to position", the non-AJAX delete, reminders and
 * import broke on perfectly-placed units. The strip now excludes `valid`
 * and `body_parsed` — the two keys trusted internal hydration writes.
 *
 * Root-cause guards (SQLite lane). The end-to-end symptom (admin push after
 * a unit is deleted) needs GET_LOCK and lives in
 * bin/test_run_unit_delete_push_smoke.php (live MariaDB).
 */
class RunUnitValidHydrationTest extends \PHPUnit\Framework\TestCase
{
    private $db;
    private const RUN_ID = 1;
    private const UNIT_ID = 10;          // a Pause
    private const RUN_UNIT_ID = 100;
    private const SKIP_UNIT_ID = 11;     // a SkipForward (non-body subclass)
    private const SKIP_RUN_UNIT_ID = 101;

    protected function setUp(): void
    {
        $this->db = DB::getInstance();
        foreach (['survey_units', 'survey_run_units', 'survey_pauses', 'survey_branches', 'survey_run_special_units'] as $t) {
            $this->db->exec("DROP TABLE IF EXISTS $t");
        }
        $this->db->exec('CREATE TABLE survey_units (id INTEGER PRIMARY KEY, type TEXT, created TEXT, modified TEXT)');
        $this->db->exec('CREATE TABLE survey_run_units (id INTEGER PRIMARY KEY, run_id INTEGER, unit_id INTEGER, position INTEGER, description TEXT)');
        $this->db->exec('CREATE TABLE survey_pauses (id INTEGER PRIMARY KEY, body TEXT, body_parsed TEXT, wait_until_time TEXT, wait_minutes TEXT, wait_until_date TEXT, relative_to TEXT)');
        $this->db->exec('CREATE TABLE survey_branches (id INTEGER PRIMARY KEY, `condition` TEXT, if_true INTEGER, automatically_jump INTEGER, automatically_go_on INTEGER)');
        // find()'s miss path falls back to the special-unit tables.
        $this->db->exec('CREATE TABLE survey_run_special_units (id INTEGER PRIMARY KEY, run_id INTEGER, type TEXT, description TEXT)');

        $this->db->exec("INSERT INTO survey_units (id, type) VALUES (" . self::UNIT_ID . ", 'Pause'), (" . self::SKIP_UNIT_ID . ", 'SkipForward')");
        // A stored parsed body: the Pause constructor loads it as-is (it does
        // NOT re-parse, unlike Page), so stripping body_parsed shows here.
        $this->db->exec("INSERT INTO survey_pauses (id, wait_minutes, body, body_parsed) VALUES (" . self::UNIT_ID . ", 60, 'hi', '<p>hi</p>')");
        $this->db->exec("INSERT INTO survey_branches (id, `condition`, if_true) VALUES (" . self::SKIP_UNIT_ID . ", '1', 30)");
        $this->db->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position, description) VALUES
            (" . self::RUN_UNIT_ID . ", " . self::RUN_ID . ", " . self::UNIT_ID . ", 20, 'p'),
            (" . self::SKIP_RUN_UNIT_ID . ", " . self::RUN_ID . ", " . self::SKIP_UNIT_ID . ", 25, 's')");
    }

    protected function tearDown(): void
    {
        foreach (['survey_units', 'survey_run_units', 'survey_pauses', 'survey_branches', 'survey_run_special_units'] as $t) {
            $this->db->exec("DROP TABLE IF EXISTS $t");
        }
    }

    private function makeRun(int $id = self::RUN_ID): Run
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $run->id = $id;
        $run->name = 'valid_hydration_run';
        return $run;
    }

    private function bodyParsed($unit): string
    {
        $p = new ReflectionProperty(RunUnit::class, 'body_parsed');
        $p->setAccessible(true);
        return (string) $p->getValue($unit);
    }

    public function testPlacedPauseHydratesAsValid(): void
    {
        $unit = RunUnitFactory::make($this->makeRun(), ['id' => self::UNIT_ID]);
        $this->assertSame(self::UNIT_ID, (int) $unit->id);
        $this->assertTrue($unit->valid, 'a placed unit must hydrate valid=true (runTo/delete/reminders gate on it)');
        $this->assertSame(self::RUN_UNIT_ID, (int) $unit->run_unit_id);
    }

    public function testPlacedSkipForwardHydratesAsValid(): void
    {
        // A second, non-body subclass: the breakage was cross-type.
        $unit = RunUnitFactory::make($this->makeRun(), ['id' => self::SKIP_UNIT_ID]);
        $this->assertSame(self::SKIP_UNIT_ID, (int) $unit->id);
        $this->assertTrue($unit->valid, 'a placed SkipForward must hydrate valid=true');
    }

    public function testStoredBodyParsedSurvivesHydration(): void
    {
        $unit = RunUnitFactory::make($this->makeRun(), ['id' => self::UNIT_ID]);
        $this->assertSame('<p>hi</p>', $this->bodyParsed($unit), 'a unit that stores body_parsed must load it, not blank it');
    }

    public function testUnplacedIdIsInvalid(): void
    {
        // A run it is not placed in: find() fails, forgetIdentity() runs.
        $unit = RunUnitFactory::make($this->makeRun(999), ['id' => self::UNIT_ID, 'type' => 'Pause']);
        $this->assertFalse($unit->valid);
        $this->assertNull($unit->id);
    }

    public function testCallerPropsCannotOverwriteRunOrInternals(): void
    {
        $run = $this->makeRun();
        $unit = RunUnitFactory::make($run, [
            'id' => self::UNIT_ID,
            'run' => '1', 'valid' => true, 'surveyStudy' => 'x', 'icon' => 'evil',
        ]);
        // Placed → valid true, but from find(), not from the smuggled key.
        $this->assertTrue($unit->valid);
        $this->assertInstanceOf(Run::class, $unit->run);
        $this->assertSame(self::RUN_ID, (int) $unit->run->id);
        $this->assertNull($unit->surveyStudy);
        $this->assertNotSame('evil', $unit->icon);
    }

    public function testAssignPropertiesKeepsHydrationKeysButStripsRun(): void
    {
        // The canary: `valid` and `body_parsed` are written by trusted
        // internal hydration THROUGH assignProperties(), so they must never
        // rejoin UNASSIGNABLE_PROPS — while `run`/`surveyStudy` from a props
        // array must stay stripped. Re-adding either to the strip list (the
        // v1.10.3 mistake) fails here.
        $unit = RunUnitFactory::make($this->makeRun(), ['type' => 'Pause']); // no id → no find(), valid stays false
        $authorisedRun = $unit->run;
        $m = new ReflectionMethod(RunUnit::class, 'assignProperties');
        $m->setAccessible(true);
        $m->invoke($unit, ['valid' => true, 'body_parsed' => 'KEEP', 'run' => '1', 'surveyStudy' => 'x']);

        $this->assertTrue($unit->valid, 'valid must survive assignProperties (find() sets it this way)');
        $this->assertSame('KEEP', $this->bodyParsed($unit), 'body_parsed must survive assignProperties (subclass ctors set it this way)');
        $this->assertSame($authorisedRun, $unit->run, 'run must be stripped from a props array');
        $this->assertNull($unit->surveyStudy, 'surveyStudy must be stripped from a props array');
    }
}
