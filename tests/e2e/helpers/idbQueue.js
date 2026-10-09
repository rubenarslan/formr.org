// Raw access to the form_v2 offline queue (IndexedDB `formrQueue`, store
// `queue`) for specs that seed entries and mock /form-sync. The bundle
// doesn't expose queue.js; the store exists once the bundle has run its
// load-time drain, which v2Form.waitForBundle guarantees.

const SYNC_RE = /\/form-sync\/?$/;
// Route matcher, not the assertion one: run_url() emits the sync URL WITH a
// trailing slash (https://<study-domain>/<run>/form-sync/), which the glob
// '**/form-sync' does not match — the drain then hit the real server and the
// entry was applied and deleted, i.e. the test silently exercised nothing.
const SYNC_ROUTE = /\/form-sync\/?(\?.*)?$/;
const isSync = (url) => SYNC_RE.test(new URL(url).pathname);

// Page functions (passed to page.evaluate).
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
const IDB_PUT = (entries) => new Promise((resolve, reject) => {
    const req = indexedDB.open('formrQueue', 1);
    req.onerror = () => reject(req.error);
    req.onsuccess = () => {
        const db = req.result;
        const tx = db.transaction('queue', 'readwrite');
        (Array.isArray(entries) ? entries : [entries]).forEach((e) => tx.objectStore('queue').put(e));
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

// What the bundle would put on a queue entry for the current page.
async function currentFormContext(page) {
    return page.evaluate(() => {
        const root = document.querySelector('form.fmr-form-v2');
        const sec = root.querySelector('section.fmr-page:not([hidden])');
        const state = root.querySelector('script.fmr-state');
        const firstInput = sec.querySelector('input[name]:not([type=hidden]):not([name^="_item_views"]), textarea[name], select[name]');
        return {
            syncUrl: root.dataset.syncUrl,
            runUrl: root.dataset.runUrl,
            offlineMode: root.dataset.offlineMode,
            unitSessionId: state ? Number(JSON.parse(state.textContent).unit_session_id) : null,
            pageNum: Number(sec.dataset.fmrPage),
            itemName: firstInput ? firstInput.name.replace(/\[\]$/, '') : null,
        };
    });
}

function mysqlNow() {
    return new Date().toISOString().slice(0, 19).replace('T', ' ');
}

// A pending entry for the current form, overridable per field.
function entryFor(ctx, extra = {}) {
    return Object.assign({
        uuid: crypto.randomUUID(),
        page: ctx.pageNum,
        unit_session_id: ctx.unitSessionId,
        sync_url: ctx.syncUrl,
        data: {},
        item_views: {},
        client_ts: mysqlNow(),
    }, extra);
}

// Seed one entry; returns its uuid.
async function seedEntry(page, ctx, extra = {}) {
    const entry = entryFor(ctx, extra);
    await page.evaluate(IDB_PUT, entry);
    return entry.uuid;
}

module.exports = {
    SYNC_RE, SYNC_ROUTE, isSync, IDB_GET_ALL, IDB_PUT, IDB_CLEAR,
    currentFormContext, mysqlNow, entryFor, seedEntry,
};
