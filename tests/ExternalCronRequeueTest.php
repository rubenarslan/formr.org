<?php

/**
 * v1.8.0 — an External that the daemon re-executes must leave the queue in a
 * terminating state.
 *
 * The OpenCPU-error branch of External::getUnitSessionOutput() re-arms the
 * row at QUEUED_TO_EXECUTE so a transient R failure is retried (before this,
 * `wait_opencpu` left it inert at queued=0 and the participant parked
 * forever). But the SUCCESS path only ever set `redirect`, and nothing
 * downstream cleared that arming: executeUnitSession() returns at its
 * formr_in_console() guard — a cron cascade must not follow a redirect
 * (Audit F5) — before reaching any queue write. The row therefore kept
 * queued=1 with a past-due `expires`, so every daemon pass (~15 s) re-ran
 * the author's R address and re-fired whatever it does: an SMS, an API POST.
 *
 * The fix hands the queue this unit's ordinary expiry verdict on the cron
 * path: expire_after re-arms QUEUED_TO_END, no expire_after dequeues
 * (UnitSessionQueue::addItem() removes the item on an empty `expires`).
 */
class ExternalCronRequeueTest extends \PHPUnit\Framework\TestCase
{
    private function makeExternal(array $cannedExpiration, string $address = 'https://example.org/next'): ExternalCronRequeueProbe
    {
        $unit = (new ReflectionClass(ExternalCronRequeueProbe::class))->newInstanceWithoutConstructor();
        $unit->boot();
        $unit->canned = $cannedExpiration;
        $unit->setAddress($address);
        return $unit;
    }

    /** @param bool $cron true = daemon tick, false = a web request */
    private function makeUnitSession(bool $cron): UnitSession
    {
        $user = (new ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $user->cron = $cron;

        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $run->name = 'testrun';

        $runSession = (new ReflectionClass(RunSession::class))->newInstanceWithoutConstructor();
        $runSession->user = $user;
        $runSession->session = 'ABCDEF';
        $runProp = new ReflectionProperty(RunSession::class, 'run');
        $runProp->setAccessible(true);
        $runProp->setValue($runSession, $run);

        $us = (new ReflectionClass(UnitSession::class))->newInstanceWithoutConstructor();
        $us->boot();
        $us->runSession = $runSession;
        return $us;
    }

    /**
     * The loop itself: a daemon pass that produces a redirect and no
     * expire_after must dequeue, or the row is picked up again forever.
     */
    public function testCronRedirectWithoutExpiryDequeuesTheRow(): void
    {
        $unit = $this->makeExternal([]);
        $output = $unit->getUnitSessionOutput($this->makeUnitSession(true));

        $this->assertArrayHasKey('queue', $output,
            'without a queue verdict the QUEUED_TO_EXECUTE arming survives and the daemon re-executes every pass');
        $this->assertEmpty($output['queue']['expires'],
            'addItem() removes the queue item on an empty expires — that is the dequeue');
        $this->assertSame(UnitSessionQueue::QUEUED_NOT, $output['queue']['queued']);
        $this->assertNotSame(UnitSessionQueue::QUEUED_TO_EXECUTE, $output['queue']['queued'],
            're-arming TO_EXECUTE on a successful pass is the re-execution loop');
    }

    /**
     * With expire_after set, the row moves to the end-deadline arm instead:
     * abandonment stays bounded, but the daemon stops re-executing.
     */
    public function testCronRedirectWithExpiryReArmsToEndInstead(): void
    {
        $deadline = time() + 600;
        $unit = $this->makeExternal(['expires' => $deadline, 'queued' => UnitSessionQueue::QUEUED_TO_END]);
        $output = $unit->getUnitSessionOutput($this->makeUnitSession(true));

        $this->assertSame($deadline, $output['queue']['expires']);
        $this->assertSame(UnitSessionQueue::QUEUED_TO_END, $output['queue']['queued'],
            'TO_END routes the next pickup through revalidateQueueVerdict(), which terminates');
    }

    /** An already-overdue access window still expires, ahead of any of this. */
    public function testCronExpiredWindowStillExpires(): void
    {
        $unit = $this->makeExternal(['expired' => true]);
        $output = $unit->getUnitSessionOutput($this->makeUnitSession(true));

        $this->assertTrue($output['expired'] ?? false);
        $this->assertArrayNotHasKey('redirect', $output);
    }

    /** The participant path is untouched: redirect, end, move on, no queueing. */
    public function testParticipantRequestIsUnchanged(): void
    {
        $unit = $this->makeExternal([]);
        $output = $unit->getUnitSessionOutput($this->makeUnitSession(false));

        $this->assertSame('https://example.org/next', $output['redirect']);
        $this->assertTrue($output['end_session'] ?? false);
        $this->assertTrue($output['move_on'] ?? false);
        $this->assertArrayNotHasKey('queue', $output,
            'a web request advances the run itself; the queue must not be re-armed behind it');
    }
}

class ExternalCronRequeueProbe extends External
{
    public $canned = [];

    public function setAddress($address) {
        $this->address = $address;
    }

    public function getUnitSessionExpirationData(UnitSession $unitSession) {
        return $this->canned;
    }
}
