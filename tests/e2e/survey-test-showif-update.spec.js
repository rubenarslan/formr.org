// Regression (v1.10.3): a corrected showif must take effect without deleting
// and recreating the survey.
//
// survey_items_display.hidden caches each session's server-side showif
// verdict (SpreadsheetRenderer writes it on first render and never asks R
// again). Re-uploading the item table keeps item ids and used to leave those
// rows alone, so every unfinished session — a participant mid-survey, or the
// admin's own in-flight "Test Survey" (which the preview resumed on every
// click) — kept routing by the OLD showif. The admin saw the new code in the
// item table, watched the item stay hidden, and only recreating the survey
// "fixed" it. Two fixes, both covered here:
//   1. SurveyStudy::invalidateShowifVerdicts(): an upload resets the cached
//      verdict of every item whose showif changed, in unfinished sessions.
//   2. SurveyTestController: a NEW "Test Survey" click (newer token) starts a
//      fresh test instead of resuming the old one.
//
//   npx playwright test --config tests/e2e/playwright.config.js survey-test-showif-update
const { test, expect, request } = require('@playwright/test');
const { ADMIN_BASE, STATE_PATH, ensureAdminState } = require('./helpers/admin');

// Participant-side contexts must be cookie-free: the admin state carries a
// root-path formr_session cookie that would share one PHP session with them.
const EMPTY_STATE = { cookies: [], origins: [] };
const SURVEY = 'e2e_showif_update';

// Page 1 asks for a number; page 2 shows DEP only when the showif on it holds
// (evaluated in R at render time because `gate` sits on an earlier page) and
// OTHER always, so the test stays in flight on page 2.
const csv = (showif) => [
    'type,name,label,showif',
    'number,e2e_su_gate,GATE_MARKER pick a number,',
    'submit,e2e_su_submit1,Continue,',
    `text,e2e_su_dep,DEP_MARKER shown when the gate matches,"${showif}"`,
    'text,e2e_su_other,OTHER_MARKER always shown,',
    'submit,e2e_su_submit2,Finish,',
].join('\n') + '\n';

const upload = (ctx, path, showif) => ctx.request.post(`${ADMIN_BASE}${path}`, {
    multipart: { uploaded: { name: `${SURVEY}.csv`, mimeType: 'text/csv', buffer: Buffer.from(csv(showif)) } },
});
const deleteSurvey = (ctx) =>
    ctx.request.post(`${ADMIN_BASE}/admin/survey/${SURVEY}/delete_study`,
        { form: { delete_confirm: SURVEY } }).catch(() => {});

// The real "Test Survey" click: /access mints a token and redirects to it.
async function clickTestSurvey(ctx) {
    const resp = await ctx.request.get(`${ADMIN_BASE}/admin/survey/${SURVEY}/access`, { maxRedirects: 0 });
    const location = resp.headers()['location'] || '';
    expect(location, 'Test Survey should redirect to a /survey-test/ token URL').toContain('/survey-test/');
    return location.startsWith('http') ? location : ADMIN_BASE + location;
}

test.describe('Re-uploaded showif applies without recreating the survey', () => {
    let admin;

    test.beforeAll(async ({ browser }) => {
        await ensureAdminState(browser);
        admin = await browser.newContext({ storageState: STATE_PATH });
        await deleteSurvey(admin);
        const resp = await upload(admin, '/admin/survey/add_survey', 'e2e_su_gate == 1');
        expect(resp.url(), 'fixture survey should be created').toContain(SURVEY);
    });

    test.afterAll(async () => {
        await deleteSurvey(admin);
        await admin.close();
    });

    test('changed showif is re-evaluated in the in-flight test; a new click starts fresh', async () => {
        const urlA = await clickTestSurvey(admin);

        // Page 1 → answer 2 → page 2 renders with DEP hidden (showif gate == 1
        // is FALSE in R, so survey_items_display.hidden = 1 is written).
        const participant = await request.newContext({ storageState: EMPTY_STATE });
        const page1 = await participant.get(urlA);
        expect(page1.status()).toBe(200);
        const html1 = await page1.text();
        expect(html1).toContain('GATE_MARKER');
        const sessionA = (html1.match(/name="session_id" value="(\d+)"/) || [])[1];
        expect(sessionA, 'page 1 should carry the unit session id').toBeTruthy();

        const page2 = await participant.post(urlA, {
            multipart: { session_id: sessionA, e2e_su_gate: '2', e2e_su_submit1: '1' },
        });
        expect(page2.status()).toBe(200);
        const html2 = await page2.text();
        expect(html2).toContain('OTHER_MARKER');
        expect(html2, 'gate == 1 must hide DEP for an answer of 2').not.toContain('DEP_MARKER');

        // The admin corrects the showif and re-uploads the item table.
        const reupload = await upload(admin, `/admin/survey/${SURVEY}/upload_items`, 'e2e_su_gate == 2');
        expect(reupload.status(), 're-upload should succeed').toBeLessThan(400);

        // Fix 1: the SAME in-flight test (same token, same session) now shows
        // DEP — its cached verdict was reset and the new showif holds.
        const resumed = await participant.get(urlA);
        expect(resumed.status()).toBe(200);
        const htmlResumed = await resumed.text();
        expect(htmlResumed).toContain('OTHER_MARKER');
        expect(htmlResumed, 'the corrected showif must apply to the unfinished session').toContain('DEP_MARKER');
        expect(htmlResumed).toContain(`name="session_id" value="${sessionA}"`);

        // Fix 2: clicking "Test Survey" again is a NEW test — back on page 1,
        // with a different unit session — not a resume of the old one.
        const urlB = await clickTestSurvey(admin);
        expect(urlB).not.toBe(urlA);
        const fresh = await participant.get(urlB);
        expect(fresh.status()).toBe(200);
        const htmlFresh = await fresh.text();
        expect(htmlFresh, 'a new Test Survey click must start on page 1').toContain('GATE_MARKER');
        const sessionB = (htmlFresh.match(/name="session_id" value="(\d+)"/) || [])[1];
        expect(sessionB).toBeTruthy();
        expect(sessionB).not.toBe(sessionA);

        await participant.dispose();
    });
});
