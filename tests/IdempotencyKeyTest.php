<?php

/**
 * Track A A4 — closes R5.
 *
 * Without idempotency_key + UNIQUE on survey_email_log / push_logs, a
 * SIGKILL'd daemon between a send-row INSERT and the success-marker
 * UPDATE leaves an orphan: the row exists but `result` on the parent
 * unit-session is still NULL. On restart, the v0.25.7 terminal-result
 * guard sees result=NULL, falls through, and re-INSERTs / re-sends a
 * duplicate.
 *
 * The Track A fix:
 *  - Email::queueNow uses INSERT ... ON DUPLICATE KEY UPDATE id=id keyed
 *    on idempotency_key = "email:{unit_session_id}:{email_id}". The
 *    second attempt collides on UNIQUE and is a silent no-op.
 *  - PushMessage::getUnitSessionOutput INSERTs a "claim" push_logs row
 *    with idempotency_key = "push:{unit_session_id}" BEFORE invoking
 *    sendPushMessage. The second attempt collides and the handler
 *    short-circuits.
 *
 * These tests pin two things at the unit level:
 *  1. The idempotency-key strings (so renames or schema changes can't
 *     silently break the cross-attempt dedup).
 *  2. The Email::queueNow / PushMessage::getUnitSessionOutput source
 *     wires the idempotency_key into its INSERT path.
 *
 * The actual UNIQUE-constraint behavior is exercised by the live-DB
 * smoke at bin/test_track_a_idempotency_smoke.php.
 */
class IdempotencyKeyTest extends \PHPUnit\Framework\TestCase
{
    public function testEmailQueueNowSourceUsesIdempotencyKey(): void
    {
        $src = file_get_contents(APPLICATION_ROOT . 'application/Model/RunUnit/Email.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString(
            'idempotency_key',
            $src,
            'Email.php must reference idempotency_key (Track A A4 / R5)'
        );

        $this->assertMatchesRegularExpression(
            "/email:.*\\\$unitSession->id.*\\\$this->id/s",
            $src,
            "Email.php must build idempotency_key as 'email:{unit_session_id}:{email_id}'"
        );

        $this->assertMatchesRegularExpression(
            '/ON\s+DUPLICATE\s+KEY\s+UPDATE/i',
            $src,
            'Email.php must use INSERT ... ON DUPLICATE KEY UPDATE for idempotent email_log inserts'
        );
    }

    public function testPushMessageSourceUsesIdempotencyKey(): void
    {
        $src = file_get_contents(APPLICATION_ROOT . 'application/Model/RunUnit/PushMessage.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString(
            'idempotency_key',
            $src,
            'PushMessage.php must reference idempotency_key (Track A A4 / R5)'
        );

        $this->assertMatchesRegularExpression(
            "/push:.*\\\$unitSession->id/s",
            $src,
            "PushMessage.php must build idempotency_key as 'push:{unit_session_id}'"
        );
    }

    /**
     * Push handler must short-circuit on a duplicate idempotency-key
     * insert. We probe the helper that decides early-return shape on
     * an idempotent skip — the existing v0.25.7 terminal-result guard
     * uses ['move_on' => true]; the new idempotent skip should match
     * the same shape so callers (RunSession::executeUnitSession) treat
     * it the same way.
     */
    public function testPushIdempotentSkipReturnsMoveOnShape(): void
    {
        // Mirror the v0.25.7 guard shape — ['move_on' => true]. If the
        // idempotent skip uses a different shape, the cascade
        // dispatcher in RunSession won't treat it as completed and the
        // run-session will dangle. Rather than re-implement the guard,
        // we read the source for the expected return literal next to
        // the idempotent-skip branch.
        $src = file_get_contents(APPLICATION_ROOT . 'application/Model/RunUnit/PushMessage.php');
        $this->assertMatchesRegularExpression(
            "/Idempotent skip[^}]{0,500}'move_on'\s*=>\s*true/s",
            $src,
            "PushMessage idempotent-skip branch should return ['move_on' => true] like the v0.25.7 guard"
        );
    }
}
