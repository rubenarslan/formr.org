// Resolve persistent test-run names to participant URLs.
//
// runs.json is produced by the one-time admin setup runbook
// (tests/e2e/setup/runbook.md) and committed. Each fixture has a v1 and a v2
// run, so a single spec file can iterate `['v1','v2']` and stay in lock-step.
//
// If runs.json is missing or a key isn't populated, the helper throws with a
// pointer to the runbook — that's the only way a fresh checkout discovers
// they need to run Phase 1 once.

const fs = require('node:fs');
const path = require('node:path');

const RUNS_PATH = path.resolve(__dirname, '../setup/runs.json');

let cached = null;

function load() {
    if (cached) return cached;
    if (!fs.existsSync(RUNS_PATH)) {
        throw new Error(
            `tests/e2e/setup/runs.json not found.\n` +
            `Run the admin setup runbook once (tests/e2e/setup/runbook.md) ` +
            `to create the persistent test runs and emit runs.json.`,
        );
    }
    cached = JSON.parse(fs.readFileSync(RUNS_PATH, 'utf8'));
    return cached;
}

function runName(suite, variant) {
    const r = load();
    const entry = r[suite] && r[suite][variant];
    if (!entry || !entry.run) {
        throw new Error(
            `runs.json missing ${suite}.${variant}.run — re-check the Phase 1 runbook.`,
        );
    }
    return entry.run;
}

function participantPath(suite, variant) {
    return `/${runName(suite, variant)}/`;
}

// Absolute URL for browser navigation. Tests should pass `baseURL` from the
// `{ baseURL }` fixture so Playwright's config drives it.
function participantUrl(baseURL, suite, variant) {
    if (!baseURL) {
        throw new Error('participantUrl: baseURL is required (pass the {baseURL} fixture).');
    }
    return baseURL.replace(/\/+$/, '') + participantPath(suite, variant);
}

module.exports = { runName, participantPath, participantUrl };
