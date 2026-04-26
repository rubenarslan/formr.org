// PWA high-friction (enforce-phone) flow, rendered by v2 (Form unit).
// See pwa-high-v1.spec.js for the fixture caveat.

const { test, expect } = require('@playwright/test');
const { runName, participantPath } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const pwa = require('./helpers/pwa');

const SUITE = 'pwa_high';
const VARIANT = 'v2';
const RUN = () => runName(SUITE, VARIANT);

test.describe('PWA high-friction v2', () => {
    test('manifest endpoint returns valid PWA manifest', async ({ request }) => {
        await pwa.assertManifest(request, participantPath(SUITE, VARIANT));
    });

    test('v2 form + PWA head wiring', async ({ browser }) => {
        const { context, page } = await freshParticipant(browser, RUN());
        try {
            await expect(page.locator(v2.FORM_SELECTOR).first()).toBeVisible({ timeout: 10000 });
            await v2.waitForBundle(page);
            await pwa.assertHeadWiring(page, { runPath: participantPath(SUITE, VARIANT), expectVapid: false });
            expect(await page.locator('script[src*="form.bundle.js"]').count()).toBeGreaterThan(0);
        } finally {
            await context.close();
        }
    });

    test('request_phone markup present (skipped if fixture lacks it)', async ({ browser }) => {
        const { context, page } = await freshParticipant(browser, RUN());
        try {
            await expect(page.locator(v2.FORM_SELECTOR).first()).toBeVisible({ timeout: 10000 });
            await v2.waitForBundle(page);
            const cnt = await page.locator('.item-request_phone, .request-phone-wrapper, .browser-switch-ui').count();
            test.skip(cnt === 0, 'fixture has no request_phone item; re-run runbook with the high-friction sheet to enable');
            expect(cnt).toBeGreaterThan(0);
        } finally {
            await context.close();
        }
    });

    test('service worker activates [BS-only]', async ({ page, baseURL }, info) => {
        test.skip(pwa.isLocal(info), 'local-chromium blocks SWs');
        await page.goto(`${baseURL}${participantPath(SUITE, VARIANT)}`, { waitUntil: 'commit', timeout: 60000 });
        const state = await pwa.swActivated(page);
        if (state !== 'activated') console.error('SW failed; diag:', JSON.stringify(await pwa.swDiagnostics(page)));
        expect(state).toBe('activated');
    });

    test('caches populate after first load [BS-only]', async ({ page, baseURL }, info) => {
        test.skip(pwa.isLocal(info), 'local-chromium blocks SWs');
        await page.goto(`${baseURL}${participantPath(SUITE, VARIANT)}`, { waitUntil: 'commit', timeout: 60000 });
        await page.waitForTimeout(8000);
        const keys = await pwa.cacheKeys(page);
        expect(keys.length).toBeGreaterThan(0);
    });
});
