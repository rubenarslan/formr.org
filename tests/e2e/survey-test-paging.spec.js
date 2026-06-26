// Regression: the "Test Survey" preview must ADVANCE through a multi-page
// survey. The CSP refactor (ea3766a2) folded seeding + rendering + page-submit
// handling into one SurveyTestController::indexAction and broke this two ways,
// both fixed in a0bf4446:
//
//   1. It re-seeded test_study_data every request, dropping the unit_session_id
//      that Run::testStudy() persists — so every "Next" rebuilt a fresh unit
//      session stuck on page 1 (submit-item / non-paged surveys).
//   2. It never carried the URL page segment that PagedSpreadsheetRenderer
//      (use_paging) navigates by, and the redirect branch went to the bare,
//      page-less token URL — so paged surveys redirected forever.
//
// This spec is RED on the pre-fix controller: the non-paged test sees
// PAGE_ONE_MARKER again after "Continue", and the paged test's goto() hangs in
// the redirect loop until timeout. It is GREEN after the fix.
//
//   npx playwright test --config tests/e2e/playwright.config.js survey-test-paging

const { test, expect } = require('./helpers/test');
const { ADMIN_BASE, STATE_PATH, ensureAdminState, dismissConsent } = require('./helpers/admin');

test.use({ storageState: STATE_PATH });

// A minimal 2-page survey: a note + a submit per page, no answerable/required
// fields, so "Continue" always advances. Distinct note labels tell the pages
// apart. Uploaded as CSV (an allowed import format) so the fixture is plain
// text, owned by the test admin.
const SURVEY = 'e2e_survey_test_paging';
const CSV = [
    'type,name,label',
    'note,e2e_p1_note,PAGE_ONE_MARKER',
    'submit,e2e_p1_submit,Continue',
    'note,e2e_p2_note,PAGE_TWO_MARKER',
    'submit,e2e_p2_submit,Finish',
].join('\n') + '\n';

const authCtx = (browser) => browser.newContext({ storageState: STATE_PATH });

// Delete confirmation requires typing the name back; 404 when absent is fine.
const deleteSurvey = (ctx) =>
    ctx.request.post(`${ADMIN_BASE}/admin/survey/${SURVEY}/delete_study`,
        { form: { delete_confirm: SURVEY } }).catch(() => {});

test.describe('Test Survey preview advances through multi-page surveys', () => {
    test.beforeAll(async ({ browser }) => {
        await ensureAdminState(browser);
        const ctx = await authCtx(browser);
        await deleteSurvey(ctx); // clear any leftover from a prior run (use_paging resets too)
        const resp = await ctx.request.post(`${ADMIN_BASE}/admin/survey/add_survey`, {
            multipart: { uploaded: { name: `${SURVEY}.csv`, mimeType: 'text/csv', buffer: Buffer.from(CSV) } },
        });
        // On success the importer redirects to .../<name>/show_item_table; a
        // name clash would bounce to /admin/survey instead.
        expect(resp.url(), 'fixture survey should be created').toContain(SURVEY);
        await ctx.close();
    });

    test.afterAll(async ({ browser }) => {
        const ctx = await authCtx(browser);
        await deleteSurvey(ctx);
        await ctx.close();
    });

    // Open "Test Survey" and step page 1 -> page 2 via the "Continue" submit.
    async function walkPreview(page) {
        page.on('dialog', (d) => d.dismiss().catch(() => {}));
        // goto resolves the access -> token-mint -> preview redirect chain. On
        // the unfixed paged path this never settles (infinite redirect) and
        // times out — which is exactly the regression we are guarding.
        await page.goto(`${ADMIN_BASE}/admin/survey/${SURVEY}/access`,
            { waitUntil: 'domcontentloaded', timeout: 30000 });
        expect(page.url(), 'preview renders off /admin/ on the token path').toContain('/survey-test/');
        await dismissConsent(page);
        await expect(page.getByText('PAGE_ONE_MARKER')).toBeVisible();

        await page.getByRole('button', { name: /Continue/i }).click();

        // Decisive: page 2 shows and page 1 is gone — not a reload of page 1,
        // not a stalled redirect.
        await expect(page.getByText('PAGE_TWO_MARKER')).toBeVisible();
        await expect(page.getByText('PAGE_ONE_MARKER')).toHaveCount(0);
    }

    // Runs first (declaration order, workers:1): the freshly uploaded survey
    // has use_paging = 0.
    test('non-paged (submit-item) survey advances past page 1', async ({ page }) => {
        await walkPreview(page);
    });

    test('paged survey (use_paging) advances without infinite-redirecting', async ({ page }) => {
        // Enable Custom Paging through the settings form so all the pre-filled
        // numeric fields ride along and pass server-side range validation
        // (posting use_paging alone trips the percentage check and saves nothing).
        await page.goto(`${ADMIN_BASE}/admin/survey/${SURVEY}`,
            { waitUntil: 'domcontentloaded', timeout: 30000 });
        await dismissConsent(page);
        await page.check('input[name=use_paging]');
        await page.getByRole('button', { name: /Save Settings/i }).click();
        await expect(page.locator('input[name=use_paging]')).toBeChecked();

        await walkPreview(page);
        // Paged previews carry the page as a URL segment — prove we advanced to /2/.
        expect(page.url()).toMatch(/\/survey-test\/[^/]+\/2\/?$/);
    });
});
