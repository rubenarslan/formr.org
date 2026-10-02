// A form_v2 Form whose survey has an access window renders as a form.
//
// A survey with `expire_invitation_after` (minutes from the invitation, set
// on the first load) or `expire_after` (minutes since the last activity,
// once the participant has submitted a page: Survey::
// getUnitSessionExpirationData) is queued for its deadline on every page
// load. That load goes through the queue branch of
// RunSession::executeUnitSession, which used to drop the v2 page state
// (`form_v2`): RunController then rendered run/index, which loads no form
// bundle, and every page section stayed hidden — a blank page for the
// participant and in Test Survey. bin/test_form_v2_page_load_smoke.php
// covers the engine; this spec covers what the participant sees.
//
// Each case checks the load really was queued (survey_unit_sessions.queued),
// so it cannot pass on a load that never reached the branch under test.
// Everything is created here and deleted in afterAll: two v2 studies, two
// runs (Form at 10, a Page at 20) and their sessions; the preview's unit
// sessions go with their study.
//
//   npx playwright test --config tests/e2e/playwright.config.js --project=local-chromium v2-access-window

const { request } = require('@playwright/test');
const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { freshParticipant } = require('./helpers/participant');
const { dbQuery, dbExecRaw, dbState, dbNow, dbResultsRow, sqlStr, studyByName } = require('./helpers/db');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');
const v2 = require('./helpers/v2Form');

const AJAX = { 'X-Requested-With': 'XMLHttpRequest' };
const SFX = Date.now().toString(36);
const END_TEXT = `ACCWIN_END_PAGE_${SFX}`;

// Two pages, each with a marker note and a required text, then the end.
const studyJson = (name, settings) => ({
    name, settings,
    items: [
        { type: 'note', name: 'win_note1', label: 'ACCWIN_PAGE_ONE' },
        { type: 'text', name: 'win_q1', label: 'First question' },
        { type: 'submit', name: 'win_s1', label: 'Next' },
        { type: 'note', name: 'win_note2', label: 'ACCWIN_PAGE_TWO' },
        { type: 'text', name: 'win_q2', label: 'Second question' },
        { type: 'submit', name: 'win_s2', label: 'Send' },
    ],
});

const STUDIES = {
    invitation: studyJson(`e2e_accwin_inv_${SFX}`, { expire_invitation_after: 60 }),
    inactivity: studyJson(`e2e_accwin_idle_${SFX}`, { expire_after: 60 }),
};
const RUNS = { invitation: `e2e-accwin-inv-${SFX}`, inactivity: `e2e-accwin-idle-${SFX}` };

const created = { studies: [], runs: [], units: [] };

// --- provisioning (admin requests with the suite's cookie jar) -------------

function alertText(html) {
    const m = String(html).match(/alert-danger[^>]*>([\s\S]{0,400})/);
    return m ? m[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() : '';
}

async function uploadStudy(admin, json) {
    const res = await admin.post(`${ADMIN_BASE}/admin/survey/add_survey/`, {
        multipart: { engine: 'v2', uploaded: { name: `${json.name}.json`, mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(json)) } },
    });
    const study = studyByName(json.name, 'id, name, results_table, rendering_mode, expire_after, expire_invitation_after, expire_invitation_grace');
    if (!study) throw new Error(`add_survey ${json.name}: HTTP ${res.status()} ${alertText(await res.text())}`);
    created.studies.push(json.name);
    if (study.rendering_mode !== 'v2') throw new Error(`${json.name} is on ${study.rendering_mode}, not v2`);
    return study;
}

