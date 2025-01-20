<?php

class RunExpiresOnCron extends Cron {
    protected $name = 'Formr.RunExpiresOnCron';
    protected $mailer = null;

    protected function process(): void {
        try {
            $this->processRuns();
        } finally {
            // Clean up SMTP connection
            if ($this->mailer !== null) {
                $this->mailer->getSMTPInstance()->quit(true);
                $this->mailer->getSMTPInstance()->close();
            }
        }
    }

    private function getMailer() {
        if ($this->mailer === null) {
            $this->mailer = $this->site->makeAdminMailer();
            $this->mailer->SMTPKeepAlive = true; // Keep the SMTP connection alive between sends
        }
        // Clear any previous recipients and attachments
        $this->mailer->clearAddresses();
        $this->mailer->clearAttachments();
        $this->mailer->clearAllRecipients();
        
        return $this->mailer;
    }

    /**
     * Process runs that need to be reminded or deleted
     * We loop over the reminder intervals and process the runs that need to be reminded or deleted.
     * Reminders are sent once per interval. 
     * To avoid spamming, we only send a reminder if the run has not received a reminder in the last 6 days.
     * If the run has received 2 reminders and the first reminder was at least two weeks ago, we delete the run data.
     * 
     * The expiry routine is configured in such a way that run data may not be deleted on the day of expiry if the study owner was not given sufficient notice (e.g., because of problems with the email server or because they recently changed their expiry date).
     * 
     * @return void
     */
    private function processRuns() {
        $reminderIntervals = [
            ['type' => 'expired', 'interval' => 'INTERVAL 0 DAY', 'days' => 0],
            ['type' => '1_day', 'interval' => 'INTERVAL 1 DAY', 'days' => 1],
            ['type' => '1_week', 'interval' => 'INTERVAL 1 WEEK', 'days' => 7],
            ['type' => '1_month', 'interval' => 'INTERVAL 1 MONTH', 'days' => 30],
            ['type' => '2_months', 'interval' => 'INTERVAL 2 MONTH', 'days' => 60],
            ['type' => '6_months', 'interval' => 'INTERVAL 6 MONTH', 'days' => 180]
        ];

        try {
            foreach ($reminderIntervals as $interval) {
                // Get all runs that need this reminder and haven't received any reminders in this interval yet
                $query = "SELECT r.name, r.id, r.expiresOn, r.title, u.email, u.first_name, u.last_name,
                           COUNT(er2.id) as reminder_count,  MIN(er2.sent_at) as earliest_reminder,
                           MAX(er2.sent_at) as last_reminder
                    FROM survey_runs r
                    LEFT JOIN survey_users u ON r.user_id = u.id
                    LEFT JOIN survey_run_expiry_reminders er2 ON er2.run_id = r.id
                    WHERE r.expiresOn IS NOT NULL
                        AND r.expiresOn <= DATE_ADD(NOW(), " . $interval['interval'] . ")
                        -- Check if the run has any data
                        AND EXISTS (
                            SELECT 1 
                            FROM survey_run_sessions rs 
                            WHERE rs.run_id = r.id
                        )
                        -- Check if the run has received any reminders in this interval
                        AND NOT EXISTS (
                            SELECT 1 
                            FROM survey_run_expiry_reminders er 
                            WHERE er.run_id = r.id
                            AND er.sent_at >= DATE_SUB(NOW(), " . $interval['interval'] . ")
                        )
                    GROUP BY r.id";

                $stmt = $this->db->prepare($query);
                $stmt->execute();
                $runsNeedingAction = $stmt->fetchAll(PDO::FETCH_ASSOC);


                if(count($runsNeedingAction) > 0) {
                    formr_log("Runs needing action: " . json_encode($runsNeedingAction), 'RUN_INFO');
                }

                foreach ($runsNeedingAction as $run) {
                    # If the run is expired, we need to consider the reminders differently
                    if ($interval['type'] === 'expired') {
                        if(strtotime($run['earliest_reminder']) > strtotime('-2 weeks')) {
                            # If the run has not yet received a reminder in the last 2 weeks, we need to consider sending a reminder
                            $this->considerReminder($run, $interval);
                        } else if($run['reminder_count'] < 2) {
                            # If the run has not yet received 2 reminders, we need to consider sending a reminder
                            $this->considerReminder($run, $interval);
                        } else {
                            # If the run has received 2 reminders and the first reminders was at least two weeks ago, we can delete the run data
                            $this->deleteRunData($run);
                        }
                    } else {
                        $this->considerReminder($run, $interval);
                    }
                }
            }
        } catch (Exception $e) {
            formr_log("Error processing runs: " . $e->getMessage(), 'CRON_ERROR');
        }
    }

