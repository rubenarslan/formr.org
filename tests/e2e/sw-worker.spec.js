// The served service worker (webroot/assets/common/js/service-worker.js) with
// a live worker — project sw-chromium only (`npm run test:e2e:sw`).
// local-chromium blocks service workers, which is how all of this went
// unseen (review 2026-09-27):
//   - the fetch handler re-fetched every in-scope request it didn't cache
//     (`return fetch(event.request)` without respondWith), so each navigation
//     to the run URL ran twice on the server;
//   - install rejected when the run had no manifest (/manifest 404): the
//     registration vanished and every page load re-registered and beaconed;
//   - CACHE_ASSETS cached nothing (the work was the argument of an optional-
//     chained postMessage on a port pwa-register never sends);
//   - FMR_DUMP_CACHES, which the cache tests ask, had no handler;
//   - any path containing "/manifest" was cache-first, e.g. a run "manifesto";
//   - v2 registered the worker on studies that are not a PWA.

const { test, expect } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const pwa = require('./helpers/pwa');

const PWA_RUN = () => runName('pwa_high', 'v2');
const PLAIN_RUN = () => runName('all_widgets_v2', 'v2');
const isAsset = (url) => /\/assets\/|\/manifest(\.json)?\/?(\?|$)/.test(new URL(url).pathname + new URL(url).search);

// Post a message to the active worker; resolve with its reply (or a timeout
// marker). `withPort: false` posts the way pwa-register.js does: no reply.
const postToWorker = (page, msg, { withPort = true } = {}) => page.evaluate(async ({ msg, withPort }) => {
    const reg = await navigator.serviceWorker.getRegistration();
    const sw = reg && reg.active;
    if (!sw) return { error: 'no active worker' };
    if (!withPort) { sw.postMessage(msg); return { posted: true }; }
    return new Promise((resolve) => {
        const ch = new MessageChannel();
        const timer = setTimeout(() => resolve({ error: 'no reply' }), 8000);
        ch.port1.onmessage = (e) => { clearTimeout(timer); resolve(e.data); };
        sw.postMessage(msg, [ch.port2]);
    });
}, { msg, withPort });

const inAnyCache = (page, url) => page.evaluate(async (u) => !!(await caches.match(u)), url);