// A run with the study's Form at 10 and an end Page at 20.
async function createRun(admin, name, studyId) {
    let res = await admin.post(`${ADMIN_BASE}/admin/run/add_run/`, { form: { run_name: name } });
    const run = dbQuery(`SELECT id FROM survey_runs WHERE name = ${sqlStr(name)}`)[0];
    if (!run) throw new Error(`add_run ${name}: HTTP ${res.status()} ${alertText(await res.text())}`);
    created.runs.push(name);
    const rid = Number(run.id);
    // add_run gives the run its special units; deleting the run unlinks
    // them but leaves their unit rows.
    for (const su of dbQuery(`SELECT id FROM survey_run_special_units WHERE run_id = ${rid}`)) created.units.push(Number(su.id));
    const units = [{ type: 'Survey', study_id: studyId }, { type: 'Page', body: END_TEXT, title: '' }];
    for (const [i, u] of units.entries()) {
        const position = (i + 1) * 10;
        res = await admin.post(`${ADMIN_BASE}/admin/run/${name}/ajax_create_run_unit?type=${u.type}&position=${position}`, { headers: AJAX });
        if (!res.ok()) throw new Error(`${name}: create ${u.type} at ${position}: HTTP ${res.status()}`);
        const ru = dbQuery(`SELECT id, unit_id FROM survey_run_units WHERE run_id = ${rid} AND position = ${position}`)[0];
        // A Survey placement starts on a placeholder unit row that linking
        // the study leaves behind: remembered for afterAll.
        created.units.push(Number(ru.unit_id));
        res = await admin.post(`${ADMIN_BASE}/admin/run/${name}/ajax_save_run_unit`, {
            headers: AJAX, form: { unit_id: ru.unit_id, run_unit_id: ru.id, position, ...u },
        });
        if (!res.ok()) throw new Error(`${name}: save ${u.type} at ${position}: HTTP ${res.status()}`);
    }
    dbExecRaw(`UPDATE survey_runs SET public = 2, expiresOn = DATE_ADD(NOW(), INTERVAL 1 YEAR) WHERE id = ${rid}`);
    const placed = dbQuery(`SELECT ru.position, ru.unit_id, u.type FROM survey_run_units ru JOIN survey_units u ON u.id = ru.unit_id `
        + `WHERE ru.run_id = ${rid} ORDER BY ru.position`);
    for (const p of placed) created.units.push(Number(p.unit_id));
    return { id: rid, units: placed.map((p) => ({ position: Number(p.position), unitId: Number(p.unit_id), type: p.type })) };
}

async function cleanup(admin) {
    for (const name of created.runs) {
        await admin.post(`${ADMIN_BASE}/admin/run/${name}/delete_run`, { form: { delete: '1', delete_confirm: name } }).catch(() => {});
    }
    // Deleting a study deletes its unit sessions, the preview's included.
    for (const name of created.studies) {
        await admin.post(`${ADMIN_BASE}/admin/survey/${name}/delete_study`, { form: { delete_confirm: name } }).catch(() => {});
    }
    // Page, service-message and placeholder unit rows that no run or study
    // holds any more.
    const ids = [...new Set(created.units.filter((id) => Number.isInteger(id) && id > 0))];
    if (ids.length) {
        dbExecRaw(`DELETE FROM survey_units WHERE id IN (${ids.join(',')}) `
            + `AND NOT EXISTS (SELECT 1 FROM survey_studies s WHERE s.id = survey_units.id) `
            + `AND NOT EXISTS (SELECT 1 FROM survey_run_units ru WHERE ru.unit_id = survey_units.id) `
            + `AND NOT EXISTS (SELECT 1 FROM survey_run_special_units su WHERE su.id = survey_units.id);`);
    }
}

// --- what the participant sees ------------------------------------------------

const MARKER = { 1: 'ACCWIN_PAGE_ONE', 2: 'ACCWIN_PAGE_TWO' };

// The page is the v2 form (run/form_index): the form bundle is loaded and
// booted, and exactly one page section — page n — is shown, with its items.
// On run/index the sections are all there, but all hidden.
async function expectFormOnPage(page, n) {
    await v2.waitForBundle(page);
    expect(await page.locator('script[src*="/js/form.bundle.js"]').count(), 'the form bundle is loaded').toBe(1);
    const shown = page.locator('form.fmr-form-v2 section.fmr-page:not([hidden])');
    await expect(shown, 'one page section is shown').toHaveCount(1);
    await expect(shown).toHaveAttribute('data-fmr-page', String(n));
    await expect(shown).toBeVisible();
    await expect(shown.getByText(MARKER[n])).toBeVisible();
    await expect(shown.locator(`input[name="win_q${n}"]`)).toBeVisible();
    expect(await v2.visiblePageNum(page)).toBe(n);
}

// Evidence of what was shown, under .playwright-mcp/ (gitignored).
async function snap(page, name) {
    await page.screenshot({ path: `.playwright-mcp/v2-access-window-${name}.png` }).catch(() => {});
}

