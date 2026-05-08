# Survey-expiry / unfinished-state plan

Companion to `EXPIRY_AUDIT.md`. Where the audit is the *findings*, this
file is the *plan-of-work* for the fix branch — the running state of
decisions, written so a future contributor (or me, six months from now)
can pick up where this left off.

## Starting point

A prod report flagged four symptom shapes on the AMOR ESM run:

- **A**: `survey_unit_sessions.ended IS NULL AND expired IS NULL` with
  populated results-row (interrupted mid-survey).
- **B**: `ended IS NOT NULL` with no results-row (despite required items).
- **C**: early aborts on a *second* run beyond AMOR.
- **D**: ~80 cases on each of two specific Pause units that were skipped.

We built an e2e characterisation suite against the dev formr_db
(`tests/e2e/{expiry-fixture,survey-symptoms,survey-expiry-matrix,
survey-unfinished-pathways}.spec.js`, plus the `bin/expiry_*` PHP
helpers) and produced `EXPIRY_AUDIT.md` consolidating the divergences.

## Prod investigation refined the diagnosis

Running `tests/e2e/prod_expiry_audit.sql` against the AMOR run on prod
showed two things the original analysis missed:

1. **The "access window is off" claim was wrong for the dropping
   surveys.** AMOR's ESM at positions 129/133/137 has X=60, Y=0, Z=0
   (the W4.a wiki-divergence cell), the EOD at 142 has X=180/Y=30,
   the screening surveys at 113/148/153 have X=2040 (34h). Only the
   Tzero-phase surveys are X=Y=Z=0. So the original W4.a hypothesis
   *is* an active cause: 171 ESM surveys (57 × 3 ESM time-slots) have
   `expired IS NOT NULL` directly from the X-rule unconditional override
   in `Survey.php:139-141`.

2. **The cron-driven "stale reference" branch at `RunSession.php:247-251`
   is firing on real participants while they're filling surveys.**
   `tests/e2e/EXPIRY_AUDIT.md` §1 / §8 / §9 walkthrough captures one
   participant (`run_session_id=...`) where Survey 471879 (the 13:45
   ESM, `result='survey_filling_out'`) was orphaned to `queued=-9`
   1m51s after the participant started filling. The mechanism: the
   queue daemon snapshot included a stale reference; `execute()` saw
   `referenceUnitSession.id != currentUnitSession.id`, hit the line-247
   branch, called `removeItem` AND `moveOn()`. moveOn advanced the run
   one position past the participant's active Survey, and the
   subsequent `createUnitSession`'s blanket supersede in
   `UnitSession::create():66-70` flipped the still-active Survey to
   `queued=-9`. The participant's run-session position is now on a
   later unit; their next request lands somewhere else.

3. **`§0` inventory totals confirm the magnitude.** 40 queued=-9
   NULL/NULL ESM Survey orphans per ESM time-slot (120 total), 45
   Pause orphans across positions 122/130/134/138, and 57
   X-rule-expired ESM surveys per time-slot (171 total). All within
   the last 14 days.

## Fix plan

Three fixes ship in this branch, plus two hygiene cleanups. Each has
a regression test already in the e2e suite; the test annotations
(`test.fail()`) flip from "expected divergence" to "clean assertion"
as each fix lands.

### Fix 1 — Stop the cron's stale-reference branch from advancing the run

**File:** `application/Model/RunSession.php:247-251`

The branch:

```php
} elseif ($referenceUnitSession && $currentUnitSession && $referenceUnitSession->id != $currentUnitSession->id) {
    UnitSessionQueue::removeItem($referenceUnitSession->id);
    return $this->moveOn();           // ← BUG: advances the run while a
}                                     //   different unit is active
```

