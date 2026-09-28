<?php

/**
 * Run::replaceUnits() must refuse a structure importUnits() would fail on
 * BEFORE it deletes the run's current units. The surrounding transaction
 * cannot undo a half-done import (DB transactions are flattened and the
 * first Survey unit's item import commits), so a Branch unit or an
 * object-shaped choice used to leave the run truncated while the API
 * reported only "Import incomplete: run has N units, expected M".
 */
class RunImportPreflightTest extends \PHPUnit\Framework\TestCase {

    private const RUN_ID = 1;

    /** @var DB */
    private $db;

    protected function setUp(): void {
        $this->db = DB::getInstance();
        $this->dropTables();
        $this->db->exec('CREATE TABLE survey_run_units (id INTEGER PRIMARY KEY, run_id INTEGER, unit_id INTEGER, position INTEGER)');
        $r = self::RUN_ID;
        $this->db->exec("INSERT INTO survey_run_units (id, run_id, unit_id, position) VALUES (100, $r, 20, 10), (101, $r, 21, 20)");
    }

    protected function tearDown(): void {
        $this->dropTables();
    }

    private function dropTables(): void {
        $this->db->execute('SELECT 1');
        $this->db->exec('DROP TABLE IF EXISTS survey_run_units');
    }

    private function makeRun(): Run {
        $run = new Run();
        $run->id = self::RUN_ID;
        return $run;
    }

    private function unitCount(): int {
        return (int) $this->db->count('survey_run_units', ['run_id' => self::RUN_ID]);
    }

    private function preflight(Run $run, array $structure): array {
        $m = new ReflectionMethod(Run::class, 'preflightImport');
        $m->setAccessible(true);
        return $m->invoke($run, json_encode($structure));
    }

    public function testBranchUnitIsRefusedBeforeExistingUnitsAreTouched(): void {
        $run = $this->makeRun();
        $json = json_encode(['units' => [
            ['type' => 'Page', 'position' => 10, 'body' => 'Hi'],
            ['type' => 'Branch', 'position' => 20, 'condition' => 'TRUE', 'if_true' => 10],
        ]]);

        $this->assertFalse($run->replaceUnits($json));
        $errors = $run->getImportErrors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("unit 2 (Branch at position 20)", $errors[0]);
        $this->assertStringContainsString('SkipForward', $errors[0]);
        $this->assertSame(2, $this->unitCount(), 'existing units must survive a refused import');
    }

    public function testObjectShapedChoiceIsRefused(): void {
        $errors = $this->preflight($this->makeRun(), ['units' => [
            ['type' => 'Survey', 'position' => 10, 'survey_data' => ['name' => 's', 'items' => [
                ['type' => 'mc', 'name' => 'q1', 'choices' => ['1' => ['label' => 'yes', 'label_parsed' => null]]],
            ]]],
        ]]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("item 'q1', choice '1' must be a plain label string", $errors[0]);
    }

    public function testSkipNeedsNumericJumpTarget(): void {
        $errors = $this->preflight($this->makeRun(), ['units' => [
            ['type' => 'SkipForward', 'position' => 10, 'condition' => 'TRUE', 'if_true' => 'end'],
        ]]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('if_true must be a numeric position', $errors[0]);
    }

    public function testInvalidJsonIsRefused(): void {
        $run = $this->makeRun();
        $this->assertFalse($run->replaceUnits('{not json'));
        $this->assertSame(['Invalid JSON or no units.'], $run->getImportErrors());
        $this->assertSame(2, $this->unitCount());
    }

    public function testExportShapedStructurePasses(): void {
        // What exportStructure() emits: plain-string choices, the legacy
        // Endpage alias, numeric strings for positions.
        $errors = $this->preflight($this->makeRun(), ['units' => [
            ['type' => 'Survey', 'position' => '10', 'survey_data' => ['name' => 's', 'items' => [
                ['type' => 'mc', 'name' => 'q1', 'choices' => ['1' => 'yes', '2' => 'no'], 'choice_list' => 'q1'],
                ['type' => 'note', 'name' => 'n1', 'label' => 'Hi', 'label_parsed' => null],
            ]]],
            ['type' => 'SkipBackward', 'position' => 20, 'condition' => 'FALSE', 'if_true' => 10],
            ['type' => 'Endpage', 'position' => 30, 'body' => 'Bye'],
        ]]);
        $this->assertSame([], $errors);
    }
}
