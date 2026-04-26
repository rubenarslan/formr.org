// Single-test BrowserStack smoke. Designed to ACTUALLY pass on real
// devices — uses the `page` fixture directly (BS only gets one device per
// test, `browser.newContext()` returns about:blank), navigates to the
// publicly-accessible run URL (no admin test_code minting), and asserts
// only what BS can reliably observe (form selector visible, manifest
// link present in head).
//
// Run locally:    npx playwright test --config tests/e2e/playwright.config.js bs-smoke.spec.js
// Run on BS:      npx browserstack-node-sdk playwright test --config tests/e2e/playwright.config.js bs-smoke.spec.js

const { test, expect } = require('@playwright/test');
const { freshParticipant } = require('./helpers/participant');
const { runName, participantPath } = require('./helpers/runs');

test('v2 form renders on real device (page fixture, public URL)', async ({ page, baseURL }) => {
    const run = runName('pwa_low', 'v2'); // PWA-enabled v2 run, publicly accessible
    await freshParticipant(page, run, { baseURL });

    // FIRST: verify the device actually navigated to where we expect.
    // Without this assertion a test can claim to pass while the BS device
    // is still on about:blank — the Playwright terminal and the BS
    // dashboard video disagree, and the dashboard is the truth.
    expect(page.url(), 'page should be on the participant URL, not about:blank').toContain(`/${run}/`);

    // Form is visible. v2-specific selector — the real evidence the
    // FormRenderer fired and the bundle's CSS loaded.
    await expect(page.locator('form.fmr-form-v2').first())
        .toBeVisible({ timeout: 20000 });

    // Manifest link is in the head. Cheap second assertion — confirms the
    // PWA wiring rendered server-side.
    const manifestHref = await page.locator('link[rel="manifest"]').first().getAttribute('href');
    expect(manifestHref).toContain(participantPath('pwa_low', 'v2') + 'manifest');
});
