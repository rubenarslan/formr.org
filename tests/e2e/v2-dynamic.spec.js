// form_v2: one resolution path for dynamic content on every page.
//
//   npx playwright test --config tests/e2e/playwright.config.js v2-dynamic
//
// Dynamic `value`s (including hidden items with an R value), dynamic labels, R in choice
// labels and note_iframe all resolve through /form-render-page — on the first
// page as on every later one — and showif is JavaScript only (no server-side R
// pass). Fixture: tests/e2e/setup/e2e_v2_dynamic.json, provisioned by
// tests/e2e/setup/create-v2-fixtures.js as run `e2e-dyn-v2`.
//
//   page 1: d_intro (label `r 2*3`), d_num (number), d_default (value
//           paste("pre","filled")), d_calc_false (hidden 1 > 2), d_calc_seed
//           (hidden 3 + 4), d_after_calc (showif d_calc_seed == 7), d_mc
//           (choice label `r 10 + 1`)
//   page 2: d_label2 (label `r d_num`), d_default2 (value d_num * 2), d_calc2
//           (hidden d_num > 5), d_mc2 (choice label `r d_num + 100`),
//           d_show2 (showif d_num > 5), d_iframe (note_iframe `r d_num`)
const { test, expect, bsSafeEvaluate } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const db = require('./helpers/db');

const RUN = () => runName('v2_dynamic', 'v2');

function study() {
    return db.dbQuery("SELECT id, results_table FROM survey_studies WHERE name = 'e2e_v2_dynamic' ORDER BY id DESC LIMIT 1")[0];
}

async function unitSessionId(page) {
    return v2.unitSessionId(page);
}

// Deferred content has resolved once page `pageNum` is the visible one and
// nothing on it is pending (the client resolves a page before showing it).
async function waitResolved(page, pageNum) {
    await page.waitForFunction((n) => {
        const sec = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])');
        return sec && Number(sec.dataset.fmrPage) === n && !sec.querySelector('.fmr-fill-pending');
    }, pageNum, { timeout: 30000 });
}

// The knitted note_iframe document (OpenCPU session file), as text.
async function iframeText(page, src) {
    const res = await page.request.get(src, { ignoreHTTPSErrors: true });
    return (await res.text()).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
}

