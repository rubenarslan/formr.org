// form_v2 write endpoints, against the real server (no mocks).
//
// The queue specs mock /form-sync, so the server half of at-most-once
// delivery had no test (coverage pass, documentation/agent_doc/
// coverage_pass.md gaps 1–2):
//   - /form-sync applies an entry once: a resend of the same uuid is
//     answered already_applied and changes nothing;
//   - a refusal (validation errors) or a failure leaves no ledger row, so
//     the corrected resend of the same uuid applies;
//   - a malformed envelope is refused with 400 naming the field;
//   - the shared write gate refuses: no run session (403), rate limit
//     (429), a current unit that is not a v2 Form (409), a page rendered
//     for another unit session (409 stale_session).
// Requests go through page.request, which carries the participant's
// cookies. Fixture: run e2e-inputs-v2 (study e2e_v2_inputs: i_mc, i_mc_req,
// i_btn, i_zip [0-9]{5}, i_email on page 1, a note on page 2).

const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const { dbQuery, dbExecRaw } = require('./helpers/db');
const v2 = require('./helpers/v2Form');
const { currentFormContext, mysqlNow } = require('./helpers/idbQueue');

const RUN = () => runName('v2_inputs', 'v2');

async function start(page, baseURL) {
    await freshParticipant(page, RUN(), { baseURL });
    await v2.waitForBundle(page);
    const ctx = await currentFormContext(page);
    ctx.submitUrl = await page.evaluate(() => document.querySelector('form.fmr-form-v2').dataset.submitUrl);
    ctx.renderUrl = ctx.runUrl.replace(/\/?$/, '/') + 'form-render-page';
    ctx.saveUrl = await page.evaluate(() => document.querySelector('form.fmr-form-v2').dataset.saveUrl);
    return ctx;
}

const entry = (ctx, data, extra = {}) => Object.assign({
    uuid: crypto.randomUUID(), page: ctx.pageNum, unit_session_id: ctx.unitSessionId,
    client_ts: mysqlNow(), data, item_views: {},
}, extra);

async function post(page, url, body) {
    const res = await page.request.post(url, { data: body, headers: { Accept: 'application/json' } });
    return { status: res.status(), body: await res.json().catch(() => null) };
}

const ledger = (uuid) => dbQuery(`SELECT uuid, client_ts FROM survey_form_submissions WHERE uuid = '${uuid}';`);
const answer = (usid, name) => {
    const rows = dbQuery(`SELECT d.answer FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id `
        + `WHERE d.session_id = ${Number(usid)} AND i.name = '${name}';`);
    return rows.length ? rows[0].answer : undefined;
};

test.describe('/form-sync applies an entry at most once', () => {
    test.skip(RUNNING_ON_BS, 'drives the server directly');

    test('a resent entry is answered already_applied and changes nothing', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        const e = entry(ctx, { i_zip: '12345' });
        const first = await post(page, ctx.syncUrl, e);
        expect(first.status).toBe(200);
        expect(first.body.status).toBe('ok');
        expect(answer(ctx.unitSessionId, 'i_zip')).toBe('12345');

        const again = await post(page, ctx.syncUrl, Object.assign({}, e, { data: { i_zip: '99999' } }));
        expect(again.status).toBe(200);
        expect(again.body).toEqual({ status: 'ok', already_applied: true });
        expect(answer(ctx.unitSessionId, 'i_zip'), 'the resend changed nothing').toBe('12345');
        expect(ledger(e.uuid)).toHaveLength(1);
    });

    test('an entry refused for validation errors leaves no ledger row; the corrected resend applies', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        const e = entry(ctx, { i_email: 'not-an-address' });
        const refused = await post(page, ctx.syncUrl, e);
        expect(refused.status).toBe(200);
        expect(refused.body.status).toBe('errors');
        expect(Object.keys(refused.body.errors)).toContain('i_email');
        expect(ledger(e.uuid), 'nothing applied, nothing recorded').toHaveLength(0);
        expect(answer(ctx.unitSessionId, 'i_email') || null).toBeNull();

        const fixed = await post(page, ctx.syncUrl, Object.assign({}, e, { data: { i_email: 'someone@example.org' } }));
        expect(fixed.body.status).toBe('ok');
        expect(fixed.body.already_applied).toBeUndefined();
        expect(answer(ctx.unitSessionId, 'i_email')).toBe('someone@example.org');
        expect(ledger(e.uuid)).toHaveLength(1);
    });

    test('an entry that fails to store leaves no ledger row; the resend applies', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        // A write the database refuses after the ledger row was inserted:
        // the failure path. A CHECK constraint refusing one value stands in
        // for any such refusal (a results column too small for an answer,
        // say — what a table created before a column-type fix looks like).
        const table = dbQuery("SELECT results_table FROM survey_studies WHERE name = 'e2e_v2_inputs';")[0].results_table;
        // A value no stored row holds: the constraint is checked against the
        // table's rows when added, and every run stores the one it refused.
        let refused;
        do {
            refused = String(10000 + Math.floor(Math.random() * 90000));
        } while (dbQuery(`SELECT 1 AS hit FROM \`${table}\` WHERE \`i_zip\` = '${refused}' LIMIT 1;`).length);
        dbExecRaw(`ALTER TABLE \`${table}\` ADD CONSTRAINT \`e2e_refuse\` CHECK (\`i_zip\` IS NULL OR \`i_zip\` <> '${refused}');`);
        const e = entry(ctx, { i_zip: refused });
        try {
            const failed = await post(page, ctx.syncUrl, e);
            expect(failed.status, JSON.stringify(failed.body)).toBe(500);
            expect(ledger(e.uuid), 'the failed entry is not recorded as applied').toHaveLength(0);
        } finally {
            dbExecRaw(`ALTER TABLE \`${table}\` DROP CONSTRAINT IF EXISTS \`e2e_refuse\`;`);
        }
        const resent = await post(page, ctx.syncUrl, e);
        expect(resent.body.status).toBe('ok');
        expect(resent.body.already_applied).toBeUndefined();
        expect(answer(ctx.unitSessionId, 'i_zip')).toBe(refused);
    });

    test('a malformed envelope is refused with 400 naming the field; a missing client_ts is fine', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        for (const [field, extra] of [['uuid', { uuid: 'not-a-uuid' }], ['page', { page: 0 }], ['client_ts', { client_ts: '2026-09-27T10:00:00.000Z' }]]) {
            const res = await post(page, ctx.syncUrl, entry(ctx, { i_zip: '11111' }, extra));
            expect(res.status, field).toBe(400);
            expect(res.body.field).toBe(field);
        }
        const e = entry(ctx, { i_zip: '22222' });
        delete e.client_ts;
        const ok = await post(page, ctx.syncUrl, e);
        expect(ok.body.status).toBe('ok');
        expect(ledger(e.uuid)[0].client_ts).toBeNull();
    });
});

