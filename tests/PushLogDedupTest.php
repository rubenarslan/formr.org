<?php

/**
 * Track A A9 — push_logs de-duplication.
 *
 * Pre-fix: PushMessage::getUnitSessionOutput inserted a "claim" row
 * (status='queued', idempotency_key set), then PushNotificationService::
 * logPushSuccess / logPushFailure inserted SEPARATE audit rows
 * (idempotency_key NULL). Result: two rows per send, observable in
 * the admin push-log view as duplicates and in the storage cost as
 * 2× growth.
 *
 * Fix: logPushSuccess / logPushFailure now UPDATE the claim row by
 * `idempotency_key = "push:{sessionId}"`. They fall back to INSERT
 * only when no claim exists (batch sendPushMessages path).
 *
 * These tests pin the upsert helper's two arms — the UPDATE-claim
 * path and the INSERT-fallback path — without standing up a real
 * VAPID-keyed PushNotificationService, by injecting a mock $db.
 */
class PushLogDedupTest extends \PHPUnit\Framework\TestCase
{
    private function buildService(DB $db): PushNotificationService
    {
        $svc = (new ReflectionClass(PushNotificationService::class))
            ->newInstanceWithoutConstructor();
        // PushNotificationService::$run is typed as `Run`; build one
        // via newInstanceWithoutConstructor and just set $id (the
        // only field upsertPushLog reads).
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $run->id = 42;
        foreach (['db' => $db, 'run' => $run] as $prop => $val) {
            $r = new ReflectionProperty(PushNotificationService::class, $prop);
            $r->setAccessible(true);
            $r->setValue($svc, $val);
        }
        return $svc;
    }

    /**
     * UPDATE path: when a claim row exists for `idempotency_key =
     * "push:{sessionId}"`, logPushSuccess UPDATEs it (no INSERT).
     */
    public function testLogPushSuccessUpdatesExistingClaimRow(): void
    {
        $db = $this->createMock(DB::class);

        // First call (UPDATE) returns 1 row affected — claim existed.
        // The INSERT branch must NOT be reached.
        $db->expects($this->once())
            ->method('exec')
            ->with(
                $this->stringContains('UPDATE `push_logs`'),
                $this->callback(function ($params) {
                    // `??` swallows explicit-null vs missing-key — check
                    // explicit-null on `error` via array_key_exists.
                    return ($params['key'] ?? null) === 'push:7'
                        && ($params['status'] ?? null) === 'success'
                        && ($params['attempt'] ?? null) === 1
                        && array_key_exists('error', $params)
                        && $params['error'] === null;
                })
            )
            ->willReturn(1);

        $svc = $this->buildService($db);
        $m = new ReflectionMethod(PushNotificationService::class, 'logPushSuccess');
        $m->setAccessible(true);
        $m->invoke($svc, 7, 'hello');
        // Mock's expects()->once() throws on tearDown if not satisfied;
        // surface that as an explicit assertion so PHPUnit doesn't mark
        // the test "risky / did no assertions".
        $this->addToAssertionCount(1);
    }

    /**
     * INSERT-fallback path: when UPDATE affects 0 rows (no claim
     * exists — e.g. batch sendPushMessages call), the helper falls
     * back to INSERT … ON DUPLICATE KEY UPDATE.
     */
    public function testLogPushFailureFallsBackToInsertWhenNoClaim(): void
    {
        $db = $this->createMock(DB::class);

        $callIdx = 0;
        $db->expects($this->exactly(2))
            ->method('exec')
            ->willReturnCallback(function ($sql, $params) use (&$callIdx) {
                $callIdx++;
                if ($callIdx === 1) {
                    $this->assertStringContainsString('UPDATE `push_logs`', $sql);
                    $this->assertSame('push:9', $params['key']);
                    return 0; // no claim row → fall through to INSERT
                }
                $this->assertStringContainsString('INSERT INTO `push_logs`', $sql);
                $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
                $this->assertSame('push:9',  $params['key']);
                $this->assertSame('failed',  $params['status']);
                $this->assertSame('boom',    $params['error']);
                $this->assertSame(2,         $params['attempt']);
                $this->assertSame(9,         $params['us_id']);
                $this->assertSame(42,        $params['run_id']);
                return 1;
            });

        $svc = $this->buildService($db);
        $m = new ReflectionMethod(PushNotificationService::class, 'logPushFailure');
        $m->setAccessible(true);
        $m->invoke($svc, 9, 'msg', 'boom', 2);
    }

    /**
     * The idempotency key format must match the convention used by
     * PushMessage::getUnitSessionOutput. If either side drifts, the
     * UPDATE WHERE never matches and we silently degrade to INSERT,
     * re-introducing the duplication. Source-grep both sides.
     */
    public function testIdempotencyKeyFormatMatchesPushMessageHandler(): void
    {
        $svcSrc  = file_get_contents(APPLICATION_ROOT . 'application/Services/PushNotificationService.php');
        $handlerSrc = file_get_contents(APPLICATION_ROOT . 'application/Model/RunUnit/PushMessage.php');

        $this->assertMatchesRegularExpression(
            '/"push:\{\$sessionId\}"/',
            $svcSrc,
            'PushNotificationService must build idempotency_key as "push:{sessionId}"'
        );
        $this->assertMatchesRegularExpression(
            '/"push:\{\$unitSession->id\}"/',
            $handlerSrc,
            'PushMessage handler must build idempotency_key as "push:{unitSession->id}" — same shape, different variable name'
        );
    }
}
