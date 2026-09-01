<?php

/**
 * Bot friction + gating for the registration form (and the form-token part
 * for forgot-password). Two invisible layers on by default — a honeypot
 * field and a signed server-issued timestamp (no third party, no user-facing
 * cost) — plus an optional pluggable proof-of-work/captcha challenge
 * (registration.challenge = 'altcha' | 'turnstile', off by default), plus
 * the registration.mode gate (open | domains | invite | closed).
 *
 * The HMAC secret is derived from the instance's at-rest crypto key, so no
 * new secret needs provisioning; override via mail_guard.form_secret. If no
 * secret can be derived the token checks fail OPEN (nobody gets locked out
 * by a misconfiguration) — the honeypot still applies.
 */
class RegistrationGuard {

    /** Invisible field bots autofill. Name chosen to look attractive to them. */
    const HONEYPOT_FIELD = 'website';

    /** Hidden field carrying the signed mint-timestamp token. */
    const TOKEN_FIELD = '_form_minted';

    public static function secret() {
        $configured = Config::get('mail_guard.form_secret');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $keyFile = Config::get('encryption_key_file');
        if (!$keyFile && defined('APPLICATION_CRYPTO_KEY_FILE')) {
            $keyFile = APPLICATION_CRYPTO_KEY_FILE;
        }
        if ($keyFile && is_readable($keyFile)) {
            return hash('sha256', 'formr-registration-guard|' . file_get_contents($keyFile));
        }
        return null;
    }

    /** Signed "this form was rendered at $time" token: time.nonce.hmac. */
    public static function mintFormToken() {
        $secret = self::secret();
        if ($secret === null) {
            return '';
        }
        $payload = time() . '.' . bin2hex(random_bytes(8));
        return $payload . '.' . hash_hmac('sha256', $payload, $secret);
    }

    /** @return array{ok: bool, age: int} age = seconds since mint (only if ok) */
    public static function parseFormToken($token) {
        $secret = self::secret();
        if ($secret === null) {
            return array('ok' => true, 'age' => PHP_INT_MAX); // can't sign -> can't check
        }
        $parts = explode('.', (string) $token);
        if (count($parts) !== 3) {
            return array('ok' => false, 'age' => 0);
        }
        list($time, $nonce, $sig) = $parts;
        if (!hash_equals(hash_hmac('sha256', $time . '.' . $nonce, $secret), $sig)) {
            return array('ok' => false, 'age' => 0);
        }
        return array('ok' => true, 'age' => time() - (int) $time);
    }

    /**
     * Bot checks for a submitted form. `silent` means: pretend success and do
     * nothing (honeypot hits learn nothing); otherwise show `message` and
     * re-render the form — a real user just tries again.
     *
     * @return array{ok: bool, outcome: ?string, silent: bool, message: string}
     */
    public static function checkSubmission(Request $request) {
        if ($request->str(self::HONEYPOT_FIELD)) {
            return array('ok' => false, 'outcome' => 'refused_honeypot', 'silent' => true, 'message' => '');
        }

        $token = self::parseFormToken($request->str(self::TOKEN_FIELD));
        $ttl = (int) Config::get('registration.form_token_ttl', 43200);
        $minSeconds = (int) Config::get('registration.min_form_seconds', 3);
        if (!$token['ok'] || $token['age'] < 0 || ($token['age'] !== PHP_INT_MAX && $token['age'] > $ttl)) {
            return array('ok' => false, 'outcome' => 'refused_form_token', 'silent' => false,
                'message' => __('This form has expired. Please submit it again.'));
        }
        if ($token['age'] < $minSeconds) {
            return array('ok' => false, 'outcome' => 'refused_too_fast', 'silent' => false,
                'message' => __('That was quick! Please take a moment to review your details and submit again.'));
        }

        $driver = self::challengeDriver();
        if ($driver !== null && !$driver->verifyRequest($request)) {
            return array('ok' => false, 'outcome' => 'refused_challenge', 'silent' => false,
                'message' => __('The anti-bot check could not be verified. Please try again.'));
        }

        return array('ok' => true, 'outcome' => null, 'silent' => false, 'message' => '');
    }

    /**
     * Who may register at all. registration.mode:
     *   open     current behaviour (default)
     *   domains  email domain must match registration.allowed_domains
     *   invite   a valid, unused token from bin/registration_invite.php
     *            must be entered in the token field of the signup form
     *   closed   nobody
     *
     * @return array{ok: bool, message: string}
     */
    public static function gate($email, $suppliedToken = null) {
        $mode = (string) Config::get('registration.mode', 'open');
        switch ($mode) {
            case 'open':
                return array('ok' => true, 'message' => '');
            case 'domains':
                // Accept if the address matches a whole allowed domain OR ends
                // in an allowed TLD. allowed_tlds is the same label-boundary
                // suffix match, just named for operators who only want to gate
                // by TLD (e.g. 'edu', 'ac.uk') without listing every institution.
                $domain = EmailDomainService::domainOf($email);
                if (self::domainMatchesAny($domain, (array) Config::get('registration.allowed_domains', array()))
                    || self::domainMatchesAny($domain, (array) Config::get('registration.allowed_tlds', array()))) {
                    return array('ok' => true, 'message' => '');
                }
                // A single-use invite (issued by a superadmin in the admin UI
                // or via bin/registration_invite.php) overrides the domain
                // restriction: it is a deliberate per-person exception, which
                // is exactly what people ask administrators for.
                if ($suppliedToken && self::findUsableInvite($suppliedToken)) {
                    return array('ok' => true, 'message' => '');
                }
                return array('ok' => false, 'message' => __('Sign-up on this formr instance is limited to certain institutions. Please use your institutional email address or an invitation token, or contact the administrator.'));
            case 'invite':
                if ($suppliedToken && self::findUsableInvite($suppliedToken)) {
                    return array('ok' => true, 'message' => '');
                }
                return array('ok' => false, 'message' => __('Sign-up on this formr instance requires an invitation token. Please enter the token you received, or contact the administrator.'));
            case 'closed':
            default:
                if ($mode !== 'closed') {
                    formr_log("REGISTRATION: unknown registration.mode '{$mode}' — treating as 'closed'.", 'MailGuard');
                }
                return array('ok' => false, 'message' => __('Sign-up is disabled on this formr instance.'));
        }
    }

