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
