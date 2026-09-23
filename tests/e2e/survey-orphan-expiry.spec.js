// Regression (v1.10.3): a Survey stuck showing the "Session expired" modal on
// a page the server keeps re-serving — the client/server expiry mismatch.
//
// The client fires the modal when window.unit_session_expires (the survey's
// stored deadline, rendered into the page by head.php) passes. The server
// decides expiry independently on reload. They diverged whenever the server's
// recompute yields NO live deadline while the stored `expires` column still
// holds a PAST one:
//
//   The common trigger (seen in prod) is a survey with a START window but no
//   post-access deadline — expire_invitation_after > 0, grace = 0,
//   expire_after = 0 ("X minutes to START, then no time limit"). The moment the
//   participant starts, the pre-access deadline stops applying (the wiki's
//   "Y=0 & Z=0 ⇒ never expires" post-access), but the stored pre-access
//   `expires` lingers. The client kept showing it; the server, correctly,
//   would not end the session; reload re-served the same page forever.
//
// The fix reconciles in isExpired() — the one point both the participant render
// (UnitSession::execute) and the daemon (revalidateQueueVerdict) reach: when
// there is no live deadline, the stale stored `expires` is CLEARED, so nothing
// renders a past deadline the server won't enforce. The session is NOT expired:
// a participant who started in time is entitled to finish. (To auto-close
// abandoned in-progress sessions a study must configure grace or expire_after.)
//
//   npx playwright test --config tests/e2e/playwright.config.js survey-orphan-expiry
const { spawnSync } = require('node:child_process');
const { test, expect } = require('./helpers/test');
const { dbQuery, dbExecRaw, dbState } = require('./helpers/db');
const { provision } = require('./helpers/expiry');
const { runQueueOnce } = require('./helpers/queue');

// The stalled-session sweep isn't wrapped by a shared helper; run it directly
// and capture stderr (spawnSync returns it even on success, unlike execFileSync).
function runSweep(runId) {
    const r = spawnSync('docker',
        ['exec', '-i', 'formr_app', 'php', '/var/www/formr/bin/sweep_stalled_unit_sessions.php',
         '--min-age-minutes=1', `--run-id=${runId}`],
        { encoding: 'utf8' });
    return { ok: r.status === 0, stdout: r.stdout || '', stderr: r.stderr || '' };
}

// Shape the fixture's unit session like the prod case: a participant who
// STARTED within an X-minute start window that has since passed, on a survey
// with no post-access deadline. `queued` lets a test place it in the daemon's
// END set (2) or as an already-dequeued orphan (0). A saved item row makes the
// session "started" (first_submit > invitation), so the recompute is post-access
// with no deadline while the stored pre-access `expires` is already past.
function startedPastStartWindow(usId, firstItemId, { queued = 2 } = {}) {
    dbExecRaw(`
        UPDATE survey_unit_sessions
           SET created = NOW() - INTERVAL 70 MINUTE,
               expires = NOW() - INTERVAL 10 MINUTE,  -- stored pre-access deadline (invite + 60), now past
               queued = ${queued}, ended = NULL, expired = NULL,
               result = 'survey_filling_out', state = 'RUNNING'
         WHERE id = ${usId};
        UPDATE survey_items_display SET created = NOW() - INTERVAL 70 MINUTE WHERE session_id = ${usId};
        UPDATE survey_items_display SET saved = NOW() - INTERVAL 65 MINUTE
         WHERE session_id = ${usId} AND item_id = ${firstItemId};`);
}

