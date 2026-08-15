/**
 * Result coverage — what an admin's own buttons write onto a participant's row.
 *
 * Also the home of this suite's NEGATIVE assertions. The catalogue declares
 * `manual_admin_push` unreachable, and the audit says why: `forceTo()` calls
 * `end()` (which stamps `ended = NOW()`) and only then assigns the label and
 * calls `logResult()`, whose `AND ended IS NULL` guard matches zero rows. An
 * unreachability claim nobody drives is a claim nobody checks, so this drives
 * the exact path and fails if the label ever appears.
 *
 * That same path is finding X1: `end()` on a Survey also stamps
 * `results_table.ended` and fires `StudyMetrics::recordCompletion()`, so an
 * admin "send to position" on someone parked mid-survey manufactures a row
 * that analysis counts as a completion. This spec pins the observable half —
 * a force-moved Survey records `survey_ended`, a genuine completion records
 * `survey_completed` (see result-coverage-survey.spec.js) — so the day X1 is
 * fixed, the fix has something to move.
 *
 * Fixture (MCP-built, exported to tests/e2e/fixtures/rescov-admin.json):
 *   10 Survey `rescov_pages`   20 Pause 600min   30 Endpage
 *
 * One participant per test: these actions are terminal for the row they touch.
 *
 * See documentation/agent_doc/LOG_STATE_AUDIT.md.
 */
const { test, expect } = require('./helpers/test');
const { dbQuery } = require('./helpers/db');
const { ensureAdminState, STATE_PATH, sendToPosition, nextInRun } = require('./helpers/admin');
const { sessionRows, sessionTrace, runExists, recordObserved, enrolInOwnContext } = require('./helpers/results');

const RUN = 'rescov-admin';

/** The code of the run session a participant just created. */
function newestSession(runName) {
    const rows = dbQuery(`
        SELECT rs.session AS session FROM survey_run_sessions rs
          JOIN survey_runs r ON r.id = rs.run_id
         WHERE r.name = '${runName}' ORDER BY rs.id DESC LIMIT 1;
    `);
    return rows.length ? rows[0].session : null;
}

/** Every result this run has EVER recorded — the scope a negative needs. */
function allResultsEver(runName) {
    return dbQuery(`
        SELECT DISTINCT us.result AS result FROM survey_unit_sessions us
          JOIN survey_run_sessions rs ON rs.id = us.run_session_id
          JOIN survey_runs r ON r.id = rs.run_id
         WHERE r.name = '${runName}' AND us.result IS NOT NULL;
    `).map((r) => r.result);
}

test.describe('result coverage — admin actions', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: STATE_PATH });

    test.beforeAll(async ({ browser }) => {
        test.skip(!runExists(RUN),
            `fixture run '${RUN}' is missing — rebuild it from `
            + `tests/e2e/fixtures/${RUN}.json via the run editor's Import button`);
        await ensureAdminState(browser);
    });

    test('force-moving a participant mid-survey records survey_ended, never manual_admin_push', async ({ page, browser }) => {
        // The participant needs their OWN context: `page` here is the admin's,
        // and clearing its cookies to enrol would log the admin out — after
        // which every admin POST lands on the login page, returns 200, and
        // does nothing.
        const participant = await enrolInOwnContext(browser, RUN);
        await participant.ctx.close();
        expect(sessionTrace(RUN)).toEqual([[10, 'survey_started']]);
        const session = newestSession(RUN);

        // The admin's "send this user to that position" button.
        const res = await sendToPosition(page, RUN, session, 20);
        expect(res.status(), await res.text()).toBe(200);

        const survey = sessionRows(RUN).find((r) => r.position === 10);
        expect(survey.result,
            'end() falls back to the unit type default because the Survey logged '
            + 'nothing on this pass — nobody submitted anything')
            .toBe('survey_ended');

        // The negative. forceTo() tries to write manual_admin_push AFTER end()
        // has already set `ended`, so logResult()'s `ended IS NULL` guard
        // matches no rows and the label has never existed in the database, for
        // any unit type. If this starts failing, the audit finding is fixed —
        // update tests/fixtures/result_catalogue.php in the same change.
        expect(allResultsEver(RUN),
            'X1: forceTo() calls end() first, so its own label can never land')
            .not.toContain('manual_admin_push');

        recordObserved(['survey_ended']);
    });

    test('advancing a parked participant records moved', async ({ page, browser }) => {
        const participant = await enrolInOwnContext(browser, RUN);
        await participant.ctx.close();
        const session = newestSession(RUN);

        // Get them onto the Pause, then use "next in run" — which goes through
        // forceMoveOn('moved'), the lock-taking path the F3 audit item added.
        await sendToPosition(page, RUN, session, 20);
        expect(sessionRows(RUN).filter((r) => r.position === 20).pop().result)
            .toBe('pause_waiting');

        const res = await nextInRun(page, RUN, session);
        expect(res.status(), await res.text()).toBe(200);

        expect(sessionRows(RUN).filter((r) => r.position === 20).pop().result,
            'an operator-forced advance is labelled as one, not as the unit\'s '
            + 'own outcome').toBe('moved');

        expect(allResultsEver(RUN)).not.toContain('manual_admin_push');

        recordObserved(['moved']);
    });
});
