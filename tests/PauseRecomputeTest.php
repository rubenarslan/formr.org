<?php

/**
 * Regression coverage for the v0.26.4 pause deadline fix.
 *
 * A Pause that computes its deadline via R (relative_to) stores the
 * result on the unit-session row (`expires`, queued = QUEUED_TO_END).
 * Pre-fix, the guard in getUnitSessionExpirationData only short-
 * circuited while the stored deadline was in the FUTURE; once it had
 * passed, any web request re-evaluated the R rule. A now-relative rule
 * ("Sys.time() + weeks(4)") then re-armed the pause by its full horizon
 * on every post-due page load instead of ending it (reproduced live on
 * the dev instance: deadline 18:40:40, a single GET at 19:46:20 moved
 * expires to 19:48:19). The daemon's END-q path always ended
 * QUEUED_TO_END sessions at the stored deadline without re-evaluating;
 * the fix makes the web path consistent with it.
 *
 * The QUEUED_TO_EXECUTE re-check path (+10min retry after an OpenCPU
 * failure or a relative_to === false wait) must keep re-evaluating and
 * is deliberately NOT fast-ended by the fix. It is not covered here:
 * exercising it requires the OpenCPU client and MySQL date functions
 * (NOW()/DATE_ADD in the condition probe), neither of which the SQLite
 * unit lane can host.
 */
class PauseRecomputeTest extends \PHPUnit\Framework\TestCase
{
    private function makePause(string $relativeTo): Pause
    {
        $pause = (new ReflectionClass(Pause::class))->newInstanceWithoutConstructor();
        $pause->boot();
        $r = new ReflectionProperty(Pause::class, 'relative_to');
        $r->setAccessible(true);
        $r->setValue($pause, $relativeTo);
        return $pause;
    }

    private function makeUnitSession(string $expires): UnitSession
    {
        $us = (new ReflectionClass(UnitSession::class))->newInstanceWithoutConstructor();
        $us->boot();
        $us->id = 4242;
        $us->created = date('Y-m-d H:i:s', strtotime($expires) - 120);
        $us->expires = $expires;
        $us->queued = UnitSessionQueue::QUEUED_TO_END;
        return $us;
    }

    /**
     * Stored deadline passed + QUEUED_TO_END: the pause must end at the
     * stored deadline WITHOUT re-evaluating relative_to. Pre-fix this
     * fell through into the R evaluation path, so the assertions fail.
     */
    public function testOverdueConcreteDeadlineEndsWithoutReevaluation(): void
    {
        $pause = $this->makePause('Sys.time() + 4*7*24*60*60');
        $past = date('Y-m-d H:i:s', time() - 3600);
        $us = $this->makeUnitSession($past);

        $data = $pause->getUnitSessionExpirationData($us);

        $this->assertTrue(!empty($data['end_session']), 'overdue pause must end, not re-evaluate');
        $this->assertTrue(empty($data['expired']), 'must END (daemon END-q parity, result pause_ended), not expire');
        $this->assertEquals(strtotime($past), $data['expires'], 'the STORED deadline is authoritative');
        $this->assertFalse($data['check_failed']);
    }

    /**
     * Stored deadline still in the future: the pre-existing short-circuit
     * is unchanged — no end, no re-evaluation.
     */
    public function testFutureDeadlineShortCircuits(): void
    {
        $pause = $this->makePause('Sys.time() + 4*7*24*60*60');
        $future = date('Y-m-d H:i:s', time() + 3600);
        $us = $this->makeUnitSession($future);

        $data = $pause->getUnitSessionExpirationData($us);

        $this->assertArrayNotHasKey('end_session', $data);
        $this->assertEquals(strtotime($future), $data['expires']);
    }
}
