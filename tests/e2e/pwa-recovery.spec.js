// PWA recovery flows end-to-end against the dev instance.
//
// Covers the migration & worst-case-recovery commits in Phase 3:
//   - c987bd27 cookie self-heal 302 redirect from bare URL
//   - bc66181f server-side recovery page (when _pwa=true)
//   - 2a6b6661 client-side recovery banner (covers the cold-launch
//     case where _pwa= isn't in the URL yet)
//   - 697284b2 pattern attribute derived from configured regex
//
// Standalone-mode emulation: pwa-register.js gates the banner on
// `matchMedia('(display-mode: standalone)').matches`. Real iOS sets
// this when launched from a home-screen icon; in headless Chromium we
// override matchMedia via addInitScript so the banner code path runs
// the same way it would on a captured-icon cold launch.
//
// Service workers are blocked in playwright.config.js for local
// chromium (the dev SW hangs Playwright's CDP target), so these
// tests only verify the page-side branches, not the SW. Real-iOS
// verification of the captured-icon launch stays manual — see the
// commit-message notes on that limitation.

const { test, expect } = require('./helpers/test');

// See pwa-manifest.spec.js for the e2e-pwa-h-v1 fixture rationale.
// In short: we need a run whose bare URL renders the public/head
// template (so vapidPublicKey is set and pwa-register.js loads).
const RUN = process.env.PWA_TEST_RUN || 'e2e-pwa-h-v1';
const CODE = process.env.PWA_TEST_CODE;

// Override matchMedia so pwa-register.js sees `display-mode: standalone`
// regardless of the actual browser context. We also flip
// navigator.standalone for the iOS-shaped check that pwa-register.js
// also consults.
const STANDALONE_INIT = `
    (() => {
        try {
            const orig = window.matchMedia ? window.matchMedia.bind(window) : null;
            window.matchMedia = (q) => {
                if (typeof q === 'string' && q.includes('display-mode: standalone')) {
                    return {
                        matches: true,
                        media: q,
                        addEventListener: () => {},
                        removeEventListener: () => {},
                        addListener: () => {},
                        removeListener: () => {},
                        dispatchEvent: () => false,
                    };
                }
                return orig ? orig(q) : { matches: false, media: q, addEventListener:()=>{}, removeEventListener:()=>{}, addListener:()=>{}, removeListener:()=>{}, dispatchEvent:()=>false };
            };
            try { Object.defineProperty(window.navigator, 'standalone', { configurable: true, get: () => true }); } catch (e) {}
        } catch (e) {}
    })();
`;

