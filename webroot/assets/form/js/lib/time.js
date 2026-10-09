// Time helpers used across the form bundle.
//
// `mysqlDatetime()` is the canonical format for any timestamp the server
// will land in a MariaDB DATETIME column (item-views, queued client_ts,
// etc.): `/form-sync` refuses ISO-8601 with `.sssZ` (FormSyncPayload). It is
// UTC, whatever the participant's time zone; the server stores it in its own
// (client_time).

export function mysqlDatetime() {
    return new Date().toISOString().slice(0, 19).replace('T', ' ');
}

// "00:42" / "01:05" — used by the audio recorder to display recording length.
export function formatDuration(sec) {
    const m = Math.floor(sec / 60).toString().padStart(2, '0');
    const s = Math.floor(sec % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
}
