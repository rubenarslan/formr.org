// A v2 form whose survey is switched back to v1 while a participant is on it.
//
// The unit session is still the one the form was rendered for, and both
// engines keep its answers in the same rows, so what the form sends is
// stored — its offline-queued pages too, which the sync used to answer with
// 409 drop_entry, deleting them in the browser — and the participant goes on
// on v1. A queued final page ends nothing: v1 ends a survey itself.
// Everything is created here and deleted in afterAll: one study, one run.

const { request } = require('@playwright/test');
const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { freshParticipant } = require('./helpers/participant');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');
const { provisioner } = require('./helpers/provision');
const { dbQuery } = require('./helpers/db');
const { currentFormContext, mysqlNow } = require('./helpers/idbQueue');
const v2 = require('./helpers/v2Form');

const SFX = Date.now().toString(36);
const NAME = `e2e_switchback_${SFX}`;
const RUN = `e2e-switchback-${SFX}`;
const ITEMS = [
    { type: 'text', name: 'sb_q1', label: 'First', optional: 1 },
    { type: 'submit', name: 'sb_s1', label: 'Next' },
    { type: 'text', name: 'sb_q2', label: 'Second', optional: 1 },
    { type: 'submit', name: 'sb_s2', label: 'Done' },
];

const answer = (usid, name) => {
    const rows = dbQuery(`SELECT d.answer FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id `
        + `WHERE d.session_id = ${Number(usid)} AND i.name = '${name}';`);
    return rows.length ? rows[0].answer : undefined;
};

test.describe('a v2 form whose survey goes back to v1', () => {
    test.skip(RUNNING_ON_BS, 'provisions through the admin');
    const prov = provisioner();
    let admin;

    test.beforeAll(async ({ browser }) => {
        test.setTimeout(120000);
        await ensureAdminState(browser);
        admin = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        const study = await prov.uploadStudy(admin, { engine: 'v2', json: { name: NAME, settings: { offline_mode: 1 }, items: ITEMS } });
        await prov.createRun(admin, RUN, [{ type: 'Survey', studyId: study.id }, { type: 'Page', body: `END_${SFX}` }]);
    });

    test.afterAll(async () => {
        if (admin) {
            await prov.cleanup(admin);
            await admin.dispose();
        }
    });

    const toEngine = async (target) => {
        const res = await admin.post(`${ADMIN_BASE}/admin/survey/${NAME}/switch_rendering_mode`, { form: { target } });
        expect(res.ok()).toBeTruthy();
        expect(dbQuery(`SELECT rendering_mode FROM survey_studies WHERE name = '${NAME}'`)[0].rendering_mode).toBe(target);
    };

    test('stores its queued and its open pages, and the participant goes on on v1', async ({ page, baseURL }) => {
        await toEngine('v2');
        // What the switch-back confirmation promises is what follows.
        const settings = await (await admin.get(`${ADMIN_BASE}/admin/survey/${NAME}`)).text();
        const confirm = (settings.match(/data-confirm="(Switch this survey back to v1[^"]*)"/) || [])[1] || '';
        expect(confirm).toContain('keep their answers');
        expect(confirm).not.toContain('lost');
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const ctx = await currentFormContext(page);
        await toEngine('v1');

        // A page that waited in the offline queue, marked final.
        const res = await page.request.post(ctx.syncUrl, { headers: { Accept: 'application/json' }, data: {
            uuid: crypto.randomUUID(), page: ctx.pageNum, unit_session_id: ctx.unitSessionId, client_ts: mysqlNow(),
            data: { sb_q1: 'queued' }, item_views: {}, final: true,
        } });
        const body = await res.json();
        expect(res.status(), JSON.stringify(body)).toBe(200);
        expect(body.status).toBe('ok');
        expect(body.engine_changed).toBe(true);
        expect(answer(ctx.unitSessionId, 'sb_q1')).toBe('queued');
        expect(dbQuery(`SELECT ended FROM survey_unit_sessions WHERE id = ${Number(ctx.unitSessionId)}`)[0].ended,
            'a queued final page ends nothing on v1').toBeNull();

        // The page still open in the browser: its submit is stored and the
        // browser goes to the run URL, which shows the survey on v1.
        await page.fill('[name="sb_q1"]', 'live');
        await Promise.all([
            page.waitForURL((url) => url.pathname.replace(/\/$/, '').endsWith(RUN), { timeout: 15000 }),
            page.locator('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]').first().click(),
        ]);
        expect(answer(ctx.unitSessionId, 'sb_q1')).toBe('live');
        await expect(page.locator('form.fmr-form-v2')).toHaveCount(0);
        await expect(page.locator('[name="sb_q2"]')).toBeVisible();
    });
});
