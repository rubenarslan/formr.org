<?php

/**
 * Issue #608: enforce per-user monthly compute limits.
 *
 * Effective limit for a study admin (run owner) = survey_users.compute_limit_monthly
 * when set, else the instance-wide `compute_limit_monthly_default` config. In both,
 * 0 = unlimited; >0 = a budget in seconds. Usage = SUM(execution_time) over all the
 * user's unit sessions in the current calendar month.
 *
 * Over limit -> for every still-ACTIVE run of that user (public > 0, or non-public
 *               but with automatic actions still enabled, cron_active = 1), set
 *               public = 0 AND cron_active = 0 (stop the run daemon from executing
 *               its unit sessions — the source of the compute we're throttling),
 *               remember the prior public/cron state in compute_closed_from /
 *               compute_closed_cron_active, and email them once. A non-public run
 *               whose daemon is still running keeps spending compute, so it is
 *               closed too (the daemon pickup never checks `public`).
 *
 * Compute-closed runs are NEVER auto-reopened (hard stop, not a bouncing gate;
 * ported from form_v2 65a80b44). Crossing the limit is a stop the owner must clear
 * deliberately: republish the study and re-enable cron in the run settings once
 * usage is addressed (or a higher limit is arranged). The compute_closed_* columns
 * are retained purely as an audit trail of the state the run had when auto-closed.
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

                // Over budget with runs still active -> close them. Under budget is a
                // no-op: compute-closed runs stay closed until the owner reopens them.
                if ($limit > 0 && $used >= $limit && (int) $row['active_runs'] > 0) {
                    $this->closeUserRuns($row, $used, $limit);
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
     * Owners who could be closed this run: when the global default is finite,
     * every run owner; otherwise only those with a per-user override. (Under-limit
     * users are a no-op — nothing is reopened — so the previous "has a
     * compute-closed run" candidacy clause is gone.)
     */
    private function candidateUsers(bool $defaultFinite, string $monthStart): array {
        // The per-run month aggregate drives from us.created (indexed) so the
        // hourly cron scans this month's unit sessions, not the full history;
        // the run counter then joins users×runs only (no session fan-out).
        $stmt = $this->db->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name, u.compute_limit_monthly,
                   COALESCE(SUM(m.month_used), 0) AS month_used,
                   COUNT(DISTINCT CASE WHEN r.public > 0 OR r.cron_active = 1
                                       THEN r.id END) AS active_runs
            FROM survey_users u
            JOIN survey_runs r ON r.user_id = u.id
            LEFT JOIN (
                SELECT rs.run_id, SUM(us.execution_time) AS month_used
                FROM survey_unit_sessions us
                JOIN survey_run_sessions rs ON rs.id = us.run_session_id
                WHERE us.created >= :month_start AND us.execution_time IS NOT NULL
                GROUP BY rs.run_id
            ) m ON m.run_id = r.id
            WHERE :default_finite = 1
               OR u.compute_limit_monthly IS NOT NULL
            GROUP BY u.id, u.email, u.first_name, u.last_name, u.compute_limit_monthly
        ");
        $stmt->execute([
            ':month_start' => $monthStart,
            ':default_finite' => $defaultFinite ? 1 : 0,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function closeUserRuns(array $row, float $used, float $limit): void {
        // "Active" = still accepting participants (public > 0) OR still running the
        // daemon (cron_active = 1); a non-public run whose cron is on keeps spending
        // compute, so it is closed too. Capture names BEFORE the update for the email.
        $activeWhere = "user_id = :uid AND (public > 0 OR cron_active = 1)";
        $names = $this->runNames("SELECT name FROM survey_runs WHERE {$activeWhere}", $row['id']);
        // Record each run's prior public/cron state, then close it AND disable its
        // automatic actions so the run daemon stops executing its unit sessions —
        // where the compute is spent. Left-to-right SET assignment reads the prior
        // values before they are zeroed; the COALESCEs keep the ORIGINAL audit
        // markers if a partially-reopened run (owner re-enabled only cron) is
        // re-closed. Fully-closed runs don't match, so they are never re-stamped.
        // Not auto-reopened: the owner must republish (and re-enable cron) by hand.
        $affected = $this->db->exec(
            "UPDATE survey_runs
                SET compute_closed_from = COALESCE(compute_closed_from, public),
                    compute_closed_cron_active = COALESCE(compute_closed_cron_active, cron_active),
                    public = 0, cron_active = 0
             WHERE {$activeWhere}",
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