async function answer(page, n, value) {
    await page.locator(`form.fmr-form-v2 input[name="win_q${n}"]`).fill(value);
}

async function submitPage(page) {
    const r = await v2.submitV2(page, { timeout: 20000 });
    expect(r.status, JSON.stringify(r)).toBe(200);
    return r.body;
}

// The unit session's queue state: queued > 0 means the load went through
// the queue branch (UnitSession::queue), with `expires` its deadline.
function queueOf(usid) {
    const s = dbState(usid);
    return { queued: Number(s.queued), expires: s.expires_unix, created: s.created_unix, ended: s.ended };
}

// --- the cases ------------------------------------------------------------------

test.describe('a form_v2 Form whose survey has an access window', () => {
    test.skip(RUNNING_ON_BS, 'provisions runs and reads the database directly');

    let admin = null;
    const fx = {};
    let consoleErrors = [];

    test.beforeAll(async ({ browser }) => {
        test.setTimeout(180000);
        await ensureAdminState(browser);
        admin = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        fx.invitation = await uploadStudy(admin, STUDIES.invitation);
        fx.inactivity = await uploadStudy(admin, STUDIES.inactivity);
        // The windows the import was given are the study's (X: invitation,
        // Y: grace, Z: inactivity, in minutes).
        const win = (s) => ({ X: Number(s.expire_invitation_after || 0), Y: Number(s.expire_invitation_grace || 0), Z: Number(s.expire_after || 0) });
        expect(win(fx.invitation)).toEqual({ X: 60, Y: 0, Z: 0 });
        expect(win(fx.inactivity)).toEqual({ X: 0, Y: 0, Z: 60 });
        for (const key of Object.keys(RUNS)) {
            const run = await createRun(admin, RUNS[key], fx[key].id);
            expect(run.units.map((u) => `${u.position}:${u.type}`).join(' '), key).toBe('10:Form 20:Page');
            expect(run.units[0].unitId, `${key}: the Form is the v2 study`).toBe(Number(fx[key].id));
        }
    });

    test.afterAll(async () => {
        if (!admin) return;
        await cleanup(admin);
        await admin.dispose();
    });

    // Every case: nothing on the page logs an error (the form bundle's
    // failures surface as console errors or uncaught page errors).
    test.beforeEach(async ({ page }) => {
        consoleErrors = [];
        page.on('console', (m) => {
            if (m.type() === 'error') consoleErrors.push(`console: ${m.text()} @ ${(m.location() || {}).url || '?'}`);
        });
        page.on('pageerror', (e) => consoleErrors.push(`pageerror: ${e.message}`));
    });
    test.afterEach(async () => {
        expect(consoleErrors.filter((e) => !/favicon/.test(e)), 'no console errors').toEqual([]);
    });

    test('expire_invitation_after: a fresh participant sees page 1, then page 2 (also after a reload), and ends on the end page', async ({ page, baseURL }) => {
        await freshParticipant(page, RUNS.invitation, { baseURL });
        await expectFormOnPage(page, 1);
        await snap(page, 'invitation-page1');
        const usid = await v2.unitSessionId(page);
        expect(usid, 'the run opens on the Form').toBeTruthy();
        // The first load was queued for the invitation deadline: the branch
        // under test, an hour after the invitation.
        const q = queueOf(usid);
        expect(q.queued, 'the load was queued').toBeGreaterThan(0);
        expect(q.expires - q.created, 'deadline = invitation + 60 min').toBe(3600);

        await answer(page, 1, 'first');
        const r1 = await submitPage(page);
        expect(v2.advanced(r1), JSON.stringify(r1)).toBe(true);
        await expectFormOnPage(page, 2);

        // Reloading mid-form shows the form again, on page 2. Whether this
        // load is queued depends on timing: expire_invitation_after alone
        // bounds only *starting*, and a page saved within 2 s of the
        // invitation does not count as started. The expire_after case below
        // has the queued reload for certain; here either path must render.
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expectFormOnPage(page, 2);
        expect(await v2.unitSessionId(page), 'the same unit session').toBe(usid);
        test.info().annotations.push({ type: 'reload', description: queueOf(usid).queued > 0 ? 'queued' : 'not queued' });

        await answer(page, 2, 'second');
        const r2 = await submitPage(page);
        expect(r2.complete, JSON.stringify(r2)).toBe(true);
        await expect(page.getByText(END_TEXT)).toBeVisible({ timeout: 15000 });
        await expect(page.locator('form.fmr-form-v2')).toHaveCount(0);
        expect(queueOf(usid).ended, 'the Form ended').not.toBeNull();
        expect(dbResultsRow(fx.invitation.results_table, usid)).toMatchObject({ win_q1: 'first', win_q2: 'second' });
    });

    test('expire_after: after page 1 is in, a reload (now queued) still renders the form on page 2, and it completes', async ({ page, baseURL }) => {
        await freshParticipant(page, RUNS.inactivity, { baseURL });
        await expectFormOnPage(page, 1);
        await snap(page, 'inactivity-page1');
        const usid = await v2.unitSessionId(page);
        expect(usid, 'the run opens on the Form').toBeTruthy();
        // Not started yet: expire_after sets no deadline, nothing is queued.
        expect(queueOf(usid).queued, 'the first load is not queued').toBe(0);

        // Started = a page saved more than 2 s after the invitation
        // (Survey::getUnitSessionExpirationData): let that much time pass.
        await expect.poll(() => dbNow() - queueOf(usid).created, { timeout: 10000, intervals: [500] }).toBeGreaterThan(2);
        await answer(page, 1, 'first');
        const r1 = await submitPage(page);
        expect(v2.advanced(r1), JSON.stringify(r1)).toBe(true);
        await expectFormOnPage(page, 2);

        await page.reload({ waitUntil: 'domcontentloaded' });
        await expectFormOnPage(page, 2);
        expect(await v2.unitSessionId(page), 'the same unit session').toBe(usid);
        await snap(page, 'inactivity-reload-page2');
        // This load was queued: an hour after the last activity.
        const q = queueOf(usid);
        expect(q.queued, 'the reload was queued').toBeGreaterThan(0);
        const left = q.expires - dbNow();
        expect(left, 'deadline = last activity + 60 min').toBeGreaterThan(3600 - 120);
        expect(left).toBeLessThanOrEqual(3600);

        await answer(page, 2, 'second');
        const r2 = await submitPage(page);
        expect(r2.complete, JSON.stringify(r2)).toBe(true);
        await expect(page.getByText(END_TEXT)).toBeVisible({ timeout: 15000 });
        await expect(page.locator('form.fmr-form-v2')).toHaveCount(0);
        expect(queueOf(usid).ended, 'the Form ended').not.toBeNull();
        expect(dbResultsRow(fx.inactivity.results_table, usid)).toMatchObject({ win_q1: 'first', win_q2: 'second' });
    });

    test.describe('Test Survey', () => {
        test.use({ storageState: STATE_PATH });

        test('a study with expire_invitation_after previews as a Form, page 1 shown, and can be finished', async ({ page }) => {
            await page.goto(`${ADMIN_BASE}/admin/survey/${STUDIES.invitation.name}/access`, { waitUntil: 'domcontentloaded' });
            expect(page.url()).toContain('/survey-test/');
            await expectFormOnPage(page, 1);
            await snap(page, 'preview-page1');
            await expect(page.locator('.fmr-preview-note')).toContainText('v2');
            const usid = await v2.unitSessionId(page);
            expect(usid).toBeTruthy();
            const q = queueOf(usid);
            expect(q.queued, 'the preview load was queued').toBeGreaterThan(0);
            expect(q.expires - q.created).toBe(3600);

            await answer(page, 1, 'preview first');
            const r1 = await submitPage(page);
            expect(v2.advanced(r1), JSON.stringify(r1)).toBe(true);
            await expectFormOnPage(page, 2);
            await page.reload({ waitUntil: 'domcontentloaded' });
            await expectFormOnPage(page, 2);

            await answer(page, 2, 'preview second');
            const r2 = await submitPage(page);
            expect(r2.complete, JSON.stringify(r2)).toBe(true);
            await expect(page.locator('body')).toContainText("You're finished with testing this survey", { timeout: 20000 });
            expect(dbResultsRow(fx.invitation.results_table, usid)).toMatchObject({ win_q1: 'preview first', win_q2: 'preview second' });
        });
    });
});
