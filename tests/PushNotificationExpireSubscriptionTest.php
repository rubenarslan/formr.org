<?php

/**
 * Coverage for PushNotificationService::markSubscriptionExpired().
 *
 * Validates the dead-subscription cleanup that fires when a push provider
 * returns 404/410 (browser uninstalled the PWA, user revoked permission,
 * iOS dropped the subscription). Without this cleanup the same dead
 * endpoint gets re-tried on every send for the rest of the run.
 *
 * The method is protected; invoked via Reflection. We instantiate the
 * service with newInstanceWithoutConstructor so we can inject only the
 * DB property without needing a Run + VAPID keys.
 *
 * Test scopes itself to a unique endpoint string per run so concurrent
 * runs against the live dev DB don't collide. Cleanup tears the
 * fixtures down regardless of test outcome.
 *
 * UNIQUE(session_id, item_id) on survey_items_display means we have to
 * mint a fresh survey_items row per seeded display row.
 *
 * @group integration
 */
class PushNotificationExpireSubscriptionTest extends \PHPUnit\Framework\TestCase
{
    /** @var DB */
    private $db;
    /** @var \ReflectionMethod */
    private $mark;
    /** @var PushNotificationService */
    private $service;

    private $endpoint;
    private $sessionId;
    private $studyId;
    private $itemIds = [];
    private $displayIds = [];

    protected function setUp(): void
    {
        $this->db = DB::getInstance();
        $this->endpoint = 'https://fcm.googleapis.com/test-' . bin2hex(random_bytes(8));

        $studyRow = $this->db->execute(
            'SELECT id FROM survey_studies ORDER BY id LIMIT 1',
            [],
            false,
            true
        );
        $sessRow = $this->db->execute(
            'SELECT id FROM survey_unit_sessions ORDER BY id LIMIT 1',
            [],
            false,
            true
        );
        if (!$studyRow || !$sessRow) {
            $this->markTestSkipped('No survey_studies / survey_unit_sessions rows to anchor FK');
        }
        $this->studyId = (int) $studyRow['id'];
        $this->sessionId = (int) $sessRow['id'];

        $this->service = (new \ReflectionClass(PushNotificationService::class))
            ->newInstanceWithoutConstructor();
        $dbProp = (new \ReflectionClass(PushNotificationService::class))
            ->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($this->service, $this->db);

        $this->mark = new \ReflectionMethod(
            PushNotificationService::class,
            'markSubscriptionExpired'
        );
        $this->mark->setAccessible(true);
    }

    protected function tearDown(): void
    {
        if ($this->displayIds) {
            $in = implode(',', array_map('intval', $this->displayIds));
            $this->db->exec("DELETE FROM survey_items_display WHERE id IN ($in)");
        }
        if ($this->itemIds) {
            $in = implode(',', array_map('intval', $this->itemIds));
            $this->db->exec("DELETE FROM survey_items WHERE id IN ($in)");
        }
    }

    private function newItem(): int
    {
        $id = (int) $this->db->insert('survey_items', [
            'study_id' => $this->studyId,
            'name' => 'test_push_' . bin2hex(random_bytes(4)),
            'type' => 'push_notification',
            'item_order' => 0,
            'block_order' => 0,
        ]);
        $this->itemIds[] = $id;
        return $id;
    }

    private function seedDisplay(string $answer): int
    {
        $id = (int) $this->db->insert('survey_items_display', [
            'item_id' => $this->newItem(),
            'session_id' => $this->sessionId,
            'answer' => $answer,
            'created' => date('Y-m-d H:i:s'),
            'saved' => date('Y-m-d H:i:s'),
        ]);
        $this->displayIds[] = $id;
        return $id;
    }

    private function answerOf(int $id): ?string
    {
        $row = $this->db->findRow('survey_items_display', ['id' => $id], ['answer']);
        return $row ? $row['answer'] : null;
    }

    public function testMarksMatchingEndpointExpired(): void
    {
        $sub = json_encode(['endpoint' => $this->endpoint, 'keys' => ['p256dh' => 'x', 'auth' => 'y']]);
        $id = $this->seedDisplay($sub);

        $this->mark->invoke($this->service, $this->endpoint);

        $this->assertSame('expired', $this->answerOf($id));
    }

    public function testLeavesOtherEndpointsAlone(): void
    {
        $sub = json_encode(['endpoint' => $this->endpoint, 'keys' => ['p256dh' => 'x', 'auth' => 'y']]);
        $other = json_encode(['endpoint' => 'https://other.example/' . bin2hex(random_bytes(4)), 'keys' => ['p256dh' => 'x', 'auth' => 'y']]);
        $id1 = $this->seedDisplay($sub);
        $id2 = $this->seedDisplay($other);

        $this->mark->invoke($this->service, $this->endpoint);

        $this->assertSame('expired', $this->answerOf($id1));
        $this->assertSame($other, $this->answerOf($id2));
    }

    public function testLeavesSentinelAnswersAlone(): void
    {
        $idNotReq = $this->seedDisplay('not_requested');
        $idNotSup = $this->seedDisplay('not_supported');
        $idIos = $this->seedDisplay('ios_version_not_supported');

        $this->mark->invoke($this->service, $this->endpoint);

        $this->assertSame('not_requested', $this->answerOf($idNotReq));
        $this->assertSame('not_supported', $this->answerOf($idNotSup));
        $this->assertSame('ios_version_not_supported', $this->answerOf($idIos));
    }

    public function testNoOpWhenNoMatch(): void
    {
        $sub = json_encode(['endpoint' => 'https://other.example/x', 'keys' => ['p256dh' => 'x', 'auth' => 'y']]);
        $id = $this->seedDisplay($sub);

        $this->mark->invoke($this->service, $this->endpoint);

        $this->assertSame($sub, $this->answerOf($id));
    }
}
