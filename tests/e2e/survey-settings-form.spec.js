// The survey settings form's v2 settings (AdminSurveyController::changeSettings).
//
// The form falls back where an import drops: an unchecked box is off, a
// layout or language it cannot use is the default. Both apply the same
// rules (SurveyStudy::validateEngineSettings). One v2 study is created here
// and deleted in afterAll.

const { request } = require('@playwright/test');
const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');
const { provisioner } = require('./helpers/provision');
const { dbQuery, sqlStr } = require('./helpers/db');

const SFX = Date.now().toString(36);
const NAME = `e2e_settings_${SFX}`;

test.describe('the survey settings form', () => {
    test.skip(RUNNING_ON_BS, 'drives the admin directly');
    const prov = provisioner();
    let admin;

    test.beforeAll(async ({ browser }) => {
        await ensureAdminState(browser);
        admin = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        await prov.uploadStudy(admin, { engine: 'v2', json: { name: NAME, settings: { offline_mode: 1 }, items: [
            { type: 'text', name: 'q1', label: 'Q1', optional: 1 },
        ] } });
    });

    test.afterAll(async () => {
        if (admin) {
            await prov.cleanup(admin);
            await admin.dispose();
        }
    });

    test('falls back to the defaults for what it cannot use', async () => {
        const res = await admin.post(`${ADMIN_BASE}/admin/survey/${NAME}/`, { form: {
            maximum_number_displayed: '0', displayed_percentage_maximum: '100', add_percentage_points: '0', expire_after: '0',
            // offline_mode unchecked: not posted
            allow_previous: '1', layout: 'sideways', language: 'not a tag!',
            next_button_label: '  Weiter  ', submit_button_label: '',
        } });
        expect(res.ok()).toBeTruthy();
        const row = dbQuery(`SELECT offline_mode, allow_previous, layout, language, next_button_label, submit_button_label `
            + `FROM survey_studies WHERE name = ${sqlStr(NAME)}`)[0];
        expect(row).toEqual({ offline_mode: '0', allow_previous: '1', layout: 'default', language: 'en',
            next_button_label: 'Weiter', submit_button_label: null });
    });
});