test.describe('a Survey with no post-access deadline never gets stuck on the expiry modal', () => {
    test('the daemon clears the phantom deadline of a started survey, and does NOT expire it', async () => {
        const f = await provision({ x: 60, y: 0, z: 0, items: 2, name: 'e2e-orphan-b', withUnitSession: true });
        const usId = parseInt(f.unit_session_id, 10);
        startedPastStartWindow(usId, f.item_ids[0], { queued: 2 });

        // Precondition: queued-to-end, a past stored deadline.
        let s = dbState(usId);
        expect(parseInt(s.queued, 10)).toBe(2);
        expect(s.expires_unix).toBeLessThan(Math.floor(Date.now() / 1000));

        expect(runQueueOnce().ok).toBe(true);

        // The phantom deadline is gone and the session is still live — NOT
        // expired (the participant started in time and may finish).
        s = dbState(usId);
        expect(s.expired, 'a started participant with no post-access deadline must NOT be expired').toBeNull();
        expect(s.expires, 'the phantom stored deadline must be cleared').toBeNull();
        expect(parseInt(s.queued, 10)).toBe(0);
    });

    test('a survey with expiry switched off has its stale deadline cleared, not expired', async () => {
        const f = await provision({ x: 0, y: 0, z: 0, items: 2, name: 'e2e-orphan-noexp', withUnitSession: true });
        const usId = parseInt(f.unit_session_id, 10);
        dbExecRaw(`UPDATE survey_unit_sessions
                      SET expires = NOW() - INTERVAL 8 HOUR, queued = 2,
                          ended = NULL, expired = NULL, result = 'survey_filling_out', state = 'RUNNING'
                    WHERE id = ${usId};`);

        expect(runQueueOnce().ok).toBe(true);

        const s = dbState(usId);
        expect(s.expired, 'a survey with expiry switched off must NOT be expired').toBeNull();
        expect(s.expires, 'the phantom stale deadline must be cleared').toBeNull();
        expect(parseInt(s.queued, 10)).toBe(0);
    });

    test('a participant who reloads the stuck page continues filling — never stuck, never wrongly expired', async ({ page, baseURL }) => {
        // The invariant: the reload must resolve the mismatch. The page the
        // participant now sees must not carry a past deadline (which fired the
        // modal), and they must be able to keep going — not be ended.
        const f = await provision({ x: 60, y: 0, z: 0, items: 2, name: 'e2e-orphan-visit', withUnitSession: true });
        const usId = parseInt(f.unit_session_id, 10);
        startedPastStartWindow(usId, f.item_ids[0], { queued: 2 });
        dbExecRaw(`UPDATE survey_run_sessions SET current_unit_session_id = ${usId}, position = 10
                    WHERE id = ${f.run_session_id};`);

        await page.goto(`${baseURL}/${f.run_name}/?code=${f.code}`, { waitUntil: 'load', timeout: 30000 });

        const s = dbState(usId);
        expect(s.expired, 'reloading must not expire a started participant').toBeNull();
        expect(s.expires, 'the reload must clear the phantom deadline').toBeNull();

        // No past deadline is rendered, so the ExpiryNotifier modal cannot fire.
        const rendered = await page.evaluate(() => window.unit_session_expires ?? null);
        if (rendered !== null) {
            expect(new Date(String(rendered).replace(' ', 'T')).getTime(),
                'a rendered deadline must never already be in the past').toBeGreaterThan(Date.now());
        }
    });

    test('the stalled-session sweep clears an already-orphaned survey\'s phantom deadline', async () => {
        const f = await provision({ x: 60, y: 0, z: 0, items: 2, name: 'e2e-orphan-c', withUnitSession: true });
        const usId = parseInt(f.unit_session_id, 10);
        // Already dequeued (queued=0) before the fix: the daemon can't see it.
        startedPastStartWindow(usId, f.item_ids[0], { queued: 0 });
        dbExecRaw(`UPDATE survey_run_sessions SET current_unit_session_id = ${usId}, position = 10
                    WHERE id = ${f.run_session_id};`);

        runQueueOnce();
        expect(parseInt(dbState(usId).queued, 10), 'daemon cannot see a queued=0 orphan').toBe(0);

        const sweep = runSweep(f.run_id);
        expect(sweep.stderr + sweep.stdout).toMatch(/re-arm orphaned Survey unit-session/);
        expect(parseInt(dbState(usId).queued, 10), 'sweep must re-arm the orphan').toBe(2);

        expect(runQueueOnce().ok).toBe(true);
        const s = dbState(usId);
        expect(s.expired, 'the sweep path must clear, not expire').toBeNull();
        expect(s.expires, 'the phantom deadline must be cleared').toBeNull();
        expect(parseInt(dbState(usId).queued, 10)).toBe(0);
    });
});
