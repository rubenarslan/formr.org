// The unit after a form_v2 Form, run by the form's final submit.
//
// A Form's final page submit (POST /{run}/form-page-submit, `final: true`)
// ends the Form's unit session and executes what follows inside that same
// request (FormSubmissionService::apply → RunSession::endFormUnitSession).
// Two things went wrong there:
//   1. an External right after the Form never sent the participant to its
//      address: its redirect was dropped and the reply always named the run
//      URL. The reply's `redirect` is now the External's address.
//   2. the next unit ran as though it had received the Form's POST: a paged
//      v1 Survey refused it ("Invalid Survey Page", result error_survey),
//      and a v1 Survey could take the request's fields for answers. The next
//      unit now gets no submission (PageSubmission): it runs as on a page
//      load.
// External units only redirect over HTTP (never from the CLI), hence a
// browser spec. Each case drives a real participant through a one-page Form
// (nx_text, nx_choice, nx_file, a submit) placed at 10, the unit under test
// at 20 and a Page at 30. Everything is created here and deleted in
// afterAll: two v1 studies, one v2 study, four runs.

const { execFileSync } = require('node:child_process');
const { request } = require('@playwright/test');
const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { freshParticipant } = require('./helpers/participant');
const { dbQuery, dbExecRaw, sqlStr, studyByName } = require('./helpers/db');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');
const v2 = require('./helpers/v2Form');

const STUDY_BASE = (process.env.FORMR_PARTICIPANT_URL || 'https://study.researchmixtape.com').replace(/\/+$/, '');
const AJAX = { 'X-Requested-With': 'XMLHttpRequest' };
const SFX = Date.now().toString(36);
const UPLOADS = '/var/www/formr/webroot/assets/tmp/user_uploaded_files';
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');

// An address on the dev host that needs no internet: an unknown run name,
// answered 404. Only the URL the browser ends up on is checked.
const EXTERNAL = (tag) => `${STUDY_BASE}/e2e-external-target/?from=formr&case=${tag}-${SFX}`;
const runUrl = (run) => `${STUDY_BASE}/${run}/`;

// What a multipart form_v2 submit puts at the top level of $_POST / $_FILES
// (offline/queue.js buildFormData). A v1 Survey that read the Form's request
// would see these, not the Form's answers (those sit under data[...]).
const ENVELOPE_NAMES = ['page', 'final', 'doc', 'seq', 'uuid', 'client_ts', 'unit_session_id',
    'hidden', 'data', 'item_views', 'files'];
const SHARED_NAMES = ['nx_text', 'nx_choice', 'nx_file', ...ENVELOPE_NAMES];

const STUDIES = {
    form: {
        engine: 'v2',
        json: {
            name: `e2e_nx_form_${SFX}`, settings: {},
            items: [
                { type: 'text', name: 'nx_text', label: 'NX_FORM text', optional: 1 },
                { type: 'mc', name: 'nx_choice', label: 'NX_FORM choice', optional: 1, choice_list: 'nxc', choices: { 1: 'A', 2: 'B' } },
                { type: 'file', name: 'nx_file', label: 'NX_FORM file', optional: 1 },
                { type: 'submit', name: 'nx_send', label: 'Send' },
            ],
        },
    },
    paged: {
        engine: 'v1',
        json: {
            name: `e2e_nx_paged_${SFX}`, settings: { use_paging: 1 },
            items: [
                { type: 'note', name: 'nxp_note1', label: 'NX_PAGED_PAGE_ONE' },
                { type: 'text', name: 'nxp_q1', label: 'Paged question', optional: 1 },
                { type: 'submit', name: 'nxp_s1', label: 'Next' },
                { type: 'note', name: 'nxp_note2', label: 'NX_PAGED_PAGE_TWO' },
                { type: 'submit', name: 'nxp_s2', label: 'Done' },
            ],
        },
    },
    shared: {
        engine: 'v1',
        json: {
            name: `e2e_nx_shared_${SFX}`, settings: {},
            items: [
                { type: 'note', name: 'nxs_note', label: 'NX_SHARED_SURVEY' },
                { type: 'text', name: 'nx_text', label: 'Same name as the form text', optional: 1 },
                { type: 'mc', name: 'nx_choice', label: 'Same name as the form choice', optional: 1, choice_list: 'nxc', choices: { 1: 'A', 2: 'B' } },
                { type: 'file', name: 'nx_file', label: 'Same name as the form file', optional: 1 },
                ...ENVELOPE_NAMES.map((n) => ({ type: 'text', name: n, label: `Named like the request field ${n}`, optional: 1 })),
                { type: 'submit', name: 'nxs_send', label: 'Send' },
            ],
        },
    },
};

