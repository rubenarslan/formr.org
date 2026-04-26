// Fresh participant helpers.
//
// Runs created by the Phase 1 setup are NOT public (no privacy policy /
// expiry configured), so participant URLs need a `?code=<test_code>` minted
// via the admin endpoint `/admin/run/<name>/create_new_test_code`. The
// global-setup hook stashes admin auth in `tests/e2e/setup/admin-state.json`;
// each call to `freshParticipant` mints a fresh test code, then opens a
// clean (no admin cookies) participant context.

const path = require('node:path');
const fs = require('node:fs');
const dotenv = require('dotenv');

dotenv.config({ path: path.resolve(__dirname, '../../../../.env.dev') });

const ADMIN_BASE = process.env.FORMR_DEV_URL || 'https://formr.researchmixtape.com';
const ADMIN_STATE = path.resolve(__dirname, '../setup/admin-state.json');

// Mint a fresh test_code session for `runName` and return the participant
// URL (`https://study.../<runName>/?code=...`). Uses a one-shot admin
// context loaded from storageState so we don't hit the login page per test.
async function mintTestCode(browser, runName) {
    if (!fs.existsSync(ADMIN_STATE)) {
        throw new Error(
            `${ADMIN_STATE} missing — globalSetup hasn't run. ` +
            `Either run "npm run test:e2e" (which triggers globalSetup) ` +
            `or invoke the setup script directly.`,
        );
    }
    const adminCtx = await browser.newContext({
        storageState: ADMIN_STATE,
        ignoreHTTPSErrors: true,
    });
    const adminPage = await adminCtx.newPage();
    const url = `${ADMIN_BASE}/admin/run/${encodeURIComponent(runName)}/create_new_test_code`;
    try {
        const resp = await adminPage.goto(url, { waitUntil: 'domcontentloaded' });
        if (!resp || resp.status() >= 400) {
            throw new Error(`mintTestCode: admin endpoint returned ${resp ? resp.status() : 'no response'} for ${url}`);
        }
        // The endpoint 302s to study.../<run>/?code=<test_code>. Playwright
        // follows it; the final URL is on the participant origin.
        const finalUrl = adminPage.url();
        if (!/[?&]code=/.test(finalUrl)) {
            throw new Error(`mintTestCode: final URL has no ?code= — admin not logged in? Got ${finalUrl}`);
        }
        return finalUrl;
    } finally {
        await adminCtx.close();
    }
}

async function freshParticipant(browser, runName, { acceptCookies = true } = {}) {
    // Try admin-mint first — works on local-chromium and on any non-public
    // run. Fall back to bare navigation when BS rejects the storageState
    // option (BS Real Mobile doesn't support `browser.newContext({
    // storageState })`) or when the admin-state isn't loaded — that's the
    // public-run path.
    let url;
    try {
        url = await mintTestCode(browser, runName);
    } catch (e) {
        const msg = String(e && e.message || e);
        const bsLimitation = msg.includes('browserstack_error') || msg.includes('storageState');
        if (!bsLimitation && !msg.includes(ADMIN_STATE)) throw e;
        // eslint-disable-next-line no-console
        console.warn(`mintTestCode failed (${bsLimitation ? 'BS storageState limitation' : 'no admin-state'}); falling back to bare /${runName}/ — assumes the run is publicly accessible.`);
        url = `/${encodeURIComponent(runName)}/`;
    }
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();
    // BS iPhone Safari times out on `domcontentloaded` for the form pages;
    // `commit` is the safer signal — page bytes have arrived, JS will run.
    await page.goto(url, { waitUntil: 'commit', timeout: 60000 });
    if (acceptCookies) {
        await acceptCookieConsent(page);
    }
    return { context, page, url };
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

module.exports = { freshParticipant, acceptCookieConsent, mintTestCode };
