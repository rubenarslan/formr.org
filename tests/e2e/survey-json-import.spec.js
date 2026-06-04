// Survey JSON upload + robot seeding.
//
// Survey upload accepts a .json survey export (AdminSurveyController →
// SurveyStudy::createFromData). This test seeds the all_widgets catalogue from
// documentation/example_surveys/all_widgets.json as the robot account
// (robot@researchmixtapes.com — the FORMR_DEV_ADMIN account global-setup logs
// in as) and asserts every catalogued item type lands in the new study. That
// exercises the whole import path end to end: the `.json` allowlist, the
// export-field stripping (showif_js), the `*` wildcard choice list (timezone),
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
const ROBOT_ID = 5;          // robot@researchmixtapes.com
// A throwaway study name — NOT `all_widgets`, which is the persistent name the
// e2e-aw-v2 fixture study uses for this same robot account. We override the
// JSON's internal `name` to this before upload so the test never clobbers it.
const STUDY = 'e2e_json_probe';

function robotStudy() {
    return db.dbQuery(
        `SELECT id, results_table FROM survey_studies WHERE user_id = ${ROBOT_ID} AND name = '${STUDY}' ORDER BY id DESC LIMIT 1`,
    )[0];
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
        const want = new Set(JSON.parse(fs.readFileSync(FIXTURE, 'utf8')).items.map((i) => i.type));
        const missing = [...want].filter((t) => !got.has(t)).sort();
        expect(missing, `seeded study is missing item types: ${missing.join(', ')}`).toEqual([]);
        expect(got.size, 'expected the full widget catalogue').toBeGreaterThanOrEqual(50);
    });
});
