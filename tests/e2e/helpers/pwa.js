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

async function swActivated(page, { timeout = 8000 } = {}) {
    return page.evaluate(async (t) => {
        try {
            const reg = await Promise.race([
                navigator.serviceWorker.ready,
                new Promise((_, rej) => setTimeout(() => rej(new Error('sw timeout')), t)),
            ]);
            return reg && reg.active ? reg.active.state : null;
        } catch (e) { return null; }
    }, timeout);
}

async function cacheKeys(page) {
    return page.evaluate(async () => ('caches' in self ? await caches.keys() : []));
}

module.exports = { isLocal, assertManifest, assertHeadWiring, swActivated, cacheKeys };
