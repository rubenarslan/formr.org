<?php

/**
 * v1.8.0 — computed jumps (upstream #699) must not swallow the 0/1 booleans
 * that existing skip conditions return.
 *
 * A Skip condition may return a position number instead of TRUE/FALSE. But R
 * has no boolean-vs-integer signal that survives the round trip — OpenCPU
 * answers in JSON, so `TRUE`, `1L` and `1` all reach PHP as the same value —
 * and the conditions people actually write return 1/0 constantly:
 * `screener$consent` on a 1/0 coded item, `sum(x)`, `nrow(df)`,
 * `as.numeric(...)`. Reading those as positions silently reverses the routing
 * of every skip written before the feature existed, and does it quietly: on a
 * run numbered 10/20/30 there is no unit at position 1, so the "target does
 * not exist" arm continues past the skip — the exact arm the skip existed to
 * avoid.
 *
 * So 0 and 1 keep their boolean reading and are not expressible as jump
 * targets (Branch::MIN_COMPUTED_JUMP_POSITION). Agreed with @timseidel before
 * release.
 */
class BranchComputedJumpTest extends \PHPUnit\Framework\TestCase
{
    private function makeBranch(): BranchComputedJumpProbe
    {
        $branch = (new ReflectionClass(BranchComputedJumpProbe::class))->newInstanceWithoutConstructor();
        $branch->boot();
        $branch->setIfTrue(70);
        return $branch;
    }

    private function target($eval)
    {
        $m = new ReflectionMethod(Branch::class, 'computedJumpTarget');
        $m->setAccessible(true);
        return $m->invoke($this->makeBranch(), $eval);
    }

    /** The regression this guards: a 1/0 coded consent item must stay boolean. */
    public function testZeroAndOneAreNeverJumpTargets(): void
    {
        $this->assertNull($this->target(1),
            '1 must read as TRUE (jump to if_true), never as position 1');
        $this->assertNull($this->target(0),
            '0 must read as FALSE (continue), never as position 0');
        $this->assertNull($this->target('1'), 'JSON may hand us the numeric string');
        $this->assertNull($this->target('0'));
    }

    /** Real booleans never reach the numeric path at all. */
    public function testBooleansAreNotNumeric(): void
    {
        $this->assertNull($this->target(true));
        $this->assertNull($this->target(false));
    }

    /** The feature itself still works for genuine positions. */
    public function testPositionsAtOrAboveTheFloorJump(): void
    {
        $this->assertSame(2, $this->target(2), 'the floor itself is a valid target');
        $this->assertSame(30, $this->target(30));
        $this->assertSame(30, $this->target('30'));
        $this->assertSame(70, $this->target(70.0), 'R numerics arrive as floats');
    }

    /** Nothing below the floor, including negatives, is a position. */
    public function testBelowTheFloorFallsBackToBoolean(): void
    {
        $this->assertNull($this->target(1.4), 'rounds to 1');
        $this->assertNull($this->target(-5), 'a negative is not a position');
        $this->assertNull($this->target('abc'));
        $this->assertNull($this->target(''));
        $this->assertNull($this->target(null));
    }

    /**
     * The Test view's "Routes to" column and the real routing must agree —
     * they now share computedJumpTarget(), so a numeric 1 previews as the
     * boolean jump, not as position 1.
     */
    public function testTestViewPreviewsTheBooleanReadingForOne(): void
    {
        $branch = $this->makeBranch();
        $m = new ReflectionMethod(Branch::class, 'describeRouting');
        $m->setAccessible(true);

        $this->assertStringContainsString('70', $m->invoke($branch, 1, $this->makeUnitSession()),
            '1 is TRUE, so the preview must name if_true (70)');
        $this->assertStringNotContainsString('position 1', $m->invoke($branch, 1, $this->makeUnitSession()));
        $this->assertSame('continue (coerced)', $m->invoke($branch, 0, $this->makeUnitSession()));
        $this->assertSame('jump to position 30', $m->invoke($branch, 30, $this->makeUnitSession()));
    }

    /**
     * v1.10.3: NA (JSON null) is not a verdict. The live path has always halted
     * on it — null is also the failed-evaluation signal — but called it an
     * "OpenCPU error" with an empty message, and the Test view previewed the
     * very same value as "continue (coerced)". The preview must show the halt.
     * With no OpenCPU call in this lane opencpu_last_error() is empty, which is
     * exactly how a genuine NA arrives (a failed evaluation leaves a message).
     */
    public function testNaPreviewsAsAHaltNotAsContinue(): void
    {
        $branch = $this->makeBranch();
        $m = new ReflectionMethod(Branch::class, 'describeRouting');
        $m->setAccessible(true);
        $routing = $m->invoke($branch, null, $this->makeUnitSession());
        $this->assertStringStartsWith('halt', $routing);
        $this->assertStringContainsString('NA', $routing);
        $this->assertStringNotContainsString('continue', $routing);
    }

    /**
     * A nested list used to reach strtotime() with an array — a TypeError in
     * PHP 8, fataling ajax_test_unit for the whole Test view over one
     * participant's result.
     */
    public function testNestedListDoesNotFatalTheTestView(): void
    {
        $branch = $this->makeBranch();
        $m = new ReflectionMethod(Branch::class, 'describeRouting');
        $m->setAccessible(true);

        $this->assertStringContainsString('nested list', $m->invoke($branch, [[30, 40]], $this->makeUnitSession()));
    }

    /**
     * The live path must survive a nested list too, not just the Test view.
     * `strtotime(array)` is a PHP 8 TypeError — an Error, not an Exception, so
     * nothing in the request or the daemon catches it: 500 for the
     * participant, crash-loop for the run daemon, and an untouched row.
     */
    public function testNestedListDoesNotFatalTheLivePath(): void
    {
        $eval = [[30, 40]];
        array_shift($eval);

        $this->assertNull($this->target([[30, 40]]),
            'a nested list is not a jump target');

        $branch = $this->makeBranch();
        $m = new ReflectionMethod(Branch::class, 'joinNotes');
        $m->setAccessible(true);
        $note = $m->invoke($branch, ['first note'], 'nested list');
        $this->assertSame('first note nested list', $note,
            'the halt path must carry the earlier notes, not drop them');
    }

    /** A RunSession whose position→unit map is primed, so nothing hits the DB. */
    private function makeUnitSession(): UnitSession
    {
        $runSession = (new ReflectionClass(RunSession::class))->newInstanceWithoutConstructor();
        $positions = new ReflectionProperty(RunSession::class, 'positionedUnitIds');
        $positions->setAccessible(true);
        $positions->setValue($runSession, [1 => null, 2 => 902, 30 => 930, 70 => 970]);

        $us = (new ReflectionClass(UnitSession::class))->newInstanceWithoutConstructor();
        $us->boot();
        $us->runSession = $runSession;
        return $us;
    }
}

class BranchComputedJumpProbe extends Branch
{
    public function setIfTrue($position) {
        $this->if_true = $position;
    }
}
