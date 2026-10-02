// Survey JSON upload + robot seeding.
//
// Survey upload accepts a .json survey export (AdminSurveyController →
// SurveyStudy::createFromData). This test seeds the all_widgets catalogue from
// documentation/example_surveys/all_widgets.json as the robot account
// (robot@researchmixtapes.com — the FORMR_DEV_ADMIN account global-setup logs
// in as) and asserts every catalogued item type lands in the new study. That
// exercises the whole import path end to end: the `.json` allowlist, the
// export-only-field stripping, the `*` wildcard choice list (timezone),
// and every form_v2 item type's choice/validation requirements.
//
// Hermetic: deletes the robot-owned `all_widgets` study before and after, so it
// can run repeatedly. Auth comes from the saved robot storageState; no creds in
// the spec. DB assertions go through the docker-exec helper.

const { test, expect, request } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const db = require('./helpers/db');

const ADMIN_URL = process.env.FORMR_DEV_URL || 'https://formr.researchmixtape.com';
const ADMIN_STATE = path.resolve(__dirname, 'setup/admin-state.json');
const FIXTURE = path.resolve(__dirname, '../../documentation/example_surveys/all_widgets.json');
// A throwaway study name — NOT `all_widgets`, which is the persistent name the
// e2e-aw-v2 fixture study uses for this same robot account. We override the
// JSON's internal `name` to this before upload so the test never clobbers it.
const STUDY = 'e2e_json_probe';
// Types the catalogue carries that a v1 survey refuses (Item::v1Unsupported);
// the catalogue upload here is a v1 one, so they are left out of it.
const V2_ONLY = ['bot_check', 'rank'];

function robotStudy() {
    return db.studyByName(STUDY, 'id, results_table');
}
function cleanup() {
    const s = robotStudy();
    if (!s) return;
    const rt = /^[a-zA-Z0-9_]+$/.test(s.results_table || '') ? s.results_table : null;
    db.dbExecRaw(
        (rt ? `DROP TABLE IF EXISTS \`${rt}\`;\n` : '')
        + `DELETE FROM survey_items WHERE study_id = ${parseInt(s.id, 10)};\n`
        + `DELETE FROM survey_units WHERE id = ${parseInt(s.id, 10)};\n`
        + `DELETE FROM survey_studies WHERE id = ${parseInt(s.id, 10)};\n`,
    );
}

test.describe('survey JSON upload (robot seeding)', () => {
    test.beforeAll(() => cleanup());
    test.afterAll(() => cleanup());

    test('robot seeds all_widgets from JSON, covering every catalogued item type', async () => {
        // Reuse the robot admin session (global-setup wrote it).
        // Upload the catalogue under the throwaway name (override the JSON's
        // internal `name` so createFromData stores it as STUDY, not all_widgets).
        const survey = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
        survey.name = STUDY;
        // Drop the export's explicit ordering: a hand-written survey JSON has
        // none, and createFromData must then keep file order (it used to leave
        // item_order NULL, so the render order was whatever MariaDB returned).
        survey.items.forEach((i) => { delete i.order; delete i.item_order; });
        survey.items = survey.items.filter((i) => !V2_ONLY.includes(i.type));
        const ctx = await request.newContext({ ignoreHTTPSErrors: true, storageState: ADMIN_STATE });
        const res = await ctx.post(`${ADMIN_URL}/admin/survey/add_survey/`, {
            multipart: {
                uploaded: {
                    name: `${STUDY}.json`,
                    mimeType: 'application/json',
                    buffer: Buffer.from(JSON.stringify(survey)),
                },
            },
        });
        expect(res.ok(), `add_survey returned ${res.status()}`).toBeTruthy();
        await ctx.dispose();

        const study = robotStudy();
        expect(study, 'robot-owned all_widgets study should exist after upload').toBeTruthy();

        const got = new Set(
            db.dbQuery(`SELECT DISTINCT type FROM survey_items WHERE study_id = ${parseInt(study.id, 10)}`).map((r) => r.type),
        );
        const want = new Set(survey.items.map((i) => i.type));
        const missing = [...want].filter((t) => !got.has(t)).sort();
        expect(missing, `seeded study is missing item types: ${missing.join(', ')}`).toEqual([]);
        expect(got.size, 'expected the full widget catalogue').toBeGreaterThanOrEqual(50);

        const stored = db.dbQuery(
            `SELECT name, item_order FROM survey_items WHERE study_id = ${parseInt(study.id, 10)} ORDER BY item_order, id`,
        );
        expect(stored.map((r) => r.name)).toEqual(survey.items.map((i) => i.name));
        expect(stored.map((r) => Number(r.item_order))).toEqual(survey.items.map((_, i) => i + 1));
    });
    // The v1 engine cannot render a rank or a bot_check: a v1 upload that
    // carries one is refused, naming the item, and the item is not stored.
    test('a v1 upload refuses the v2-only item types', async () => {
        cleanup();
        const catalogue = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
        const survey = {
            name: STUDY,
            settings: {},
            items: [
                { type: 'text', name: 'plain_text', label: 'Text' },
                ...catalogue.items.filter((i) => V2_ONLY.includes(i.type)).map((i) => { const c = { ...i }; delete c.order; delete c.item_order; return c; }),
            ],
        };
        const ctx = await request.newContext({ ignoreHTTPSErrors: true, storageState: ADMIN_STATE });
        const res = await ctx.post(`${ADMIN_URL}/admin/survey/add_survey/`, {
            multipart: { uploaded: { name: `${STUDY}.json`, mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(survey)) } },
        });
        const html = await res.text();
        await ctx.dispose();
        for (const t of V2_ONLY) {
            expect(html, `the refusal names the ${t} item`).toContain(`${t} items only work in v2`);
        }
        const study = robotStudy();
        const types = study ? db.dbQuery(`SELECT DISTINCT type FROM survey_items WHERE study_id = ${parseInt(study.id, 10)}`).map((r) => r.type) : [];
        for (const t of V2_ONLY) expect(types, `no ${t} item stored in a v1 study`).not.toContain(t);
    });
    // createFromData creates the survey before it imports the items, so a
    // refused item table leaves the survey behind, empty. The admin is told
    // and taken to its item upload, as on the spreadsheet path — not to the
    // survey list, where uploading the file again was refused as a duplicate
    // name and the empty survey was never pointed out.
    test('a survey whose items are refused is pointed out and opened for a new item table', async () => {
        cleanup();
        const survey = {
            name: STUDY,
            settings: { rendering_mode: 'v2' },
            items: [
                { type: 'text', name: 'q1', label: 'q1', optional: 1 },
                { type: 'calculate', name: 'score', label: 'score', value: 'nchar(q1)' },
            ],
        };
        const ctx = await request.newContext({ ignoreHTTPSErrors: true, storageState: ADMIN_STATE });
        const res = await ctx.post(`${ADMIN_URL}/admin/survey/add_survey/`, {
            multipart: { uploaded: { name: `${STUDY}.json`, mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(survey)) } },
        });
        const html = await res.text();
        const landed = new URL(res.url()).pathname;
        await ctx.dispose();
        expect(robotStudy(), 'the survey row exists, without items').toBeTruthy();
        expect(landed, 'taken to its item upload').toMatch(new RegExp(`/admin/survey/${STUDY}/upload_items/?$`));
        expect(html).toContain(`The survey "${STUDY}" was created, but its items could not be imported`);
    });
});
