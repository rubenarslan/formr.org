<?php

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Algorithm\Argon2id;
use AltchaOrg\Altcha\Algorithm\Sha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\ChallengeParameters;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\Solution;
use AltchaOrg\Altcha\VerifySolutionOptions;

/**
 * Local-only, privacy-preserving bot challenge for the `bot_check` item — now
 * backed by Altcha (https://altcha.org, MIT, self-hosted PoW).
 *
 * No third party, no cloud, no cookies, no PII leaves the box — the challenge
 * is minted and verified entirely on this server, so it adds no GDPR processor
 * relationship (cf. the Cloudflare/reCAPTCHA siteverify model, which ships the
 * participant's IP + headers to a US service). This is Altcha's free self-hosted
 * proof-of-work, NOT the Sentinel SaaS.
 *
 *   mint()   — issues an HMAC-signed Altcha challenge bound to the current
 *              participant (user_code folded into the signed `data`). Fetched
 *              fresh by the widget from RunController::formBotChallengeAction
 *              when it mounts (NOT baked into the page), so it can't go stale on
 *              a later form_v2 page.
 *   verify() — re-derives + checks the Altcha solution (signature pins the
 *              parameters so difficulty/memory can't be lowered), confirms the
 *              session binding, and checks freshness/expiry. A harvested token is
 *              worthless to another participant and a direct-POST bot can't forge
 *              one at all.
 *
 * The memory-hard Argon2id PoW shifts the cost to RAM, resisting GPU/ASIC
 * acceleration and bot farms. It stops the dominant survey-fraud classes
 * (direct-to-endpoint HTTP bots and naive JS automation). It does NOT stop a
 * clean browser driven by OS-level input — no self-hosted check can. See
 * documentation/agent_doc/bot_check_altcha.md for the threat model + config.
 */
class BotCheckChallenge {

    // How long a minted challenge stays valid (its Altcha expiresAt). The token
    // is bound to the participant's session, so a long window grants an attacker
    // nothing: a harvested challenge is useless to anyone else and the PoW costs
    // the same work regardless. Slowness is NOT a bot signal. With the v2
    // challengeurl/lazy-fetch design the challenge is fetched when the widget
    // mounts (i.e. when the participant reaches the page), so it no longer has to
    // outlive a whole multi-page form — but we keep a generous default so a diary
    // page left open for a while still verifies. Override via Config('bot_check_ttl').
    const TTL = 86400; // 24 hours

    // Argon2id difficulty knobs. keyPrefix length (bytes) drives the expected
    // number of PoW attempts (~128 per byte on average); cost/memoryCost drive
    // the per-attempt work. Defaults target ~a few hundred ms on a normal device
    // with the widget's parallel workers, while staying genuinely memory-hard.
    // 4 MiB memory + 1-byte prefix (~256 expected hashes). Native median ~0.3s
    // across the widget's 4 parallel workers; in-browser WASM is a few× slower,
    // so a normal device lands in the sub-second-to-~2s range — comfortably "a
    // few hundred ms"-ish without being trivial, and genuinely memory-hard
    // (4 MiB resists GPU/ASIC fan-out). Bump bot_check_argon_memory for a harder
    // gate. The SERVER only ever does ONE verification hash (~7ms), so raising
    // memory costs the attacker, not us.
    const DEFAULT_MEMORY_COST = 4096;  // KiB (4 MiB) — memory-hard, GPU/ASIC-resistant
    const DEFAULT_TIME_COST   = 1;     // Argon2 iterations (passes)
    const DEFAULT_PARALLELISM = 1;     // sodium's pwhash uses 1; echoed for the widget
    const DEFAULT_PREFIX_BYTES = 1;    // leading bytes the derived key must match
    const DEFAULT_KEY_LENGTH  = 16;    // derived-key length in bytes

    // legacy diff knob (type_options on the item) is reinterpreted as prefix
    // bytes when 1..3, else ignored; kept so existing `bot_check N` sheets don't
    // error. Difficulty is otherwise governed by the Config knobs above.
    const MIN_PREFIX_BYTES = 1;
    const MAX_PREFIX_BYTES = 3;

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
    public static function subject() {
        $user = class_exists('Site') ? Site::getCurrentUser() : null;
        return ($user && !empty($user->user_code)) ? (string) $user->user_code : '';
    }

    /** Whether ext-sodium (Argon2id) is available; otherwise fall back to SHA-256. */
    public static function argon2idAvailable() {
        return function_exists('sodium_crypto_pwhash')
            && defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13');
    }

    protected static function altcha() {
        $secret = self::secret();
        return $secret === null ? null : new Altcha($secret);
    }

    protected static function algorithm() {
        // Memory-hard Argon2id when sodium is present; bounded SHA-256 otherwise.
        // Both are verified by the same Altcha verifySolution path; the widget
        // picks the matching algorithm from the challenge JSON.
        return self::argon2idAvailable() ? new Argon2id() : new Sha();
    }

    /** Difficulty: prefix bytes (PoW attempt count). Optionally from type_options. */
    protected static function prefixBytes($override = null) {
        $n = (int) Config::get('bot_check_prefix_bytes', self::DEFAULT_PREFIX_BYTES);
        if ($override !== null && is_numeric($override)) {
            $n = (int) $override;
        }
        if ($n < self::MIN_PREFIX_BYTES) return self::DEFAULT_PREFIX_BYTES;
        if ($n > self::MAX_PREFIX_BYTES) return self::MAX_PREFIX_BYTES;
        return $n;
    }

    protected static function memoryCost() {
        $m = (int) Config::get('bot_check_argon_memory', self::DEFAULT_MEMORY_COST);
        return $m >= 256 ? $m : self::DEFAULT_MEMORY_COST; // KiB; sodium min ~256
    }

