// form_v2: pages of nothing but hidden items, hidden values that follow the
// answers, and select_or_add_multiple's semicolon separator.
//
//   npx playwright test --config tests/e2e/playwright.config.js v2-hidden-pages
//
// Fixture: tests/e2e/setup/e2e_v2_hidden.json, provisioned by
// tests/e2e/setup/create-v2-fixtures.js as run `e2e-hidden-v2` (Previous on).
//   page 1: h_group  hidden sample(c(10, 20), 1)   — before every shown page
//   page 2: h_gate   text, showif h_group >= 10
//   page 3: h_q1     number, required
//   page 4: h_double hidden h_q1 * 2               — between shown pages
//   page 5: h_big    note, showif h_double > 10; h_places select_or_add_multiple
//                    ("Berlin, Germany", "Paris"); h_colours (one cell:
//                    "red;green;blue"); h_city (one choice "Berlin, Germany")
//                    — the last shown page
//   page 6: h_sum    hidden h_q1 + 100             — after the last submit
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect, bsSafeEvaluate } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');
const v2 = require('./helpers/v2Form');
const db = require('./helpers/db');

const RUN = () => runName('v2_hidden', 'v2');

function study(name = 'e2e_v2_hidden') {
    return db.dbQuery(`SELECT id, results_table, rendering_mode FROM survey_studies WHERE user_id = ${db.adminUserId()} AND name = '${name}' ORDER BY id DESC LIMIT 1`)[0] || null;
}
const inputValue = (page, name) => bsSafeEvaluate(page,
    (n) => { const el = document.querySelector(`form.fmr-form-v2 [name="${n}"]`); return el ? el.value : null; }, name);
const isShown = (page, name) => bsSafeEvaluate(page, (n) => {
    const w = document.querySelector(`form.fmr-form-v2 [name="${n}"]`)?.closest('.form-group');
    return !!w && !w.classList.contains('hidden') && !w.hasAttribute('data-fmr-hidden');
}, name);
// The page shown once nothing on it waits for the server.
async function settledOn(page, pageNum) {
    await page.waitForFunction((n) => {
        const sec = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])');
        return sec && Number(sec.dataset.fmrPage) === n && !document.querySelector('form.fmr-form-v2 .fmr-fill-pending');
    }, pageNum, { timeout: 30000 });
}

test.describe('form_v2: hidden-only pages ride with a shown page', () => {
    test('never shown; computed from the answers; stored with the submit next to them', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        // Page 1 holds only h_group: it is resolved before the landing page
        // is chosen, so page 2 (its showif reads h_group) is where we land.
        await settledOn(page, 2);
        const group = await inputValue(page, 'h_group');
        expect(['10', '20']).toContain(group);
        expect(await isShown(page, 'h_gate')).toBe(true);

        let r = await v2.submitV2(page);
        expect(v2.advanced(r.body), JSON.stringify(r.body)).toBeTruthy();
        await settledOn(page, 3);
        const sid = await v2.unitSessionId(page);
        expect(db.dbResultsRow(study().results_table, sid).h_group, 'the leading page goes with the first shown page').toBe(group);

        await page.fill('[name="h_q1"]', '3');
        r = await v2.submitV2(page);
        expect(v2.advanced(r.body), JSON.stringify(r.body)).toBeTruthy();
        // Page 4 (h_double only) is stepped over, its value computed from h_q1.
        await settledOn(page, 5);
        expect(await inputValue(page, 'h_double')).toBe('6');
        expect(await isShown(page, 'h_big')).toBe(false);
        expect(db.dbResultsRow(study().results_table, sid).h_double, 'stored with page 3').toBe('6');

        // Back to page 3, change the answer: h_double follows it, h_group
        // (a draw that reads no answer) stays.
        await v2.goPrevious(page);
        await settledOn(page, 3);
        await page.fill('[name="h_q1"]', '8');
        r = await v2.submitV2(page);
        expect(v2.advanced(r.body), JSON.stringify(r.body)).toBeTruthy();
        await settledOn(page, 5);
        expect(await inputValue(page, 'h_double')).toBe('16');
        expect(await isShown(page, 'h_big')).toBe(true);
        expect(await inputValue(page, 'h_group')).toBe(group);

        // A choice list written in one cell is split at ";" in v2; a lone
        // choice with a comma stays one choice.
        const options = (name) => bsSafeEvaluate(page,
            (n) => Object.keys(document.querySelector(`form.fmr-form-v2 [name="${n}"]`).tomselect.options).sort(), name);
        expect(await options('h_colours')).toEqual(['blue', 'green', 'red']);
        expect(await options('h_city')).toEqual(['Berlin, Germany']);

        // Page 5 is the last shown page: its submit is final and carries page 6.
        await bsSafeEvaluate(page, () => {
            document.querySelector('form.fmr-form-v2 [name="h_places"]').tomselect.setValue(['Berlin, Germany', 'Paris']);
        });
        r = await v2.submitV2(page);
        expect(r.body && r.body.complete, JSON.stringify(r.body)).toBe(true);
        const row = db.dbResultsRow(study().results_table, sid);
        expect(row.h_double).toBe('16');
        expect(row.h_sum, 'the trailing page is computed from every answer').toBe('108');
        expect(row.h_group).toBe(group);
        expect(row.h_places, 'entries joined by ";", the comma inside one kept').toBe('Berlin, Germany;Paris');
    });
});