const RUNS = {
    external: `e2e-nx-ext-${SFX}`,
    externalApi: `e2e-nx-extapi-${SFX}`,
    paged: `e2e-nx-paged-${SFX}`,
    shared: `e2e-nx-shared-${SFX}`,
};

const created = { studies: [], runs: [], units: [] };

// --- provisioning (admin requests with the suite's cookie jar) -------------

function alertText(html) {
    const m = String(html).match(/alert-danger[^>]*>([\s\S]{0,400})/);
    return m ? m[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() : '';
}

async function uploadStudy(admin, { engine, json }) {
    const res = await admin.post(`${ADMIN_BASE}/admin/survey/add_survey/`, {
        multipart: { engine, uploaded: { name: `${json.name}.json`, mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(json)) } },
    });
    const study = studyByName(json.name, 'id, name, results_table, rendering_mode, use_paging');
    if (!study) throw new Error(`add_survey ${json.name}: HTTP ${res.status()} ${alertText(await res.text())}`);
    created.studies.push(json.name);
    if (study.rendering_mode !== engine) throw new Error(`${json.name} is on ${study.rendering_mode}, not ${engine}`);
    return study;
}

// units: [{ type: 'Survey', studyId } | { type: 'External', address, apiEnd } | { type: 'Page', body }]
// placed at 10, 20, 30, …
async function createRun(admin, name, units) {
    let res = await admin.post(`${ADMIN_BASE}/admin/run/add_run/`, { form: { run_name: name } });
    const run = dbQuery(`SELECT id FROM survey_runs WHERE name = ${sqlStr(name)}`)[0];
    if (!run) throw new Error(`add_run ${name}: HTTP ${res.status()} ${alertText(await res.text())}`);
    created.runs.push(name);
    const rid = Number(run.id);
    // add_run gives the run its special units (the service message page);
    // deleting the run unlinks them but leaves their unit rows.
    for (const su of dbQuery(`SELECT id FROM survey_run_special_units WHERE run_id = ${rid}`)) created.units.push(Number(su.id));
    for (const [i, u] of units.entries()) {
        const position = (i + 1) * 10;
        res = await admin.post(`${ADMIN_BASE}/admin/run/${name}/ajax_create_run_unit?type=${u.type}&position=${position}`, { headers: AJAX });
        if (!res.ok()) throw new Error(`${name}: create ${u.type} at ${position}: HTTP ${res.status()}`);
        const ru = dbQuery(`SELECT id, unit_id FROM survey_run_units WHERE run_id = ${rid} AND position = ${position}`)[0];
        // A Survey placement starts on a placeholder unit row that linking
        // the study leaves behind: remembered for afterAll.
        created.units.push(Number(ru.unit_id));
        const form = { unit_id: ru.unit_id, run_unit_id: ru.id, type: u.type, position };
        if (u.type === 'Survey') form.study_id = u.studyId;
        if (u.type === 'External') Object.assign(form, { external_link: u.address, api_end: u.apiEnd ? 1 : 0, expire_after: 0 });
        if (u.type === 'Page') Object.assign(form, { body: u.body, title: '' });
        res = await admin.post(`${ADMIN_BASE}/admin/run/${name}/ajax_save_run_unit`, { headers: AJAX, form });
        if (!res.ok()) throw new Error(`${name}: save ${u.type} at ${position}: HTTP ${res.status()}`);
    }
    dbExecRaw(`UPDATE survey_runs SET public = 2, expiresOn = DATE_ADD(NOW(), INTERVAL 1 YEAR) WHERE id = ${rid}`);
    const placed = dbQuery(`SELECT ru.position, ru.unit_id, u.type FROM survey_run_units ru JOIN survey_units u ON u.id = ru.unit_id `
        + `WHERE ru.run_id = ${rid} ORDER BY ru.position`);
    // Saving may have moved a placement onto another unit row (a Survey onto
    // its study, which cleanup leaves to delete_study).
    for (const p of placed) created.units.push(Number(p.unit_id));
    return { id: rid, units: placed.map((p) => ({ position: Number(p.position), unitId: Number(p.unit_id), type: p.type })) };
}

