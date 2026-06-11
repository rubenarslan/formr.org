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
    // vanilla-cookieconsent renders #cc-main; the action buttons have
    // `data-cc="accept-all"` / `data-cc="accept-necessary"`. Either is fine
    // — accept-necessary keeps it minimal. The dialog only appears once per
    // context, so a missing dialog after an early dismissal is normal.
    const necessary = page.locator('[data-cc="accept-necessary"]').first();
    try {
        await necessary.waitFor({ state: 'visible', timeout: 2000 });
        await necessary.click();
        await necessary.waitFor({ state: 'hidden', timeout: 2000 }).catch(() => {});
    } catch {
        // Dialog not shown — already dismissed or not applicable to this page.
    }
}

module.exports = { freshParticipant, acceptCookieConsent };