test.describe('service worker', () => {
    test('a v2 study that is not a PWA registers no worker', async ({ page, baseURL }) => {
        // Count registration attempts, not registrations: a worker whose
        // install fails is gone again by the time anyone looks.
        const scripts = [];
        page.context().on('request', (r) => { if (/\/service-worker\/?$/.test(new URL(r.url()).pathname)) scripts.push(r.url()); });
        await freshParticipant(page, PLAIN_RUN(), { baseURL });
        await v2.waitForBundle(page);
        await page.waitForTimeout(2000);
        const regs = await page.evaluate(async () => (await navigator.serviceWorker.getRegistrations()).length);
        expect(regs).toBe(0);
        expect(scripts, 'the worker script is never requested').toEqual([]);
    });

    test('on a PWA run the worker controls the page and fetches nothing it does not cache', async ({ page, baseURL }) => {
        await freshParticipant(page, PWA_RUN(), { baseURL });
        await v2.waitForBundle(page);
        expect(await pwa.swActivated(page)).toBe('activated');
        await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller), { timeout: 15000 }).toBe(true);

        const requests = [];
        page.context().on('request', (r) => requests.push(r));
        const pageUrl = page.url();
        await page.reload({ waitUntil: 'load' });
        await v2.waitForBundle(page);
        await page.waitForTimeout(1500);

        const fromWorker = requests.filter((r) => r.serviceWorker() && !isAsset(r.url()));
        expect(fromWorker.map((r) => r.url()), 'the worker must leave non-asset requests to the browser').toEqual([]);
        const navigations = requests.filter((r) => r.method() === 'GET' && r.url() === pageUrl);
        expect(navigations.length, 'the run page is requested once per load').toBe(1);
    });

    test('CACHE_ASSETS caches what it is sent, with or without a reply port; FMR_DUMP_CACHES answers', async ({ page, baseURL }) => {
        await freshParticipant(page, PWA_RUN(), { baseURL });
        await v2.waitForBundle(page);
        expect(await pwa.swActivated(page)).toBe('activated');
        const [cssUrl, jsUrl] = await page.evaluate(() => [
            document.querySelector('link[rel=stylesheet][href*="/assets/"]').href,
            document.querySelector('script[src*="/assets/"]').src,
        ]);

        // pwa-register.js's shape: no port.
        await postToWorker(page, { type: 'CACHE_ASSETS', assets: [cssUrl] }, { withPort: false });
        await expect.poll(() => inAnyCache(page, cssUrl), { timeout: 15000 }).toBe(true);

        const reply = await postToWorker(page, { type: 'CACHE_ASSETS', assets: [jsUrl] });
        expect(reply.error).toBeUndefined();
        expect(reply.ok).toBe(true);
        expect(await inAnyCache(page, jsUrl)).toBe(true);

        const dump = await postToWorker(page, { type: 'FMR_DUMP_CACHES' });
        expect(dump.keys).toContain(dump.cacheName);
        expect(dump.entries[dump.cacheName]).toBeGreaterThanOrEqual(2);

        // A message without data (or not an object) is ignored, not thrown on.
        await postToWorker(page, null, { withPort: false });
        expect((await postToWorker(page, { type: 'FMR_DUMP_CACHES' })).cacheName).toBe(dump.cacheName);
    });

    test('only a path ending in /manifest is treated as the manifest', async ({ page, baseURL }) => {
        await freshParticipant(page, PWA_RUN(), { baseURL });
        await v2.waitForBundle(page);
        expect(await pwa.swActivated(page)).toBe('activated');
        await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller), { timeout: 15000 }).toBe(true);
        const runUrl = await page.evaluate(() => document.querySelector('form.fmr-form-v2').dataset.runUrl.replace(/\/?$/, '/'));
        const lookalike = runUrl + 'manifesto-e2e';
        const manifest = runUrl + 'manifest';
        // A cacheable answer for the look-alike (a real run page would be
        // one); context.route also sees the worker's own fetches.
        await page.context().route((u) => u.pathname.endsWith('/manifesto-e2e'), (route) => route.fulfill({
            status: 200, contentType: 'text/html', body: '<!doctype html><title>e2e</title>',
        }));
        await page.evaluate(async (urls) => { for (const u of urls) await fetch(u).catch(() => null); }, [lookalike, manifest]);
        await expect.poll(() => inAnyCache(page, manifest), { timeout: 10000 }).toBe(true);
        expect(await inAnyCache(page, lookalike)).toBe(false);
    });

    test('the worker installs on a run without a manifest, without a failure beacon', async ({ page, baseURL }) => {
        await freshParticipant(page, PLAIN_RUN(), { baseURL });
        await v2.waitForBundle(page);
        const beacons = [];
        page.context().on('request', (r) => { if (/\/pwa-beacon\/?$/.test(new URL(r.url()).pathname)) beacons.push(r.url()); });
        // v2 no longer registers it here; an earlier bundle did, so the
        // worker must still cope: register it by hand.
        const scope = await page.evaluate(() => new URL(document.querySelector('form.fmr-form-v2').dataset.runUrl).pathname.replace(/\/?$/, '/'));
        const manifestStatus = (await page.request.get(scope + 'manifest')).status();
        expect(manifestStatus, 'fixture run has no manifest').toBe(404);
        await page.evaluate((s) => navigator.serviceWorker.register(s + 'service-worker', { scope: s }), scope);
        expect(await pwa.swActivated(page)).toBe('activated');
        await page.reload({ waitUntil: 'load' });
        await v2.waitForBundle(page);
        expect(await page.evaluate(async (s) => {
            const reg = await navigator.serviceWorker.getRegistration(s);
            return reg && reg.active ? reg.active.state : null;
        }, scope)).toBe('activated');
        expect(beacons).toEqual([]);
    });
});
