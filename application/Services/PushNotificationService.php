<?php

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PushNotificationService
{
    protected WebPush $webPush;
    protected array $vapidKeys;
    protected int $maxRetries;
    protected int $batchSize;
    protected int $rateLimit; // messages per minute per session
    protected DB $db;
    protected Run $run;
    protected $runSession;

    /**
     * @param Run    $run    The Run object representing the PWA/study
     * @param DB     $db     A DB instance.
     * @param array  $config Optional configuration: max_retries, batch_size, rate_limit.
     */
    public function __construct(Run $run, DB $db, array $config = [])
    {
        $this->db = $db;
        $this->run = $run;

        // Set defaults; override if provided in $config.
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->batchSize  = $config['batch_size']  ?? 10;
        $this->rateLimit  = $config['rate_limit']  ?? 60;

        // Get VAPID keys from the run
        $this->vapidKeys = [
            'publicKey' => $run->getVapidPublicKey(),
            'privateKey' => $this->getVapidPrivateKey($run->id)
        ];

        if (!$this->vapidKeys['publicKey'] || !$this->vapidKeys['privateKey']) {
            throw new Exception("VAPID keys not found for run: {$run->name}");
        }

        $owner = $run->getOwner();
        $this->webPush = new WebPush([
            'VAPID' => [
                'subject'    => "mailto:{$owner->email}",
                'publicKey'  => $this->vapidKeys['publicKey'],
                'privateKey' => $this->vapidKeys['privateKey'],
            ]
        ]);
    }

    /**
     * Retrieve encrypted VAPID private key from the survey_runs table.
     *
     * @param int $runId
     * @return string|null
     */
    protected function getVapidPrivateKey(int $runId): ?string
    {
        $row = $this->db->findRow('survey_runs', ['id' => $runId], ['vapid_private_key']);
        if (!$row) {
            return null;
        }
        
        // Decrypt the private key
        return \Crypto::decrypt($row['vapid_private_key']);
    }

    /**
     * Send a single push notification with retry logic.
     *
     * @param int   $sessionId      Session ID for logging and rate limiting.
     * @param array $subscription   Subscription data.
     * @param array $options        Notification options including message.
     * @param int   $attempt        Current attempt count.
     *
     * @throws Exception on final failure.
     */
    public function sendPushMessage(
        int $sessionId,
        array $subscription,
        array $options,
        int $attempt = 1
    ) {
        // Check rate limits before sending
        $rateLimit = new RateLimitService($this->db, false, false);
        $result = $rateLimit->isAllowedToSend((string)$sessionId, 'push_logs');
        
        if (!$result['allowed']) {
            throw new Exception($result['message']);
        }

        if ($result['message'] !== null) {
            error_log("Push notification warning: " . $result['message']);
        }

        // Extract message from options
        $message = $options['message'];

        $sub = Subscription::create($subscription);

        $title = $options['title'] ?? 'Notification';
        $clickTarget = $options['clickTarget'] ?? run_url($this->run->name);
        $tag = $options['title'] ?? null;

        $payload = json_encode([
            'title' => $title,
            'body'  => $message,
            'clickTarget' => $clickTarget,
            'tag' => $tag,
            'priority' => $options['priority'] ?? 'normal',
            'timeToLive' => isset($options['timeToLive']) ? (int)$options['timeToLive'] : null,
            'badgeCount' => isset($options['badgeCount']) ? (int)$options['badgeCount'] : null,
            'vibrate' => isset($options['vibrate']) ? (bool)$options['vibrate'] : true,
            'requireInteraction' => isset($options['requireInteraction']) ? (bool)$options['requireInteraction'] : false,
            'renotify' => isset($options['renotify']) ? (bool)$options['renotify'] : false,
            'silent' => isset($options['silent']) ? (bool)$options['silent'] : false,

            // Declarative Web Push (Safari 18.4+, RFC 8030): if the service worker
            // fails to call showNotification(), the browser uses this object to
            // display the notification natively. Prevents iOS from counting the
            // push as "silent" and terminating the subscription.
            'web_push' => 8030,
            'notification' => [
                'title' => $title,
                'options' => [
                    'body' => $message,
                    'data' => [
                        'clickTarget' => $clickTarget,
                    ],
                    'tag' => $tag,
                ],
            ],
        ]);
        
        // Debug log the payload
        error_log("Push notification payload: " . $payload);
        
        $this->webPush->queueNotification($sub, $payload);

        foreach ($this->webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                $this->logPushFailure($sessionId, $message, $report->getReason(), $attempt);
                // 404/410 from the push service: the subscription is gone for good
                // (browser uninstalled the PWA, user revoked permissions, iOS
                // dropped it after silent-push budget exhaustion). No amount of
                // retries will resurrect it; mark it expired so future sends
                // skip it and the participant gets re-prompted to subscribe.
                if ($report->isSubscriptionExpired()) {
                    $this->markSubscriptionExpired($report->getEndpoint());
                    throw new Exception("Push notification subscription expired (410/404); marked for re-subscribe.");
                }
                if ($attempt < $this->maxRetries) {
                    sleep(pow(2, $attempt)); // exponential backoff
                    $this->sendPushMessage($sessionId, $subscription, $options, $attempt + 1);
                } else {
                    throw new Exception("Push notification permanently failed: " . $report->getReason());
                }
            } else {
                $this->logPushSuccess($sessionId, $message);
            }
        }
    }

    /**
     * Flag every survey_items_display row carrying this endpoint as expired.
     *
     * Endpoint is globally unique per browser-installation, so the same dead
     * endpoint across multiple sessions / runs is dead everywhere. Mark them
     * all in one shot so subsequent getSubscription() calls see no
     * subscription and can re-prompt.
     */
    protected function markSubscriptionExpired(string $endpoint): void
    {
        // Two-step: SELECT JSON-shaped push_notification rows (sentinels
        // like 'not_requested' don't contain "endpoint"), parse each
        // answer in PHP, match exactly. We can't do this purely in SQL
        // because MariaDB's optimizer evaluates JSON_EXTRACT before NOT
        // IN filters, so a single bad row in the table aborts the whole
        // UPDATE with "Syntax error in JSON text". And we can't LIKE on
        // the endpoint URL itself because json_encode escapes slashes
        // (`\/`) but JSON.stringify doesn't, so stored shapes vary.
        try {
            $rows = $this->db->execute(
                "SELECT sid.id, sid.answer
                 FROM survey_items_display sid
                 JOIN survey_items si ON si.id = sid.item_id
                 WHERE si.type = 'push_notification'
                   AND sid.answer LIKE '%\"endpoint\"%'"
            );
            $matches = [];
            foreach ((array) $rows as $row) {
                $decoded = json_decode($row['answer'], true);
                if (is_array($decoded) && isset($decoded['endpoint']) && $decoded['endpoint'] === $endpoint) {
                    $matches[] = (int) $row['id'];
                }
            }
            if (!$matches) {
                return;
            }
            $in = implode(',', $matches);
            $this->db->execute("UPDATE survey_items_display SET answer = 'expired' WHERE id IN ($in)");
        } catch (Throwable $e) {
            error_log("markSubscriptionExpired failed for endpoint {$endpoint}: " . $e->getMessage());
        }
    }

    /**
     * Batch-send push notifications.
     *
     * Each notification should be an array with:
     *  - session_id: int
     *  - subscription: array (subscription data for WebPush\Subscription::create)
     *  - options: array of notification options including message
     *
     * @param array $notifications
     * @return int Number of notifications sent successfully.
     */
    public function sendPushMessages(array $notifications): int
    {
        $sentCount = 0;
        $batches = array_chunk($notifications, $this->batchSize);
        foreach ($batches as $batch) {
            foreach ($batch as $notification) {
                $sessionId = $notification['session_id'];
                $subscription = $notification['subscription'];
                $options = $notification['options'];

                try {
                    $this->sendPushMessage($sessionId, $subscription, $options);
                    $sentCount++;
                } catch (Exception $e) {
                    error_log("Push notification failed for session {$sessionId}: " . $e->getMessage());
                }
            }
            // Pause between batches to help with rate limiting.
            sleep(1);
        }
        return $sentCount;
    }

    /**
     * Log a successful push notification. Track A A9: prefer UPDATE
     * of the claim row that PushMessage::getUnitSessionOutput inserted
     * (idempotency_key = "push:{sessionId}") so each send corresponds
     * to exactly ONE push_logs row instead of two (claim + audit).
     * Falls back to INSERT for callers that bypass the PushMessage
     * handler (notably the batch sendPushMessages path) where no
     * claim row exists.
     *
     * @param int    $sessionId
     * @param string $message
     */
    protected function logPushSuccess(int $sessionId, string $message)
    {
        $this->upsertPushLog($sessionId, $message, 'success', null, 1);
    }

    /**
     * Log a failed push notification. Same UPDATE-then-fallback-to-INSERT
     * shape as logPushSuccess. The retry loop in sendPushMessage calls
     * this once per attempt — under the UPDATE path, the row's
     * `attempt` and `error_message` reflect the LAST attempt; the
     * intermediate retry history is lost (acceptable trade-off; the
     * pre-fix per-attempt audit rows weren't load-bearing for any
     * downstream consumer).
     *
     * @param int    $sessionId
     * @param string $message
     * @param string $error
     * @param int    $attempt
     */
    protected function logPushFailure(
        int $sessionId,
        string $message,
        string $error,
        int $attempt
    ) {
        $this->upsertPushLog($sessionId, $message, 'failed', $error, $attempt);
    }

    /**
     * Track A A9 helper: UPDATE the claim row for this sessionId, or
     * INSERT if no claim exists (batch path / pre-Track-A historical
     * paths). Single source of truth for what gets written into
     * push_logs from the service. The claim's `idempotency_key` is
     * derived from sessionId by the same convention used in
     * PushMessage::getUnitSessionOutput — keep both sides in lockstep.
     */
    private function upsertPushLog(int $sessionId, string $message, string $status, ?string $error, int $attempt): void
    {
        $idempotency_key = "push:{$sessionId}";

        $affected = $this->db->exec(
            "UPDATE `push_logs`
             SET `status` = :status,
                 `error_message` = :error,
                 `attempt` = :attempt,
                 `message` = :message
             WHERE `idempotency_key` = :key",
            [
                'status'  => $status,
                'error'   => $error,
                'attempt' => $attempt,
                'message' => $message,
                'key'     => $idempotency_key,
            ]
        );

        if ((int) $affected > 0) {
            return;
        }

        // No claim row to update — fall back to INSERT. Happens for the
        // batch sendPushMessages path that bypasses PushMessage. The
        // INSERT also carries the idempotency_key so a follow-up retry
        // through the same path no-ops on the UNIQUE collision; if the
        // status differs (success vs failed across retries), the next
        // call lands here again, finds the row via UPDATE, and writes
        // through.
        $this->db->exec(
            "INSERT INTO `push_logs`
                (`unit_session_id`, `run_id`, `message`, `status`,
                 `error_message`, `attempt`, `created`, `idempotency_key`)
             VALUES
                (:us_id, :run_id, :message, :status,
                 :error, :attempt, NOW(), :key)
             ON DUPLICATE KEY UPDATE
                `status` = VALUES(`status`),
                `error_message` = VALUES(`error_message`),
                `attempt` = VALUES(`attempt`),
                `message` = VALUES(`message`)",
            [
                'us_id'   => $sessionId,
                'run_id'  => $this->run->id,
                'message' => $message,
                'status'  => $status,
                'error'   => $error,
                'attempt' => $attempt,
                'key'     => $idempotency_key,
            ]
        );
    }
}