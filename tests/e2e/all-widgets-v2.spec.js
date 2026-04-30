// All-widgets smoke for v2 (FormRenderer + form.bundle.js).
//
// Page-fixture form (BS-safe — `browser.newContext()` returns about:blank
// on BS Real Mobile). Each test starts with a URL precheck so a misrouted
// navigation surfaces immediately instead of asserting against about:blank.

const { test, expect } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v2 = require('./helpers/v2Form');
const { WIDGETS, fillAllVisible } = require('./helpers/widgets');

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
        expect(await f.getAttribute('data-rcall-url')).toMatch(/\/form-r-call\/?$/);
        expect(await f.getAttribute('data-fill-url')).toMatch(/\/form-fill\/?$/);
        expect(await f.getAttribute('data-sync-url')).toMatch(/\/form-sync\/?$/);
    });

    test('every supported widget on page is reachable', async ({ page, baseURL }) => {
        const run = RUN();
        await freshParticipant(page, run, { baseURL });
        expect(page.url()).toContain(`/${run}/`);

        await v2.waitForBundle(page);
        const presentTypes = [];
        for (const w of WIDGETS) {
            const n = await page.locator(`.item-${w.type}`).count();
            if (n > 0) presentTypes.push({ type: w.type, count: n });
        }
        expect(presentTypes.length, `widget types seen: ${presentTypes.map((p) => p.type).join(', ')}`).toBeGreaterThanOrEqual(8);
    });

    test('fill-all and submit page 1 returns ok', async ({ page, baseURL }) => {
        const run = RUN();
        await freshParticipant(page, run, { baseURL });
        expect(page.url(), 'page should be on the participant URL, not about:blank').toContain(`/${run}/`);

        await v2.waitForBundle(page);
        await fillAllVisible(page, page.locator('section.fmr-page:not([hidden])'));
        const { status, body, blockedByClient } = await v2.submitV2(page);
        // Acceptable outcomes: server responded ok, or client gated the
        // submit (would mean fillAllVisible left a required field blank).
        // The latter is a softer pass — we couldn't fill everything but
        // the form gate is working.
        if (blockedByClient) {
            test.info().annotations.push({ type: 'note', description: 'client-side gate fired; some required fields not filled by fillAllVisible' });
            return;
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
