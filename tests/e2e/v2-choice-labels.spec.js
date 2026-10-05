// R in a select's choice labels (form_v2). FormRenderer puts a placeholder
// <span> into the <option>, which only a parser with customizable <select>
// keeps (Chrome since 2025); Firefox, and older Chrome and Safari, drop it.
// A page whose only R is such a label must still be resolved. Everything is
// created here and deleted in afterAll: one study, one run.

const { request } = require('@playwright/test');
const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { freshParticipant } = require('./helpers/participant');
const { STATE_PATH, ensureAdminState } = require('./helpers/admin');
const { provisioner } = require('./helpers/provision');
const v2 = require('./helpers/v2Form');

const SFX = Date.now().toString(36);
const NAME = `e2e_choicelabels_${SFX}`;
const RUN = `e2e-choicelabels-${SFX}`;
const ITEMS = [
    { type: 'select_one', name: 'cl_pick', label: 'Pick one', optional: 1, choice_list: 'rlabels',
        choices: { 1: '`r paste("Option", 10 + 1)`', 2: 'Plain two' } },
    { type: 'submit', name: 'cl_s1', label: 'Done' },
];

test.describe('form_v2: R in the choice labels of a select', () => {
    test.skip(RUNNING_ON_BS, 'provisions through the admin');
    const prov = provisioner();
    let admin;

    test.beforeAll(async ({ browser }) => {
        test.setTimeout(120000);
        await ensureAdminState(browser);
        admin = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        const study = await prov.uploadStudy(admin, { engine: 'v2', json: { name: NAME, items: ITEMS } });
        await prov.createRun(admin, RUN, [{ type: 'Survey', studyId: study.id }, { type: 'Page', body: `END_${SFX}` }]);
    });

    test.afterAll(async () => {
        if (admin) {
            await prov.cleanup(admin);
            await admin.dispose();
        }
    });

    test('a page whose only R is a choice label gets the label', async ({ page, baseURL }) => {
        // The document as a parser without customizable <select> builds it:
        // no markup inside an <option>.
        await page.route((u) => u.pathname === `/${RUN}/`, async (route) => {
            const res = await route.fetch();
            const html = (await res.text()).replace(/(<option\b[^>]*>)\s*<span\b[^>]*data-fmr-choice-id[^>]*><\/span>/g, '$1');
            await route.fulfill({ response: res, body: html });
        });
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        expect(await page.locator('[data-fmr-choice-id]').count(), 'the placeholder is gone, as in Firefox').toBe(0);
        await expect(page.locator('select[name="cl_pick"] option[value="1"]')).toHaveText('Option 11', { timeout: 15000 });
    });
});
