/**
 * Browser lane of the result-coverage suite.
 *
 * bin/test_result_coverage_smoke.php drives every unit it can from PHP, but
 * four results belong to Survey and cannot be reached that way:
 * survey_started, survey_filling_out, survey_ended and error_survey need a
 * real multi-page form POST carrying the paging token, plus a real
 * survey_studies row with items and a results table.
 *
 * There is a second reason this lane exists rather than being folded into
 * the PHP harness. That harness has to call asParticipant() to force
 * `cron = false`, because Model::boot() derives it from
 * Site::runningInConsole(); it therefore *simulates* a participant request.
 * A browser IS one. So this spec independently validates the assumption the
 * PHP harness asserts — if the two ever disagree about a web-side result,
 * the browser is right.
 *
 * Assertions read survey_unit_sessions.result out of the database rather
 * than inspecting the page, because the defect class this whole suite came
 * from is a result the code computes and the row never receives. A green
 * page proves nothing about the row.
 *
 * See documentation/agent_doc/LOG_STATE_AUDIT.md and
 * tests/fixtures/result_catalogue.php.
 */
const { test, expect } = require('./helpers/test');
const { dbQuery, dbExecRaw } = require('./helpers/db');

/**
 * A shared fixture run: Survey at 10 (an `email` item, a `push_notification`
 * item, two submit buttons so there are two pages), Endpage at 20.
 *
 * Built and maintained through the formr MCP rather than by driving the
 * admin upload form in beforeAll — the form is not a thing this spec is
 * trying to test, and reproducing it in Playwright made the fixture the
 * most fragile part of the file. Recreate with:
 *
 *   create_run('rescov-fixture')
 *   get_run_structure_to_file / add_run_unit / update_run_structure_from_file
 *
 * The structure is checked in at tests/e2e/fixtures/rescov-fixture.json.
 *
 * Pagination comes from the ITEM ORDER, not from a page column: an item, a
 * `submit`, then another item gives two pages. An earlier version put two
 * items before the submit and a second submit after, which formr rendered
 * as a single page — and `page_no` is NULL either way, so editing that
 * column by hand does not paginate anything (it produces an incoherent
 * page instead). Page 1 here is the email item; page 2 is the push
 * permission, which is optional because headless Chromium cannot grant it.
 */
const RUN = 'rescov-fixture';
const STUDY = 'rescov_contact2';

/**
 * Distinct results for the NEWEST run session of this run. The fixture is
 * shared and re-entered by every test, so scoping to the whole run would
 * make each test see its predecessors' rows and pass for the wrong reason.
 */
function resultsFor(runName) {
    const rows = dbQuery(`
        SELECT DISTINCT us.result
          FROM survey_unit_sessions us
         WHERE us.run_session_id = (
                   SELECT rs.id FROM survey_run_sessions rs
                     JOIN survey_runs r ON r.id = rs.run_id
                    WHERE r.name = '${runName}' ORDER BY rs.id DESC LIMIT 1)
           AND us.result IS NOT NULL;
    `);
    return rows.map((r) => r.result);
}

test.describe('result coverage — Survey', () => {
    // MUST be serial. The error_survey test renames the study's results table
    // out from under the run, which is global state: run in parallel it breaks
    // the traversal test's page mid-flight, and the symptom is a fill() that
    // times out on an input a standalone browser finds visible.
    test.describe.configure({ mode: 'serial' });

    test.beforeAll(() => {
        const run = dbQuery(`SELECT id, public FROM survey_runs WHERE name = '${RUN}' LIMIT 1;`);
        test.skip(!run.length,
            `fixture run '${RUN}' is missing — recreate it via the formr MCP from `
            + `tests/e2e/fixtures/rescov-fixture.json`);
        // Link-accessible, so a fresh context can enrol without a code.
        dbExecRaw(`UPDATE survey_runs SET public = 2 WHERE name = '${RUN}';`);
    });

    test('a participant traversal records survey_started, survey_filling_out and survey_ended', async ({ page, context }) => {
        // Enrol as a participant, not as the admin: the results below are all
        // gated on !isExecutedByCron(), and only a genuine request clears it.
        await context.clearCookies();
        await page.goto(`https://study.researchmixtape.com/${RUN}/`);
        await page.waitForLoadState('networkidle');

        expect(resultsFor(RUN), 'first render must create the results row')
            .toContain('survey_started');

        // Fill the email item before submitting: it is required, so an empty
        // submit fails validation and never advances the page — and the
        // reported address is what makes the Email unit's recipient_field
        // (`rescov_contact$contact_email`) resolvable for the Email lane.
        await page.fill('input[name=contact_email], input[type=email]', 'rescov@example.org');

        // Submit page 1 of 2 -> still filling out.
        await page.click('button[type=submit], input[type=submit]');
        await page.waitForLoadState('networkidle');
        expect(resultsFor(RUN), 'a submitted page with more to come is survey_filling_out')
            .toContain('survey_filling_out');

        // Submit the last page -> end() stamps the type default.
        await page.click('button[type=submit], input[type=submit]');
        await page.waitForLoadState('networkidle');

        const results = resultsFor(RUN);
        expect(results,
            'v1.8.0: resolveEndResult() keeps the unit\'s own verdict, so a genuine '
            + 'completion records survey_completed. Before it, end() re-derived the '
            + 'result from the unit type and this was survey_ended — which is why a '
            + 'real completion and an admin force-push were indistinguishable.')
            .toContain('survey_completed');
        expect(results, 'the endpage ends the run session').toContain('ended');
    });

    test('a broken results table records error_survey', async ({ page, context }) => {
        // processStudy throws when the results table is gone; the unit catches
        // it and logs error_survey with the exception text. Renaming the table
        // is the least invasive way to produce a genuine failure — no code
        // path is stubbed, the exception is real.
        const study = dbQuery(`SELECT results_table FROM survey_studies WHERE name = '${STUDY}' ORDER BY id DESC LIMIT 1;`);
        test.skip(!study.length, `study '${STUDY}' is missing — see beforeAll`);
        const table = study[0].results_table;

        await context.clearCookies();
        dbExecRaw(`RENAME TABLE \`${table}\` TO \`${table}_hidden\`;`);
        try {
            await page.goto(`https://study.researchmixtape.com/${RUN}/`);
            await page.waitForLoadState('networkidle');
            expect(resultsFor(RUN)).toContain('error_survey');
        } finally {
            dbExecRaw(`RENAME TABLE \`${table}_hidden\` TO \`${table}\`;`);
        }
    });
});
