// PWA low-friction (push-in-non-phone-browser) flow, v2.
// See pwa-high-v1.spec.js for the fixture caveat.

const { test, expect } = require('@playwright/test');
const { runName, participantPath } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const pwa = require('./helpers/pwa');
const { fillAllVisible } = require('./helpers/widgets');

const SUITE = 'pwa_low';
const VARIANT = 'v2';
const RUN = () => runName(SUITE, VARIANT);

test.describe('PWA low-friction v2', () => {
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
            const f = v2.form(page);
            expect(await f.getAttribute('data-sync-url')).toMatch(/\/form-sync\/?$/);
        } finally {
            await context.close();
        }
    });

    test('add_to_home_screen / push_notification items present (skipped if fixture lacks them)', async ({ browser }) => {
        const { context, page } = await freshParticipant(browser, RUN());
        try {
            await expect(page.locator(v2.FORM_SELECTOR).first()).toBeVisible({ timeout: 10000 });
            await v2.waitForBundle(page);
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
        await page.goto(`${baseURL}${participantPath(SUITE, VARIANT)}`, { waitUntil: 'commit', timeout: 60000 });
        const state = await pwa.swActivated(page);
        expect(state).toBe('activated');
    });

    test('offline submit queues and drains on reconnect [BS-only]', async ({ browser }, info) => {
        test.skip(pwa.isLocal(info), 'local-chromium blocks SWs (offline-queue ride-along)');
        const { context, page } = await freshParticipant(browser, RUN());
        try {
            await v2.waitForBundle(page);
            await fillAllVisible(page, page.locator('section.fmr-page:not([hidden])'));
            await context.setOffline(true);
            await page.locator(`${v2.FORM_SELECTOR} section.fmr-page:not([hidden]) [data-fmr-next]`).first().click();
            await expect(page.locator('.fmr-offline-banner, [data-fmr-queued], .alert:has-text("queued")').first())
                .toBeVisible({ timeout: 5000 });
            await context.setOffline(false);
            await page.waitForResponse(
                (resp) => /\/form-sync$/.test(new URL(resp.url()).pathname) && resp.status() === 200,
                { timeout: 10000 },
            );
        } finally {
            await context.close();
        }
    });
});
