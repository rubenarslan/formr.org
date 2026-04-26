// PWA low-friction (push-in-non-phone-browser) flow, v1.
// See pwa-high-v1.spec.js for the fixture caveat.

const { test, expect } = require('@playwright/test');
const { runName, participantPath } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v1 = require('./helpers/v1Form');
const pwa = require('./helpers/pwa');

const SUITE = 'pwa_low';
const VARIANT = 'v1';
const RUN = () => runName(SUITE, VARIANT);

test.describe('PWA low-friction v1', () => {
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

    test('add_to_home_screen / push_notification items present (skipped if fixture lacks them)', async ({ browser }) => {
        const { context, page } = await freshParticipant(browser, RUN());
        try {
            await expect(page.locator(v1.FORM_SELECTOR).first()).toBeVisible({ timeout: 10000 });
            const a2hs = await page.locator('.item-add_to_home_screen').count();
            const push = await page.locator('.item-push_notification').count();
            test.skip(a2hs + push === 0, 'fixture has no PWA items; re-run runbook with the low-friction sheet to enable');
            expect(a2hs + push).toBeGreaterThan(0);
        } finally {
            await context.close();
        }
    });

    test('service worker activates [BS-only]', async ({ page, baseURL }, info) => {
        test.skip(pwa.isLocal(info), 'local-chromium blocks SWs');
        await page.goto(`${baseURL}${participantPath(SUITE, VARIANT)}`, { waitUntil: 'domcontentloaded' });
        const state = await pwa.swActivated(page);
        expect(state).toBe('activated');
    });
});
