// The cookie-consent banner (vanilla-cookieconsent). No test-fixture imports,
// so global setup can use it too.

async function acceptCookieConsent(page) {
    // formr's vanilla-cookieconsent renders #cc-main with `.cm__btn` buttons
    // carrying `data-role`: "all" (accept all), "necessary" (necessary-only /
    // "Do not allow"), "show" (manage). NOTE: this previously matched
    // `data-cc="accept-necessary"` — which the markup never emits — so the
    // banner was never dismissed and could overlay the form (especially on slow
    // real devices where it appears after the 2s window). CAVEAT: the selector
    // list below resolves by DOM order, and accept-all renders BEFORE
    // necessary in the banner — so in practice this grants FULL consent
    // (functionality included), not the minimal consent the original intent
    // stated. Specs that need a consent-free participant (request_cookie's
    // showPreferences wiring) must pass acceptCookies: false instead of
    // relying on necessary-only. Generous timeout: on BrowserStack the
    // banner can take several seconds to render.
    // Scope to `.cm__btn` (the consent banner) — `[data-role]` also matches the
    // hidden `.pm__btn` preferences-modal buttons, and .first() can pick an
    // invisible one (the click then times out).
    const btn = page.locator('#cc-main .cm__btn[data-role="necessary"], #cc-main .cm__btn[data-role="all"]').first();
    try {
        await btn.waitFor({ state: 'visible', timeout: 8000 });
        await btn.click();
        await btn.waitFor({ state: 'hidden', timeout: 3000 }).catch(() => {});
    } catch {
        // Banner didn't appear in the window — already dismissed or not shown here.
    }
}

module.exports = { acceptCookieConsent };
