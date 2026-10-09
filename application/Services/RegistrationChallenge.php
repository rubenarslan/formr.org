<?php

/**
 * Pluggable anti-bot challenge for the registration form, selected by
 * registration.challenge ('altcha' | 'turnstile'), off by default.
 * Drivers must fail OPEN on their own misconfiguration or upstream outage —
 * a broken challenge must never lock every human out of signing up — and
 * log when they do.
 */
interface RegistrationChallenge {

    /**
     * HTML to render inside the registration <form>: the widget plus whatever
     * script/style tags it needs. Must be safe to echo as-is.
     *
     * @param string $cspNonce the nonce of the response being rendered — the
     *   controller's Site, never Site::getInstance(): index.php restores the
     *   Site from the session without registering it as the singleton, so on
     *   every request after a session's first, getInstance() is a second
     *   object whose nonce is not the one in the CSP header, and any inline
     *   script tagged with it is blocked.
     * @return string
     */
    public function widgetHtml($cspNonce);

    /**
     * Verify the challenge response contained in the submitted request.
     *
     * @param Request $request the POSTed form
     * @return bool true = passes (human, or driver failing open)
     */
    public function verifyRequest(Request $request);

}
