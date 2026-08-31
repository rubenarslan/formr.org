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
 * Fails open (with a log line) on missing keys or siteverify outage.
 */
class TurnstileChallenge implements RegistrationChallenge {

    const FIELD = 'cf-turnstile-response';
    const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function widgetHtml() {
        $siteKey = Config::get('registration.turnstile_site_key');
        if (!$siteKey || !Config::get('registration.turnstile_secret_key')) {
            formr_log('TURNSTILE: site/secret key not configured — challenge disabled (failing open).', 'MailGuard');
            return '';
        }
        return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'
            . '<div class="form-group cf-turnstile" data-sitekey="' . h($siteKey) . '"></div>';
    }

    public function verifyRequest(Request $request) {
        $secret = Config::get('registration.turnstile_secret_key');
        if (!$secret || !Config::get('registration.turnstile_site_key')) {
            return true; // not configured -> failing open, logged in widgetHtml
        }
        $response = $request->str(self::FIELD);
        if (!$response) {
            return false; // widget was rendered but no token came back: bot or JS off
        }

        $post = array('secret' => $secret, 'response' => $response);
        if (($ip = MailGuardService::clientIp())) {
            $post['remoteip'] = $ip;
        }
        $ch = curl_init(self::VERIFY_URL);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ));
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            formr_log("TURNSTILE: siteverify unreachable ({$err}) — failing open.", 'MailGuard');
            return true;
        }
        $json = json_decode($body, true);
        return is_array($json) && !empty($json['success']);
    }

}
