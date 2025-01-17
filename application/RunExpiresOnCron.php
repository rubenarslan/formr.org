<?php

class RunExpiresOnCron extends Cron {
    protected $name = 'Formr.RunExpiresOnCron';

    protected function process(): void {
        $this->processReminders();
        $this->processExpiredRuns();
    }

    private function processReminders() {
        $reminderIntervals = [
            '6_months' => 'INTERVAL 6 MONTH',
            '2_months' => 'INTERVAL 2 MONTH',
            '1_month' => 'INTERVAL 1 MONTH',
            '1_week' => 'INTERVAL 1 WEEK',
            '1_day' => 'INTERVAL 1 DAY'
        ];

        try {
            foreach ($reminderIntervals as $type => $interval) {
                // Get all runs that need this reminder and haven't received it yet
                $query = "
                    SELECT r.name, r.id, r.expiresOn, r.title, u.email, u.first_name, u.last_name, u.user_code
                    FROM survey_runs r
                    JOIN survey_users u ON r.user_id = u.id
                    LEFT JOIN survey_run_expiry_reminders er 
                        ON er.run_id = r.id AND er.reminder_type = :reminder_type
                    WHERE r.expiresOn IS NOT NULL
                        AND r.expiresOn > NOW()
                        AND r.expiresOn <= NOW() + {$interval}
                        AND er.id IS NULL
                    FOR UPDATE";  // Lock these rows to prevent race conditions
                
                $this->db->beginTransaction();
                try {
                    $stmt = $this->db->prepare($query);
                    $stmt->execute(['reminder_type' => $type]);
                    $runsNeedingReminder = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($runsNeedingReminder as $run) {
                        // Calculate time until expiry
                        $expiryDate = new DateTime($run['expiresOn']);
                        $now = new DateTime();
                        $interval = $now->diff($expiryDate);
                        
                        $timeUntilExpiry = $this->formatTimeUntilExpiry($interval);

                        // Send reminder
                        $success = $this->sendReminderEmail(
                            $run['email'],
                            $run['name'],
                            $run['title'],
                            $run['first_name'] . ' ' . $run['last_name'],
                            $run['expiresOn'],
                            $timeUntilExpiry
                        );

                        if ($success) {
                            // Record the reminder
                            $this->db->insert('survey_run_expiry_reminders', [
                                'run_id' => $run['id'],
                                'reminder_type' => $type,
                                'sent_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                    $this->db->commit();
                } catch (Exception $e) {
                    $this->db->rollBack();
                    formr_log("Error processing reminders for interval {$type}: " . $e->getMessage(), 'CRON_ERROR');
                }
            }

            // Check for any runs that might have missed reminders due to expiry date changes
            $this->processMissedReminders();

        } catch (Exception $e) {
            formr_log("Fatal error in processReminders: " . $e->getMessage(), 'CRON_ERROR');
        }
    }

    private function processMissedReminders() {
        $intervals = [
            ['type' => '1_day', 'days' => 1],
            ['type' => '1_week', 'days' => 7],
            ['type' => '1_month', 'days' => 30],
            ['type' => '2_months', 'days' => 60],
            ['type' => '6_months', 'days' => 180]
        ];

        foreach ($intervals as $interval) {
            $query = "
                SELECT r.name, r.id, r.expiresOn, r.title, u.email, u.first_name, u.last_name, u.user_code
                FROM survey_runs r
                JOIN survey_users u ON r.user_id = u.id
                LEFT JOIN survey_run_expiry_reminders er 
                    ON er.run_id = r.id AND er.reminder_type = :reminder_type
                WHERE r.expiresOn IS NOT NULL
                    AND r.expiresOn > NOW()
                    AND r.expiresOn <= DATE_ADD(NOW(), INTERVAL :days DAY)
                    AND er.id IS NULL
                FOR UPDATE";

            $this->db->beginTransaction();
            try {
                $stmt = $this->db->prepare($query);
                $stmt->execute([
                    'reminder_type' => $interval['type'],
                    'days' => $interval['days']
                ]);
                $missedRuns = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($missedRuns as $run) {
                    $expiryDate = new DateTime($run['expiresOn']);
                    $now = new DateTime();
                    $timeInterval = $now->diff($expiryDate);
                    
                    // Only send if this reminder should have already been sent
                    if ($timeInterval->days <= $interval['days']) {
                        $timeUntilExpiry = $this->formatTimeUntilExpiry($timeInterval);

                        $success = $this->sendReminderEmail(
                            $run['email'],
                            $run['name'],
                            $run['title'],
                            $run['first_name'] . ' ' . $run['last_name'],
                            $run['expiresOn'],
                            $timeUntilExpiry
                        );

                        if ($success) {
                            $this->db->insert('survey_run_expiry_reminders', [
                                'run_id' => $run['id'],
                                'reminder_type' => $interval['type'],
                                'sent_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                }
                $this->db->commit();
            } catch (Exception $e) {
                $this->db->rollBack();
                formr_log("Error processing missed reminders for interval {$interval['type']}: " . $e->getMessage(), 'CRON_ERROR');
            }
        }
    }

    private function formatTimeUntilExpiry(DateInterval $interval): string {
        if ($interval->y > 0) {
            return "in {$interval->y} year(s)";
        } elseif ($interval->m > 0) {
            return "in {$interval->m} month(s)";
        } elseif ($interval->d > 0) {
            return "in {$interval->d} day(s)";
        }
        return "today";
    }

    private function sendReminderEmail(string $email, string $runName, string $title, string $userName, string $expiryDate, string $timeUntilExpiry): bool {
        $mail = $this->site->makeAdminMailer();
        $mail->AddAddress($email);
        $mail->Subject = "formr: Reminder! Run {$runName} will be deleted!";
        $mail->Body = Template::get_replace('email/auto-delete-reminder.ftpl', array(
            'user' => $userName,
            'title' => $title,
            'expiryDate' => $expiryDate,
            'timeUntilExpiry' => $timeUntilExpiry
        ));

        if (!$mail->Send()) {
            formr_log("Error sending reminder email for run {$runName}: " . $mail->ErrorInfo, 'MAIL_ERROR');
            return false;
        }

        formr_log("A Reminder for the Run {$runName} was sent to {$email}", 'MAIL_INFO');
        return true;
    }

    private function processExpiredRuns() {
        try {
            // Only get expired runs that have received at least 2 reminders and still have sessions
            $query = "
                SELECT r.name, COUNT(er.id) as reminder_count
                FROM survey_runs r
                JOIN survey_run_expiry_reminders er ON er.run_id = r.id
                WHERE r.expiresOn < NOW()
                AND EXISTS (
                    SELECT 1 
                    FROM survey_run_sessions rs 
                    WHERE rs.run_id = r.id
                )
                GROUP BY r.id
                HAVING reminder_count >= 2
                FOR UPDATE";

            $this->db->beginTransaction();
            try {
                $expiredRuns = $this->db->query($query);
                $this->deleteRuns($expiredRuns);
                $this->db->commit();
            } catch (Exception $e) {
                $this->db->rollBack();
                formr_log("Error processing expired runs: " . $e->getMessage(), 'CRON_ERROR');
            }
        } catch (Exception $e) {
            formr_log("Fatal error in processExpiredRuns: " . $e->getMessage(), 'CRON_ERROR');
        }
    }

    private function sendDeleteNotification(Run $run, string $email){
        $mail = $this->site->makeAdminMailer();
        $mail->AddAddress($email);
        $mail->Subject = "formr: Run {$run->name} automatically deleted";
        $mail->Body = Template::get_replace('email/auto-delete-notification.ftpl', array(
            'user' => $run->getOwner()->user_code,
            'title' => $run->title,
            'expiryDate' => $run->expiresOn,
        ));
        if (!$mail->Send()) {
            formr_log("Error: ". $mail->ErrorInfo, 'MAIL_ERROR');
        }else{
            formr_log("The Delete-Notification for the Run {$run->name} was sent to {$email}", 'MAIL_INFO');
        }
    }

    private function deleteRuns($namesOfRuns): void {
        foreach ($namesOfRuns as $runName) {
            $run = new Run($runName['name']);
            $email = $run->getOwner()->getEmail();
            $run->emptySelf();
            formr_log("Deleted All Data in Run {$run->name} due to expiration", 'RUN_DELETE');
            $this->sendDeleteNotification($run, $email);
        }
    }
}