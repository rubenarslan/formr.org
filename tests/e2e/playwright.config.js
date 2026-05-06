// Playwright config for end-to-end tests against the dev instance.
//
// Two modes:
//   - DEFAULT (`npm run test:e2e`)
//     One project, `local-chromium`. Playwright's bundled chromium against
//     the public dev URL. Service workers blocked because Playwright's CDP
//     target hangs on the dev SW (real devices don't have this pathology).
//
//   - BS (`npm run test:bs`)
//     `npx browserstack-node-sdk playwright test` runs this same config but
//     the SDK monkey-patches Playwright + appends one project per platform
//     in `browserstack.yml` (iPhone 15 Pro Max iOS 17 + Google Pixel 8
//     Android 14). The SDK's monkey-patch makes Playwright tolerate
//     `browserName: 'safari'` (which plain Playwright rejects with
//     "expected one of (chromium|firefox|webkit)"). It only works on
//     Playwright versions BS supports — currently ≤1.57. Pinned in
//     package.json: don't bump @playwright/test past 1.57 without
//     verifying BS still matches.
//
// Credentials come from /home/admin/formr-docker/.env.dev (gitignored)
// and from `browserstack.yml` (BROWSERSTACK_USERNAME / _ACCESS_KEY env vars).

const { defineConfig } = require('@playwright/test');
const dotenv = require('dotenv');
const path = require('node:path');

dotenv.config({ path: path.resolve(__dirname, '../../../.env.dev') });

// Detect "we're under the SDK runner" so the local-only SW-block hack
// doesn't suppress SW behaviour on real devices, where SW is the whole
// point of the BS-only tests.
const RUNNING_ON_BS = process.env.BROWSERSTACK_AUTOMATION === 'true';

module.exports = defineConfig({
    testDir: '.',
    // No globalSetup on this branch — the PWA-persistence specs target a
    // public dev run and a pre-existing test session code, no admin
    // login / test-code minting needed. Cherry-pick form_v2's
    // setup/global-setup.js if a future spec here needs admin auth.
    timeout: 120 * 1000,
    expect: { timeout: 15 * 1000 },
    // BS sessions occasionally drop the websocket bridge or the device
    // returns ERR_CONNECTION_CLOSED on a navigation; one retry absorbs
    // those without papering over real test failures (a real failure
    // reproduces on retry). Local stays at 0.
    retries: RUNNING_ON_BS ? 1 : 0,
    workers: 1,
    reporter: [
        ['list'],
        ['html', { outputFolder: path.resolve(__dirname, '../../playwright-report'), open: 'never' }],
    ],
    use: {
        baseURL: process.env.FORMR_PARTICIPANT_URL || 'https://study.researchmixtape.com',
        // Playwright tracing on iOS Safari (BS) errors with "Unsupported
        // Playwright command on iOS: tracingStartChunk". Disable tracing
        // entirely under BS — videos + screenshots already cover the
        // forensics.
        trace: RUNNING_ON_BS ? 'off' : 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        ignoreHTTPSErrors: true,
        // SW block is a local-Chromium hack — Playwright's CDP target hangs
        // waiting on something the dev SW does. Real devices via BS don't
        // have that pathology and we WANT the SW to load there to verify
        // install, caches, push.
        serviceWorkers: RUNNING_ON_BS ? 'allow' : 'block',
    },
    projects: [
        {
            name: 'local-chromium',
            use: { browserName: 'chromium' },
        },
    ],
});
