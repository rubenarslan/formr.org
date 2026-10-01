// The switch of a study to form_v2 refuses what v2 would silently break:
//   - items v2 cannot run (calculate, a no-input item with an R value):
//     always refused, `force` or not;
//   - showifs the compatibility scan flags (leftover R, names not on the
//     form): refused unless the author confirms with "Move to v2 anyway".
//
//   npx playwright test --config tests/e2e/playwright.config.js v2-switch-gate
//
// Auth via the cached admin storage state (helpers/admin.js). Creates two
// throwaway studies from JSON and deletes them afterwards.
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');
const db = require('./helpers/db');

test.use({ storageState: STATE_PATH });

const SUFFIX = Date.now().toString(36);
const SHOWIF_STUDY = `e2e_gate_showif_${SUFFIX}`;
const CALC_STUDY = `e2e_gate_calc_${SUFFIX}`;
const LIST_STUDY = `e2e_gate_list_${SUFFIX}`;
const SEMI_STUDY = `e2e_gate_semi_${SUFFIX}`;

function surveyJson(name, items) {
    return Buffer.from(JSON.stringify({ name, settings: {}, items: items.map((it, i) => Object.assign({ order: i + 1, item_order: i + 1 }, it)) }));
}
function renderingMode(name) {
    const row = db.studyByName(name);
    return row ? row.rendering_mode : null;
}
function deleteStudy(name) {
    const row = db.studyByName(name);
    if (!row) return;
    execFileSync('docker', ['exec', 'formr_app', 'php', '-r',
        `require '/var/www/formr/setup.php'; (new SurveyStudy(${parseInt(row.id, 10)}))->delete();`], { encoding: 'utf8' });
}

