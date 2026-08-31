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
     * @return string
     */
    public function widgetHtml();

    /**
     * Verify the challenge response contained in the submitted request.
     *
     * @param Request $request the POSTed form
     * @return bool true = passes (human, or driver failing open)
     */
    public function verifyRequest(Request $request);

}
