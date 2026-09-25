// The switch of a study to form_v2 refuses what v2 would silently break:
//   - items v2 cannot run (calculate, a no-input item with an R value):
//     always refused, `force` or not;
//   - showifs the compatibility scan flags (leftover R, names not on the
//     form): refused unless the author confirms with "Upgrade anyway".
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

function surveyJson(name, items) {
    return Buffer.from(JSON.stringify({ name, settings: {}, items: items.map((it, i) => Object.assign({ order: i + 1, item_order: i + 1 }, it)) }));
}
function renderingMode(name) {
    const row = db.dbQuery(`SELECT rendering_mode FROM survey_studies WHERE name = '${name}'`)[0];
    return row ? row.rendering_mode : null;
}
function deleteStudy(name) {
    const row = db.dbQuery(`SELECT id FROM survey_studies WHERE name = '${name}'`)[0];
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
        await ctx.close();
    });
    test.afterAll(() => { deleteStudy(SHOWIF_STUDY); deleteStudy(CALC_STUDY); });

    const switchTo = (page, name, form) => page.request.post(`${ADMIN_BASE}/admin/survey/${name}/switch_rendering_mode`, { form });

    test('a flagged showif stops the switch until the author confirms it', async ({ page }) => {
        let res = await switchTo(page, SHOWIF_STUDY, { target: 'v2' });
        expect(res.ok()).toBeTruthy();
        let html = await res.text();
        expect(html).toContain('Not switched');
        expect(html, 'the offending item is named (HTML-escaped)').toMatch(/(?:'|&#0?39;)q2(?:'|&#0?39;)/);
        expect(html, 'the way past it is offered').toContain('Upgrade anyway');
        expect(renderingMode(SHOWIF_STUDY)).toBe('v1');

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
            expect(html).toContain('calculate items are not supported');
            expect(renderingMode(CALC_STUDY), JSON.stringify(form)).toBe('v1');
        }
    });
});