async function cleanup(admin) {
    // Uploads a failed test did not get to remove: they outlive the sessions
    // that deleting the runs takes with them.
    const form = created.studies.includes(STUDIES.form.json.name) && studyByName(STUDIES.form.json.name);
    if (form) {
        for (const d of dbQuery(`SELECT d.answer FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id `
            + `WHERE i.study_id = ${Number(form.id)} AND i.name = 'nx_file' AND d.answer IS NOT NULL`)) removeUpload(d.answer);
    }
    for (const name of created.runs) {
        await admin.post(`${ADMIN_BASE}/admin/run/${name}/delete_run`, { form: { delete: '1', delete_confirm: name } }).catch(() => {});
    }
    for (const name of created.studies) {
        await admin.post(`${ADMIN_BASE}/admin/survey/${name}/delete_study`, { form: { delete_confirm: name } }).catch(() => {});
    }
    // External, Page, service-message and placeholder unit rows that no run
    // or study holds any more.
    const ids = [...new Set(created.units.filter((id) => Number.isInteger(id) && id > 0))];
    if (ids.length) {
        dbExecRaw(`DELETE FROM survey_units WHERE id IN (${ids.join(',')}) `
            + `AND NOT EXISTS (SELECT 1 FROM survey_studies s WHERE s.id = survey_units.id) `
            + `AND NOT EXISTS (SELECT 1 FROM survey_run_units ru WHERE ru.unit_id = survey_units.id) `
            + `AND NOT EXISTS (SELECT 1 FROM survey_run_special_units su WHERE su.id = survey_units.id);`);
    }
}

// --- what the database says -------------------------------------------------

function runSessionOf(unitSessionId) {
    return Number(dbQuery(`SELECT run_session_id FROM survey_unit_sessions WHERE id = ${Number(unitSessionId)}`)[0].run_session_id);
}

function runSession(id) {
    const r = dbQuery(`SELECT position, current_unit_session_id, ended FROM survey_run_sessions WHERE id = ${Number(id)}`)[0];
    return { position: Number(r.position), current: r.current_unit_session_id === null ? null : Number(r.current_unit_session_id), ended: r.ended };
}

// The run session's unit sessions in creation order, with their placement.
function unitSessions(runSessionId) {
    return dbQuery(`SELECT us.id, us.unit_id, ru.position, u.type, us.state, us.result, us.result_log, us.ended, us.expired `
        + `FROM survey_unit_sessions us JOIN survey_units u ON u.id = us.unit_id `
        + `LEFT JOIN survey_run_units ru ON ru.id = us.run_unit_id `
        + `WHERE us.run_session_id = ${Number(runSessionId)} ORDER BY us.id`)
        .map((r) => Object.assign(r, { id: Number(r.id), unit_id: Number(r.unit_id), position: r.position === null ? null : Number(r.position) }));
}

const at = (rows, position) => rows.filter((r) => r.position === position);

// Every displayed item of a unit session with what is stored for it.
function displayed(unitSessionId) {
    return dbQuery(`SELECT i.name, d.answer, d.saved, d.answered, d.answered_relative, d.shown, d.shown_relative, d.displaycount `
        + `FROM survey_items_display d `
        + `JOIN survey_items i ON i.id = d.item_id WHERE d.session_id = ${Number(unitSessionId)} ORDER BY d.display_order, i.id`);
}

function resultsRow(study, unitSessionId) {
    if (!/^[A-Za-z0-9_]+$/.test(study.results_table)) throw new Error(`unsafe results_table ${study.results_table}`);
    return dbQuery(`SELECT * FROM \`${study.results_table}\` WHERE session_id = ${Number(unitSessionId)}`)[0] || null;
}

