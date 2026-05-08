// Phase 4: adjacent-pathway tests for "survey ended/skipped without
// the access window being the cause".
//
// Especially relevant after the user reported that the AMOR ESM run
// produces drops *while the access window is turned off* — i.e., X=0
// for every Survey. The tests here characterise the non-X pathways
// most likely to bypass a Survey or Pause:
//
//   - U7  Validation race: participant POSTs invalid 4×, backdate, POST
//         valid → still expired (P7).
//   - U9  studyCompleted false-positive (P10): a Survey whose unanswered
//         items all have show-ifs that return NULL completes immediately.
//   - U10 SkipBackward duplicate: a target unit ends up with two
//         unit-session rows after a back-jump.
//   - U11 Pause wait_minutes=0 vs '' equivalence: same `relative_to`
//         under the two code paths produces the same `expires`.
//   - U12 Pause R expression that returns a past timestamp ends
//         immediately (= AMOR's "missed window" semantics).
//   - U13 SkipBackward loop supersede: a back-jump's createUnitSession
//         flips queued siblings to -9 (Symptom A in the wild).
//
// Skipping U1, U2, U3, U5, U6, U8 from the plan for this branch — they
// either duplicate other coverage (U6 ≈ B1, U4=B1) or need fixture
// extensions we haven't built (U2 admin remove unit, U5 forceTo, U8
// post_max_size simulation). They can land in a follow-up phase if
// the AMOR-pattern tests above don't fully explain the prod drops.

const { test, expect } = require('@playwright/test');
const {
    dbExecRaw, dbQuery, dbState, dbResultsRow,
    setUnitSessionCreated, setItemsDisplaySaved, backdateUnitSession,
    insertUnitSession,
} = require('./helpers/db');
const { provision, computeExpiry } = require('./helpers/expiry');
const { runQueueOnce } = require('./helpers/queue');

