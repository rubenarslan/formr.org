// Playwright globalSetup: log into the dev admin once and save the cookie
// jar so per-test test-code minting (helpers/participant.js) doesn't have to
// re-login. The runs created by Phase 1 (`runbook.md`) are NOT public, so
// every participant request needs `?code=<test_code>` and that code is
// minted via the admin endpoint `/admin/run/<name>/create_new_test_code`.
//
// Output: `tests/e2e/setup/admin-state.json` (gitignored).

const path = require('node:path');
const fs = require('node:fs');
const dotenv = require('dotenv');
const { chromium } = require('@playwright/test');

dotenv.config({ path: path.resolve(__dirname, '../../../../.env.dev') });

const ADMIN_URL = process.env.FORMR_DEV_URL || 'https://formr.researchmixtape.com';
const LOGIN_URL = process.env.FORMR_DEV_LOGIN_URL || `${ADMIN_URL}/admin/account/login`;
const EMAIL = process.env.FORMR_DEV_ADMIN_EMAIL;
const PASSWORD = process.env.FORMR_DEV_ADMIN_PASSWORD;
const OUT = path.resolve(__dirname, 'admin-state.json');

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
    await browser.close();

    if (!fs.existsSync(OUT)) {
        throw new Error(`global-setup: failed to write ${OUT}`);
    }
    // eslint-disable-next-line no-console
    console.log(`[global-setup] admin auth saved → ${OUT}`);
};
