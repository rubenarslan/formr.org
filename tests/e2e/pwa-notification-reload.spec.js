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
// hangs on the dev SW). Real-SW exercise is parked for the BS path.
//
// What this test pins:
//   - the listener handles both message shapes
//   - the timestamp dedup logic in `reload_invalidated` doesn't suppress
//     the very first message after a fresh page load
//
// What it does NOT cover:
//   - SW->page postMessage delivery to a hidden/BFCached/discarded client
//   - `clients.matchAll` selecting the right client when several are open
//   - iOS-specific `window.location.href = window.location.href` reload
//   - the no-clients `clients.openWindow` fallback
// Those production failure modes need a real SW round-trip (BS).
//
// Why a fresh `Date.now()` (not `Date.now() + N`): the dispatched message's
// timestamp is what gets stored in localStorage('state-invalidated'), and
// the post-reload visibilitychange/pageshow/focus listeners re-feed that
// value back into reload_invalidated. A future timestamp keeps it ahead
// of last-reload-timestamp after every reload, looping the page through a
// reload every ~500ms until the clock catches up — which is what the
// previous version of this test did and is why it was flaky.

const { test, expect } = require('./helpers/test');

const RUN = process.env.PWA_TEST_RUN || 'e2e-pwa-h-v1';

async function expectSyntheticReload(page, baseURL, messageData) {
    let navCount = 0;
    page.on('framenavigated', (frame) => {
        if (frame === page.mainFrame()) navCount++;
    });

    await page.goto(`${baseURL}/${RUN}/`, { waitUntil: 'load', timeout: 60000 });
    const navsAfterLoad = navCount;

    // Sentinel lives on `window`; it disappears across a real reload.
    await page.evaluate(() => {
        window.__reloadSentinel = 'kept-until-reload';
    });

    // Dispatch the synthetic SW->page message. Build the data object on
    // the page side so the timestamp is taken from the page's clock —
    // matches what the real SW posts, which also uses Date.now() in the
    // same browser process.
    await page.evaluate((dataTemplate) => {
        const data = { ...dataTemplate, timestamp: Date.now() };
        const ev = new MessageEvent('message', { data });
        navigator.serviceWorker.dispatchEvent(ev);
    }, messageData);

    // The listener calls reload_invalidated which schedules
    // setTimeout(reload, 100). Wait for the resulting navigation to
    // complete instead of polling on a fixed sleep.
    await page.waitForFunction(
        () => typeof window.__reloadSentinel === 'undefined',
        null,
        { timeout: 10000 }
    );

    expect(navCount, 'expected at least one extra mainFrame navigation').toBeGreaterThan(navsAfterLoad);
}

test.describe('PWA notification reload', () => {
    test('NOTIFICATION_CLICK message triggers a page reload', async ({ page, baseURL }) => {
        await expectSyntheticReload(page, baseURL, {
            type: 'NOTIFICATION_CLICK',
            action: 'reload',
        });
    });

    test('STATE_INVALIDATED message triggers a page reload', async ({ page, baseURL }) => {
        await expectSyntheticReload(page, baseURL, {
            type: 'STATE_INVALIDATED',
            tag: 'test',
        });
    });

    // Regression for the failure mode reported as "clicking a push
    // notification doesn't reload the open PWA page when the unit's state
    // was advanced server-side". `handling-reload` is set in localStorage
    // when reload_invalidated kicks off a reload, and is only cleared in
    // the next DOMContentLoaded. If the previous reload was suppressed (BFCache
    // transition, navigation cancelled, browser crash mid-reload, hidden-tab
    // throttling), the flag is sticky and every subsequent NOTIFICATION_CLICK
    // is dropped silently, leaving the participant on a stale unit page.
    test('NOTIFICATION_CLICK reloads even when a stale handling-reload flag is set', async ({ page, baseURL }) => {
        let navCount = 0;
        page.on('framenavigated', (frame) => {
            if (frame === page.mainFrame()) navCount++;
        });

        await page.goto(`${baseURL}/${RUN}/`, { waitUntil: 'load', timeout: 60000 });
        const navsAfterLoad = navCount;

        // Simulate the stuck state: pretend a prior reload started ~1
        // minute ago and never cleared the in-flight flag.
        await page.evaluate(() => {
            const stale = Date.now() - 60_000;
            localStorage.setItem('handling-reload', 'true');
            localStorage.setItem('last-reload-timestamp', String(stale));
            window.__reloadSentinel = 'kept-until-reload';
        });

        await page.evaluate(() => {
            const ev = new MessageEvent('message', {
                data: { type: 'NOTIFICATION_CLICK', action: 'reload', timestamp: Date.now() }
            });
            navigator.serviceWorker.dispatchEvent(ev);
        });

        await page.waitForFunction(
            () => typeof window.__reloadSentinel === 'undefined',
            null,
            { timeout: 10000 }
        );

        expect(navCount, 'expected at least one extra mainFrame navigation').toBeGreaterThan(navsAfterLoad);
    });
});
