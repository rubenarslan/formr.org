/**
 * Result coverage — PushMessage.
 *
 * `no_subscription` runs anywhere. The other three need a REAL push
 * subscription, and a test cannot earn one: headless Chromium has no push
 * service, and Brave disables Google's by default (`brave://settings/privacy`
 * → "Use Google services for push messaging"), both giving
 * `AbortError: Registration failed - push service error`.
 *
 * So the subscription is provisioned once, by hand, in a real browser:
 *
 *   1. enable Google push messaging in Brave (or use Chrome)
 *   2. open https://study.researchmixtape.com/rescov-push/
 *   3. answer the survey and grant notification permission
 *
 * The subscription is the ANSWER to the survey's `push_notification` item
 * (RunSession::getSubscription reads survey_items_display), so it belongs to
 * that one run session — which is why this spec finds that session and
 * re-drives it, instead of enrolling a fresh participant like every other
 * spec here.
 *
 * Re-driving means the admin pushes the session back to position 20 and the
 * three PushMessage units run again — and unit 20 delivers a REAL
 * notification to whoever provisioned the subscription, every time. That is
 * why this file is opt-in:
 *
 *   RESCOV_PUSH=1 npx playwright test --config tests/e2e/playwright.config.js result-coverage-push
 *
 * Fixtures (MCP-built, exported to tests/e2e/fixtures/<run>.json):
 *   rescov-push      10 Survey (rescov_contact2: email item + push item)
 *                    20 PushMessage, plain text      -> sent
 *                    30 PushMessage, message `r ""`  -> message_parse_failed
 *                    40 PushMessage, topic   `r ""`  -> title_parse_failed
 *                    50 Endpage
 *   rescov-pushnone  10 PushMessage -> no_subscription, 20 Endpage
 *
 * See documentation/agent_doc/LOG_STATE_AUDIT.md.
 */
const { test, expect } = require('./helpers/test');
const { dbQuery } = require('./helpers/db');
const { ensureAdminState, STATE_PATH, sendToPosition } = require('./helpers/admin');
const { sessionRows, sessionTrace, runExists, recordObserved, enrol } = require('./helpers/results');

const STUDY = 'https://study.researchmixtape.com';

test('a participant who never subscribed records no_subscription', async ({ page, context }) => {
    test.skip(!runExists('rescov-pushnone'),
        'fixture run \'rescov-pushnone\' is missing — rebuild it from '
        + 'tests/e2e/fixtures/rescov-pushnone.json via the run editor\'s Import button');

    await enrol(page, context, 'rescov-pushnone');
    expect(sessionTrace('rescov-pushnone')).toEqual([
        [10, 'no_subscription'],
        [20, 'ended'],
    ]);

    recordObserved(['no_subscription', 'ended']);
});

test.describe('result coverage — PushMessage with a real subscription', () => {
    test.use({ storageState: STATE_PATH });
    test.skip(process.env.RESCOV_PUSH !== '1',
        'sends a real notification to whoever provisioned the subscription — set RESCOV_PUSH=1 to run');

    let SESSION = null;

    test.beforeAll(async ({ browser }) => {
        await ensureAdminState(browser);
        // The most recent session in the fixture run that holds a usable
        // subscription. Looked up rather than hardcoded: it is a live
        // participant code and does not belong in the repository.
        const rows = dbQuery(`
            SELECT rs.session AS session
              FROM survey_items_display sid
              JOIN survey_items si        ON si.id  = sid.item_id
              JOIN survey_unit_sessions us ON us.id = sid.session_id
              JOIN survey_run_sessions rs ON rs.id  = us.run_session_id
              JOIN survey_runs r          ON r.id   = rs.run_id
             WHERE r.name = 'rescov-push'
               AND si.type = 'push_notification'
               AND sid.answer NOT IN ('not_requested', 'not_supported', 'expired', 'ios_version_not_supported')
             ORDER BY sid.created DESC
             LIMIT 1;
        `);
        SESSION = rows.length ? rows[0].session : null;
    });

    test('sent, message_parse_failed and title_parse_failed', async ({ page }) => {
        test.skip(!SESSION, 'no session in rescov-push holds a push subscription — see the recipe above');

        // Rewind to the first PushMessage. This is the admin's own "send to
        // position" button, so the three units get fresh unit sessions and
        // re-execute; without it their idempotency guard (a terminal result
        // short-circuits a re-run) would replay nothing.
        const res = await sendToPosition(page, 'rescov-push', SESSION, 20);
        expect(res.status(), await res.text()).toBe(200);

        // Return as the participant. Their code, not a new enrolment: the
        // subscription lives on this run session.
        await page.goto(`${STUDY}/rescov-push/?code=${encodeURIComponent(SESSION)}`);
        await page.waitForLoadState('networkidle');

        const rows = sessionRows('rescov-push');
        const at = (pos) => rows.filter((r) => r.position === pos).pop();

        expect(at(20).result, 'a real push accepted by the push service').toBe('sent');
        expect(at(30).result, 'an empty knit of the message body drops the push')
            .toBe('message_parse_failed');
        expect(at(40).result, 'an empty knit of a title that was meant to knit')
            .toBe('title_parse_failed');

        // The two parse failures are terminal and the run advances past them,
        // so the row is the only place an admin can learn the participant was
        // skipped. title_parse_failed says so; message_parse_failed still
        // reports only to notify_study_admin, where the 10-minute throttle can
        // drop it. Asserted as it is, not as it should be — see the Push
        // findings in LOG_STATE_AUDIT.md.
        expect(at(40).log).toMatch(/title/i);

        recordObserved(['sent', 'message_parse_failed', 'title_parse_failed']);
    });
});