async function snapshot(page) {
    return bsSafeEvaluate(page, () => {
        const wrap = (name) => document.querySelector(`form.fmr-form-v2 [name="${name}"]`)?.closest('.form-group');
        const visible = (name) => { const w = wrap(name); return !!w && !w.hasAttribute('data-fmr-hidden') && w.style.display !== 'none' && !w.classList.contains('hidden'); };
        const text = (sel) => document.querySelector(sel)?.textContent.replace(/\s+/g, ' ').trim() || null;
        const choice = (name, value) => text(`form.fmr-form-v2 label[for$="_${value}"]:has(input[name="${name}"])`);
        return {
            intro: text('form.fmr-form-v2 .item-note:has([name="d_intro"]) .control-label'),
            label2: text('form.fmr-form-v2 .item-note:has([name="d_label2"]) .control-label'),
            d_default: document.querySelector('[name="d_default"]')?.value ?? null,
            d_default2: document.querySelector('[name="d_default2"]')?.value ?? null,
            d_calc_false: document.querySelector('[name="d_calc_false"]')?.value ?? null,
            d_calc_seed: document.querySelector('[name="d_calc_seed"]')?.value ?? null,
            d_calc2: document.querySelector('[name="d_calc2"]')?.value ?? null,
            mc1: choice('d_mc', 1),
            mc2_1: choice('d_mc2', 1),
            afterCalc: visible('d_after_calc'),
            show2: visible('d_show2'),
            iframeSrc: document.querySelector('form.fmr-form-v2 .item-note_iframe iframe')?.getAttribute('src') || null,
            rawR: /`r /.test(document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden])')?.textContent || ''),
        };
    });
}

test.describe('form_v2: dynamic content resolves the same way on every page', () => {
    test('page 1: values, labels, choice labels and hidden values resolve on load', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        await waitResolved(page, 1);
        const s = await snapshot(page);
        expect(s.intro).toContain('Dynamic label on page one: 6');
        expect(s.d_default).toBe('pre filled');
        expect(s.mc1).toBe('11');
        expect(s.rawR, 'no raw `r …` source may reach the participant').toBe(false);
        expect(s.afterCalc, 'a showif over a same-page hidden item').toBe(true);
        expect(s.d_calc_false, 'a FALSE result is written as "FALSE", not empty').toBe('FALSE');
        expect(s.d_calc_seed).toBe('7');
    });

    test('page 2 resolves against page-1 answers; hidden values are stored with their page', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        await waitResolved(page, 1);
        await page.fill('[name="d_num"]', '9');
        const r = await v2.submitV2(page);
        expect(v2.advanced(r.body), JSON.stringify(r.body)).toBeTruthy();
        await waitResolved(page, 2);
        const s = await snapshot(page);
        expect(s.label2).toContain('You picked 9');
        expect(s.d_default2).toBe('18');
        expect(s.mc2_1).toBe('109');
        expect(s.show2).toBe(true);
        expect(s.rawR).toBe(false);
        expect(s.iframeSrc, 'note_iframe gets its knitted src on its own page').toMatch(/^https?:\/\//);
        expect(await iframeText(page, s.iframeSrc), 'knitted with the page-1 answer, not at initial render').toContain('Iframe says 9');

        expect(s.d_calc2, 'resolved against the page-1 answer').toBe('TRUE');

        // Hidden values are stored with their page's submit, like any answer.
        const sid = await unitSessionId(page);
        const row = db.dbResultsRow(study().results_table, sid);
        expect(row.d_calc_false, 'a FALSE result is stored as "FALSE", not empty').toBe('FALSE');
        expect(row.d_calc_seed).toBe('7');
    });

    test('resume: a showif over an earlier page\'s answer survives a reload', async ({ page, baseURL }) => {
        await freshParticipant(page, RUN(), { baseURL });
        await v2.waitForBundle(page);
        await waitResolved(page, 1);
        await page.fill('[name="d_num"]', '9');
        const r = await v2.submitV2(page);
        expect(v2.advanced(r.body), JSON.stringify(r.body)).toBeTruthy();
        // The reply comes before the page change (it waits on page 2's R
        // values): reload once page 2 is showing, as a participant would.
        await expect.poll(() => v2.visiblePageNum(page), { timeout: 10000 }).toBe(2);
        await page.reload();
        await v2.waitForBundle(page);
        await waitResolved(page, 2);
        const s = await snapshot(page);
        expect(s.show2, 'd_num is no longer in the DOM; its saved answer must still drive the showif').toBe(true);
        expect(s.label2).toContain('You picked 9');
    });
});

// A page is shown after at most PAGE_RESOLVE_WAIT_MS while its R values may
// still be resolving; a submit must wait for them. Otherwise a hidden bridge
// item (d_calc_seed = 3 + 4) went out empty, and the late reply filled an
// input that nothing sends again.
test('a submit waits for its page\'s R values still in flight', async ({ page, baseURL }) => {
    test.skip(!!process.env.BROWSERSTACK_AUTOMATION, 'page.route is not available through the BrowserStack bridge');
    await page.route(/\/form-render-page\/?(\?.*)?$/, async (route) => {
        // Longer than setting up the page takes, shorter than the submit's
        // bounded wait (SUBMIT_RESOLVE_WAIT_MS).
        await new Promise((done) => setTimeout(done, 10000));
        await route.continue();
    });
    await freshParticipant(page, RUN(), { baseURL });
    await v2.waitForBundle(page);
    await page.locator('input[name="d_num"]').fill('3');
    await page.locator('section.fmr-page:not([hidden]) input[name="d_mc"][type=radio]').first().check({ force: true });
    const [req] = await Promise.all([
        page.waitForRequest((r) => /\/form-page-submit\/?$/.test(new URL(r.url()).pathname), { timeout: 30000 }),
        page.locator('section.fmr-page:not([hidden]) [data-fmr-next]').click(),
    ]);
    const body = JSON.parse(req.postData() || '{}');
    expect(body.data && body.data.d_calc_seed, 'the resolved value went with the page').toBe('7');
});
