// Playwright config for end-to-end tests against the dev instance.
//
// Three projects:
//   - local-chromium  — runs on this box, fastest, no BrowserStack quota.
//                       `npm run test:e2e`.
//   - bs-iphone-15    — real iPhone 15 / iOS 17 / Safari via BrowserStack
//                       Automate. Use for WebKit-only regressions, PWA
//                       install behavior, anything iOS-finicky.
//   - bs-pixel-8      — real Pixel 8 / Android 14 / Chrome via BrowserStack.
//
// No BrowserStackLocal tunnel — we point the remote devices at the public
// dev URL (https://study.researchmixtape.com / formr.researchmixtape.com),
// which is internet-routable. If you need to test against a local-only
// build, switch on the tunnel + flip `bstack:options.local` to true.
//
// Credentials come from /home/admin/formr-docker/.env.dev (gitignored).

const { defineConfig } = require('@playwright/test');
const dotenv = require('dotenv');
const path = require('node:path');

// .env.dev lives one level up from formr_source/.
dotenv.config({ path: path.resolve(__dirname, '../../../.env.dev') });

const BS_USERNAME = process.env.BROWSERSTACK_USERNAME;
const BS_ACCESS_KEY = process.env.BROWSERSTACK_ACCESS_KEY;

const buildName = process.env.FORMR_BS_BUILD || `formr form_v2 smoke ${new Date().toISOString().slice(0, 16)}`;

function bsCaps(extra) {
    return Object.assign({
        'browserstack.username': BS_USERNAME,
        'browserstack.accessKey': BS_ACCESS_KEY,
        'browserstack.local': 'false',
        'browserstack.idleTimeout': 300,
        'browserstack.networkLogs': true,
        'browserstack.consoleLogs': 'errors',
        build: buildName,
        project: 'formr',
    }, extra);
}

function bsWsEndpoint(caps) {
    return `wss://cdp.browserstack.com/playwright?caps=${encodeURIComponent(JSON.stringify(caps))}`;
}

const projects = [
    // Default: local Chromium against the public dev URL. Fastest loop.
    // serviceWorkers: 'block' is a workaround — Playwright's CDP target
    // gets stuck waiting on something the SW does (registration race or
    // a fetch the SW intercepts), and `page.content()` / `page.evaluate()`
    // hang for the full test timeout. Real-device tests on BrowserStack
    // don't have this issue. Local tests therefore can't verify SW
    // behavior; route those to the BrowserStack projects instead.
    {
        name: 'local-chromium',
        use: { browserName: 'chromium', serviceWorkers: 'block' },
    },
];

// BrowserStack projects: WIRED BUT NOT WORKING YET.
//
// 2026-04-25 attempt: REST creds verify (plan = Automate Mobile, 5
// parallel slots). Connect call hits the right BS endpoint and creates
// builds visible on the dashboard, but the Playwright handshake fails
// with one of:
//   - "browserName: expected one of (chromium|firefox|webkit)" (iOS,
//     regardless of `browser` cap value)
//   - "Malformed endpoint. Did you use BrowserType.launchServer
//     method?" (Android Chrome with playwright-chromium)
// Both errors look like a wire-protocol mismatch between
// @playwright/test ^1.59.1 (current) and BS's CDP endpoint. Best
// hypothesis: BS supports an older Playwright protocol; pin Playwright
// to a version on their support matrix. Alt: switch to the
// browserstack-node-sdk wrapper (already installed; lacks an explicit
// 'playwright' subcommand on the version we have, so a different
// integration approach may be needed).
//
// Disabling these projects so `npm run test:bs` doesn't fire half-
// configured runs that burn parallel slots. Re-enable once the cap /
// version mismatch is resolved.
const ENABLE_BS = false;
if (ENABLE_BS && BS_USERNAME && BS_ACCESS_KEY) {
    projects.push({
        name: 'bs-iphone-15',
        use: {
            browserName: 'chromium',
            connectOptions: {
                wsEndpoint: bsWsEndpoint(bsCaps({
                    browser: 'playwright-webkit',
                    os: 'ios',
                    os_version: '17',
                    device: 'iPhone 15',
                    real_mobile: 'true',
                    name: 'iPhone 15 Safari',
                })),
            },
        },
    });
    projects.push({
        name: 'bs-pixel-8',
        use: {
            browserName: 'chromium',
            connectOptions: {
                wsEndpoint: bsWsEndpoint(bsCaps({
                    browser: 'playwright-chromium',
                    os: 'android',
                    os_version: '14.0',
                    device: 'Google Pixel 8',
                    real_mobile: 'true',
                    name: 'Pixel 8 Chrome',
                })),
            },
        },
    });
}

module.exports = defineConfig({
    testDir: '.',
    timeout: 120 * 1000,
    expect: { timeout: 15 * 1000 },
    retries: 0,
    workers: 1,
    reporter: [
        ['list'],
        ['html', { outputFolder: path.resolve(__dirname, '../../playwright-report'), open: 'never' }],
    ],
    use: {
        baseURL: process.env.FORMR_PARTICIPANT_URL || 'https://study.researchmixtape.com',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        ignoreHTTPSErrors: true,
    },
    projects,
});