test.describe('the v2 write gate refuses what it must', () => {
    test.skip(RUNNING_ON_BS, 'drives the server directly');

    test('a page rendered for another unit session is refused as stale', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        const other = ctx.unitSessionId + 100000;
        const submit = await post(page, ctx.submitUrl, { page: ctx.pageNum, unit_session_id: other, data: { i_zip: '12345' } });
        expect(submit.status).toBe(409);
        expect(submit.body.stale_session).toBe(true);
        // The queue's endpoint says the same, and lets the entry go.
        const sync = await post(page, ctx.syncUrl, entry(ctx, { i_zip: '12345' }, { unit_session_id: other }));
        expect(sync.status).toBe(409);
        expect(sync.body).toMatchObject({ stale_session: true, drop_entry: true });
        expect(answer(ctx.unitSessionId, 'i_zip') || null, 'nothing stored').toBeNull();
    });

    test('a write without a run session is refused with 403', async ({ page, baseURL, playwright }) => {
        const ctx = await start(page, baseURL);
        const anon = await playwright.request.newContext({ baseURL, ignoreHTTPSErrors: true });
        try {
            const res = await anon.post(ctx.submitUrl, { data: { page: ctx.pageNum, unit_session_id: ctx.unitSessionId, data: { i_zip: '12345' } } });
            expect(res.status()).toBe(403);
            expect((await res.json()).error).toMatch(/run session/i);
        } finally {
            await anon.dispose();
        }
        expect(answer(ctx.unitSessionId, 'i_zip') || null).toBeNull();
    });

    test('R-call requests over the per-minute limit are refused with 429', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        const statuses = [];
        for (let i = 0; i < 40 && !statuses.includes(429); i++) {
            statuses.push((await post(page, ctx.renderUrl, { page: 1, unit_session_id: ctx.unitSessionId })).status);
        }
        expect(statuses, 'the limit is 30 per minute (form_v2_rate_limits.rcall_per_min)').toContain(429);
        expect(statuses.filter((s) => s === 200).length).toBeLessThanOrEqual(30);
    });

    test('a write to a run whose current unit is not a v2 Form is refused with 409', async ({ page, baseURL }) => {
        const v1Run = runName('pwa_low', 'v1');
        await freshParticipant(page, v1Run, { baseURL });
        const res = await post(page, `${baseURL}/${v1Run}/form-page-submit/`, { page: 1, data: {} });
        expect(res.status).toBe(409);
        expect(res.body.error).toMatch(/not a v2 form/i);
    });
});

test.describe('write ordering and access', () => {
    test.skip(RUNNING_ON_BS, 'drives the server directly');

    test('an autosave started before a page submit of the same document is ignored', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        const doc = crypto.randomUUID();
        const submit = await post(page, ctx.submitUrl, { page: ctx.pageNum, unit_session_id: ctx.unitSessionId,
            data: { i_mc_req: '1', i_zip: '22222' }, doc, seq: 5 });
        expect(submit.body.status).toBe('ok');
        // The late autosave (numbered 3, sent before the submit) lands now.
        const stale = await post(page, ctx.saveUrl, { page: ctx.pageNum, unit_session_id: ctx.unitSessionId,
            data: { i_zip: '11111' }, doc, seq: 3 });
        expect(stale.body.status).toBe('stale');
        expect(answer(ctx.unitSessionId, 'i_zip')).toBe('22222');
        // A later autosave of the same document, or one from another tab, is stored.
        const later = await post(page, ctx.saveUrl, { page: ctx.pageNum, unit_session_id: ctx.unitSessionId,
            data: { i_zip: '33333' }, doc, seq: 6 });
        expect(later.body.status).toBe('saved');
        expect(answer(ctx.unitSessionId, 'i_zip')).toBe('33333');
    });

    test('a run closed while a form is open takes no more answers from it', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        const before = dbQuery(`SELECT public FROM survey_runs WHERE name = '${RUN()}';`)[0].public;
        dbExecRaw(`UPDATE survey_runs SET public = 0 WHERE name = '${RUN()}';`);
        try {
            const res = await post(page, ctx.submitUrl, { page: ctx.pageNum, unit_session_id: ctx.unitSessionId, data: { i_zip: '44444' } });
            expect(res.status).toBe(403);
            expect(res.body.closed).toBe(true);
            expect(answer(ctx.unitSessionId, 'i_zip') || null).toBeNull();
        } finally {
            dbExecRaw(`UPDATE survey_runs SET public = ${Number(before)} WHERE name = '${RUN()}';`);
        }
    });
});
