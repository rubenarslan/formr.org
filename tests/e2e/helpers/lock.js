// Hold a MariaDB GET_LOCK from a detached background subprocess so a
// Playwright test can trigger a request that races against the
// run-session named lock.
//
// `RunSession::execute()` at application/Model/RunSession.php:188 tries
// `GET_LOCK('run_session_<id>', $timeout)`. For user requests the
// default timeout is 10 s — if we hold the lock for >10 s the user
// request returns the 5-s-reload HTML body. For queue requests the
// timeout is 0.1 s — easy to race.
//
// Usage:
//   const handle = holdRunSessionLock(runSessionId, 15);
//   ...test does its thing, server returns reload HTML...
//   handle.release();   // optional, the subprocess exits on its own

const { spawn } = require('node:child_process');
const fs = require('node:fs');

let CREDS = null;
function creds() {
    if (CREDS) return CREDS;
    let pwd = process.env.FORMR_DB_PASSWORD;
    let usr = process.env.FORMR_DB_USER || 'formr_user';
    let dbn = process.env.FORMR_DB_NAME || 'formr_db';
    if (!pwd) {
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

// Hold the lock for `durationSec` seconds. Spawns a detached
// `docker exec` running mariadb interactively, with a SQL script piped
// in that does GET_LOCK + SLEEP + RELEASE_LOCK. Returns a handle with
// a `release()` that kills the subprocess if you want to release early.
function holdRunSessionLock(runSessionId, durationSec = 15) {
    const { pwd, usr, dbn } = creds();
    const lockName = `run_session_${parseInt(runSessionId, 10)}`;
    const sql = `SELECT GET_LOCK('${lockName}', 5) AS got; SELECT SLEEP(${parseInt(durationSec, 10)}); SELECT RELEASE_LOCK('${lockName}');`;
    const proc = spawn('docker', [
        'exec', '-i', 'formr_db',
        'mariadb', '-u', usr, `-p${pwd}`, dbn, '-N', '-B', '-e', sql,
    ], {
        stdio: ['ignore', 'pipe', 'pipe'],
        detached: false,
    });

    return {
        release: () => {
            try { proc.kill('SIGTERM'); } catch (_) {}
        },
        // Polls IS_USED_LOCK from a separate connection until the lock
        // is held. mariadb -e buffers stdout, so we can't observe the
        // GET_LOCK result through the lock-holding subprocess; we ask
        // the server "is anyone holding this lock?" directly.
        waitAcquired: async () => {
            const { execFileSync } = require('node:child_process');
            const start = Date.now();
            while (Date.now() - start < 5000) {
                try {
                    const out = execFileSync('docker', [
                        'exec', '-i', 'formr_db',
                        'mariadb', '-u', usr, `-p${pwd}`, dbn, '-N', '-B',
                        '-e', `SELECT IS_USED_LOCK('${lockName}')`,
                    ], { encoding: 'utf8' });
                    const heldBy = out.trim();
                    if (heldBy && heldBy !== 'NULL' && heldBy !== '') {
                        return true;
                    }
                } catch (_) { /* retry */ }
                await new Promise((r) => setTimeout(r, 100));
            }
            throw new Error('lock acquisition timed out after 5s');
        },
    };
}

module.exports = { holdRunSessionLock };
