// A bot_check's token is one-shot. A page that carries one and was stored,
// but whose reply was lost (or that failed after storing — the final page's
// run-session lock), is sent again by the queue with the same token. The
// resend must not be refused as unverified: the check passed in this unit
// session. (Correctness review 2026-09-28, server F1.)
//
// Fixture: the showcase (run e2e-awv2); its last page (5) carries u_human.

const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const { dbQuery } = require('./helpers/db');
const v2 = require('./helpers/v2Form');
const { mysqlNow } = require('./helpers/idbQueue');
const { solveBotCheckIfPresent } = require('./helpers/v2Complete');

const RUN = () => runName('all_widgets_v2', 'v2');

test('a stored page with a passed bot_check can be sent again', async ({ page, baseURL }) => {
    test.skip(RUNNING_ON_BS, 'drives the server directly');
    await freshParticipant(page, RUN(), { baseURL });
    await v2.waitForBundle(page);
    const usid = await v2.unitSessionId(page);
    await page.evaluate((id) => window.localStorage.setItem(`fmr:page:${id}`, '5'), usid);
    // The URL names the landing page (?page=1), which wins over the
    // remembered one: open the run URL itself.
    await page.goto(page.url().replace(/[?&]page=\d+/, '').replace(/\?$/, ''), { waitUntil: 'domcontentloaded' });
    await v2.waitForBundle(page);
    await expect.poll(() => v2.visiblePageNum(page), { timeout: 10000 }).toBe(5);
    expect(await solveBotCheckIfPresent(page, 'form.fmr-form-v2 section.fmr-page:not([hidden])')).toBe(true);

    const [req] = await Promise.all([
        page.waitForRequest((r) => /\/form-page-submit\/?$/.test(new URL(r.url()).pathname), { timeout: 15000 }),
        page.locator('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]').click(),
    ]);
    const payload = JSON.parse(req.postData() || '{}');
    expect(payload.data && payload.data.u_human, 'the live submit carried the solved token').toBeTruthy();
    await expect.poll(() => (dbQuery(`SELECT d.answer FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id `
        + `WHERE d.session_id = ${Number(usid)} AND i.name = 'u_human';`)[0] || {}).answer, { timeout: 15000 }).toBe('verified');

    // The queue's copy of the same page: same token, a new uuid.
    const syncUrl = await page.evaluate(() => document.querySelector('form.fmr-form-v2').dataset.syncUrl);
    const res = await page.request.post(syncUrl, {
        data: Object.assign({}, payload, { uuid: crypto.randomUUID(), client_ts: mysqlNow() }),
        headers: { Accept: 'application/json' },
    });
    const body = await res.json();
    expect(res.status()).toBe(200);
    expect(body.status, JSON.stringify(body.errors || {})).toBe('ok');
});

// A bot_check the server refuses (a spent or expired token) is put back to
// unverified, so the participant can verify again instead of a widget that
// shows "verified" and is refused every time.
test('a refused bot_check resets its widget', async ({ page, baseURL }) => {
    test.skip(RUNNING_ON_BS, 'page.route is not available through the BrowserStack bridge');
    await freshParticipant(page, RUN(), { baseURL });
    await v2.waitForBundle(page);
    const usid = await v2.unitSessionId(page);
    await page.evaluate((id) => window.localStorage.setItem(`fmr:page:${id}`, '5'), usid);
    // The URL names the landing page (?page=1), which wins over the
    // remembered one: open the run URL itself.
    await page.goto(page.url().replace(/[?&]page=\d+/, '').replace(/\?$/, ''), { waitUntil: 'domcontentloaded' });
    await v2.waitForBundle(page);
    await expect.poll(() => v2.visiblePageNum(page), { timeout: 10000 }).toBe(5);
    const scope = 'form.fmr-form-v2 section.fmr-page:not([hidden])';
    expect(await solveBotCheckIfPresent(page, scope)).toBe(true);
    const state = () => page.evaluate((sel) => document.querySelector(`${sel} altcha-widget .altcha`)?.getAttribute('data-state'), scope);
    expect(await state()).toBe('verified');

    await page.route(/\/form-page-submit\/?(\?.*)?$/, (route) => route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ status: 'errors', errors: { u_human: 'Please verify that you are human to continue.' } }),
    }));
    const pageErrors = [];
    page.on('pageerror', (e) => pageErrors.push(e.message));
    await page.locator(`${scope} [data-fmr-next]`).click();
    await expect.poll(state, { timeout: 10000 }).not.toBe('verified');
    // The refusal is shown on the page (placing it used to throw on the widget).
    await expect(page.locator(`${scope} .fmr-invalid-feedback`, { hasText: 'verify that you are human' })).toBeVisible();
    expect(pageErrors).toEqual([]);
});
