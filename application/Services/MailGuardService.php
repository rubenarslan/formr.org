<?php

/**
 * Central outbound-mail guard, evaluated in GuardedMailer::send() — the one
 * point every message passes on its way to SMTP — so registration,
 * resend-verification, password reset, cron notices, study mail and anything
 * added later inherit it automatically.
 *
 * Motivation (2026-08 incident): bots submitted the signup and
 * forgot-password forms with strangers' addresses; formr dutifully mailed
 * each one, seconds apart, until the provider disabled SMTP for the whole
 * mailbox (subscription bombing). Platform mail previously had no log, no
 * rate limit and no suppression support at all.
 *
 * Three categories, tagged on the mailer by its factory:
 *   transactional  unauthenticated, attacker-triggerable mail to unverified
 *                  addresses (verification, password reset). Full guard:
 *                  suppression, duplicate window, per-IP / per-subnet /
 *                  global hourly ceilings, domain plausibility.
 *   system         researcher-facing platform mail (cron expiry notices,
 *                  account tests). Suppression + domain check + log only —
 *                  not attacker-triggerable, and a big instance legitimately
 *                  sends many per hour.
 *   study          run emails through researcher-owned accounts. Suppression
 *                  + domain check + log; pacing stays with the existing
 *                  per-recipient RateLimitService (survey_email_log).
 *
 * Every attempted handoff is recorded in survey_mail_guard_log (recipient,
 * type, outcome — never message bodies). Duplicate-window and global-ceiling
 * counts consider only outcome='sent' rows, so a refused attempt can never
 * extend a block; per-IP/subnet limits count all attempts, so hammering a
 * refused endpoint does not become free retries.
 *
 * The guard FAILS OPEN on internal errors (missing table before the 076
 * migration ran, DB hiccup): mail delivery must not break because the guard
 * is sick. Failures are logged loudly instead.
 */
class MailGuardService {

    /**
     * Test seam: callable(string $address): bool replacing
     * EmailDomainService::isDeliverableAddress. Null in production.
     * @var callable|null
     */
    public static $domainChecker = null;

    const CATEGORY_TRANSACTIONAL = 'transactional';
    const CATEGORY_SYSTEM = 'system';
    const CATEGORY_STUDY = 'study';

    /**
     * Types subject to the duplicate-send window: unauthenticated triggers
     * whose content is deterministic per (address, type), so a second copy
     * within the window serves no one but an abuser. Deliberately NOT
     * 'system'/'study' types — three expiry notices for three runs to the
     * same owner in one day are legitimate.
     */
    const DUPLICATE_WINDOW_TYPES = array('verification', 'password_reset');

    const OUTCOME_SENT = 'sent';
    const OUTCOME_ERROR = 'error';
    const OUTCOME_SUPPRESSED = 'refused_suppressed';
    const OUTCOME_DUPLICATE = 'refused_duplicate';
    const OUTCOME_IP_LIMIT = 'refused_ip_limit';
    const OUTCOME_SUBNET_LIMIT = 'refused_subnet_limit';
    const OUTCOME_GLOBAL_LIMIT = 'refused_global_limit';
    const OUTCOME_BAD_DOMAIN = 'refused_bad_domain';

    /** Neutral refusal message; never tells an abuser which limit tripped. */
    const REFUSAL_MESSAGE = 'This email could not be sent right now. Please try again later or contact the administrator of this formr instance.';

    public static function enabled() {
        return (bool) Config::get('mail_guard.enabled', true);
    }

    public static function normalizeAddress($address) {
        return strtolower(trim((string) $address));
    }

