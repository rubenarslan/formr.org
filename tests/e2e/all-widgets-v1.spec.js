// All-widgets smoke for v1 (SpreadsheetRenderer).
//
// Page-fixture form (BS-safe — `browser.newContext()` returns about:blank
// on BS Real Mobile). Each test starts with a URL precheck so a misrouted
// navigation surfaces immediately instead of asserting against about:blank.

const { test, expect } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v1 = require('./helpers/v1Form');
const { WIDGETS, fillAllVisible } = require('./helpers/widgets');

const RUN = () => runName('all_widgets', 'v1');

test.describe('all_widgets v1', () => {
    test('form renders with main_formr_survey class', async ({ page, baseURL }) => {
        const run = RUN();
        await freshParticipant(page, run, { baseURL });
        expect(page.url(), 'page should be on the participant URL, not about:blank').toContain(`/${run}/`);

        await expect(page.locator(v1.FORM_SELECTOR).first()).toBeVisible({ timeout: 20000 });
        await expect(page.locator(`${v1.FORM_SELECTOR} input[name="session_id"]`)).toHaveCount(1);
    });

    test('every supported widget on page is reachable', async ({ page, baseURL }) => {
        const run = RUN();
        await freshParticipant(page, run, { baseURL });
        expect(page.url()).toContain(`/${run}/`);

        const presentTypes = [];
        for (const w of WIDGETS) {
            const n = await page.locator(`.item-${w.type}`).count();
            if (n > 0) presentTypes.push({ type: w.type, count: n });
        }
        expect(presentTypes.length, `widget types seen: ${presentTypes.map((p) => p.type).join(', ')}`).toBeGreaterThanOrEqual(8);
    });

    test('fill-all and submit page 1 progresses', async ({ page, baseURL }) => {
        const run = RUN();
        await freshParticipant(page, run, { baseURL });
        expect(page.url(), 'page should be on the participant URL, not about:blank').toContain(`/${run}/`);

        await expect(page.locator(v1.FORM_SELECTOR).first()).toBeVisible({ timeout: 20000 });
        const beforeProgress = await v1.progressPercent(page);
        await fillAllVisible(page);
        await v1.submitV1(page);
        // After submit, either: (a) we're on a new page (form still present, progress ↑),
        // (b) the form is gone (advanced past survey), or (c) inline validation failed
        // (form still present, errors visible). (a) and (b) are passes.
        const errs = await v1.errorMessages(page);
        const stillForm = await v1.isPresent(page);
        if (errs.length && stillForm) {
            throw new Error('v1 submit returned validation errors after fill-all: ' + errs.join(' | '));
        }
        if (stillForm) {
            const afterProgress = await v1.progressPercent(page);
            if (beforeProgress != null && afterProgress != null) {
                expect(afterProgress, 'progress should not regress after a successful submit').toBeGreaterThanOrEqual(beforeProgress);
            }
        }
    });

    test('blank submit on a page with a required item returns errors', async ({ page, baseURL }) => {
        const run = RUN();
        await freshParticipant(page, run, { baseURL });
        expect(page.url()).toContain(`/${run}/`);

        await expect(page.locator(v1.FORM_SELECTOR).first()).toBeVisible({ timeout: 20000 });
        const requiredCount = await page.locator(`${v1.FORM_SELECTOR} .form-group.required input, ${v1.FORM_SELECTOR} .form-group.required select, ${v1.FORM_SELECTOR} .form-group.required textarea`).count();
        test.skip(requiredCount === 0, 'no required items on page 1 of this fixture');
        await v1.submitV1(page);
        // After blank submit, we expect either an inline error message OR
        // the form is still here (page didn't advance). The exact wording of
        // v1's "this field is required" message is browser-localized so
        // assert presence, not text.
        const errs = await v1.errorMessages(page);
        const stillForm = await v1.isPresent(page);
        expect(errs.length > 0 || stillForm, `expected validation block; errs=${errs.length} formPresent=${stillForm}`).toBeTruthy();
    });
});