function answer(unitSessionId, name) {
    const r = displayed(unitSessionId).find((d) => d.name === name);
    return r ? r.answer : undefined;
}

// A stored upload lives in formr_app's volume: removed once checked.
function removeUpload(storedAnswer) {
    const m = storedAnswer && String(storedAnswer).match(/user_uploaded_files\/([A-Za-z0-9_.-]+)/);
    if (!m || m[1].includes('..')) return false;
    try { execFileSync('docker', ['exec', 'formr_app', 'rm', '-f', `${UPLOADS}/${m[1]}`]); return true; } catch (e) { return false; }
}

// --- the participant ----------------------------------------------------------

async function openForm(page, baseURL, run) {
    await freshParticipant(page, run, { baseURL });
    await v2.waitForBundle(page);
    const usid = await v2.unitSessionId(page);
    expect(usid, 'the run opens on the Form').toBeTruthy();
    return { usid, rsid: runSessionOf(usid) };
}

// Hold the browser's navigation to the run URL (and to a paged survey's
// page, /{run}/N/) until released: the reply's redirect is followed 600 ms
// after it lands, and that GET executes the next unit again. Held, what the
// database shows is what the final submit itself did.
async function holdRunNavigation(page, run) {
    const base = runUrl(run);
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    await page.route((u) => u.href === base || (u.href.startsWith(base) && /^\d+\/?$/.test(u.href.slice(base.length))),
        async (route) => {
            if (route.request().isNavigationRequest()) await gate;
            await route.continue();
        });
    return release;
}

// Click the form's (only, so final) Submit; resolve with the request and the
// reply of /form-page-submit.
async function sendFinal(page) {
    const reqP = page.waitForRequest((r) => /\/form-page-submit\/?$/.test(new URL(r.url()).pathname) && r.method() === 'POST', { timeout: 20000 });
    const sent = await v2.submitV2(page, { timeout: 20000 });
    const req = await reqP;
    expect(sent.status, JSON.stringify(sent.body)).toBe(200);
    return { body: sent.body, contentType: req.headers()['content-type'] || '' };
}

// --- the cases ------------------------------------------------------------------

