/**
 * Result coverage — two outcomes that need a setting changed first.
 *
 * `expired` — a Survey's X deadline (`survey_studies.expire_after`) passing.
 * The setting lives on the SURVEY, not the run, so the MCP's run structure
 * cannot carry it; the spec posts the survey settings form as an admin,
 * which is where a real admin sets it too. One real minute, then a real
 * queue pass.
 *
 * `email_skipped_user_disabled` — a participant switching emails off. The
 * run's settings page only offers that when formr knows their address, so
 * this fixture collects one with an `email` item instead of resolving the
 * recipient from an R literal like the other Email fixtures.
 *
 * Fixtures (MCP-built, exported to tests/e2e/fixtures/<run>.json):
 *   rescov-expire      10 Survey `rescov_expire`      20 Endpage
 *   rescov-mail-optout 10 Survey `rescov_optout` (email item)
 *                      20 Pause 600min  30 Email  40 Endpage
 *
 * See documentation/agent_doc/LOG_STATE_AUDIT.md.
 */
const { test, expect } = require('./helpers/test');
const { dbQuery, adminUserId } = require('./helpers/db');
const { runQueueOnce } = require('./helpers/queue');
const { ensureAdminState, STATE_PATH, ADMIN_BASE, sendToPosition } = require('./helpers/admin');
const { sessionRows, sessionTrace, runExists, recordObserved, enrolInOwnContext } = require('./helpers/results');

const STUDY_BASE = 'https://study.researchmixtape.com';

test.describe('result coverage — expiry and opt-out', () => {
    test.describe.configure({ mode: 'serial', timeout: 240 * 1000 });
    test.use({ storageState: STATE_PATH });

    test.beforeAll(async ({ browser }) => {
        await ensureAdminState(browser);
    });

    test('a Survey whose expire_after elapses is expired by the daemon', async ({ page, browser }) => {
        // NOT YET WORKING, and left in place because the half that works is
        // the informative half. Setting `expire_after` through the admin form
        // does stick (verified in the DB), but a freshly opened Survey then
        // still gets `expires = NULL` and `queued = 0`, so no daemon pass ever
        // considers it and the row stays `survey_started`. So X alone does not
        // arm the row on first render — expire_after is the INACTIVITY timer,
        // and inactivity is measured from an item display/answer this
        // participant never produces by merely opening the page.
        //
        // The existing expiry suite (tests/e2e/survey-expiry-*.spec.js) drives
        // this through bin/expiry_fixture.php, which provisions the unit
        // session directly — the PHP-fixture pattern this suite is moving away
        // from. Reaching `expired` the browser way needs a participant who
        // answers an item and then goes quiet, which is a longer scenario than
        // the rest of this file.
        test.skip(true, 'expire_after does not arm `expires` on first render — see the comment');
        test.skip(!runExists('rescov-expire'),
            'fixture run \'rescov-expire\' is missing — rebuild it from '
            + 'tests/e2e/fixtures/rescov-expire.json via the run editor\'s Import button');

        // Set X = 1 minute through the survey settings form. Done every run
        // rather than baked into the fixture, because a run export cannot
        // carry a survey-level setting — leaving it implicit is how a fixture
        // silently stops testing what its name claims.
        //
        // Filled and submitted in the page rather than POSTed as a couple of
        // fields: AdminSurveyController::changeSettings validates
        // `$settings['displayed_percentage_maximum'] < 1` outside its own
        // isset() guard (&& binds tighter than ||), so an absent key reads as
        // null < 1 — true — and the whole save is refused. A partial post
        // therefore saves nothing and says so only in an alert. The real form
        // submits every field, so the spec does too.
        await page.goto(`${ADMIN_BASE}/admin/survey/rescov_expire`);
        // The number input is wrapped by a spinner widget that hides it behind
        // a text proxy, so fill() waits forever on an element that is in the
        // DOM but never visible. Write through the real input and fire the
        // change event the widget listens for; the submit below is still the
        // page's own form posting to its own endpoint.
        await page.$eval('input[name=expire_after]', (el) => {
            el.value = '1';
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await page.click('form button[type=submit]');
        await page.waitForLoadState('networkidle');
        const study = dbQuery(`SELECT expire_after FROM survey_studies WHERE user_id = ${adminUserId()} AND name = 'rescov_expire' ORDER BY id DESC LIMIT 1;`);
        test.skip(parseInt(study[0].expire_after, 10) !== 1,
            'could not set expire_after through the admin form — the settings '
            + 'allowlist or the form fields changed');

        // Open the survey and walk away without submitting.
        const participant = await enrolInOwnContext(browser, 'rescov-expire');
        await participant.ctx.close();
        expect(sessionTrace('rescov-expire')).toEqual([[10, 'survey_started']]);

        await new Promise((r) => setTimeout(r, 75 * 1000));
        expect(runQueueOnce().ok, 'the queue worker must run').toBe(true);
        runQueueOnce();

        const survey = sessionRows('rescov-expire').find((r) => r.position === 10);
        expect(survey.result,
            'expire() labels the row; note it records no reason — the X/Y/Z '
            + 'matrix computes WHICH rule fired and discards it (audit, Survey)')
            .toBe('expired');

        recordObserved(['expired']);
    });

    test('a participant who switches emails off is skipped, and the run moves on', async ({ page, browser }) => {
        test.skip(!runExists('rescov-mail-optout'),
            'fixture run \'rescov-mail-optout\' is missing — rebuild it from '
            + 'tests/e2e/fixtures/rescov-mail-optout.json via the run editor\'s Import button');

        // Participant reports an address, then parks on the Pause.
        const p = await enrolInOwnContext(browser, 'rescov-mail-optout');
        await p.page.fill('input[name=contact_email], input[type=email]', 'rescov-optout@invalid.invalid');
        await p.page.click('button[type=submit], input[type=submit]');
        await p.page.waitForLoadState('networkidle');

        const code = dbQuery(`
            SELECT rs.session AS session FROM survey_run_sessions rs
              JOIN survey_runs r ON r.id = rs.run_id
             WHERE r.name = 'rescov-mail-optout' ORDER BY rs.id DESC LIMIT 1;
        `)[0].session;

        // They opt out on the run's own settings page. no_email = 0 is the
        // "do not send me email" value (RunSession::isEmailScheduled reads it
        // that way); the form only appears because the survey above gave
        // formr an address to show them.
        await p.page.goto(`${STUDY_BASE}/rescov-mail-optout/settings?code=${encodeURIComponent(code)}`);
        await p.page.waitForLoadState('networkidle');
        const hasForm = await p.page.locator('select[name=no_email]').count();
        test.skip(!hasForm, 'the settings page did not offer the email subscription control');
        await p.page.selectOption('select[name=no_email]', '0');
        await p.page.click('button[type=submit]');
        await p.page.waitForLoadState('networkidle');
        await p.ctx.close();

        // Send them into the Email. The admin button is the shortest honest
        // route to it; the Pause is only there to hold them still.
        const res = await sendToPosition(page, 'rescov-mail-optout', code, 30);
        expect(res.status(), await res.text()).toBe(200);

        const email = sessionRows('rescov-mail-optout').filter((r) => r.position === 30).pop();
        expect(email.result,
            'v1.8.0: this branch also advances the run. Before, it returned '
            + 'content only, so the row stayed queued=0 and invisible to the '
            + 'daemon — every opted-out participant parked here until the '
            + 'stalled-session sweep healed them.')
            .toBe('email_skipped_user_disabled');
        expect(email.log).toMatch(/disabled receiving emails/i);

        recordObserved(['email_skipped_user_disabled']);
    });
});
