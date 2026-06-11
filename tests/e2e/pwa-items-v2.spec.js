// Behavioural e2e for the v2 PWA items (beyond the presence checks in
// pwa-low-v2.spec.js). Targets the e2e_pwa_low_v2 fixture (run e2e-pwa-l-v2): a
// push_notification + add_to_home_screen item.
//
// v2 renders the whole form into one document and initInstallStack() / the push
// init run at page LOAD over every item (not just the visible page), so the
// install-stack wiring + the regression guards for the add_to_home_screen fix
// (window.AddToHomeScreen factory resolved, no /manifest.json root 404, no init
// TypeError) are all assertable at load — no page walk needed.
//
// Local-chromium covers this wiring. The actual SW registration + push subscribe
// + native install banner are [BS-only] (local-chromium blocks service workers).

const { test, expect } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');

const RUN = () => runName('pwa_low', 'v2');

// Items exist anywhere in the form (page 2 is hidden but present in the DOM).
async function hasPwaItems(page) {
    return (await page.locator('form.fmr-form-v2 .item-add_to_home_screen, form.fmr-form-v2 .item-push_notification').count()) > 0;
}

test.describe('PWA items v2 — install stack', () => {
    test('add_to_home_screen: factory wired, no /manifest.json 404, no init error', async ({ page, baseURL }) => {
        const consoleErrors = [];
        const bad404s = [];
        page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
        page.on('pageerror', (e) => consoleErrors.push(String(e && e.message || e)));
        page.on('response', (r) => { if (r.status() === 404 && /\/manifest\.json(\?|$)/.test(r.url())) bad404s.push(r.url()); });

        await freshParticipant(page, RUN(), { baseURL });
        await expect(page.locator(v2.FORM_SELECTOR).first()).toBeVisible({ timeout: 20000 });
        await v2.waitForBundle(page);
        test.skip(!(await hasPwaItems(page)), 'fixture lacks PWA items; rebuild the runbook');
        await page.waitForTimeout(600); // let initInstallStack() + manifest fetch settle

        // add-to-homescreen is a prebuilt IIFE that only sets the global; the
        // webpack default import binding is undefined (the old bug). The fix calls
        // window.AddToHomeScreen — assert the global factory resolved.
        const wiring = await page.evaluate(() => ({
            factory: typeof window.AddToHomeScreen,
            pwaInstallEls: document.querySelectorAll('pwa-install').length,
            manifestUrl: document.querySelector('pwa-install')?.getAttribute('manifest-url') || null,
            installBtn: document.querySelectorAll('.add-to-homescreen').length,
        }));
        expect(wiring.factory, 'window.AddToHomeScreen must be the global factory').toBe('function');
        expect(wiring.installBtn, 'install button rendered').toBeGreaterThan(0);
        if (wiring.pwaInstallEls > 0) {
            expect(wiring.manifestUrl, 'pwa-install uses the run manifest, not the /manifest.json root probe').toMatch(/\/manifest\/?$/);
        }
        expect(consoleErrors.filter((t) => /AddToHomeScreen|is not a function/.test(t)),
            'no AddToHomeScreen init TypeError').toEqual([]);
        expect(bad404s, 'no /manifest.json root 404').toEqual([]);
    });

    test('add_to_home_screen: captured beforeinstallprompt is handed to <pwa-install>', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await expect(page.locator(v2.FORM_SELECTOR).first()).toBeVisible({ timeout: 20000 });
        await v2.waitForBundle(page);
        test.skip(!(await hasPwaItems(page)), 'fixture lacks PWA items');
        await page.waitForTimeout(600);
        const result = await page.evaluate(async () => {
            if (!document.querySelector('pwa-install')) return 'no-pwa-install';
            const ev = new Event('beforeinstallprompt');
            ev.prompt = () => {}; ev.userChoice = Promise.resolve({ outcome: 'accepted' }); ev.preventDefault = () => {};
            window.dispatchEvent(ev);
            await new Promise((r) => setTimeout(r, 100));
            const btn = document.querySelector('.add-to-homescreen');
            if (btn) btn.click(); // works even though the button is on the hidden page
            await new Promise((r) => setTimeout(r, 150));
            const el = document.querySelector('pwa-install');
            return (el && el.externalPromptEvent != null) ? 'wired' : 'not-wired';
        });
        test.skip(result === 'no-pwa-install', 'run has no manifest → no <pwa-install> (native path n/a)');
        expect(result, 'captured prompt routed to <pwa-install>').toBe('wired');
    });

    test('push_notification: item + permission button present (subscribe is [BS-only])', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await expect(page.locator(v2.FORM_SELECTOR).first()).toBeVisible({ timeout: 20000 });
        await v2.waitForBundle(page);
        test.skip((await page.locator('form.fmr-form-v2 .item-push_notification').count()) === 0, 'no push item in fixture');
        const state = await page.evaluate(() => {
            const btn = document.querySelector('.push-notification-permission');
            const hidden = document.querySelector('.item-push_notification input[type=text], .item-push_notification input');
            return { btn: !!btn, hiddenPresent: !!hidden, vapid: typeof window.vapidPublicKey };
        });
        expect(state.btn, 'push permission button rendered').toBeTruthy();
        expect(state.hiddenPresent, 'push hidden result input present').toBeTruthy();
        expect(state.vapid, 'VAPID public key exposed for the subscribe path').toBe('string');
    });
});

