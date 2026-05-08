// Phase 3: wiki-cell matrix.
//
// Each test mirrors one bullet of the Expiry wiki page
// (https://github.com/rubenarslan/formr.org/wiki/Expiry). The wiki is
// the canonical spec; the in-app help text agrees in some places and
// diverges in others (notably: in-app help says "If the invitation is
// still valid (see above), this value doesn't count" for inactivity,
// which agrees with the code's X-overrides-Z; the wiki disagrees).
//
// The tests assert the WIKI's prediction by directly invoking
// getUnitSessionExpirationData() (via bin/expiry_compute.php) — NOT
// by running the queue daemon. That's because the queue's END-q branch
// at RunSession.php:242-245 calls endCurrentUnitSession() which calls
// expire() on Survey unconditionally — no recomputation of the deadline.
// The queue trusts the stored `expires` column, so testing the queue's
// behaviour conflates the algorithm's verdict with the queue's
// trust-but-no-verify. We isolate the algorithm here.
//
// When code's algorithm diverges from the wiki, the test is tagged
// `test.fail()` so the suite stays green pre-fix. When the eventual
// fix lands and a tagged test starts asserting cleanly, `test.fail()`
// flips it to RED — the cue to remove the tag.

const { test, expect } = require('@playwright/test');
const {
    setUnitSessionCreated, setItemsDisplaySaved, setItemsDisplayCreated,
} = require('./helpers/db');
const { provision, computeExpiry } = require('./helpers/expiry');

// All matrix tests share the "with-unit-session" fixture path: skip
// the Playwright visit, pre-create the unit-session and items_display
// rows via PHP. Faster, and avoids the 1-2s drift between Playwright
// visit and DB-state-setting that would otherwise tilt edge cases.
async function setup({ x, y, z, items = 1 }) {
    return provision({ x, y, z, items, withUnitSession: true });
}

