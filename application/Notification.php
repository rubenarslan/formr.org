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

        // Issue #608: don't email study admins about errors in test sessions
        // WHILE THEY ARE LOOKING AT THEM. Admins routinely break R code while
        // building a run interactively (previewing, the survey-test harness) —
        // there the on-screen alert already tells them, and email would bury
        // real participant-facing errors in noise. But in console/cron context
        // (review 2026-07, item 11) email is the ONLY channel: a researcher
        // piloting their run as a testing session before launch must hear
        // about the daemon hitting their broken relative_to at night, or the
        // study launches with the fault undiscovered. Muted by design, not
        // throttled — so no suppression row: recording every preview error
        // would grow survey_notifications with rows nobody was owed.
        if ($unitSession->runSession->isTesting() && !formr_in_console()) {
            return false;
        }

        if (!$this->canBeSent($unitSession, $type)) {
            // Record the suppression: after the fact, silence must be
            // distinguishable from "no errors" (X4 in LOG_STATE_AUDIT.md).
            $this->logNotification($unitSession, $message, $type, $studyOwner, true);
            return false;
        }

        // Fold what the throttle swallowed since the last delivered email
        // about this unit into the one that does go out.
        $suppressedCount = $this->countSuppressedSinceLastSent($unitSession, $type, $studyOwner);
        if ($suppressedCount > 0) {
            $message .= "\n\n(" . $suppressedCount . ' similar notification' . ($suppressedCount === 1 ? '' : 's')
                . ' about this unit ' . ($suppressedCount === 1 ? 'was' : 'were')
                . ' suppressed by throttling since the last email.)';
        }

        if ($this->deliver($studyOwner, $unitSession, $message, $type)) {
            $this->logNotification($unitSession, $message, $type, $studyOwner);
            return true;
        }

        return false;
    }

    /**
     * Actually send the notification email. Split out of notifyStudyAdmin
     * so the throttle/bookkeeping policy is testable without SMTP.
     */
    protected function deliver(User $studyOwner, UnitSession $unitSession, string $message, string $type): bool {
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
        // Admin-error notification to the study owner's own address — not
        // participant mail, so 'system' semantics in the guard log.
        $mailer->guard_category = MailGuardService::CATEGORY_SYSTEM;
        $mailer->guard_type = 'notification';

        // Prepare email
        $mailer->IsHTML(true);
        $mailer->AddAddress($studyOwner->email);
        $mailer->Subject = "[formr] Run Notification";

        // Map type to a CSS hex color for the email border
        $typeColor = $type === 'error' ? '#dc3545' : ($type === 'warning' ? '#ffc107' : '#17a2b8');
        $formattedMessage = Template::get_replace('email/notification.ftpl', [
            'typeColor' => $typeColor,
            'message' => $message,
            'instance' => formr_instance_label(),
            'run_name' => $unitSession->runSession->getRun()->name,
            'run_unit' => $unitSession->runUnit->position . '. ' . $unitSession->runUnit->type,
            'session' => $unitSession->runSession->session,
            'date' => date('Y-m-d H:i:s')
        ]);

        $mailer->Body = $formattedMessage;

        return (bool) $mailer->Send();
    }

    /**
     * One throttle bucket per unit and notification type (X4). Keyed by
     * survey_units.id, so a repeat of the SAME unit's problem is throttled
     * while a different unit's problem still gets its own email.
     */
    protected function fingerprint(UnitSession $unitSession, string $errorCode): string {
        $unitId = $unitSession->runUnit->id ?? 0;
        return $errorCode . ':u' . (int) $unitId;
    }

    /**
     * How many notifications with this fingerprint were throttled away
     * since the last one that was actually delivered.
     */
    protected function countSuppressedSinceLastSent(UnitSession $unitSession, string $errorCode, User $recipient): int {
        $runId = $unitSession->runSession->getRun()->id;
        $fingerprint = $this->fingerprint($unitSession, $errorCode);
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM survey_notifications
            WHERE run_id = ? AND recipient_id = ? AND fingerprint = ? AND suppressed = 1
              AND id > COALESCE((
                SELECT MAX(id) FROM survey_notifications
                WHERE run_id = ? AND recipient_id = ? AND fingerprint = ? AND suppressed = 0
              ), 0)
        ");
        $stmt->execute([$runId, $recipient->id, $fingerprint, $runId, $recipient->id, $fingerprint]);
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * Log the notification in the database
     * 
     * @param UnitSession $unitSession The run session where the issue occurred
     * @param string $message The notification message
     * @param string $type The type of notification (error, warning, info)
     * @param User $recipient The recipient of the notification
     * @param bool $suppressed True when the throttle swallowed it — recorded
     *                         for the digest and post-hoc diagnosis, but it
     *                         does not restart the throttle window
     */
    protected function logNotification(UnitSession $unitSession, string $message, string $type, User $recipient, bool $suppressed = false): void {
        DB::getInstance()->insert('survey_notifications', [
            'run_id' => $unitSession->runSession->getRun()->id,
            'session_id' => $unitSession->id,
            'message' => $message,
            'type' => $type,
            'created' => mysql_datetime(),
            'recipient_id' => $recipient->id,
            'fingerprint' => $this->fingerprint($unitSession, $type),
            'suppressed' => (int) $suppressed
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
        // The interactive-testing mute lives in notifyStudyAdmin (a muted
        // notification is by design and leaves no suppression row); this
        // method answers only the throttle question.
        $owner = $unitSession->runSession->getRun()->getOwner();
        if (!$owner) {
            return false;
        }

        $throttleMinutes = $this->getThrottleMinutes($errorCode) ?? 0;

        // Check the last DELIVERED notification for this unit (fingerprint =
        // type + unit id). Keying on run + type alone gave the whole run one
        // email slot per window, silencing distinct problems (X4). Suppressed
        // rows don't count — they must not extend the window. Legacy rows
        // (fingerprint NULL) aren't consulted: at most one extra email per
        // unit in the first window after upgrading.
        $stmt = $this->pdo->prepare("
            SELECT created
            FROM survey_notifications
            WHERE run_id = ? AND recipient_id = ? AND fingerprint = ? AND suppressed = 0
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$unitSession->runSession->getRun()->id, $owner->id, $this->fingerprint($unitSession, $errorCode)]);
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