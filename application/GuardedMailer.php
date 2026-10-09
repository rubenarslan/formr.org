<?php

use PHPMailer\PHPMailer\PHPMailer;

/**
 * The one place a message is handed to the mailer. All three mailer
 * factories (Site::makeAdminMailer, EmailAccount::makeMailer,
 * EmailQueue::getSMTPConnection) construct this subclass instead of a bare
 * PHPMailer, so every send() — including legacy ->Send() call sites, PHP
 * method names being case-insensitive — passes through MailGuardService
 * before anything leaves the process, and every attempt is recorded in
 * survey_mail_guard_log. Mail added later inherits the guard by using the
 * factories.
 *
 * Factories/callers tag the message via the guard_* properties. The default
 * is the strictest category (transactional), so an untagged new mail type is
 * over-guarded rather than unguarded.
 */
class GuardedMailer extends PHPMailer {

    /** Message type, e.g. 'verification', 'password_reset', 'cron', 'study'. */
    public $guard_type = 'other';

    /** MailGuardService::CATEGORY_* — decides which checks apply. */
    public $guard_category = MailGuardService::CATEGORY_TRANSACTIONAL;

    /** Account id of the user this mail concerns, when known. */
    public $guard_user_id = null;

    /** Signup bearing a valid referral/invite token — relaxes per-source limits. */
    public $guard_trusted = false;

    /** True when the last send() was refused by the guard (never attempted). */
    public $guard_blocked = false;

    /** MailGuardService outcome of the last send() attempt. */
    public $guard_outcome = null;

    public function send() {
        $this->guard_blocked = false;
        $this->guard_outcome = null;

        $recipients = $this->guardRecipients();
        $ip = MailGuardService::clientIp();

        foreach ($recipients as $address) {
            $verdict = MailGuardService::check($address, $this->guard_type, $this->guard_category, $ip, $this->guard_trusted);
            if (!$verdict['allowed']) {
                MailGuardService::record($address, $this->guard_type, $this->guard_category, $ip, $this->guard_user_id, $verdict['outcome']);
                $this->guard_blocked = true;
                $this->guard_outcome = $verdict['outcome'];
                $this->setError($verdict['message']);
                formr_log("MAIL GUARD refused {$this->guard_type} to {$address}: {$verdict['outcome']}", 'MailGuard');
                return false;
            }
        }

        $sent = parent::send();

        $outcome = $sent ? MailGuardService::OUTCOME_SENT : MailGuardService::OUTCOME_ERROR;
        $this->guard_outcome = $outcome;
        foreach ($recipients as $address) {
            MailGuardService::record($address, $this->guard_type, $this->guard_category, $ip, $this->guard_user_id, $outcome);
        }
        return $sent;
    }

    /**
     * To-addresses of the pending message: the resolved list plus any still
     * queued for IDN conversion (PHPMailer queues those until send()).
     */
    protected function guardRecipients() {
        $out = array();
        foreach ($this->to as $recipient) {
            $out[] = $recipient[0];
        }
        foreach ($this->RecipientsQueue as $params) {
            if (isset($params[0], $params[1]) && $params[0] === 'to') {
                $out[] = $params[1];
            }
        }
        return array_values(array_unique(array_filter($out)));
    }

}
