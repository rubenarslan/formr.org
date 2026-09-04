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
import { toMysqlDatetime } from '../lib/time.js';

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

// Overwrite a stored entry in place (same keyPath). Used by the drain to
// persist retry bookkeeping (`attempts`, `next_retry_at`, `state`, `errors`)
// so backoff survives a reload — the drain runs on every load and every
// `online` event, and without persisted state a permanently-rejected entry
// is re-POSTed on each of them.
export async function queueUpdate(entry) {
    const db = await getDb();
    await db.put(STORE, entry);
    return entry;
}

// Delete every entry that targets the same page of the same unit session as
// `entry` (except `entry` itself, when given). A page re-submitted — after a
// validation rejection, or simply re-visited via Previous — supersedes the
// earlier queued copy: draining the old one after the new one landed would
// overwrite the accepted answers with the stale ones, and a `needs_attention`
// copy would wedge the run's queue behind an error the participant already
// fixed. `sync_url` scopes the match to one run (the DB is origin-wide).
export async function queueSupersede({ sync_url, unit_session_id, page }, keepUuid) {
    const db = await getDb();
    const entries = (await db.getAll(STORE)) || [];
    let removed = 0;
    for (const e of entries) {
        if (keepUuid && e.uuid === keepUuid) continue;
        if ((e.sync_url || '') !== (sync_url || '')) continue;
        if (Number(e.page) !== Number(page)) continue;
        if ((e.unit_session_id || 0) !== (unit_session_id || 0)) continue;
        await db.delete(STORE, e.uuid);
        removed += 1;
    }
    return removed;
}

// ---- Retry state -----------------------------------------------------------
//
// Every entry starts as STATE_PENDING (also the implicit state of entries
// written by older bundles, which carry no `state` at all). The drain moves it
// to:
//   - STATE_NEEDS_ATTENTION: the server applied nothing and answered
//     `{status:'errors', errors}` — the participant has to fix the page and
//     re-submit it (which supersedes this entry). Never auto-retried: the same
//     bytes would be rejected again.
//   - STATE_BLOCKED: rejected RETRY_MAX_ATTEMPTS times, or rejected with an
//     error the client knows won't clear on its own (400 envelope error, 403 for
//     the run the page itself belongs to). Kept on disk, surfaced in the banner,
//     retried only when the participant presses Retry (`queueResetRetry`).
// A pending entry that was rejected carries `attempts` + `next_retry_at` (ms
// epoch) and is skipped by the drain until that time — exponential backoff so a
// server that keeps saying no isn't hammered on every `online` / load.
export const STATE_PENDING = 'pending';
export const STATE_NEEDS_ATTENTION = 'needs_attention';
export const STATE_BLOCKED = 'blocked';

export const RETRY_MAX_ATTEMPTS = 8;
const RETRY_BASE_MS = 30 * 1000;
const RETRY_CAP_MS = 60 * 60 * 1000;

export function entryState(entry) {
    return (entry && entry.state) || STATE_PENDING;
}

export function retryDelayMs(attempts) {
    const n = Math.max(1, Number(attempts) || 1);
    return Math.min(RETRY_CAP_MS, RETRY_BASE_MS * Math.pow(2, n - 1));
}

// Is this entry due for a POST right now? Only pending entries are ever
// auto-drained; a backoff window keeps it pending but not due.
export function entryIsDue(entry, now = Date.now()) {
    if (entryState(entry) !== STATE_PENDING) return false;
    return !(entry.next_retry_at && entry.next_retry_at > now);
}

// Record one failed attempt. `permanent` skips the backoff ladder and blocks
// immediately. `reason` is a short machine tag ('session', 'invalid',
// 'server', 'errors') the banner turns into participant-facing copy.
export function markRetryFailure(entry, { reason, status, message, permanent = false, now = Date.now() } = {}) {
    const attempts = (Number(entry.attempts) || 0) + 1;
    entry.attempts = attempts;
    entry.last_error = { reason: reason || 'server', status: status || 0, message: message || '', at: now };
    if (permanent || attempts >= RETRY_MAX_ATTEMPTS) {
        entry.state = STATE_BLOCKED;
        entry.next_retry_at = null;
    } else {
        entry.state = STATE_PENDING;
        entry.next_retry_at = now + retryDelayMs(attempts);
    }
    return entry;
}

