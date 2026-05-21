// Direct DB access for e2e tests, via `docker exec formr_db mariadb`.
//
// The test environment can shell out to formr_db (proven by
// tests/pwa_manifest_smoke.sh:34-39). Reads the dev DB credentials from
// /home/admin/formr-docker/.env, which is the canonical secret store on
// this host (gitignored). Tests on other hosts can override via
// FORMR_DB_PASSWORD / FORMR_DB_USER / FORMR_DB_NAME env vars.

const { execFileSync } = require('node:child_process');
const fs = require('node:fs');

let CREDS = null;
function creds() {
    if (CREDS) return CREDS;
    let pwd = process.env.FORMR_DB_PASSWORD;
    let usr = process.env.FORMR_DB_USER || 'formr_user';
    let dbn = process.env.FORMR_DB_NAME || 'formr_db';
    if (!pwd) {
        // Pull from /home/admin/formr-docker/.env if available (dev host).
        try {
            const env = fs.readFileSync('/home/admin/formr-docker/.env', 'utf8');
            const m = env.match(/^MARIADB_PASSWORD\s*=\s*"?([^"\n]+)"?/m);
            if (m) pwd = m[1];
            const u = env.match(/^MARIADB_USER\s*=\s*"?([^"\n]+)"?/m);
            if (u) usr = u[1];
            const d = env.match(/^MARIADB_DATABASE\s*=\s*"?([^"\n]+)"?/m);
            if (d) dbn = d[1];
        } catch (_) { /* ignore */ }
    }
    if (!pwd) throw new Error('FORMR_DB_PASSWORD not set and /home/admin/formr-docker/.env unreadable');
    CREDS = { pwd, usr, dbn };
    return CREDS;
}

function dbExecRaw(sql) {
    const { pwd, usr, dbn } = creds();
    const out = execFileSync(
        'docker',
        ['exec', '-i', 'formr_db', 'mariadb', '-u', usr, `-p${pwd}`, dbn, '-N', '-B'],
        { input: sql, encoding: 'utf8' }
    );
    return out;
}

// Returns rows as arrays of objects keyed by column name. Tab-delimited
// row format from `mariadb -B`; first row is the column header.
function dbQuery(sql) {
    const { pwd, usr, dbn } = creds();
    const out = execFileSync(
        'docker',
        ['exec', '-i', 'formr_db', 'mariadb', '-u', usr, `-p${pwd}`, dbn, '-B'],
        { input: sql, encoding: 'utf8' }
    );
    const lines = out.split('\n').filter((l) => l.length > 0);
    if (lines.length === 0) return [];
    const cols = lines[0].split('\t');
    return lines.slice(1).map((line) => {
        const vals = line.split('\t');
        const row = {};
        cols.forEach((c, i) => {
            const v = vals[i];
            row[c] = v === 'NULL' ? null : v;
        });
        return row;
    });
}

// Returns the survey_unit_sessions row for the given id, or null.
// Each DATETIME column comes back as both the raw mariadb string (in
// the server's local TZ — Europe/Berlin in this dev) AND as a
// `*_unix` epoch-seconds variant. Tests should compare timestamps
// against `dbNow()` to avoid local-vs-server TZ confusion.
function dbState(unitSessionId) {
    const rows = dbQuery(
        `SELECT id, run_session_id, unit_id, created, expires, ended, expired,
                queued, result, result_log,
                UNIX_TIMESTAMP(created) AS created_unix,
                UNIX_TIMESTAMP(expires) AS expires_unix,
                UNIX_TIMESTAMP(ended)   AS ended_unix,
                UNIX_TIMESTAMP(expired) AS expired_unix
         FROM survey_unit_sessions WHERE id = ${parseInt(unitSessionId, 10)}`
    );
    if (!rows[0]) return null;
    const r = rows[0];
    // Coerce numeric timestamps to JS numbers (or null when DB returned NULL).
    for (const k of ['created_unix', 'expires_unix', 'ended_unix', 'expired_unix']) {
        r[k] = r[k] === null || r[k] === '' ? null : parseInt(r[k], 10);
    }
    return r;
}

// Returns the DB's current epoch second. Use as the reference clock in
// tests instead of Date.now(): JS host time and DB server time may
// differ by minutes if either drifts.
function dbNow() {
    const rows = dbQuery(`SELECT UNIX_TIMESTAMP(NOW()) AS now`);
    return parseInt(rows[0].now, 10);
}

// Returns the row in the study's results table, or null.
function dbResultsRow(resultsTable, unitSessionId) {
    if (!/^[a-zA-Z0-9_]+$/.test(resultsTable)) {
        throw new Error(`unsafe results_table: ${resultsTable}`);
    }
    const rows = dbQuery(
        `SELECT * FROM \`${resultsTable}\` WHERE session_id = ${parseInt(unitSessionId, 10)}`
    );
    return rows[0] || null;
}

// Backdate a unit-session by `minutes` minutes. Both `created` and
// `expires` shift by the same delta so relative semantics hold; tests
// assert against the resulting absolute clock distance.
function backdateUnitSession(unitSessionId, minutes) {
    const m = parseInt(minutes, 10);
    const id = parseInt(unitSessionId, 10);
    dbExecRaw(
        `UPDATE survey_unit_sessions SET
            created = DATE_SUB(created, INTERVAL ${m} MINUTE),
            expires = IF(expires IS NULL, NULL, DATE_SUB(expires, INTERVAL ${m} MINUTE))
         WHERE id = ${id}`
    );
}

