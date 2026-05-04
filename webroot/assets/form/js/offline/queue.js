// IndexedDB-backed offline submission queue.
//
// One database (`formrQueue`), one object store (`queue`), keyPath `uuid`
// (server's dedupe key), single index `client_ts` for ordered drain. Replaces
// the hand-rolled `openQueueDB` / `idbTx` / queueAdd-Get-Delete promise
// wrappers with `idb` (Jake Archibald's tiny IDB wrapper) so the surface
// stays small and we don't maintain custom IDB plumbing.
//
// Exports return promises that resolve with the wrapped result; callers
// don't care that the underlying mechanism is IDB.

import { openDB } from 'idb';

const DB_NAME = 'formrQueue';
const STORE = 'queue';

let dbPromise = null;

function getDb() {
    if (!dbPromise) {
        dbPromise = openDB(DB_NAME, 1, {
            upgrade(db) {
                if (!db.objectStoreNames.contains(STORE)) {
                    const store = db.createObjectStore(STORE, { keyPath: 'uuid' });
                    store.createIndex('client_ts', 'client_ts');
                }
            },
        });
    }
    return dbPromise;
}

export async function queueAdd(entry) {
    const db = await getDb();
    await db.put(STORE, entry);
    return entry;
}

export async function queueGetAll() {
    const db = await getDb();
    return (await db.getAll(STORE)) || [];
}

export async function queueDelete(uuid) {
    const db = await getDb();
    await db.delete(STORE, uuid);
}

// Wipe every queued entry. Used on logout / account deletion / SW unregister
// so plaintext answers don't persist on the participant's device beyond their
// participant relationship with the run. The objectStore stays in place
// (cheaper than dropping the database and re-creating); only the rows go.
export async function wipeQueue() {
    const db = await getDb();
    await db.clear(STORE);
}

// Build a FormData representation of a queued entry that has `files`. Keys
// mirror form-page-submit's multipart branch (see `RunController::formSyncAction`).
// Pulled out of drainQueue so the multipart shape lives in one place — also
// useful when an entry goes from JSON to multipart between offline-enqueue
// and online-drain (e.g. a future blob attachment).
export function buildSyncFormData(entry) {
    const fd = new FormData();
    fd.append('uuid', entry.uuid);
    fd.append('page', String(entry.page));
    if (entry.unit_session_id != null) fd.append('unit_session_id', String(entry.unit_session_id));
    if (entry.client_ts) fd.append('client_ts', entry.client_ts);
    const data = entry.data || {};
    Object.keys(data).forEach((k) => {
        const v = data[k];
        if (Array.isArray(v)) {
            v.forEach((vv) => fd.append(`data[${k}][]`, vv == null ? '' : String(vv)));
        } else if (v != null) {
            fd.append(`data[${k}]`, String(v));
        }
    });
    const views = entry.item_views || {};
    Object.keys(views).forEach((bucket) => {
        const m = views[bucket] || {};
        Object.keys(m).forEach((id) => fd.append(`item_views[${bucket}][${id}]`, String(m[id])));
    });
    const files = entry.files || {};
    Object.keys(files).forEach((itemName) => {
        const f = files[itemName];
        if (!f) return;
        const fname = (f.name || itemName);
        fd.append(`files[${itemName}]`, f, fname);
    });
    return fd;
}

// Network-ish failure: fetch() rejected (no response) OR a 5xx server error.
// 4xx is a real "server said no" — don't queue, bubble up.
export function isTransientFailure(err, res) {
    return (err != null) || (res && res.status >= 500 && res.status < 600);
}
