// form_v2 offline-queue drain: order, scope and lifecycle, local Chromium.
//
// Each test seeds entries straight into IndexedDB and mocks /form-sync
// (helpers/idbQueue.js), so the server's answer is deterministic. Written
// against breakage found in review (2026-09-27):
//   - a final page queued before a re-queued earlier page drained first,
//     ended the form, and the earlier page was dropped;
//   - a drop (409) of this page's own unit session left the participant on
//     a dead form;
//   - an entry from an earlier unit session of the run blocked the current
//     one;
//   - a live submit started while a drain was running skipped the drain and
//     overtook the queued page;
//   - the drain before a live submit posted every study's entries on the
//     origin;
//   - turning the study's offline mode off stranded what was already queued;
//   - a logout click could navigate away mid-wipe.
// Skipped on BrowserStack: page.route isn't available through its bridge.

const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const { dbExecRaw } = require('./helpers/db');
const { fillAllVisible } = require('./helpers/widgets');
const v2 = require('./helpers/v2Form');
const {
    SYNC_ROUTE, isSync, IDB_GET_ALL, IDB_PUT, IDB_CLEAR, currentFormContext, entryFor, seedEntry,
} = require('./helpers/idbQueue');

const RUN = () => runName('pwa_low', 'v2');
const OK = { status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'ok', items: {} }) };
const SUBMIT_RE = /\/form-page-submit\/?$/;

// Record the uuid of every /form-sync POST and answer with `answer(body)`
// (default: applied).
async function mockSync(page, answer = () => OK) {
    const posted = [];
    await page.route(SYNC_ROUTE, async (route) => {
        const body = JSON.parse(route.request().postData() || '{}');
        posted.push(body.uuid);
        await route.fulfill(await answer(body));
    });
    return posted;
}

async function start(page, baseURL) {
    await freshParticipant(page, RUN(), { baseURL });
    await v2.waitForBundle(page);
    const ctx = await currentFormContext(page);
    expect(ctx.syncUrl, 'fixture renders data-sync-url').toMatch(/\/form-sync\/?$/);
    return ctx;
}

// Reload so the load-time drain runs over the seeded entries.
async function reloadAndDrain(page) {
    await Promise.all([
        page.waitForResponse((r) => isSync(r.url()), { timeout: 20000 }),
        page.reload({ waitUntil: 'commit' }),
    ]);
    await v2.waitForBundle(page);
}

const setOfflineMode = (on) => dbExecRaw(
    'UPDATE survey_studies s JOIN survey_units u ON u.form_study_id = s.id '
    + 'JOIN survey_run_units ru ON ru.unit_id = u.id JOIN survey_runs r ON r.id = ru.run_id '
    + `SET s.offline_mode = ${on ? 1 : 0} WHERE r.name = '${RUN()}';`);

