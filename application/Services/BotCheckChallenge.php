<?php

/**
 * Local-only, privacy-preserving bot challenge for the `bot_check` item.
 *
 * No third party, no cloud, no cookies, no PII — everything is computed and
 * verified on this server, so it adds no GDPR processor relationship (cf. the
 * Cloudflare/reCAPTCHA siteverify model, which ships the participant's IP +
 * headers to a US service). The deterrent is a signed proof-of-work token:
 *
 *   mint()   — issued at render time; an HMAC-signed {iat, salt, diff} bound to
 *              the current participant (user_code). Embedded in the widget.
 *   verify() — recomputes the signature (so iat/salt/diff can't be tampered or
 *              the difficulty lowered), checks the PoW solution and a honeypot.
 *              Stateless: the signature binds the token to
 *              the session, so a harvested token is worthless for another
 *              participant and a direct-POST bot can't forge one at all.
 *
 * It stops the dominant survey-fraud classes (direct-to-endpoint HTTP bots and
 * naive JS automation). It does NOT stop a clean browser driven by OS-level
 * input — no self-hosted check can. See documentation/agent_doc for the threat
 * model write-up.
 */
class BotCheckChallenge {

    const TTL = 1800;        // a minted challenge is valid for 30 minutes
    const DEFAULT_DIFFICULTY = 15; // leading zero bits (~32k SHA-256 tries)
    const MIN_DIFFICULTY = 10;
    const MAX_DIFFICULTY = 22;

    /** Server-local HMAC secret. Never leaves the box, never sent to the client. */
    public static function secret() {
        $configured = Config::get('bot_check_secret');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        // Derive from the at-rest crypto key (always present in a formr install).
        $keyFile = Config::get('encryption_key_file');
        if (!$keyFile && defined('APPLICATION_CRYPTO_KEY_FILE')) {
            $keyFile = APPLICATION_CRYPTO_KEY_FILE;
        }
        if ($keyFile && is_readable($keyFile)) {
            return hash('sha256', 'fmr-bot-check|' . file_get_contents($keyFile));
        }
        return null; // misconfigured — caller fails open (see BotCheck_Item)
    }

    /** Stable per-participant binding so a token can't be reused across sessions. */
    protected static function subject() {
        $user = class_exists('Site') ? Site::getCurrentUser() : null;
        return ($user && !empty($user->user_code)) ? (string) $user->user_code : '';
    }

    public static function clampDifficulty($diff) {
        $diff = (int) $diff;
        if ($diff < self::MIN_DIFFICULTY) return self::DEFAULT_DIFFICULTY;
        if ($diff > self::MAX_DIFFICULTY) return self::MAX_DIFFICULTY;
        return $diff;
    }

    protected static function sign($iat, $salt, $diff) {
        $secret = self::secret();
        if ($secret === null) return '';
        return hash_hmac('sha256', $iat . '.' . $salt . '.' . $diff . '.' . self::subject(), $secret);
    }

    /**
     * @return array{iat:int,salt:string,diff:int,sig:string}|null
     */
    public static function mint($difficulty = null) {
        if (self::secret() === null) return null;
        $diff = self::clampDifficulty($difficulty === null ? self::DEFAULT_DIFFICULTY : $difficulty);
        $iat = time();
        $salt = bin2hex(random_bytes(12));
        return ['iat' => $iat, 'salt' => $salt, 'diff' => $diff, 'sig' => self::sign($iat, $salt, $diff)];
    }

    /** Count leading zero bits of a raw binary string. */
    protected static function leadingZeroBits($raw) {
        $bits = 0;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($raw[$i]);
            if ($b === 0) { $bits += 8; continue; }
            $mask = 0x80;
            while ($mask && ($b & $mask) === 0) { $bits++; $mask >>= 1; }
            break;
        }
        return $bits;
    }

    /**
     * Verify a client token (the JSON the widget writes into the hidden input).
     * Returns true only if the signature, PoW and honeypot all pass.
     */
    public static function verify($token) {
        if (self::secret() === null) {
            return true; // can't sign → can't challenge; don't lock people out
        }
        $t = is_array($token) ? $token : json_decode((string) $token, true);
        if (!is_array($t)) return false;
        foreach (['iat', 'salt', 'diff', 'sig', 'nonce'] as $k) {
            if (!isset($t[$k])) return false;
        }
        $iat = (int) $t['iat'];
        $salt = (string) $t['salt'];
        $diff = (int) $t['diff'];
        // Signature must match — pins iat/salt/diff and the participant binding,
        // so the difficulty can't be lowered and the token can't be re-bound.
        $expected = self::sign($iat, $salt, $diff);
        if ($expected === '' || !hash_equals($expected, (string) $t['sig'])) return false;
        if ($diff < self::MIN_DIFFICULTY) return false;
        // Freshness.
        $age = time() - $iat;
        if ($age < -120 || $age > self::TTL) return false;
        // Honeypot must be empty.
        if (!empty($t['hp'])) return false;
        // NOTE: we deliberately do NOT gate on a minimum solve time. The client
        // reports `el` (PoW wall-time), but (1) it's client-supplied so a real
        // bot just claims a large value, and (2) the PoW solve time is highly
        // variable — diff-15 legitimately finishes in tens of ms on modern
        // hardware (~40%+ of honest solves came in under 200ms in testing), so
        // any floor false-positives real participants ("verified but can't
        // proceed"). The signed PoW + freshness + isTrusted gate are the real
        // deterrents; the timing added no bot signal, only honest-user blocks.
        // Proof of work: SHA-256(salt . nonce) has >= diff leading zero bits.
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $t['nonce'])) return false;
        $digest = hash('sha256', $salt . (string) $t['nonce'], true);
        return self::leadingZeroBits($digest) >= $diff;
    }
}
