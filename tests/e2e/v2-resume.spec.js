// form_v2: the browser owns "where is the participant" (state.mjs pageState),
// so a reload, an online submit and an offline submit all land on the same
// page, with the same items and the same progress.
//
// Fixture: the cross-page showif run (two pages, every item optional):
//   page 1: cp_intro (note), cp_a (text)
//   page 2: cp_b (text), cp_followup (text, showif cp_a == 1 && cp_b == 3)
// allow_previous is switched on for this file through the DB (local only —
// the BrowserStack lane has no DB access) and restored afterwards.

const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const db = require('./helpers/db');

const RUN = () => runName('crosspage_showif', 'v2');
const STUDY = 'e2e_crosspage_showif_v2';

test.describe('form_v2: reload, offline and Previous agree on the page', () => {
    test.skip(RUNNING_ON_BS, 'toggles allow_previous through the DB; local lane only');

    test.beforeAll(() => { db.dbExecRaw(`UPDATE survey_studies SET allow_previous = 1 WHERE name = '${STUDY}'`); });
    test.afterAll(() => { db.dbExecRaw(`UPDATE survey_studies SET allow_previous = 0 WHERE name = '${STUDY}'`); });

    async function progressLabel(page) {
        return page.locator('form.fmr-form-v2 [data-fmr-progress-label]').textContent();
    }
    async function reload(page) {
        await page.reload();
        await v2.waitForBundle(page);
    }

    test('a half-filled page comes back as it was', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        expect(await v2.visiblePageNum(page)).toBe(1);
        expect(await progressLabel(page)).toContain('1 of 2');
        // The first change autosaves at once (the throttle window starts empty).
        const saved = page.waitForResponse((r) => /\/form-save\/?$/.test(new URL(r.url()).pathname));
        await page.fill('[name="cp_a"]', '1');
        await page.locator('[name="cp_a"]').blur();
        await saved;
        await reload(page);
        expect(await v2.visiblePageNum(page), 'still page 1').toBe(1);
        expect(await page.inputValue('[name="cp_a"]'), 'the autosaved answer is in its input').toBe('1');
        expect(await progressLabel(page)).toContain('1 of 2');
        expect(await page.locator('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-prev]:visible').count(), 'no Previous on the first page').toBe(0);
    });

    test('a submitted page stays behind: remembered, and without memory too', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        await page.fill('[name="cp_a"]', '1');
        const r = await v2.submitV2(page);
        expect(v2.advanced(r.body), JSON.stringify(r.body)).toBeTruthy();
        expect(await v2.visiblePageNum(page)).toBe(2);
        expect(await progressLabel(page)).toContain('2 of 2');

        await reload(page);
        expect(await v2.visiblePageNum(page), 'the remembered page').toBe(2);
        expect(await progressLabel(page)).toContain('2 of 2');

        // Another browser: no memory. The first page with an unanswered
        // answerable item is page 2 (page 1's only input is answered).
        await page.evaluate(() => { try { localStorage.clear(); } catch (e) { /* noop */ } });
        await reload(page);
        expect(await v2.visiblePageNum(page), 'fallback rule').toBe(2);

        // Previous is offered here and reaches page 1 with its answer.
        expect(await v2.allowsPrevious(page)).toBe(true);
        await v2.goPrevious(page);
        expect(await v2.visiblePageNum(page)).toBe(1);
        expect(await page.inputValue('[name="cp_a"]')).toBe('1');
        expect(page.url()).toMatch(/[?&]page=1/);
        await reload(page);
        expect(await v2.visiblePageNum(page), 'the page Previous went to is remembered').toBe(1);
    });

    test('an offline submit moves on like an online one and survives a reload', async ({ page, baseURL, context }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        await page.fill('[name="cp_a"]', '1');
        await context.setOffline(true);
        await page.locator('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]').first().click();
        await expect(page.locator('.fmr-queue-banner')).toBeVisible();
        expect(await v2.visiblePageNum(page), 'advanced locally').toBe(2);
        expect(await progressLabel(page)).toContain('2 of 2');

        const synced = page.waitForResponse((r) => /\/form-sync\/?$/.test(new URL(r.url()).pathname) && r.status() === 200);
        await context.setOffline(false);
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        const body = await (await synced).json();
        expect(body.status, JSON.stringify(body)).toBe('ok');
        expect(body.items && body.items.cp_a && body.items.cp_a.value).toBe('1');

        await reload(page);
        expect(await v2.visiblePageNum(page)).toBe(2);
        await v2.goPrevious(page);
        expect(await page.inputValue('[name="cp_a"]'), 'the queued answer was stored').toBe('1');
    });
});

// The showcase (documentation/example_surveys/all_widgets_v2.json): items
// whose inputs always hold a value (range) are
// restored by the server rendering the stored answer, not by the browser.
const SHOWCASE = () => runName('all_widgets_v2', 'v2');

