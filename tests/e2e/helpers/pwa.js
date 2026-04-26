// Shared PWA assertions, used by all four pwa-install-* specs.
//
// Local-Chromium asserts the cheap stuff (manifest endpoint, head links,
// item presence). Real-device assertions (SW activation, cache population,
// offline-queue drain, install prompt) gate on `isLocal(info)` and skip on
// local-chromium.

const { expect } = require('@playwright/test');

const isLocal = (info) => info.project.name === 'local-chromium';

async function assertManifest(request, runPath) {
    const res = await request.get(`${runPath}manifest`);
    expect(res.status(), 'manifest endpoint should return 200').toBe(200);
    const m = await res.json();
    expect(m.name, 'manifest.name').toBeTruthy();
    expect(m.start_url, 'manifest.start_url').toContain(runPath);
    expect(m.scope, 'manifest.scope').toContain(runPath);
    expect(Array.isArray(m.icons) && m.icons.length > 0, 'manifest.icons[]').toBe(true);
    return m;
}

async function assertHeadWiring(page, { runPath, expectVapid = false } = {}) {
    const manifestHref = await page.locator('link[rel="manifest"]').first().getAttribute('href').catch(() => null);
    expect(manifestHref, 'link[rel=manifest] href').toBeTruthy();
    expect(manifestHref).toContain(`${runPath}manifest`);

    if (expectVapid) {
        // VAPID is per-run setting (push enabled). If not configured, the
        // template doesn't emit window.vapidPublicKey at all. Caller decides
        // whether to require it (push test) or just sample (general head check).
        const hasVapid = await page.evaluate(() => typeof window.vapidPublicKey === 'string' && window.vapidPublicKey.length > 10);
        expect(hasVapid, 'window.vapidPublicKey populated').toBe(true);
    }
}

// Wait until the SW reaches 'activated'. Polls TEST-side (one page.evaluate
// per tick) rather than in-page because iOS Safari real-device's setTimeout
// inside page.evaluate misbehaves under BS automation — promises sometimes
// settle as null long before the SW is actually ready. Test-side polling
// uses Playwright's timing, which works on both Pixel and iPhone.
async function swActivated(page, { timeout = 30000, pollInterval = 1000 } = {}) {
    const deadline = Date.now() + timeout;
    let lastState = null;
    while (Date.now() < deadline) {
        try {
            const state = await page.evaluate(async () => {
                if (!('serviceWorker' in navigator)) return 'no-sw';
                // Don't await `ready` — if the SW is still installing it
                // won't resolve and we'd block past the per-tick budget.
                // Instead read getRegistration's active state directly.
                const reg = await navigator.serviceWorker.getRegistration();
                if (!reg) return 'no-registration';
                if (reg.active) return reg.active.state;
                if (reg.waiting) return 'waiting';
                if (reg.installing) return reg.installing.state;
                return null;
            });
            lastState = state;
            if (state === 'activated' || state === 'redundant') return state;
        } catch {
            // Page closing / nav in flight — keep trying until deadline.
        }
        await page.waitForTimeout(pollInterval);
    }
    return lastState;
}

// Diagnostic for "why did swActivated return null?". Returns a small object
// describing the SW lifecycle on the page — number of registrations,
// scriptURL, controller state, latest registration's installing/waiting/
// active states. Use in tests to surface "SW didn't register" vs "SW
// registered but didn't activate" vs "SW active but state never
// transitioned" rather than a generic null.
async function swDiagnostics(page) {
    return page.evaluate(async () => {
        try {
            const regs = await navigator.serviceWorker.getRegistrations();
            const controller = navigator.serviceWorker.controller;
            return {
                supports: !!navigator.serviceWorker,
                controller: controller ? { scriptURL: controller.scriptURL, state: controller.state } : null,
                registrations: regs.map((r) => ({
                    scope: r.scope,
                    installingState: r.installing?.state || null,
                    waitingState: r.waiting?.state || null,
                    activeState: r.active?.state || null,
                    activeScriptURL: r.active?.scriptURL || null,
                })),
            };
        } catch (e) { return { error: String(e && e.message || e) }; }
    });
}

async function cacheKeys(page) {
    return page.evaluate(async () => ('caches' in self ? await caches.keys() : []));
}

module.exports = { isLocal, assertManifest, assertHeadWiring, swActivated, swDiagnostics, cacheKeys };
