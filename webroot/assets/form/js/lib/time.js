// Time helpers used across the form bundle.
//
// `mysqlDatetime()` is the canonical format for any timestamp the server
// will land in a MariaDB DATETIME column (item-views, queued client_ts,
// etc.). ISO-8601 with `.sssZ` 500s on `survey_form_submissions.client_ts`
// — that bug was the canary; this helper is the fix.

export function mysqlDatetime() {
    return new Date().toISOString().slice(0, 19).replace('T', ' ');
}

// "0:42" / "1:05" — used by the audio recorder to display recording length.
export function formatDuration(sec) {
    const m = Math.floor(sec / 60).toString().padStart(2, '0');
    const s = Math.floor(sec % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
}