// Backdate items_display.saved for items belonging to the given session.
// Use to simulate "user has been editing for X minutes". `itemId=null`
// applies to every item belonging to the session.
function backdateItemsDisplay(unitSessionId, minutes, itemId = null) {
    const m = parseInt(minutes, 10);
    const id = parseInt(unitSessionId, 10);
    const where = itemId === null
        ? `session_id = ${id}`
        : `session_id = ${id} AND item_id = ${parseInt(itemId, 10)}`;
    dbExecRaw(
        `UPDATE survey_items_display SET
            created = IF(created IS NULL, NULL, DATE_SUB(created, INTERVAL ${m} MINUTE)),
            saved   = IF(saved   IS NULL, NULL, DATE_SUB(saved,   INTERVAL ${m} MINUTE))
         WHERE ${where}`
    );
}

// Set items_display.saved to NOW - minutesAgo minutes for the given session.
// `itemId=null` applies to every items_display row in the session.
// Use to simulate "user submitted item X at minute Y of their session".
function setItemsDisplaySaved(unitSessionId, minutesAgo, itemId = null) {
    const m = parseInt(minutesAgo, 10);
    const id = parseInt(unitSessionId, 10);
    const where = itemId === null
        ? `session_id = ${id}`
        : `session_id = ${id} AND item_id = ${parseInt(itemId, 10)}`;
    dbExecRaw(
        `UPDATE survey_items_display SET saved = DATE_SUB(NOW(), INTERVAL ${m} MINUTE)
         WHERE ${where}`
    );
}

// Set items_display.created (when the row was first instantiated) to
// NOW - minutesAgo. `getUnitSessionLastVisit` falls back to `created`
// when `saved` is NULL, so this simulates "user has loaded the page but
// not submitted yet" at a specific time in the past.
function setItemsDisplayCreated(unitSessionId, minutesAgo, itemId = null) {
    const m = parseInt(minutesAgo, 10);
    const id = parseInt(unitSessionId, 10);
    const where = itemId === null
        ? `session_id = ${id}`
        : `session_id = ${id} AND item_id = ${parseInt(itemId, 10)}`;
    dbExecRaw(
        `UPDATE survey_items_display SET created = DATE_SUB(NOW(), INTERVAL ${m} MINUTE)
         WHERE ${where}`
    );
}

// Set survey_unit_sessions.created (the "invitation_sent" anchor) to a
// specific moment in the past. Use this in matrix tests instead of
// backdateUnitSession() when you need an absolute anchor; backdate
// shifts both `created` and `expires`, which is awkward when the test
// wants `expires` to be (re)computed by the queue from a fresh state.
function setUnitSessionCreated(unitSessionId, minutesAgo) {
    const m = parseInt(minutesAgo, 10);
    const id = parseInt(unitSessionId, 10);
    dbExecRaw(
        `UPDATE survey_unit_sessions SET created = DATE_SUB(NOW(), INTERVAL ${m} MINUTE)
         WHERE id = ${id}`
    );
}

// Force survey_unit_sessions.expires to a specific moment relative to NOW.
// Use to drive the queue daemon's WHERE expires <= NOW() filter directly.
function setUnitSessionExpires(unitSessionId, minutesAgoOrAhead) {
    const m = parseInt(minutesAgoOrAhead, 10);
    const id = parseInt(unitSessionId, 10);
    const op = m >= 0 ? 'DATE_SUB' : 'DATE_ADD';
    const abs = Math.abs(m);
    dbExecRaw(
        `UPDATE survey_unit_sessions SET expires = ${op}(NOW(), INTERVAL ${abs} MINUTE)
         WHERE id = ${id}`
    );
}

// Manually insert a unit-session row for the given run-session/unit pair.
// Bypasses the moveOn → createUnitSession path so tests can construct
// arbitrary scenarios without driving the participant flow. Returns the
// inserted row's id.
function insertUnitSession(runSessionId, unitId, { queued = 0 } = {}) {
    const rs = parseInt(runSessionId, 10);
    const u  = parseInt(unitId, 10);
    const q  = parseInt(queued, 10);
    dbExecRaw(
        `INSERT INTO survey_unit_sessions (unit_id, run_session_id, created, queued)
         VALUES (${u}, ${rs}, NOW(), ${q})`
    );
    const rows = dbQuery(
        `SELECT id FROM survey_unit_sessions
         WHERE run_session_id = ${rs} AND unit_id = ${u}
         ORDER BY id DESC LIMIT 1`
    );
    return parseInt(rows[0].id, 10);
}

// Backdate the run_session for the given id.
function backdateRunSession(runSessionId, minutes) {
    const m = parseInt(minutes, 10);
    const id = parseInt(runSessionId, 10);
    dbExecRaw(
        `UPDATE survey_run_sessions SET
            created = DATE_SUB(created, INTERVAL ${m} MINUTE),
            last_access = IF(last_access IS NULL, NULL, DATE_SUB(last_access, INTERVAL ${m} MINUTE))
         WHERE id = ${id}`
    );
}

module.exports = {
    dbQuery, dbExecRaw, dbNow,
    dbState, dbResultsRow,
    backdateUnitSession, backdateItemsDisplay, backdateRunSession,
    setItemsDisplaySaved, setItemsDisplayCreated,
    setUnitSessionCreated, setUnitSessionExpires,
    insertUnitSession,
};
