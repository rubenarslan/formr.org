// IndexedDB-backed offline submission queue.
//
// One database (`formrQueue`), one object store (`queue`), keyPath `uuid`
// (server's dedupe key), single index `client_ts` for ordered drain. Replaces
// the hand-rolled `openQueueDB` / `idbTx` / queuePut-Get-Delete promise
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
            // The browser closed the connection (storage eviction, WebKit
            // after the installed app was in the background).
            terminated() { dbPromise = null; },
        });
        // A failed open is not kept either: the next call tries again.
        dbPromise.catch(() => { dbPromise = null; });
    }
    return dbPromise;
}

// Run one queue operation on the connection — and once more on a new one
// when the old one turns out to be closed. WebKit can drop the connection of
// an installed app that was in the background without a close event; every
// call then failed (InvalidStateError) until a reload, and a page the
// participant sent offline could not be queued.
async function onDb(op) {
    try {
        return await op(await getDb());
    } catch (err) {
        if (!err || !['InvalidStateError', 'UnknownError', 'TransactionInactiveError'].includes(err.name)) throw err;
        dbPromise = null;
        return op(await getDb());
    }
}

// Store an entry, or overwrite a stored one in place (same keyPath). The drain
// uses the overwrite to persist retry bookkeeping (`attempts`,
// `next_retry_at`, `state`, `errors`) so backoff survives a reload — the drain
// runs on every load and every `online` event, and without persisted state a
// permanently-rejected entry is re-POSTed on each of them.
export async function queuePut(entry) {
    return onDb(async (db) => {
        await db.put(STORE, entry);
        return entry;
    });
}

// Go over every entry in one readwrite transaction: `edit(entry)` returns
// 'delete', true (changed in place: put back) or false (left alone).
// Resolves to the number of entries deleted or put back.
async function editEntries(edit) {
    return onDb(async (db) => {
        const tx = db.transaction(STORE, 'readwrite');
        const entries = (await tx.store.getAll()) || [];
        let edited = 0;
        for (const e of entries) {
            const outcome = edit(e);
            if (outcome === 'delete') tx.store.delete(e.uuid);
            else if (outcome) tx.store.put(e);
            if (outcome) edited += 1;
        }
        await tx.done;
        return edited;
    });
}

export async function queueGetAll() {
    return onDb(async (db) => {
        return (await db.getAll(STORE)) || [];
    });
}

export async function queueDelete(uuid) {
    return onDb(async (db) => {
        await db.delete(STORE, uuid);
    });
}

// Does a queue entry belong to `owner` ({sync_url, unit_session_id})? The one
// rule for the drain, the queue summary, the queued-behind check and the
// helpers below. `anyUnitSession` matches on the run alone.
export function entryBelongsTo(e, { sync_url, unit_session_id }, { anyUnitSession = false } = {}) {
    if (e.sync_url !== sync_url) return false;
    return anyUnitSession || Number(e.unit_session_id) === Number(unit_session_id || 0);
}

// Delete every entry that targets the same page of the same unit session as
// `entry` (except `entry` itself, when given). A page re-submitted — after a
// validation rejection, or simply re-visited via Previous — supersedes the
// earlier queued copy: draining the old one after the new one landed would
// overwrite the accepted answers with the stale ones, and a `needs_attention`
// copy would wedge the run's queue behind an error the participant already
// fixed. `sync_url` scopes the match to one run (the DB is origin-wide).
export async function queueSupersede({ sync_url, unit_session_id, page }, keepUuid) {
    return editEntries((e) => {
        if (keepUuid && e.uuid === keepUuid) return false;
        if (Number(e.page) !== Number(page)) return false;
        return entryBelongsTo(e, { sync_url, unit_session_id }) ? 'delete' : false;
    });
}

// A final page drains last within its unit session (it ends the form), so a
// page queued after it — a revisit — lands first. The final entry's `hidden`
// list is then older than the revisit's and would override what the revisit
// revealed: give the waiting final page the newer snapshot.
export async function queueRestampFinal({ sync_url, unit_session_id }, hidden) {
    return editEntries((e) => {
        if (!e.final || !entryBelongsTo(e, { sync_url, unit_session_id })) return false;
        e.hidden = (hidden || []).slice();
        return true;
    });
}

// ---- Retry state -----------------------------------------------------------
//
// Every entry starts as STATE_PENDING, which an entry carries implicitly:
// none is written with a `state`. The drain moves it to:
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
export async function queueResetRetry() {
    return editEntries((e) => {
        if (entryState(e) === STATE_NEEDS_ATTENTION) return false;
        if (!e.attempts && !e.next_retry_at && entryState(e) === STATE_PENDING) return false;
        e.state = STATE_PENDING;
        e.attempts = 0;
        e.next_retry_at = null;
        delete e.last_error;
        return true;
    });
}

// Wipe only the entries belonging to one run. The queue DB is origin-wide, so
// on path-routed deploys (study.example.com/<run>/) logging out of run B must
// not destroy run A's still-undrained answers. `runPathPrefix` is the run's
// path with trailing slash (e.g. "/mystudy/"); entries are matched on their
// sync_url pathname. An entry whose run cannot be read is wiped too:
// plaintext answers must not outlive a logout that might be theirs.
export async function wipeQueueForRun(runPathPrefix) {
    return editEntries((entry) => {
        let belongs = true;
        if (entry.sync_url) {
            try {
                belongs = new URL(entry.sync_url, self.location.href).pathname.indexOf(runPathPrefix) === 0;
            } catch (e) { belongs = true; }
        }
        return belongs ? 'delete' : false;
    });
}

// The multipart shape of a v2 write, for a live submit and for a queued
// entry alike (FormSubmissionService::readPayload reads both): the envelope
// (page, unit_session_id, final, doc, seq, base, prev[], and for the queue
// uuid + client_ts),
// data[name] / data[name][], item_views[bucket][id], hidden[] and
// files[name] for each File beside the answers. One builder, so the two
// transports cannot drift apart.
export function buildFormData(entry) {
    const fd = new FormData();
    if (entry.uuid) fd.append('uuid', entry.uuid);
    fd.append('page', String(entry.page));
    if (entry.unit_session_id != null) fd.append('unit_session_id', String(entry.unit_session_id));
    if (entry.client_ts) fd.append('client_ts', entry.client_ts);
    if (entry.final) fd.append('final', '1');
    if (entry.doc) { fd.append('doc', entry.doc); fd.append('seq', String(entry.seq || 0)); }
    if (entry.base != null) fd.append('base', String(entry.base));
    (entry.prev || []).forEach((d) => fd.append('prev[]', d));
    (entry.hidden || []).forEach((id) => fd.append('hidden[]', String(id)));
    const data = entry.data || {};
    Object.keys(data).forEach((k) => {
        const v = data[k];
        if (Array.isArray(v) && v.length === 0) {
            // Nothing ticked: post the empty scalar v1's hidden placeholder
            // posts, so an answered-empty multiple choice is stored as such
            // on both transports (readPayload maps JSON's [] to it too).
            fd.append(`data[${k}]`, '');
        } else if (Array.isArray(v)) {
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

// POST one v2 write (a live page submit or a queued entry): multipart via
// buildFormData when it carries files, JSON otherwise. Resolves {res, body};
// throws on network failure.
export async function postWrite(url, payload) {
    const multipart = !!(payload.files && Object.keys(payload.files).length);
    const headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
    if (!multipart) headers['Content-Type'] = 'application/json';
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: multipart ? buildFormData(payload) : JSON.stringify(payload),
    });
    const body = await res.json().catch(() => null);
    return { res, body };
}
