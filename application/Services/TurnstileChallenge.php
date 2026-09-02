<?php

/**
 * Cloudflare Turnstile driver for the registration challenge. NOT the
 * default and deliberately not recommended for European institutional
 * deployments: the widget loads from and reports to Cloudflare, so visitor
 * data (IP, browser signals) reaches a third party and the instance operator
 * must document that processing. Prefer registration.challenge = 'altcha'
 * (self-hosted). Provided because some operators already have Turnstile keys.
 *
 * Config: registration.turnstile_site_key / registration.turnstile_secret_key.
 *
 * The admin CSP is nonce-gated with no third-party origins, so the widget
 * registers https://challenges.cloudflare.com for script/frame/connect on
 * the request that renders it and carries the per-request nonce on its
 * script tag. Verification goes through CURL::HttpRequest so the instance's
 * outbound proxy config applies.
 *
 * Fail-open policy, same shape as the mail guard's DNS check: only
 * Cloudflare's own verdict ("success": false) fails closed. Missing keys,
 * an unreachable or erroring (5xx) siteverify, or an unparseable answer let
 * the signup through with a log line — the challenge is friction, not the
 * only defence, and an outage upstream must not close registration.
 */
class TurnstileChallenge implements RegistrationChallenge {

    const FIELD = 'cf-turnstile-response';
    const ORIGIN = 'https://challenges.cloudflare.com';
    const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function widgetHtml() {
        $siteKey = Config::get('registration.turnstile_site_key');
        if (!$siteKey || !Config::get('registration.turnstile_secret_key')) {
            formr_log('TURNSTILE: site/secret key not configured — challenge disabled (failing open).', 'MailGuard');
            return '';
        }
        Csp::allow(self::ORIGIN, array('script-src', 'frame-src', 'connect-src'));
        $nonce = h(Site::getInstance()->getCspNonce());
        return '<script src="' . self::ORIGIN . '/turnstile/v0/api.js" nonce="' . $nonce . '" async defer></script>'
            . '<div class="form-group cf-turnstile" data-sitekey="' . h($siteKey) . '"></div>';
    }

    public function verifyRequest(Request $request) {
        $secret = Config::get('registration.turnstile_secret_key');
        if (!$secret || !Config::get('registration.turnstile_site_key')) {
            return true; // not configured -> failing open, logged in widgetHtml
        }
        $response = $request->str(self::FIELD);
        if ($response === '') {
            return false; // widget was rendered but no token came back: bot or JS off
        }

        $post = array('secret' => $secret, 'response' => $response);
        if (($ip = MailGuardService::clientIp())) {
            $post['remoteip'] = $ip;
        }

        try {
            $result = $this->siteverify($post);
        } catch (Throwable $e) {
            formr_log('TURNSTILE: siteverify unreachable (' . $e->getMessage() . ') — failing open.', 'MailGuard');
            return true;
        }
        $status = (int) $result['status'];
        if ($status >= 500 || $status === 0) {
            formr_log("TURNSTILE: siteverify answered HTTP {$status} — failing open.", 'MailGuard');
            return true;
        }
        $json = json_decode((string) $result['body'], true);
        if (!is_array($json) || !array_key_exists('success', $json)) {
            formr_log("TURNSTILE: siteverify answered HTTP {$status} without a verdict — failing open.", 'MailGuard');
            return true;
        }
        if (!empty($json['success'])) {
            return true;
        }
        $codes = isset($json['error-codes']) ? implode(',', (array) $json['error-codes']) : '';
        formr_log("TURNSTILE: token rejected ({$codes}).", 'MailGuard');
        return false;
    }

    /**
     * POST to siteverify. Overridable so tests can script Cloudflare's answer.
     *
     * @return array{status:int,body:string}
     * @throws Exception on transport failure
     */
    protected function siteverify(array $post) {
        $info = null;
        $body = CURL::HttpRequest(self::VERIFY_URL, http_build_query($post), CURL::HTTP_METHOD_POST, array(
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ), $info);
        return array('status' => isset($info['http_code']) ? (int) $info['http_code'] : 0, 'body' => (string) $body);
    }

}
