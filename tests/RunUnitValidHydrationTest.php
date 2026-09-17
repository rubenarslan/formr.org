<?php

/**
 * A unit that IS placed in the run must hydrate as valid=true.
 *
 * v1.10.3 added RunUnit::UNASSIGNABLE_PROPS to stop a request props array
 * from overwriting the authorised Run object (and valid/surveyStudy). The
 * first cut did the stripping inside an assignProperties() override — but
 * RunUnit::find() sets `valid` THROUGH assignProperties(), so every
 * find()-hydrated unit came back valid=false. runTo() and
 * deleteRunUnitAction() both gate on ->valid, so admin "send to position"
 * failed with "Could not create unit session for unit N at pos. M" and the
 * run-editor delete reported "unexpected error" — on perfectly-placed
 * units (v1.10.4). The strip now applies only to caller-supplied props.
 */
class RunUnitValidHydrationTest extends \PHPUnit\Framework\TestCase
{
    private $db;
    private const RUN_ID = 1;
    private const UNIT_ID = 10;
    private const RUN_UNIT_ID = 100;

    protected function setUp(): void
    {
        $this->db = DB::getInstance();
        $this->db->exec('DROP TABLE IF EXISTS survey_units');
        $this->db->exec('DROP TABLE IF EXISTS survey_run_units');
        $this->db->exec('CREATE TABLE survey_units (id INTEGER PRIMARY KEY, type TEXT, created TEXT, modified TEXT)');
        $this->db->exec('CREATE TABLE survey_run_units (id INTEGER PRIMARY KEY, run_id INTEGER, unit_id INTEGER, position INTEGER, description TEXT)');
        $this->db->exec('CREATE TABLE survey_pauses (id INTEGER PRIMARY KEY, body TEXT, body_parsed TEXT, wait_until_time TEXT, wait_minutes TEXT, wait_until_date TEXT, relative_to TEXT)');
        // find()'s miss path falls back to the special-unit tables.
        $this->db->exec('CREATE TABLE survey_run_special_units (id INTEGER PRIMARY KEY, run_id INTEGER, type TEXT, description TEXT)');
        $this->db->exec("INSERT INTO survey_units (id, type) VALUES (" . self::UNIT_ID . ", 'Pause')");
        $this->db->exec("INSERT INTO survey_pauses (id, wait_minutes) VALUES (" . self::UNIT_ID . ", 60)");
        $this->db->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position, description)
            VALUES (" . self::RUN_UNIT_ID . ", " . self::RUN_ID . ", " . self::UNIT_ID . ", 20, 'p')");
    }

    protected function tearDown(): void
    {
        $this->db->exec('DROP TABLE IF EXISTS survey_units');
        $this->db->exec('DROP TABLE IF EXISTS survey_run_units');
        $this->db->exec('DROP TABLE IF EXISTS survey_pauses');
        $this->db->exec('DROP TABLE IF EXISTS survey_run_special_units');
    }

    private function makeRun(): Run
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $run->id = self::RUN_ID;
        $run->name = 'valid_hydration_run';
        return $run;
    }

    public function testPlacedUnitHydratesAsValid(): void
    {
        $unit = RunUnitFactory::make($this->makeRun(), ['id' => self::UNIT_ID]);
        $this->assertSame(self::UNIT_ID, (int) $unit->id);
        $this->assertTrue($unit->valid, 'a placed unit must hydrate valid=true (runTo/delete gate on it)');
        $this->assertSame(self::RUN_UNIT_ID, (int) $unit->run_unit_id);
    }

    public function testUnplacedIdIsInvalid(): void
    {
        // A run it is not placed in: find() fails, forgetIdentity() runs.
        $other = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $other->id = 999; $other->name = 'other';
        $unit2 = RunUnitFactory::make($other, ['id' => self::UNIT_ID, 'type' => 'Pause']);
        $this->assertFalse($unit2->valid);
        $this->assertNull($unit2->id);
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
}
