// Run-engine audit (2026-07) — daemon-driven regression tests.
// See documentation/agent_doc/run_engine_audit_2026-07.md.
//
// These exercise the fixes end-to-end through the REAL UnitSession queue
// (runQueueOnce), which is the layer where the deterministic PHP smoke
// (bin/test_run_engine_audit_smoke.php) can only approximate. The most
// valuable is F4: a sliding-window Survey whose participant is still
// working must NOT be expired by the daemon on a stale stored deadline —
// this is the exact shape of the 6456-row damage found on the Freiburg
// prod instance.
//
// Post-fix these are GREEN; on the pre-fix code they are RED (the daemon
// expires the active participant). Fixture provisions its own run, so no
// unrelated dev data is touched.

const { test, expect } = require('./helpers/test');
const { dbExecRaw, dbState } = require('./helpers/db');
const {
    setItemsDisplaySaved, setUnitSessionCreated, setUnitSessionExpires,
} = require('./helpers/db');
const { provision } = require('./helpers/expiry');
const { runQueueOnce } = require('./helpers/queue');

const QUEUED_TO_END = 2;

test.describe('Run-engine audit — F4 daemon deadline revalidation', () => {

    test('F4 — sliding-window Survey with recent activity is re-armed, not expired', async () => {
        // Sliding window only: X=0 (no invitation deadline), Z=30 (expire
        // 30 min after last activity). Participant has STARTED (invitation
        // 60 min ago) and was ACTIVE 5 min ago — well inside the window.
        const f = await provision({
            x: 0, y: 0, z: 30, items: 1, withUnitSession: true,
            name: 'e2e-audit-f4-active',
        });
        const usId = f.unit_session_id;

        // Invitation 60 min ago; last activity 5 min ago (started + active).
        setUnitSessionCreated(usId, 60);
        setItemsDisplaySaved(usId, 5);
        // The queue row carries a STALE deadline (armed 10 min ago from an
        // earlier state) and is due — exactly what the daemon picks up.
        setUnitSessionExpires(usId, 10);
        dbExecRaw(`UPDATE survey_unit_sessions SET queued = ${QUEUED_TO_END} WHERE id = ${usId}`);

        // Daemon END-q pass. Pre-fix: expires the active participant at the
        // stale deadline. Post-fix: revalidateQueueVerdict recomputes
        // last_active(5m) + Z(30m) = ~25 min in the FUTURE → re-arm.
        const res = runQueueOnce();
        expect(res.ok, `queue ran cleanly: ${res.stderr || ''}`).toBe(true);

        const row = dbState(usId);
        expect(row.expired, 'active participant NOT expired').toBeNull();
        expect(row.ended, 'active participant NOT ended').toBeNull();
        expect(row.expires_unix, 'deadline re-armed into the future')
            .toBeGreaterThan(Math.floor(Date.now() / 1000));
    });

    test('F4 — sliding-window Survey with only stale activity DOES expire', async () => {
        // Negative control: same window, but last activity was 60 min ago
        // (> Z=30) — the deadline has genuinely passed, so the daemon must
        // still expire it. Proves the re-arm isn't a blanket "never expire".
        const f = await provision({
            x: 0, y: 0, z: 30, items: 1, withUnitSession: true,
            name: 'e2e-audit-f4-stale',
        });
        const usId = f.unit_session_id;

        setUnitSessionCreated(usId, 120);
        setItemsDisplaySaved(usId, 60);       // last active 60 min ago
        setUnitSessionExpires(usId, 10);
        dbExecRaw(`UPDATE survey_unit_sessions SET queued = ${QUEUED_TO_END} WHERE id = ${usId}`);

        const res = runQueueOnce();
        expect(res.ok, `queue ran cleanly: ${res.stderr || ''}`).toBe(true);

        const row = dbState(usId);
        expect(row.expired, 'genuinely-overdue participant IS expired').not.toBeNull();
    });
});
