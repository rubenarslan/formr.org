# Plan: e2e characterisation of Survey expiry + the two reported "unfinished" symptoms

## Context

A prod report surfaced four shapes:

- **Symptom A** — `survey_unit_sessions.ended IS NULL AND expired IS NULL`
  with a populated results row (looks like the participant was interrupted
  mid-survey).
- **Symptom B** — `ended IS NOT NULL` with **no** results row, despite all
  items being required.
- **Symptom C** — early abort observed in **at least one other survey**
  besides the originally-reported one. The reporter is "not entirely sure
  the early aborts are due to the access window" — so this may or may not
  be the same root cause as A. Treat C as "Symptom A or B occurring on a
  different study", *not* as a third distinct shape, until reproduction
  proves otherwise.
- **Symptom D** — **Pause skipped**: ~80 cases each on two different Pause
  units, where the Pause appears to have been bypassed entirely. No
  forensics yet ("Wenn es das nächste Mal vorkommt, werde ich Screenshots
  und co. machen"). Treat as a separate root-cause area:
  `Pause::getUnitSessionExpirationData` not Survey's.

Reading `application/Model/RunUnit/Survey.php:116-163` (the Survey expiry
calculation), `application/Model/UnitSession.php:181-249` (`expire()` /
`end()`), and `application/Model/RunUnit/Pause.php:110-251` (the Pause
expiry calculation) shows multiple paths consistent with each symptom.

The intended outcome of this branch is **not** a fix yet. It's a small
characterisation suite that produces the two symptom shapes deterministically
in CI, plus pins the most user-visible expiry divergence (the
originally-reported "X minutes after invitation, even though I'm typing"
case) so any future fix has an obvious regression test.

The user's draft plan went broad — 24 wiki cells × 10 unfinished pathways × 5
UI tests + a codebase audit. That's the right *eventual* scope but it
swallows this branch. This plan trims to: build the minimum fixture+DB
helpers, pin the two symptoms, pin two expiry divergences, stop. The broader
audit and the JS modal / lock-timeout work get tagged as follow-ups.

## What I verified by reading the code

The draft's "presumption" list survives mostly intact. Confirmed against the
files cited:

- **P1, P2 — X-rule unconditionally overwrites Z and the inactivity branch.**
  `Survey.php:132-134` writes `$expires` for the inactivity branch, then
  `Survey.php:139-141` overwrites it with `invitation_sent + X*60` whenever
  `expire_invitation_after !== 0` — no guard on whether the participant is
  active. Note: the in-app help text at
  `templates/admin/survey/index.php:148` actually *documents* this: "If the
  invitation is still valid (see above), this value doesn't count." So the
  in-app help and code agree on X-overrides-Z. The wiki page
  (https://github.com/rubenarslan/formr.org/wiki/Expiry) is the divergent
  source — it promises the combined-MIN semantics. The draft conflated
  these two docs; the only divergence we can pin without ambiguity is
  **wiki vs code**.
- **P3 — grace anchor.** `Survey.php:154` sets `$expires = first_submit +
  grace_period*60`. The wiki defines the deadline as `invitation_sent + X +
  Y`. Early starters get less total time than late starters. Real.
- **P4 — `end()` vs `expire()` asymmetry on `queued`.** `expire()` at
  `UnitSession.php:198` resets `queued = 0`; `end()` at `:234-246` does not
  touch `queued`. Real.
- **P5 — supersede orphan.** `UnitSession::create():66-70` sets `queued =
  -9` on every queued sibling unconditionally. `RunSession.php:247-251`
  triggers a moveOn when the queue daemon's reference session disagrees
  with the run's current unit. Both sites confirmed; need to reproduce the
  exact precondition.
- **P6 — `end()` on a never-visited Survey.** `UnitSession.php:211` updates
  the results table; if no row exists the UPDATE matches zero rows and
  returns success. The second UPDATE at `:234-246` then sets `ended = NOW()`
  on `survey_unit_sessions` regardless. Real.
- **P7 — validation race.** `UnitSession.php:435-438` early-returns on
  `$this->errors` *before* writing `survey_items_display.saved`. So failed
  POSTs don't count as "started editing" for the grace block. Real.
- **P8, P9, P10** — confirmed in code (`head.php:80-92` only emits `expires`
  on render; `RunSession.php:197` returns the 5 s reload HTML;
  `SpreadsheetRenderer.php:611-613` flips `progress = 1` when every item is
  hidden). All real; characterised in phase 5.

**Pause-skip (Symptom D) candidate paths**, from reading
`Pause.php:110-251` and `UnitSession.php:140-162`:

- **D-1** — `relative_to` evaluates to PHP `true`. Line 150-152 sets
  `$conditions['relative_to'] = '1=1'` and `$data['expire_relatively'] =
  true` but leaves `$data['expires']` empty. Line 228-244 runs the SQL
  test which evaluates `1=1` → `$result = true` → line 249 sets
  `$data['end_session'] = $data['expired'] = true`. Pause ends
  immediately on first execute. Easy to hit if the user-supplied R
  expression yields `TRUE` for many participants at once (date threshold
  passed, condition flipped on a column update).
- **D-2** — admin-config Pause with neither `wait_minutes` nor
  `relative_to` nor `wait_until_*`. `$conditions = []`, `$data['expires'] =
  null`, line 245-247 fallback `$result = true` → ends immediately.
  Reachable by misconfiguration.
- **D-3** — `wait_until_time` set, `wait_until_date` empty. Lines 201-207
  fill `wait_date = today` and synthesise `wait_datetime`. If the
  participant arrives after that wall-clock time *and* `wait_date_defined
  = false` (no explicit date), line 217-220 jumps the deadline 24 h
  forward. But if `wait_date_defined = true` and time has passed,
  `$data['expires']` is past → `end_session` flips. Edge case where the
  Pause was queued just before a daily threshold and the cron picks it
  up after.
- **D-4** — run-session-ended branch (`RunSession.php:204`) calls
  `referenceUnitSession->end('ended_by_queue_rse')` on a queued Pause;
  `UnitSession.php:222-223` rewrites the reason to `pause_ended`. Pause
  marked ended without waiting; participant skips it on next visit.
  Same root cause as Symptom B for Surveys.
- **D-5** — supersede (`UnitSession::create():66-70`) flips a queued
  Pause's `queued = -9` when a sibling unit becomes current. Symptom
  ≈ A for the Pause: `ended IS NULL, expired IS NULL, queued = -9`,
  participant has moved past it.

D-1, D-2, D-4, D-5 are testable; D-3 is testable but timing-fragile.

## What does NOT exist that the draft assumed

- `tests/e2e/survey-expiry.spec.js` — referenced as something to "replace".
  Doesn't exist.
- `expiry-fixture.php` — referenced as something to "promote". Doesn't exist.
- Any admin auth / study-mint helper for e2e. The existing specs target a
  pre-minted run/code on `study.researchmixtape.com` (`PWA_TEST_RUN`,
  `PWA_TEST_CODE`).

What *does* exist and is reusable:

- `tests/pwa_manifest_smoke.sh:34-39` proves the test environment can shell
  out to `docker exec formr_db mariadb …` to read/write the dev DB. That's
  the backdating channel.
- `tests/e2e/playwright.config.js` — `workers: 1`, `baseURL` from
  `FORMR_PARTICIPANT_URL`. Adequate as-is.
- `tests/e2e/helpers/test.js` — wraps `@playwright/test` for BS-aware
  contexts. New helpers go alongside, not inside this file.

## Scope of this branch

```
       ┌─────────────────────────────────────┐
       │  Phase 1: fixture + DB helpers      │
       └─────────────────┬───────────────────┘
                         │
       ┌─────────────────▼───────────────────┐
       │  Phase 2: Symptom A + B + D         │
       │  (the prod report; C is "A or B on  │
       │   a different study" → no new test) │
       └─────────────────┬───────────────────┘
                         │
       ┌─────────────────▼───────────────────┐
       │  Phase 3: full wiki-cell matrix     │
       │  (every row from the draft, all     │
       │   tagged test.fail() pre-fix)       │
       └─────────────────┬───────────────────┘
                         │
       ┌─────────────────▼───────────────────┐
       │  Phase 4: adjacent-pathway tests    │
       │  (validation race, post_max_size,   │
       │   studyCompleted, SkipBackward,     │
       │   end()-vs-queued, dangling-end)    │
       └─────────────────┬───────────────────┘
                         │
       ┌─────────────────▼───────────────────┐
       │  Phase 5: JS / UI drift tests       │
       │  (ExpiryNotifier, lock-timeout      │
       │   reload, modal markup)             │
       └─────────────────┬───────────────────┘
                         │
       ┌─────────────────▼───────────────────┐
       │  Phase 6: codebase audit            │
       │  → EXPIRY_AUDIT.md (divergence      │
       │     ledger, severity-tagged)        │
       └─────────────────────────────────────┘
```

Phases run in order — phases 2-5 need phase-1 helpers; phase 6 consumes the
divergence list produced by phases 2-5 and adds the static-read findings on
top.

## Phase 1 — fixture + DB helpers

Two new files, both in `tests/e2e/helpers/`, plus one PHP CLI fixture in
`bin/`. The PHP fixture is invoked via `docker exec formr_app php
bin/expiry_fixture.php …` from the test, the DB helper via `docker exec
formr_db mariadb …`. Both shell-out patterns are already proven by
`tests/pwa_manifest_smoke.sh`.

**`bin/expiry_fixture.php`** (new) — takes flags
`--x=<int> --y=<int> --z=<int> [--items=N] [--owner=<admin_email>]` and
prints JSON `{run_name, code, run_session_id, unit_session_id, study_id,
results_table, item_ids: [...]}`. Internally:

1. Loads the framework via `setup.php` (same as `bin/cron.php`).
2. Creates a SurveyStudy with the given X/Y/Z and N required text items.
3. Wraps it in a single-unit Run, sets `cron_active = 1`.
4. Mints a testing run-session (`testing = 1`) and queues the survey
   (`UnitSession::create()`) so a `survey_unit_sessions` row exists with
   real `created`/`expires`.

Reuse: `Run`, `SurveyStudy`, `RunSession`, `UnitSession::create()` — no new
domain model. Look at `bin/initialize.php` for the framework-bootstrap
pattern; `application/Model/Run.php` for run creation.

**`tests/e2e/helpers/db.js`** (new) — thin wrapper around `child_process`:

- `dbExec(sql, params)` — runs `docker exec formr_db mariadb -uroot
  -p$MARIADB_ROOT_PASSWORD $MARIADB_DATABASE -N -B -e <sql>`, returns
  rows.
- `dbState(unitSessionId)` — `SELECT id, created, expires, ended, expired,
  queued, result, result_log FROM survey_unit_sessions WHERE id = ?`.
- `dbResultsRow(resultsTable, unitSessionId)` — `SELECT * FROM
  <results_table> WHERE session_id = ?`. Returns `null` when absent.
- `backdateUnitSession(unitSessionId, minutes)` — `UPDATE survey_unit_sessions
  SET created = DATE_SUB(created, INTERVAL ? MINUTE), expires = DATE_SUB(expires,
  INTERVAL ? MINUTE) WHERE id = ?`. Same helper applied to
  `survey_items_display.saved` for activity backdating.

**`tests/e2e/helpers/queue.js`** (new) — `runQueueOnce()` invokes
`docker exec formr_app php bin/queue.php -t UnitSession --once` (the
`--once` flag is added in this branch — runs `processQueue()` exactly
once and exits, instead of looping forever like the daemon does).

**`tests/e2e/helpers/expiry.js`** (new) — `provision({x, y, z, items})`
shells out to `bin/expiry_fixture.php` and parses the JSON. Returns the
fixture descriptor.

**`tests/e2e/expiry-fixture.spec.js`** (new) — calls `provision({x: 60,
y: 0, z: 0})`, asserts the row exists with the right `expires`, calls
`backdateUnitSession(id, 90)`, asserts `dbState().expires` is in the past,
calls `runQueueOnce()`, asserts `expired = NOW`. This is the smoke test
for the helpers themselves.

## Phase 2 — Symptom A, Symptom B, Symptom D

All in a new file `tests/e2e/survey-symptoms.spec.js`. Each test creates
its own fixture and tears it down. Symptom C is **not** a separate test
— if C is "A or B on a different study", the existing A/B reproductions
already cover it; the cross-study generalisation is asserted in phase 6
audit pass 1 by reading the call sites.

### Test A1 — "end() leaves queued = 2 on the active session" (warm-up)

Provision X=60, Y=0, Z=0. Visit the survey via Playwright (`page.goto(run
URL ?code=…)`), do not submit any items. Call `runQueueOnce()` while
`expires` is still in the future — asserts the queue does NOT touch the
session. Now `backdateUnitSession(id, 90)`, run queue. Assert
`dbState()` ends up `{ ended IS NULL, expired = NOW(), queued = 0,
result = 'expired' }`. This pins normal expire() behaviour as the
control case.

### Test A2 — Symptom A reproduction: queue-orphan via mismatched current unit

Provision a 2-unit run: `Survey1 (X=60) → Endpage`. Visit Survey1; do not
submit. `backdateUnitSession(survey1Id, 90)`. Force the run's current
position to the Endpage by direct SQL (`UPDATE survey_run_sessions SET
position = <endpage_pos> WHERE id = ?`) — this reproduces the
"reference session expired but is no longer the current unit" condition.
Run queue. Assert: `survey_unit_sessions` row for Survey1 has
`{ ended IS NULL, expired IS NULL, queued = -9 }` — the supersede orphan
shape from `RunSession.php:247-251` followed by `UnitSession::create()`.
Mark `test.fail()` if the trace doesn't match — but only if it disagrees
with the code-reading prediction; the goal is to characterise, not
prejudge.

### Test B1 — Symptom B reproduction: end() on never-visited Survey

Provision `Survey1 (X=60, required item) → Endpage`. **Do not visit.**
End the run-session directly (`UPDATE survey_run_sessions SET ended =
NOW()`). Run queue. The `RunSession::execute()` ended-branch at
`RunSession.php:204` calls `referenceUnitSession->end('ended_by_queue_rse')`
on the never-visited Survey. Assert: `dbState()` is
`{ ended = NOW(), result = 'survey_ended' }` (note: this is a sub-bug —
`UnitSession.php:215` overwrites the `'ended_by_queue_rse'` reason with
`'survey_ended'`, document this in the test comment) **and**
`dbResultsRow()` returns `null` (no row was ever inserted because the
participant never POSTed). This is Symptom B exactly.

### Test D1 — Pause skipped via `relative_to → TRUE` (path D-1)

Provision a 3-unit run: `Survey1 (X=0, optional item) → Pause(relative_to
= "TRUE", no wait_minutes) → Endpage`. Visit and complete Survey1. Run
queue. The Pause's `relative_to` returns PHP `true` →
`Pause.php:150-152` sets `$conditions['relative_to'] = '1=1'` →
`SELECT 1=1 AS test` → `$result = true` → `$data['end_session'] = true`.
Assert: Pause `dbState()` is `{ ended = NOW(), result = 'pause_ended',
queued = 0 }`, run advanced to Endpage.

This pins D-1 as observed behaviour. Whether the prod ~80-skip cases
share this trigger is then a question for the audit (phase 6 pass 1):
do any prod Pauses have a `relative_to` that returned `TRUE` around
the time of the skip burst?

### Test D2 — Pause skipped via degenerate config (path D-2)

Provision `Survey1 → Pause(no wait_minutes, no relative_to, no
wait_until_*) → Endpage`. Visit and complete Survey1. Run queue.
`Pause.php:245-247` fallback fires → ends immediately. Assert same shape
as D1.

This is a misconfiguration safety net — admin UI should prevent it but
may not. Low priority for the prod report; included for completeness.

### Test D3 — Pause skipped via run-session-ended (path D-4)

Provision `Survey1 → Pause(wait_minutes=60) → Endpage`. Visit and
complete Survey1. Pause queued with `expires = NOW + 60 min`. End the
run-session directly. Run queue. The `RunSession::execute()` ended
branch at `:204` calls `end('ended_by_queue_rse')` on the queued Pause;
`UnitSession.php:222-223` rewrites reason to `pause_ended`. Assert:
Pause `dbState()` is `{ ended = NOW(), result = 'pause_ended', queued =
2 (NOT 0) }` — same end()/queued asymmetry as Symptom B.

### Test D4 — Pause supersede orphan (path D-5)

Provision `Survey1 → Pause(wait_minutes=60) → Endpage`. Visit Survey1
(do not complete). `backdateUnitSession(survey1Id, 90)`. Force the run's
position to the Endpage by direct SQL (same precondition as Test A2).
Run queue. The queue daemon picks up the (long-since-stale) Survey1
reference, `RunSession.php:247-251` fires moveOn, `UnitSession::create()`
for the Endpage flips Pause's `queued = -9`. Assert: Pause `dbState()`
is `{ ended IS NULL, expired IS NULL, queued = -9 }` — Symptom-A shape
on a Pause.

## Phase 3 — full wiki-cell matrix

`tests/e2e/survey-expiry-matrix.spec.js`. One Playwright test per row of
the draft's matrix; every test that the code-read predicts will fail is
tagged `test.fail()` with a comment naming the structural cause (so the
suite stays green pre-fix and the divergence ledger reads off the
annotations directly).

The wiki page (https://github.com/rubenarslan/formr.org/wiki/Expiry) is
fetched once during phase-1 helper development and the relevant scenario
text is pasted as a docstring at the top of each test, so a reviewer can
verify wiki ↔ code without re-reading the wiki. (If the wiki text doesn't
match the draft's quoted summary, the prediction column is corrected
before the test is written; the goal is to test what the wiki actually
says, not what the draft remembered it saying.)

Tests, grouped under `test.describe('Wiki spec compliance')`. Each row
sets X/Y/Z, backdates `created`, optionally inserts
`survey_items_display.saved` rows to simulate activity, runs queue once,
asserts `dbState()`. Predictions come from re-reading
`Survey.php:116-163`:

| # | Cell | Setup | Wiki | Code (prediction) | Tag |
|---|---|---|---|---|---|
| W1.a | wiki #1 access at 2:30, fills | X=420, Y=30, Z=0; backdate -2:30, first_submit -2:30 | `expired IS NULL` (~5 h left of invitation+X+Y window) | grace fires: `first_submit+Y` ≈ 2 h past → `expired = NOW()` | `test.fail()` |
| W1.b | wiki #1 access at 6:50, idle | X=420, Y=30, Z=0; backdate -7:30, no first_submit | `expired IS NULL` until 7:30 | X-rule: `expired = NOW()` at 7:00 (early by 30 min) | `test.fail()` |
| W1.c | wiki #1 access at 6:50, fills 1/10 min | X=420, Y=30, Z=0; -7:35, first_submit -0:45, last_active -0:05 | `expired = NOW()` at 7:30 (hard X+Y cap) | grace: `first_submit+Y` ≈ -0:15 → expired (right answer, wrong reason) | green |
| W2.a | wiki #2 string out for hours | X=420, Y=0, Z=30; -8:00, first_submit -7:30, last_active -0:05 | `expired IS NULL` (Z slides) | X-rule overrides Z: `expired = NOW()` at 7:00 | `test.fail()` |
| W2.b | wiki #2 idle after access | X=420, Y=0, Z=30; -7:30, last_active -0:40 | `expired = NOW()` at 7:20 (last_active+Z) | X-rule: `expired = NOW()` at 7:00 (early 20 min, wrong rule) | `test.fail()` (asserts on *time*, not just `expired IS NOT NULL`) |
| W3.a | wiki #3 active user | X=420, Y=180, Z=30; -9:55, first_submit -3:05, last_active -0:05 | `expired IS NULL` (5 min until invitation+X+Y) | grace: `first_submit+Y` = -0:05 → expired (early ~5 min) | `test.fail()` |
| W3.b | wiki #3 idle 40 min | X=420, Y=180, Z=30; -7:30, no first_submit, last_active = invitation (-7:30) | `expired IS NULL` until 7:20 (Z floor) | X-rule: `expired = NOW()` at 7:00 (early 20 min, wrong rule) | `test.fail()` |
| W4.a | wiki #4 X-only, accessed then idle (the prod report) | X=420, Y=0, Z=0; -8:00, first_submit -7:50, last_active -7:50 | `expired IS NULL` forever | X-rule: `expired = NOW()` at 7:00 | `test.fail()` |
| W4.b | wiki #4 never accessed | X=420, Y=0, Z=0; -7:05, no first_submit | `expired = NOW()` at 7:00 | matches | green |
| W5.a | wiki #5 X=0 idle indefinitely | X=0, Y=0, Z=30; -10:00, no first_submit, last_active = invitation | `expired IS NULL` (no X cap, pre-access doesn't trigger Z) | Z fires off invitation_sent + 30 → `expired = NOW()` at -9:30 | `test.fail()` |
| W5.b | wiki #5 access then idle | X=0, Y=0, Z=30; -7:30, last_active -0:40 | `expired = NOW()` at 7:20 | matches | green |
| W5.c | wiki #5 steady activity | X=0, Y=0, Z=30; -10:00, first_submit -9:00, last_active -0:05 | `expired IS NULL` (Z slides) | matches | green |

Plus four boundary tests not in the wiki:

| # | Setup | Why |
|---|---|---|
| B1 | X=60, Y=0, Z=0, never visit, queue at +1h | confirms unvisited Survey expires with no results row written (relates to Symptom B) |
| B2 | X=0, Y=60, Z=0 | wiki silent on Y-alone — document what code does |
| B3 | studyCompleted false-positive: study with all `showif: FALSE` items, first visit | `progress=1` flips even with no answers (`SpreadsheetRenderer.php:611-613`); assert `ended = NOW()` with empty results row |
| B4 | grace anchor uses real first_submit, not auto-saved fields: first POST is browser/IP only (saved at invitation_sent +0s) | confirms `> 2s` guard at `Survey.php:152` correctly excludes auto-fields |

## Phase 4 — adjacent-pathway tests

`tests/e2e/survey-unfinished-pathways.spec.js`. Each test reproduces one
specific terminal DB shape via a deterministic trigger.

| # | Trigger | Asserted shape |
|---|---|---|
| U1 | Symptom A via supersede orphan: queue picks up reference at position 5 while run is at position 7 | `ended IS NULL, expired IS NULL, queued = -9, results-row exists` |
| U2 | Symptom A via admin removes unit: drop `survey_run_units` row mid-flight, run queue | orphan shape (same as U1) |
| U3 | Symptom A via end()/queued asymmetry: Survey at end-of-run with no Stop, user completes | `queued = 2, ended = NOW`. Then run queue at expires; assert no double-end, queued lands at 0 |
| U4 | Symptom B via end() on never-visited (already in phase 2 as B1; cross-listed for completeness) | `ended = NOW, results-row IS NULL` |
| U5 | Symptom B via admin `forceTo` past unvisited Survey | `ended = NOW, results-row IS NULL` |
| U6 | Symptom B via run-session-ended: `RunSession::execute()` ended branch ends queued sibling Survey | `ended = NOW`. Also asserts the `result = 'survey_ended'` overwrite of the explicit `'ended_by_queue_rse'` reason — a separate small bug at `UnitSession.php:215` |
| U7 | Validation race (P7): POST invalid 4×, backdate, POST valid → still expired despite "active editing" | `expired = NOW`; document failed-submit count in `result_log` |
| U8 | post_max_size race: simulated upload > `post_max_size` as first contact (`createSurveyStudyRecord` skipped) | `queued = 2, results-row IS NULL`; then run-session-ended path → Symptom B |
| U9 | studyCompleted false-positive (cross-listed with phase 3's B3, asserted here as a *pathway* result rather than a wiki cell) | `ended = NOW, results-row exists with no answer columns set` |
| U10 | SkipBackward duplicate: `Survey5 → Pause → SkipBack(target=5)`, complete Survey5, expire Pause, run SkipBack | two `survey_unit_sessions` rows for `unit_id=Survey5`; `(A.ended=NOW, A.queued=-9)` and `(B.ended=NULL, B.queued=2)`; both have results-table rows |

## Phase 5 — JS / UI drift tests

`tests/e2e/survey-expiry-ui.spec.js`. Playwright drives the actual
browser, so we can observe what the participant sees.

| # | Test | Pinning |
|---|---|---|
| J1 | ExpiryNotifier modal fires when expected | visit, idle 70 s with X = 1 min, assert `#expired-modal` visible with the Reload button (sanity for the modal markup at `templates/run/index.php:47-61`) |
| J2 | ExpiryNotifier modal fires on stale client clock | visit, fill page 1 within 30 s; server-side grace block has now moved deadline; client modal still pops at the originally-rendered time. Asserts the drift, not a fix. |
| J3 | Lock-timeout reload eats input (`RunSession.php:197`) | hold the named lock from a parallel `mariadb -e "SELECT GET_LOCK('run_session_<id>',60)"`; type into the form; trigger a participant POST; assert the 5-s-reload HTML, then post-reload form re-renders with only saved values |
| J4 | Reload after expire shows next unit silently | backdate to expired, click anywhere, assert participant lands on next unit's page without notification of discarded prior survey |
| J5 | `window.unit_session_expires` reflects only first render | render → backdate → poke server → re-render; assert new `unit_session_expires` matches new server-side `expires`. Pins whether the JS clock can self-correct. |

## Phase 6 — codebase audit → `EXPIRY_AUDIT.md`

Once phases 2-5 have produced a runtime divergence ledger (one entry per
`test.fail()`-tagged test with a one-line cause), do the static reads
that complement it. This phase produces only a markdown document — no
code changes.

Seven audit passes, one section in `EXPIRY_AUDIT.md` per pass, each with
{file:line, finding, severity tag (UX-visible / silent-data-corruption /
metric-only / dead-code)}:

1. **Spec-vs-impl on adjacent units.** Same overwrite-not-combine read of
   `Pause::getUnitSessionExpirationData` (relative_to + wait_minutes +
   wait_until_*) and `Branch::automatically_jump` /
   `automatically_go_on`. Document any "help text says X, code does Y"
   divergences. Also: `Run::expire_cookie` and `runAccessExpired` —
   `runAccessExpired` may already be dead per a quick callsite check
   (the draft's claim); confirm. **Cross-study generalisation:** confirm
   the Survey expiry computation has no per-study branching (it doesn't —
   `Survey.php:116-163` reads `$this->surveyStudy->expire_*` only), so
   any Survey on the platform is equally affected. This is the answer to
   Symptom C: "yes, the same root cause hits any survey".
2. **`UPDATE survey_unit_sessions` symmetry.** Every UPDATE site
   (`expire`, `end`, `endLastExternal` at `RunSession.php:467-476`,
   email-queue path at `EmailQueue.php:169` if it exists, the queue
   daemon's own UPDATE) checked for: does it touch `queued`? does it
   touch `result`? does it touch `result_log`? Tabulate. Asymmetries
   are bug candidates.
3. **`createUnitSession` / `UnitSession::create` callers.** Every call
   site audited for whether the supersede side effect (`queued = -9` on
   siblings) is intended there. `getReminderSession`
   (`Run.php:672`-ish) passes `setAsCurrent=false` to skip supersede;
   confirm no other caller misses the flag where it should.
4. **Results-row presence guard.** Every `UPDATE` on a `survey_<study>`
   results table that uses `WHERE … ended IS null` — list them. Each is
   a Symptom-B candidate when the row is absent. Specifically
   `UnitSession::end():211`, `expire():185`, plus any join paths in
   `getRecipientEmail`.
5. **`getCurrentUnitSession` ordering.** `RunSession.php:436` is
   `ORDER BY id DESC LIMIT 1`. Phase-4 U10 confirms multiple actives
   *can* exist. Audit every caller of `getCurrentUnitSession()` for the
   silent assumption "there is at most one".
6. **ExpiryNotifier vs server.** Every place the server can mutate
   `survey_unit_sessions.expires` after page render — list them. Each
   is a place the client clock can drift from the server. Phase-5 J5
   confirms or refutes self-correction.
7. **Pause-skip surface area.** Every code path that can end a Pause
   without the participant ever seeing it. Five known
   (D-1 … D-5 above); audit for a sixth: any place a queue-driven
   `execute()` hits a Pause whose `expires` was set at a moment of
   inconsistency (e.g. `relative_to` referencing a column that was
   `NULL` at queue-time and is now populated, flipping the SQL test
   from `0=1` to `1=1`). Tabulate the conditions under which each
   skip-path is reachable.

Output: `EXPIRY_AUDIT.md` with the seven sections, plus a final
"Recommended fix order" section that ranks each finding by
{severity, blast_radius, code-change-size}. The fix branch (out of
scope here) consumes that ordering.

## Critical files (read these to implement)

- `application/Model/RunUnit/Survey.php:116-163` — Survey expiry
  computation under test.
- `application/Model/RunUnit/Pause.php:110-251` — Pause expiry
  computation; relevant to Symptom D.
- `application/Model/UnitSession.php:140-162` — `isExpired()` dispatch
  for Pause/Branch (re-queue extension on check_failed /
  expire_relatively=false).
- `application/Model/UnitSession.php:181-249` — `expire()` / `end()`;
  asymmetry source.
- `application/Model/UnitSession.php:51-79` — `create()` supersede side
  effect (Symptom A trigger).
- `application/Model/RunSession.php:200-251` — execute() lock + ended
  branch + supersede-orphan branch.
- `application/Queue/UnitSessionQueue.php:67-94` — `expires <= NOW()`
  pickup that the queue helper drives.
- `bin/cron.php` and `bin/initialize.php` — bootstrap pattern for the
  new fixture script.
- `tests/pwa_manifest_smoke.sh:34-39` — the proven `docker exec formr_db
  mariadb` pattern reused by `helpers/db.js`.
- `tests/e2e/playwright.config.js` — no changes needed; `workers: 1`
  and the `baseURL` resolution handle both local and BS.

## Implementation order

1. `bin/expiry_fixture.php` + `tests/e2e/helpers/db.js` +
   `helpers/queue.js` + `helpers/expiry.js` +
   `tests/e2e/expiry-fixture.spec.js` (the smoke test). Phase-1 lands
   together; smoke test green before moving on.
2. `tests/e2e/survey-symptoms.spec.js` — phase 2 (A1, A2, B1, D1, D2,
   D3, D4).
3. `tests/e2e/survey-expiry-matrix.spec.js` — phase 3 (12 wiki + 4
   boundary tests, predicted-failures `test.fail()`-tagged).
4. `tests/e2e/survey-unfinished-pathways.spec.js` — phase 4 (U1-U10).
5. `tests/e2e/survey-expiry-ui.spec.js` — phase 5 (J1-J5).
6. `EXPIRY_AUDIT.md` — phase 6: six audit passes plus the
   "recommended fix order" section seeded from the runtime divergence
   ledger built up by phases 2-5.

## Verification

```
npm run test:e2e -- expiry-fixture.spec.js              # phase 1 smoke
npm run test:e2e -- survey-symptoms.spec.js             # phase 2
npm run test:e2e -- survey-expiry-matrix.spec.js        # phase 3
npm run test:e2e -- survey-unfinished-pathways.spec.js  # phase 4
npm run test:e2e -- survey-expiry-ui.spec.js            # phase 5
```

All five suites must pass on local-chromium against the dev URL
(`study.researchmixtape.com`), with the dev formr_db reachable via the
existing `docker exec formr_db` channel. `test.fail()`-tagged tests pass
*because* the assertion fails — when the eventual fix lands and a tagged
test starts passing for real, the `test.fail()` will flip to RED, which
is the cue to remove the tag.

Phase 6 has no automated verification: review of `EXPIRY_AUDIT.md` is by
reading.

## Mid-implementation update — AMOR run observation

User reported a fresh ESM drop on the `amor-hauptstudie` run with the
**access window turned off** (every Survey has `X=Y=Z=None`). User
provided the run JSON at `tests/e2e/amor-hauptstudie(1).json`. Key
patterns from the export:

- **All 60 Surveys have X=Y=Z=0/null.** Confirms the X-rule override
  (P1) is *not* the active culprit for the new drops. The drop must
  come from a non-Survey-expiry path.
- **Pauses mix `wait_minutes=0` and `wait_minutes=''`** for similar
  ESM-time-of-day breaks (positions 124, 130 use `0`; 134, 138 use
  `''`). Different code paths in `Pause.php:148` (the `relative_to`
  branch) vs `:175` (the `wait_minutes` branch); both compute `expires`
  but via different SQL conditions. Worth pinning that both produce
  the same `expires` for equivalent inputs.
- **R expressions with `today()` / `floor_date(now())`** can yield a
  past timestamp if cron evaluates *after* the wall-clock moment —
  e.g., `today() + days_until_friday` returns midnight today; cron
  at 00:01 sees `expires < NOW()` and ends the Pause immediately.
  This is intended behaviour for "missed-window" semantics, not a bug.
- **SkipBackward at position 143** loops the ESM body for 7 days. Each
  iteration's `runTo()` calls `createUnitSession()` for the loop's
  start (Pause 122), which **supersedes any queued siblings** in the
  same run-session to `queued = -9` (P5).
- **Surveys range from 9 to 1190 items**. Many use show-if. The larger
  the survey, the higher the P10 (`studyCompleted()` false-positive)
  risk if the participant's data hasn't yet populated the show-if
  conditions.

**Updated drop-pathway ranking for X=0/Y=0/Z=0 Surveys (most likely first):**

1. **studyCompleted false-positive (P10)** — `SpreadsheetRenderer::getStudyProgress`
   at `:611-613` flips `progress = 1` when every unanswered item is
   hidden. Cron-driven processItems on a queue tick can complete a
   Survey with no participant input. Adds a `survey_<study>` row with
   only `created`/`ended` populated → **Symptom B** (no required
   answers but ended is set).
2. **moveOn supersede via SkipBackward loop** — each loop iteration
   supersedes prior queued ESM unit-sessions to `queued = -9` →
   **Symptom A** (queued=-9, ended=NULL, expired=NULL, results-row
   exists from prior partial fill).
3. **Pause `relative_to` evaluates to past timestamp** → instant
   end → moveOn cascades through Email/Push/Survey. If the next
   Survey is reached but the participant doesn't visit, it sits
   forever (no expiry). If they DO visit, it works. Not a drop on
   its own; only contributes to (1) by giving cron a ticking entry
   point.
4. **Run-session-ended path** — admin or expiry cron sets
   `survey_run_sessions.ended`; subsequent queue ticks call
   `end('ended_by_queue_rse')` on every queued unit; for Surveys
   with X=Y=Z=0 that haven't been queued, this never fires. Less
   likely cause for AMOR.

**Test additions in Phase 4:**

| # | Trigger | Asserted shape |
|---|---|---|
| U11 | Two ESM-time Pauses with `wait_minutes=0` vs `wait_minutes=''`, same `relative_to`, same fixture clock | both produce identical `expires` and identical `dbState()` after queue tick — pins the two-paths-equivalent assumption |
| U12 | Pause R expression returns past timestamp at queue evaluation time | Pause ends immediately, `result='pause_ended'`, queued=0, `ended=NOW()` — pin the "missed-window" semantics |
| U13 | SkipBackward loop iteration: ESM_End_of_Day → SkipBack(target=ESM_period_2). Participant has a queued partially-filled ESM_10:00 from yesterday's loop. SkipBack fires, creates new Pause(122) unit-session. | Yesterday's ESM_10:00 row has `queued = -9, ended = NULL, expired = NULL, results-row populated` (Symptom A in the wild) |
| U14 | Survey with all show-if items + cron tick processItems | `studyCompleted` flips, `end_session=true`, `survey_<study>` has row with no answer columns, `ended = NOW()`. Confirms Symptom B can come from cron path even with X=Y=Z=0. |

These four tests directly target the AMOR drop pattern and will fire
green only if the diagnosis above is correct. If they pass, the fix
branch knows exactly which sites to patch (P10 in `SpreadsheetRenderer`,
the supersede side-effect in `UnitSession::create`).

## What this branch deliberately leaves out

- **No code fixes.** Every divergence is captured as a `test.fail()`
  assertion or an audit-document entry. Fixes ship in a follow-up branch
  once the audit is complete, so we ship one coherent change instead
  of five reactive ones.
- **No production DB inspection.** Hypotheses are testable on dev with
  backdating; if a prod-only shape resists reproduction, the audit notes
  it and the fix branch can request a sanitised snapshot.
- **No multi-worker test parallelism.** `workers: 1` stays; phase 5 J3
  needs lock contention but holds the lock from outside the worker.
