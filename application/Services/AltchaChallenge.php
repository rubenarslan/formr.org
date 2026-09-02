<?php

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Algorithm\Argon2id;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\ChallengeParameters;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\Solution;
use AltchaOrg\Altcha\VerifySolutionOptions;

/**
 * Self-hosted proof-of-work challenge for the registration form, backed by
 * Altcha (https://altcha.org, MIT). Everything is minted and verified on this
 * server: no third party, no cookies, no visitor data leaves the box, so it
 * raises no data-protection questions for European institutional deployments
 * (unlike the Cloudflare/reCAPTCHA siteverify model). Usage patterns follow
 * the vetted BotCheckChallenge service on the feature/altcha branch:
 * memory-hard Argon2id proof of work, solved in-browser by the self-hosted
 * argon2id.js Web Worker registered with the stock <altcha-widget> — the
 * ONLY algorithm/widget pairing with browser e2e evidence (the v3 widget
 * refuses the PHP lib's SHA-256 challenges: "Unsupported algorithm").
 * Requires ext-sodium; without it the challenge fails open (no widget)
 * rather than minting challenges no browser can solve.
 *
 * The widget (self-hosted, webpack-copied from the `altcha` npm package into
 * the asset build) fetches a fresh challenge from
 * admin/account/register-challenge (v3 attribute name: `challenge`) when
 * clicked and submits the solved payload in the hidden `altcha` field. The
 * HMAC signature (secret shared with RegistrationGuard, derived from the
 * instance crypto key) pins difficulty, memory cost and the session binding;
 * server-side verification is a single Argon2id hash (~ms).
 */
class AltchaChallenge implements RegistrationChallenge {

    /** How long a minted challenge stays valid. */
    const TTL = 3600;

    // Argon2id knobs, straight from the vetted BotCheckChallenge defaults:
    // 4 MiB memory (GPU/ASIC-resistant), 1 iteration, 1-byte zero prefix
    // (~256 expected attempts) — sub-second-to-a-few-seconds on a normal
    // device via the widget's workers; ONE ~ms hash for the server to verify.
    const MEMORY_COST = 4096; // KiB
    const TIME_COST = 1;
    const PARALLELISM = 1;
    const KEY_PREFIX = '00';  // one zero byte
    const KEY_LENGTH = 16;

    const FIELD = 'altcha';

    protected function altcha() {
        if (!class_exists('AltchaOrg\\Altcha\\Altcha')) {
            formr_log('ALTCHA: composer package altcha-org/altcha is not installed — challenge disabled (failing open).', 'MailGuard');
            return null;
        }
        if (!function_exists('sodium_crypto_pwhash') || !defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13')) {
            formr_log('ALTCHA: ext-sodium (Argon2id) unavailable — challenge disabled (failing open).', 'MailGuard');
            return null;
        }
        $secret = RegistrationGuard::secret();
        if ($secret === null) {
            formr_log('ALTCHA: no signing secret available — challenge disabled (failing open).', 'MailGuard');
            return null;
        }
        return new Altcha($secret);
    }

    /** Session binding, so a harvested solution is useless to another visitor. */
    protected function subject() {
        return session_id() ?: '';
    }

    /**
     * Wire array for the widget's challengeurl endpoint
     * (AdminAccountController::registerChallengeAction), or null when the
     * driver cannot sign (caller then omits the widget).
     */
    public function mint() {
        $altcha = $this->altcha();
        if ($altcha === null) {
            return null;
        }
        $opts = new CreateChallengeOptions(
            algorithm: new Argon2id(),
            cost: self::TIME_COST,
            keyLength: self::KEY_LENGTH,
            keyPrefix: self::KEY_PREFIX,
            memoryCost: self::MEMORY_COST,
            parallelism: self::PARALLELISM,
            expiresAt: time() + self::TTL,
            data: array('sid' => $this->subject())
        );
        return $altcha->createChallenge($opts)->toArray();
    }

    public function widgetHtml($cspNonce) {
        if ($this->altcha() === null) {
            return ''; // failing open — no widget, no verification
        }
        $challengeUrl = h(site_url('admin/account/register-challenge'));
        $js = h(asset_url('assets/build/js/altcha/altcha.min.js'));
        $css = h(asset_url('assets/build/js/altcha/altcha.css'));
        $worker = json_encode(asset_url('assets/build/js/altcha/argon2id.js'));
        // The admin CSP nonce-gates inline scripts (script-src 'self'
        // 'nonce-…', no unsafe-inline); the nonce comes from the controller
        // so it is the one in the header (see RegistrationChallenge).
        // External 'self' scripts need no nonce. Module scripts run in
        // document order: the external build defines <altcha-widget> and
        // window.$altcha, then the inline module registers the self-hosted
        // Argon2id worker — if that inline script is blocked, the widget
        // has no algorithm and reports "Verification failed" on every click.
        $nonce = h($cspNonce);
        return '<link rel="stylesheet" href="' . $css . '">'
            . '<script type="module" src="' . $js . '"></script>'
            . '<script type="module" nonce="' . $nonce . '">if (window.$altcha && window.$altcha.algorithms) {'
            . ' window.$altcha.algorithms.set(\'ARGON2ID\', () => new Worker(' . $worker . ')); }</script>'
            . '<div class="form-group"><altcha-widget challenge="' . $challengeUrl . '"></altcha-widget></div>';
    }

    public function verifyRequest(Request $request) {
        $altcha = $this->altcha();
        if ($altcha === null) {
            return true; // can't sign -> can't challenge; don't lock people out
        }
        $arr = $this->decodePayload($request->str(self::FIELD));
        if ($arr === null) {
            return false;
        }
        $params = isset($arr['challenge']['parameters']) && is_array($arr['challenge']['parameters'])
            ? $arr['challenge']['parameters'] : array();
        if (!$params || (isset($params['algorithm']) && $params['algorithm'] !== 'ARGON2ID')) {
            return false;
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
            algorithm: new Argon2id()
        ));
        if (!$result->verified) {
            return false;
        }
        // The signature proved data.sid is what we minted; confirm it was
        // minted FOR this visitor so solutions can't be replayed cross-session.
        $boundSid = isset($params['data']['sid']) ? (string) $params['data']['sid'] : '';
        if ($boundSid !== $this->subject()) {
            return false;
        }
        // ...and only once within this session: a solved challenge is burned
        // until it expires, so the same PoW can't be resubmitted for the rest
        // of its TTL. The signature identifies the challenge (it covers salt +
        // expiry), and each re-rendered form fetches a fresh one.
        $id = 'altcha:' . (string) $challenge->signature;
        if (RegistrationGuard::isBurned($id)) {
            return false;
        }
        $expires = isset($params['expiresAt']) ? (int) $params['expiresAt'] : time() + self::TTL;
        RegistrationGuard::burn($id, $expires);
        return true;
    }

    /** The widget submits base64-encoded JSON {challenge, solution}. */
    protected function decodePayload($token) {
        $s = (string) $token;
        if ($s === '') {
            return null;
        }
        $decoded = base64_decode($s, true);
        $json = ($decoded !== false) ? json_decode($decoded, true) : null;
        if (!is_array($json)) {
            $json = json_decode($s, true);
        }
        if (!is_array($json) || !isset($json['challenge'], $json['solution'])
            || !is_array($json['challenge']) || !is_array($json['solution'])) {
            return null;
        }
        return $json;
    }

}
