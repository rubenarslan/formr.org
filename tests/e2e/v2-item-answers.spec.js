// What v2 stores for items whose answer is not one plain input: the ticked
// choices of an mc_multiple, … Each case submits through the browser and
// reads the stored answer back. Everything is created here and deleted in
// afterAll: one study, one run.

const { request } = require('@playwright/test');
const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { freshParticipant } = require('./helpers/participant');
const { STATE_PATH, ensureAdminState } = require('./helpers/admin');
const { provisioner } = require('./helpers/provision');
const { dbQuery } = require('./helpers/db');
const v2 = require('./helpers/v2Form');

const SFX = Date.now().toString(36);
const NAME = `e2e_itemans_${SFX}`;
const RUN = `e2e-itemans-${SFX}`;
const ABC = { 1: 'A', 2: 'B', 3: 'C' };
const ITEMS = [
    { type: 'mc_multiple', name: 'ia_multi', label: 'Pick some', optional: 1, choice_list: 'abc', choices: ABC },
    { type: 'mc_multiple', name: 'ia_req', label: 'Pick at least one', choice_list: 'abc', choices: ABC },
    { type: 'mc_multiple', name: 'ia_untouched', label: 'Leave me', optional: 1, choice_list: 'abc', choices: ABC },
    { type: 'submit', name: 'ia_s1', label: 'Done' },
];

const stored = (usid, name) => {
    const rows = dbQuery(`SELECT d.answer FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id `
        + `WHERE d.session_id = ${Number(usid)} AND i.name = '${name}';`);
    return rows.length ? rows[0].answer : undefined;
};

test.describe('form_v2: what an item stores', () => {
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

    test('an mc_multiple stores the choices ticked, and nothing when none is', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const usid = await v2.unitSessionId(page);
        await page.locator('input[name="ia_multi[]"][value="2"]').check({ force: true });
        await page.locator('input[name="ia_req[]"][value="1"]').check({ force: true });
        const posted = page.waitForRequest((r) => /\/form-page-submit\/?$/.test(new URL(r.url()).pathname));
        const { status, body } = await v2.submitV2(page);
        expect(status, JSON.stringify(body)).toBe(200);
        const data = (await posted).postDataJSON().data;
        // As a native form posts it: the hidden placeholder, then the ticked box.
        expect(data.ia_multi).toEqual(['', '2']);
        expect(data.ia_untouched).toEqual(['']);
        expect(stored(usid, 'ia_multi')).toBe('2');
        expect(stored(usid, 'ia_req')).toBe('1');
        expect(stored(usid, 'ia_untouched') || null).toBeNull();
    });
});