export function markNeedsAttention(entry, errors, now = Date.now()) {
    entry.state = STATE_NEEDS_ATTENTION;
    entry.errors = errors || {};
    entry.next_retry_at = null;
    entry.last_error = { reason: 'errors', status: 200, message: '', at: now };
    return entry;
}

// Manual "Retry": clear the bookkeeping so the next drain POSTs the entry
// again. Applies to blocked and backoff-pending entries; needs_attention ones
// are left alone (only a re-submit of the page can fix those).
export async function queueResetRetry(filter) {
    const db = await getDb();
    const entries = (await db.getAll(STORE)) || [];
    let reset = 0;
    for (const e of entries) {
        if (entryState(e) === STATE_NEEDS_ATTENTION) continue;
        if (typeof filter === 'function' && !filter(e)) continue;
        if (!e.attempts && !e.next_retry_at && entryState(e) === STATE_PENDING) continue;
        e.state = STATE_PENDING;
        e.attempts = 0;
        e.next_retry_at = null;
        delete e.last_error;
        await db.put(STORE, e);
        reset += 1;
    }
    return reset;
}

// Wipe every queued entry. Used on logout / account deletion / SW unregister
// so plaintext answers don't persist on the participant's device beyond their
// participant relationship with the run. The objectStore stays in place
// (cheaper than dropping the database and re-creating); only the rows go.
export async function wipeQueue() {
    const db = await getDb();
    await db.clear(STORE);
}

// Wipe only the entries belonging to one run. The queue DB is origin-wide, so
// on path-routed deploys (study.example.com/<run>/) logging out of run B must
// not destroy run A's still-undrained answers. `runPathPrefix` is the run's
// path with trailing slash (e.g. "/mystudy/"); entries are matched on their
// sync_url pathname. Legacy entries without sync_url can't be attributed to a
// run — they're wiped too, keeping the pre-scoping security posture (plaintext
// answers must not outlive a logout that might be theirs).
export async function wipeQueueForRun(runPathPrefix) {
    const db = await getDb();
    const entries = (await db.getAll(STORE)) || [];
    for (const entry of entries) {
        let belongs = true;
        if (entry.sync_url) {
            try {
                belongs = new URL(entry.sync_url, self.location.href).pathname.indexOf(runPathPrefix) === 0;
            } catch (e) { belongs = true; }
        }
        if (belongs) {
            await db.delete(STORE, entry.uuid);
        }
    }
}

// Normalise a stored entry's envelope before it goes on the wire.
//
// `/form-sync` validates uuid / page / unit_session_id / client_ts strictly and
// answers 400 — which the drain treats as permanent (the entry is blocked until
// a manual Retry) — so a value an older bundle happened to store in a different
// shape must be fixed here rather than stranding the answers. Only the envelope
// is touched; `data`,
// `item_views` and `files` are passed through untouched.
export function normalizeSyncEntry(entry) {
    const out = Object.assign({}, entry);
    out.page = Number(out.page);
    if (!Number.isFinite(out.page) || out.page < 1) out.page = 1;
    out.page = Math.round(out.page);
    const ts = toMysqlDatetime(out.client_ts);
    if (ts === null) delete out.client_ts; else out.client_ts = ts;
    return out;
}

// Build a FormData representation of a queued entry that has `files`. Keys
// mirror form-page-submit's multipart branch (see `RunController::formSyncAction`).
// Pulled out of drainQueue so the multipart shape lives in one place — also
// useful when an entry goes from JSON to multipart between offline-enqueue
// and online-drain (e.g. a future blob attachment).
export function buildSyncFormData(rawEntry) {
    const entry = normalizeSyncEntry(rawEntry);
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
