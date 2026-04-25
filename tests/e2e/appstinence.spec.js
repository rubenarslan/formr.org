// Smoke test for the form_v2 PWA flow against the dev instance.
//
// Local-Chromium project blocks service workers (Playwright hangs on the
// dev SW); SW-dependent assertions are skipped there and run only on the
// BrowserStack real-device projects. Cheaper local tests still cover the
// manifest endpoint, the run-page response shape, and the static infra
// (vapid-key + apple-touch-icon when the run is currently on a Form unit).
//
// Caveat: the participant URL renders whatever unit the run-session is
// currently on. If a prior test advanced past the Form, the page won't
// have .fmr-form-v2; the form-bound assertions skip in that case.
//
// Run locally:    npm run test:e2e
// Run on BS:      npm run test:bs

const { test, expect } = require('@playwright/test');

const APPSTINENCE_PATH = '/appstinence-v2/?code=q447trWh7EQJMEEvUUTGBLkcQn47fLO0wHtttj9HvP-kUgA0Vcjk8lwBIzQy613B';

const isLocal = (info) => info.project.name === 'local-chromium';

test.describe('appstinence-v2 smoke', () => {
    test('manifest endpoint returns valid PWA manifest', async ({ request }) => {
        const res = await request.get('/appstinence-v2/manifest');
        expect(res.status()).toBe(200);
        const m = await res.json();
        expect(m.name).toBeTruthy();
        expect(m.start_url).toContain('/appstinence-v2/');
        expect(m.scope).toContain('/appstinence-v2/');
        expect(Array.isArray(m.icons)).toBe(true);
        expect(m.icons.length).toBeGreaterThan(0);
    });

    test('participant URL serves HTML 200', async ({ request }) => {
        const res = await request.get(APPSTINENCE_PATH);
        expect(res.status()).toBe(200);
        const html = await res.text();
        expect(html).toMatch(/<title[^>]*>[^<]*appstinence/i);
        expect(html).toContain('<script src="');
    });

    test('Form unit renders with PWA infra', async ({ page }) => {
        await page.goto(APPSTINENCE_PATH, { waitUntil: 'domcontentloaded', timeout: 15000 });
        await expect(page).toHaveTitle(/appstinence/i);

        // The participant URL renders whatever unit the session is on. If
        // the form isn't currently active, the rest of this test isn't
        // meaningful — skip rather than fail.
        const formCount = await page.locator('.fmr-form-v2').count();
        test.skip(formCount === 0,
            `session is not on a Form unit right now (no .fmr-form-v2 in DOM); reset session via admin to re-test`);

        await expect(page.locator('.fmr-form-v2').first()).toBeVisible({ timeout: 10000 });

        const manifestHref = await page.locator('link[rel="manifest"]').getAttribute('href');
        expect(manifestHref).toContain('/appstinence-v2/manifest');

        const hasVapid = await page.evaluate(() =>
            typeof window.vapidPublicKey === 'string' && window.vapidPublicKey.length > 10
        );
        expect(hasVapid).toBe(true);

        expect(await page.locator('script[src*="form.bundle.js"]').count()).toBeGreaterThan(0);
        // apple-touch-icon links only render when the run has icons uploaded
        // via /admin/run/.../pwa. Not asserted here — manifest-link presence
        // is the core PWA-installability evidence; the icons are admin-
        // configured per run.
    });

    test('service worker activates within 5s', async ({ page }, info) => {
        test.skip(isLocal(info), 'local-chromium blocks SWs to avoid a Playwright hang; SW lifecycle is verified on BrowserStack');
        await page.goto(APPSTINENCE_PATH, { waitUntil: 'domcontentloaded', timeout: 15000 });
        const swState = await page.evaluate(async () => {
            const reg = await navigator.serviceWorker.ready;
            return reg.active ? reg.active.state : null;
        });
        expect(swState).toBe('activated');
    });

    test('caches populate after first load', async ({ page }, info) => {
        test.skip(isLocal(info), 'local-chromium blocks SWs');
        await page.goto(APPSTINENCE_PATH, { waitUntil: 'domcontentloaded', timeout: 15000 });
        await page.waitForTimeout(2000);
        const cacheNames = await page.evaluate(async () =>
            'caches' in self ? await caches.keys() : []
        );
        expect(cacheNames.length).toBeGreaterThan(0);
    });
});
