// "Test Survey" from the survey admin: a study on the form_v2 engine is
// previewed as a Form — the v2 page, its showifs, its per-page submits
// through /survey-test/<token>/form-page-submit — and the preview says which
// engine it shows; a v1 study still previews on the classic engine.
//
//   npx playwright test --config tests/e2e/playwright.config.js v2-preview
//
// Auth via the cached admin storage state (helpers/admin.js). Fixtures: the
// cross-page showif pair (tests/e2e/setup/runs.json), v1 and v2.
const { test, expect } = require('./helpers/test');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');
const v2 = require('./helpers/v2Form');
const db = require('./helpers/db');
const { driveDefault } = require('./helpers/v2Drive');
const { provisioner } = require('./helpers/provision');
const { request } = require('@playwright/test');

test.use({ storageState: STATE_PATH });

const studyOf = (variant) => require('./setup/runs.json').crosspage_showif[variant].study;

test.describe('Test Survey previews the engine the study is on', () => {
    test.beforeAll(async ({ browser }) => { await ensureAdminState(browser); });

    test('a v2 study previews as a Form and can be walked to the end', async ({ page }) => {
        const study = studyOf('v2');
        const errors = [];
        page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
        page.on('response', (r) => { if (r.status() >= 400 && !/favicon/.test(r.url())) errors.push(`${r.status()} ${new URL(r.url()).pathname}`); });

        await page.goto(`${ADMIN_BASE}/admin/survey/${study}/access`, { waitUntil: 'domcontentloaded' });
        expect(page.url()).toContain('/survey-test/');
        await v2.waitForBundle(page);
        await expect(page.locator('.fmr-preview-note')).toContainText('v2');
        expect(await v2.pageCount(page), 'every page of the form is in the document').toBe(2);
        const endpoints = await page.evaluate(() => {
            const f = document.querySelector('form.fmr-form-v2');
            return { submit: f.dataset.submitUrl, save: f.dataset.saveUrl, run: f.dataset.runUrl };
        });
        for (const url of Object.values(endpoints)) {
            expect(url, 'endpoints point at the preview, not at the test run').toContain('/survey-test/');
        }

        // Page 1 → page 2 through the preview's form-page-submit, with the
        // cross-page showif reacting in the browser.
        await page.fill('[name="cp_a"]', '1');
        const r1 = await v2.submitV2(page);
        expect(v2.advanced(r1.body), JSON.stringify(r1.body)).toBeTruthy();
        expect(await v2.visiblePageNum(page)).toBe(2);
        await page.fill('[name="cp_b"]', '3');
        await expect(page.locator('[name="cp_followup"]').locator('xpath=ancestor::*[contains(@class,"form-group")][1]')).not.toHaveClass(/\bhidden\b/);

        // Final page: the form ends and the preview says so.
        const sid = await v2.unitSessionId(page);
        await page.fill('[name="cp_followup"]', 'shown');
        await page.locator('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]').first().click();
        await expect(page.locator('body')).toContainText("You're finished with testing this survey", { timeout: 20000 });

        const results = db.dbQuery(`SELECT results_table FROM survey_studies WHERE user_id = ${db.adminUserId()} AND name = '${study}'`)[0].results_table;
        const row = db.dbResultsRow(results, sid);
        expect(row.cp_a).toBe('1');
        expect(row.cp_b).toBe('3');
        expect(row.cp_followup).toBe('shown');
        expect(db.dbQuery(`SELECT ended FROM survey_unit_sessions WHERE id = ${Number(sid)}`)[0].ended).not.toBeNull();
        expect(errors, 'no console errors or failed requests').toEqual([]);
    });

    test('the all_widgets_v2 showcase walks to the end in the preview (bot_check included)', async ({ page }) => {
        test.setTimeout(300000);
        const failed = [];
        page.on('response', (r) => { if (r.status() >= 400 && !/favicon/.test(r.url())) failed.push(`${r.status()} ${new URL(r.url()).pathname}`); });
        await page.goto(`${ADMIN_BASE}/admin/survey/all_widgets_v2/access`, { waitUntil: 'domcontentloaded' });
        await v2.waitForBundle(page);
        const r = await driveDefault(page);
        expect(r.completed, JSON.stringify(r)).toBe(true);
        await expect(page.locator('body')).toContainText("You're finished with testing this survey", { timeout: 20000 });
        expect(failed, 'every endpoint answered through the preview').toEqual([]);
    });

    test('a v1 study still previews on the classic engine, and says so', async ({ page }) => {
        await page.goto(`${ADMIN_BASE}/admin/survey/${studyOf('v1')}/access`, { waitUntil: 'domcontentloaded' });
        expect(page.url()).toContain('/survey-test/');
        await expect(page.locator('.fmr-preview-note')).toContainText('as in a Survey unit');
        expect(await page.locator('form.fmr-form-v2').count()).toBe(0);
    });

    test('the survey admin shows which engine a study is on', async ({ page }) => {
        await page.goto(`${ADMIN_BASE}/admin/survey/${studyOf('v2')}`, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.content-header .fmr-engine-badge')).toContainText('v2');
        await page.goto(`${ADMIN_BASE}/admin/survey/${studyOf('v1')}`, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.content-header .fmr-engine-badge')).toContainText('v1');
        await page.goto(`${ADMIN_BASE}/admin/survey/list`, { waitUntil: 'domcontentloaded' });
        const row = page.locator('tr', { has: page.locator(`a[href$="/admin/survey/${studyOf('v2')}"], a[href$="/admin/survey/${studyOf('v2')}/"]`) });
        await expect(row.locator('.fmr-engine-badge')).toContainText('v2');
    });
});

test.describe('the preview write gate', () => {
    test.beforeAll(async ({ browser }) => { await ensureAdminState(browser); });

    // Same refusal as a participant's form (v2-write-endpoints.spec.js),
    // through the preview's own gate (RunController::previewWriteContext).
    test('a preview page rendered for another test session is refused as stale', async ({ page }) => {
        await page.goto(`${ADMIN_BASE}/admin/survey/${studyOf('v2')}/access`, { waitUntil: 'domcontentloaded' });
        await v2.waitForBundle(page);
        const f = await page.evaluate(() => {
            const form = document.querySelector('form.fmr-form-v2');
            const state = JSON.parse(form.querySelector('script.fmr-state').textContent);
            return { submit: form.dataset.submitUrl, usid: Number(state.unit_session_id) };
        });
        const res = await page.request.post(f.submit, { data: { page: 1, unit_session_id: f.usid + 100000, data: {} } });
        expect(res.status()).toBe(409);
        expect((await res.json()).stale_session).toBe(true);
        const ok = await page.request.post(f.submit, { data: { page: 1, unit_session_id: f.usid, data: {} } });
        expect(ok.status(), 'the same write for the right session goes through').toBe(200);
    });
});

// R in the preview reads the preview's own test session, as R for a
// participant reads theirs: user_id is its code, not empty.
test.describe('R in the preview', () => {
    const prov = provisioner();
    const SFX = Date.now().toString(36);
    const name = `e2e_prevcode_${SFX}`;
    let admin;
    test.beforeAll(async ({ browser }) => {
        await ensureAdminState(browser);
        admin = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        await prov.uploadStudy(admin, { engine: 'v2', json: { name, settings: {}, items: [
            { type: 'note', name: 'pc_note', label: 'CODE[`r user_id`]' },
            { type: 'submit', name: 'pc_send', label: 'Send' },
        ] } });
    });
    test.afterAll(async () => {
        if (admin) {
            await prov.cleanup(admin);
            await admin.dispose();
        }
    });

    test('sees the test session code as user_id', async ({ page }) => {
        await page.goto(`${ADMIN_BASE}/admin/survey/${name}/access`, { waitUntil: 'domcontentloaded' });
        await v2.waitForBundle(page);
        // A test code: 64 characters of the user_code rule, not empty.
        await expect(page.locator('form.fmr-form-v2')).toContainText(/CODE\[[A-Za-z0-9+_~-]{64}\]/, { timeout: 15000 });
    });
});
