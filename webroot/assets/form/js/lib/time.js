// Time helpers used across the form bundle.
//
// `mysqlDatetime()` is the canonical format for any timestamp the server
// will land in a MariaDB DATETIME column (item-views, queued client_ts,
// etc.). ISO-8601 with `.sssZ` 500s on `survey_form_submissions.client_ts`
// — that bug was the canary; this helper is the fix.

export function mysqlDatetime() {
    return new Date().toISOString().slice(0, 19).replace('T', ' ');
}

// Coerce an arbitrary stored timestamp into the DATETIME literal above, or
// null when it can't be. `/form-sync` validates `client_ts` strictly and 400s
// anything else BEFORE applying the answers (a malformed value used to blow up
// at INSERT time, after the apply, which made the client retry into a
// double-write). A 400 stops the whole drain, so an entry written by an older
// bundle — or by any code path that reached for `toISOString()` directly —
// would wedge the queue forever. Normalise on the way out instead: an
// ISO-8601 string loses its `T`/`.sssZ`, anything unparseable is dropped
// (client_ts is optional paradata; the answers matter, its timestamp doesn't).
export function toMysqlDatetime(value) {
    if (value == null || value === '') return null;
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)) {
        return value;
    }
    const d = new Date(value);
    if (isNaN(d.getTime())) return null;
    return d.toISOString().slice(0, 19).replace('T', ' ');
}

// "00:42" / "01:05" — used by the audio recorder to display recording length.
export function formatDuration(sec) {
    const m = Math.floor(sec / 60).toString().padStart(2, '0');
    const s = Math.floor(sec % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
}