test.describe('form_v2: select_or_add_multiple choices', () => {
    const SUFFIX = Date.now().toString(36);
    const created = [];
    const deleteStudy = (name) => {
        const row = study(name);
        if (!row) return;
        execFileSync('docker', ['exec', 'formr_app', 'php', '-r',
            `require '/var/www/formr/setup.php'; (new SurveyStudy(${parseInt(row.id, 10)}))->delete();`], { encoding: 'utf8' });
    };
    test.beforeAll(async ({ browser }) => { await ensureAdminState(browser); });
    test.afterAll(() => { created.forEach(deleteStudy); });

    // A semicolon separates the answers in v2, so a choice cannot hold one;
    // v1 separates with commas and takes it as before.
    test('a choice with a semicolon is refused on v2 and taken on v1', async ({ playwright }) => {
        const ctx = await playwright.request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        try {
            const upload = async (name, engine) => {
                const file = path.join(os.tmpdir(), `${name}.json`);
                fs.writeFileSync(file, JSON.stringify({ name, settings: {}, items: [{
                    type: 'select_or_add_multiple', name: 'places', label: 'Places', optional: 1,
                    choice_list: 'places', choices: { 1: 'Berlin; Germany', 2: 'Paris' },
                }] }));
                const res = await ctx.post(`${ADMIN_BASE}/admin/survey/add_survey/`, {
                    multipart: { engine, uploaded: { name: `${name}.json`, mimeType: 'application/json', buffer: fs.readFileSync(file) } },
                });
                created.push(name);
                return res.text();
            };
            const v2Name = `e2e_semi_v2_${SUFFIX}`;
            const html = await upload(v2Name, 'v2');
            expect(html).toContain('separated by semicolons');
            expect(study(v2Name) && Number(db.dbQuery(`SELECT COUNT(*) AS n FROM survey_items WHERE study_id = ${parseInt(study(v2Name).id, 10)}`)[0].n),
                'no item of a refused sheet is stored').toBeFalsy();
            const v1Name = `e2e_semi_v1_${SUFFIX}`;
            await upload(v1Name, 'v1');
            expect(study(v1Name) && study(v1Name).rendering_mode).toBe('v1');
            expect(Number(db.dbQuery(`SELECT COUNT(*) AS n FROM survey_items WHERE study_id = ${parseInt(study(v1Name).id, 10)}`)[0].n)).toBe(1);
        } finally {
            await ctx.dispose();
        }
    });
});