test.describe('form_v2: stored answers come back through the items themselves', () => {
    test('a slider keeps its stored answer across a reload, and loading autosaves nothing', async ({ page, baseURL }) => {
        await freshParticipant(page, SHOWCASE(), { baseURL });
        await v2.waitForBundle(page);
        const saved = page.waitForResponse((r) => /\/form-save\/?$/.test(new URL(r.url()).pathname));
        await page.evaluate(() => {
            const r = document.querySelector('[name="n_range"]');
            r.value = '80';
            r.dispatchEvent(new Event('input', { bubbles: true }));
            r.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await saved;

        const saves = [];
        page.on('request', (r) => { if (/\/form-save\/?$/.test(new URL(r.url()).pathname)) saves.push(r.url()); });
        await page.reload();
        await v2.waitForBundle(page);
        expect(await page.inputValue('[name="n_range"]'), 'the stored 80, not the midpoint').toBe('80');
        await page.waitForTimeout(1500);
        expect(saves, 'restoring the page is not an answer').toEqual([]);
    });

    test('the state block keeps no-input values out of the page unless a showif reads them', async ({ page, baseURL }) => {
        await freshParticipant(page, SHOWCASE(), { baseURL });
        await v2.waitForBundle(page);
        const items = await page.evaluate(() => JSON.parse(document.querySelector('script.fmr-state').textContent).items);
        expect(items.r_group.answered, 'the random item was drawn').toBe(true);
        expect(items.r_group.value, 'a showif reads r_group, so it ships').not.toBeNull();
        expect(items.a_ip.answered).toBe(true);
        expect(items.a_ip.value, 'no showif reads the IP address').toBeNull();
        expect(items.a_browser.value).toBeNull();
    });
});

// A server error (not an outage) queues a page; a later page must never
// overtake it. The live submit of page 2 first tries to send page 1 and,
// when that still fails, queues itself behind it; once the server is back
// both land in order and the form completes.
test.describe('form_v2: the queue keeps pages in order', () => {
    test.skip(RUNNING_ON_BS, 'page.route is not available through the BrowserStack bridge');

    test('a page after a failed one queues behind it, and both land in order', async ({ page, baseURL }) => {
        test.setTimeout(120000);
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        const sid = await v2.unitSessionId(page);
        const livePages = [];
        const syncedPages = [];
        page.on('request', (r) => {
            const p = new URL(r.url()).pathname;
            let body = null;
            try { body = JSON.parse(r.postData() || 'null'); } catch (e) { /* multipart */ }
            if (/\/form-page-submit\/?$/.test(p) && body) livePages.push(body.page);
            if (/\/form-sync\/?$/.test(p) && body) syncedPages.push(body.page);
        });

        // Page 1: the server answers 500 — queued, and the participant moves on.
        await page.route(/\/form-page-submit\/?$/, (route) => route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"boom"}' }), { times: 1 });
        await page.route(/\/form-sync\/?$/, (route) => route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"still down"}' }));
        await page.fill('[name="cp_a"]', '1');
        await page.locator('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]').first().click();
        await expect.poll(() => v2.visiblePageNum(page)).toBe(2);

        // Page 2 (the last): page 1 still cannot be sent, so page 2 is not
        // sent live either — it queues behind page 1.
        await page.fill('[name="cp_b"]', '2');
        await page.locator('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]').first().click();
        await expect(page.locator('.fmr-queue-banner')).toContainText(/queued behind/i);
        expect(livePages, 'page 2 never went out live').toEqual([1]);
        const queued = await page.evaluate(() => new Promise((resolve) => {
            const open = indexedDB.open('formrQueue');
            open.onsuccess = () => {
                const req = open.result.transaction('queue').objectStore('queue').getAll();
                req.onsuccess = () => resolve(req.result.map((e) => ({ page: e.page, final: !!e.final })).sort((a, b) => a.page - b.page));
            };
        }));
        expect(queued).toEqual([{ page: 1, final: false }, { page: 2, final: true }]);

        // The server is back. Clear the backoff and drain.
        await page.unroute(/\/form-sync\/?$/);
        syncedPages.length = 0;
        await page.evaluate(() => new Promise((resolve) => {
            const open = indexedDB.open('formrQueue');
            open.onsuccess = () => {
                const store = open.result.transaction('queue', 'readwrite').objectStore('queue');
                const req = store.getAll();
                req.onsuccess = () => {
                    req.result.forEach((e) => { e.next_retry_at = null; e.attempts = 0; e.state = 'pending'; store.put(e); });
                    store.transaction.oncomplete = () => resolve();
                };
            };
        }));
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await expect.poll(() => syncedPages.slice(), { timeout: 30000 }).toEqual([1, 2]);
        // The final page's redirect takes the participant past the form.
        await expect.poll(() => page.locator('form.fmr-form-v2').count().catch(() => 1), { timeout: 20000 }).toBe(0);

        const st = db.dbQuery(`SELECT results_table FROM survey_studies WHERE name = '${STUDY}'`)[0];
        const row = db.dbResultsRow(st.results_table, sid);
        expect(row.cp_a, 'page 1 landed').toBe('1');
        expect(row.cp_b, 'page 2 landed').toBe('2');
        const us = db.dbQuery(`SELECT ended FROM survey_unit_sessions WHERE id = ${Number(sid)}`)[0];
        expect(us.ended, 'the final page, drained last, ended the form').not.toBeNull();
    });
});