    /**
     * Source IP of the current request; null under CLI (cron, queue daemon).
     * env('REMOTE_ADDR') is the app-wide accessor; behind the shipped Traefik
     * stack mod_remoteip has already rewritten it to the real client, and we
     * deliberately do NOT parse X-Forwarded-For here (client-forgeable).
     */
    public static function clientIp() {
        $ip = php_sapi_name() === 'cli' ? null : env('REMOTE_ADDR');
        return ($ip && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : null;
    }

    /** /24 bucket for IPv4, /64 for IPv6 — the practical "one operator" unit. */
    public static function subnetKey($ip) {
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        if (strpos($ip, ':') === false) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    public static function isSuppressed($address) {
        $address = self::normalizeAddress($address);
        if ($address === '') {
            return false;
        }
        return (bool) DB::getInstance()->entry_exists('survey_mail_suppressions', array('address' => $address));
    }

    public static function suppress($address, $reason = null, $addedBy = null) {
        $address = self::normalizeAddress($address);
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (self::isSuppressed($address)) {
            return true;
        }
        return (bool) DB::getInstance()->insert('survey_mail_suppressions', array(
            'address' => $address,
            'reason' => $reason,
            'added_by' => $addedBy,
            'created' => mysql_now(),
        ));
    }

    public static function unsuppress($address) {
        $address = self::normalizeAddress($address);
        return DB::getInstance()->delete('survey_mail_suppressions', array('address' => $address));
    }

    public static function listSuppressions() {
        return DB::getInstance()->execute(
            'SELECT address, reason, added_by, created FROM survey_mail_suppressions ORDER BY created DESC'
        );
    }

    /**
     * Decide whether a message may be handed to the mailer. Pure — records
     * nothing; callers record the resulting outcome via record().
     *
     * @return array{allowed: bool, outcome: string, message: string}
     */
    public static function check($recipient, $type, $category, $ip = null) {
        $allowed = array('allowed' => true, 'outcome' => self::OUTCOME_SENT, 'message' => '');
        if (!self::enabled()) {
            return $allowed;
        }
        try {
            $recipient = self::normalizeAddress($recipient);

            if (self::isSuppressed($recipient)) {
                return self::refusal(self::OUTCOME_SUPPRESSED);
            }

            if ($category === self::CATEGORY_TRANSACTIONAL) {
                if (in_array($type, self::DUPLICATE_WINDOW_TYPES, true)) {
                    $hours = (float) Config::get('mail_guard.duplicate_window_hours', 24);
                    if ($hours > 0 && self::countLog(
                        "recipient = :k AND type = :t AND outcome = 'sent'",
                        array(':k' => $recipient, ':t' => $type), $hours * 3600) > 0) {
                        return self::refusal(self::OUTCOME_DUPLICATE);
                    }
                }
                if ($ip) {
                    $ipLimit = (int) Config::get('mail_guard.ip_limit_per_hour', 10);
                    if ($ipLimit > 0 && self::countLog(
                        "ip = :k AND category = 'transactional'",
                        array(':k' => $ip), 3600) >= $ipLimit) {
                        return self::refusal(self::OUTCOME_IP_LIMIT);
                    }
                    $subnet = self::subnetKey($ip);
                    $subnetLimit = (int) Config::get('mail_guard.subnet_limit_per_hour', 20);
                    if ($subnet && $subnetLimit > 0 && self::countLog(
                        "subnet = :k AND category = 'transactional'",
                        array(':k' => $subnet), 3600) >= $subnetLimit) {
                        return self::refusal(self::OUTCOME_SUBNET_LIMIT);
                    }
                }
                $global = (int) Config::get('mail_guard.global_limit_per_hour', 50);
                if ($global > 0 && self::countLog(
                    "category = 'transactional' AND outcome = 'sent'", array(), 3600) >= $global) {
                    formr_log("MAIL GUARD: global transactional ceiling ({$global}/h) reached; "
                        . "refusing sends. If this is not an attack, raise mail_guard.global_limit_per_hour. "
                        . "See admin/advanced/mail-guard.", 'MailGuard');
                    return self::refusal(self::OUTCOME_GLOBAL_LIMIT);
                }
            }

            if (Config::get('mail_guard.mx_check', true)) {
                $checker = self::$domainChecker ?: array('EmailDomainService', 'isDeliverableAddress');
                if (!call_user_func($checker, $recipient)) {
                    return self::refusal(self::OUTCOME_BAD_DOMAIN);
                }
            }
        } catch (Throwable $e) {
            // Fail open: the guard being sick must not break mail delivery
            // (e.g. code deployed moments before the 076 migration applied).
            formr_log('MAIL GUARD check failed open: ' . $e->getMessage(), 'MailGuard');
        }
        return $allowed;
    }

    /** Record one attempted handoff. Address, type and outcome only — no bodies. */
    public static function record($recipient, $type, $category, $ip, $userId, $outcome) {
        if (!self::enabled()) {
            return;
        }
        try {
            DB::getInstance()->insert('survey_mail_guard_log', array(
                'recipient' => self::normalizeAddress($recipient),
                'type' => (string) $type,
                'category' => (string) $category,
                'user_id' => $userId ? (int) $userId : null,
                'ip' => $ip ?: null,
                'subnet' => self::subnetKey($ip),
                'outcome' => (string) $outcome,
                'created' => mysql_now(),
            ));
        } catch (Throwable $e) {
            formr_log('MAIL GUARD record failed: ' . $e->getMessage(), 'MailGuard');
        }
    }

    /** Per-(category, outcome) counts over the window, for the admin panel. */
    public static function summary($hours = 24) {
        $cutoff = date('Y-m-d H:i:s', time() - (int) ($hours * 3600));
        return DB::getInstance()->execute(
            'SELECT category, outcome, COUNT(*) AS n FROM survey_mail_guard_log '
            . 'WHERE created >= :cutoff GROUP BY category, outcome ORDER BY category, outcome',
            array(':cutoff' => $cutoff)
        );
    }

    /** Did any send hit the global ceiling in the window? (Admin warning.) */
    public static function globalCeilingTripped($hours = 24) {
        try {
            // category narrows to the (category, outcome, created) index; the
            // global ceiling only ever applies to transactional mail anyway.
            return self::countLog(
                "category = 'transactional' AND outcome = :k",
                array(':k' => self::OUTCOME_GLOBAL_LIMIT), $hours * 3600) > 0;
        } catch (Throwable $e) {
            return false; // pre-migration or DB hiccup: don't break the admin UI
        }
    }

    /** Latest non-sent guard-log rows, for the admin panel. */
    public static function recentRefusals($limit = 50) {
        return DB::getInstance()->execute(
            "SELECT recipient, type, category, ip, outcome, created FROM survey_mail_guard_log "
            . "WHERE outcome <> 'sent' ORDER BY id DESC LIMIT " . (int) $limit
        );
    }

    protected static function refusal($outcome) {
        return array('allowed' => false, 'outcome' => $outcome, 'message' => self::REFUSAL_MESSAGE);
    }

    /**
     * Count guard-log rows matching $condition within the last $seconds.
     * The cutoff is computed in PHP so the SQL stays portable between MariaDB
     * and the SQLite test bootstrap (no DATE_SUB).
     */
    protected static function countLog($condition, array $params, $seconds) {
        $params[':cutoff'] = date('Y-m-d H:i:s', time() - (int) $seconds);
        return (int) DB::getInstance()->execute(
            "SELECT COUNT(*) FROM survey_mail_guard_log WHERE {$condition} AND created >= :cutoff",
            $params, true
        );
    }

}
