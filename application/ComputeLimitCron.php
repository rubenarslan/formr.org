<?php

/**
 * Issue #608: enforce per-user monthly compute limits.
 *
 * Effective limit for a study admin (run owner) = survey_users.compute_limit_monthly
 * when set, else the instance-wide `compute_limit_monthly_default` config. In both,
 * 0 = unlimited; >0 = a budget in seconds. Usage = SUM(execution_time) over all the
 * user's unit sessions in the current calendar month.
 *
 * Over limit  -> set every still-public run of that user to public=0, remembering the
 *                prior level in survey_runs.compute_closed_from, and email them once.
 * Under limit -> restore any run we previously compute-closed (public = compute_closed_from)
 *                and email them once. This is what reopens runs when the month rolls over.
 *
 * Runs the owner closed by hand (compute_closed_from IS NULL) are never touched.
 */
class ComputeLimitCron extends Cron {
    protected $name = 'Formr.ComputeLimitCron';
    protected $mailer = null;

    protected function process(): void {
        try {
            $default = (float) Config::get('compute_limit_monthly_default', 0);
            $monthStart = date('Y-m-01 00:00:00');

            foreach ($this->candidateUsers($default > 0, $monthStart) as $row) {
                $override = $row['compute_limit_monthly'];
                $limit = ($override !== null) ? (float) $override : $default;
                $used = (float) $row['month_used'];

                if ($limit > 0 && $used >= $limit) {
                    if ((int) $row['open_runs'] > 0) {
                        $this->closeUserRuns($row, $used, $limit);
                    }
                } elseif ((int) $row['closed_runs'] > 0) {
                    $this->reopenUserRuns($row);
                }
            }
        } finally {
            if ($this->mailer !== null) {
                $this->mailer->getSMTPInstance()->quit(true);
                $this->mailer->getSMTPInstance()->close();
            }
        }
    }

    /**
     * Owners who could be affected this run: when the global default is finite,
     * every run owner; otherwise only those with a per-user override or a run we
     * previously compute-closed (so a removed/raised override still reopens).
     */
    private function candidateUsers(bool $defaultFinite, string $monthStart): array {
        $stmt = $this->db->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name, u.compute_limit_monthly,
                   COALESCE(SUM(CASE WHEN us.created >= :month_start
                                     THEN us.execution_time END), 0) AS month_used,
                   COUNT(DISTINCT CASE WHEN r.public > 0 THEN r.id END) AS open_runs,
                   COUNT(DISTINCT CASE WHEN r.compute_closed_from IS NOT NULL
                                       THEN r.id END) AS closed_runs
            FROM survey_users u
            JOIN survey_runs r ON r.user_id = u.id
            LEFT JOIN survey_run_sessions rs ON rs.run_id = r.id
            LEFT JOIN survey_unit_sessions us ON us.run_session_id = rs.id
            WHERE :default_finite = 1
               OR u.compute_limit_monthly IS NOT NULL
               OR EXISTS (SELECT 1 FROM survey_runs rc
                          WHERE rc.user_id = u.id AND rc.compute_closed_from IS NOT NULL)
            GROUP BY u.id, u.email, u.first_name, u.last_name, u.compute_limit_monthly
        ");
        $stmt->execute([
            ':month_start' => $monthStart,
            ':default_finite' => $defaultFinite ? 1 : 0,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function closeUserRuns(array $row, float $used, float $limit): void {
        // Capture the names BEFORE the update so the email can name the studies.
        $names = $this->runNames("SELECT name FROM survey_runs WHERE user_id = :uid AND public > 0", $row['id']);
        // Record each open run's level, then close it. Left-to-right assignment
        // means public is read into compute_closed_from before it is zeroed.
        $affected = $this->db->exec(
            "UPDATE survey_runs SET compute_closed_from = public, public = 0
             WHERE user_id = :uid AND public > 0",
            ['uid' => $row['id']]
        );
        if (!$affected) {
            return;
        }
        formr_log("ComputeLimitCron: closed {$affected} run(s) for user {$row['id']} "
            . "({$row['email']}); used " . round($used) . "s of " . round($limit) . "s", 'CRON_INFO');
        $this->notify($row, 'email/compute-limit-reached.ftpl',
            'formr: studies paused — monthly compute limit reached', $used, $limit, $names);
    }

    private function reopenUserRuns(array $row): void {
        $names = $this->runNames("SELECT name FROM survey_runs WHERE user_id = :uid AND compute_closed_from IS NOT NULL", $row['id']);
        $affected = $this->db->exec(
            "UPDATE survey_runs SET public = compute_closed_from, compute_closed_from = NULL
             WHERE user_id = :uid AND compute_closed_from IS NOT NULL",
            ['uid' => $row['id']]
        );
        if (!$affected) {
            return;
        }
        formr_log("ComputeLimitCron: reopened {$affected} run(s) for user {$row['id']} "
            . "({$row['email']}) — back under monthly compute limit", 'CRON_INFO');
        $this->notify($row, 'email/compute-limit-restored.ftpl',
            'formr: studies reopened — compute usage back under limit', 0, 0, $names);
    }

    /** Names of the runs matched by $query (bound :uid), for the notification email. */
    private function runNames(string $query, $uid): array {
        $stmt = $this->db->prepare($query);
        $stmt->execute([':uid' => $uid]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    private function getMailer() {
        if ($this->mailer === null) {
            $this->mailer = $this->site->makeAdminMailer();
            $this->mailer->SMTPKeepAlive = true;
        }
        $this->mailer->clearAddresses();
        $this->mailer->clearAttachments();
        $this->mailer->clearAllRecipients();
        return $this->mailer;
    }

    private function notify(array $row, string $template, string $subject, float $used, float $limit, array $names = []): void {
        try {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $runs = $names ? implode("\n", array_map(function ($n) { return '  - ' . $n; }, $names)) : '  (none)';
            $mail = $this->getMailer();
            $mail->AddAddress($row['email']);
            $mail->Subject = $subject;
            $mail->Body = Template::get_replace($template, [
                'user' => $name !== '' ? $name : $row['email'],
                'used' => ComputeUsageHelper::formatDuration($used),
                'limit' => ComputeUsageHelper::formatDuration($limit),
                'month' => date('F Y'),
                'runs' => $runs,
                'instance' => formr_instance_label(),
                'time' => date('Y-m-d H:i:s'),
                'compute_url' => formr_admin_base_url() . '/admin/compute',
            ]);
            if (!$mail->Send()) {
                formr_log("ComputeLimitCron: email to {$row['email']} failed: " . $mail->ErrorInfo, 'MAIL_ERROR');
            }
        } catch (Exception $e) {
            formr_log_exception($e, 'ComputeLimitCron.notify');
        }
    }
}
