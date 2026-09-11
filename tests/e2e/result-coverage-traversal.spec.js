/**
 * Result coverage — units a participant traverses without submitting anything.
 *
 * Skips, Shuffle, Pause, Wait and the Endpage: every result here is produced
 * by a plain GET on the run, so one enrolment per fixture drives the whole
 * chain. Nine catalogue entries live in this file.
 *
 * Each fixture is a run built through the formr MCP — the admin API — and
 * exported to tests/e2e/fixtures/<run>.json. To rebuild one, import that file
 * with the run editor's Import button, or replay it through the MCP:
 *
 *   create_run('rescov-skips')
 *   get_run_structure_to_file / add_run_unit / update_run_structure_from_file
 *   update_run_settings('rescov-skips', {public: 2})
 *
 * `public: 2` is load-bearing: it lets a fresh browser context enrol without
 * a code, which is what makes these plain participant traversals.
 *
 * Assertions are on `survey_unit_sessions`, not on the page. The defect class
 * this suite exists for is a result the code computes and the row never
 * receives, so a rendered page proves nothing. Where routing is the claim
 * ("the skip jumped over position 30"), the assertion is the absence of a row
 * at that position — a label alone cannot show where a participant went.
 *
 * See documentation/agent_doc/LOG_STATE_AUDIT.md.
 */
const { test, expect } = require('./helpers/test');
const { dbQuery } = require('./helpers/db');
const { sessionRows, sessionTrace, runExists, recordObserved, enrol } = require('./helpers/results');

function requireFixture(runName) {
    test.skip(!runExists(runName),
        `fixture run '${runName}' is missing — rebuild it from `
        + `tests/e2e/fixtures/${runName}.json via the run editor's Import button`);
}

