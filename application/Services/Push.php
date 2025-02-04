<?php

namespace App\Services;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use Exception;
use PDO;

class PushNotificationService
{
    protected WebPush $webPush;
    protected array $vapidKeys;
    protected int $maxRetries;
    protected int $batchSize;
    protected int $rateLimit; // messages per minute per session
    protected PDO $db;
    // Rate limits stored as: [session_id => ['count' => int, 'start' => timestamp]]
    protected array $rateLimits = [];

    /**
     * @param int   $runId  The survey_runs id used to retrieve VAPID keys.
     * @param PDO   $db     A PDO instance.
     * @param array $config Optional configuration: max_retries, batch_size, rate_limit.
     */
    public function __construct(int $runId, PDO $db, array $config = [])
    {
        $this->db = $db;

        // Set defaults; override if provided in $config.
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->batchSize  = $config['batch_size']  ?? 10;
        $this->rateLimit  = $config['rate_limit']  ?? 60;

        // Retrieve VAPID keys from survey_runs table.
        $this->vapidKeys = $this->getVapidKeys($runId);
        if (!$this->vapidKeys ||
            empty($this->vapidKeys['publicKey']) ||
            empty($this->vapidKeys['privateKey'])) {
            throw new Exception("VAPID keys not found for run ID: {$runId}");
        }

        $this->webPush = new WebPush([
            'VAPID' => [
                'subject'    => 'mailto:admin@example.com', // adjust as needed
                'publicKey'  => $this->vapidKeys['publicKey'],
                'privateKey' => $this->vapidKeys['privateKey'],
            ]
        ]);
    }

    /**
     * Retrieve VAPID keys from the survey_runs table.
     *
     * @param int $runId
     * @return array|null
     */
    protected function getVapidKeys(int $runId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT vapid_public_key, vapid_private_key FROM survey_runs WHERE id = :runId"
        );
        $stmt->execute([':runId' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'publicKey'  => $row['vapid_public_key'],
            'privateKey' => $row['vapid_private_key'],
        ];
    }

    /**
     * Batch-send push notifications.
     *
     * Each notification should be an array with:
     *  - session_id: int
     *  - run_id: int (should match the run id used in the constructor)
     *  - subscription: array (subscription data for WebPush\Subscription::create)
     *  - message: string
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
                $sessionId      = $notification['session_id'];
                $runId          = $notification['run_id'];
                $subscription   = $notification['subscription'];
                $message        = $notification['message'];

                try {
                    $this->checkRateLimit($sessionId);
                    $this->sendPushMessage($sessionId, $runId, $subscription, $message);
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
     * Send a single push notification with retry logic.
     *
     * @param int   $sessionId      Session ID for logging and rate limiting.
     * @param int   $runId          Run ID (must match constructor run id).
     * @param array $subscription   Subscription data.
     * @param string $message       Message to send.
     * @param int   $attempt        Current attempt count.
     *
     * @throws Exception on final failure.
     */
    public function sendPushMessage(
        int $sessionId,
        int $runId,
        array $subscription,
        string $message,
        int $attempt = 1
    ) {
        $sub = Subscription::create($subscription);
        $payload = json_encode([
            'title' => 'Notification',
            'body'  => $message,
            'icon'  => '/path/to/icon.png'
        ]);
        $this->webPush->queueNotification($sub, $payload);

        foreach ($this->webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                $this->logPushFailure($sessionId, $runId, $message, $report->getReason(), $attempt);
                if ($attempt < $this->maxRetries) {
                    sleep(pow(2, $attempt)); // exponential backoff
                    $this->sendPushMessage($sessionId, $runId, $subscription, $message, $attempt + 1);
                } else {
                    throw new Exception("Push notification permanently failed: " . $report->getReason());
                }
            } else {
                $this->logPushSuccess($sessionId, $runId, $message);
            }
        }
    }

    /**
     * Enforce rate limiting for a given session.
     *
     * @param int $sessionId
     * @throws Exception if rate limit is exceeded.
     */
    protected function checkRateLimit(int $sessionId)
    {
        $currentTime = time();
        if (!isset($this->rateLimits[$sessionId])) {
            $this->rateLimits[$sessionId] = ['count' => 0, 'start' => $currentTime];
        }

        $data = $this->rateLimits[$sessionId];

        // If more than 60 seconds have elapsed, reset the counter.
        if ($currentTime - $data['start'] >= 60) {
            $this->rateLimits[$sessionId] = ['count' => 0, 'start' => $currentTime];
        }

        if ($this->rateLimits[$sessionId]['count'] >= $this->rateLimit) {
            throw new Exception("Rate limit exceeded for session {$sessionId}.");
        }

        $this->rateLimits[$sessionId]['count']++;
    }

    /**
     * Log a successful push notification to the push_logs table.
     *
     * @param int    $sessionId
     * @param int    $runId
     * @param string $message
     */
    protected function logPushSuccess(int $sessionId, int $runId, string $message)
    {
        $stmt = $this->db->prepare(
            "INSERT INTO push_logs 
                (session_id, run_id, message, status, error_message, attempt, created_at)
             VALUES 
                (:session_id, :run_id, :message, 'success', NULL, 1, NOW())"
        );
        $stmt->execute([
            ':session_id' => $sessionId,
            ':run_id'     => $runId,
            ':message'    => $message
        ]);
    }

    /**
     * Log a failed push notification to the push_logs table.
     *
     * @param int    $sessionId
     * @param int    $runId
     * @param string $message
     * @param string $error
     * @param int    $attempt
     */
    protected function logPushFailure(
        int $sessionId,
        int $runId,
        string $message,
        string $error,
        int $attempt
    ) {
        $stmt = $this->db->prepare(
            "INSERT INTO push_logs 
                (session_id, run_id, message, status, error_message, attempt, created_at)
             VALUES 
                (:session_id, :run_id, :message, 'failed', :error_message, :attempt, NOW())"
        );
        $stmt->execute([
            ':session_id'    => $sessionId,
            ':run_id'        => $runId,
            ':message'       => $message,
            ':error_message' => $error,
            ':attempt'       => $attempt
        ]);
    }
}