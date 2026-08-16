// Drives bin/queue.php in --once mode for deterministic tests. The
// daemon's normal mode loops forever with sleep ticks; --once does a
// single processQueue() pass and exits.
//
// Output is logged through formr's `error_log` to a file under tmp/, so
// stdout/stderr from this command is usually empty. We rely on the exit
// code only.
//
// Cron toggle: the rescov-* fixture runs are kept at cron_active = 0
// between test sessions (2026-08-16, LOG_STATE_AUDIT.md) because the
// permanently-failing fixtures otherwise retry forever and mail the
// study admin once per throttle window. The queue pickup filters
// cron_active = 1 in SQL, so a --once pass would see nothing. Enable
// the fixture runs for exactly the duration of the pass and always
// disable them again — the finally runs even when the pass errors, so
// a crashed spec can't leave the spam tap open.

const { execFileSync } = require('node:child_process');
const { dbExecRaw } = require('./db');

function setFixtureCron(active) {
    dbExecRaw(`UPDATE survey_runs SET cron_active = ${active ? 1 : 0} WHERE name LIKE 'rescov-%';`);
}

function runQueueOnce({ debug = false } = {}) {
    const args = ['exec', '-i', 'formr_app', 'php', '/var/www/formr/bin/queue.php', '-t', 'UnitSession', '--once'];
    setFixtureCron(true);
    try {
        const out = execFileSync('docker', args, { encoding: 'utf8', stdio: debug ? 'inherit' : 'pipe' });
        return { ok: true, stdout: out };
    } catch (e) {
        return { ok: false, stdout: e.stdout?.toString() || '', stderr: e.stderr?.toString() || '', code: e.status };
    } finally {
        setFixtureCron(false);
    }
}

module.exports = { runQueueOnce, setFixtureCron };