    protected static function timeCost() {
        $t = (int) Config::get('bot_check_argon_time', self::DEFAULT_TIME_COST);
        return $t >= 1 ? $t : self::DEFAULT_TIME_COST;
    }

    protected static function ttl() {
        $ttl = (int) Config::get('bot_check_ttl', self::TTL);
        return $ttl >= 60 ? $ttl : self::TTL;
    }

    /**
     * Mint a fresh, session-bound Altcha challenge. Returns the wire array the
     * widget's challengeurl expects: {algorithm, challenge, salt, signature, ...,
     * maxnumber?}. (Altcha's $challenge->toArray() = {parameters, signature};
     * the v3 widget consumes the nested form directly.)
     *
     * @return array<string,mixed>|null  null when the server can't sign.
     */
    public static function mint($difficulty = null) {
        $altcha = self::altcha();
        if ($altcha === null) return null;
        $alg = self::algorithm();
        // Session binding: fold user_code into the signed `data`. The HMAC covers
        // it (toCanonicalJson includes `data`), so a harvested challenge can't be
        // re-bound to another participant; verify() additionally checks it matches
        // the verifying session (defence in depth).
        $data = array('uc' => self::subject());
        $isArgon = $alg->getAlgorithmName() === 'ARGON2ID';
        // keyPrefix = N leading zero BYTES the derived key must match. This IS the
        // PoW: the client searches counters until its Argon2id(salt, nonce|counter)
        // begins with that prefix (~256 expected attempts per byte). The HMAC
        // signature pins keyPrefix/cost/memoryCost so a bot can't lower it.
        $opts = new CreateChallengeOptions(
            algorithm: $alg,
            cost: $isArgon ? self::timeCost() : 100000,
            keyLength: self::DEFAULT_KEY_LENGTH,
            keyPrefix: str_repeat('00', self::prefixBytes($difficulty)),
            memoryCost: $isArgon ? self::memoryCost() : null,
            parallelism: $isArgon ? self::DEFAULT_PARALLELISM : null,
            expiresAt: time() + self::ttl(),
            data: $data
        );
        $challenge = $altcha->createChallenge($opts);
        return $challenge->toArray();
    }

    /**
     * Decode the client payload. The Altcha widget submits a base64-encoded JSON
     * {challenge:{parameters,signature}, solution:{counter,derivedKey}}. We also
     * accept already-decoded arrays / raw JSON for the smoke test + robustness.
     *
     * @return array{challenge:array,solution:array}|null
     */
    protected static function decodePayload($token) {
        if (is_array($token)) {
            $arr = $token;
        } else {
            $s = (string) $token;
            if ($s === '') return null;
            $decoded = base64_decode($s, true);
            $json = ($decoded !== false) ? json_decode($decoded, true) : null;
            if (!is_array($json)) {
                $json = json_decode($s, true); // maybe it was raw JSON
            }
            $arr = is_array($json) ? $json : null;
        }
        if (!is_array($arr) || !isset($arr['challenge'], $arr['solution'])) return null;
        if (!is_array($arr['challenge']) || !is_array($arr['solution'])) return null;
        return $arr;
    }

    /**
     * Verify a client token. Returns true only if the Altcha signature + PoW
     * solution verify AND the challenge is bound to the current participant AND
     * it's fresh. The signature (recomputed from our secret over the canonical
     * parameters, incl. `data.uc`) pins everything: difficulty/memory can't be
     * lowered, the binding can't be re-pointed, the solution can't be forged.
     */
    public static function verify($token) {
        $altcha = self::altcha();
        if ($altcha === null) {
            return true; // can't sign → can't challenge; don't lock people out
        }
        $arr = self::decodePayload($token);
        if ($arr === null) return false;

        $params = isset($arr['challenge']['parameters']) && is_array($arr['challenge']['parameters'])
            ? $arr['challenge']['parameters'] : array();
        if (!$params) return false;

        // Pick the verifying algorithm from the (signature-pinned) challenge.
        $algName = isset($params['algorithm']) ? (string) $params['algorithm'] : '';
        if ($algName === 'ARGON2ID') {
            if (!self::argon2idAvailable()) return false;
            $alg = new Argon2id();
        } elseif ($algName === 'SHA-256') {
            $alg = new Sha();
        } else {
            return false; // unexpected algorithm — refuse
        }

        $challenge = new Challenge(
            ChallengeParameters::fromArray($params),
            isset($arr['challenge']['signature']) ? (string) $arr['challenge']['signature'] : null
        );
        $solution = new Solution(
            (int) ($arr['solution']['counter'] ?? 0),
            (string) ($arr['solution']['derivedKey'] ?? '')
        );

        $result = $altcha->verifySolution(new VerifySolutionOptions(
            payload: new Payload($challenge, $solution),
            algorithm: $alg
        ));
        if (!$result->verified) return false;

        // Session binding: the signed `data.uc` must match this participant. The
        // signature already proved data.uc is what we minted; this confirms it
        // was minted FOR this session (so a token from session A can't be
        // replayed by session B even if both are valid Altcha solutions).
        $boundUc = isset($params['data']['uc']) ? (string) $params['data']['uc'] : '';
        if ($boundUc !== self::subject()) return false;

        // Freshness hygiene (expiry is also enforced inside verifySolution via
        // expiresAt; this rejects clock-skew "issued in the future" tokens).
        if (isset($params['expiresAt'])) {
            $iat = (int) $params['expiresAt'] - self::ttl();
            if (time() - $iat < -120) return false;
        }
        return true;
    }
}
