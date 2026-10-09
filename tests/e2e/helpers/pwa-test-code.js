// Resolves PWA_TEST_CODE for the pwa-manifest + pwa-recovery suites.
// Prefers process.env.PWA_TEST_CODE; falls back to the file written by
// tests/e2e/setup/global-setup.js. Returns '' when neither is present —
// callers should test.skip() in that case.

const fs = require('node:fs');
const path = require('node:path');

const FILE = path.resolve(__dirname, '..', 'setup', 'pwa-test-code.txt');

function getPwaTestCode() {
    if (process.env.PWA_TEST_CODE) return process.env.PWA_TEST_CODE;
    try {
        return fs.readFileSync(FILE, 'utf8').trim();
    } catch {
        return '';
    }
}

module.exports = { getPwaTestCode };
