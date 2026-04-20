<?php

class Notification {

    /**
     * @var array
     */
    protected $config;

    /**
     * @var Notification
     */
    protected static $instance = null;

    /**
     * @var DB
     */
    protected $pdo;

    public function __construct()
    {
        $this->config = Config::get('notification', [
            'default_throttle_minutes' => 10,
        ]);
        $this->pdo = DB::getInstance();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Notify the study administrator about an issue
     * 
     * @param UnitSession $unitSession The run session where the issue occurred
     * @param string $message The notification message
     * @param string $type The type of notification (error, warning, info)
     * @return bool Whether the notification was sent successfully
     */
    public function notifyStudyAdmin(UnitSession $unitSession, string $message, string $type = 'error'): bool {
        if (!$unitSession || !$unitSession->runSession || !$unitSession->runSession->getRun()) {
            return false;
        }

        $studyOwner = $unitSession->runSession->getRun()->getOwner();
        if (!$studyOwner) {
            return false;
        }

        if (!$this->canBeSent($unitSession, $type)) {
            return false;
        }

        // Get the owner's email account
        $emailAccounts = $studyOwner->getEmailAccounts();
        if (empty($emailAccounts)) {
            return false;
        }

        // Use the first available email account
        $account = new EmailAccount($emailAccounts[0]['id'], $studyOwner->id);
        if (!$account || !$account->account) {
            return false;
        }

        // Create mailer instance
        $mailer = $account->makeMailer();
        if (!$mailer) {
            return false;
        }

        // Prepare email
        $mailer->IsHTML(true);
        $mailer->AddAddress($studyOwner->email);
        $mailer->Subject = "[formr] Run Notification";

        // Map type to a CSS hex color for the email border
        $typeColor = $type === 'error' ? '#dc3545' : ($type === 'warning' ? '#ffc107' : '#17a2b8');
        $formattedMessage = Template::get_replace('email/notification.ftpl', [
            'typeColor' => $typeColor,
            'message' => $message,
            'run_name' => $unitSession->runSession->getRun()->name,
            'run_unit' => $unitSession->runUnit->position . '. ' . $unitSession->runUnit->type,
            'session' => $unitSession->runSession->session,
            'date' => date('Y-m-d H:i:s')
        ]);

        $mailer->Body = $formattedMessage;
        
        // Send the notification
        if ($mailer->Send()) {
            // Log the notification
            $this->logNotification($unitSession, $message, $type, $studyOwner);
            return true;
        }
        
        return false;
    }
    
    /**
     * Log the notification in the database
     * 
     * @param UnitSession $unitSession The run session where the issue occurred
     * @param string $message The notification message
     * @param string $type The type of notification (error, warning, info)
     * @param User $recipient The recipient of the notification
     */
    protected function logNotification(UnitSession $unitSession, string $message, string $type, User $recipient): void {
        DB::getInstance()->insert('survey_notifications', [
            'run_id' => $unitSession->runSession->getRun()->id,
            'session_id' => $unitSession->id,
            'message' => $message,
            'type' => $type,
            'created' => mysql_datetime(),
            'recipient_id' => $recipient->id
        ]);
    }

    /**
     * Summary of getThrottleMinutes
     * @param string $errorCode
     * @return int
     */
    protected function getThrottleMinutes(string $errorCode): int
    {
        if (isset($this->config['throttle_map'][$errorCode])) {
            return $this->config['throttle_map'][$errorCode];
        }
        return $this->config['default_throttle_minutes'] ?? 0;
    }

    protected function canBeSent(UnitSession $unitSession, string $errorCode): bool
    {
        if (!$unitSession || !$unitSession->runSession || !$unitSession->runSession->getRun()) {
            return false;
        }
        $owner = $unitSession->runSession->getRun()->getOwner();
        if (!$owner) {
            return false;
        }

        $throttleMinutes = $this->getThrottleMinutes($errorCode) ?? 0;

        // Check last notification of this type for this run+recipient
        $stmt = $this->pdo->prepare("
            SELECT created
            FROM survey_notifications
            WHERE run_id = ? AND recipient_id = ? AND type = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$unitSession->runSession->getRun()->id, $owner->id, $errorCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $now = new DateTime();
        $canSend = false;

        if (!$row) {
            $canSend = true;
        } else {
            $lastSent = new DateTime($row['created']);
            $diff = $now->getTimestamp() - $lastSent->getTimestamp();
            if ($diff >= $throttleMinutes * 60) {
                $canSend = true;
            }
        }

        return $canSend;
    }


} 