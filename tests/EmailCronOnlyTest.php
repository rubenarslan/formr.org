<?php

/**
 * Coverage for the Email::cron_only gate, and for the User::cron flag
 * that the gate consults via isExecutedByCron().
 *
 * Track A's A5 task fixes this latent bug:
 * pre-fix, `User::$cron` was never set to true anywhere in the codebase,
 * so isExecutedByCron() always returned false. As a consequence, an
 * Email unit configured with `cron_only=true` was permanently skipped on
 * BOTH the web request path AND the cron tick — admins could mark an
 * email "cron_only" and it would never be sent, anywhere.
 *
 * The fix flips `RunSession->user->cron = true` inside
 * UnitSessionQueue::processQueue right before calling
 * `$runSession->execute()`, so the gate distinguishes web vs cron.
 *
 * These tests probe the gate at the smallest reachable level via
 * Reflection — Email::getUnitSessionOutput consults
 * $unitSession->isExecutedByCron(), which calls
 * $unitSession->runSession->isCron(), which calls
 * $unitSession->runSession->user->isCron(). We mock the chain and
 * check the gate's two-arm behaviour.
 */
class EmailCronOnlyTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Build a (Email, UnitSession) pair where the runSession's user has
     * the given cron flag. Bypasses constructors so we don't need to
     * stand up a real Run / DB / EmailAccount.
     */
    private function buildEmailAndUnitSession(bool $cronOnly, bool $userCron): array
    {
        $email = (new ReflectionClass(Email::class))->newInstanceWithoutConstructor();
        // Email's cron_only is protected — set via Reflection.
        $r = new ReflectionProperty(Email::class, 'cron_only');
        $r->setAccessible(true);
        $r->setValue($email, $cronOnly ? 1 : 0);

        $user = (new ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $user->cron = $userCron;

        $runSession = (new ReflectionClass(RunSession::class))->newInstanceWithoutConstructor();
        $runSession->user = $user;

        $unitSession = (new ReflectionClass(UnitSession::class))->newInstanceWithoutConstructor();
        $unitSession->runSession = $runSession;
        $unitSession->result = null;

        return [$email, $unitSession];
    }

    /**
     * cron_only=true + web request (user->cron=false) — gate must skip
     * the send and return end_session+log. This arm worked correctly
     * pre-fix and is preserved post-fix.
     */
    public function testCronOnlyEmailSkippedOnWebRequest(): void
    {
        [$email, $us] = $this->buildEmailAndUnitSession(true, false);

        $output = $email->getUnitSessionOutput($us);

        $this->assertTrue($output['end_session'] ?? false, 'cron_only email on web should set end_session');
        $this->assertArrayHasKey('log', $output);
        $this->assertSame('email_skipped_user_active', $output['log']['result'] ?? null,
            'cron_only email on web should log the skip reason');
    }

    /**
     * cron_only=true + cron tick (user->cron=true) — gate must NOT skip;
     * control falls through to the actual send path. Pre-fix this arm
     * was unreachable (user->cron was never set anywhere).
     *
     * We can't actually send the email in this test (no Run / EmailAccount
     * fixtures), but we can prove the gate didn't fire by observing that
     * the output is NOT the early-skip shape.
     */
    public function testCronOnlyEmailDoesNotSkipOnCronTick(): void
    {
        [$email, $us] = $this->buildEmailAndUnitSession(true, true);

        // The actual send path consults runSession->canReceiveMails(),
        // recipient, subject, body, EmailAccount — none of which are
        // wired in our fake. So we expect a Throwable. What we DON'T
        // want is the gate's silent end_session=true skip — that would
        // mean the cron tick is still misclassified as a web request
        // and the cron_only email never sends.
        $output = null;
        try {
            $output = $email->getUnitSessionOutput($us);
        } catch (\Throwable $e) {
            // Falling into the send path is the correct behaviour.
            $this->addToAssertionCount(1);
            return;
        }

        // If we reach here, getUnitSessionOutput returned without
        // throwing. The only way that can happen is the gate fired
        // OR canReceiveMails returned false. Both are not what we
        // want when user->cron is true.
        $this->assertNotSame(
            'email_skipped_user_active',
            $output['log']['result'] ?? null,
            'cron_only email on cron MUST NOT log email_skipped_user_active — that means the gate misclassified the cron request as user-driven (latent bug A7 / Track A A5)'
        );
    }

    /**
     * cron_only=false + web request — gate must NOT skip. Sanity-check
     * that the gate keys on cron_only and not on user->cron alone.
     */
    public function testNonCronOnlyEmailDoesNotSkipOnWebRequest(): void
    {
        [$email, $us] = $this->buildEmailAndUnitSession(false, false);

        $output = null;
        try {
            $output = $email->getUnitSessionOutput($us);
        } catch (\Throwable $e) {
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertNotSame(
            'email_skipped_user_active',
            $output['log']['result'] ?? null,
            'non-cron_only email on web should not be skipped'
        );
    }

    /**
     * UnitSessionQueue::processQueue should set User->cron = true on the
     * runSession's user before invoking execute(), so that downstream
     * isExecutedByCron() returns true and the cron_only gate fires
     * correctly. We can't drive the full processQueue here without a
     * live DB, so we assert via static analysis that the line exists in
     * the source. Brittle but cheap; pairs with the e2e/integration run
     * that exercises a real cron tick through the queue.
     *
     * If the line moves or gets refactored, the test fails loudly — at
     * which point the new wiring needs an equivalent assertion.
     */
    public function testProcessQueueSetsUserCronFlag(): void
    {
        $src = file_get_contents(APPLICATION_ROOT . 'application/Queue/UnitSessionQueue.php');
        $this->assertNotFalse($src, 'UnitSessionQueue.php source must be readable');
        $this->assertMatchesRegularExpression(
            '/\$runSession->user->cron\s*=\s*true/',
            $src,
            "UnitSessionQueue::processQueue must set \$runSession->user->cron = true so cron-only Email gate fires correctly. See REFACTOR_QUEUE_PLAN.md A5 / A7 for context."
        );
    }
}
