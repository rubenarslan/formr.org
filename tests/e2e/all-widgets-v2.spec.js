// All-widgets smoke for v2 (FormRenderer + form.bundle.js).
//
// Page-fixture form (BS-safe — `browser.newContext()` returns about:blank
// on BS Real Mobile). Each test starts with a URL precheck so a misrouted
// navigation surfaces immediately instead of asserting against about:blank.

const { test, expect } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const { WIDGET_TYPES, fillAllVisible, V2_PAGE } = require('./helpers/widgets');

const RUN = () => runName('all_widgets', 'v2');

test.describe('all_widgets v2', () => {
    test('form renders with fmr-form-v2 wrapper', async ({ page, baseURL }) => {
        const run = RUN();
        await freshParticipant(page, run, { baseURL });
        expect(page.url(), 'page should be on the participant URL, not about:blank').toContain(`/${run}/`);

        await expect(page.locator(v2.FORM_SELECTOR).first()).toBeVisible({ timeout: 20000 });
        await v2.waitForBundle(page);
        const f = v2.form(page);
        expect(await f.getAttribute('data-submit-url')).toMatch(/\/form-page-submit\/?$/);
        expect(await f.getAttribute('data-sync-url')).toMatch(/\/form-sync\/?$/);
    });

    test('every supported widget on page is reachable', async ({ page, baseURL }) => {
        const run = RUN();
        await freshParticipant(page, run, { baseURL });
        expect(page.url()).toContain(`/${run}/`);

        await v2.waitForBundle(page);
        const presentTypes = [];
        for (const type of WIDGET_TYPES) {
            const n = await page.locator(`.item-${type}`).count();
            if (n > 0) presentTypes.push({ type, count: n });
        }
        expect(presentTypes.length, `widget types seen: ${presentTypes.map((p) => p.type).join(', ')}`).toBeGreaterThanOrEqual(8);
    });

    test('fill-all and submit page 1 returns ok', async ({ page, baseURL }) => {
        const run = RUN();
        await freshParticipant(page, run, { baseURL });
        expect(page.url(), 'page should be on the participant URL, not about:blank').toContain(`/${run}/`);

        await v2.waitForBundle(page);
        const filled = await fillAllVisible(page, V2_PAGE);
        // Every item the filler has a strategy for must have been answered —
        // in particular the required selects (time_period, partner), which a
        // throwing filler used to leave blank without anyone noticing.
        const failed = filled.filter((f) => !f.ok || f.error);
        expect(failed, `filler could not answer: ${JSON.stringify(failed)}`).toEqual([]);
        for (const type of ['select_one', 'select_multiple']) {
            expect(filled.some((f) => f.type === type), `page 1 should carry a ${type}`).toBe(true);
        }
        const unansweredSelects = await page.evaluate((sel) =>
            [...document.querySelectorAll(`${sel} .item-select_one select, ${sel} .item-select_multiple select`)]
                .filter((s) => ![...s.selectedOptions].some((o) => o.value !== ''))
                .map((s) => s.name), V2_PAGE);
        expect(unansweredSelects, 'selects left blank after fill').toEqual([]);

        const { status, body, blockedByClient } = await v2.submitV2(page);
        // A client-side block means the filler left a required item blank —
        // a failure, not a soft pass. Name the flagged items.
        if (blockedByClient) {
            const flagged = await page.evaluate((sel) =>
                [...document.querySelectorAll(`${sel} .form-group.is-invalid`)]
                    .map((g) => (g.className.match(/\bitem-([a-z_]+)\b/) || [, '?'])[1]
                        + ':' + ((g.querySelector('[name]:not([name^="_item_views"])') || {}).name || '?')), V2_PAGE);
            throw new Error(`submit blocked client-side after fill-all; flagged: ${flagged.join(', ') || '(none marked)'}`);
        }
        expect(status).toBe(200);
        if (body && body.status === 'errors') {
            throw new Error('v2 submit returned validation errors: ' + JSON.stringify(body.errors));
        }
        expect(body && body.status, 'expected status:ok').toBe('ok');
    });

    test('blank submit on a page with a required item shows client-side validation feedback', async ({ page, baseURL }) => {
        const run = RUN();
        await freshParticipant(page, run, { baseURL });
        expect(page.url()).toContain(`/${run}/`);

        await v2.waitForBundle(page);
        const inPage = page.locator('section.fmr-page:not([hidden])');
        const requiredCount = await inPage.locator('.form-group.required input, .form-group.required select, .form-group.required textarea').count();
        test.skip(requiredCount === 0, 'no required items on page 1 of this fixture');
        // v2 client gates submit on validatePageAndShowFeedback (native
        // Constraint Validation). Blank required → no /form-page-submit
        // request is fired; instead, inline `.fmr-invalid-feedback` /
        // `.fmr-btn-feedback` is rendered by the bundle. Click via JS
        // to bypass Playwright's actionability checks (the OpenCPU error
        // alert at the top obscures actionability heuristics).
        await page.evaluate(() => {
            const btn = document.querySelector('form.fmr-form-v2 section.fmr-page:not([hidden]) [data-fmr-next]');
            if (btn) btn.click();
        });
        await page.waitForTimeout(800);
        const feedback = await inPage.locator('.fmr-invalid-feedback, .fmr-btn-feedback').count();
        expect(feedback, 'expected at least one inline feedback for blank required').toBeGreaterThan(0);
        const stillForm = await v2.isPresent(page);
        expect(stillForm, 'form should still be present after blocked submit').toBe(true);
    });
});
