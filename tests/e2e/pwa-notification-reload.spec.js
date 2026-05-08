// Pin the SW->page reload contract that backs the "click a push notification
// and the open PWA page should reflect the new server-side state" flow.
//
// The page-side handler in PWAInstaller.js (`reload_invalidated` + the
// `navigator.serviceWorker` 'message' listener) is what closes the loop —
// the SW posts {type:'NOTIFICATION_CLICK',action:'reload',timestamp} (on
// notificationclick) or {type:'STATE_INVALIDATED',...} (on push receive),
// and the page must reload in response. We exercise that listener with a
// synthetic MessageEvent dispatched on `navigator.serviceWorker` rather
// than booting a real SW, because Playwright's local-Chromium project
// blocks SW registration (see playwright.config.js: serviceWorkers='block'
// hangs on the dev SW). On BS this same dispatch lands on a real iOS
// Safari WebKit listener — that's the iOS-specific regression guard for
// the reload-technique fix (window.location.reload() instead of the
// href self-assign no-op).
//
// What this test pins (on BOTH local-chromium and BS iOS Safari):
//   - the listener handles both message shapes
//   - reload_invalidated triggers a real document reload (not just a
//     framenavigated event — those don't fire reliably on iOS via the BS
//     Selenium bridge)
//   - the timestamp dedup logic doesn't suppress the very first message
//     after a fresh page load
//
// What it does NOT cover:
//   - SW->page postMessage delivery to a hidden/BFCached/discarded client
//   - `clients.matchAll` selecting the right client when several are open
//   - the no-clients `clients.openWindow` fallback
// Those production failure modes need a real push round-trip; on BS, neither
// `context.serviceWorkers()` nor real-push from APNs work reliably enough to
// automate. Real-device manual verification covers them.
//
// BS quirks worked around here (see tests/e2e/helpers/test.js for the
// fixtures-side counterparts):
//   - No arg passing to page.evaluate / waitForFunction. iOS Safari + BS
//     Selenium mangles the argument structure on its way through the
//     bridge, so `(arg) => {...}` receives garbage. We hardcode message
//     data per test and stash before-state in sessionStorage so the
//     waitForFunction predicate can read it without an arg.
//   - No reliance on `framenavigated`. The bridge doesn't reliably surface
//     mainFrame reload events on iOS.
//   - reload-detection via `performance.timeOrigin`. Each new document
//     gets a fresh timeOrigin; comparing against the saved value proves a
//     real document-level reload happened, not just a JS context blip.
//
// Why a fresh `Date.now()` (not `Date.now() + N`): the dispatched message's
// timestamp is what gets stored in localStorage('state-invalidated'), and
// the post-reload visibilitychange/pageshow/focus listeners re-feed that
// value back into reload_invalidated. A future timestamp keeps it ahead
// of last-reload-timestamp after every reload, looping the page through a
// reload every ~500ms until the clock catches up.

const { test, expect } = require('./helpers/test');

const RUN = process.env.PWA_TEST_RUN || 'e2e-pwa-h-v1';

async function setBeforeReloadMarkers(page) {
    await page.evaluate(() => {
        sessionStorage.setItem('__pwa_test_before_origin', String(performance.timeOrigin));
        window.__pwa_test_sentinel = 'kept-until-reload';
    });
}

async function waitForReload(page) {
    // A real document-level reload has both:
    //   - window.__pwa_test_sentinel reset (new global object)
    //   - performance.timeOrigin different (new document time origin)
    // Either alone could lie; together they pin a real reload.
    await page.waitForFunction(
        () => {
            if (typeof window.__pwa_test_sentinel !== 'undefined') return false;
            const beforeRaw = sessionStorage.getItem('__pwa_test_before_origin');
            const before = beforeRaw == null ? NaN : parseFloat(beforeRaw);
            return Number.isFinite(before) && performance.timeOrigin !== before;
        },
        null,
        { timeout: 15000 }
    );
}

test.describe('PWA notification reload', () => {
    test('NOTIFICATION_CLICK message triggers a page reload', async ({ page, baseURL }) => {
        await page.goto(`${baseURL}/${RUN}/`, { waitUntil: 'load', timeout: 60000 });
        // SW activation broadcast can trigger an immediate reload on first
        // visit (commit 1698bf70's clients.matchAll().postMessage dance);
        // give it room to settle so our dispatch is the only post-stable
        // reload trigger we measure.
        await page.waitForTimeout(2000);
        await setBeforeReloadMarkers(page);

        await page.evaluate(() => {
            const ev = new MessageEvent('message', {
                data: { type: 'NOTIFICATION_CLICK', action: 'reload', timestamp: Date.now() }
            });
            navigator.serviceWorker.dispatchEvent(ev);
        });

        await waitForReload(page);
    });

    test('STATE_INVALIDATED message triggers a page reload', async ({ page, baseURL }) => {
        await page.goto(`${baseURL}/${RUN}/`, { waitUntil: 'load', timeout: 60000 });
        await page.waitForTimeout(2000);
        await setBeforeReloadMarkers(page);

        await page.evaluate(() => {
            const ev = new MessageEvent('message', {
                data: { type: 'STATE_INVALIDATED', tag: 'test', timestamp: Date.now() }
            });
            navigator.serviceWorker.dispatchEvent(ev);
        });

        await waitForReload(page);
    });

    // Regression for the failure mode reported as "clicking a push
    // notification doesn't reload the open PWA page when the unit's state
    // was advanced server-side". `handling-reload` was set in localStorage
    // when reload_invalidated kicked off a reload, and was only cleared in
    // the next DOMContentLoaded. If the previous reload was suppressed
    // (BFCache transition, navigation cancelled, browser crash mid-reload,
    // hidden-tab throttling), the flag stuck and every subsequent
    // NOTIFICATION_CLICK was dropped silently. The fix in commit b5195ad3
    // time-bounds the flag (10s) so it self-recovers.
    test('NOTIFICATION_CLICK reloads even when a stale handling-reload flag is set', async ({ page, baseURL }) => {
        await page.goto(`${baseURL}/${RUN}/`, { waitUntil: 'load', timeout: 60000 });
        await page.waitForTimeout(2000);
        await setBeforeReloadMarkers(page);

        // Simulate the stuck state: pretend a prior reload started ~1
        // minute ago and never cleared the in-flight flag.
        await page.evaluate(() => {
            const stale = Date.now() - 60_000;
            localStorage.setItem('handling-reload', 'true');
            localStorage.setItem('last-reload-timestamp', String(stale));
        });

        await page.evaluate(() => {
            const ev = new MessageEvent('message', {
                data: { type: 'NOTIFICATION_CLICK', action: 'reload', timestamp: Date.now() }
            });
            navigator.serviceWorker.dispatchEvent(ev);
        });

        await waitForReload(page);
    });
});
