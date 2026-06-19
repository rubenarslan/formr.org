// Cross-page showif: a showif that references BOTH a prior-page answer and a
// current-page answer reveals its item live in v2 but NOT in v1.
//
//   npx playwright test --config tests/e2e/playwright.config.js crosspage-showif
//
// Why they diverge (this is the characterisation, not a v1 bug to "fix"):
//   - v1 (SpreadsheetRenderer) renders ONE page per server round-trip. Its
//     client evaluator (webroot/assets/common/js/survey.js) builds the showif
//     context only from the CURRENT page's form elements, then runs
//     `with(context){ return !(EXPR) }` via new Function(). A prior-page
//     variable isn't in that context, so `with` falls through to global scope
//     → ReferenceError → the catch hides the item. It only resolves server-side
//     on a later render ("appears on the next page"). Authors must carry the
//     value forward as a hidden field to make it live in v1.
//   - v2 (FormRenderer) emits ALL pages into one DOM under one Alpine
//     `fmrForm` scope, so a page-1 answer is reactive state on page 2 and the
//     showif evaluates correctly (and Alpine eval is try/caught → no throw).
//
// Fixture (2 pages):
//   page 1: text `cp_a`
//   page 2: text `cp_b`, and text `cp_followup` with
//           showif  `cp_a == '1' && cp_b == '3'`   (prior-page var + current)
// Provisioned by tests/e2e/setup/create-crosspage-showif-fixture.sql
// (runs e2e-crosspage-v1 / e2e-crosspage-v2; see setup/runbook.md + runs.json).

const { test, expect } = require('./helpers/test');
const { runName } = require('./helpers/runs');
const { freshParticipant } = require('./helpers/participant');
const v1 = require('./helpers/v1Form');
const v2 = require('./helpers/v2Form');

const FOLLOWUP = '[name="cp_followup"]';

test.describe('cross-page showif (prior-page var + current-page var)', () => {

    test('v2: reveals live — prior-page answer is in the Alpine scope', async ({ page, baseURL }) => {
        const run = runName('crosspage_showif', 'v2');
        await freshParticipant(page, run, { baseURL });
        expect(page.url(), 'should be on the participant URL').toContain(`/${run}/`);
        await v2.waitForBundle(page);

        // page 1: set the prior-page var
        await page.fill('[name="cp_a"]', '1');
        // hidden until both conditions hold
        await expect(page.locator(FOLLOWUP)).toBeHidden();

        // advance to page 2 (client-side nav, same Alpine scope — no round-trip)
        await v2.submitV2(page);

        // current-page var: completing the AND must reveal the follow-up,
        // even though cp_a was answered on the previous page.
        await page.fill('[name="cp_b"]', '3');
        await page.locator('[name="cp_b"]').blur();
        await expect(page.locator(FOLLOWUP),
            'v2 should reveal the cross-page showif item live').toBeVisible({ timeout: 5000 });
    });

    test('v1: does NOT reveal live — prior-page var is absent from the page context', async ({ page, baseURL }) => {
        const run = runName('crosspage_showif', 'v1');
        await freshParticipant(page, run, { baseURL });
        expect(page.url()).toContain(`/${run}/`);

        // capture the evaluator's failure log (survey.js logs "JS showif failed")
        const showifErrors = [];
        page.on('console', (m) => {
            const t = m.text();
            if (/JS showif failed/i.test(t)) showifErrors.push(t);
        });

        // page 1: set the prior-page var, then submit (server round-trip)
        await page.fill('[name="cp_a"]', '1');
        await v1.submitV1(page);

        // page 2 is a fresh server render: cp_a is NOT in the DOM here.
        await page.fill('[name="cp_b"]', '3');
        await page.locator('[name="cp_b"]').blur();
        await page.waitForTimeout(1000); // let the change handler run + (fail to) reveal

        await expect(page.locator(FOLLOWUP),
            'v1 cannot resolve a prior-page var client-side, so it stays hidden').toBeHidden();
        expect(showifErrors.join('\n'),
            'survey.js should have logged a ReferenceError for the prior-page var cp_a')
            .toMatch(/cp_a is not defined|JS showif failed/i);
    });
});