test.describe('form_v2 offline queue drain', () => {
    test.skip(RUNNING_ON_BS, 'page.route is not available through the BrowserStack Selenium bridge');

    test.afterEach(async ({ page }) => {
        try { await page.unroute(SYNC_ROUTE); } catch {}
        try { await page.evaluate(IDB_CLEAR); } catch {}
    });

    test('a final page drains after an earlier page queued later in the same unit session', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        const final = entryFor(ctx, { page: ctx.pageNum + 1, final: true, queued_at: 1, client_ts: '2026-01-01 10:00:00' });
        const earlier = entryFor(ctx, { page: ctx.pageNum, queued_at: 2, client_ts: '2026-01-01 10:00:05' });
        await page.evaluate(IDB_PUT, [final, earlier]);
        const posted = await mockSync(page);
        await reloadAndDrain(page);
        await expect.poll(() => posted.length, { timeout: 10000 }).toBe(2);
        expect(posted).toEqual([earlier.uuid, final.uuid]);
    });

    test('a drop of this page\'s own unit session takes the participant to the run URL', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        await seedEntry(page, ctx);
        await mockSync(page, () => ({ status: 409, contentType: 'application/json', body: JSON.stringify({ error: 'ended', drop_entry: true }) }));
        let navigations = 0;
        page.on('framenavigated', (f) => { if (f === page.mainFrame()) navigations += 1; });
        await reloadAndDrain(page);
        // The reload is one navigation; the drop must cause a second, to the
        // run URL (only the server knows where the participant is now).
        await expect.poll(() => navigations, { timeout: 15000 }).toBeGreaterThanOrEqual(2);
        expect(new URL(page.url()).pathname).toBe(new URL(ctx.runUrl).pathname);
        expect(await page.evaluate(IDB_GET_ALL)).toEqual([]);
    });

    test('a stuck entry of an earlier unit session does not block the current one', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        const stale = entryFor(ctx, {
            unit_session_id: ctx.unitSessionId - 1, queued_at: 1, state: 'needs_attention',
            last_error: { reason: 'validation', errors: { x: 'e2e' } },
        });
        const own = entryFor(ctx, { queued_at: 2 });
        await page.evaluate(IDB_PUT, [stale, own]);
        const posted = await mockSync(page);
        await reloadAndDrain(page);
        await expect.poll(() => posted, { timeout: 10000 }).toEqual([own.uuid]);
        // It can never apply: the banner says so and offers to let it go.
        const banner = page.locator('.fmr-queue-banner');
        await expect(banner).toContainText('earlier visit');
        page.once('dialog', (d) => d.accept());
        await banner.locator('.fmr-queue-banner-action', { hasText: /Discard/ }).click();
        await expect.poll(async () => (await page.evaluate(IDB_GET_ALL)).length, { timeout: 10000 }).toBe(0);
    });

    test('entries queued before offline mode was turned off still drain', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        const uuid = await seedEntry(page, ctx);
        setOfflineMode(false);
        try {
            const posted = await mockSync(page);
            await reloadAndDrain(page);
            expect((await currentFormContext(page)).offlineMode).toBe('off');
            await expect.poll(() => posted, { timeout: 10000 }).toEqual([uuid]);
            await expect.poll(async () => (await page.evaluate(IDB_GET_ALL)).length, { timeout: 10000 }).toBe(0);
        } finally {
            setOfflineMode(true);
        }
    });

    test('a live submit waits for a running drain and lands after the queued page', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        await fillAllVisible(page, page.locator('section.fmr-page:not([hidden])'));
        const events = [];
        await page.route(SYNC_ROUTE, async (route) => {
            events.push('sync-start');
            await new Promise((done) => setTimeout(done, 2500));
            events.push('sync-done');
            await route.fulfill(OK);
        });
        page.on('request', (r) => { if (SUBMIT_RE.test(new URL(r.url()).pathname)) events.push('submit'); });
        await seedEntry(page, ctx);
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await expect.poll(() => events.includes('sync-start'), { timeout: 10000 }).toBe(true);
        await Promise.all([
            page.waitForRequest((r) => SUBMIT_RE.test(new URL(r.url()).pathname), { timeout: 20000 }),
            page.evaluate(() => document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]').click()),
        ]);
        expect(events).toEqual(['sync-start', 'sync-done', 'submit']);
    });

    test('the drain before a live submit leaves other studies\' entries alone', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        await fillAllVisible(page, page.locator('section.fmr-page:not([hidden])'));
        const foreign = await seedEntry(page, ctx, {
            sync_url: new URL('/e2e-queue-foreign/form-sync/', ctx.syncUrl).toString(), unit_session_id: 1,
        });
        const posted = await mockSync(page);
        const res = await v2.submitV2(page, { timeout: 20000 });
        expect(res.status).toBe(200);
        await page.waitForTimeout(1000);
        expect(posted, 'a live submit drains only its own form').toEqual([]);
        // The drain triggers still cover every study on the origin.
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await expect.poll(() => posted, { timeout: 10000 }).toEqual([foreign]);
    });

    test('a logout click wipes this run\'s queue before it navigates, and only this run\'s', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        const own = Array.from({ length: 200 }, (_, i) => entryFor(ctx, { page: ctx.pageNum, queued_at: i + 1 }));
        const foreign = entryFor(ctx, { sync_url: new URL('/e2e-queue-foreign/form-sync/', ctx.syncUrl).toString(), unit_session_id: 1 });
        await page.evaluate(IDB_PUT, [...own, foreign]);
        const logoutUrl = ctx.runUrl.replace(/\/?$/, '/') + 'logout';
        await page.route((u) => /\/logout\/?$/.test(u.pathname), (route) => route.fulfill({
            status: 200, contentType: 'text/html', body: '<!doctype html><title>logged out</title>',
        }));
        await page.evaluate((href) => {
            const a = document.createElement('a');
            a.href = href;
            a.id = 'e2e-logout';
            a.textContent = 'Log out';
            document.querySelector('form.fmr-form-v2').appendChild(a);
        }, logoutUrl);
        await Promise.all([
            page.waitForURL((u) => /\/logout\/?$/.test(u.pathname), { timeout: 10000 }),
            page.click('#e2e-logout'),
        ]);
        const left = (await page.evaluate(IDB_GET_ALL)).map((e) => e.uuid);
        expect(left).toEqual([foreign.uuid]);
    });

    test('a page queued after a waiting final page gives it its newer hidden list', async ({ page, baseURL }) => {
        const ctx = await start(page, baseURL);
        await fillAllVisible(page, page.locator('section.fmr-page:not([hidden])'));
        const final = entryFor(ctx, { page: ctx.pageNum + 1, final: true, hidden: [987654], queued_at: 1 });
        await page.evaluate(IDB_PUT, final);
        await mockSync(page, () => ({ status: 503, contentType: 'application/json', body: '{}' }));
        await page.context().setOffline(true);
        try {
            await page.locator('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]').click();
            await expect.poll(async () => (await page.evaluate(IDB_GET_ALL)).length, { timeout: 10000 }).toBe(2);
        } finally {
            await page.context().setOffline(false);
        }
        const all = await page.evaluate(IDB_GET_ALL);
        const revisit = all.find((e) => e.uuid !== final.uuid);
        const restamped = all.find((e) => e.uuid === final.uuid);
        expect(revisit.final).toBeFalsy();
        expect(restamped.hidden, 'the final page carries the newer snapshot').toEqual(revisit.hidden);
    });

    test('offline mode off: a submit held back by an earlier page says so, without a connection alert', async ({ page, baseURL }) => {
        setOfflineMode(false);
        try {
            const ctx = await start(page, baseURL);
            await fillAllVisible(page, page.locator('section.fmr-page:not([hidden])'));
            await mockSync(page, () => ({ status: 503, contentType: 'application/json', body: '{}' }));
            await seedEntry(page, ctx, { page: ctx.pageNum + 1 });
            let alerted = null;
            page.on('dialog', (d) => { alerted = d.message(); d.dismiss().catch(() => {}); });
            await page.locator('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]').click();
            await expect(page.locator('.fmr-queue-banner')).toContainText('still waiting to be sent', { timeout: 10000 });
            expect(alerted).toBeNull();
            expect(await v2.visiblePageNum(page)).toBe(ctx.pageNum);
        } finally {
            setOfflineMode(true);
        }
    });
});