test.describe('the unit after a form_v2 Form, run by its final submit', () => {
    test.skip(RUNNING_ON_BS, 'provisions runs and reads the database directly');

    let admin = null;
    const fx = {};

    test.beforeAll(async ({ browser }) => {
        test.setTimeout(240000);
        await ensureAdminState(browser);
        admin = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        fx.form = await uploadStudy(admin, STUDIES.form);
        fx.paged = await uploadStudy(admin, STUDIES.paged);
        fx.shared = await uploadStudy(admin, STUDIES.shared);
        expect(Number(fx.paged.use_paging), 'the paged study pages').toBe(1);
        const form = { type: 'Survey', studyId: fx.form.id };
        const end = { type: 'Page', body: `NX_END_PAGE_${SFX}` };
        fx.runs = {
            external: await createRun(admin, RUNS.external, [form, { type: 'External', address: EXTERNAL('plain') }, end]),
            externalApi: await createRun(admin, RUNS.externalApi, [form, { type: 'External', address: EXTERNAL('api'), apiEnd: true }, end]),
            paged: await createRun(admin, RUNS.paged, [form, { type: 'Survey', studyId: fx.paged.id }, end]),
            shared: await createRun(admin, RUNS.shared, [form, { type: 'Survey', studyId: fx.shared.id }, end]),
        };
        for (const [key, run] of Object.entries(fx.runs)) {
            const types = run.units.map((u) => `${u.position}:${u.type}`).join(' ');
            const next = key.startsWith('external') ? 'External' : 'Survey';
            expect(types, key).toBe(`10:Form 20:${next} 30:Page`);
            expect(run.units[0].unitId, `${key}: the Form is the v2 study`).toBe(Number(fx.form.id));
        }
    });

    test.afterAll(async () => {
        if (!admin) return;
        await cleanup(admin);
        await admin.dispose();
    });

    test('Form → External: the reply sends the browser to its address; the External ends and the run moves past it', async ({ page, baseURL }) => {
        const { rsid } = await openForm(page, baseURL, RUNS.external);
        const { body } = await sendFinal(page);
        expect(body.complete, JSON.stringify(body)).toBe(true);
        expect(body.redirect, 'the External\'s address, not the run URL').toBe(EXTERNAL('plain'));
        await page.waitForURL((u) => u.href === EXTERNAL('plain'), { timeout: 15000 });

        const rows = unitSessions(rsid);
        expect(at(rows, 10).map((r) => [r.type, r.state])).toEqual([['Form', 'ENDED']]);
        const ext = at(rows, 20);
        expect(ext, JSON.stringify(rows)).toHaveLength(1);
        expect(ext[0]).toMatchObject({ type: 'External', state: 'ENDED', result: 'external_redirect' });
        expect(ext[0].ended).not.toBeNull();
        // Moved past it: the Page's unit session is current, waiting for the
        // participant to come back.
        const end = at(rows, 30);
        expect(end, JSON.stringify(rows)).toHaveLength(1);
        expect(runSession(rsid)).toMatchObject({ position: 30, current: end[0].id, ended: null });

        // Back from the External, the run shows what follows it.
        await page.goto(runUrl(RUNS.external), { waitUntil: 'domcontentloaded' });
        await expect(page.getByText(`NX_END_PAGE_${SFX}`)).toBeVisible({ timeout: 15000 });
    });

    test('Form → External ended by the API: the reply still sends the browser there; the External stays open for the callback', async ({ page, baseURL }) => {
        const { rsid } = await openForm(page, baseURL, RUNS.externalApi);
        const { body } = await sendFinal(page);
        expect(body.complete, JSON.stringify(body)).toBe(true);
        expect(body.redirect).toBe(EXTERNAL('api'));
        await page.waitForURL((u) => u.href === EXTERNAL('api'), { timeout: 15000 });

        const rows = unitSessions(rsid);
        const ext = at(rows, 20);
        expect(ext, JSON.stringify(rows)).toHaveLength(1);
        expect(ext[0]).toMatchObject({ type: 'External', result: 'external_wait_for_api', ended: null, expired: null });
        expect(['ENDED', 'EXPIRED']).not.toContain(ext[0].state);
        // As on a v1 GET (RunSession::executeUnitSession), the run session
        // moves on before the redirect; the External's unit session is left
        // for the external service to end.
        const end = at(rows, 30);
        expect(end, JSON.stringify(rows)).toHaveLength(1);
        expect(runSession(rsid)).toMatchObject({ position: 30, current: end[0].id });

        const code = dbQuery(`SELECT session FROM survey_run_sessions WHERE id = ${rsid}`)[0].session;
        const res = await page.request.post(`${ADMIN_BASE}/api/post/end-last-external`, {
            headers: { 'Content-Type': 'application/json' }, data: { run: { name: RUNS.externalApi, session: code } },
        });
        expect(res.status(), await res.text()).toBe(200);
        expect(unitSessions(rsid).find((r) => r.id === ext[0].id)).toMatchObject({ state: 'ENDED', result: 'external_ended' });
    });

    test('Form → paged v1 Survey: the survey is not handed the Form\'s POST, and the redirect shows its first page', async ({ page, baseURL }) => {
        const { rsid } = await openForm(page, baseURL, RUNS.paged);
        const release = await holdRunNavigation(page, RUNS.paged);
        const { body } = await sendFinal(page);
        expect(body.complete, JSON.stringify(body)).toBe(true);

        const rows = unitSessions(rsid);
        const survey = at(rows, 20);
        expect(survey, JSON.stringify(rows)).toHaveLength(1);
        expect(survey[0].type).toBe('Survey');
        expect(survey[0].result, `${survey[0].result}: ${survey[0].result_log}`).not.toBe('error_survey');
        expect(survey[0]).toMatchObject({ ended: null, expired: null });
        expect(runSession(rsid)).toMatchObject({ position: 20, current: survey[0].id, ended: null });

        // The survey's own first answer to a GET is a redirect to its page:
        // the reply carries it.
        expect(body.redirect, 'the survey\'s first page').toBe(`${runUrl(RUNS.paged)}1/`);
        release();
        await expect(page.getByText('NX_PAGED_PAGE_ONE')).toBeVisible({ timeout: 15000 });
        await expect(page.locator('input[name="nxp_q1"]')).toBeVisible();
        await expect(page.getByText('NX_PAGED_PAGE_TWO')).toHaveCount(0);
        await expect(page.locator('form.fmr-form-v2')).toHaveCount(0);
        const after = unitSessions(rsid).find((r) => r.id === survey[0].id);
        expect(after.result, `${after.result}: ${after.result_log}`).not.toBe('error_survey');
        expect(after.ended).toBeNull();
    });

    for (const transport of ['multipart', 'json']) {
        test(`Form → v1 Survey with the same item names: nothing from the Form's ${transport} POST is stored for it`, async ({ page, baseURL }) => {
            const { usid, rsid } = await openForm(page, baseURL, RUNS.shared);
            const release = await holdRunNavigation(page, RUNS.shared);
            await page.locator('form.fmr-form-v2 input[name="nx_text"]').fill('NX_LEAK_TEXT');
            await page.locator('form.fmr-form-v2 input[name="nx_choice"][value="2"]').check({ force: true });
            if (transport === 'multipart') {
                await page.locator('form.fmr-form-v2 input[type=file][name="nx_file"]').setInputFiles({ name: 'nx.png', mimeType: 'image/png', buffer: PNG });
            }
            const { body, contentType } = await sendFinal(page);
            expect(contentType).toMatch(transport === 'multipart' ? /^multipart\/form-data/ : /^application\/json/);
            expect(body.complete, JSON.stringify(body)).toBe(true);
            expect(body.redirect, 'the survey redirects nowhere: back to the run').toBe(runUrl(RUNS.shared));

            // The Form's POST carried these values; the Form stored them ...
            expect(answer(usid, 'nx_text')).toBe('NX_LEAK_TEXT');
            expect(answer(usid, 'nx_choice')).toBe('2');
            if (transport === 'multipart') {
                const stored = answer(usid, 'nx_file');
                expect(stored).toMatch(/user_uploaded_files\//);
                removeUpload(stored);
            }

            // ... and the v1 Survey executed in the same request stored none of
            // them, nor any request field.
            const rows = unitSessions(rsid);
            const survey = at(rows, 20);
            expect(survey, JSON.stringify(rows)).toHaveLength(1);
            expect(survey[0].type).toBe('Survey');
            expect(survey[0].result, `${survey[0].result}: ${survey[0].result_log}`).not.toBe('error_survey');
            expect(survey[0]).toMatchObject({ ended: null, expired: null });
            const shown = displayed(survey[0].id).filter((d) => SHARED_NAMES.includes(d.name));
            // Rendered once — by the final submit, the redirect is still held.
            expect(shown.map((d) => `${d.name}:${d.displaycount}`).sort(), 'the survey ran in the final submit')
                .toEqual(SHARED_NAMES.map((n) => `${n}:1`).sort());
            const PARADATA = ['answer', 'saved', 'answered', 'answered_relative', 'shown', 'shown_relative'];
            const stored = shown.filter((d) => PARADATA.some((c) => d[c] !== null));
            expect(stored, 'answers or paradata stored for the next unit').toEqual([]);
            const row = resultsRow(fx.shared, survey[0].id);
            const none = Object.fromEntries(SHARED_NAMES.map((n) => [n, null]));
            expect(row, 'the survey has its results row').not.toBeNull();
            expect(Object.fromEntries(SHARED_NAMES.map((n) => [n, row[n]]))).toEqual(none);

            // Following the redirect shows the survey, blank.
            release();
            await expect(page.getByText('NX_SHARED_SURVEY')).toBeVisible({ timeout: 15000 });
            await expect(page.locator('input[name="nx_text"]')).toHaveValue('');
            await expect(page.locator('input[name="page"]')).toHaveValue('');
            expect(displayed(survey[0].id).filter((d) => d.answer !== null)).toEqual([]);
        });
    }
});