    private function considerReminder($run, $interval) {
        # If there's no last reminder or the last reminder was more than 6 days ago, send a reminder
        if (!$run['last_reminder'] || strtotime($run['last_reminder']) < strtotime('-6 days')) {
            return $this->sendReminder($run, $interval);
        } else {
            return false;
        }
    }

    private function sendReminder($run, $interval) {
        $expiryDate = new DateTime($run['expiresOn']);
        $now = new DateTime();
        $timeInterval = $now->diff($expiryDate);
        
        $timeUntilExpiry = $this->formatTimeUntilExpiry($timeInterval);

        if(!empty($run['first_name'])) {
            $userName = $run['first_name'] . ' ' . $run['last_name'];
        } else {
            $userName = $run['email'];
        }

        $success = $this->sendReminderEmail(
            $run['email'],
            $run['name'],
            $run['title'],
            $userName,
            $run['expiresOn'],
            $timeUntilExpiry,
            admin_url()
        );

        if ($success) {
            $this->db->insert('survey_run_expiry_reminders', [
                'run_id' => $run['id'],
                'reminder_type' => $interval['type'],
                'sent_at' => date('Y-m-d H:i:s')
            ]);
        }
        return true;
    }

    private function deleteRunData($run) {
        try {
            $this->db->beginTransaction();
            $runObj = new Run($run['name']);
            $email = $runObj->getOwner()->getEmail();
            $runObj->emptySelf();
            formr_log("Deleted All Data in Run {$runObj->name} due to expiration", 'RUN_DELETE');
            $this->sendDeleteNotification($runObj, $email);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            formr_log("Error deleting expired run: " . $e->getMessage(), 'CRON_ERROR');
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

    private function sendReminderEmail(string $email, string $runName, string $title, string $userName, string $expiryDate, string $timeUntilExpiry, string $siteUrl): bool {
        $mail = $this->getMailer();
        $mail->AddAddress($email);
        $mail->Subject = "formr: Reminder! Run {$runName} will be deleted!";
        $mail->Body = Template::get_replace('email/auto-delete-reminder.ftpl', array(
            'user' => $userName,
            'title' => $title,
            'expiryDate' => $expiryDate,
            'timeUntilExpiry' => $timeUntilExpiry,
            'site_url' => $siteUrl
        ));

        if (!$mail->Send()) {
            formr_log("Error sending reminder email for run {$runName}: " . $mail->ErrorInfo, 'MAIL_ERROR');
            return false;
        }

        formr_log("A Reminder for the Run {$runName} was sent to {$email}", 'MAIL_INFO');
        return true;
    }

    private function sendDeleteNotification(Run $run, string $email) {
        $mail = $this->getMailer();
        $mail->AddAddress($email);
        $mail->Subject = "formr: Run {$run->name} automatically deleted";
        $mail->Body = Template::get_replace('email/auto-delete-notification.ftpl', array(
            'user' => $run->getOwner()->user_code,
            'title' => $run->title,
            'expiryDate' => $run->expiresOn
        ));
        if (!$mail->Send()) {
            formr_log("Error: ". $mail->ErrorInfo, 'MAIL_ERROR');
        } else {
            $this->db->insert('survey_run_expiry_reminders', [
                'run_id' => $run->id,
                'reminder_type' => 'deletion',
                'sent_at' => date('Y-m-d H:i:s')
            ]);
            formr_log("The Delete-Notification for the Run {$run->name} was sent to {$email}", 'MAIL_INFO');
        }
    }
}