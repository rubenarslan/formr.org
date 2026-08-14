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
const { adminLogin, createRun, deleteRun, ADMIN_BASE } = require('./helpers/admin');
const { dbQuery, dbExecRaw } = require('./helpers/db');
const path = require('path');

const RUN = 'rescovsurvey' + Date.now().toString(36);
const STUDY = 'rescov_study_' + Date.now().toString(36);
const SHEET = path.resolve(__dirname, '../../documentation/example_surveys/page1.xlsx');

/** Distinct result values recorded for this run's unit sessions. */
function resultsFor(runName) {
    const rows = dbQuery(`
        SELECT DISTINCT us.result
          FROM survey_unit_sessions us
          JOIN survey_run_sessions rs ON rs.id = us.run_session_id
          JOIN survey_runs r ON r.id = rs.run_id
         WHERE r.name = '${runName}' AND us.result IS NOT NULL;
    `);
    return rows.map((r) => r.result);
}

test.describe('result coverage — Survey', () => {
    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        await adminLogin(page);

        // A survey needs a real study: upload the smallest fixture that has
        // more than one page, so survey_filling_out is reachable.
        // The upload form is admin/survey/add_survey: `new_study` names the
        // study, `uploaded` takes the spreadsheet, `add_study` submits.
        await page.goto(ADMIN_BASE + '/admin/survey/add_survey');
        await page.fill('input[name=new_study]', STUDY);
        await page.setInputFiles('input[name=uploaded]', SHEET);
        await page.click('[name=add_study]');
        await page.waitForLoadState('networkidle');

        await createRun(page, RUN);
        await page.close();
    });

    test.afterAll(async ({ browser }) => {
        const page = await browser.newPage();
        await adminLogin(page);
        await deleteRun(page, RUN).catch(() => {});
        await page.close();
    });

    test('a participant traversal records survey_started, survey_filling_out and survey_ended', async ({ page, context }) => {
        // Enrol as a participant, not as the admin: the results below are all
        // gated on !isExecutedByCron(), and only a genuine request clears it.
        await context.clearCookies();
        await page.goto(`https://study.researchmixtape.com/${RUN}/`);
        await page.waitForLoadState('networkidle');

        expect(resultsFor(RUN), 'first render must create the results row')
            .toContain('survey_started');

        // Submit page 1 of 2 -> still filling out.
        await page.click('button[type=submit], input[type=submit]');
        await page.waitForLoadState('networkidle');
        expect(resultsFor(RUN), 'a submitted page with more to come is survey_filling_out')
            .toContain('survey_filling_out');

        // Submit the last page -> end() stamps the type default.
        await page.click('button[type=submit], input[type=submit]');
        await page.waitForLoadState('networkidle');

        const results = resultsFor(RUN);
        expect(results, 'the completed survey ends via end()').toContain('survey_ended');
        expect(results,
            'survey_completed is catalogued unreachable: the unit logs it and end() '
            + 'overwrites it with survey_ended in the same request. If this ever '
            + 'appears, update tests/fixtures/result_catalogue.php.')
            .not.toContain('survey_completed');
    });

    test('a broken results table records error_survey', async ({ page, context }) => {
        // processStudy throws when the results table is gone; the unit catches
        // it and logs error_survey with the exception text. Renaming the table
        // is the least invasive way to produce a genuine failure — no code
        // path is stubbed, the exception is real.
        const study = dbQuery(`SELECT results_table FROM survey_studies WHERE name = '${STUDY}' LIMIT 1;`);
        test.skip(!study.length, 'study fixture not created');
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