    /**
     * True when $domain equals, or is a subdomain/TLD-suffix of, any entry
     * (label-boundary match: 'edu' accepts x.edu but not x.notedu.com).
     */
    protected static function domainMatchesAny($domain, array $entries) {
        $domain = strtolower(trim((string) $domain));
        if ($domain === '') {
            return false;
        }
        foreach ($entries as $entry) {
            $entry = strtolower(trim((string) $entry, ". \t"));
            if ($entry !== '' && ($domain === $entry || substr($domain, -strlen('.' . $entry)) === '.' . $entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a signup carries strong evidence of a real user: a valid
     * referral token (referrer_codes), or a valid single-use invite in
     * invite mode. A spammer essentially never has one, so the mail guard
     * relaxes per-source rate limits for these — keeping the global ceiling
     * and the duplicate/suppression/MX checks. See MailGuardService::check().
     */
    public static function signupIsTrusted($suppliedToken) {
        $code = trim((string) $suppliedToken);
        if ($code === '') {
            return false;
        }
        if (in_array($code, (array) Config::get('referrer_codes', array()), true)) {
            return true;
        }
        // A usable single-use invite is admin-issued, so it is trusted in any
        // mode — including as a domain-gate exception in 'domains' mode.
        if (self::findUsableInvite($code)) {
            return true;
        }
        return false;
    }

    /**
     * Issue $count single-use invites; returns the plaintext tokens — the
     * only time they are visible (only a sha256 is stored). Shared by the
     * admin UI (admin/advanced/registration-invites) and the caller-formatted
     * bin/registration_invite.php.
     *
     * @return string[] the tokens, to hand to the invitees
     */
    public static function issueInvites($count, $expiresDays, $createdBy) {
        $count = max(1, min(50, (int) $count));
        $expires = ((int) $expiresDays) > 0 ? date('Y-m-d H:i:s', time() + ((int) $expiresDays) * 86400) : null;
        $tokens = array();
        for ($i = 0; $i < $count; $i++) {
            $token = crypto_token(24);
            DB::getInstance()->insert('survey_registration_invites', array(
                'token_hash' => hash('sha256', $token),
                'created_by' => (string) $createdBy,
                'created' => mysql_now(),
                'expires' => $expires,
            ));
            $tokens[] = $token;
        }
        return $tokens;
    }

    /** All invites, newest first (metadata only — hashes are not reversible). */
    public static function listInvites() {
        return DB::getInstance()->execute(
            'SELECT id, created_by, created, expires, used_at, used_by '
            . 'FROM survey_registration_invites ORDER BY id DESC'
        );
    }

    /** Delete one UNUSED invite by id (used ones stay for the audit trail). */
    public static function revokeInviteById($id) {
        return (bool) DB::getInstance()->exec(
            'DELETE FROM survey_registration_invites WHERE id = :id AND used_at IS NULL',
            array('id' => (int) $id)
        );
    }

    protected static function findUsableInvite($suppliedToken) {
        $row = DB::getInstance()->findRow('survey_registration_invites',
            array('token_hash' => hash('sha256', trim($suppliedToken))), array('id', 'expires', 'used_at'));
        if (!$row || $row['used_at'] !== null) {
            return null;
        }
        if ($row['expires'] !== null && strtotime($row['expires']) < time()) {
            return null;
        }
        return $row;
    }

    /** Burn the invite after the account row exists. */
    public static function consumeInvite($suppliedToken, $userId) {
        $row = self::findUsableInvite($suppliedToken);
        if (!$row) {
            return false;
        }
        return (bool) DB::getInstance()->update('survey_registration_invites',
            array('used_at' => mysql_now(), 'used_by' => (int) $userId),
            array('id' => $row['id']));
    }

    /** @return RegistrationChallenge|null the configured challenge driver */
    public static function challengeDriver() {
        $name = Config::get('registration.challenge');
        if (!$name) {
            return null;
        }
        switch (strtolower((string) $name)) {
            case 'altcha':
                return new AltchaChallenge();
            case 'turnstile':
                return new TurnstileChallenge();
            default:
                formr_log("REGISTRATION: unknown registration.challenge '{$name}' — ignoring.", 'MailGuard');
                return null;
        }
    }

}
