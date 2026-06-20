// Regression guard for the v1.3.0 CSP-externalization bug where the superadmin
// User Management page lost its `saAjaxUrl` global (fixed on release/1.3.1; the
// original externalization was b003d248).
//
// The four row actions (.api-btn / .del-btn / .verify-email-btn /
// .add-email-btn) are bound in the admin bundle (common/js/run_users.js) but
// read the AJAX endpoint from a GLOBAL `saAjaxUrl`. That global used to be set
// by an inline <script> on the page; externalizing the page's behaviour for
// CSP dropped it, so every one of those clicks threw "saAjaxUrl is not defined"
// and silently did nothing (only Reset 2FA, which had been migrated to a local
// var, still worked).
//
// Why the CSP violation crawler (csp-crawl.spec.js) cannot catch this:
//   1. it only GET-renders pages — it never clicks, so a ReferenceError thrown
//      inside a click handler never fires; and
//   2. it runs as a non-superadmin, so /admin/advanced/user_management 403s and
//      is skipped entirely.
// Hence this dedicated, interaction-level guard.
//
//   npx playwright test --config tests/e2e/playwright.config.js user-management-actions
//
// Requires the configured admin account (.env.dev) to be SUPERADMIN. When it is
// not, the page 403s and the test self-skips — documenting the blind spot
// rather than failing in CI.

const { test, expect } = require('@playwright/test');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');

test.use({ storageState: STATE_PATH });

test.beforeAll(async ({ browser }) => {
    await ensureAdminState(browser);
});

test('superadmin user-management row actions are wired (saAjaxUrl global present)', async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', (e) => pageErrors.push(String(e)));

    await page.goto(ADMIN_BASE + '/admin/advanced/user_management', { waitUntil: 'domcontentloaded' });

    // Non-superadmin -> 403 / redirect, no user-management container. Skip, which
    // is exactly the crawler's blind spot — not a failure.
    const onSuperadminPage = await page.locator('#user-management-page').count();
    test.skip(!onSuperadminPage, 'configured account is not superadmin; user_management 403s (crawler blind spot)');

    // 1) the global the admin bundle's row-action handlers read must be defined
    const saAjaxUrl = await page.evaluate(() => window.saAjaxUrl);
    expect(saAjaxUrl, 'window.saAjaxUrl must be defined for run_users.js row actions').toBeTruthy();

    // 2) .api-btn uses saAjaxUrl immediately on click: it must not throw and must
    //    open the API Access modal via the real ajax_admin round-trip (read-only)
    await page.locator('.api-btn').first().click();
    await expect(page.locator('.modal:visible')).toContainText('API Access', { timeout: 5000 });

    // 3) the regression's signature error must be absent
    expect(
        pageErrors.filter((e) => /saAjaxUrl/.test(e)),
        'no "saAjaxUrl is not defined" ReferenceError on row-action click'
    ).toEqual([]);
});
