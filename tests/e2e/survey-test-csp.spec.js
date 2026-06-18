// Regression: the "Test Survey" flow must render the participant survey
// OUTSIDE /admin/, so it carries neither the admin CSP (which blocks v1's
// new Function() live showif) nor the /admin/-scoped admin session cookie.
//
//   npx playwright test --config tests/e2e/playwright.config.js survey-test-csp
//
// Before the fix, /admin/survey/<name>/access redirected to
// /admin/survey/<name>/test_run/ — an admin-area page that inherits the
// enforce-mode CSP (script-src without 'unsafe-eval'), breaking live showif.
// After the fix it redirects to a token-gated /survey-test/<token> render,
// which is not in the admin area and gets no CSP. This test is RED on the old
// behavior (URL under /admin/, CSP header present) and GREEN on the new one.

const { test, expect } = require('./helpers/test');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');

test.use({ storageState: STATE_PATH });

test.describe('survey test render escapes the admin CSP', () => {
    test.beforeAll(async ({ browser }) => { await ensureAdminState(browser); });

    test('Test Survey lands off /admin/ with no CSP header', async ({ page }) => {
        // A discovered survey may carry author JS (e.g. alert()); never let a
        // dialog hang the navigation.
        page.on('dialog', (d) => d.dismiss().catch(() => {}));

        // Discover any existing survey name from the list (read-only).
        await page.goto(ADMIN_BASE + '/admin/survey', { waitUntil: 'domcontentloaded', timeout: 30000 });
        const name = await page.$$eval('a[href]', (as) => {
            for (const a of as) {
                const m = (a.getAttribute('href') || '').match(/\/admin\/survey\/([A-Za-z][A-Za-z0-9_]{2,64})(?:[\/?#]|$)/);
                if (m && !['add_survey', 'list'].includes(m[1])) return m[1];
            }
            return null;
        });
        test.skip(!name, 'no survey available on this instance to test');

        // Follow the "Test Survey" entry point; goto resolves to the final
        // (post-redirect) response.
        const resp = await page.goto(ADMIN_BASE + '/admin/survey/' + name + '/access',
            { waitUntil: 'domcontentloaded', timeout: 30000 });

        // 1. Renders on the token preview path, not the old admin test_run.
        expect(page.url(), 'survey test must render outside /admin/').toContain('/survey-test/');
        expect(page.url()).not.toContain('/admin/');

        // 2. The rendered page carries NO admin CSP (so new Function() showif works).
        const csp = resp.headers()['content-security-policy'];
        expect(csp, 'survey-test render must not carry the admin CSP').toBeFalsy();
    });
});
