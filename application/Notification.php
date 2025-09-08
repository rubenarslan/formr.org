<?php

class Notification {
    
    /**
     * Notify the study administrator about an issue
     * 
     * @param RunSession $runSession The run session where the issue occurred
     * @param string $subject The notification subject
     * @param string $message The notification message
     * @param string $type The type of notification (error, warning, info)
     * @return bool Whether the notification was sent successfully
     */
    public static function notifyStudyAdmin(RunSession $runSession, string $subject, string $message, string $type = 'error'): bool {
        if (!$runSession || !$runSession->getRun()) {
            return false;
        }
        
        // Get the study owner
        $studyOwner = $runSession->getRun()->getOwner();
        if (!$studyOwner) {
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
        
        // Format message based on type
        $typeClass = $type === 'error' ? 'danger' : ($type === 'warning' ? 'warning' : 'info');
        $formattedMessage = "
            <div style='font-family: Arial, sans-serif; padding: 20px;'>
                <h2 style='color: #333;'>Study Notification</h2>
                <div style='background-color: #f8f9fa; border-left: 4px solid #{$typeClass}; padding: 15px; margin: 10px 0;'>
                    <p style='margin: 0;'>{$message}</p>
                </div>
                <div style='margin-top: 20px; font-size: 12px; color: #666;'>
                    <p>Study: {$runSession->getRun()->name}</p>
                    <p>Session: {$runSession->session}</p>
                    <p>Time: " . date('Y-m-d H:i:s') . "</p>
                </div>
            </div>
        ";
        
        $mailer->Body = $formattedMessage;
        
        // Send the notification
        if ($mailer->Send()) {
            // Log the notification
            self::logNotification($runSession, $subject, $message, $type, $studyOwner);
            return true;
        }
        
        return false;
    }
    
    /**
     * Log the notification in the database
     */
    protected static function logNotification(RunSession $runSession, string $subject, string $message, string $type, User $recipient): void {
        DB::getInstance()->insert('survey_notifications', [
            'run_id' => $runSession->getRun()->id,
            'session_id' => $runSession->id,
            'message' => $message,
            'type' => $type,
            'created' => mysql_datetime(),
            'recipient_id' => $recipient->id
        ]);
    }
} 