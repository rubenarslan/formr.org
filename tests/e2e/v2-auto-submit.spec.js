// form_v2: a page that sends itself once every item on it is answered
// (`submit` with type_options `auto`, items/auto-submit.js). Everything is
// created here and deleted in afterAll: two studies, two runs.

const { request } = require('@playwright/test');
const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { freshParticipant } = require('./helpers/participant');
const { STATE_PATH, ensureAdminState } = require('./helpers/admin');
const { provisioner } = require('./helpers/provision');
const { dbQuery } = require('./helpers/db');
const v2 = require('./helpers/v2Form');

const SFX = Date.now().toString(36);
const NAME = `e2e_autosubmit_${SFX}`;
const RUN = `e2e-autosubmit-${SFX}`;
const YN = { 1: 'yes', 2: 'no' };
const ITEMS = [
    { type: 'mc', name: 'as_q', label: 'Go on?', choice_list: 'yn', choices: YN },
    // Kept once the participant says yes: its showif comes true on the
    // answer that completes the page.
    { type: 'hidden', name: 'as_flag', label: 'flag', value: '7', showif: 'as_q == 1' },
    { type: 'submit', type_options: 'auto', name: 'as_s1', label: 'Next' },
    { type: 'hidden', name: 'as_more', label: 'more', value: '8', showif: 'as_q == 1' },
    { type: 'text', name: 'as_p2', label: 'Page two', optional: 1 },
    { type: 'submit', name: 'as_s2', label: 'Done' },
];

// A scale answered and stored before the participant reloads, and a box
// to tick: unticked, it posts its "0" partner, which is no answer yet.
const STORED_NAME = `e2e_autostored_${SFX}`;
const STORED_RUN = `e2e-autostored-${SFX}`;
const STORED_ITEMS = [
    { type: 'visual_analog_scale', name: 'sv_scale', label: 'How much?', choice_list: 'poles', choices: { 1: 'none', 2: 'a lot' } },
    { type: 'mc', name: 'sv_q', label: 'Go on?', choice_list: 'yn', choices: YN },
    { type: 'check', name: 'sv_ok', label: 'All good', optional: 1 },
    { type: 'submit', type_options: 'auto', name: 'sv_s1', label: 'Next' },
    { type: 'text', name: 'sv_p2', label: 'Page two', optional: 1 },
    { type: 'submit', name: 'sv_s2', label: 'Done' },
];

const display = (usid, name) => {
    const rows = dbQuery(`SELECT d.answer, d.hidden FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id `
        + `WHERE d.session_id = ${Number(usid)} AND i.name = '${name}';`);
    return rows.length ? rows[0] : {};
};

test.describe('form_v2: a page that sends itself once answered', () => {
    test.skip(RUNNING_ON_BS, 'provisions through the admin');
    const prov = provisioner();
    let admin;

    test.beforeAll(async ({ browser }) => {
        test.setTimeout(120000);
        await ensureAdminState(browser);
        admin = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        const study = await prov.uploadStudy(admin, { engine: 'v2', json: { name: NAME, items: ITEMS } });
        await prov.createRun(admin, RUN, [{ type: 'Survey', studyId: study.id }, { type: 'Page', body: `END_${SFX}` }]);
        const stored = await prov.uploadStudy(admin, { engine: 'v2', json: { name: STORED_NAME, items: STORED_ITEMS } });
        await prov.createRun(admin, STORED_RUN, [{ type: 'Survey', studyId: stored.id }, { type: 'Page', body: `END_${SFX}` }]);
    });

    test.afterAll(async () => {
        if (admin) {
            await prov.cleanup(admin);
            await admin.dispose();
        }
    });

    test('a hidden item whose showif comes true neither shows nor holds the page', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const usid = await v2.unitSessionId(page);
        await page.locator('.form-group:has(input[name="as_q"]) label:has(input[value="1"])').click();
        await expect.poll(() => v2.visiblePageNum(page), { timeout: 10000 }).toBe(2);
        expect(display(usid, 'as_flag'), 'kept: its showif is true').toEqual({ answer: '7', hidden: '0' });
        const more = page.locator('.form-group:has(input[name="as_more"])');
        expect(await more.evaluate((el) => el.hasAttribute('data-fmr-hidden')), 'its showif is true').toBe(false);
        expect(await more.evaluate((el) => getComputedStyle(el).display)).toBe('none');
    });

    test('an answer stored before a reload counts toward the page', async ({ page, baseURL }) => {
        await freshParticipant(page, STORED_RUN, { baseURL });
        await v2.waitForBundle(page);
        const usid = await v2.unitSessionId(page);
        const saved = page.waitForResponse((r) => /\/form-save\/?$/.test(new URL(r.url()).pathname), { timeout: 30000 });
        await page.locator('.form-group:has(input[name="sv_scale"]) .vas-display').evaluate((r) => {
            r.value = '30';
            r.dispatchEvent(new Event('input', { bubbles: true }));
            r.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await saved;
        expect(display(usid, 'sv_scale').answer).toBe('30');
        // Back on the page, the scale's answer is in its hidden carrier: no
        // interaction on this document marks it.
        await page.reload({ waitUntil: 'commit' });
        await v2.waitForBundle(page);
        await page.locator('.form-group:has(input[name="sv_q"]) label:has(input[value="1"])').click();
        await page.waitForTimeout(1000);
        expect(await v2.visiblePageNum(page), 'the box is not ticked yet').toBe(1);
        await page.locator('input[type=checkbox][name="sv_ok"]').check();
        await expect.poll(() => v2.visiblePageNum(page), { timeout: 10000 }).toBe(2);
    });
});
