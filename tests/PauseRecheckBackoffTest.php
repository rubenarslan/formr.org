<?php

/**
 * Regression coverage for the v1.7.1 change to audit F19's escalating
 * recheck backoff.
 *
 * F19 keyed the tier on how long the unit session had been ALIVE, which
 * conflates "parked for a long time" with "failing for a long time". A
 * longitudinal Pause legitimately waiting days, hitting its first
 * transient OpenCPU failure, was demoted straight to the 6-hour tier — so
 * a timed prompt could land six hours late because of one bad minute —
 * and nothing ever reset it. The tier is now keyed on the length of the
 * current failure streak, which the first success clears.
 */
class PauseRecheckBackoffTest extends \PHPUnit\Framework\TestCase
{
    private function makeSession(string $createdAgo, $stateLog = null): UnitSession
    {
        $us = (new ReflectionClass(UnitSession::class))->newInstanceWithoutConstructor();
        $us->boot();
        // id stays null: recheckFailingSince() then skips its DB write, so
        // this stays a pure unit test of the tier arithmetic.
        $us->created = mysql_datetime(strtotime($createdAgo));
        $us->state_log = $stateLog;
        $us->runUnit = (new ReflectionClass(Pause::class))->newInstanceWithoutConstructor();
        return $us;
    }

    private function backoff(UnitSession $us): string
    {
        $m = new ReflectionMethod(UnitSession::class, 'recheckBackoffExpression');
        $m->setAccessible(true);
        return $m->invoke($us);
    }

    private function marker(string $sinceAgo): string
    {
        return UnitSession::buildStateLog(UnitSession::RECHECK_FAILING_REASON, [
            'since' => date(DATE_ATOM, strtotime($sinceAgo)),
        ]);
    }

    /** The regression: an old session failing for the first time. */
    public function testLongParkedSessionFailingForTheFirstTimeGetsTheFastTier(): void
    {
        $us = $this->makeSession('-10 days');

        $this->assertSame('+10 minutes', $this->backoff($us),
            'first failure must recover fast however long the session has been parked');
        $this->assertNotEmpty($us->state_log, 'the streak start is stamped on the first failure');
    }

    /** A genuinely broken run still escalates. */
    public function testSustainedFailureStreakStillEscalates(): void
    {
        $mid = $this->makeSession('-10 days', $this->marker('-2 hours'));
        $this->assertSame('+1 hour', $this->backoff($mid));

        $max = $this->makeSession('-10 days', $this->marker('-3 days'));
        $this->assertSame('+6 hours', $this->backoff($max));
    }

    /** A success drops the marker, so the next failure starts over. */
    public function testSuccessClearsTheStreak(): void
    {
        $us = $this->makeSession('-10 days', $this->marker('-3 days'));
        $this->assertSame('+6 hours', $this->backoff($us), 'precondition: escalated');

        $m = new ReflectionMethod(UnitSession::class, 'clearRecheckFailureMarker');
        $m->setAccessible(true);
        $m->invoke($us);

        $this->assertNull($us->state_log);
        $this->assertSame('+10 minutes', $this->backoff($us));
    }

    /** An unrelated state_log must not be read as a streak, nor be cleared. */
    public function testForeignStateLogIsIgnoredAndPreserved(): void
    {
        $foreign = UnitSession::buildStateLog('pause_ended', ['unit_type' => 'Pause']);
        $us = $this->makeSession('-10 days', $foreign);

        $this->assertSame('+10 minutes', $this->backoff($us));

        $us->state_log = $foreign;
        $m = new ReflectionMethod(UnitSession::class, 'clearRecheckFailureMarker');
        $m->setAccessible(true);
        $m->invoke($us);
        $this->assertSame($foreign, $us->state_log, 'only our own marker may be cleared');
    }
}
