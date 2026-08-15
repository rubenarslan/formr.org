/**
 * Result coverage — External units.
 *
 * Seven catalogue entries, all driven the way they happen in production: a
 * participant follows the redirect out, the external service posts back.
 *
 * Fixtures are MCP-built runs, exported to tests/e2e/fixtures/<run>.json:
 *
 *   rescov-ext     10 `1 + 1`      -> external_compute            (moves on)
 *                  20 `FALSE`      -> external_r_call_no_redirect (moves on)
 *                  30 plain URL    -> external_redirect           (moves on)
 *                  40 R -> URL     -> external_r_redirect         (moves on)
 *                  50 Endpage      -> ended
 *   rescov-extapi  10 api_end      -> external_wait_for_api       (parks)
 *                  20 Endpage
 *   rescov-exterr  10 broken R     -> error_opencpu               (parks)
 *
 * The redirect targets are the run's own privacy-policy and terms pages,
 * which makes the "external service" self-contained — no third-party host a
 * test has to depend on. On `rescov-ext` those pages are unset, so each one
 * 302s straight back to the run and the next unit executes: one navigation
 * produces the whole chain, and the participant really is sent to both
 * addresses (asserted from the document responses, not just from the rows).
 * On `rescov-extapi` the privacy text IS set, so the page renders and the
 * participant genuinely leaves — which is the only way to observe an api_end
 * External parked and waiting.
 *
 * See documentation/agent_doc/LOG_STATE_AUDIT.md.
 */
const { test, expect } = require('./helpers/test');
const { sessionRows, sessionTrace, runExists, recordObserved, enrol } = require('./helpers/results');

const STUDY = 'https://study.researchmixtape.com';
const ADMIN = 'https://formr.researchmixtape.com';

function requireFixture(runName) {
    test.skip(!runExists(runName),
        `fixture run '${runName}' is missing — rebuild it from `
        + `tests/e2e/fixtures/${runName}.json via the run editor's Import button`);
}

test.describe('result coverage — External', () => {
    test('compute, no-redirect, plain redirect and R redirect, then the endpage', async ({ page, context }) => {
        requireFixture('rescov-ext');

        const documents = [];
        page.on('response', (r) => {
            if (r.request().resourceType() === 'document') documents.push(r.url());
        });

        await enrol(page, context, 'rescov-ext');

        expect(sessionTrace('rescov-ext')).toEqual([
            [10, 'external_compute'],
            [20, 'external_r_call_no_redirect'],
            [30, 'external_redirect'],
            [40, 'external_r_redirect'],
            [50, 'ended'],
        ]);

        // The participant was actually sent to both addresses. Without this,
        // a redirect that was computed and then discarded — which is exactly
        // what M1 does to logs — would look identical from the rows.
        expect(documents.join('\n'), 'the plain address must be visited')
            .toContain(`${STUDY}/rescov-ext/privacy_policy/`);
        expect(documents.join('\n'), 'the R-computed address must be visited')
            .toContain(`${STUDY}/rescov-ext/terms_of_service/`);

        // external_compute keeps the computed value, which is the one branch
        // where the value itself is the point of the record.
        expect(sessionRows('rescov-ext').find((r) => r.position === 10).log).toContain('2');

        recordObserved(['external_compute', 'external_r_call_no_redirect',
            'external_redirect', 'external_r_redirect', 'ended']);
    });

    test('an api_end External parks as external_wait_for_api and the callback ends it', async ({ page, context }) => {
        requireFixture('rescov-extapi');
        await enrol(page, context, 'rescov-extapi');

        // The participant is now ON the stand-in external service, and the
        // External is parked: no move_on, so the run waits for a callback.
        expect(page.url()).toContain('/rescov-extapi/privacy_policy/');
        const parked = sessionRows('rescov-extapi');
        expect(parked[0].position).toBe(10);
        expect(parked[0].result).toBe('external_wait_for_api');

        // The External's address ends in `?code={{login_code}}`, so the
        // participant arrives at the service carrying their session code —
        // which is how a real integration learns who came back. Read it off
        // the URL rather than out of the database: this is the handover the
        // callback depends on, so it is part of what the test proves.
        const code = new URL(page.url()).searchParams.get('code');
        expect(code, 'the redirect must hand the service the session code').toBeTruthy();

        // The external service reports back. Legacy v0 endpoint, deliberately
        // unauthenticated, and 100% broken before v1.8.0 (it passed null into
        // a non-nullable `Run $run`, so every call was a JSON 500).
        const res = await page.request.post(`${ADMIN}/api/post/end-last-external`, {
            headers: { 'Content-Type': 'application/json' },
            data: { run: { name: 'rescov-extapi', session: code } },
        });
        expect(res.status(), await res.text()).toBe(200);

        const ended = sessionRows('rescov-extapi').find((r) => r.position === 10);
        expect(ended.result, 'the callback stamps external_ended').toBe('external_ended');
        expect(ended.log).toContain('end-last-external');

        recordObserved(['external_wait_for_api', 'external_ended']);
    });

    test('an External whose R address cannot evaluate records error_opencpu and parks', async ({ page, context }) => {
        requireFixture('rescov-exterr');
        await enrol(page, context, 'rescov-exterr');

        const rows = sessionRows('rescov-exterr');
        expect(rows.map((r) => [r.position, r.result])).toEqual([[10, 'error_opencpu']]);
        expect(rows[0].log).toMatch(/could not find function/i);

        // v1.8.0 re-arms the row so the daemon retries it. Before that it sat
        // inert at queued=0 — and External is the one type the stalled-session
        // sweep skips, so the participant parked here indefinitely.
        const { dbQuery } = require('./helpers/db');
        const queued = dbQuery(`
            SELECT us.queued AS queued, us.expires AS expires
              FROM survey_unit_sessions us
              JOIN survey_run_sessions rs ON rs.id = us.run_session_id
              JOIN survey_runs r ON r.id = rs.run_id
             WHERE r.name = 'rescov-exterr'
               AND rs.id = (SELECT MAX(rs2.id) FROM survey_run_sessions rs2 WHERE rs2.run_id = r.id)
             ORDER BY us.id DESC LIMIT 1;
        `)[0];
        expect(parseInt(queued.queued, 10), 'the failed row must be re-armed for the daemon').toBeGreaterThan(0);
        expect(queued.expires, 'and carry a retry deadline').not.toBeNull();

        recordObserved(['error_opencpu']);
    });
});
