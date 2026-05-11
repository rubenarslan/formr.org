<?php


/**
 * Idempotency-guard coverage for Email::getUnitSessionOutput and
 * PushMessage::getUnitSessionOutput.
 *
 * Without these guards, re-executing an already-sent Email/Push
 * unit-session row delivers a duplicate message — observed in prod on
 * AMOR 2026-05-09 (18 affected participants got 2× email + 2× push).
 * The position-recheck guard in RunSession::execute is the primary fix
 * for the cascade-level race; these row-level guards are belt-and-
 * braces for any other path that re-executes a terminated unit-session.
 *
 * Tests bypass the constructors via newInstanceWithoutConstructor so we
 * can probe the guard without standing up Run/RunSession/DB. The guard
 * itself only reads $unitSession->result.
 */
class EmailPushIdempotencyTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @dataProvider terminalEmailResults
     */
    public function testEmailEarlyReturnsForTerminalResult(string $result): void
    {
        $email = (new ReflectionClass('Email'))->newInstanceWithoutConstructor();
        $unitSession = (new ReflectionClass('UnitSession'))->newInstanceWithoutConstructor();
        $unitSession->result = $result;

        $output = $email->getUnitSessionOutput($unitSession);

        $this->assertSame(
            ['end_session' => true, 'move_on' => true],
            $output,
            "Email::getUnitSessionOutput should early-return for terminal result '{$result}'"
        );
    }

    public static function terminalEmailResults(): array
    {
        return [
            'email_sent' => ['email_sent'],
            'email_queued' => ['email_queued'],
            'email_skipped_user_active' => ['email_skipped_user_active'],
            'email_skipped_user_disabled' => ['email_skipped_user_disabled'],
        ];
    }

    /**
     * @dataProvider terminalPushResults
     */
    public function testPushMessageEarlyReturnsForTerminalResult(string $result): void
    {
        $push = (new ReflectionClass('PushMessage'))->newInstanceWithoutConstructor();
        $unitSession = (new ReflectionClass('UnitSession'))->newInstanceWithoutConstructor();
        $unitSession->result = $result;

        $output = $push->getUnitSessionOutput($unitSession);

        // Track A A8: the terminal-result guard now includes
        // end_session so re-encountering an already-sent Push row also
        // transitions state to ENDED. Pre-A8 the guard returned just
        // ['move_on' => true] and left the row stuck in PENDING.
        $this->assertSame(
            ['end_session' => true, 'move_on' => true],
            $output,
            "PushMessage::getUnitSessionOutput should early-return end_session+move_on for terminal result '{$result}'"
        );
    }

    public static function terminalPushResults(): array
    {
        return [
            'sent' => ['sent'],
            'no_subscription' => ['no_subscription'],
            'error' => ['error'],
            'message_parse_failed' => ['message_parse_failed'],
            'title_parse_failed' => ['title_parse_failed'],
        ];
    }

    /**
     * Sanity: a unit-session whose result is NULL (fresh, never sent)
     * must NOT trigger the early-return — otherwise the guard would
     * suppress legitimate first-send attempts. Verified by probing the
     * guard's branch only; we don't drive the full sendMail/sendPush
     * path here.
     */
    public function testEmailDoesNotEarlyReturnWhenResultIsNull(): void
    {
        $email = (new ReflectionClass('Email'))->newInstanceWithoutConstructor();
        $unitSession = (new ReflectionClass('UnitSession'))->newInstanceWithoutConstructor();
        $unitSession->result = null;

        // We can't safely call getUnitSessionOutput here without a Run/
        // EmailAccount fixture, but the early-return shape is
        // ['end_session' => true, 'move_on' => true] WITHOUT a 'log' key.
        // We assert the inverse: invoking with a non-terminal result
        // would attempt the rest of the method and fail fast on the
        // missing run/account dependencies. That distinguishes the
        // guard-fired path from the legitimate-first-send path.
        $this->expectException(\Throwable::class);
        $email->getUnitSessionOutput($unitSession);
    }

    public function testPushDoesNotEarlyReturnWhenResultIsNull(): void
    {
        $push = (new ReflectionClass('PushMessage'))->newInstanceWithoutConstructor();
        $unitSession = (new ReflectionClass('UnitSession'))->newInstanceWithoutConstructor();
        $unitSession->result = null;

        // Push's try/catch (line 174-178) catches Exception only; the
        // missing-runSession dependency surfaces as a PHP Error
        // ("member function on null"), which falls through. That's
        // sufficient evidence the guard didn't fire — a guard-fired
        // path would short-circuit before any property access.
        $this->expectException(\Throwable::class);
        $push->getUnitSessionOutput($unitSession);
    }
}
