// Form unit export/import round-trip through the admin UI.
//
// Before the fix a run exported "with survey details" shipped a Form unit
// as a bare skeleton (Run::export only attached survey_data for Survey
// units, and Run::importUnits had no Form case), so a shared bundle lost
// the study behind every v2 form. Now the export resolves the Form's study
// (dialog study_id → survey_units.form_study_id) and the import
// materialises it via SurveyStudy::createFromData(place_unit=false) and
// links it through form_study_id — study only, no stray Survey unit.
//
// Source: the persistent e2e-rank-v2 run (Form → e2e_rank, then a Page).
// The bundle's study is renamed before import so the test proves a NEW
// study is created with items + v2 settings, instead of createFromData
// reusing the admin's existing e2e_rank by name. Everything it creates
// (run + study) is deleted through the admin UI afterwards.

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const { test, expect } = require('./helpers/test');
const { adminLogin, dismissConsent } = require('./helpers/admin');
const { dbQuery, adminUserId, sqlStr } = require('./helpers/db');
const { runName } = require('./helpers/runs');

const ADMIN_URL = (process.env.FORMR_DEV_URL || 'https://formr.researchmixtape.com').replace(/\/+$/, '');
const SOURCE_RUN = runName('rank', 'v2');

async function createRun(page, name) {
    await page.goto(`${ADMIN_URL}/admin/run/add_run`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="run_name"]', name);
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.locator('form').filter({ has: page.locator('input[name="run_name"]') })
            .locator('button[type="submit"], input[type="submit"]').first().click(),
    ]);
    await expect(page).toHaveURL(new RegExp(`/admin/run/${name}/?$`));
}

async function deleteRun(page, name) {
    try {
        await page.goto(`${ADMIN_URL}/admin/run/${name}/delete_run`, { waitUntil: 'domcontentloaded' });
        await page.fill('input[name="delete_confirm"]', name);
        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            page.locator('button[name="delete"], input[name="delete"], button[type="submit"]').first().click(),
        ]);
    } catch (e) {
        console.warn(`[form-roundtrip] cleanup of run ${name} failed: ${e.message}`);
    }
}

async function deleteStudy(page, name) {
    try {
        await page.goto(`${ADMIN_URL}/admin/survey/${name}/delete_study`, { waitUntil: 'domcontentloaded' });
        await page.fill('input[name="delete_confirm"]', name);
        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            page.locator('form[action*="delete_study"] button[type="submit"], form[action*="delete_study"] input[type="submit"]').first().click(),
        ]);
    } catch (e) {
        console.warn(`[form-roundtrip] cleanup of study ${name} failed: ${e.message}`);
    }
}

// Drive the real export dialog (run.js exportUnits): its serialize() is
// what puts the Form dialog's study_id into the posted unit.
async function exportRunWithSurveyDetails(page, sourceRun, exportName) {
    await page.goto(`${ADMIN_URL}/admin/run/${sourceRun}`, { waitUntil: 'domcontentloaded' });
    await dismissConsent(page);
    await page.locator('a.export_run_units').click();
    const modal = page.locator('form#export_run_units');
    await expect(modal).toBeVisible();
    await modal.locator('input[name="export_name"]').fill(exportName);
    await modal.locator('input[name="include_survey_details"]').check();
    const [download] = await Promise.all([
        page.waitForEvent('download', { timeout: 30000 }),
        modal.locator('.confirm-export').click(),
    ]);
    const tmp = path.join(os.tmpdir(), `formr-e2e-export-${Date.now()}.json`);
    await download.saveAs(tmp);
    return JSON.parse(fs.readFileSync(tmp, 'utf8'));
}

async function importBundle(page, targetRun, bundle) {
    const tmp = path.join(os.tmpdir(), `formr-e2e-import-${Date.now()}.json`);
    fs.writeFileSync(tmp, JSON.stringify(bundle));
    await page.goto(`${ADMIN_URL}/admin/run/${targetRun}`, { waitUntil: 'domcontentloaded' });
    await dismissConsent(page);
    await page.locator('a.import_run_units').click();
    const modal = page.locator('#run-import-modal-dialog');
    await expect(modal).toBeVisible();
    await modal.locator('input[name="run_file"]').setInputFiles(tmp);
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        modal.locator('.confirm-import').click(),
    ]);
    fs.unlinkSync(tmp);
}

function studyRow(name) {
    return dbQuery(
        `SELECT s.id, s.rendering_mode, s.layout, s.language, s.offline_mode, ` +
        `(SELECT COUNT(*) FROM survey_items i WHERE i.study_id = s.id AND i.deleted IS NULL) AS items ` +
        `FROM survey_studies s WHERE s.user_id = ${adminUserId()} AND s.name = ${sqlStr(name)} ORDER BY s.id DESC LIMIT 1`,
    )[0];
}

function runUnits(name) {
    return dbQuery(
        `SELECT ru.position, u.id AS unit_id, u.type, u.form_study_id, ru.description ` +
        `FROM survey_run_units ru JOIN survey_units u ON u.id = ru.unit_id ` +
        `JOIN survey_runs r ON r.id = ru.run_id WHERE r.name = '${name}' ORDER BY ru.position`,
    );
}

