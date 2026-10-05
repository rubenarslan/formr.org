// One v2 form open in two tabs (FormSubmissionService::claimWrite).
//
// A page submit records which items the form's showifs hide and clears
// their answers; a tab rendered before answers were given in another tab
// would hide, and clear, those. So a write from a tab that has not seen the
// latest write of its form is refused (409 stale_document) and the tab
// reloads; a page from the offline queue, whose tab may be long gone, is
// stored without its hidden list. Everything is created here and deleted in
// afterAll: one study, one run.

const { request } = require('@playwright/test');
const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { freshParticipant, acceptCookieConsent } = require('./helpers/participant');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');
const { provisioner } = require('./helpers/provision');
const { dbQuery } = require('./helpers/db');
const { currentFormContext, mysqlNow } = require('./helpers/idbQueue');
const v2 = require('./helpers/v2Form');

const SFX = Date.now().toString(36);
const NAME = `e2e_staletab_${SFX}`;
const RUN = `e2e-staletab-${SFX}`;
const ITEMS = [
    { type: 'text', name: 'st_a', label: 'A', optional: 1 },
    { type: 'submit', name: 'st_s1', label: 'Next' },
    { type: 'text', name: 'st_b', label: 'B', optional: 1 },
    { type: 'submit', name: 'st_s2', label: 'Next' },
    { type: 'text', name: 'st_c', label: 'C', optional: 1, showif: "st_b == 'yes'" },
    { type: 'submit', name: 'st_s3', label: 'Next' },
    { type: 'text', name: 'st_d', label: 'D', optional: 1 },
    { type: 'submit', name: 'st_s4', label: 'Done' },
];
const NEXT = 'form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]';

const display = (usid, name) => {
    const rows = dbQuery(`SELECT d.answer, d.hidden FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id `
        + `WHERE d.session_id = ${Number(usid)} AND i.name = '${name}';`);
    return rows.length ? rows[0] : {};
};
const itemId = (name) => Number(dbQuery(`SELECT i.id FROM survey_items i JOIN survey_studies s ON s.id = i.study_id `
    + `WHERE s.name = '${NAME}' AND i.name = '${name}';`)[0].id);

// Answer the visible page's text field and submit it, as the participant.
const answerPage = async (page, name, value) => {
    await page.fill(`[name="${name}"]`, value);
    const { status, body } = await v2.submitV2(page);
    expect(status, JSON.stringify(body)).toBe(200);
    expect(body.status).toBe('ok');
};

