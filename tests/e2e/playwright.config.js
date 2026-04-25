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

// BrowserStack projects only when credentials are present. Lets `npm run
// test:e2e` work locally without BrowserStack creds.
if (BS_USERNAME && BS_ACCESS_KEY) {
    projects.push({
        name: 'bs-iphone-15',
        use: {
            connectOptions: {
                wsEndpoint: bsWsEndpoint(bsCaps({
                    os: 'ios',
                    osVersion: '17',
                    browser: 'iphone',
                    browserVersion: 'latest',
                    deviceName: 'iPhone 15',
                    realMobile: 'true',
                    name: 'iPhone 15 Safari',
                })),
            },
        },
    });
    projects.push({
        name: 'bs-pixel-8',
        use: {
            connectOptions: {
                wsEndpoint: bsWsEndpoint(bsCaps({
                    os: 'android',
                    osVersion: '14.0',
                    browser: 'chrome',
                    browserVersion: 'latest',
                    deviceName: 'Google Pixel 8',
                    realMobile: 'true',
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
