// Drives bin/queue.php in --once mode for deterministic tests. The
// daemon's normal mode loops forever with sleep ticks; --once does a
// single processQueue() pass and exits.
//
// Output is logged through formr's `error_log` to a file under tmp/, so
// stdout/stderr from this command is usually empty. We rely on the exit
// code only.

const { execFileSync } = require('node:child_process');

function runQueueOnce({ debug = false } = {}) {
    const args = ['exec', '-i', 'formr_app', 'php', '/var/www/formr/bin/queue.php', '-t', 'UnitSession', '--once'];
    try {
        const out = execFileSync('docker', args, { encoding: 'utf8', stdio: debug ? 'inherit' : 'pipe' });
        return { ok: true, stdout: out };
    } catch (e) {
        return { ok: false, stdout: e.stdout?.toString() || '', stderr: e.stderr?.toString() || '', code: e.status };
    }
}

module.exports = { runQueueOnce };