test.describe('switching a study to form_v2', () => {
    test.beforeAll(async ({ browser }) => {
        await ensureAdminState(browser);
        const ctx = await browser.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        const upload = async (name, items) => {
            const res = await ctx.request.post(`${ADMIN_BASE}/admin/survey/add_survey/`, {
                multipart: { uploaded: { name: `${name}.json`, mimeType: 'application/json', buffer: surveyJson(name, items) } },
            });
            expect(res.ok(), `upload ${name}`).toBeTruthy();
            expect(renderingMode(name), `${name} was created on v1`).toBe('v1');
        };
        await upload(SHOWIF_STUDY, [
            { type: 'text', name: 'q1', label: 'q1', optional: 1 },
            { type: 'text', name: 'q2', label: 'q2', optional: 1, showif: 'q1 %in% c(1, 2)' },
        ]);
        await upload(CALC_STUDY, [
            { type: 'text', name: 'q1', label: 'q1', optional: 1 },
            { type: 'calculate', name: 'score', label: 'score', value: 'nchar(q1)' },
        ]);
        // v1 splits a one-cell list at its commas; v2 at semicolons.
        await upload(LIST_STUDY, [
            { type: 'select_or_add_one', name: 'colour', label: 'colour', optional: 1, choice_list: 'colours', choices: { 1: 'red,green,blue' } },
        ]);
        // v2 joins a multiple answer with ";", so no choice may hold one.
        await upload(SEMI_STUDY, [
            { type: 'select_or_add_multiple', name: 'places', label: 'places', optional: 1, choice_list: 'places', choices: { 1: 'Berlin; Germany', 2: 'Paris' } },
        ]);
        await ctx.close();
    });
    test.afterAll(() => { [SHOWIF_STUDY, CALC_STUDY, LIST_STUDY, SEMI_STUDY].forEach(deleteStudy); });

    const switchTo = (page, name, form) => page.request.post(`${ADMIN_BASE}/admin/survey/${name}/switch_rendering_mode`, { form });

    test('a flagged showif stops the switch until the author confirms it', async ({ page }) => {
        let res = await switchTo(page, SHOWIF_STUDY, { target: 'v2' });
        expect(res.ok()).toBeTruthy();
        let html = await res.text();
        expect(html).toContain('Not switched');
        expect(html, 'the offending item is named (HTML-escaped)').toMatch(/(?:'|&#0?39;)q2(?:'|&#0?39;)/);
        expect(html, 'the way past it is offered').toContain('move the survey anyway');
        expect(renderingMode(SHOWIF_STUDY)).toBe('v1');
        // The way past it lives on the compatibility report (the settings
        // page no longer runs the scan on every view).
        const report = await page.request.get(`${ADMIN_BASE}/admin/survey/${SHOWIF_STUDY}/form_v2_compat_scan`);
        expect(await report.text(), 'the report offers "Move to v2 anyway"').toMatch(/name="force" value="1"[\s\S]*Move to v2 anyway/);

        res = await switchTo(page, SHOWIF_STUDY, { target: 'v2', force: '1' });
        expect(res.ok()).toBeTruthy();
        expect(renderingMode(SHOWIF_STUDY), 'confirmed: switched').toBe('v2');
    });

    test('an item v2 cannot run blocks the switch, even when forced', async ({ page }) => {
        for (const form of [{ target: 'v2' }, { target: 'v2', force: '1' }]) {
            const res = await switchTo(page, CALC_STUDY, form);
            expect(res.ok()).toBeTruthy();
            const html = await res.text();
            expect(html).toContain('Not switched');
            expect(html).toContain('v2 has no calculate items');
            expect(renderingMode(CALC_STUDY), JSON.stringify(form)).toBe('v1');
        }
    });

    test('a one-cell comma list stops the switch until the author confirms it', async ({ page }) => {
        let res = await switchTo(page, LIST_STUDY, { target: 'v2' });
        const html = await res.text();
        expect(html).toContain('Not switched');
        expect(html).toContain('written in one cell with commas');
        expect(renderingMode(LIST_STUDY)).toBe('v1');
        const report = await page.request.get(`${ADMIN_BASE}/admin/survey/${LIST_STUDY}/form_v2_compat_scan`);
        expect(await report.text(), 'the report offers "Move to v2 anyway"').toMatch(/name="force" value="1"[\s\S]*Move to v2 anyway/);
        res = await switchTo(page, LIST_STUDY, { target: 'v2', force: '1' });
        expect(res.ok()).toBeTruthy();
        expect(renderingMode(LIST_STUDY), 'confirmed: switched').toBe('v2');
    });

    test('a select_or_add_multiple choice with a semicolon blocks the switch, even when forced', async ({ page }) => {
        for (const form of [{ target: 'v2' }, { target: 'v2', force: '1' }]) {
            const html = await (await switchTo(page, SEMI_STUDY, form)).text();
            expect(html).toContain('Not switched');
            expect(html).toContain('separated by semicolons');
            expect(renderingMode(SEMI_STUDY), JSON.stringify(form)).toBe('v1');
        }
    });

    test('a survey a run uses as a classic Survey is not switched, even when forced', async ({ page }) => {
        // e2e_pwa_low runs as a Survey in e2e-pwa-l-v1: switching it would
        // change the engine under that run's participants. It is a shared
        // fixture, so a regression that lets the switch through must not
        // leave it on v2 for every later run.
        const study = db.studyByName('e2e_pwa_low');
        expect(study, 'fixture study e2e_pwa_low').toBeTruthy();
        expect(study.rendering_mode).toBe('v1');
        try {
            for (const form of [{ target: 'v2' }, { target: 'v2', force: '1' }]) {
                const res = await switchTo(page, 'e2e_pwa_low', form);
                expect(res.ok()).toBeTruthy();
                const html = await res.text();
                expect(html).toContain('Not switched');
                expect(html).toContain('e2e-pwa-l-v1');
                expect(renderingMode('e2e_pwa_low'), JSON.stringify(form)).toBe('v1');
            }
        } finally {
            db.dbExecRaw(`UPDATE survey_studies SET rendering_mode = 'v1' WHERE id = ${parseInt(study.id, 10)};`);
        }
    });
});
