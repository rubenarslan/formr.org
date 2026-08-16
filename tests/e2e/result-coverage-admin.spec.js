/**
 * Result coverage — what an admin's own buttons write onto a participant's row.
 *
 * This file drove audit finding X1 before and after its fix, and the
 * assertions changed direction as a result.
 *
 * BEFORE: `forceTo()` called `end()` — which stamps `ended = NOW()` — and only
 * then assigned `manual_admin_push` and called `logResult()`, whose
 * `AND ended IS NULL` guard matched zero rows. The label had never once
 * existed in the database. Worse, `end()` on a Survey also stamps
 * `results_table.ended` and fires `StudyMetrics::recordCompletion()`, so an
 * admin "send to position" on someone parked mid-survey manufactured a row
 * that analysis counts as a completion — permanently, and in bulk through
 * `positionSessions()`.
 *
 * AFTER (v1.8.0): the reason is passed INTO `end()`, so it lands; and `end()`
 * takes a `$completes` flag that the two operator paths (`forceTo`,
 * `forceMoveOn`) set false, so a forced advance no longer finishes anyone's
 * survey. Both are asserted below — the label, and the results row staying
 * unfinished, which is the half that reaches published numbers.
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
const STUDY = 'rescov_pages';

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

    let resultsTable = null;

    test.beforeAll(async ({ browser }) => {
        const st = dbQuery(`SELECT results_table FROM survey_studies WHERE name = '${STUDY}' ORDER BY id DESC LIMIT 1;`);
        resultsTable = st.length ? st[0].results_table : null;
        test.skip(!runExists(RUN),
            `fixture run '${RUN}' is missing — rebuild it from `
            + `tests/e2e/fixtures/${RUN}.json via the run editor's Import button`);
        await ensureAdminState(browser);
    });

    test('force-moving a participant mid-survey records manual_admin_push, not a completion', async ({ page, browser }) => {
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
            'v1.8.0 (audit X1): the operator\'s own label now lands. It used to '
            + 'be assigned AFTER end() had set `ended`, so logResult()\'s '
            + '`ended IS NULL` guard matched zero rows and manual_admin_push had '
            + 'never once existed in the database — leaving a forced advance '
            + 'indistinguishable from the unit\'s own ending.')
            .toBe('manual_admin_push');

        // The invariant that matters more than the label: a force-move must not
        // be counted as a completion. end() stamps the results row's `ended`
        // and fires StudyMetrics::recordCompletion(), and `ended IS NOT NULL`
        // is how analysis counts finishers — so this used to inflate a study's
        // completion count and fold a non-completion into its geometric-mean
        // duration, permanently, in bulk via positionSessions().
        const results = dbQuery(`
            SELECT ended FROM \`${resultsTable}\`
             WHERE session_id = ${survey.id} LIMIT 1;
        `);
        if (results.length) {
            expect(results[0].ended,
                'an admin force-move must leave the results row unfinished')
                .toBeNull();
        }

        recordObserved(['manual_admin_push']);
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

        recordObserved(['moved']);
    });
});
