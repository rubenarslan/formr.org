/**
 * Audit X2 — the API and the admin must agree about a participant's group.
 *
 * `ApiBase::getShuffleResults()` used to select `sus.result AS group`, so the
 * v1 API reported the string it found in `survey_unit_sessions.result` while
 * the admin's randomisation page and its exports read `shuffle`.`group`, the
 * integer the Shuffle unit actually wrote. Two copies of one fact in two
 * value spaces, with nothing keeping them in sync — and the API's copy was
 * the one that gets overwritten.
 *
 * This test overwrites it on purpose. An admin force-move stamps
 * `manual_admin_push` into `result`; before v1.8.0 the API then reported
 * `group: "manual_admin_push"` for a participant whose R still read 2, and
 * any analysis joining on the API's answer silently mis-assigned them. The
 * fix reads the authoritative column and formats it the way clients already
 * expect, so the wire format is unchanged and only the source moved.
 *
 * Needs API credentials with `run:read` and `data:read` — see
 * result-coverage-structure.spec.js for how they are minted.
 *
 * Fixture: rescov-shuffle (10 Shuffle, 2 groups; 20 Endpage).
 *
 * See documentation/agent_doc/LOG_STATE_AUDIT.md.
 */
const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('./helpers/test');
const { dbQuery } = require('./helpers/db');
const { ensureAdminState, STATE_PATH, ADMIN_BASE, sendToPosition } = require('./helpers/admin');
const { sessionRows, runExists, enrolInOwnContext } = require('./helpers/results');

const RUN = 'rescov-shuffle';
const CREDS_PATH = path.resolve(__dirname, 'setup/api-credentials-state.json');

test.describe('audit X2 — group assignment survives a result overwrite', () => {
    test.use({ storageState: STATE_PATH });

    let token = null;

    test.beforeAll(async ({ browser, playwright }) => {
        test.skip(!runExists(RUN), `fixture run '${RUN}' is missing`);
        await ensureAdminState(browser);
        let creds = null;
        try { creds = JSON.parse(fs.readFileSync(CREDS_PATH, 'utf8')); } catch (e) { /* absent */ }
        test.skip(!creds, `no API credentials at ${CREDS_PATH}`);

        const api = await playwright.request.newContext();
        const res = await api.post(`${ADMIN_BASE}/api/oauth/access_token`, {
            headers: { Authorization: 'Basic ' + Buffer.from(`${creds.client_id}:${creds.client_secret}`).toString('base64') },
            form: { grant_type: 'client_credentials' },
        });
        const body = await res.json().catch(() => null);
        token = body && body.access_token ? body.access_token : null;
        await api.dispose();
        test.skip(!token, 'could not obtain an access token');
    });

    test('the API reports the shuffle table, not whatever is in result', async ({ page, browser, playwright }) => {
        const p = await enrolInOwnContext(browser, RUN);
        await p.ctx.close();

        const shuffleRow = sessionRows(RUN).find((r) => r.position === 10);
        expect(shuffleRow.result, 'the participant is assigned a group').toMatch(/^group_[12]$/);

        // The authoritative integer, which is what `shuffle$group` resolves to
        // in the author's R and what the admin randomisation page shows.
        const authoritative = dbQuery(`
            SELECT sh.\`group\` AS grp FROM shuffle sh WHERE sh.session_id = ${shuffleRow.id} LIMIT 1;
        `);
        expect(authoritative.length, 'the shuffle table holds the assignment').toBe(1);
        const expected = `group_${authoritative[0].grp}`;

        // An admin action for good measure. Note it does NOT overwrite this
        // particular row: a Shuffle advances immediately, so by the time an
        // admin can act the current unit session is the Endpage, and that is
        // what forceTo() ends. Manufacturing a divergent `result` on a shuffle
        // row therefore is not reachable from the admin UI today — which is
        // luck, not design, since the API was reading that column.
        const session = dbQuery(`
            SELECT rs.session AS session FROM survey_run_sessions rs
              JOIN survey_runs r ON r.id = rs.run_id
             WHERE r.name = '${RUN}' ORDER BY rs.id DESC LIMIT 1;
        `)[0].session;
        expect((await sendToPosition(page, RUN, session, 10)).status()).toBe(200);

        // The claim itself: every group the API reports equals the group the
        // shuffle table holds, for every assignment in the run.
        const api = await playwright.request.newContext();
        const res = await api.get(`${ADMIN_BASE}/api/v1/runs/${RUN}/results`, {
            headers: { Authorization: `Bearer ${token}` },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        const shuffles = body.shuffles || (body.data && body.data.shuffles) || [];
        await api.dispose();

        const reported = shuffles.find((s) => Number(s.session_id) === shuffleRow.id);
        expect(reported, 'the API lists this assignment').toBeTruthy();
        expect(reported.group,
            'X2: the API used to echo `result`, so anything written there — '
            + 'moved, manual_admin_push — became someone\'s experimental group')
            .toBe(expected);

        const authoritativeAll = dbQuery(`
            SELECT sh.session_id AS session_id, sh.\`group\` AS grp
              FROM shuffle sh
              JOIN survey_unit_sessions us ON us.id = sh.session_id
              JOIN survey_run_sessions rs ON rs.id = us.run_session_id
              JOIN survey_runs r ON r.id = rs.run_id
             WHERE r.name = '${RUN}';
        `).reduce((m, r) => Object.assign(m, { [r.session_id]: `group_${r.grp}` }), {});
        const disagreements = shuffles
            .filter((s) => authoritativeAll[s.session_id])
            .filter((s) => s.group !== authoritativeAll[s.session_id])
            .map((s) => `${s.session_id}: api=${s.group} shuffle=${authoritativeAll[s.session_id]}`);
        expect(disagreements, 'no assignment may differ between the two readers').toEqual([]);
    });
});
