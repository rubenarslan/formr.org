// form_v2 file uploads, live and through the offline queue (coverage pass,
// documentation/agent_doc/coverage_pass.md gap 5): no spec picked a file,
// so the multipart path — buildFormData, the multipart /form-page-submit
// and /form-sync, FormSubmissionService::mergeUploadedFiles, File_Item
// storing the upload — never ran.
//
// Fixture: the showcase (run e2e-awv2, all_widgets_v2.json). Its file item
// u_file sits on the last page (5) with optional items only; the test opens
// that page through the remembered-page key (state.mjs) instead of walking
// the showcase, and solves the page's bot_check before submitting. The
// last page's submit is final, and pages 1–4 are unanswered, so the server
// answers `missing` — after storing the page, which is what is checked
// here. Uploads live in formr_app's webroot/assets/tmp volume, not on the
// host: they are checked (and removed) through docker exec.

const { execFileSync } = require('node:child_process');
const { test, expect, RUNNING_ON_BS } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const { dbQuery } = require('./helpers/db');
const v2 = require('./helpers/v2Form');
const { IDB_GET_ALL } = require('./helpers/idbQueue');
const { solveBotCheckIfPresent } = require('./helpers/v2Complete');

const RUN = () => runName('all_widgets_v2', 'v2');
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const UPLOADS = '/var/www/formr/webroot/assets/tmp/user_uploaded_files';
const isWrite = (url) => /\/(form-page-submit|form-sync)\/?$/.test(new URL(url).pathname);

async function openFilePage(page, baseURL) {
    await freshParticipant(page, RUN(), { baseURL });
    await v2.waitForBundle(page);
    const usid = await v2.unitSessionId(page);
    await page.evaluate((id) => window.localStorage.setItem(`fmr:page:${id}`, '5'), usid);
    // The URL names the landing page (?page=1), which wins over the
    // remembered one: open the run URL itself.
    await page.goto(page.url().replace(/[?&]page=\d+/, '').replace(/\?$/, ''), { waitUntil: 'domcontentloaded' });
    await v2.waitForBundle(page);
    await expect.poll(() => v2.visiblePageNum(page), { timeout: 10000 }).toBe(5);
    await page.locator('input[type=file][name="u_file"]').setInputFiles({ name: 'dot.png', mimeType: 'image/png', buffer: PNG });
    expect(await solveBotCheckIfPresent(page, 'form.fmr-form-v2 section.fmr-page:not([hidden])')).toBe(true);
    return usid;
}

function storedUpload(usid) {
    const rows = dbQuery(`SELECT d.answer FROM survey_items_display d JOIN survey_items i ON i.id = d.item_id `
        + `WHERE d.session_id = ${Number(usid)} AND i.name = 'u_file';`);
    const answer = rows.length ? rows[0].answer : null;
    const m = answer && answer.match(/user_uploaded_files\/([A-Za-z0-9_-]+\.png)/);
    return { answer, file: m ? `${UPLOADS}/${m[1]}` : null };
}

// Is the stored file on disk? Removes it: nothing to keep after the check.
function takeUpload(file) {
    if (!file) return false;
    try { execFileSync('docker', ['exec', 'formr_app', 'test', '-s', file]); } catch (e) { return false; }
    try { execFileSync('docker', ['exec', 'formr_app', 'rm', '-f', file]); } catch (e) { /* best effort */ }
    return true;
}

test.describe('form_v2 file uploads', () => {
    test.skip(RUNNING_ON_BS, 'setInputFiles and setOffline are not reliable through the BrowserStack bridge');

    test('a live submit sends the file as multipart and the server stores it', async ({ page, baseURL }) => {
        const usid = await openFilePage(page, baseURL);
        const [req] = await Promise.all([
            page.waitForRequest((r) => isWrite(r.url()) && r.method() === 'POST', { timeout: 15000 }),
            page.locator('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]').click(),
        ]);
        expect(new URL(req.url()).pathname).toMatch(/form-page-submit/);
        expect(req.headers()['content-type']).toMatch(/^multipart\/form-data/);
        await expect.poll(() => storedUpload(usid).answer, { timeout: 15000 }).toMatch(/user_uploaded_files\//);
        expect(takeUpload(storedUpload(usid).file), 'the upload is on disk').toBe(true);
    });

    test('offline, the file is queued with the page and sent as multipart on reconnect', async ({ page, baseURL }) => {
        const usid = await openFilePage(page, baseURL);
        await page.context().setOffline(true);
        await page.locator('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]').click();
        await expect.poll(async () => (await page.evaluate(IDB_GET_ALL)).length, { timeout: 10000 }).toBe(1);
        const [queued] = await page.evaluate(IDB_GET_ALL);
        expect(Object.keys(queued.files || {}), 'the file travels beside the answers').toEqual(['u_file']);
        expect(queued.data.u_file, 'not stringified into data').toBeUndefined();

        const syncReq = page.waitForRequest((r) => /\/form-sync\/?$/.test(new URL(r.url()).pathname), { timeout: 20000 });
        await page.context().setOffline(false);
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        expect((await syncReq).headers()['content-type']).toMatch(/^multipart\/form-data/);
        await expect.poll(() => storedUpload(usid).answer, { timeout: 15000 }).toMatch(/user_uploaded_files\//);
        expect(takeUpload(storedUpload(usid).file), 'the upload is on disk').toBe(true);
        await expect.poll(async () => (await page.evaluate(IDB_GET_ALL)).length, { timeout: 10000 }).toBe(0);
    });

    test('a stored upload survives its page being submitted again without a new file', async ({ page, baseURL }) => {
        const usid = await openFilePage(page, baseURL);
        const next = () => page.locator('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]').click();
        await Promise.all([page.waitForRequest((r) => isWrite(r.url()), { timeout: 15000 }), next()]);
        await expect.poll(() => storedUpload(usid).answer, { timeout: 15000 }).toMatch(/user_uploaded_files\//);
        const stored = storedUpload(usid);

        // A reload empties the file input; the page is submitted again. (The
        // `missing` reply routed to page 1 and put ?page=1 in the URL, which
        // a reload would honour: open the run URL itself.)
        await page.evaluate((id) => window.localStorage.setItem(`fmr:page:${id}`, '5'), usid);
        await page.goto(page.url().replace(/\?.*$/, ''), { waitUntil: 'domcontentloaded' });
        await v2.waitForBundle(page);
        await expect.poll(() => v2.visiblePageNum(page), { timeout: 10000 }).toBe(5);
        await solveBotCheckIfPresent(page, 'form.fmr-form-v2 section.fmr-page:not([hidden])');
        const [req] = await Promise.all([page.waitForRequest((r) => isWrite(r.url()), { timeout: 15000 }), next()]);
        const posted = req.postData() || '';
        expect(posted, 'nothing is posted for the stored upload').not.toMatch(/"u_file"/);
        await page.waitForTimeout(1500);
        expect(storedUpload(usid).answer, 'the upload is still stored').toBe(stored.answer);
        takeUpload(stored.file);
    });
});