test.describe('result coverage — participant traversal', () => {
    test('skips: false, true, computed, computed-with-no-unit, then the endpage', async ({ page, context }) => {
        requireFixture('rescov-skips');
        await enrol(page, context, 'rescov-skips');

        const trace = sessionTrace('rescov-skips');
        expect(trace).toEqual([
            [10, 'skip_false'],       // FALSE -> fall through to 20
            [20, 'skip_true'],        // TRUE  -> jump to 40, over 30
            [40, 'skip_to'],          // returns 60 -> computed jump, over 50
            [60, 'skip_to_invalid'],  // returns 999, no unit -> continue to 70
            [70, 'ended'],
        ]);

        // The jumps, stated as the absence of the units jumped over. Positions
        // 30 and 50 exist in no other assertion: if a skip silently fell
        // through instead of jumping, the labels above would be unchanged and
        // only this would fail.
        const visited = trace.map(([pos]) => pos);
        expect(visited, 'skip_true must jump over position 30').not.toContain(30);
        expect(visited, 'the computed jump must go over position 50').not.toContain(50);

        // v1.8.0 records which of the two readings a numeric condition got,
        // because that is exactly where an author can now be surprised.
        const rows = sessionRows('rescov-skips');
        expect(rows.find((r) => r.position === 40).log).toContain('60');
        expect(rows.find((r) => r.position === 60).log).toMatch(/999/);

        recordObserved(trace.map(([, result]) => result));
    });

    test('a skip whose condition cannot evaluate records error_opencpu_r and halts', async ({ page, context }) => {
        requireFixture('rescov-skiperr');
        await enrol(page, context, 'rescov-skiperr');

        const rows = sessionRows('rescov-skiperr');
        expect(rows.map((r) => [r.position, r.result])).toEqual([[10, 'error_opencpu_r']]);
        // The R error itself must reach the row — the whole point of the
        // Branch fix in 25cbcc53 was that it did not.
        expect(rows[0].log).toMatch(/could not find function/i);

        recordObserved(['error_opencpu_r']);
    });

    test('a skip whose condition returns NA halts like an R error instead of continuing', async ({ page, context }) => {
        // v1.10.3: NA decodes to null — the same value a failed evaluation
        // returns — so it halted as "OpenCPU error. Fix R code" with nothing
        // after it, while the unit's Test view said "continue". The row must
        // now name NA and say how to fix the condition.
        requireFixture('rescov-skipna');
        await enrol(page, context, 'rescov-skipna');

        const rows = sessionRows('rescov-skipna');
        expect(rows.map((r) => [r.position, r.result]), 'no row at 20: the skip must not fall through')
            .toEqual([[10, 'error_opencpu_r']]);
        expect(rows[0].log).toMatch(/returned NA/);

        recordObserved(['error_opencpu_r']);
    });

    test('Shuffle records the group it assigned, and the row agrees with the shuffle table', async ({ page, context }) => {
        requireFixture('rescov-shuffle');
        await enrol(page, context, 'rescov-shuffle');

        const trace = sessionTrace('rescov-shuffle');
        expect(trace.length).toBe(2);
        expect(trace[0][0]).toBe(10);
        expect(trace[0][1], 'group_N, one per configured group').toMatch(/^group_[12]$/);
        expect(trace[1]).toEqual([20, 'ended']);

        // X2 in the audit: the v1 API reads the group from `sus.result` while
        // the admin randomisation page reads `shuffle`.`group`. Two value
        // spaces with nothing keeping them in sync — so pin that they agree
        // for a fresh assignment. If a later change writes any other reason
        // into `result` before the participant returns, this fails and names
        // the API's copy as the casualty.
        // shuffle.session_id is the UNIT session, not the run session.
        const authoritative = dbQuery(`
            SELECT sh.\`group\` AS grp
              FROM shuffle sh
              JOIN survey_unit_sessions us ON us.id = sh.session_id
              JOIN survey_run_sessions rs  ON rs.id = us.run_session_id
              JOIN survey_runs r           ON r.id  = rs.run_id
             WHERE r.name = 'rescov-shuffle'
               AND rs.id = (SELECT MAX(rs2.id) FROM survey_run_sessions rs2
                             WHERE rs2.run_id = r.id)
             LIMIT 1;
        `);
        if (authoritative.length) {
            expect(`group_${authoritative[0].grp}`,
                'the API reads result, the admin reads shuffle.group — they must agree')
                .toBe(trace[0][1]);
        }

        recordObserved([trace[0][1], 'ended']);
    });

    test('a Pause with a future deadline parks the participant as pause_waiting', async ({ page, context }) => {
        requireFixture('rescov-pause');
        await enrol(page, context, 'rescov-pause');

        // Nothing after it: the endpage at 20 is only reachable once the
        // deadline passes, which is the queue lane's scenario, not this one.
        expect(sessionTrace('rescov-pause')).toEqual([[10, 'pause_waiting']]);

        recordObserved(['pause_waiting']);
    });

    test('a parked Wait ends on any participant request and jumps to its body position', async ({ page, context }) => {
        requireFixture('rescov-wait');
        await enrol(page, context, 'rescov-wait');

        const trace = sessionTrace('rescov-wait');
        expect(trace).toEqual([
            [10, 'wait_ended_by_user'],
            [30, 'ended'],
        ]);
        // Position 20 holds a Pause that nothing should reach: a pause_waiting
        // row there would mean the Wait advanced by one unit instead of
        // jumping to `body`, which is the shape of the field report behind the
        // v1.8.0 service-worker fix (position 50 -> 80, skipping 60 and 70).
        expect(trace.map(([pos]) => pos), 'the Wait must jump to body, not move on')
            .not.toContain(20);

        // v1.8.0 records the request shape that ended it, so an unattended
        // executor can be named from data rather than re-derived.
        const wait = sessionRows('rescov-wait').find((r) => r.position === 10);
        expect(wait.log).toMatch(/Sec-Fetch-Dest=document/);

        recordObserved(['wait_ended_by_user', 'ended']);
    });
});
