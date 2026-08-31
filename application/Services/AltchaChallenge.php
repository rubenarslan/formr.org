<?php

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Algorithm\Sha;
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
 * the vetted BotCheckChallenge service on the feature/altcha branch, reduced
 * to SHA-256 PoW so the stock <altcha-widget> works without extra worker
 * assets.
 *
 * The widget (self-hosted, webpack-copied from the `altcha` npm package into
 * the asset build) fetches a fresh challenge from
 * admin/account/register-challenge when it mounts and submits the solved
 * payload in the hidden `altcha` field. The HMAC signature (secret shared
 * with RegistrationGuard, derived from the instance crypto key) pins the
 * difficulty and the session binding; verification is a single hash.
 */
class AltchaChallenge implements RegistrationChallenge {

    /** How long a minted challenge stays valid. */
    const TTL = 3600;

    /** SHA-256 PoW difficulty: expected number of hash attempts (~cost/2). */
    const DEFAULT_COST = 100000;

    const FIELD = 'altcha';

    protected function altcha() {
        if (!class_exists('AltchaOrg\\Altcha\\Altcha')) {
            formr_log('ALTCHA: composer package altcha-org/altcha is not installed — challenge disabled (failing open).', 'MailGuard');
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
            algorithm: new Sha(),
            cost: (int) Config::get('registration.altcha_cost', self::DEFAULT_COST),
            keyLength: 16,
            keyPrefix: '00',
            expiresAt: time() + self::TTL,
            data: array('sid' => $this->subject())
        );
        return $altcha->createChallenge($opts)->toArray();
    }

    public function widgetHtml() {
        if ($this->altcha() === null) {
            return ''; // failing open — no widget, no verification
        }
        $challengeUrl = h(site_url('admin/account/register-challenge'));
        $js = h(asset_url('assets/build/js/altcha/altcha.min.js'));
        $css = h(asset_url('assets/build/js/altcha/altcha.css'));
        return '<link rel="stylesheet" href="' . $css . '">'
            . '<script type="module" src="' . $js . '"></script>'
            . '<div class="form-group"><altcha-widget challengeurl="' . $challengeUrl . '"></altcha-widget></div>';
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
        if (!$params || (isset($params['algorithm']) && $params['algorithm'] !== 'SHA-256')) {
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
            algorithm: new Sha()
        ));
        if (!$result->verified) {
            return false;
        }
        // The signature proved data.sid is what we minted; confirm it was
        // minted FOR this visitor so solutions can't be replayed cross-session.
        $boundSid = isset($params['data']['sid']) ? (string) $params['data']['sid'] : '';
        return $boundSid === $this->subject();
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
