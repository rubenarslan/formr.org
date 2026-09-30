// A new survey can arrive on form_v2 straight from its upload — no toggle:
//   - a spreadsheet's `settings` sheet says `rendering_mode` | `v2`;
//   - the Add Survey form's Engine choice says form_v2 (it wins over the file);
//   - and on v2, items v2 cannot run (calculate) are refused at upload.
//
//   npx playwright test --config tests/e2e/playwright.config.js v2-upload-engine
//
// Auth via the cached admin storage state (helpers/admin.js). Creates
// throwaway surveys and deletes them afterwards.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');
const db = require('./helpers/db');

test.use({ storageState: STATE_PATH });

const SUFFIX = Date.now().toString(36);
const created = [];

function xlsx(name, sheets) {
    const out = path.join(os.tmpdir(), `${name}.xlsx`);
    execFileSync('php', [path.resolve(__dirname, 'setup/make-xlsx.php'), out], { input: JSON.stringify(sheets) });
    return out;
}
function study(name) {
    return db.dbQuery(`SELECT id, rendering_mode FROM survey_studies WHERE user_id = ${db.adminUserId()} AND name = '${name}'`)[0] || null;
}
function deleteStudy(name) {
    const row = study(name);
    if (!row) return;
    execFileSync('docker', ['exec', 'formr_app', 'php', '-r',
        `require '/var/www/formr/setup.php'; (new SurveyStudy(${parseInt(row.id, 10)}))->delete();`], { encoding: 'utf8' });
}
async function upload(page, file, engine) {
    await page.goto(`${ADMIN_BASE}/admin/survey/`, { waitUntil: 'domcontentloaded' });
    const form = page.locator('form#add_study');
    await form.locator('input[name="uploaded"]').setInputFiles(file);
    if (engine !== undefined) await form.locator('select[name="engine"]').selectOption(engine);
    await Promise.all([page.waitForLoadState('domcontentloaded'), form.locator('button[type="submit"]').click()]);
    return page.content();
}
const SURVEY = [['type', 'name', 'label', 'optional'], ['text', 'q1', 'Q1', 1], ['text', 'q2', 'Q2', 1]];

test.describe('uploading a new survey onto form_v2', () => {
    test.beforeAll(async ({ browser }) => { await ensureAdminState(browser); });
    test.afterAll(() => { created.forEach(deleteStudy); });

    test('a settings sheet declares the engine', async ({ page }) => {
        const name = `e2e_up_sheet_${SUFFIX}`;
        created.push(name);
        const html = await upload(page, xlsx(name, { survey: SURVEY, settings: [['item', 'value'], ['rendering_mode', 'v2']] }));
        expect(html).toContain('rendering_mode = v2');
        expect(study(name) && study(name).rendering_mode).toBe('v2');
        await page.goto(`${ADMIN_BASE}/admin/survey/${name}`);
        await expect(page.locator('.content-header .fmr-engine-badge')).toContainText('v2');
    });

    test('without a settings sheet a spreadsheet arrives on v1', async ({ page }) => {
        const name = `e2e_up_plain_${SUFFIX}`;
        created.push(name);
        await upload(page, xlsx(name, { survey: SURVEY }));
        expect(study(name) && study(name).rendering_mode).toBe('v1');
    });

    test('the Engine choice on the form wins over the file', async ({ page }) => {
        const name = `e2e_up_choice_${SUFFIX}`;
        created.push(name);
        await upload(page, xlsx(name, { survey: SURVEY, settings: [['item', 'value'], ['rendering_mode', 'v1']] }), 'v2');
        expect(study(name) && study(name).rendering_mode).toBe('v2');

        const jsonName = `e2e_up_json_${SUFFIX}`;
        created.push(jsonName);
        const jsonFile = path.join(os.tmpdir(), `${jsonName}.json`);
        fs.writeFileSync(jsonFile, JSON.stringify({ name: jsonName, settings: {}, items: [{ type: 'text', name: 'q1', label: 'Q1', optional: 1, order: 1, item_order: 1 }] }));
        await upload(page, jsonFile, 'v2');
        expect(study(jsonName) && study(jsonName).rendering_mode, 'JSON upload with Engine: form_v2').toBe('v2');
    });

    test('on v2, a calculate item is refused at upload', async ({ page }) => {
        const name = `e2e_up_calc_${SUFFIX}`;
        created.push(name);
        const html = await upload(page, xlsx(name, {
            survey: [['type', 'name', 'label', 'value'], ['text', 'q1', 'Q1', ''], ['calculate', 'score', 'score', 'nchar(q1)']],
        }), 'v2');
        expect(html).toContain('v2 has no calculate items');
        const row = study(name);
        const items = row ? db.dbQuery(`SELECT name FROM survey_items WHERE study_id = ${parseInt(row.id, 10)}`).map((r) => r.name) : [];
        expect(items, 'no items were stored').not.toContain('score');
    });
});