test.describe('Wiki spec compliance — survey expiry algorithm', () => {

    // ---------------------------------------------------------------
    // Wiki Scenario #1: X=420, Y=30, Z=0
    // "Invitation should be open for 7h; can edit at most 7.5h."
    // ---------------------------------------------------------------

    test('W1.a — access at 2:30, fills (wiki: still alive 5h before X+Y deadline)', async () => {
        // Post Fix-3: deadline = invitation + X+Y = 7:30h after invitation.
        // At 2:30h elapsed with one submit, ~5h remaining → expired=false.
        // Pre-fix: grace block anchored on first_submit + Y, so first submit
        // at -2:30 + Y=30 = -2h → already expired (FALSE divergence).
        const f = await setup({ x: 420, y: 30, z: 0, items: 1 });
        setUnitSessionCreated(f.unit_session_id, 150);                      // -2:30h
        setItemsDisplaySaved(f.unit_session_id, 145, f.item_ids[0]);        // 5min after invitation
        const e = computeExpiry(f.unit_session_id);

        expect(e.expired, '5h remaining of X+Y window').toBe(false);
    });

    test('W1.b — access at 6:50 then idle (wiki: deadline at 7:30; code: 7:00)', async () => {
        // Two snapshots: at 6:59h (before either deadline), and at 7:01h
        // (past code's old X-rule but before wiki's X+Y deadline). Post
        // Fix-3 the X-rule no longer fires for users who never started
        // editing only after invitation+X+Y if Y is set; with no
        // first_submit (the user only loaded the page), the pre-access
        // path applies: deadline = invitation + X = 7:00h. So both 6:59h
        // (alive) and 7:01h (expired) match the code behaviour.
        // Note this case is identical to wiki behaviour: pre-access X
        // applies regardless of Y — Y is the post-access add-on.
        const f = await setup({ x: 420, y: 30, z: 0, items: 1 });

        setUnitSessionCreated(f.unit_session_id, 419);                      // -6:59h
        // No items_display.saved (user only loaded the page, never POSTed).
        let e = computeExpiry(f.unit_session_id);
        expect(e.expired, '6:59h: both wiki and code say alive').toBe(false);

        setUnitSessionCreated(f.unit_session_id, 421);                      // -7:01h
        e = computeExpiry(f.unit_session_id);
        expect(e.expired, 'never-accessed user expires at invitation+X').toBe(true);
    });

    // ---------------------------------------------------------------
    // Wiki Scenario #2: X=420, Y=0, Z=30
    // "Once started, can string out for hours via inactivity (Z slides)."
    // ---------------------------------------------------------------

    test('W2.a — string out for hours (wiki: Z slides, can run indefinitely)', async () => {
        // Post Fix-3: Y=0 ⇒ no X+Y deadline, only Z. last_active=now-5min,
        // Z=30 ⇒ deadline=now+25min ⇒ alive. Wiki #2's "stringing out for
        // hours" promise is now honoured.
        // Pre-fix: X-rule unconditionally overwrote Z; expires=invitation+X
        //          = -8h + 7h = -1h ⇒ expired (broke the promise).
        const f = await setup({ x: 420, y: 0, z: 30, items: 1 });
        setUnitSessionCreated(f.unit_session_id, 480);                      // -8h
        setItemsDisplaySaved(f.unit_session_id, 5, f.item_ids[0]);          // last_active 5min ago
        const e = computeExpiry(f.unit_session_id);

        expect(e.expired, 'Z=30 sliding, last_active 5min ago → 25min remaining').toBe(false);
    });

    test('W2.b — idle 40 min after access at 6:50 (wiki: 7:20 from Z; code: 7:00 from X)', async () => {
        // Post Fix-3: Y=0 ⇒ post-access deadline = last_active + Z =
        // -6:50h + 30min = -6:20h ago. Snapshot at -7:05h elapsed since
        // invitation, so deadline is past → expired=true.
        // Pre-fix: X-rule fired at -7:00 (15min earlier than wiki).
        // Both agree on "expired" at this snapshot — the difference is
        // the *timing*, which W2.a above isolates.
        const f = await setup({ x: 420, y: 0, z: 30, items: 1 });
        setUnitSessionCreated(f.unit_session_id, 425);                      // -7:05h
        setItemsDisplaySaved(f.unit_session_id, 410, f.item_ids[0]);        // last_active -6:50h ago
        const e = computeExpiry(f.unit_session_id);

        expect(e.expired, 'last_active+Z = -6:20 → expired').toBe(true);
    });

    // ---------------------------------------------------------------
    // Wiki Scenario #3: X=420, Y=180, Z=30
    // "Edit at most 10h, but only if active. Z=30 floor."
    // ---------------------------------------------------------------

    test('W3.a — active user 5min before X+Y deadline (wiki: alive; code: maybe expired)', async () => {
        // WIKI: deadline = MIN(invitation+X+Y=10h, last_active+Z=5min+30min) → 5min remaining at 9:55h.
        // CODE: grace fires; expires = first_submit + Y*60.
        //       Single-item simplification: first_submit ≈ last_active ≈ 5min ago.
        //       => first_submit + 180min = 175min ahead. Code agrees alive in this case.
        // Skip the divergence test until we have multi-item fixture support
        // for distinguishing first vs last submit; both predict alive here.
        const f = await setup({ x: 420, y: 180, z: 30, items: 1 });
        setUnitSessionCreated(f.unit_session_id, 595);                      // -9:55h
        setItemsDisplaySaved(f.unit_session_id, 5, f.item_ids[0]);          // last_active 5min ago
        const e = computeExpiry(f.unit_session_id);
        expect(e.expired, 'single-item: both predict alive (175min ahead)').toBe(false);
    });

    // ---------------------------------------------------------------
    // Wiki Scenario #4: X=420, Y=0, Z=0 — THE PROD-REPORT CELL
    // "Once started, unlimited time."
    // ---------------------------------------------------------------

    test('W4.a — accessed long ago, wiki: unlimited (post-fix matches)', async () => {
        // Post Fix-3: X=420 only applies pre-access. Once accessed
        // (first_submit > invitation+2s), Y=0 + Z=0 ⇒ never expire.
        // This was the originally-reported prod bug; now fixed.
        const f = await setup({ x: 420, y: 0, z: 0, items: 1 });
        setUnitSessionCreated(f.unit_session_id, 480);                      // -8h
        setItemsDisplaySaved(f.unit_session_id, 470, f.item_ids[0]);        // first_submit -7:50h
        const e = computeExpiry(f.unit_session_id);

        expect(e.expired, 'accessed user with Y=Z=0 → unlimited time').toBe(false);
        expect(e.expires_unix, 'no deadline computed post-access when Y=Z=0').toBeNull();
    });

    test('W4.b — never accessed, wiki & code agree at 7:00', async () => {
        const f = await setup({ x: 420, y: 0, z: 0, items: 1 });
        setUnitSessionCreated(f.unit_session_id, 421);                      // -7:01h
        // No items_display.saved set (user never POSTed).
        const e = computeExpiry(f.unit_session_id);
        expect(e.expired, 'X=7h passed without access → expired').toBe(true);
    });

    // ---------------------------------------------------------------
    // Wiki Scenario #5: X=0, Y=0, Z=30
    // "Access window forever; once started, snooze 30 min."
    // ---------------------------------------------------------------

    test('W5.a — X=0 with no first_submit (wiki: never expires; post-fix matches)', async () => {
        // Post Fix-3: with X=0 and no first_submit > invitation+2s, the
        // pre-access path returns expires=0 (never). Z is post-access only
        // per the wiki. The fix moved Z out of the pre-access path so it
        // doesn't fire off items_display.created (which createSurveyStudyRecord
        // set to NOW on first visit even before any user input).
        const f = await setup({ x: 0, y: 0, z: 30, items: 1 });
        setUnitSessionCreated(f.unit_session_id, 600);                      // -10h
        setItemsDisplayCreated(f.unit_session_id, 600);                     // -10h, all items
        const e = computeExpiry(f.unit_session_id);

        expect(e.expired, 'X=0 → user never expires until they actually engage').toBe(false);
        expect(e.expires_unix, 'no pre-access deadline when X=0').toBeNull();
    });

    test('W5.b — accessed at 6:50 then idle, wiki & code: 7:20 (matches)', async () => {
        const f = await setup({ x: 0, y: 0, z: 30, items: 1 });
        setUnitSessionCreated(f.unit_session_id, 450);                      // -7:30h
        setItemsDisplaySaved(f.unit_session_id, 410, f.item_ids[0]);        // last_active -6:50h
        const e = computeExpiry(f.unit_session_id);
        expect(e.expired, 'last_active+Z = -6:50+30min = -6:20 → expired').toBe(true);
    });

    test('W5.c — steady activity, wiki & code: never expires (matches)', async () => {
        const f = await setup({ x: 0, y: 0, z: 30, items: 1 });
        setUnitSessionCreated(f.unit_session_id, 600);                      // -10h
        setItemsDisplaySaved(f.unit_session_id, 5, f.item_ids[0]);          // last_active 5min ago
        const e = computeExpiry(f.unit_session_id);
        expect(e.expired, 'last_active=now-5min, Z=30 → 25min remaining').toBe(false);
    });

    // ---------------------------------------------------------------
    // Boundary tests
    // ---------------------------------------------------------------

    test('B2 — Y alone (X=0, Z=0): degenerate, no rule fires', async () => {
        const f = await setup({ x: 0, y: 60, z: 0, items: 1 });
        setUnitSessionCreated(f.unit_session_id, 480);
        setItemsDisplaySaved(f.unit_session_id, 5, f.item_ids[0]);
        const e = computeExpiry(f.unit_session_id);
        // Post Fix-3: if both X and Z are 0, return [] early — no calc.
        expect(e.expires_unix, 'returns no expires when X and Z are 0').toBeNull();
        expect(e.expired, 'returns expired=false').toBe(false);
    });

    test('B3 — All-zero settings: never expires', async () => {
        const f = await setup({ x: 0, y: 0, z: 0, items: 1 });
        setUnitSessionCreated(f.unit_session_id, 999);
        setItemsDisplaySaved(f.unit_session_id, 5, f.item_ids[0]);
        const e = computeExpiry(f.unit_session_id);
        expect(e.expires_unix).toBeNull();
        expect(e.expired).toBe(false);
    });

    test('B4 — grace anchor uses real first_submit, not auto-saved fields', async () => {
        // For X=60, Y=30: if the only "saved" item is at invitation_sent
        // (auto-saved on createSurveyStudyRecord, like browser/IP fields),
        // the grace block's `first_submit > invitation+2s` guard at
        // Survey.php:152 should EXCLUDE it. Let's verify.
        const f = await setup({ x: 60, y: 30, z: 0, items: 1 });
        setUnitSessionCreated(f.unit_session_id, 30);                       // -30min, well within X
        // Set items_display.saved exactly at invitation_sent (no real
        // editing — saved at the same moment as creation).
        setItemsDisplaySaved(f.unit_session_id, 30, f.item_ids[0]);         // same moment as created
        const e = computeExpiry(f.unit_session_id);

        // Grace block requires first_submit - invitation > 2s, so it should
        // not fire. Deadline reverts to X-rule = invitation + 60min = +30min ahead.
        expect(e.expired).toBe(false);
        expect(e.ahead_minutes, 'X-rule deadline at +30min ahead (60min after invitation, currently at -30min)')
            .toBeGreaterThan(20);
    });

});
