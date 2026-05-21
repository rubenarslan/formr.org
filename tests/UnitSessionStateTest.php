<?php

/**
 * Coverage for Track A's state-column dual-write helpers and the new
 * UnitSession property surface area.
 *
 * Track A keeps the legacy `queued` column as the queue's pickup signal
 * and adds a parallel `state` ENUM column so admin tooling and analysis
 * exports can read a self-documenting value instead of decoding the
 * four magic queued values (-9 / 0 / 1 / 2). This file pins:
 *
 *   - the property surface UnitSession exposes for the new columns
 *     (so `load()` writing into them and templates reading from them
 *     stay in lockstep)
 *   - the state classifier in UnitSessionQueue::stateForQueuedUnit which
 *     is what `addItem` uses to derive WAITING_USER vs WAITING_TIMER
 *     from the runUnit type
 *
 * The full state-machine end-to-end (PENDING -> WAITING_* -> ENDED /
 * EXPIRED / SUPERSEDED) is exercised by a separate live-DB integration
 * harness — see bin/test_track_a_smoke.php — because that path needs the
 * MariaDB-specific JSON / ENUM column types that the SQLite test
 * bootstrap doesn't carry.
 */
class UnitSessionStateTest extends \PHPUnit\Framework\TestCase
{
    public function testUnitSessionExposesTrackAProperties(): void
    {
        $rc = new ReflectionClass(UnitSession::class);

        // The columns 047_uxec_track_a.sql adds; load() and create()
        // need to round-trip through these properties for the dual-
        // write to land in the row. If any of these get renamed or
        // removed in code without updating the migration / admin
        // templates, this test catches the drift.
        foreach (['run_unit_id', 'iteration', 'state', 'state_log', 'idempotency_key'] as $name) {
            $this->assertTrue(
                $rc->hasProperty($name),
                "UnitSession must expose property `{$name}` for Track A round-tripping"
            );
        }
    }

    public function testUnitSessionQueueExposesNamedStateConstants(): void
    {
        $rc = new ReflectionClass(UnitSessionQueue::class);
        foreach ([
            'STATE_PENDING'       => 'PENDING',
            'STATE_RUNNING'       => 'RUNNING',
            'STATE_WAITING_USER'  => 'WAITING_USER',
            'STATE_WAITING_TIMER' => 'WAITING_TIMER',
            'STATE_ENDED'         => 'ENDED',
            'STATE_EXPIRED'       => 'EXPIRED',
            'STATE_SUPERSEDED'    => 'SUPERSEDED',
        ] as $constName => $expected) {
            $this->assertTrue(
                $rc->hasConstant($constName),
                "UnitSessionQueue::{$constName} must exist (Track A)"
            );
            $this->assertSame(
                $expected,
                $rc->getConstant($constName),
                "UnitSessionQueue::{$constName} must equal '{$expected}' — value is what gets written into the ENUM column and read by admin templates"
            );
        }
    }

    /**
     * @dataProvider unitTypeStateMapping
     */
    public function testStateForQueuedUnitClassifiesByType(string $type, string $expectedState): void
    {
        $runUnit = (new ReflectionClass(RunUnit::class))->newInstanceWithoutConstructor();
        $runUnit->type = $type;

        $this->assertSame(
            $expectedState,
            UnitSessionQueue::stateForQueuedUnit($runUnit),
            "Unit type '{$type}' should map to state '{$expectedState}'"
        );
    }

    public function testQueueLabelPrefersStateColumnWhenPresent(): void
    {
        // Track A row: state populated, label/color come from state.
        $row = ['state' => UnitSessionQueue::STATE_WAITING_USER, 'queued' => 2];
        $label = UnitSessionQueue::queueLabelForRow($row);
        $this->assertSame('WAITING_USER', $label['label']);
        $this->assertSame('primary', $label['color']);

        $row = ['state' => UnitSessionQueue::STATE_ENDED, 'queued' => 0];
        $label = UnitSessionQueue::queueLabelForRow($row);
        $this->assertSame('ENDED', $label['label']);
        $this->assertSame('success', $label['color']);

        $row = ['state' => UnitSessionQueue::STATE_SUPERSEDED, 'queued' => -9];
        $label = UnitSessionQueue::queueLabelForRow($row);
        $this->assertSame('SUPERSEDED', $label['label']);
    }

    public function testQueueLabelFallsBackToQueuedMappingForLegacyRows(): void
    {
        // Legacy row: state column is NULL (pre-047), so the helper
        // decodes the magic queued value. Verifies admin templates still
        // render a sensible label for unbackfilled historical data.
        $cases = [
            [UnitSessionQueue::QUEUED_TO_EXECUTE, 'TO_EXECUTE', 'success'],
            [UnitSessionQueue::QUEUED_TO_END,     'TO_END',     'warning'],
            [UnitSessionQueue::QUEUED_NOT,        'NOT_QUEUED', 'default'],
            [UnitSessionQueue::QUEUED_SUPERCEDED, 'SUPERSEDED', 'default'],
        ];
        foreach ($cases as [$queued, $expectedLabel, $expectedColor]) {
            $row = ['state' => null, 'queued' => $queued];
            $label = UnitSessionQueue::queueLabelForRow($row);
            $this->assertSame($expectedLabel, $label['label'], "queued={$queued} should label as {$expectedLabel}");
            $this->assertSame($expectedColor, $label['color']);
        }
    }

    public static function unitTypeStateMapping(): array
    {
        return [
            // Interactive / participant-facing units. The participant is
            // the next actor; the queue's expires deadline is just a
            // fallback timer.
            'Survey'      => ['Survey',      UnitSessionQueue::STATE_WAITING_USER],
            'External'    => ['External',    UnitSessionQueue::STATE_WAITING_USER],
            'Page'        => ['Page',        UnitSessionQueue::STATE_WAITING_USER],
            'Endpage'     => ['Endpage',     UnitSessionQueue::STATE_WAITING_USER],
            // Timer- or cron-driven units. No participant interaction
            // expected before the next state transition.
            'Pause'       => ['Pause',       UnitSessionQueue::STATE_WAITING_TIMER],
            'Wait'        => ['Wait',        UnitSessionQueue::STATE_WAITING_TIMER],
            'Email'       => ['Email',       UnitSessionQueue::STATE_WAITING_TIMER],
            'PushMessage' => ['PushMessage', UnitSessionQueue::STATE_WAITING_TIMER],
            'Branch'      => ['Branch',      UnitSessionQueue::STATE_WAITING_TIMER],
            'SkipForward' => ['SkipForward', UnitSessionQueue::STATE_WAITING_TIMER],
            'SkipBackward'=> ['SkipBackward',UnitSessionQueue::STATE_WAITING_TIMER],
            'Shuffle'     => ['Shuffle',     UnitSessionQueue::STATE_WAITING_TIMER],
        ];
    }
}
