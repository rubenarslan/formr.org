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
 *              parameters so they can't be edited), re-derives the difficulty
 *              the item demands and refuses an easier genuine challenge,
 *              confirms the session binding, checks freshness/expiry, and
 *              burns the token so it is single-use. A harvested token is
 *              worthless to another participant and a direct-POST bot can't
 *              forge one at all. Fails CLOSED in every direction, including a
 *              server that has lost its signing secret.
 *
 * Difficulty is decided by the server alone: `bot_check N` on the item (via
 * BotCheck_Item::chosenDifficulty) or Config, never by the request. The mint
 * endpoint takes an item NAME and looks the item up in the current study.
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
    const SHA_COST            = 100000; // SHA-256 iterations when sodium is missing

    // `bot_check N` (type_options on the item) sets the prefix bytes when 1..3;
    // out-of-range values clamp (see expectedPrefixBytes()). Without it the
    // Config knob applies.
    const MIN_PREFIX_BYTES = 1;
    const MAX_PREFIX_BYTES = 3;

    /** Session key of the burn list (single-use tokens), shared with RegistrationGuard. */
    const BURN_PREFIX = 'botcheck:';

    /** Server-local HMAC secret. Never leaves the box, never sent to the client. */
    public static function secret() {
        $configured = Config::get('bot_check_secret');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        // Derive from the at-rest crypto key (always present in a formr install),
        // resolved the same way Crypto itself (and RegistrationGuard) resolves it.
        $keyFile = Crypto::getKeyFile();
        if ($keyFile && is_readable($keyFile)) {
            return hash('sha256', 'fmr-bot-check|' . file_get_contents($keyFile));
        }
        // Misconfigured. Callers fail CLOSED: mint() returns null (endpoint 503s
        // + logs), verify() rejects every token, BotCheck_Item surfaces a
        // "temporarily unavailable" error. Never silently accept.
        return null;
    }

    /** @var int|null the unit session a challenge is minted for and verified against */
    private static $unitSessionId = null;

    /**
     * Bind challenges to a unit session (RunController's v2 write gates set
     * it). A minted challenge carries it signed (`data.us`), and a token
     * minted for another unit session is refused: the single-use list lives
     * in the PHP session, so without this a bot that drops its cookie could
     * replay one solved token in every later unit session (each diary entry).
     */
    public static function bindTo($unitSessionId) {
        self::$unitSessionId = $unitSessionId ? (int) $unitSessionId : null;
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

    /**
     * Whether the server can sign (and therefore verify) challenges at all.
     * False means the install is misconfigured — see secret(). Callers use it
     * to tell "this participant failed the check" apart from "this server
     * cannot run the check", which are the same `false` out of verify().
     */
    public static function available() {
        return self::secret() !== null;
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

    /**
     * Difficulty: prefix bytes (PoW attempt count). The SERVER decides this —
     * from the item's `bot_check N` type_options when the sheet sets one, else
     * from Config('bot_check_prefix_bytes') — never from anything the client
     * sends. mint() and verify() both go through here, so a challenge minted at
     * a lower difficulty than the item demands (an old/foreign mint, a tampered
     * request to the mint endpoint) fails verify() even though its signature is
     * genuine.
     *
     * @param BotCheck_Item|null $item the study's item row, or null for the
     *        configured default (a mint without an item).
     */
    public static function expectedPrefixBytes($item = null) {
        $n = (int) Config::get('bot_check_prefix_bytes', self::DEFAULT_PREFIX_BYTES);
        if ($item instanceof BotCheck_Item) {
            $chosen = $item->chosenDifficulty();
            if ($chosen !== null) {
                $n = (int) $chosen;
            }
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
     * @param BotCheck_Item|null $item the bot_check item this challenge is for;
     *        its type_options set the difficulty (see expectedPrefixBytes()).
     *        Anything that is not a BotCheck_Item is ignored — the difficulty
     *        is never taken from a caller-supplied number.
     * @return array<string,mixed>|null  null when the server can't sign.
     */
    public static function mint($item = null) {
        $altcha = self::altcha();
        if ($altcha === null) return null;
        $alg = self::algorithm();
        // Session binding: fold user_code into the signed `data`. The HMAC covers
        // it (toCanonicalJson includes `data`), so a harvested challenge can't be
        // re-bound to another participant; verify() additionally checks it matches
        // the verifying session (defence in depth).
        $data = array('uc' => self::subject());
        if (self::$unitSessionId) {
            $data['us'] = (string) self::$unitSessionId;
        }
        $isArgon = $alg->getAlgorithmName() === 'ARGON2ID';
        // keyPrefix = N leading zero BYTES the derived key must match. This IS the
        // PoW: the client searches counters until its Argon2id(salt, nonce|counter)
        // begins with that prefix (~256 expected attempts per byte). The HMAC
        // signature pins keyPrefix/cost/memoryCost so a bot can't lower it, and
        // verify() re-derives the same floor from the item, so a genuinely
        // signed but too-easy challenge is refused too.
        $opts = new CreateChallengeOptions(
            algorithm: $alg,
            cost: $isArgon ? self::timeCost() : self::SHA_COST,
            keyLength: self::DEFAULT_KEY_LENGTH,
            keyPrefix: str_repeat('00', self::expectedPrefixBytes($item)),
            memoryCost: $isArgon ? self::memoryCost() : null,
            parallelism: $isArgon ? self::DEFAULT_PARALLELISM : null,
            expiresAt: time() + self::ttl(),
            data: $data
        );
        $challenge = $altcha->createChallenge($opts);
        return $challenge->toArray();
    }

    /**
     * Verify a client token. Returns true only if the Altcha signature + PoW
     * solution verify AND the challenge is bound to the current participant AND
     * it's fresh. The signature (recomputed from our secret over the canonical
     * parameters, incl. `data.uc`) pins everything: difficulty/memory can't be
     * lowered, the binding can't be re-pointed, the solution can't be forged.
     *
     * FAILS CLOSED. Every path that cannot positively verify returns false —
     * including the one where the server has no signing secret. That branch used
     * to `return true`, on the reasoning that an install which can't mint a
     * challenge shouldn't lock participants out. But the branch is reachable by
     * a plain empty token, so the effect was that a single unreadable key file
     * silently turned every bot_check in the fleet into a no-op that still
     * *looked* armed in the admin UI — a security control whose failure mode is
     * "accept everything, tell nobody". The endpoint now logs the
     * misconfiguration and answers 503, and BotCheck_Item::validateInput shows a
     * distinct "temporarily unavailable" message, so the failure is loud and
     * fixable instead of silent and permanent.
     *
     * A token that verifies is BURNED (RegistrationGuard's session burn list,
     * the same mechanism the registration Altcha uses): the signature identifies
     * the challenge, and it is remembered as used until it would have expired
     * anyway. Without that, one solved PoW replays for the whole TTL — every
     * bot_check the participant meets again within 24h (a diary run re-presents
     * it per unit session) would accept the same token.
     *
     * @param mixed $token the widget's base64 payload (or decoded array)
     * @param BotCheck_Item|null $item the item being validated; sets the
     *        difficulty floor (see expectedPrefixBytes()). null = config default.
     * @param string|null $burnId set to the burn a verified token got, which
     *        BotCheck_Item::finishSideEffects gives back if its page is not stored
     */
    public static function verify($token, $item = null, &$burnId = null) {
        $burnId = null;
        $altcha = self::altcha();
        if ($altcha === null) {
            formr_log(
                'BotCheckChallenge::verify refused: no signing secret (set bot_check_secret, or make the '
                . 'encryption key file readable). Every bot_check submission is rejected until this is fixed.',
                'bot_check'
            );
            return false;
        }
        $arr = AltchaChallenge::decodePayload($token);
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

        // Difficulty floor, re-derived server-side from the item/config. The
        // signature proves WE minted these parameters, not that they are hard
        // enough for THIS item: a challenge minted at the default (or for an
        // easier item, or by an older endpoint that took the difficulty from
        // the query string) must not satisfy a `bot_check 3`.
        if (!self::meetsDifficultyFloor($params, $algName, $item)) return false;

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
        // …and this unit session (bindTo), when there is one.
        $boundUs = isset($params['data']['us']) ? (string) $params['data']['us'] : '';
        if ($boundUs !== (self::$unitSessionId ? (string) self::$unitSessionId : '')) return false;

        // Freshness is the signed expiresAt, which verifySolution enforces. (A
        // mint time derived from it and the configured TTL refused every
        // outstanding challenge as "issued in the future" once the TTL was
        // lowered.)

        // Single use. The signature (over salt + nonce + expiry + data) names
        // the challenge; once a solution for it has passed, the same token is
        // refused for the rest of its life. Burn BEFORE returning true so the
        // caller can't observe an accepted-but-not-yet-burned window.
        $id = self::BURN_PREFIX . (string) $challenge->signature;
        if (RegistrationGuard::isBurned($id)) return false;
        $expires = isset($params['expiresAt']) ? (int) $params['expiresAt'] : time() + self::ttl();
        RegistrationGuard::burn($id, $expires);
        $burnId = $id;
        return true;
    }

    /**
     * The minted parameters must be at least as hard as what mint() would
     * issue right now for $item: prefix bytes from the item/config, and the
     * per-attempt cost knobs from Config. All of these are signature-pinned,
     * so this is about WHICH genuine challenge is being presented, not forgery.
     *
     * @param array<string,mixed> $params
     */
    protected static function meetsDifficultyFloor(array $params, $algName, $item) {
        $keyPrefix = isset($params['keyPrefix']) ? (string) $params['keyPrefix'] : '';
        if ($keyPrefix === '' || strlen($keyPrefix) % 2 !== 0 || !ctype_xdigit($keyPrefix)) return false;
        if (strlen($keyPrefix) / 2 < self::expectedPrefixBytes($item)) return false;

        $cost = isset($params['cost']) ? (int) $params['cost'] : 0;
        if ($algName === 'ARGON2ID') {
            $memory = isset($params['memoryCost']) ? (int) $params['memoryCost'] : 0;
            if ($memory < self::memoryCost() || $cost < self::timeCost()) return false;
        } elseif ($cost < self::SHA_COST) {
            return false;
        }
        return true;
    }
}
