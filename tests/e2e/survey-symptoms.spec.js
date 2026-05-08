// Phase 2: deterministic reproductions of the four prod symptoms.
//
// Each test characterises one specific terminal DB shape via a
// deterministic trigger. Predictions come from reading the code
// (Survey.php / Pause.php / UnitSession.php / RunSession.php / Queue);
// where the actual run diverges from the prediction we update the test
// comment, not the assertion — the goal is to characterise reality.

const { test, expect } = require('./helpers/test');
const {
    dbExecRaw, dbQuery, dbState, dbResultsRow,
    backdateUnitSession, dbNow,
} = require('./helpers/db');
const { provision } = require('./helpers/expiry');
const { runQueueOnce } = require('./helpers/queue');

// Helper: visit the run once via Playwright to lazily create the
// Survey unit-session (which only happens when RunSession::execute()
// runs and moveOn lands on the Survey).
async function visitParticipant(page, baseURL, fixture, { expectForm = true } = {}) {
    await page.context().clearCookies();
    await page.goto(`${baseURL}/${fixture.run_name}/?code=${fixture.code}`,
        { waitUntil: 'load', timeout: 30000 });
    if (expectForm) {
        await expect(page.locator('input[name="q1"]')).toBeVisible({ timeout: 10000 });
    }
}

function unitSessionsFor(runSessionId) {
    return dbQuery(
        `SELECT us.id, us.unit_id, u.type, us.queued, us.ended, us.expired, us.result,
                UNIX_TIMESTAMP(us.expires) AS expires_unix
         FROM survey_unit_sessions us
         JOIN survey_units u ON u.id = us.unit_id
         WHERE us.run_session_id = ${parseInt(runSessionId, 10)}
         ORDER BY us.id ASC`
    );
}

