// Regression: the "Test Survey" preview token (SurveyTestController,
// TOKEN_TTL = 15 min) must not kill a test that is already IN FLIGHT when it
// expires. Filling a real survey routinely takes longer than the TTL; before
// the fix, the first "Next"/"Finish" POST after expiry got a 410 "Link
// expired" and silently discarded that page's answers (the pre-1.3.0
// admin-session preview never expired mid-test). The fix lets an
// expired-but-AUTHENTIC token continue the live test session (the
// /survey-test/ session's test_study_data proves the test started while the
// token was valid) — while an expired token in a FRESH session still 410s,
// so the replay window for leaked links is unchanged.
//
// Host-coupled: mints a short-TTL token via `docker exec formr_app`, so it
// runs only where the dev stack runs (same assumption as npm run test:e2e).
//
//   npx playwright test --config tests/e2e/playwright.config.js survey-test-token-expiry

const { execSync } = require('node:child_process');
const { test, expect, request } = require('@playwright/test');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');

// No file-level storageState: the participant-side contexts below must be
// cookie-free (the admin state carries a root-path formr_session cookie that
// would otherwise SHARE one PHP session across "different browsers" and
// silently satisfy the has-live-test-session check for the fresh context).
const EMPTY_STATE = { cookies: [], origins: [] };

const SURVEY = 'e2e_survey_test_token_ttl';
const CSV = [
    'type,name,label',
    'text,e2e_ttl_p1,PAGE_ONE_MARKER answer here',
    'submit,e2e_ttl_submit1,Continue',
    'text,e2e_ttl_p2,PAGE_TWO_MARKER answer here',
    'submit,e2e_ttl_submit2,Finish',
].join('\n') + '\n';
const TTL_SECONDS = 8;

const deleteSurvey = (ctx) =>
    ctx.request.post(`${ADMIN_BASE}/admin/survey/${SURVEY}/delete_study`,
        { form: { delete_confirm: SURVEY } }).catch(() => {});

// Mint a preview token inside the app container, resolving the owning user
// from the study row (mintToken is the same code accessAction calls).
function mintShortToken() {
    const php = `require "/var/www/formr/setup.php";` +
        `$s = DB::getInstance()->findRow("survey_studies", array("name" => "${SURVEY}"));` +
        `echo SurveyTestController::mintToken($s["name"], $s["user_id"], ${TTL_SECONDS});`;
    return execSync(`docker exec formr_app php -r '${php}'`, { encoding: 'utf8' }).trim();
}

test.describe('Test Survey preview token expiring mid-test', () => {
    test.beforeAll(async ({ browser }) => {
        await ensureAdminState(browser);
        const ctx = await browser.newContext({ storageState: STATE_PATH });
        await deleteSurvey(ctx);
        const resp = await ctx.request.post(`${ADMIN_BASE}/admin/survey/add_survey`, {
            multipart: { uploaded: { name: `${SURVEY}.csv`, mimeType: 'text/csv', buffer: Buffer.from(CSV) } },
        });
        expect(resp.url(), 'fixture survey should be created').toContain(SURVEY);
        await ctx.close();
    });

    test.afterAll(async ({ browser }) => {
        const ctx = await browser.newContext({ storageState: STATE_PATH });
        await deleteSurvey(ctx);
        await ctx.close();
    });

    test('in-flight test continues past expiry; fresh session still 410s', async () => {
        const token = mintShortToken();
        const url = `${ADMIN_BASE}/survey-test/${token}/`;

        // Establish the test session while the token is valid (page 1 renders).
        const inFlight = await request.newContext({ storageState: EMPTY_STATE });
        const page1 = await inFlight.get(url);
        expect(page1.status()).toBe(200);
        const html1 = await page1.text();
        expect(html1).toContain('PAGE_ONE_MARKER');
        const sessionId = (html1.match(/name="session_id" value="(\d+)"/) || [])[1];
        expect(sessionId, 'page 1 should carry the unit session id').toBeTruthy();

        await new Promise((r) => setTimeout(r, (TTL_SECONDS + 3) * 1000));

        // The answer POST lands AFTER expiry — it must still be accepted.
        const post = await inFlight.post(url, {
            multipart: { session_id: sessionId, e2e_ttl_p1: 'slow but thorough answer', e2e_ttl_submit1: '1' },
        });
        expect(post.status(), 'post-expiry submit of an in-flight test must not 410').toBe(200);
        expect(await post.text()).toContain('PAGE_TWO_MARKER');
        await inFlight.dispose();

        // Same expired token in a browser WITHOUT the test session: refused.
        const fresh = await request.newContext({ storageState: EMPTY_STATE });
        const denied = await fresh.get(url);
        expect(denied.status(), 'expired token must not start a new preview').toBe(410);
        await fresh.dispose();
    });
});
