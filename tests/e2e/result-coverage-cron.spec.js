/**
 * Result coverage — three more outcomes only a daemon or a maintenance job
 * writes. Companion to result-coverage-queue.spec.js, split off because
 * these need a second real minute each.
 *
 * `waiting_deprecated` — the legacy `automatically_jump = 0` skip. It only
 * appears under cron, and a skip is only reached under cron if the
 * participant was ALREADY parked when the daemon advanced them — hence the
 * Pause in front of it, same shape as the Wait fixture.
 *
 * `swept_stale` — bin/sweep_stalled_unit_sessions.php, stamping a row that
 * has been live with a NULL result for too long. The NULL row is not
 * contrived: when the daemon ends a Pause it creates the successor and, for
 * a Survey, cannot execute it (a Survey needs a participant), so the row
 * sits there with no result at all. That is the shape the sweep exists for.
 * `--min-age-minutes=1 --run-id=N` scopes it to this fixture and to a
 * one-minute stall instead of the default thirty.
 *
 * `expired` — a Survey's X deadline. `expire_after` is the INACTIVITY
 * timer, so opening the survey is not enough: the participant has to ANSWER
 * something and then go quiet. This is why the earlier attempt (in
 * result-coverage-expiry-optout.spec.js) recorded `expires = NULL` and gave
 * up — the fixture is now two pages, and the participant submits the first.
 *
 * Fixtures (MCP-built, exported to tests/e2e/fixtures/<run>.json):
 *   rescov-skipcron  10 Pause 1min  20 SkipForward automatically_jump=0  40 Endpage
 *   rescov-sweep     10 Pause 1min  20 Survey (never executed)           30 Endpage
 *   rescov-expire    10 Survey `rescov_expire` (q1, submit, q2)          20 Endpage
 *
 * See documentation/agent_doc/LOG_STATE_AUDIT.md.
 */
const { execFileSync } = require('node:child_process');
const { test, expect } = require('./helpers/test');
const { dbQuery, adminUserId } = require('./helpers/db');
const { runQueueOnce } = require('./helpers/queue');
const { ensureAdminState, STATE_PATH, ADMIN_BASE } = require('./helpers/admin');
const { sessionRows, sessionTrace, runExists, recordObserved, enrol, enrolInOwnContext } = require('./helpers/results');

const MINUTE_PLUS_MARGIN = 75 * 1000;

function drainQueue() { const first = runQueueOnce(); runQueueOnce(); return first; }

/** The real maintenance job, scoped to one run and a one-minute stall. */
function sweep(runId) {
    return execFileSync('docker', ['exec', '-i', 'formr_app', 'php',
        '/var/www/formr/bin/sweep_stalled_unit_sessions.php',
        '--min-age-minutes=1', `--run-id=${runId}`], { encoding: 'utf8' });
}

function runId(name) {
    const rows = dbQuery(`SELECT id FROM survey_runs WHERE name = '${name}' LIMIT 1;`);
    return rows.length ? parseInt(rows[0].id, 10) : null;
}

