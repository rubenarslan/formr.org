<?php

/**
 * v1.8.0 — a Wait's two terminal outcomes must stay distinguishable in the
 * results table.
 *
 * `UnitSession::end()` derives the result from the unit TYPE alone, so it
 * writes 'wait_ended' for every Wait it ends — overwriting whatever the
 * unit's own log just recorded. Both exits of Wait::getUnitSessionOutput()
 * therefore read 'wait_ended': the participant-returned-early jump to
 * `body`, and a wait that genuinely elapsed.
 *
 * That collapse arrived in v1.7.1. Dropping the leaked `expired` flag
 * correctly routed an elapsed Wait through end() rather than expire(), so
 * the elapsed case stopped recording 'expired' and started recording
 * 'wait_ended' — the label the early-return branch had always produced.
 * Field reports of "the participant came back early condition fires by
 * itself" could then no longer be told apart from healthy timeouts without
 * reading state_log or noticing that the run jumped to `body` instead of
 * advancing one position.
 */
class WaitEndReasonTest extends \PHPUnit\Framework\TestCase
{
    private function makeWait(array $canned): WaitEndReasonProbe
    {
        $wait = (new ReflectionClass(WaitEndReasonProbe::class))->newInstanceWithoutConstructor();
        $wait->boot();
        $wait->canned = $canned;
        return $wait;
    }

    /** @param bool $cron true = daemon tick, false = a web request */
    private function makeUnitSession(bool $cron): UnitSession
    {
        $user = (new ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $user->cron = $cron;

        $runSession = (new ReflectionClass(RunSession::class))->newInstanceWithoutConstructor();
        $runSession->user = $user;

        $us = (new ReflectionClass(UnitSession::class))->newInstanceWithoutConstructor();
        $us->boot();
        $us->runSession = $runSession;
        return $us;
    }

    /** The participant came back before the wait elapsed. */
    public function testEarlyReturnCarriesItsOwnEndReason(): void
    {
        $wait = $this->makeWait(['expired' => false, 'check_failed' => false]);
        $output = $wait->getUnitSessionOutput($this->makeUnitSession(false));

        $this->assertTrue($output['end_session'] ?? false);
        $this->assertArrayHasKey('run_to', $output,
            'the early-return branch jumps to `body`, which is what distinguishes it in the logs');
        $this->assertSame('wait_ended_by_user', $output['end_reason'] ?? null,
            "without end_reason, end() relabels this 'wait_ended' and it becomes indistinguishable from a normal elapse");
    }

    /** The wait genuinely ran out — unchanged behaviour. */
    public function testElapsedWaitAdvancesAndKeepsTheTypeDerivedLabel(): void
    {
        $wait = $this->makeWait(['expired' => true, 'check_failed' => false]);
        $output = $wait->getUnitSessionOutput($this->makeUnitSession(true));

        $this->assertTrue($output['end_session'] ?? false);
        $this->assertTrue($output['move_on'] ?? false,
            'an elapsed wait advances to the next unit, it does not jump to body');
        $this->assertArrayNotHasKey('end_reason', $output,
            "the elapsed case keeps end()'s type-derived 'wait_ended'");
    }

    /** A daemon tick on an un-elapsed wait must leave it parked. */
    public function testCronTickOnUnelapsedWaitParks(): void
    {
        $wait = $this->makeWait(['expired' => false, 'check_failed' => false]);
        $output = $wait->getUnitSessionOutput($this->makeUnitSession(true));

        $this->assertTrue($output['wait_user'] ?? false,
            'the daemon must never take the participant-returned branch');
        $this->assertArrayNotHasKey('end_session', $output);
    }
}

class WaitEndReasonProbe extends Wait
{
    public $canned = [];

    public function getUnitSessionExpirationData(UnitSession $unitSession) {
        return $this->canned;
    }
}