test.describe('Form unit export/import round-trip', () => {
    test('a bundle with survey details recreates the Form study and links it', async ({ page }) => {
        test.skip(!process.env.FORMR_DEV_ADMIN_EMAIL, 'FORMR_DEV_ADMIN_EMAIL/PASSWORD missing from .env.dev');
        test.setTimeout(180000);

        const stamp = Date.now();
        const targetRun = `e2e-rt-${stamp}`;
        const newStudy = `e2e_rt_${stamp}`;
        const sourceUnits = runUnits(SOURCE_RUN);
        const sourceForm = sourceUnits.find((u) => u.type === 'Form');
        expect(sourceForm, `${SOURCE_RUN} needs a Form unit — see tests/e2e/setup/runbook.md`).toBeTruthy();
        const sourceStudyName = dbQuery(`SELECT name FROM survey_studies WHERE id = ${sourceForm.form_study_id}`)[0].name;
        const sourceStudy = studyRow(sourceStudyName);

        await adminLogin(page);

        // --- export: the Form unit carries its study -----------------------
        const bundle = await exportRunWithSurveyDetails(page, SOURCE_RUN, 'e2e roundtrip');
        expect(bundle.units.length).toBe(sourceUnits.length);
        const formUnit = bundle.units.find((u) => u.type === 'Form');
        expect(formUnit).toBeTruthy();
        expect(formUnit.unit_id, 'ids never leave the host').toBeUndefined();
        expect(formUnit.survey_data && formUnit.survey_data.name).toBe(sourceStudyName);
        expect(Object.keys(formUnit.survey_data.items).length).toBe(Number(sourceStudy.items));
        expect(formUnit.survey_data.settings.rendering_mode).toBe('v2');
        expect(formUnit.survey_data.settings).toHaveProperty('layout');
        expect(formUnit.survey_data.settings).toHaveProperty('language');

        // --- import into a fresh run, under a fresh study name -------------
        formUnit.survey_data.name = newStudy;
        delete formUnit.study_id; // the source host's id must not be what links it
        await createRun(page, targetRun);
        try {
            await importBundle(page, targetRun, bundle);

            const created = studyRow(newStudy);
            expect(created, 'the study was materialised from survey_data').toBeTruthy();
            expect(Number(created.items)).toBe(Number(sourceStudy.items));
            expect(created.rendering_mode).toBe('v2');
            expect(created.layout).toBe(sourceStudy.layout);
            expect(created.language).toBe(sourceStudy.language);

            const units = runUnits(targetRun);
            expect(units.map((u) => u.type)).toEqual(sourceUnits.map((u) => u.type)); // no stray Survey unit
            const form = units.find((u) => u.type === 'Form');
            expect(Number(form.form_study_id)).toBe(Number(created.id));
            expect(form.description, 'the bundle description wins over the study-name default').toBe(sourceForm.description);

            // and the run editor shows the study selected in the Form's dropdown
            await page.goto(`${ADMIN_URL}/admin/run/${targetRun}`, { waitUntil: 'domcontentloaded' });
            const select = page.locator('.run_unit select[name="study_id"]').first();
            await expect(select).toHaveValue(String(created.id));
        } finally {
            await deleteRun(page, targetRun);
            await deleteStudy(page, newStudy);
        }
        expect(studyRow(newStudy), 'cleanup removed the study').toBeUndefined();
    });

    // The shipped documentation fixture embeds its study, so one import
    // yields a working v2 form (documentation/form_v2.md "See also").
    test('the Form_v2_Basic.json fixture imports with its study embedded', async ({ page }) => {
        test.skip(!process.env.FORMR_DEV_ADMIN_EMAIL, 'FORMR_DEV_ADMIN_EMAIL/PASSWORD missing from .env.dev');
        test.setTimeout(120000);

        const fixture = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../documentation/run_components/Form_v2_Basic.json'), 'utf8'));
        const formUnit = fixture.units.find((u) => u.type === 'Form');
        const studyName = formUnit.survey_data.name;
        expect(studyRow(studyName), `${studyName} must not pre-exist for this admin`).toBeUndefined();
        const targetRun = `e2e-rtfx-${Date.now()}`;

        await adminLogin(page);
        await createRun(page, targetRun);
        try {
            await importBundle(page, targetRun, fixture);
            const created = studyRow(studyName);
            expect(created).toBeTruthy();
            expect(Number(created.items)).toBe(formUnit.survey_data.items.length);
            expect(created.rendering_mode).toBe('v2');
            const units = runUnits(targetRun);
            // the legacy 'Endpage' export type imports as a Page (Run::importUnits)
            expect(units.map((u) => u.type)).toEqual(fixture.units.map((u) => (u.type === 'Endpage' ? 'Page' : u.type)));
            expect(Number(units.find((u) => u.type === 'Form').form_study_id)).toBe(Number(created.id));
            // no import errors surfaced on the run page
            await page.goto(`${ADMIN_URL}/admin/run/${targetRun}`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator('.run_units .alert-danger')).toHaveCount(0);
        } finally {
            await deleteRun(page, targetRun);
            await deleteStudy(page, studyName);
        }
        expect(studyRow(studyName), 'cleanup removed the study').toBeUndefined();
    });
});
