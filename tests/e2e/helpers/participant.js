// Participant navigation helper.
//
// All e2e_* runs are publicly accessible (run.public = 2). Tests just open
// `https://study.researchmixtape.com/<runName>/` directly — formr's
// `loginUser()` issues a fresh user_code per request, so each new browser
// context gets an isolated participant session without any test_code
// minting or admin auth.
//
// Two call shapes:
//   - `freshParticipant(page, runName)` — preferred. Uses the test's `page`
//     fixture directly. REQUIRED on BrowserStack: the SDK provides one
//     device per test session, and `browser.newContext()` either fails or
//     returns about:blank because the BS bridge is single-context.
//   - `freshParticipant(browser, runName)` — legacy. On local-chromium,
//     creates a fresh context via `browser.newContext()` so tests don't
//     share state. Returns `{ context, page, url }`. Will fail on BS —
//     migrate to the page-fixture form before running on real devices.
//
// Under BS the `page` fixture is worker-scoped (helpers/test.js) so the
// SAME page survives across tests. Cookies and storage from a prior test
// would otherwise leak into the next one's user_code lookup. We clear
// them here before navigating, so each call still mints a fresh
// participant from formr's perspective.

const { clearBrowserState } = require('./test');

async function freshParticipant(arg, runName, { acceptCookies = true, baseURL } = {}) {
    const path = `/${encodeURIComponent(runName)}/`;
    const url = baseURL ? baseURL.replace(/\/+$/, '') + path : path;

    // Detect: is `arg` a Page (has .goto) or a Browser (has .newContext)?
    let context = null;
    let page;
    if (arg && typeof arg.goto === 'function') {
        page = arg; // Page fixture — use directly.
    } else if (arg && typeof arg.newContext === 'function') {
        context = await arg.newContext({ ignoreHTTPSErrors: true });
        page = await context.newPage();
    } else {
        throw new Error('freshParticipant: first arg must be a Playwright Page or Browser');
    }

    // BS reuses a single context across tests (worker-scoped fixture).
    // Wipe cookies + storage before navigating so each test starts as a
    // brand-new participant and not as the previous test's user_code.
    await clearBrowserState(page);

    // `commit` (not `domcontentloaded`) — iPhone Safari on BS times out
    // waiting for DCL on the form page; commit fires once bytes arrive.
    await page.goto(url, { waitUntil: 'commit', timeout: 60000 });
    if (acceptCookies) {
        await acceptCookieConsent(page);
    }
    // Return shape mirrors the caller: page-fixture callers don't get a
    // context (it's the test's, not ours to close); browser-callers do.
    return context ? { context, page, url } : { page, url };
}

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

module.exports = { freshParticipant, acceptCookieConsent };
