// A submit button labelled in R on a form_v2 page.
//
// FormRenderer leaves every dynamic label to /form-render-page, which the
// page calls when it is shown. A submit item has no label element: its
// resolved label is its button's text. The page used to show the built-in
// Next / Submit instead — the empty label was taken for a bare page break,
// tagged as navigation wording — and the resolved label was dropped.
// Everything is created here and deleted in afterAll: one v2 study, one run.

const { request } = require('@playwright/test');
const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { freshParticipant } = require('./helpers/participant');
const { STATE_PATH, ensureAdminState } = require('./helpers/admin');
const { provisioner } = require('./helpers/provision');
const v2 = require('./helpers/v2Form');

const SFX = Date.now().toString(36);
const STUDY = {
    engine: 'v2',
    json: {
        name: `e2e_dynsub_${SFX}`, settings: {},
        items: [
            { type: 'text', name: 'ds_q1', label: 'First', optional: 1 },
            { type: 'submit', name: 'ds_s1', label: 'Weiter zu Teil `r 1 + 1`' },
            { type: 'text', name: 'ds_q2', label: 'Second', optional: 1 },
            { type: 'submit', name: 'ds_s2', label: 'Fertig' },
        ],
    },
};
const RUN = `e2e-dynsub-${SFX}`;

test.describe('a submit button labelled in R', () => {
    test.skip(RUNNING_ON_BS, 'provisions through the admin');
    const prov = provisioner();
    let admin;

    test.beforeAll(async ({ browser }) => {
        test.setTimeout(120000);
        await ensureAdminState(browser);
        admin = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        const study = await prov.uploadStudy(admin, STUDY);
        await prov.createRun(admin, RUN, [{ type: 'Survey', studyId: study.id }, { type: 'Page', body: `END_${SFX}` }]);
    });

    test.afterAll(async () => {
        if (admin) {
            await prov.cleanup(admin);
            await admin.dispose();
        }
    });

    test("shows the author's words once its page resolves", async ({ page, baseURL }) => {
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const button = page.locator('.item-submit button[type="submit"]').first();
        await expect(button).toHaveText(/Weiter zu Teil 2/, { timeout: 15000 });
        // …and the navigation wording does not take it back.
        expect(await button.getAttribute('data-fmr-nav-label')).toBeNull();
    });
});