test.describe.serial('Symptom A / B / D reproductions', () => {

    test('A1 (warm-up): visit + backdate + queue → expired, queued=0, result=expired', async ({ page, baseURL }) => {
        const f = await provision({ x: 60, y: 0, z: 0, items: 1 });
        await visitParticipant(page, baseURL, f);

        const us = unitSessionsFor(f.run_session_id).find((r) => r.type === 'Survey');
        expect(us, 'Survey unit-session should exist after visit').toBeTruthy();
        const usId = parseInt(us.id, 10);

        // Run queue while expires is in the future — should NOT touch the row.
        runQueueOnce();
        let s = dbState(usId);
        expect(s.expired).toBeNull();
        expect(s.ended).toBeNull();
        expect(parseInt(s.queued, 10)).toBe(2);

        // Backdate so expires < NOW. Queue should expire it.
        backdateUnitSession(usId, 90);
        runQueueOnce();
        s = dbState(usId);
        expect(s.expired).not.toBeNull();
        expect(s.ended).toBeNull();
        expect(parseInt(s.queued, 10)).toBe(0);
        expect(s.result).toBe('expired');
    });

    test('A2 (Symptom A): queue picks up stale Survey reference while position is forced past it', async ({ page, baseURL }) => {
        const f = await provision({ x: 60, y: 0, z: 0, items: 1 });
        await visitParticipant(page, baseURL, f);

        const us = unitSessionsFor(f.run_session_id).find((r) => r.type === 'Survey');
        const usId = parseInt(us.id, 10);

        // Backdate Survey's expires past so the queue daemon picks it up.
        backdateUnitSession(usId, 90);
        // Force the run's position past the Survey, to the Endpage (position 20).
        // This simulates: another path advanced the run (admin forceTo, SkipForward, etc.)
        // while the Survey still had a queued entry.
        dbExecRaw(`UPDATE survey_run_sessions SET position = 20 WHERE id = ${f.run_session_id}`);

        runQueueOnce();

        // Code-reading prediction: queue picks up Survey, RunSession::execute()
        // calls getCurrentUnitSession() — looks for unit_id = unit at position 20 (Endpage).
        // No Endpage unit-session exists → returns false → moveOn() is invoked.
        // moveOn → getNextPosition(20) returns null → "Stop button missing" → run-session.end().
        // Survey itself is never expired/ended through this path; it stays queued=2 unless
        // a sibling supersedes it. No supersede happens because no createUnitSession runs.
        // Result: Survey stays as Symptom A (queued=2, ended/expired NULL).
        const s = dbState(usId);
        expect(s.ended, 'Survey should remain unended after position-mismatch queue tick').toBeNull();
        expect(s.expired, 'Survey should remain unexpired').toBeNull();
        // Run-session was ended by the dangling-end path:
        const rs = dbQuery(`SELECT ended FROM survey_run_sessions WHERE id = ${f.run_session_id}`);
        expect(rs[0].ended, 'run-session should be ended by the dangling-end path').not.toBeNull();
    });

    test('B1 (Symptom B): end() on never-visited Survey leaves ended=NOW with no results row', async ({ baseURL }) => {
        const f = await provision({ x: 60, y: 0, z: 0, items: 1 });
        // Do NOT visit — no Survey unit-session yet, no results-table row yet.

        // Manually create a queued Survey unit-session as if the Survey had been
        // queued for the participant but they never visited. This skips
        // createSurveyStudyRecord (which only runs on a real visit).
        const study_id = f.study_id;
        dbExecRaw(
            `INSERT INTO survey_unit_sessions (unit_id, run_session_id, created, expires, queued)
             VALUES (${study_id}, ${f.run_session_id}, NOW(), DATE_SUB(NOW(), INTERVAL 1 MINUTE), 2)`
        );
        const usRow = dbQuery(`SELECT id FROM survey_unit_sessions WHERE run_session_id=${f.run_session_id} AND unit_id=${study_id}`);
        const usId = parseInt(usRow[0].id, 10);

        // Set survey_run_sessions.ended to trigger the run-session-ended branch
        // in RunSession::execute() at :201-219.
        dbExecRaw(`UPDATE survey_run_sessions SET ended = NOW() WHERE id = ${f.run_session_id}`);

        runQueueOnce();

        // Queue picks up the Survey unit-session. RunSession::execute()
        // sees run_session.ended, calls referenceUnitSession->end('ended_by_queue_rse').
        // UnitSession::end() UPDATEs results_table SET ended=NOW WHERE ended IS NULL —
        // matches 0 rows because no row exists. Then UPDATEs survey_unit_sessions SET
        // ended=NOW. Symptom B: ended IS NOT NULL, no results row.
        // Post-Hygiene-5 fix: end() honours the explicit reason; result =
        // 'ended_by_queue_rse', not the hardcoded 'survey_ended'.
        const s = dbState(usId);
        expect(s.ended, 'Survey should be ended despite never being visited').not.toBeNull();
        expect(s.result, 'end() honours explicit $reason post-fix').toBe('ended_by_queue_rse');
        const r = dbResultsRow(f.results_table, usId);
        expect(r, 'no results row should exist for an unvisited Survey').toBeNull();
    });

    test('D1 (Pause skipped via relative_to → TRUE)', async ({ page, baseURL }) => {
        // Pause's relative_to evaluates to PHP `true` → Pause.php:150-152 sets
        // condition='1=1' and expire_relatively=true; the SQL test returns
        // true → Pause.php:249 sets BOTH end_session=true AND expired=true.
        // RunSession.executeUnitSession() at :311-316 checks expired first
        // (elseif), so expire() wins. Pause is `expired` immediately, not
        // `ended`. From the participant's POV the unit is bypassed either
        // way — but the DB shape is `expired = NOW()`, not `ended = NOW()`.
        const f = await provision({
            x: 0, y: 0, z: 0, items: 1,
            pause: { relative_to: 'TRUE' },
        });
        await visitParticipant(page, baseURL, f);

        await page.locator('input[name="q1"]').fill('done');
        await Promise.all([
            page.waitForLoadState('load', { timeout: 30000 }),
            page.locator('input[type="submit"], button[type="submit"]').first().click(),
        ]);

        const sessions = unitSessionsFor(f.run_session_id);
        const survey = sessions.find((r) => r.type === 'Survey');
        const pause = sessions.find((r) => r.type === 'Pause');
        expect(survey, 'Survey unit-session should exist').toBeTruthy();
        expect(pause, 'Pause unit-session should exist (created on moveOn)').toBeTruthy();
        expect(pause.expired, 'Pause should be expired immediately when relative_to=TRUE').not.toBeNull();
        expect(pause.ended, 'Pause is expired (not ended) — dispatcher prefers expire()').toBeNull();
        expect(pause.result).toBe('expired');
    });

    test('D2 (Pause skipped via degenerate config — no fields set)', async ({ page, baseURL }) => {
        // Pause with neither wait_minutes nor relative_to nor wait_until_*.
        // Pause.php:245-247 fallback: $conditions=[] → $result = true →
        // line 249 sets both end_session and expired=true. Same dispatcher
        // path as D1: expire() wins.
        const f = await provision({
            x: 0, y: 0, z: 0, items: 1,
            pause: {},
        });
        await visitParticipant(page, baseURL, f);

        await page.locator('input[name="q1"]').fill('done');
        await Promise.all([
            page.waitForLoadState('load', { timeout: 30000 }),
            page.locator('input[type="submit"], button[type="submit"]').first().click(),
        ]);

        const pause = unitSessionsFor(f.run_session_id).find((r) => r.type === 'Pause');
        expect(pause, 'Pause unit-session should exist').toBeTruthy();
        expect(pause.expired, 'degenerate Pause should expire immediately').not.toBeNull();
        expect(pause.ended).toBeNull();
    });

    test('D3 (Pause skipped via run-session-ended cron path)', async ({ page, baseURL }) => {
        const f = await provision({
            x: 0, y: 0, z: 0, items: 1,
            pause: { wait_minutes: 60 },
        });
        await visitParticipant(page, baseURL, f);

        // Complete Survey1 → Pause unit-session created with queued=2, expires=NOW+60.
        await page.locator('input[name="q1"]').fill('done');
        await Promise.all([
            page.waitForLoadState('load', { timeout: 30000 }),
            page.locator('input[type="submit"], button[type="submit"]').first().click(),
        ]);

        const pause = unitSessionsFor(f.run_session_id).find((r) => r.type === 'Pause');
        expect(pause).toBeTruthy();
        const pauseId = parseInt(pause.id, 10);
        expect(parseInt(pause.queued, 10)).toBe(2);

        // End run-session, backdate Pause's expires so queue picks it up.
        dbExecRaw(`UPDATE survey_run_sessions SET ended = NOW() WHERE id = ${f.run_session_id}`);
        backdateUnitSession(pauseId, 90);
        runQueueOnce();

        // Predicted: queue picks up Pause, RunSession::execute() ended-branch
        // calls end('ended_by_queue_rse') + UnitSessionQueue::removeItem().
        // For type=Pause with explicit reason, UnitSession::end() at :220-222
        // sets result = $reason (no Survey-style overwrite). removeItem then
        // sets queued=0 — so the participant's "skipped Pause" looks like
        // {ended=NOW, result='ended_by_queue_rse', queued=0} in this path.
        const s = dbState(pauseId);
        expect(s.ended, 'Pause should be ended via queue-driven run-session-ended path').not.toBeNull();
        expect(s.result).toBe('ended_by_queue_rse');
        expect(parseInt(s.queued, 10), 'removeItem in the same path resets queued').toBe(0);
    });

    test('P4 (end() asymmetry on queued): end() alone does NOT reset queued — Pause flow without queue', async ({ page, baseURL }) => {
        // Different from D3: here we drive end() through the *participant's*
        // request, not the queue. The queue daemon's removeItem masks the
        // asymmetry; the participant flow does not. We provoke end() via
        // the run-session-ended branch from a participant request (not cron):
        // RunSession::execute() at :206-219 (elseif !formr_in_console).
        //
        // Setup: Pause with future expires (queued=2). End run-session.
        // Participant POSTs back to the run URL. Server's RunSession::execute()
        // sees this.ended, takes the elseif branch (not the cron branch),
        // which redirects to logout — does NOT call end() on the queued Pause.
        //
        // So actually, for the participant path, queued stays at 2 with
        // ended/expired NULL — the Pause is orphaned but not ended either.
        // This is a *different* shape than D3.
        const f = await provision({
            x: 0, y: 0, z: 0, items: 1,
            pause: { wait_minutes: 60 },
        });
        await visitParticipant(page, baseURL, f);
        await page.locator('input[name="q1"]').fill('done');
        await Promise.all([
            page.waitForLoadState('load', { timeout: 30000 }),
            page.locator('input[type="submit"], button[type="submit"]').first().click(),
        ]);

        const pause = unitSessionsFor(f.run_session_id).find((r) => r.type === 'Pause');
        const pauseId = parseInt(pause.id, 10);
        expect(parseInt(pause.queued, 10)).toBe(2);

        // End run-session. Participant re-visits.
        dbExecRaw(`UPDATE survey_run_sessions SET ended = NOW() WHERE id = ${f.run_session_id}`);
        await page.goto(`${baseURL}/${f.run_name}/?code=${f.code}`,
            { waitUntil: 'load', timeout: 30000 });

        // Pause is untouched: still queued=2, ended/expired NULL. No path
        // through the participant request touched the queued Pause.
        const s = dbState(pauseId);
        expect(s.ended, 'participant flow does not end queued Pause').toBeNull();
        expect(s.expired).toBeNull();
        expect(parseInt(s.queued, 10), 'P4: queued unchanged when no end() runs').toBe(2);
    });
});
