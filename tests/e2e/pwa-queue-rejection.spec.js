// Offline-queue rejection handling (form_v2 drain), local Chromium.
//
// The drain (main.js drainQueue) POSTs each IndexedDB entry to /{run}/form-sync
// on load and on `online`. These tests seed an entry straight into the
// `formrQueue` IDB store and mock /form-sync so every server answer shape is
// deterministic — no fixture needs a real validation failure.
//
//   - 200 {status:'errors', errors} : the server applied NOTHING (ledger row
//     rolled back). The entry must survive (state needs_attention), the
//     server's per-item errors must render on the page, and the banner must
//     say which page needs fixing. (Regression: a green "all sent" banner and
//     a deleted entry = silent data loss.)
//   - 403 {error:'No active run session'} : keep the entry, back it off
//     (attempts + next_retry_at), don't re-POST on the very next drain.
//   - a disabled submit button (auto-submit's minimum-exposure mode) must
//     block implicit submission (Enter in a text input).
//
// Service workers are blocked in playwright.config.js locally; the drain is
// page-side so that doesn't matter here. Skipped on BrowserStack: page.route
// isn't available through the Selenium bridge.

const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');

const RUN = () => runName('pwa_low', 'v2');
const SYNC_RE = /\/form-sync\/?$/;
// Route matcher, not the assertion one: run_url() emits the sync URL WITH a
// trailing slash (https://<study-domain>/<run>/form-sync/), which the glob
// '**/form-sync' does not match — the drain then hit the real server and the
// entry was applied and deleted, i.e. the test silently exercised nothing.
const SYNC_ROUTE = /\/form-sync\/?(\?.*)?$/;

// Raw IDB access (the bundle doesn't expose queue.js). The store exists once
// the bundle has run its load-time drain, which waitForBundle guarantees.
const IDB_GET_ALL = () => new Promise((resolve, reject) => {
    const req = indexedDB.open('formrQueue', 1);
    req.onerror = () => reject(req.error);
    req.onsuccess = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains('queue')) { db.close(); resolve([]); return; }
        const tx = db.transaction('queue', 'readonly');
        const all = tx.objectStore('queue').getAll();
        all.onsuccess = () => { db.close(); resolve(all.result || []); };
        all.onerror = () => { db.close(); reject(all.error); };
    };
});
const IDB_PUT = (entry) => new Promise((resolve, reject) => {
    const req = indexedDB.open('formrQueue', 1);
    req.onerror = () => reject(req.error);
    req.onsuccess = () => {
        const db = req.result;
        const tx = db.transaction('queue', 'readwrite');
        tx.objectStore('queue').put(entry);
        tx.oncomplete = () => { db.close(); resolve(true); };
        tx.onerror = () => { db.close(); reject(tx.error); };
    };
});
const IDB_CLEAR = () => new Promise((resolve) => {
    const req = indexedDB.open('formrQueue', 1);
    req.onerror = () => resolve(false);
    req.onsuccess = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains('queue')) { db.close(); resolve(true); return; }
        const tx = db.transaction('queue', 'readwrite');
        tx.objectStore('queue').clear();
        tx.oncomplete = () => { db.close(); resolve(true); };
        tx.onerror = () => { db.close(); resolve(false); };
    };
});

// Read what the bundle would put on a queue entry for the current page.
async function currentFormContext(page) {
    return page.evaluate(() => {
        const root = document.querySelector('form.fmr-form-v2');
        const sec = root.querySelector('section.fmr-page:not([hidden])');
        const sess = root.querySelector('input[name="session_id"]');
        const firstInput = sec.querySelector('input[name]:not([type=hidden]):not([name^="_item_views"]), textarea[name], select[name]');
        return {
            syncUrl: root.dataset.syncUrl,
            unitSessionId: sess ? Number(sess.value) : null,
            pageNum: Number(sec.dataset.fmrPage),
            itemName: firstInput ? firstInput.name.replace(/\[\]$/, '') : null,
        };
    });
}

function mysqlNow() {
    return new Date().toISOString().slice(0, 19).replace('T', ' ');
}

// Seed one pending entry for the current page, then reload so the load-time
// drain picks it up. Returns the seeded uuid.
async function seedEntry(page, ctx, extra = {}) {
    const uuid = crypto.randomUUID();
    await page.evaluate(IDB_PUT, Object.assign({
        uuid,
        page: ctx.pageNum,
        unit_session_id: ctx.unitSessionId,
        sync_url: ctx.syncUrl,
        data: {},
        item_views: {},
        client_ts: mysqlNow(),
    }, extra));
    return uuid;
}