// cookie + request_phone live in the all_widgets fixture (run e2e-aw-v2). Both
// inits run at page load over the whole form, so these are also load-time checks.
const AW_RUN = 'e2e-aw-v2';

test.describe('PWA items v2 — cookie + phone', () => {
    // ROOT CAUSE of the old fixme ("cookieconsent does not initialise in the
    // headless probe, no error"): vanilla-cookieconsent defaults
    // `hideFromBots: true`, and its bot check is a UA regex OR
    // `navigator.webdriver` — true in every Playwright browser. run() then
    // silently no-ops: no #cc-main, no show--consent, nothing to click. Real
    // participants are unaffected (verified interactively end-to-end
    // 2026-06-09: modal opens, accepting writes the cookie, hidden input
    // becomes consent_given). Mask webdriver before any page script runs so
    // the consent stack initialises under automation. NB the same suppression
    // applies on BrowserStack devices: a *required* request_cookie can never
    // be satisfied in an automated run without this mask.
    test('request_cookie: vanilla-cookieconsent initialised + button opens preferences', async ({ page, baseURL }) => {
        await page.addInitScript(() => {
            Object.defineProperty(Object.getPrototypeOf(navigator), 'webdriver', { get: () => false });
        });
        await freshParticipant(page, AW_RUN, { baseURL });
        await expect(page.locator(v2.FORM_SELECTOR).first()).toBeVisible({ timeout: 20000 });
        await v2.waitForBundle(page);
        test.skip((await page.locator('form.fmr-form-v2 .request-cookie-wrapper, form.fmr-form-v2 .item-request_cookie').count()) === 0, 'no request_cookie in fixture');
        await page.waitForTimeout(500);
        // Clicking the item button calls the imported showPreferences() module
        // export (the fix), which shows the vanilla-cookieconsent preferences
        // modal (#cc-main gains show--preferences). The old bug — a bogus
        // window.showPreferences with cookieconsent never initialised — left the
        // click a silent no-op, so #cc-main never appears: this is the regression test.
        const opened = await page.evaluate(async () => {
            const btn = document.querySelector('button.request-cookie');
            if (!btn) return 'no-btn';
            btn.click();
            await new Promise((r) => setTimeout(r, 600));
            // vanilla-cookieconsent toggles show--preferences on <html> and
            // renders its modal under #cc-main.
            const shown = document.documentElement.classList.contains('show--preferences')
                || !!document.querySelector('#cc-main .pm');
            return shown ? 'opened' : 'closed';
        });
        test.skip(opened === 'no-btn', 'cookie button not present');
        expect(opened, 'showPreferences() opens the consent preferences modal').toBe('opened');
    });

    test('request_phone: desktop renders QR encoding the resumable run URL', async ({ page, baseURL }) => {
        await freshParticipant(page, AW_RUN, { baseURL });
        await expect(page.locator(v2.FORM_SELECTOR).first()).toBeVisible({ timeout: 20000 });
        await v2.waitForBundle(page);
        test.skip((await page.locator('form.fmr-form-v2 .request-phone-wrapper, form.fmr-form-v2 .item-request_phone').count()) === 0, 'no request_phone in fixture');
        await page.waitForTimeout(800); // QR render + manifest-logo fetch
        const r = await page.evaluate(() => {
            const w = document.querySelector('.request-phone-wrapper');
            return {
                resumeUrl: (window.formr && window.formr.runResumeUrl) || null,
                qrRendered: !!(w && w.querySelector('.qr-code-container svg')),
                copyBtn: !!(w && w.querySelector('.request-phone-copy')),
            };
        });
        expect(r.resumeUrl, 'resumable run URL injected into window.formr').toBeTruthy();
        expect(r.resumeUrl, 'points at this run').toContain(`/${AW_RUN}/`);
        expect(r.resumeUrl, 'carries the participant code so the phone resumes the same session').toMatch(/[?&]code=/);
        expect(r.qrRendered, 'QR svg rendered on desktop').toBeTruthy();
        expect(r.copyBtn, 'copy-link control present').toBeTruthy();
    });
});
