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
        $payload = json_encode([
            'title' => $options['title'] ?? null,
            'body'  => $message,
            'clickTarget' => $options['clickTarget'] ?? run_url($this->run->name),
            // Include additional notification options
            'tag' => $options['title'] ?? null,
            'priority' => $options['priority'] ?? 'normal',
            // Use explicit isset checks for numeric values that could be 0
            'timeToLive' => isset($options['timeToLive']) ? (int)$options['timeToLive'] : null,
            // badgeCount is a custom property, not part of the standard Web Notifications API
            // It will be stored in the notification's data object
            'badgeCount' => isset($options['badgeCount']) ? (int)$options['badgeCount'] : null,
            // Ensure vibrate is explicitly set to true or false, never null or undefined
            'vibrate' => isset($options['vibrate']) ? (bool)$options['vibrate'] : true,
            // Ensure other boolean options are explicitly true or false
            'requireInteraction' => isset($options['requireInteraction']) ? (bool)$options['requireInteraction'] : false,
            'renotify' => isset($options['renotify']) ? (bool)$options['renotify'] : false,
            'silent' => isset($options['silent']) ? (bool)$options['silent'] : false
        ]);
        
        // Debug log the payload
        error_log("Push notification payload: " . $payload);
        
        $this->webPush->queueNotification($sub, $payload);

        foreach ($this->webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                $this->logPushFailure($sessionId, $message, $report->getReason(), $attempt);
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
     * Log a successful push notification to the push_logs table.
     *
     * @param int    $sessionId
     * @param string $message
     */
    protected function logPushSuccess(int $sessionId, string $message)
    {
        $this->db->insert('push_logs', [
            'unit_session_id' => $sessionId,
            'run_id' => $this->run->id,
            'message' => $message,
            'status' => 'success',
            'error_message' => null,
            'attempt' => 1,
            'created' => mysql_now()
        ]);
    }

    /**
     * Log a failed push notification to the push_logs table.
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
        $this->db->insert('push_logs', [
            'unit_session_id' => $sessionId,
            'run_id' => $this->run->id,
            'message' => $message,
            'status' => 'failed',
            'error_message' => $error,
            'attempt' => $attempt,
            'created' => mysql_now()
        ]);
    }
}