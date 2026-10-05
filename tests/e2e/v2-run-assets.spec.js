// A run's own CSS and JS (its settings) load on its form_v2 pages, as on
// every other page of the run. Everything is created here and deleted in
// afterAll: one study, one run.

const { request } = require('@playwright/test');
const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { freshParticipant } = require('./helpers/participant');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');
const { provisioner, AJAX } = require('./helpers/provision');
const v2 = require('./helpers/v2Form');

const SFX = Date.now().toString(36);
const NAME = `e2e_runassets_${SFX}`;
const RUN = `e2e-runassets-${SFX}`;

test.describe("a run's CSS and JS on form_v2 pages", () => {
    test.skip(RUNNING_ON_BS, 'provisions through the admin');
    const prov = provisioner();
    let admin;

    test.beforeAll(async ({ browser }) => {
        test.setTimeout(120000);
        await ensureAdminState(browser);
        admin = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        const study = await prov.uploadStudy(admin, { engine: 'v2', json: { name: NAME, items: [
            { type: 'text', name: 'ra_q1', label: 'Q1', optional: 1 },
            { type: 'submit', name: 'ra_s1', label: 'Done' },
        ] } });
        await prov.createRun(admin, RUN, [{ type: 'Survey', studyId: study.id }, { type: 'Page', body: `END_${SFX}` }]);
        const res = await admin.post(`${ADMIN_BASE}/admin/run/${RUN}/ajax_save_settings`, { headers: AJAX, form: {
            custom_css: '.fmr-form-v2 { outline: 7px solid rgb(1, 2, 3); }',
            custom_js: "window.fmrRunScript = 'loaded';",
        } });
        expect(res.ok(), await res.text()).toBeTruthy();
    });

    test.afterAll(async () => {
        if (admin) {
            await prov.cleanup(admin);
            await admin.dispose();
        }
    });

    test('load on the form page', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        expect(await page.locator('form.fmr-form-v2').evaluate((f) => getComputedStyle(f).outlineColor)).toBe('rgb(1, 2, 3)');
        expect(await page.evaluate(() => window.fmrRunScript)).toBe('loaded');
    });
});
