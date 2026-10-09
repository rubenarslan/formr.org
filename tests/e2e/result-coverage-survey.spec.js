/**
 * Result coverage — Survey.
 *
 * Four catalogue entries that need a real form POST carrying the paging
 * token, a real `survey_studies` row with items, and a real results table.
 *
 * The fixture is `rescov-pages`, built entirely through the formr MCP —
 * `add_run_unit` with `survey_data` creates the study and its items, so no
 * spreadsheet upload and no hand-written rows are involved:
 *
 *   10 Survey `rescov_pages`  q1 (text), page1_done (submit), q2 (text)
 *   20 Endpage
 *
 * Pagination comes from the ITEM ORDER — an item, a submit, then another
 * item gives two pages. `page_no` is NULL either way, so editing that column
 * by hand paginates nothing; it produces an incoherent page instead.
 *
 * Both items are plain text on purpose. The previous fixture put an optional
 * `push_notification` item alone on page 2, which made the second page
 * depend on a widget headless Chromium cannot render — the page collapsed to
 * one and the browser occasionally crashed outright. A survey fixture should
 * exercise paging, not push support.
 *
 * Assertions read `survey_unit_sessions.result` rather than inspecting the
 * page: the defect class this suite came from is a result the code computes
 * and the row never receives, so a green page proves nothing.
 *
 * See documentation/agent_doc/LOG_STATE_AUDIT.md.
 */
const { test, expect } = require('./helpers/test');
const { dbQuery, dbExecRaw, adminUserId } = require('./helpers/db');
const { sessionTrace, runExists, recordObserved, enrol } = require('./helpers/results');

const RUN = 'rescov-pages';
const STUDY = 'rescov_pages';

test.describe('result coverage — Survey', () => {
    // MUST be serial. The error_survey test renames the study's results table
    // out from under the run, which is global state: in parallel it breaks the
    // traversal test's page mid-flight, and the symptom is a fill() timing out
    // on an input a standalone browser finds perfectly visible.
    test.describe.configure({ mode: 'serial' });

    test.beforeAll(() => {
        test.skip(!runExists(RUN),
            `fixture run '${RUN}' is missing — rebuild it from `
            + `tests/e2e/fixtures/${RUN}.json via the run editor's Import button`);
    });

    test('a traversal records survey_started, survey_filling_out and survey_completed', async ({ page, context }) => {
        await enrol(page, context, RUN);
        expect(sessionTrace(RUN), 'first render creates the results row')
            .toEqual([[10, 'survey_started']]);

        // Page 1 of 2 -> still filling out.
        await page.fill('input[name=q1]', 'one');
        await page.click('button[type=submit], input[type=submit]');
        await page.waitForLoadState('networkidle');
        expect(sessionTrace(RUN), 'a submitted page with more to come is survey_filling_out')
            .toEqual([[10, 'survey_filling_out']]);

        // Page 2 of 2 -> the unit records its own verdict and the run ends.
        await page.fill('input[name=q2]', 'two');
        await page.click('button[type=submit], input[type=submit]');
        await page.waitForLoadState('networkidle');

        expect(sessionTrace(RUN),
            'v1.8.0: resolveEndResult() keeps the unit\'s own verdict, so a genuine '
            + 'completion records survey_completed. Before it, end() re-derived the '
            + 'result from the unit type and this read survey_ended — which is why a '
            + 'real completion and an admin force-push were indistinguishable.')
            .toEqual([[10, 'survey_completed'], [20, 'ended']]);

        recordObserved(['survey_started', 'survey_filling_out', 'survey_completed', 'ended']);
    });

    test('a broken results table records error_survey', async ({ page, context }) => {
        // processStudy throws when the results table is gone; the unit catches
        // it and logs error_survey with the exception text. Renaming the table
        // is the least invasive way to produce a genuine failure — no code path
        // is stubbed, the exception is real.
        const study = dbQuery(`SELECT results_table FROM survey_studies WHERE user_id = ${adminUserId()} AND name = '${STUDY}' ORDER BY id DESC LIMIT 1;`);
        test.skip(!study.length, `study '${STUDY}' is missing — see the fixture recipe above`);
        const table = study[0].results_table;

        dbExecRaw(`RENAME TABLE \`${table}\` TO \`${table}_hidden\`;`);
        try {
            await enrol(page, context, RUN);
            expect(sessionTrace(RUN).map(([, result]) => result)).toContain('error_survey');
            recordObserved(['error_survey']);
        } finally {
            dbExecRaw(`RENAME TABLE \`${table}_hidden\` TO \`${table}\`;`);
        }
    });
});
