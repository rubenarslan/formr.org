// Shared reading side of the result-coverage suite.
//
// The suite drives formr the way admins and participants do — fixtures built
// through the formr MCP, traversals driven in a browser — and then asserts on
// the row, because the defect class it exists for is a result the code
// computes and `survey_unit_sessions` never receives. A green page proves
// nothing about the row, so every spec reads back through here.
//
// Reading the database to assert is fine. Writing it to build a fixture is
// not: a row written by INSERT is a state the application never agreed to.
// See documentation/agent_doc/LOG_STATE_AUDIT.md.

const fs = require('node:fs');
const path = require('node:path');
const { dbQuery } = require('./db');

/** Where specs deposit what they observed, for bin/result_coverage_report.php. */
const OBSERVED_PATH = path.resolve(__dirname, '../.observed-results.json');

/**
 * Every unit session of a run's NEWEST run session, in creation order, with
 * the run position it actually ran at.
 *
 * Scoped to the newest session because fixture runs are shared and re-entered:
 * scoping to the run would let a spec see its predecessors' rows and pass for
 * the wrong reason. `run_unit_id` (patch 047) pins the placement; a row
 * predating it reports a NULL position rather than a guessed one.
 */
function sessionRows(runName) {
    return dbQuery(`
        SELECT sru.position AS position,
               su.type      AS type,
               us.result    AS result,
               IFNULL(us.result_log, '') AS result_log
          FROM survey_unit_sessions us
          JOIN survey_run_sessions rs ON rs.id = us.run_session_id
          JOIN survey_runs r          ON r.id  = rs.run_id
     LEFT JOIN survey_run_units sru   ON sru.id = us.run_unit_id
     LEFT JOIN survey_units su        ON su.id  = us.unit_id
         WHERE r.name = '${runName}'
           AND rs.id = (SELECT MAX(rs2.id) FROM survey_run_sessions rs2
                         WHERE rs2.run_id = r.id)
         ORDER BY us.id;
    `).map((r) => ({
        position: r.position === null ? null : parseInt(r.position, 10),
        type: r.type,
        result: r.result,
        log: r.result_log,
    }));
}

/** [position, result] pairs — the shape a traversal assertion reads best. */
function sessionTrace(runName) {
    return sessionRows(runName).map((r) => [r.position, r.result]);
}

/** True when the fixture run is absent, so a spec can skip with a recipe. */
function runExists(runName) {
    return dbQuery(`SELECT id FROM survey_runs WHERE name = '${runName}' LIMIT 1;`).length > 0;
}

/**
 * Record which catalogue entries this spec actually produced.
 *
 * The retired PHP harness kept a live counter of covered results; the browser
 * lane can't, because each spec is its own process. So specs deposit what they
 * saw and bin/result_coverage_report.php reconciles the union against
 * tests/fixtures/result_catalogue.php — the catalogue stays the checklist, and
 * the count is derived rather than maintained by hand.
 */
function recordObserved(results) {
    let seen = {};
    try { seen = JSON.parse(fs.readFileSync(OBSERVED_PATH, 'utf8')); } catch (e) { /* first spec */ }
    for (const r of results) {
        if (r) seen[r] = (seen[r] || 0) + 1;
    }
    fs.writeFileSync(OBSERVED_PATH, JSON.stringify(seen, null, 2) + '\n');
}

/** Enrol a brand-new participant in a link-accessible run. */
async function enrol(page, context, runName, base = 'https://study.researchmixtape.com') {
    // A fresh cookie jar, or the run resumes whichever session this browser
    // last held — and all of these results are gated on !isExecutedByCron(),
    // which only a genuine request clears.
    await context.clearCookies();
    await page.goto(`${base}/${runName}/`);
    await page.waitForLoadState('networkidle');
}

module.exports = { sessionRows, sessionTrace, runExists, recordObserved, enrol, OBSERVED_PATH };
