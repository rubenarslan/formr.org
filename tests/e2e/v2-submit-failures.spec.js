// form_v2: what the browser does when a live page submit does not simply
// succeed (coverage pass, documentation/agent_doc/coverage_pass.md gaps
// 3–4). None of these paths ran in any test:
//   - 409 stale_session → says so, reloads;
//   - 403 (run session gone) → says so, reloads;
//   - 409 otherwise (the form is no longer current) → the run URL;
//   - a 200 that isn't JSON → says the answer could not be saved, stays;
//   - 200 {status:'errors'} → the server's messages on the page, stays;
//   - any other error status → a generic message, stays;
//   - a final submit answered `missing` → the page of the first missing
//     item, with its message.
// /form-page-submit answers are mocked (page.route); the fixture is run
// e2e-inputs-v2 (page 1: i_mc, i_mc_req required, i_btn, i_zip, i_email;
// page 2: a note). Skipped on BrowserStack: page.route is unavailable.

const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');

const RUN = () => runName('v2_inputs', 'v2');
const SUBMIT_ROUTE = /\/form-page-submit\/?(\?.*)?$/;
const VISIBLE_PAGE = 'form.fmr-form-v2 section.fmr-page:not([hidden])';

// Open the form with ?e2e=1: a reload keeps the parameter, a move to the
// run URL drops it — so the two outcomes can be told apart. (The page adds
// its own ?code= to the URL, so only this parameter is looked at.)
async function start(page, baseURL) {
    await freshParticipant(page, RUN(), { baseURL });
    await v2.waitForBundle(page);
    await page.goto(page.url().replace(/\?.*$/, '') + '?e2e=1', { waitUntil: 'domcontentloaded' });
    await v2.waitForBundle(page);
    await page.locator(`${VISIBLE_PAGE} input[name="i_mc_req"][type=radio]`).first().check({ force: true });
}

const marked = (url) => new URL(url).searchParams.get('e2e') === '1';
const reply = (status, body) => ({ status, contentType: 'application/json', body: typeof body === 'string' ? body : JSON.stringify(body) });

async function mockSubmit(page, response) {
    await page.route(SUBMIT_ROUTE, (route) => route.fulfill(response));
}

// Click Next; resolve with the alert's text (or null) once the click has
// played out. Accepts the alert.
async function submitAndCollectAlert(page) {
    let message = null;
    page.once('dialog', (d) => { message = d.message(); d.accept().catch(() => {}); });
    await page.locator(`${VISIBLE_PAGE} [data-fmr-next]`).click();
    await page.waitForTimeout(1500);
    return message;
}

test.describe('a live page submit that does not succeed', () => {
    test.skip(RUNNING_ON_BS, 'page.route is not available through the BrowserStack Selenium bridge');

    for (const [label, response, text] of [
        ['409 stale_session', reply(409, { error: 'stale', stale_session: true }), /continued elsewhere/i],
        ['403', reply(403, { error: 'No active run session' }), /session has expired/i],
    ]) {
        test(`${label}: tells the participant and reloads`, async ({ page, baseURL }) => {
            await start(page, baseURL);
            await mockSubmit(page, response);
            const loads = [];
            page.on('load', () => loads.push(page.url()));
            const message = await submitAndCollectAlert(page);
            expect(message).toMatch(text);
            await expect.poll(() => loads.length, { timeout: 10000 }).toBeGreaterThanOrEqual(1);
            expect(marked(page.url()), 'a reload keeps the URL').toBe(true);
        });
    }

    test('409 without stale_session: goes to the run URL', async ({ page, baseURL }) => {
        await start(page, baseURL);
        await mockSubmit(page, reply(409, { error: 'No current unit session' }));
        await Promise.all([
            page.waitForURL((u) => !marked(u.toString()), { timeout: 10000 }),
            page.locator(`${VISIBLE_PAGE} [data-fmr-next]`).click(),
        ]);
        expect(new URL(page.url()).pathname).toBe(`/${RUN()}/`);
    });

    test('a 200 that is not JSON: says the answer was not saved and stays on the page', async ({ page, baseURL }) => {
        await start(page, baseURL);
        await mockSubmit(page, { status: 200, contentType: 'text/html', body: '<html>proxy page</html>' });
        const message = await submitAndCollectAlert(page);
        expect(message).toMatch(/could not be saved/i);
        expect(await v2.visiblePageNum(page)).toBe(1);
        expect(marked(page.url()), 'no navigation').toBe(true);
    });

    test('200 with errors: shows the server\'s messages on the page and stays', async ({ page, baseURL }) => {
        await start(page, baseURL);
        await mockSubmit(page, reply(200, { status: 'errors', errors: { i_email: 'Server refused this address (e2e)' } }));
        const message = await submitAndCollectAlert(page);
        expect(message).toBeNull();
        await expect(page.locator(`${VISIBLE_PAGE} .fmr-invalid-feedback`, { hasText: 'Server refused this address (e2e)' })).toBeVisible();
        expect(await v2.visiblePageNum(page)).toBe(1);
    });

    test('another error status: a generic message, stays on the page', async ({ page, baseURL }) => {
        await start(page, baseURL);
        await mockSubmit(page, reply(400, { error: 'Invalid JSON body' }));
        const message = await submitAndCollectAlert(page);
        expect(message).toMatch(/something went wrong/i);
        expect(await v2.visiblePageNum(page)).toBe(1);
    });

    test('a final submit answered `missing` opens the page of the missing item, with its message', async ({ page, baseURL }) => {
        await start(page, baseURL);
        const first = await v2.submitV2(page, { timeout: 15000 });
        expect(first.status, 'the real page-1 submit').toBe(200);
        await expect.poll(() => v2.visiblePageNum(page), { timeout: 10000 }).toBe(2);
        await mockSubmit(page, reply(200, { status: 'ok', items: {}, missing: { i_zip: 'Please give a zip code (e2e)' } }));
        await page.locator(`${VISIBLE_PAGE} [data-fmr-next]`).click();
        await expect.poll(() => v2.visiblePageNum(page), { timeout: 10000 }).toBe(1);
        await expect(page.locator(`${VISIBLE_PAGE} .fmr-invalid-feedback`, { hasText: 'Please give a zip code (e2e)' })).toBeVisible();
    });
});