When the queue daemon picks up a unit-session whose run-session has
already moved past it (the daemon snapshotted before the run advanced,
or a back-jump bumped the reference's position), the only legitimate
action is to drop the stale reference. Calling `moveOn()` advances the
run-session past the participant's currently-active unit, and the
subsequent `createUnitSession` in moveOn supersedes the active unit's
queue entry to `queued=-9` — the participant's filling becomes
unreachable.

**Replacement:**

```php
} elseif ($referenceUnitSession && $currentUnitSession && $referenceUnitSession->id != $currentUnitSession->id) {
    // The queue handed us a stale reference (its unit-session is no
    // longer the active one for this run-session). Drop the reference;
    // the active unit-session is legitimate and the cron has nothing
    // to do for THIS reference. Returning empty body lets the cron
    // continue with the next snapshot row.
    UnitSessionQueue::removeItem($referenceUnitSession->id);
    return ['body' => ''];
}
```

**Test gates:** none of the existing tests assert this branch's wrong
behaviour, so the fix doesn't flip any `test.fail()`. But the prod
diagnostic (`prod_expiry_audit.sql §1, §2, §8`) should drop to near
zero queued=-9 NULL/NULL Pauses and Surveys after deployment + 14d.

### Fix 2 — Scope the supersede side-effect to same `unit_id`

**File:** `application/Model/UnitSession.php:66-70`

The current supersede:

```php
$this->db->update('survey_unit_sessions', ['queued' => -9], [
    'run_session_id' => $this->runSession->id,
    'id <>' => $this->id,
    'queued >' => 0,
]);
```

Flips ALL queued siblings in the run-session to -9, regardless of
unit_id. Original purpose: prevent two active unit-sessions for the
same unit (e.g., a back-jump creates a new unit-session for unit X
while a previous one is still queued). But the blanket scope also
flips legitimate queued sibling units (a queued ESM Survey while a
new Pause is being created from a moveOn cascade). That's the second
half of the AMOR Symptom-A pathway — the moveOn from Fix 1's
problematic branch does spurious supersede.

**Replacement:** add `unit_id` to the WHERE so we only flip duplicates
of the same unit, leaving unrelated queued siblings intact.

```php
$this->db->update('survey_unit_sessions', ['queued' => -9], [
    'run_session_id' => $this->runSession->id,
    'unit_id'        => $this->unit_id,    // ← NEW: scope to this unit only
    'id <>'          => $this->id,
    'queued >'       => 0,
]);
```

**Test gates:**
- `survey-unfinished-pathways.spec.js::U10` (SkipBackward duplicate
  for same unit_id) still asserts queued=-9 — unchanged.
- `U13` (cross-unit_id supersede on AMOR's Pause→Survey pattern)
  flips: the queued Pause is no longer flipped to -9 by a Survey
  createUnitSession. Need to update the test's assertion: post-fix,
  the queued Pause stays at queued=2.

### Fix 3 — Rewrite `Survey::getUnitSessionExpirationData` to match the wiki spec

**File:** `application/Model/RunUnit/Survey.php:116-163`

Replace the three-rule sequential overwrite with the wiki's pre/post-
access combination form:

```php
public function getUnitSessionExpirationData(UnitSession $unitSession) {
    $X = (int) $this->surveyStudy->expire_invitation_after;
    $Y = (int) $this->surveyStudy->expire_invitation_grace;
    $Z = (int) $this->surveyStudy->expire_after;

    if ($X === 0 && $Z === 0) {
        // No deadline either pre- or post-access. Y alone is degenerate
        // (the wiki doesn't define behaviour for it; preserve the
        // empty-data signal so callers don't queue).
        return [];
    }

    $now = time();
    $invitation_sent = strtotime($unitSession->created);
    $first_submit_str = $this->getUnitSessionFirstVisit(
        $unitSession,
        'survey_items_display.saved != "' . $unitSession->created . '"'
    );
    $started = $first_submit_str !== null
        && strtotime($first_submit_str)
        && (strtotime($first_submit_str) - $invitation_sent) > 2;

    if (!$started) {
        // Pre-access: only X applies. (Z would only apply post-access
        // per the wiki — without a true first_submit, last_active is
        // just items_display.created which is roughly invitation_sent,
        // so applying Z here would just wrongly clip to invitation+Z.)
        $expires = $X > 0 ? $invitation_sent + $X * 60 : 0;
    } else {
        // Post-access: combine rules with MIN, falling back to "never"
        // if both Y and Z are zero.
        $candidates = [];
        if ($Y > 0) {
            // Wiki: "users that accessed your survey have at most X+Y
            // minutes to fill out the survey". Anchored on invitation,
            // not first_submit.
            $candidates[] = $invitation_sent + ($X + $Y) * 60;
        }
        if ($Z > 0) {
            $last_active_str = $this->getUnitSessionLastVisit($unitSession);
            if ($last_active_str !== null && strtotime($last_active_str)) {
                $candidates[] = strtotime($last_active_str) + $Z * 60;
            }
        }
        $expires = $candidates ? min($candidates) : 0;
    }

    $data = [
        'expires'  => max(0, $expires),
        'expired'  => ($expires > 0) && ($now > $expires),
        'queued'   => UnitSessionQueue::QUEUED_TO_END,
    ];
    return $data;
}
```

**Test gates:** the matrix `test.fail()`-tagged tests flip:
- `W1.a, W1.b, W2.a, W2.b, W4.a, W5.a` should now assert cleanly
  (the wiki prediction matches the new code).
- After the fix, remove the `test.fail()` annotations from those tests.
- `W4.b, W5.b, W5.c` and the `B*` boundary tests already pass and
  stay green.

### Hygiene 4 — `UnitSession::end()` resets `queued`

**File:** `application/Model/UnitSession.php:234-246`

`expire()` resets `queued = 0`; `end()` does not. The asymmetry is
masked by the queue daemon's `removeItem` after end-via-cron, but
exposed when `end()` runs from any other path (admin, dangling-end,
participant flow). Add `queued = 0` to the second UPDATE in `end()`
so post-end state is unambiguous.

**Test gate:** `survey-symptoms.spec.js::P4` currently asserts `queued
== 2` after a non-cron end(). Post-fix that flips to `queued == 0`;
update the assertion. Side benefit: `prod_expiry_audit.sql §4` will
have a much smaller `ended_with_q != 0` rowset, making future
diagnostics cleaner.

### Hygiene 5 — `UnitSession::end()` honours the caller's `$reason`

**File:** `application/Model/UnitSession.php:209-218`

For Survey and External, `end()` overwrites the explicit `$reason`
argument with a hardcoded `'survey_ended'` / `'external_ended'`. This
loses audit trail (e.g., a queued Survey ended via the run-session-
ended cron path with `reason='ended_by_queue_rse'` shows up as
`'survey_ended'` in the DB).

**Replacement:** if `$reason` is non-null, use it. The hardcoded
defaults remain for the no-`$reason` case.

```php
if ($unit->type == "Survey" || $unit->type == "External") {
    if ($unit->type == "Survey") {
        $query = "UPDATE `{$unit->surveyStudy->results_table}` SET `ended` = NOW() WHERE `session_id` = :session_id AND `study_id` = :study_id AND `ended` IS null";
        $params = array('session_id' => $this->id, 'study_id' => $unit->surveyStudy->id);
        $this->db->exec($query, $params);
    }
    if ($reason !== null) {
        $this->result = $reason;
    } else {
        $this->result = $unit->type == "Survey" ? "survey_ended" : "external_ended";
    }
} else {
    // ...existing else branch unchanged...
}
```

**Test gate:** `survey-symptoms.spec.js::B1` currently asserts
`result == 'survey_ended'` after `end('ended_by_queue_rse')`. Post-fix
that flips to `result == 'ended_by_queue_rse'`; update the assertion.

## Skipped this round

- **W4 / queue trusts stored `expires`** (audit §5). Fixing it requires
  the queue's `endCurrentUnitSession` for Survey to recompute the
  deadline before calling `expire()`. Lower-priority once Fix 3 makes
  the stored `expires` agree with the algorithm's verdict.
- **Phase 5 JS / UI drift tests** — out of scope until a participant-
  observable issue is reported.
- **U7 validation race** — needs item-type support in the fixture
  (`text` items don't validate-fail). Tag with `test.skip` until
  follow-up.

## Verification plan

After Fix 1 + 2 + 3 + Hygiene 4 + 5:

1. All e2e specs pass without `test.fail()` annotations:
   - `npm run test:e2e -- expiry-fixture.spec.js`
   - `npm run test:e2e -- survey-symptoms.spec.js`
   - `npm run test:e2e -- survey-expiry-matrix.spec.js`
   - `npm run test:e2e -- survey-unfinished-pathways.spec.js`

   The 6 previously-divergent matrix cells (W1.a/b, W2.a/b, W4.a, W5.a)
   assert wiki predictions cleanly.

2. Re-run `tests/e2e/prod_expiry_audit.sql` against AMOR 7-14 days
   after deployment. Expected:
   - §0/§2 queued=-9 NULL/NULL Survey orphans drop to near zero.
   - §1/§1b queued=-9 NULL/NULL Pause orphans drop to near zero.
   - §0 X-rule-expired ESM Surveys (`total_expired` column at positions
     129/133/137) trend toward zero unless participants legitimately
     don't visit at all (in which case they expire at invitation+X
     correctly per pre-access rule of Fix 3).
   - §4 ended-with-queued!=0 rows drop substantially due to Hygiene 4.

3. Spot-check 10 recent run-sessions via §9 walkthrough: no
   `result='survey_filling_out'` rows with `queued=-9, NULL/NULL`.