test.describe('PWA recovery flows', () => {
    test.skip(!CODE, 'PWA_TEST_CODE env var not set; skipping recovery suite');

    test('cookie self-heal redirects bare URL to ?code= when cookie identifies a participant in this run', async ({ page, context, baseURL }) => {
        // Establish the cookie via a tokenized visit first.
        await page.goto(`${baseURL}/${RUN}/?code=${CODE}`, { waitUntil: 'commit', timeout: 60000 });

        // Now navigate to the bare URL — server should 302 to ?code=...
        const responses = [];
        page.on('response', (r) => responses.push({ url: r.url(), status: r.status() }));
        await page.goto(`${baseURL}/${RUN}/`, { waitUntil: 'commit', timeout: 60000 });

        // The URL bar after redirects should carry ?code=.
        expect(page.url()).toContain(`?code=${CODE}`);

        // And there should have been at least one 302 in the response chain.
        const sawRedirect = responses.some((r) => r.status === 302 && r.url.includes(`/${RUN}`));
        expect(sawRedirect, 'expected a 302 redirect to the tokenized URL').toBe(true);
    });

    test('recovery banner appears in standalone-emulated bare URL with no recoverable cookie', async ({ page, context, baseURL }) => {
        await context.clearCookies();
        await page.addInitScript(STANDALONE_INIT);

        await page.goto(`${baseURL}/${RUN}/`, { waitUntil: 'load', timeout: 60000 });

        // Wait briefly for the bundle to evaluate; the banner injects
        // synchronously when readyState >= interactive, or on
        // DOMContentLoaded otherwise.
        await page.waitForTimeout(1500);

        // Diagnostic snapshot of the gates pwa-register.js consults.
        // Asserted into the failure path so a missing banner shows
        // exactly which gate isn't satisfied.
        const diag = await page.evaluate(() => ({
            standalone: window.matchMedia('(display-mode: standalone)').matches,
            navigatorStandalone: !!window.navigator.standalone,
            hasSwApi: 'serviceWorker' in navigator,
            vapidPublicKey: typeof window.vapidPublicKey === 'string',
            formr_run_url: window.formr && window.formr.run_url || null,
            url: window.location.href,
            urlHasCode: !!new URLSearchParams(window.location.search).get('code'),
            readyState: document.readyState,
            bannerInDom: !!document.getElementById('fmr-pwa-recovery-banner'),
        }));

        const banner = page.locator('#fmr-pwa-recovery-banner');
        await expect(banner, `pwa-register.js should inject the recovery banner when standalone + no code in URL. diag=${JSON.stringify(diag)}`).toBeVisible({ timeout: 5000 });

        // Banner has the expected fields the participant uses to recover.
        await expect(banner.locator('input[name="code"]')).toBeVisible();
        await expect(banner.locator('button[type="submit"]')).toBeVisible();
    });

    test('recovery banner pattern attribute is derived from the configured user_code_regular_expression', async ({ page, context, baseURL }) => {
        await context.clearCookies();
        await page.addInitScript(STANDALONE_INIT);
        await page.goto(`${baseURL}/${RUN}/`, { waitUntil: 'load', timeout: 60000 });

        const pattern = await page.locator('#fmr-pwa-recovery-banner input[name="code"]').getAttribute('pattern');
        // Default settings.php ships /^[A-Za-z0-9+-_~]{64}$/. The
        // commit derives pattern attribute from this and exposes it
        // via window.formr.user_code_pattern. If a deployment
        // customizes the regex we just want SOMETHING here, not the
        // hardcoded fallback we removed.
        expect(pattern, 'banner should pick up window.formr.user_code_pattern from Controller::getJsConfig').toBeTruthy();
        // The default config contains {64}; assert that as a smoke
        // for the default-deployment case.
        expect(pattern).toMatch(/\{64\}/);
    });

    test('recovery banner submission redirects with ?code=', async ({ page, context, baseURL }) => {
        await context.clearCookies();
        await page.addInitScript(STANDALONE_INIT);
        await page.goto(`${baseURL}/${RUN}/`, { waitUntil: 'load', timeout: 60000 });

        await expect(page.locator('#fmr-pwa-recovery-banner')).toBeVisible({ timeout: 15000 });
        await page.locator('#fmr-pwa-recovery-banner input[name="code"]').fill(CODE);

        await Promise.all([
            page.waitForURL((url) => url.toString().includes(`code=${CODE}`), { timeout: 30000 }),
            page.locator('#fmr-pwa-recovery-banner button[type="submit"]').click(),
        ]);

        expect(page.url()).toContain(`code=${CODE}`);
    });

    test('tokenized URL does not show the recovery banner', async ({ page, context, baseURL }) => {
        await context.clearCookies();
        await page.addInitScript(STANDALONE_INIT);
        await page.goto(`${baseURL}/${RUN}/?code=${CODE}`, { waitUntil: 'load', timeout: 60000 });

        // pwa-register.js gates the banner on URLSearchParams(...).get('code'),
        // which IS truthy here, so the DOMContentLoaded handler should never
        // inject. Wait long enough for it to have had a chance.
        await page.waitForTimeout(1500);
        await expect(page.locator('#fmr-pwa-recovery-banner')).toHaveCount(0);
    });

    test('banner is dismissible', async ({ page, context, baseURL }) => {
        await context.clearCookies();
        await page.addInitScript(STANDALONE_INIT);
        await page.goto(`${baseURL}/${RUN}/`, { waitUntil: 'load', timeout: 60000 });

        const banner = page.locator('#fmr-pwa-recovery-banner');
        await expect(banner).toBeVisible({ timeout: 15000 });
        await banner.locator('[data-dismiss]').click();
        await expect(banner).toHaveCount(0);
    });
});
