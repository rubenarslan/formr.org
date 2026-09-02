<?php

/**
 * X4 (documentation/agent_doc/LOG_STATE_AUDIT.md) — the admin-notification
 * throttle must not silence DISTINCT problems, and what it does silence
 * must leave a trace.
 *
 * Notification::canBeSent throttled per run + recipient + type, so every
 * error source in a run shared one 10-minute email slot, and a suppressed
 * notification was recorded nowhere. These tests drive the real
 * Notification flow against the SQLite lane; only the SMTP delivery step
 * is stubbed (NTTRecordingNotification::deliver, in
 * tests/helpers/notification_test_stubs.php — loaded lazily from setUp()
 * so test discovery doesn't pre-load User under ClassLoaderTest's feet).
 */
class NotificationThrottleTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/helpers/notification_test_stubs.php';

        $pdo = DB::getInstance()->pdo();
        $pdo->exec('DROP TABLE IF EXISTS survey_notifications');
        $pdo->exec("CREATE TABLE survey_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            session_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            type TEXT NOT NULL DEFAULT 'error',
            created TEXT NOT NULL,
            recipient_id INTEGER NOT NULL,
            fingerprint TEXT DEFAULT NULL,
            suppressed INTEGER NOT NULL DEFAULT 0
        )");
    }

    private function makeUnitSession(int $unitId, int $position = 5): UnitSession
    {
        $owner = (new ReflectionClass(NTTUser::class))->newInstanceWithoutConstructor();
        $owner->id = 99;

        $run = (new ReflectionClass(NTTRun::class))->newInstanceWithoutConstructor();
        $run->id = 1;
        $run->ownerStub = $owner;

        $runSession = (new ReflectionClass(NTTRunSession::class))->newInstanceWithoutConstructor();
        $runSession->runStub = $run;

        $unit = (new ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $unit->id = $unitId;
        $unit->position = $position;
        $unit->type = 'Page';

        $us = (new ReflectionClass(UnitSession::class))->newInstanceWithoutConstructor();
        $us->id = 1000 + $unitId;
        $us->runSession = $runSession;
        $us->runUnit = $unit;

        return $us;
    }

    public function testDistinctUnitProblemsAreThrottledIndependently(): void
    {
        $n = new NTTExposedNotification();
        $unitA = $this->makeUnitSession(11);
        $unitB = $this->makeUnitSession(22, 7);
        $owner = $unitA->runSession->getRun()->getOwner();

        $n->logSentPublic($unitA, 'Pause condition broke', 'error', $owner);

        $this->assertFalse($n->canBeSentPublic($unitA, 'error'), 'a repeat of the SAME problem stays throttled');
        $this->assertTrue($n->canBeSentPublic($unitB, 'error'), "a DIFFERENT unit's problem must not be silenced by unit A's email");
    }

    public function testThrottledNotificationLeavesATrace(): void
    {
        $n = new NTTRecordingNotification();
        $unitA = $this->makeUnitSession(11);

        $this->assertTrue($n->notifyStudyAdmin($unitA, 'first failure', 'error'), 'first notification must be delivered');
        $this->assertFalse($n->notifyStudyAdmin($unitA, 'second failure', 'error'), 'second within the window is throttled');

        $suppressed = DB::getInstance()->pdo()
            ->query('SELECT COUNT(*) FROM survey_notifications WHERE suppressed = 1')
            ->fetchColumn();
        $this->assertEquals(1, $suppressed, 'a throttled notification must be recorded, not vanish');
    }

    public function testGuardRefusalAnchorsTheThrottleButAnSmtpFailureDoesNot(): void
    {
        $n = new NTTGuardRefusedNotification();
        $unitA = $this->makeUnitSession(11);

        $this->assertFalse($n->notifyStudyAdmin($unitA, 'owner address suppressed', 'error'));
        $this->assertFalse($n->notifyStudyAdmin($unitA, 'again', 'error'), 'throttled: the guard will refuse again anyway');
        $this->assertSame(1, $n->attempts, 'the guard is not re-hit on every recurrence');
        $rows = DB::getInstance()->pdo()->query('SELECT suppressed FROM survey_notifications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals([0, 1], $rows, 'the refusal anchors the window like a delivery; the repeat is a suppression');

        DB::getInstance()->pdo()->exec('DELETE FROM survey_notifications');
        $n = new NTTGuardRefusedNotification();
        $n->smtpFailure = true;
        $this->assertFalse($n->notifyStudyAdmin($unitA, 'smtp down', 'error'));
        $this->assertFalse($n->notifyStudyAdmin($unitA, 'smtp still down', 'error'));
        $this->assertSame(2, $n->attempts, 'a transient SMTP failure leaves the window open for a retry');
        $this->assertEquals(0, DB::getInstance()->pdo()->query('SELECT COUNT(*) FROM survey_notifications')->fetchColumn(), 'nothing delivered, nothing anchored');
    }

    public function testNextEmailCarriesSuppressionDigest(): void
    {
        $n = new NTTRecordingNotification();
        $unitA = $this->makeUnitSession(11);

        $n->notifyStudyAdmin($unitA, 'first failure', 'error');
        $n->notifyStudyAdmin($unitA, 'again', 'error');
        $n->notifyStudyAdmin($unitA, 'and again', 'error');

        // Age the delivered notification past the 10-minute window.
        DB::getInstance()->pdo()->exec("UPDATE survey_notifications SET created = '"
            . mysql_datetime(strtotime('-11 minutes')) . "' WHERE suppressed = 0");

        $this->assertTrue($n->notifyStudyAdmin($unitA, 'later failure', 'error'));
        $this->assertCount(2, $n->delivered);
        $this->assertStringContainsString('later failure', $n->delivered[1]);
        $this->assertStringContainsString('2 similar', $n->delivered[1], 'the delivered email must say how many were suppressed');
    }
}
