<?php

/**
 * Regression coverage for upstream PR #702 fix (3) — a Pause/Wait that
 * simply ELAPSED must not be recorded as result='expired'.
 *
 * Pause's recompute path assigns end_session and expired the same value
 * (Pause.php: `$data['end_session'] = $data['expired'] = $result`), and
 * isExpired() array_merges the whole expiration payload into
 * $execResults before branching. It then returns via the
 * "// ended NOT expired" branch — but the merged `expired` key rode
 * along, execute() returned it, and RunSession::executeUnitSession()
 * tests `expired` BEFORE `end_session`, so expire() ran instead of
 * end(). Same branch taken either way (move_on), but the row was
 * stamped `expired` with `ended` left NULL — which also fed the wrong
 * `seconds_stayed` in the user-detail export (PR #703).
 *
 * The flag is deliberately still SET in the expiration payload:
 * Wait::getUnitSessionOutput() branches on $expiration['expired'] to
 * tell "participant returned in time" from "wait elapsed". Only the
 * copy that leaks into $execResults is wrong.
 */
class PauseElapsedNotExpiredTest extends \PHPUnit\Framework\TestCase
{
    /** @return array{0: bool, 1: array} isExpired()'s return value and $execResults */
    private function runIsExpired(RunUnit $runUnit): array
    {
        $us = (new ReflectionClass(UnitSession::class))->newInstanceWithoutConstructor();
        $us->boot();
        $us->id = 4242;
        $us->created = mysql_datetime(time() - 600);
        $us->runUnit = $runUnit;

        $m = new ReflectionMethod(UnitSession::class, 'isExpired');
        $m->setAccessible(true);
        $expired = $m->invoke($us);

        $p = new ReflectionProperty(UnitSession::class, 'execResults');
        $p->setAccessible(true);

        return [$expired, $p->getValue($us)];
    }

    /**
     * The production case: the recompute path says the wait is over and
     * hands back end_session AND expired. The session must end.
     */
    public function testElapsedPauseDoesNotLeakExpiredIntoExecResults(): void
    {
        $pause = (new ReflectionClass(ElapseProbePause::class))->newInstanceWithoutConstructor();
        $pause->boot();
        $pause->canned = [
            'check_failed' => false,
            'expire_relatively' => null,
            'expired' => true,
            'end_session' => true,
            'expires' => time() - 60,
            'queued' => UnitSessionQueue::QUEUED_TO_END,
        ];

        list($expired, $execResults) = $this->runIsExpired($pause);

        $this->assertFalse($expired, 'an elapsed Pause/Wait ends, it does not expire');
        $this->assertTrue(!empty($execResults['end_session']), 'end_session must survive');
        $this->assertTrue(
            empty($execResults['expired']),
            'executeUnitSession() tests `expired` first — leaking it here calls expire() '
            . 'and stamps a normal elapse as result=expired with `ended` left NULL'
        );
    }

    /**
     * Guard against over-correcting: a Survey access window that really
     * did expire has no end_session, and must still expire.
     */
    public function testGenuineSurveyExpiryStillExpires(): void
    {
        $survey = (new ReflectionClass(ElapseProbeSurvey::class))->newInstanceWithoutConstructor();
        $survey->boot();
        $survey->canned = [
            'expired' => true,
            'expires' => time() - 60,
            'queued' => 0,
        ];

        list($expired, $execResults) = $this->runIsExpired($survey);

        $this->assertTrue($expired, 'a lapsed Survey/External access window must still expire');
        $this->assertTrue(empty($execResults['end_session']));
    }
}

class ElapseProbePause extends Pause
{
    public $canned = [];

    public function getUnitSessionExpirationData(UnitSession $unitSession) {
        return $this->canned;
    }
}

class ElapseProbeSurvey extends Survey
{
    public $canned = [];

    public function getUnitSessionExpirationData(UnitSession $unitSession) {
        return $this->canned;
    }
}
