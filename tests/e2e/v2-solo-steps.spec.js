// form_v2 solo layout (one item per screen): when a step moves on by
// itself, and when it must not. Everything is created here and deleted in
// afterAll: one study, one run.

const { request } = require('@playwright/test');
const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { freshParticipant } = require('./helpers/participant');
const { STATE_PATH, ensureAdminState } = require('./helpers/admin');
const { provisioner } = require('./helpers/provision');
const v2 = require('./helpers/v2Form');

const SFX = Date.now().toString(36);
const NAME = `e2e_solosteps_${SFX}`;
const RUN = `e2e-solosteps-${SFX}`;
const ITEMS = [
    { type: 'visual_analog_scale', name: 'ss_scale', label: 'How much?', optional: 1, choice_list: 'poles', choices: { 1: 'none', 2: 'a lot' } },
    { type: 'text', name: 'ss_text', label: 'Anything else?', optional: 1 },
    { type: 'text', name: 'ss_last', label: 'Last words', optional: 1 },
    { type: 'submit', name: 'ss_s1', label: 'Done' },
];
const seated = (page) => page.locator('.fmr-solo-current');

test.describe('form_v2 solo steps', () => {
    test.skip(RUNNING_ON_BS, 'provisions through the admin');
    const prov = provisioner();
    let admin;

    test.beforeAll(async ({ browser }) => {
        test.setTimeout(120000);
        await ensureAdminState(browser);
        admin = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        const study = await prov.uploadStudy(admin, { engine: 'v2', json: { name: NAME, settings: { layout: 'solo' }, items: ITEMS } });
        await prov.createRun(admin, RUN, [{ type: 'Survey', studyId: study.id }, { type: 'Page', body: `END_${SFX}` }]);
    });

    test.afterAll(async () => {
        if (admin) {
            await prov.cleanup(admin);
            await admin.dispose();
        }
    });

    test('a scale held still mid-drag stays on its step; released, it moves on', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const slider = seated(page).locator('.vas-display');
        await expect(slider).toBeVisible();
        // The finger moves the thumb, then rests: input events, no change.
        await slider.evaluate((r) => { r.value = '30'; r.dispatchEvent(new Event('input', { bubbles: true })); });
        await page.waitForTimeout(900);
        await expect(seated(page).locator('.vas-display'), 'still on the scale').toHaveCount(1);
        // Released: the slider's own change.
        await slider.evaluate((r) => r.dispatchEvent(new Event('change', { bubbles: true })));
        await expect(seated(page).locator('[name="ss_text"]')).toBeVisible({ timeout: 3000 });
    });
});
