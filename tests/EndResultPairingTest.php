<?php

/**
 * v1.8.0 — `result` and `result_log` are a matched pair.
 *
 * `UnitSession::end()` used to re-derive `result` from the unit TYPE alone,
 * so a unit's own verdict was overwritten: a Survey that logged
 * `survey_completed` ended as `survey_ended`, an External that logged
 * `external_r_call_no_redirect` as `external_ended`, a Pause that logged
 * `error_pause_relative_to` as `pause_ended`. Distinct outcomes collapsed
 * into one label — which is why an admin force-push was indistinguishable
 * from a genuine survey completion.
 *
 * Meanwhile `result_log` was re-written verbatim from the value `load()`
 * hydrated out of an EARLIER pass, so the row could carry a label describing
 * one event beside text describing another — `pause_ended` next to an
 * OpenCPU traceback. That is worse than no text: it sends whoever reads it
 * after the wrong bug.
 *
 * `resolveEndResult()` fixes both: precedence is explicit reason → this
 * pass's own log → type default, and the log is cleared whenever the result
 * being written did not come from this pass.
 */
class EndResultPairingTest extends \PHPUnit\Framework\TestCase
{
    private function makeSession($loggedResult = null, $resultLog = null): UnitSession
    {
        $us = (new ReflectionClass(UnitSession::class))->newInstanceWithoutConstructor();
        $us->result = $loggedResult;
        $us->result_log = $resultLog;

        $logged = new ReflectionProperty(UnitSession::class, 'loggedResultThisPass');
        $logged->setAccessible(true);
        $logged->setValue($us, $loggedResult);

        return $us;
    }

    private function resolve(UnitSession $us, $reason, $default): void
    {
        $m = new ReflectionMethod(UnitSession::class, 'resolveEndResult');
        $m->setAccessible(true);
        $m->invoke($us, $reason, $default);
    }

    /** An explicit caller reason still wins outright (the queue, forceMoveOn). */
    public function testExplicitReasonWins(): void
    {
        $us = $this->makeSession('survey_completed', 'all pages done');
        $this->resolve($us, 'ended_by_queue_rse', 'survey_ended');

        $this->assertSame('ended_by_queue_rse', $us->result);
        $this->assertNull($us->result_log,
            'the queue ended this, so the unit\'s own message no longer describes the result');
    }

    /** The regression this guards: the unit's own verdict is no longer clobbered. */
    public function testUnitsOwnLoggedResultSurvivesTheTypeDefault(): void
    {
        $us = $this->makeSession('survey_completed', 'all pages done');
        $this->resolve($us, null, 'survey_ended');

        $this->assertSame('survey_completed', $us->result,
            'end() must not relabel a result the unit recorded this pass');
        $this->assertSame('all pages done', $us->result_log,
            'the log describes exactly this result, so it stays');
    }

    /** Units that record nothing keep the type default, exactly as before. */
    public function testTypeDefaultAppliesWhenNothingWasLoggedThisPass(): void
    {
        $us = $this->makeSession(null, null);
        $this->resolve($us, null, 'pause_ended');

        $this->assertSame('pause_ended', $us->result);
        $this->assertNull($us->result_log);
    }

    /**
     * The pairing rule. A Pause that failed on an earlier pass carries that
     * pass's text; ending it under the type default must not leave the two
     * describing different events.
     */
    public function testStaleLogFromAnEarlierPassIsClearedByTheTypeDefault(): void
    {
        $us = $this->makeSession(null, 'OpenCPU R error. Fix code.');
        $us->result = 'error_pause_relative_to'; // hydrated by load(), not logged now
        $this->resolve($us, null, 'pause_ended');

        $this->assertSame('pause_ended', $us->result);
        $this->assertNull($us->result_log,
            'a label describing one event beside text describing another is worse than no text');
    }

    /** Wait's end_reason equals what it logged, so its diagnostic survives. */
    public function testEndReasonMatchingThisPassKeepsTheLog(): void
    {
        $us = $this->makeSession('wait_ended_by_user', 'driven by: document/navigate');
        $this->resolve($us, 'wait_ended_by_user', 'wait_ended');

        $this->assertSame('wait_ended_by_user', $us->result);
        $this->assertSame('driven by: document/navigate', $us->result_log,
            'the Sec-Fetch diagnostic describes this very result and must not be dropped');
    }

    /** Types with no default (Email, PushMessage, Shuffle, Branch) are untouched. */
    public function testNullDefaultLeavesALoggedResultAlone(): void
    {
        $us = $this->makeSession('group_2', null);
        $this->resolve($us, null, null);

        $this->assertSame('group_2', $us->result,
            "Shuffle's assignment is the row's only record of it");
    }
}
