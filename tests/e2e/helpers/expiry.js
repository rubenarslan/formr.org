// Wraps `bin/expiry_fixture.php` invocations and returns the parsed
// JSON descriptor.
//
// Each test that calls provision() gets a fresh fixture; the underlying
// PHP script is idempotent on `run_name` so tests can re-run safely.

const { execFileSync } = require('node:child_process');

function provision({ x = 0, y = 0, z = 0, items = 1, paging = 0, owner, name, useExisting = false, pause = null, withUnitSession = false } = {}) {
    const args = ['exec', '-i', 'formr_app', 'php', '/var/www/formr/bin/expiry_fixture.php',
        `--x=${x}`, `--y=${y}`, `--z=${z}`,
        `--items=${items}`, `--paging=${paging}`,
    ];
    if (owner) args.push(`--owner=${owner}`);
    if (name) args.push(`--name=${name}`);
    if (useExisting) args.push('--use-existing');
    if (pause) args.push(`--pause=${JSON.stringify(pause)}`);
    if (withUnitSession) args.push('--with-unit-session');

    const out = execFileSync('docker', args, { encoding: 'utf8' });
    const last = out.trim().split('\n').pop();
    return JSON.parse(last);
}

// Compute getUnitSessionExpirationData() against the current DB state
// for a unit-session, returning the algorithm's verdict without firing
// the queue's expire() side effect. Use this in matrix tests to assert
// what the algorithm WOULD do "right now" given the state we set up.
function computeExpiry(unitSessionId) {
    const out = execFileSync('docker',
        ['exec', '-i', 'formr_app', 'php', '/var/www/formr/bin/expiry_compute.php',
         `--unit-session-id=${parseInt(unitSessionId, 10)}`],
        { encoding: 'utf8' });
    return JSON.parse(out.trim().split('\n').pop());
}

module.exports = { provision, computeExpiry };