test.describe('result coverage — daemon and maintenance', () => {
    test.describe.configure({ timeout: 300 * 1000 });

    test('a legacy automatically_jump=0 skip reached by cron records waiting_deprecated', async ({ page, context }) => {
        test.skip(!runExists('rescov-skipcron'), 'fixture run \'rescov-skipcron\' is missing');

        await enrol(page, context, 'rescov-skipcron');
        expect(sessionTrace('rescov-skipcron')).toEqual([[10, 'pause_waiting']]);

        await new Promise((r) => setTimeout(r, MINUTE_PLUS_MARGIN));
        drainQueue();

        const skip = sessionRows('rescov-skipcron').filter((r) => r.position === 20).pop();
        expect(skip, 'the daemon must advance them into the skip').toBeTruthy();
        expect(skip.result,
            'a skip that waits for the user cannot act under cron, so it records '
            + 'that it is waiting rather than jumping')
            .toBe('waiting_deprecated');

        recordObserved(['waiting_deprecated']);
    });

    test('a unit session left live with no result is stamped swept_stale', async ({ page, context }) => {
        // NOT YET WORKING. `swept_stale` is written by COALESCE(result, …), so
        // it needs a row that is live, unqueued, and carries NO result at all.
        // Such rows exist in production — on this dev instance alone there are
        // 26 Survey, 28 PushMessage and 5 Pause of them — but nothing a
        // participant or admin can DO produces one:
        //
        //   * the cron cascade does execute the unit it creates (a Survey
        //     reached that way records survey_started, not NULL);
        //   * aborting the request mid-execution does not help either: PHP
        //     runs to completion after the client disconnects, so the unit
        //     still logs — tried with a Pause whose relative_to sleeps 3s in
        //     R, and all three attempts recorded pause_waiting, queued = 2.
        //
        // So the rows the sweep exists for come from executions that were
        // interrupted at the process level, not at the request level. Reaching
        // this deliberately means killing PHP mid-execute, which is a
        // different kind of test from the rest of this suite.
        test.skip(true, 'no participant or admin action leaves a live NULL-result row — see the comment');
        test.skip(!runExists('rescov-sweep'), 'fixture run \'rescov-sweep\' is missing');

        await enrol(page, context, 'rescov-sweep');
        await new Promise((r) => setTimeout(r, MINUTE_PLUS_MARGIN));
        drainQueue();

        // The daemon ended the Pause and created the Survey row. It cannot
        // execute a Survey, so that row is live with result NULL — exactly
        // what COALESCE(result, 'swept_stale') is written for.
        const created = sessionRows('rescov-sweep').filter((r) => r.position === 20).pop();
        expect(created, 'the cascade must create the Survey row').toBeTruthy();
        expect(created.result, 'and leave it with no result of its own').toBeNull();

        // Let it go stale, then run the real sweep.
        await new Promise((r) => setTimeout(r, MINUTE_PLUS_MARGIN));
        const out = sweep(runId('rescov-sweep'));
        expect(out).toBeTruthy();

        const swept = sessionRows('rescov-sweep').filter((r) => r.position === 20).pop();
        expect(swept.result,
            'the sweep only stamps rows that have no result, so it never '
            + 'overwrites a unit\'s own verdict')
            .toBe('swept_stale');

        recordObserved(['swept_stale']);
    });

    test('a Survey left inactive past expire_after is expired', async ({ page, browser }) => {
        test.skip(!runExists('rescov-expire'), 'fixture run \'rescov-expire\' is missing');

        // X = 1 minute, set through the survey settings form (see
        // result-coverage-expiry-optout.spec.js for why the whole form is
        // submitted rather than two fields).
        const admin = await browser.newContext({ storageState: STATE_PATH });
        const adminPage = await admin.newPage();
        await adminPage.goto(`${ADMIN_BASE}/admin/survey/rescov_expire`);
        await adminPage.$eval('input[name=expire_after]', (el) => {
            el.value = '1';
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await adminPage.click('form button[type=submit]');
        await adminPage.waitForLoadState('networkidle');
        await admin.close();

        const study = dbQuery(`SELECT expire_after FROM survey_studies WHERE user_id = ${adminUserId()} AND name = 'rescov_expire' ORDER BY id DESC LIMIT 1;`);
        test.skip(parseInt(study[0].expire_after, 10) !== 1, 'could not set expire_after through the admin form');

        // ANSWER page 1, then go quiet. Two things are load-bearing here.
        // Merely opening the survey leaves `expires` NULL, because Z applies
        // only POST-access. And "access" is not the first render: getExpiry
        // treats the participant as started only when an items_display.saved
        // lands MORE THAN 2 SECONDS after the invitation — deliberately, so
        // that auto-filled items (browser, IP) saved synchronously with the
        // results row do not count as someone starting to edit. Submitting
        // immediately therefore leaves the row unarmed, which is exactly how
        // the first attempt at this test failed.
        const p = await enrolInOwnContext(browser, 'rescov-expire');
        await new Promise((r) => setTimeout(r, 4000));
        await p.page.fill('input[name=q1]', 'answered');
        await p.page.click('button[type=submit], input[type=submit]');
        await p.page.waitForLoadState('networkidle');
        await p.ctx.close();
        expect(sessionTrace('rescov-expire')).toEqual([[10, 'survey_filling_out']]);

        await new Promise((r) => setTimeout(r, MINUTE_PLUS_MARGIN));
        drainQueue();

        const survey = sessionRows('rescov-expire').filter((r) => r.position === 10).pop();
        expect(survey.result,
            'expire() labels the row — note it records no reason: the X/Y/Z '
            + 'matrix computes WHICH rule fired and discards it (audit, Survey)')
            .toBe('expired');

        recordObserved(['expired']);
    });
});
