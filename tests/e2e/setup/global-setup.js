// Playwright globalSetup: log into the dev admin once and save the cookie
// jar so per-test test-code minting (helpers/participant.js) doesn't have to
// re-login. The runs created by Phase 1 (`runbook.md`) are NOT public, so
// every participant request needs `?code=<test_code>` and that code is
// minted via the admin endpoint `/admin/run/<name>/create_new_test_code`.
//
// Also bootstraps PWA_TEST_CODE for the pwa-manifest + pwa-recovery suites
// so a fresh checkout doesn't skip them. Discovers (or mints, if absent) a
// testing=1 session on PWA_TEST_RUN and writes it next to the cookie jar.
// Spec files prefer process.env.PWA_TEST_CODE, falling back to that file
// via tests/e2e/helpers/pwa-test-code.js.
//
// Output: `tests/e2e/setup/admin-state.json` + `pwa-test-code.txt`
// (both gitignored).

const path = require('node:path');
const fs = require('node:fs');
const dotenv = require('dotenv');
const { chromium } = require('@playwright/test');

dotenv.config({ path: path.resolve(__dirname, '../../../../.env.dev') });

const ADMIN_URL = process.env.FORMR_DEV_URL || 'https://formr.researchmixtape.com';
const LOGIN_URL = process.env.FORMR_DEV_LOGIN_URL || `${ADMIN_URL}/admin/account/login`;
const EMAIL = process.env.FORMR_DEV_ADMIN_EMAIL;
const PASSWORD = process.env.FORMR_DEV_ADMIN_PASSWORD;
const PWA_TEST_RUN = process.env.PWA_TEST_RUN || 'e2e-pwa-h-v1';
const OUT = path.resolve(__dirname, 'admin-state.json');
const PWA_CODE_OUT = path.resolve(__dirname, 'pwa-test-code.txt');

module.exports = async () => {
    if (!EMAIL || !PASSWORD) {
        throw new Error('global-setup: FORMR_DEV_ADMIN_EMAIL/PASSWORD missing from .env.dev');
    }
    const browser = await chromium.launch();
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();

    await page.goto(LOGIN_URL, { waitUntil: 'domcontentloaded' });

    // Cookie consent dialog blocks form inputs (vanilla-cookieconsent).
    const necessary = page.locator('[data-cc="accept-necessary"]').first();
    try {
        await necessary.waitFor({ state: 'visible', timeout: 2000 });
        await necessary.click();
        await necessary.waitFor({ state: 'hidden', timeout: 2000 }).catch(() => {});
    } catch { /* dialog absent — already accepted at some prior point or not shown */ }

    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);

    // Sanity-check we're past login (redirected to /admin/ or /admin/run/).
    const url = page.url();
    if (!/\/admin\/?(\?|$|run|account|survey)/.test(url)) {
        throw new Error(`global-setup: login did not land on /admin/* (got ${url}). Check creds in .env.dev.`);
    }

    await context.storageState({ path: OUT });

    if (!fs.existsSync(OUT)) {
        await browser.close();
        throw new Error(`global-setup: failed to write ${OUT}`);
    }
    // eslint-disable-next-line no-console
    console.log(`[global-setup] admin auth saved → ${OUT}`);

    // Bootstrap PWA_TEST_CODE. Hit the admin "create test code" endpoint
    // with maxRedirects=0; the controller 302s to the participant URL
    // with ?code=<session> appended — that's our token. Always-mint
    // (rather than discover) keeps the path simple: the endpoint reuses
    // an existing testing=1 session if one exists, so we don't pile up
    // junk codes on every test run. Falls back to env var if set; skips
    // gracefully if the run doesn't exist on this dev's DB.
    if (process.env.PWA_TEST_CODE) {
        fs.writeFileSync(PWA_CODE_OUT, process.env.PWA_TEST_CODE.trim() + '\n');
        console.log(`[global-setup] PWA_TEST_CODE pinned from env → ${PWA_CODE_OUT}`);
    } else {
        try {
            const mintUrl = `${ADMIN_URL}/admin/run/${PWA_TEST_RUN}/create_new_test_code`;
            const res = await context.request.get(mintUrl, { maxRedirects: 0 });
            if (res.status() !== 302) {
                throw new Error(`expected 302, got ${res.status()}`);
            }
            const location = res.headers()['location'];
            if (!location) throw new Error('no Location header on mint redirect');
            const codeMatch = location.match(/[?&]code=([^&]+)/);
            if (!codeMatch) throw new Error(`no ?code= in Location: ${location}`);
            const code = decodeURIComponent(codeMatch[1]);
            fs.writeFileSync(PWA_CODE_OUT, code + '\n');
            console.log(`[global-setup] PWA_TEST_CODE minted on ${PWA_TEST_RUN} → ${PWA_CODE_OUT}`);
        } catch (err) {
            // Don't fail the whole suite — pwa-manifest / pwa-recovery
            // skip themselves when no code is present.
            if (fs.existsSync(PWA_CODE_OUT)) fs.unlinkSync(PWA_CODE_OUT);
            console.warn(`[global-setup] PWA_TEST_CODE bootstrap failed for run '${PWA_TEST_RUN}': ${err.message}`);
            console.warn(`[global-setup] pwa-manifest + pwa-recovery suites will skip. Create the run or set PWA_TEST_RUN/PWA_TEST_CODE.`);
        }
    }

    await browser.close();
};