test.describe('a v2 form open in two tabs', () => {
    test.skip(RUNNING_ON_BS, 'provisions through the admin; opens a second tab');
    const prov = provisioner();
    let admin;

    test.beforeAll(async ({ browser }) => {
        test.setTimeout(120000);
        await ensureAdminState(browser);
        admin = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
        const study = await prov.uploadStudy(admin, { engine: 'v2', json: { name: NAME, settings: { offline_mode: 1 }, items: ITEMS } });
        await prov.createRun(admin, RUN, [{ type: 'Survey', studyId: study.id }, { type: 'Page', body: `END_${SFX}` }]);
    });

    test.afterAll(async () => {
        if (admin) {
            await prov.cleanup(admin);
            await admin.dispose();
        }
    });

    test('the stale tab is refused and reloads; what the other tab saved stays', async ({ page, baseURL }) => {
        const { url } = await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const stale = await currentFormContext(page);

        // The same participant in a second tab: B = yes reveals C, answered.
        const other = await page.context().newPage();
        await other.goto(url, { waitUntil: 'commit' });
        await acceptCookieConsent(other);
        await v2.waitForBundle(other);
        await answerPage(other, 'st_a', 'from the other tab');
        await answerPage(other, 'st_b', 'yes');
        await answerPage(other, 'st_c', 'answered in the other tab');
        const usid = stale.unitSessionId;
        expect(display(usid, 'st_c')).toEqual({ answer: 'answered in the other tab', hidden: '0' });

        // The first tab still shows B empty, so its form hides C. Its write
        // is refused, and nothing it would have written lands.
        const res = await page.request.post(stale.runUrl.replace(/\/?$/, '/form-page-submit'), {
            headers: { Accept: 'application/json' },
            data: { page: 1, unit_session_id: usid, data: { st_a: 'from the stale tab' }, item_views: {},
                hidden: [itemId('st_c')], final: false, doc: 'a-stale-document', base: 0, seq: 1 },
        });
        expect(res.status()).toBe(409);
        expect((await res.json()).stale_document).toBe(true);
        const save = await page.request.post(stale.runUrl.replace(/\/?$/, '/form-save'), {
            headers: { Accept: 'application/json' },
            data: { unit_session_id: usid, data: { st_b: '' }, item_views: {}, doc: 'a-stale-document', base: 0, seq: 2 },
        });
        expect(save.status(), 'an autosave would withdraw B').toBe(409);
        expect(display(usid, 'st_a').answer).toBe('from the other tab');
        expect(display(usid, 'st_b').answer).toBe('yes');
        expect(display(usid, 'st_c')).toEqual({ answer: 'answered in the other tab', hidden: '0' });

        // The participant goes on in the first tab: told, and reloaded.
        const dialogs = [];
        page.on('dialog', async (d) => { dialogs.push(d.message()); await d.accept(); });
        await page.fill('[name="st_a"]', 'typed in the stale tab');
        const reloaded = page.waitForEvent('load', { timeout: 20000 });
        await page.locator(NEXT).first().click();
        await reloaded;
        expect(dialogs.join(' | ')).toContain('another tab or window');
        await v2.waitForBundle(page);
        expect(display(usid, 'st_a').answer).toBe('from the other tab');
        expect(display(usid, 'st_c')).toEqual({ answer: 'answered in the other tab', hidden: '0' });

        // Reloaded, it is current: it shows what was saved, and can write.
        expect(await page.inputValue('[name="st_c"]')).toBe('answered in the other tab');
        expect(await v2.visiblePageNum(page)).toBe(4);
        await answerPage(page, 'st_d', 'after the reload');
        expect(display(usid, 'st_d').answer).toBe('after the reload');
        await other.close();
    });

    test('a stale tab reloads even with an answer waiting to be autosaved', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        // The first autosave is answered "stale" only once a second change
        // is waiting for its own (the guard against leaving is then armed).
        let release;
        const held = new Promise((r) => { release = r; });
        await page.route((u) => /\/form-save\/?$/.test(u.pathname), async (route) => {
            await held;
            await route.fulfill({ status: 409, contentType: 'application/json',
                body: JSON.stringify({ error: 'stale', stale_document: true }) });
        });
        const field = page.locator('[name="st_a"]');
        // A real click: browsers ask before leaving only a page the
        // participant has interacted with.
        await field.click();
        await field.fill('first');
        await field.dispatchEvent('change');
        await field.fill('second');
        await field.dispatchEvent('change');
        // The alert is acknowledged; a "leave site?" prompt is answered
        // Stay, as a participant may.
        const prompts = [];
        page.on('dialog', async (d) => { prompts.push(d.type()); await (d.type() === 'beforeunload' ? d.dismiss() : d.accept()); });
        const reloaded = page.waitForEvent('load', { timeout: 15000 });
        release();
        await reloaded;
        expect(prompts).not.toContain('beforeunload');
    });

    test('a change made while its page is being sent is saved too', async ({ page, baseURL }) => {
        // The change waits out the autosave's 20 s throttle.
        test.setTimeout(90000);
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const usid = (await currentFormContext(page)).unitSessionId;
        let release;
        const held = new Promise((r) => { release = r; });
        await page.route((u) => /\/form-page-submit\/?$/.test(u.pathname), async (route) => { await held; await route.continue(); });
        const field = page.locator('[name="st_a"]');
        await field.fill('sent');
        await page.locator(NEXT).first().click();
        // While the page is on the wire, the participant changes the answer.
        await page.waitForTimeout(500);
        await field.fill('changed');
        // Committed now (blur fires its change), not when the page changes.
        await field.blur();
        release();
        await expect.poll(() => display(usid, 'st_a').answer, { timeout: 45000 }).toBe('changed');
    });

    test("a reloaded page is not stale for what its predecessor still sent", async ({ page, baseURL }) => {
        await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const ctx = await currentFormContext(page);
        const usid = ctx.unitSessionId;
        const tabDocs = () => page.evaluate((k) => JSON.parse(sessionStorage.getItem(k) || '[]'), `fmr-docs-${usid}`);
        const [before] = await tabDocs();
        await answerPage(page, 'st_a', 'first');

        await page.reload({ waitUntil: 'commit' });
        await v2.waitForBundle(page);
        const docs = await tabDocs();
        expect(docs[1], 'the tab remembers the page it showed before').toBe(before);
        // What the page before still had on the wire lands now, after this
        // one was rendered: the autosave it sent on pagehide.
        const late = await page.request.post(ctx.runUrl.replace(/\/?$/, '/form-save'), {
            headers: { Accept: 'application/json' },
            data: { unit_session_id: usid, data: { st_b: 'sent on pagehide' }, item_views: {}, doc: before, base: 0, seq: 9 },
        });
        expect(late.status()).toBe(200);
        expect(display(usid, 'st_b').answer).toBe('sent on pagehide');
        await answerPage(page, 'st_b', 'yes');
        expect(display(usid, 'st_b').answer).toBe('yes');
    });

    test("a queued page from a stale tab is stored, but not its hidden list", async ({ page, baseURL }) => {
        const { url } = await freshParticipant(page, RUN, { baseURL });
        await v2.waitForBundle(page);
        const stale = await currentFormContext(page);
        const usid = stale.unitSessionId;
        const other = await page.context().newPage();
        await other.goto(url, { waitUntil: 'commit' });
        await acceptCookieConsent(other);
        await v2.waitForBundle(other);
        await answerPage(other, 'st_a', 'from the other tab');
        await answerPage(other, 'st_b', 'yes');
        await answerPage(other, 'st_c', 'answered in the other tab');
        await other.close();

        // The first tab's page 1, queued offline before the other tab wrote.
        const res = await page.request.post(stale.syncUrl, { headers: { Accept: 'application/json' }, data: {
            uuid: crypto.randomUUID(), page: 1, unit_session_id: usid, client_ts: mysqlNow(),
            data: { st_a: 'queued in the stale tab' }, item_views: {}, hidden: [itemId('st_c')], final: false,
            doc: 'a-stale-document', base: 0, seq: 1,
        } });
        const body = await res.json();
        expect(res.status(), JSON.stringify(body)).toBe(200);
        expect(body.status).toBe('ok');
        expect(body.stale).toBe(true);
        expect(display(usid, 'st_a').answer).toBe('queued in the stale tab');
        expect(display(usid, 'st_c')).toEqual({ answer: 'answered in the other tab', hidden: '0' });
    });
});