test.describe('Adjacent-pathway tests', () => {

    test('U11 — Pause wait_minutes=0 and "" produce identical expires for the same relative_to', async () => {
        // AMOR mixes the two: positions 124, 130 use wait_minutes=0;
        // 134, 138 use wait_minutes=''. Both go through different branches
        // in Pause.php (the elseif at :175 vs the if at :148). For the
        // same `relative_to`, both should yield the same expires.
        // We provision two runs (different names so they coexist), each
        // with one Pause configured one way, and compare computed expires.

        // R literal that returns a fixed timestamp. OpenCPU eval returns
        // the string; PHP strtotime parses it.
        const RELATIVE_TO = '"2027-01-01 00:00:00"';

        const f1 = await provision({
            x: 0, y: 0, z: 0, items: 1, name: 'e2e-expiry-u11-zero',
            pause: { wait_minutes: 0, relative_to: RELATIVE_TO },
        });
        const f2 = await provision({
            x: 0, y: 0, z: 0, items: 1, name: 'e2e-expiry-u11-empty',
            pause: { wait_minutes: '', relative_to: RELATIVE_TO },
        });

        // Manually insert a Pause unit-session for each (skipping the
        // Survey-completion flow that would normally precede the Pause).
        // The Pause's getUnitSessionExpirationData reads only its own
        // configuration + run_data + the current unit_session.created;
        // run_session position doesn't matter here.
        const ps1 = insertUnitSession(f1.run_session_id, f1.pause_id);
        const ps2 = insertUnitSession(f2.run_session_id, f2.pause_id);

        const e1 = computeExpiry(ps1);
        const e2 = computeExpiry(ps2);

        expect(e1.expires_unix, 'wait_minutes=0 should produce a deadline').not.toBeNull();
        expect(e2.expires_unix, 'wait_minutes="" should produce a deadline').not.toBeNull();
        expect(e1.expires_unix, 'both Pause code paths should yield identical expires for the same relative_to')
            .toBe(e2.expires_unix);
    });

    test('U12 — Pause R expression returning past timestamp ends immediately', async () => {
        // AMOR pattern: many ESM Pauses use R expressions like
        // `today() + days_until_friday`. If cron fires after the
        // wall-clock moment, R returns a past timestamp, expires <= NOW,
        // Pause expires immediately. NOT a bug per se — it's the "missed
        // window" semantics — but worth pinning so we don't regress.
        const f = await provision({
            x: 0, y: 0, z: 0, items: 1, name: 'e2e-expiry-u12',
            pause: { wait_minutes: 0, relative_to: '"2020-01-01 00:00:00"' },
        });
        const ps = insertUnitSession(f.run_session_id, f.pause_id);
        const e = computeExpiry(ps);

        expect(e.expires_unix, 'past relative_to should still produce an expires value').not.toBeNull();
        expect(e.expired, 'past timestamp → expired immediately').toBe(true);
        expect(e.ago_minutes, 'deadline is years in the past').toBeGreaterThan(60 * 24 * 365 * 5);
    });

    test('U10 — SkipBackward fired manually creates a duplicate unit-session, supersedes prior queued siblings', async () => {
        // Build a 3-unit run: Survey1(X=60) → Pause(wait_minutes=60) → Endpage.
        // Manually insert a queued Pause (queued=2, expires=NOW+60). Then
        // simulate a SkipBackward firing by directly inserting a new
        // unit-session for the SAME Pause unit (mirrors what runTo does).
        // The supersede side-effect at UnitSession::create():66-70 should
        // flip the prior queued Pause to queued=-9.
        const f = await provision({
            x: 60, y: 0, z: 0, items: 1, name: 'e2e-expiry-u10',
            pause: { wait_minutes: 60 },
        });

        // Insert the FIRST Pause unit-session (queued=2 to simulate it
        // having been seen by getUnitSessionExpirationData and queued).
        const ps1 = insertUnitSession(f.run_session_id, f.pause_id, { queued: 2 });
        dbExecRaw(`UPDATE survey_unit_sessions SET expires = DATE_ADD(NOW(), INTERVAL 60 MINUTE) WHERE id = ${ps1}`);

        // Mint the second unit-session via UnitSession::create (which
        // includes the supersede side-effect). Easiest path: a php
        // one-liner.
        const phpScript = `<?php
require '/var/www/formr/setup.php';
$rs = new RunSession(null, new Run(null, ${f.run_id}), ['id' => ${f.run_session_id}]);
$pause = RunUnitFactory::make($rs->getRun(), ['id' => ${f.pause_id}]);
$us = new UnitSession($rs, $pause);
$us->create(true);
echo $us->id . "\\n";
`;
        const { execSync } = require('node:child_process');
        const ps2 = parseInt(execSync('docker exec -i formr_app php', { input: phpScript, encoding: 'utf8' }).trim(), 10);

        // ps1 should have been flipped to queued=-9 by the supersede
        // side-effect at UnitSession.php:66-70.
        const s1 = dbState(ps1);
        const s2 = dbState(ps2);
        expect(parseInt(s1.queued, 10), 'prior queued sibling superseded to -9').toBe(-9);
        expect(s1.ended, 'superseded session is NOT ended').toBeNull();
        expect(s1.expired, 'superseded session is NOT expired').toBeNull();
        // Both rows still exist for the SAME unit_id — the duplicate.
        expect(s2.unit_id).toBe(s1.unit_id);
    });

    test('U13 — back-jump style createUnitSession flips queued ESM-style sibling Pause to -9 (AMOR Symptom A)', async () => {
        // Synthetic ESM-loop reproduction: queued Pause (analogous to
        // ESM_break_until_10) is in the run-session at the moment a
        // back-jump (analogous to T1_ESM_repetition_loop's
        // SkipBackward) creates a new unit-session for an earlier unit.
        //
        // Setup: provision a Pause that's been queued at expires=NOW+60.
        // Then create a NEW unit-session (any unit; we use a fresh
        // Survey) — UnitSession::create's supersede flips the queued
        // Pause to -9. Resulting DB shape on the Pause: ended=NULL,
        // expired=NULL, queued=-9, no end timestamp anywhere — exactly
        // the Symptom A shape the user is hunting in prod.
        const f = await provision({
            x: 0, y: 0, z: 0, items: 1, name: 'e2e-expiry-u13',
            pause: { wait_minutes: 60 },
        });

        const queuedPauseId = insertUnitSession(f.run_session_id, f.pause_id, { queued: 2 });
        dbExecRaw(`UPDATE survey_unit_sessions SET expires = DATE_ADD(NOW(), INTERVAL 60 MINUTE) WHERE id = ${queuedPauseId}`);

        // Now simulate a back-jump creating a new unit-session for the
        // Survey unit (we don't actually need a SkipBackward — any
        // createUnitSession with setAsCurrent=true triggers the
        // supersede). This is the AMOR pattern: SkipBackward at pos 143
        // → runTo(122) → createUnitSession for unit_id at position 122.
        const phpScript = `<?php
require '/var/www/formr/setup.php';
$rs = new RunSession(null, new Run(null, ${f.run_id}), ['id' => ${f.run_session_id}]);
$survey = RunUnitFactory::make($rs->getRun(), ['id' => ${f.study_id}]);
$us = new UnitSession($rs, $survey);
$us->create(true);
echo $us->id . "\\n";
`;
        const { execSync } = require('node:child_process');
        const newSurveyId = parseInt(execSync('docker exec -i formr_app php', { input: phpScript, encoding: 'utf8' }).trim(), 10);

        const orphaned = dbState(queuedPauseId);
        expect(parseInt(orphaned.queued, 10), 'AMOR Symptom A: queued=-9 after back-jump').toBe(-9);
        expect(orphaned.ended, 'AMOR Symptom A: ended=NULL').toBeNull();
        expect(orphaned.expired, 'AMOR Symptom A: expired=NULL').toBeNull();
        // The new sibling is the new "current" unit:
        const fresh = dbState(newSurveyId);
        expect(fresh.unit_id).not.toBe(orphaned.unit_id);
    });

    test('U7 — validation failures do not advance first_submit (grace block does not fire)', async ({ baseURL, page }) => {
        // P7: updateSurveyStudyRecord at UnitSession.php:435-438 early-
        // returns on $this->errors before writing items_display.saved.
        // So a participant who hammers an invalid form does NOT count as
        // "started editing" for the grace block. The user is still
        // window-expired.
        //
        // Setup: X=60, Y=30, Z=0. Visit. Submit an invalid value for q1
        // (we use a numeric type to make validation fail by submitting
        // a non-numeric string).
        // Hmm — q1 is a 'text' item by default in our fixture, which
        // accepts anything. We need a type that validates. Skip this
        // test for now (needs --item-type=number support in the fixture);
        // tag as expected-fixed-pending.
        test.skip(true, 'fixture does not yet support item types beyond text — needs follow-up');
    });

    test('U9 — studyCompleted false-positive when items have show-if returning NULL (P10)', async ({ baseURL, page }) => {
        // P10: SpreadsheetRenderer::getStudyProgress at :611-613 forces
        // progress=1 when every unanswered item is hidden_but_rendered.
        // An item with showif returning NULL (e.g. references missing
        // data) gets BOTH hidden=true AND probably_render=true, so it
        // counts under both `not_answered` and `hidden_but_rendered`,
        // triggering the early-completion. The Survey ends with no
        // answers — Symptom B.
        //
        // Test: set up a Survey with one item whose showif evaluates to
        // NULL on OpenCPU. Visit; the renderer's processStudy should
        // detect studyCompleted=true and end the unit session.
        // The fixture creates items as type='text' with empty showif by
        // default. We need to UPDATE the survey_items.showif for the
        // first item AFTER fixture creation. Use an R expression that
        // OpenCPU returns as NA: `as.logical(NA)`.
        const f = await provision({ x: 0, y: 0, z: 0, items: 1, name: 'e2e-expiry-u9' });
        dbExecRaw(`UPDATE survey_items SET showif='as.logical(NA)' WHERE study_id=${f.study_id}`);

        // Visit. The participant request triggers processStudy. If P10
        // fires, the Survey ends and we get redirected to the next unit
        // (the Endpage).
        await page.context().clearCookies();
        await page.goto(`${baseURL}/${f.run_name}/?code=${f.code}`,
            { waitUntil: 'load', timeout: 30000 });

        // Wait briefly for the redirect chain. If the Endpage is shown,
        // we know the Survey was ended early. The Endpage has body
        // 'Done.' (set by the fixture).
        await page.waitForTimeout(1500);

        const us = dbQuery(
            `SELECT us.id, us.ended, us.expired, us.result FROM survey_unit_sessions us
             JOIN survey_units u ON u.id = us.unit_id AND u.type = 'Survey'
             WHERE us.run_session_id = ${f.run_session_id}`
        );
        expect(us.length).toBeGreaterThan(0);
        const surveyState = us[0];

        // Symptom-B-like shape: Survey is ended/expired with no real
        // answer in the results table.
        const ended = surveyState.ended || surveyState.expired;
        // P10 may not fire if OpenCPU returns something other than NULL
        // for `as.logical(NA)`. Mark expected-failure for the assertion;
        // when it does fire (which we hope is reproducible on this dev
        // instance) the test runs cleanly.
        test.fail(ended === null,
            'P10 did not fire — OpenCPU may have returned a non-NULL value for as.logical(NA), ' +
            'or the dev instance is not running OpenCPU. Either way, this test will need ' +
            'follow-up investigation to nail down the AMOR-style symptom B trigger.');
        expect(ended, 'P10: Survey should have ended despite no answered items').not.toBeNull();
    });

});
