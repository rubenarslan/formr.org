#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for the inline-send (sendNow) email claim vs the mail
 * daemon's pickup poll (review follow-up to audit F21).
 *
 * The F21 idempotency claim writes a survey_email_log row BEFORE the inline
 * SMTP send. The daemon polls `status = 0 AND account.status = 1` — so the
 * claim must never be inserted at status 0, or the daemon can pick it up
 * during the send window and deliver the same email twice
 * (email.use_queue=false host with formr_mail_daemon running — the docker
 * stack starts the daemon unconditionally).
 *
 * Asserts:
 *  A. an inline claim is INVISIBLE to both daemon poll queries
 *     (account poll + per-account fetch, EmailQueue.php ~74/~95);
 *  B. claim verdicts: fresh row = we own the send; existing SENT or
 *     QUEUED row = handled elsewhere (never send inline); orphaned
 *     SENDING row (crash before Send) = adopt and send.
 *
 * Usage:  docker exec formr_app php bin/test_email_claim_smoke.php
 * Creates a throwaway email account + log rows; cleans up in finally.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

$acc_id = null;
$keyPrefix = 'smoke:' . getmypid() . ':';
try {
    // throwaway ACTIVE email account so the daemon's account poll would see it
    $user_id = (int) $db->execute("SELECT id FROM survey_users ORDER BY id LIMIT 1", array(), true);
    $db->exec(
        "INSERT INTO survey_email_accounts (user_id, created, modified, `from`, from_name, host, port, tls, username, password, auth_key, deleted, status)
         VALUES (:uid, NOW(), NOW(), 'smoke@example.invalid', 'smoke', 'smtp.example.invalid', 587, 1, 'smoke', '', '', 0, 1)",
        ['uid' => $user_id]
    );
    $acc_id = (int) $db->lastInsertId();

    // The daemon's two poll queries (EmailQueue::getEmailAccountsStatement /
    // getEmailsStatement, application/Queue/EmailQueue.php ~74/~95), scoped to
    // the throwaway account. Keep in sync with EmailQueue if the poll changes.
    $accountPoll = function () use ($db, $acc_id) {
        return $db->execute(
            "SELECT account_id FROM survey_email_log
             LEFT JOIN survey_email_accounts ON survey_email_accounts.id = survey_email_log.account_id
             WHERE `survey_email_log`.`status` = 0 AND `survey_email_accounts`.status = 1
               AND account_id = {$acc_id} GROUP BY account_id"
        );
    };
    $emailFetch = function () use ($db, $acc_id) {
        return $db->execute(
            "SELECT id FROM survey_email_log WHERE `survey_email_log`.`status` = 0 AND account_id = {$acc_id}"
        );
    };

    $fields = function (string $key) use ($acc_id) {
        return [
            'subject' => 'smoke', 'session_id' => null, 'email_id' => null,
            'message' => 'smoke', 'recipient' => 'smoke@example.invalid',
            'account_id' => $acc_id, 'idempotency_key' => $key,
        ];
    };

    // ── A: the inline claim must be invisible to the daemon poll ────────────
    echo "== A: inline claim invisible to daemon poll ==\n";
    $key = $keyPrefix . 'a';
    $verdict = EmailQueue::claimSyncSend($fields($key));
    ok($verdict === 'own', "fresh claim → 'own' (caller sends)");
    ok(count($accountPoll()) === 0, "claim does NOT surface the account in the daemon's account poll");
    ok(count($emailFetch()) === 0, "claim is NOT selected by the daemon's per-account fetch");
    $status = (int) $db->findValue('survey_email_log', ['idempotency_key' => $key], 'status');
    ok($status === EmailQueue::STATUS_SENDING, "claim row sits at STATUS_SENDING ({$status})");

    // ── B: duplicate-claim verdicts ─────────────────────────────────────────
    echo "\n== B: duplicate-claim verdicts ==\n";
    // B1: orphaned in-flight claim (crash between claim and Send) → adopt
    ok(EmailQueue::claimSyncSend($fields($key)) === 'own',
        "orphaned STATUS_SENDING claim → 'own' (retry delivers, no silent drop)");
    // B2: already sent → handled (never re-send)
    $db->exec("UPDATE survey_email_log SET status = " . EmailQueue::STATUS_SENT . " WHERE idempotency_key = :k", ['k' => $key]);
    ok(EmailQueue::claimSyncSend($fields($key)) === 'handled', "STATUS_SENT row → 'handled' (no duplicate send)");
    // B3: queued row (daemon owns it, e.g. config flipped queue→sync) → handled
    $keyQ = $keyPrefix . 'q';
    $db->exec(
        "INSERT INTO survey_email_log (`subject`, `status`, `message`, `recipient`, `created`, `account_id`, `idempotency_key`)
         VALUES ('smoke', " . EmailQueue::STATUS_QUEUED . ", 'smoke', 'smoke@example.invalid', NOW(), :acc, :k)",
        ['acc' => $acc_id, 'k' => $keyQ]
    );
    ok(EmailQueue::claimSyncSend($fields($keyQ)) === 'handled', "STATUS_QUEUED row → 'handled' (daemon owns the send)");
    // that genuine queued row IS daemon-visible, proving the poll queries here are live
    ok(count($emailFetch()) === 1, "control: a genuine status-0 queued row IS selected by the daemon fetch");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    $failures++;
} finally {
    $db->exec("DELETE FROM survey_email_log WHERE idempotency_key LIKE :k", ['k' => $keyPrefix . '%']);
    if ($acc_id) {
        $db->exec("DELETE FROM survey_email_accounts WHERE id = :id", ['id' => $acc_id]);
    }
}

echo "\n" . ($failures === 0 ? "\e[32mALL PASSED\e[0m\n" : "\e[31m{$failures} FAILURE(S)\e[0m\n");
exit($failures === 0 ? 0 : 1);
