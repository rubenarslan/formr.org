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
const { sessionRows, sessionTrace, runExists, recordObserved, enrol, enrolInOwnContext } = require('./helpers/results');

const STUDY = 'https://study.researchmixtape.com';

/**
 * A structurally valid subscription pointing at a host that resolves nowhere.
 * Stored through the participant's OWN endpoint
 * (/<run>/ajax_save_push_subscription), which is how the browser saves a real
 * one — no row is written by hand.
 */
const BROKEN_SUBSCRIPTION = JSON.stringify({
    endpoint: 'https://fcm.invalid/fcm/send/rescov-unreachable',
    expirationTime: null,
    keys: { p256dh: 'BF7I2OK1fRTR4dN04' + 'A'.repeat(70), auth: 'cmVzY292LWJyb2tlbg' },
});

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


/**
 * `error` — a push send that raises.
 *
 * This is the entry the audit listed as "PushMessage `error` writes no row at
 * all", suspected to be the same Error-vs-Exception shape as the Branch
 * TypeError. It was: PushNotificationService::getVapidPrivateKey() passed a
 * NULL vapid_private_key straight into Crypto::decrypt(), which types its
 * argument as string, so a run that never generated a PWA manifest raised a
 * TypeError — an Error, not an Exception. It blew past the constructor's own
 * "VAPID keys not found" guard, past PushMessage's catch (Exception), and past
 * the queue's, leaving a 500 and a NULL result. Fixed in v1.8.0.
 *
 * The fixture is deliberately a run WITHOUT VAPID keys, because that is the
 * ordinary authoring mistake — adding a PushMessage before generating the
 * manifest — rather than a contrived failure.
 *
 * rescov-pusherr: 5 Survey (rescov_contact2, for the push item whose answer
 * IS the subscription)  10 Pause  20 PushMessage  30 Endpage
 */
test.describe('result coverage — a push send that raises', () => {
    test.use({ storageState: STATE_PATH });

    test.beforeAll(async ({ browser }) => {
        test.skip(!runExists('rescov-pusherr'), 'fixture run \'rescov-pusherr\' is missing');
        await ensureAdminState(browser);
    });

    test('records error rather than crashing the request', async ({ page, browser }) => {
        const p = await enrolInOwnContext(browser, 'rescov-pusherr');
        // Complete the survey so its push item has been displayed —
        // updateSubscription() writes into that item's row and returns false
        // if there is none, which is why the fixture carries a survey at all.
        await p.page.evaluate(() => {
            const e = document.querySelector('[name="contact_email"]');
            if (e) { e.value = 'rescov-pusherr@invalid.invalid'; e.dispatchEvent(new Event('change', { bubbles: true })); }
        });
        await Promise.all([
            p.page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
            p.page.evaluate(() => { const b = document.querySelector('button[type=submit], input[type=submit]'); if (b) b.click(); }),
        ]);

        const saved = await p.page.request.post(
            `${STUDY}/rescov-pusherr/ajax_save_push_subscription`,
            { form: { subscription: BROKEN_SUBSCRIPTION } }
        );
        expect(saved.status(), await saved.text()).toBe(200);
        await p.ctx.close();

        const code = dbQuery(`
            SELECT rs.session AS session FROM survey_run_sessions rs
              JOIN survey_runs r ON r.id = rs.run_id
             WHERE r.name = 'rescov-pusherr' ORDER BY rs.id DESC LIMIT 1;
        `)[0].session;

        // Send them into the PushMessage. Before the fix this answered 500.
        const res = await sendToPosition(page, 'rescov-pusherr', code, 20);
        expect(res.status(), 'a failing push must not fatal the request').toBe(200);

        const push = sessionRows('rescov-pusherr').filter((r) => r.position === 20).pop();
        expect(push.result, 'the failure has to reach the row — that is this whole suite\'s subject')
            .toBe('error');
        expect(push.log).toMatch(/VAPID/i);

        // And the run keeps moving rather than parking on a dead unit.
        expect(sessionTrace('rescov-pusherr').map(([, r]) => r)).toContain('ended');

        recordObserved(['error']);
    });
});
