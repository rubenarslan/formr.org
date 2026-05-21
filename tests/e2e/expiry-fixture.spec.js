// Phase-1 smoke test for the e2e expiry test infrastructure.
//
// Verifies that the four pieces installed in Phase 1 work end-to-end
// against the dev formr_db:
//
//   1. bin/expiry_fixture.php creates a Run with a Survey + Endpage
//      and a testing run-session, and emits parseable JSON.
//   2. helpers/db.js can read the unit-session and the results-table
//      row via `docker exec formr_db mariadb`.
//   3. A real participant visit through Playwright populates the
//      results row (createSurveyStudyRecord runs).
//   4. After backdating `expires` so the queue daemon picks it up,
//      `bin/queue.php -t UnitSession --once` expires the session.
//
// All four should pass green; if any fails, none of the later
// characterisation specs will work, so this spec is the gate.

const { test, expect } = require('./helpers/test');
const { dbQuery, dbState, dbResultsRow, backdateUnitSession, dbNow } = require('./helpers/db');
const { provision } = require('./helpers/expiry');
const { runQueueOnce } = require('./helpers/queue');

test('Phase-1 smoke: fixture + visit + backdate + queue → expired', async ({ page, baseURL }) => {
    // 1. Provision a fresh Survey/Endpage run, X=60min only.
    const f = await provision({ x: 60, y: 0, z: 0, items: 1 });
    expect(f.run_name).toBe('e2e-expiry');
    expect(f.code).toMatch(/^e2eXXX[0-9a-f]+XXX/);

    // The Survey unit-session doesn't exist until the participant visits
    // (UnitSession::create is called from RunSession::execute via moveOn).
    // So pre-visit, we have a run-session but no unit-session row yet.
    const preUS = dbQuery(
        `SELECT id FROM survey_unit_sessions WHERE run_session_id = ${f.run_session_id}`
    );
    expect(preUS.length).toBe(0);

    // 2. Visit as the participant. Should render the form for the Survey.
    await page.goto(`${baseURL}/${f.run_name}/?code=${f.code}`, { waitUntil: 'load', timeout: 30000 });
    await expect(page.locator('input[name="q1"]')).toBeVisible({ timeout: 10000 });

    // 3. Lookup the unit-session that the visit created.
    const postUS = dbQuery(
        `SELECT us.id FROM survey_unit_sessions us
         JOIN survey_units u ON u.id = us.unit_id AND u.type = 'Survey'
         WHERE us.run_session_id = ${f.run_session_id}`
    );
    expect(postUS.length).toBe(1);
    const usId = parseInt(postUS[0].id, 10);

    // First state: invitation just sent, X=60 → expires ~60 min ahead.
    let s = dbState(usId);
    expect(s).not.toBeNull();
    expect(s.ended).toBeNull();
    expect(s.expired).toBeNull();
    expect(s.expires).not.toBeNull();
    // queued is set when the unit's getUnitSessionExpirationData
    // returns a future expiry (2 = QUEUED_TO_END).
    expect(parseInt(s.queued, 10)).toBe(2);

    // The results-table row exists (createSurveyStudyRecord ran on visit).
    const r = dbResultsRow(f.results_table, usId);
    expect(r).not.toBeNull();
    expect(r.session_id).toBe(String(usId));

    // 4. Backdate by 90 min so expires < NOW. Drive the queue once.
    backdateUnitSession(usId, 90);
    s = dbState(usId);
    expect(s.expires_unix, 'expires should now be in the past').toBeLessThan(dbNow());

    const q = runQueueOnce();
    expect(q.ok, `queue --once should succeed: ${q.stderr || ''}`).toBe(true);

    // 5. Final state: expired=NOW, queued=0, result='expired'.
    s = dbState(usId);
    expect(s.expired, 'queue should set expired=NOW after backdate').not.toBeNull();
    expect(s.ended).toBeNull();
    expect(parseInt(s.queued, 10)).toBe(0);
    expect(s.result).toBe('expired');
    // expired_unix should be very close to dbNow().
    expect(Math.abs(s.expired_unix - dbNow())).toBeLessThan(60);

    // The results-table row is also stamped with `expired`.
    const rAfter = dbResultsRow(f.results_table, usId);
    expect(rAfter.expired).not.toBeNull();
});