test.describe('offline queue: rejected entries are kept, not deleted', () => {
    test.skip(RUNNING_ON_BS, 'page.route is not available through the BrowserStack Selenium bridge');

    test.afterEach(async ({ page }) => {
        try { await page.unroute(SYNC_ROUTE); } catch {}
        try { await page.evaluate(IDB_CLEAR); } catch {}
    });

    test('200 {status:"errors"} keeps the entry, renders the server errors, and names the page', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const ctx = await currentFormContext(page);
        expect(ctx.syncUrl, 'fixture must have offline_mode on (data-sync-url)').toMatch(SYNC_RE);
        expect(ctx.itemName, 'fixture page needs at least one named input').toBeTruthy();

        const uuid = await seedEntry(page, ctx);
        const errorMsg = 'Server rejected this answer (e2e)';
        const syncCalls = [];
        await page.route(SYNC_ROUTE, async (route) => {
            syncCalls.push(route.request().postData());
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ status: 'errors', errors: { [ctx.itemName]: errorMsg } }),
            });
        });

        await Promise.all([
            page.waitForResponse((r) => SYNC_RE.test(new URL(r.url()).pathname), { timeout: 20000 }),
            page.reload({ waitUntil: 'commit' }),
        ]);
        await v2.waitForBundle(page);

        // Entry survived, flagged for the participant.
        await expect.poll(async () => {
            const all = await page.evaluate(IDB_GET_ALL);
            const e = all.find((x) => x.uuid === uuid);
            return e ? e.state : 'DELETED';
        }, { timeout: 10000 }).toBe('needs_attention');

        // Server's per-item error rendered through the validation feedback path.
        await expect(page.locator('.fmr-invalid-feedback', { hasText: errorMsg }).first()).toBeVisible({ timeout: 5000 });

        // Persistent danger banner naming the page; NOT the green "all sent".
        const banner = page.locator('.fmr-queue-banner');
        await expect(banner).toBeVisible();
        await expect(banner).toHaveClass(/alert-danger/);
        await expect(banner).toContainText(`page ${ctx.pageNum}`);
        await expect(banner).not.toContainText('All queued submissions have been sent');

        // A second drain must not re-POST a needs_attention entry.
        const before = syncCalls.length;
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await page.waitForTimeout(1500);
        expect(syncCalls.length).toBe(before);
    });

    test('403 keeps the entry with backoff and does not re-POST on the next drain', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const ctx = await currentFormContext(page);
        expect(ctx.syncUrl).toMatch(SYNC_RE);

        const uuid = await seedEntry(page, ctx);
        let syncCalls = 0;
        await page.route(SYNC_ROUTE, async (route) => {
            syncCalls += 1;
            await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ error: 'No active run session' }) });
        });

        await Promise.all([
            page.waitForResponse((r) => SYNC_RE.test(new URL(r.url()).pathname), { timeout: 20000 }),
            page.reload({ waitUntil: 'commit' }),
        ]);
        await v2.waitForBundle(page);

        // Entry survived with one recorded attempt and a future retry time.
        await expect.poll(async () => {
            const all = await page.evaluate(IDB_GET_ALL);
            const e = all.find((x) => x.uuid === uuid);
            return e ? e.attempts : 'DELETED';
        }, { timeout: 10000 }).toBe(1);
        const after = (await page.evaluate(IDB_GET_ALL)).find((x) => x.uuid === uuid);
        expect(after.state).toBe('pending');
        expect(after.last_error && after.last_error.reason).toBe('session');
        expect(after.next_retry_at).toBeGreaterThan(Date.now());

        // Banner offers a manual retry; the automatic drain honours the backoff.
        const banner = page.locator('.fmr-queue-banner');
        await expect(banner).toBeVisible();
        await expect(banner.locator('.fmr-queue-banner-action')).toBeVisible();
        const before = syncCalls;
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await page.waitForTimeout(1500);
        expect(syncCalls).toBe(before);

        // Manual Retry resets the backoff and POSTs again.
        await Promise.all([
            page.waitForResponse((r) => SYNC_RE.test(new URL(r.url()).pathname), { timeout: 10000 }),
            banner.locator('.fmr-queue-banner-action').click(),
        ]);
        expect(syncCalls).toBe(before + 1);
    });

    test('a disabled submit button blocks implicit (Enter) submission', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        let submits = 0;
        page.on('request', (r) => { if (/\/form-page-submit\/?$/.test(new URL(r.url()).pathname)) submits += 1; });

        const gated = await page.evaluate(() => {
            const sec = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])');
            const btn = sec.querySelector('[data-fmr-next]');
            btn.disabled = true; // what items/auto-submit.js does for a negative data-timeout
            const can = window.fmrCanSubmitPage(sec);
            // Implicit submission: the form's `submit` event (Enter in a text input).
            sec.closest('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            return can;
        });
        expect(gated).toBe(false);
        await page.waitForTimeout(1500);
        expect(submits, 'no /form-page-submit while the submit button is disabled').toBe(0);

        const reEnabled = await page.evaluate(() => {
            const sec = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])');
            sec.querySelector('[data-fmr-next]').disabled = false;
            return window.fmrCanSubmitPage(sec);
        });
        expect(reEnabled).toBe(true);
    });
});
