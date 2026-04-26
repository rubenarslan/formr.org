// PWA high-friction (enforce-phone) flow, rendered by v1 (Survey unit).
//
// NOTE: the high-friction Google Sheet
// (1F60bSMCrwleqEoz5GW7H1CJMUB3MjbAvfdiV7pvz2Tw) currently returns 401 to
// anonymous viewers. Until its link-share is set to "anyone with the link
// → Viewer", the e2e_pwa_high* studies stay seeded with the fallback
// (all_widgets) content and the request_phone-specific assertion below
// `test.skip`s cleanly. To re-seed once sharing is fixed:
//   curl -sL -b admin-cookies.txt -X POST .../e2e_pwa_high/rename_study      -F new_name=pwa_request_phone
//   curl -sL -b admin-cookies.txt -X POST .../pwa_request_phone/upload_items -F google_sheet=<url>
//   curl -sL -b admin-cookies.txt -X POST .../pwa_request_phone/rename_study -F new_name=e2e_pwa_high
// (repeat for _v2). Same shape as the low-friction reseed.

const { test, expect } = require('@playwright/test');
const { runName, participantPath } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v1 = require('./helpers/v1Form');
const pwa = require('./helpers/pwa');

const SUITE = 'pwa_high';
const VARIANT = 'v1';
const RUN = () => runName(SUITE, VARIANT);

test.describe('PWA high-friction v1', () => {
    test('manifest endpoint returns valid PWA manifest', async ({ request }) => {
        await pwa.assertManifest(request, participantPath(SUITE, VARIANT));
    });

    test('v1 form + PWA head wiring', async ({ browser }) => {
        const { context, page } = await freshParticipant(browser, RUN());
        try {
            await expect(page.locator(v1.FORM_SELECTOR).first()).toBeVisible({ timeout: 10000 });
            await pwa.assertHeadWiring(page, { runPath: participantPath(SUITE, VARIANT), expectVapid: false });
        } finally {
            await context.close();
        }
    });

    test('request_phone markup present (skipped if fixture lacks it)', async ({ browser }) => {
        const { context, page } = await freshParticipant(browser, RUN());
        try {
            await expect(page.locator(v1.FORM_SELECTOR).first()).toBeVisible({ timeout: 10000 });
            const cnt = await page.locator('.item-request_phone, .request-phone-wrapper, .browser-switch-ui').count();
            test.skip(cnt === 0, 'fixture has no request_phone item; re-run runbook with the high-friction sheet to enable');
            expect(cnt).toBeGreaterThan(0);
        } finally {
            await context.close();
        }
    });

    test('service worker activates [BS-only]', async ({ page, baseURL }, info) => {
        test.skip(pwa.isLocal(info), 'local-chromium blocks SWs (Playwright hang); SW lifecycle verified on BrowserStack');
        await page.goto(`${baseURL}${participantPath(SUITE, VARIANT)}`, { waitUntil: 'commit', timeout: 60000 });
        const state = await pwa.swActivated(page);
        expect(state).toBe('activated');
    });

    test('caches populate after first load [BS-only]', async ({ page, baseURL }, info) => {
        test.skip(pwa.isLocal(info), 'local-chromium blocks SWs');
        await page.goto(`${baseURL}${participantPath(SUITE, VARIANT)}`, { waitUntil: 'commit', timeout: 60000 });
        await page.waitForTimeout(2000);
        const keys = await pwa.cacheKeys(page);
        expect(keys.length, 'expected at least one cache after first load').toBeGreaterThan(0);
    });
});
