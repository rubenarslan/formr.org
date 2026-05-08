# Survey expiry & "unfinished" pathways — audit

Output of the e2e characterisation suite (`tests/e2e/expiry-fixture.spec.js`,
`survey-symptoms.spec.js`, `survey-expiry-matrix.spec.js`,
`survey-unfinished-pathways.spec.js`) plus a static read of the call sites
those tests touched.

Severity tags:

- **UX-visible** — participant sees a wrong outcome (locked out, skipped past, etc.)
- **silent-data-corruption** — DB ends up in a state inconsistent with what
  the docs promise; admin queries return wrong shape
- **metric-only** — wrong column gets set but nothing user-facing breaks
- **dead-code** — code path is unreachable in practice

---

## 1. Wiki spec ↔ code divergences (Survey expiry algorithm)

All confirmed by `survey-expiry-matrix.spec.js`. Each row is one wiki
scenario; "Code prediction" is the algorithm's actual output (compared
against wiki via `bin/expiry_compute.php`).

| Wiki cell | Code site | Severity | Plain-English summary |
|---|---|---|---|
| W1.a — access at 2:30, fills | `Survey.php:154` (grace anchor) | UX-visible | Wiki: deadline is invitation+X+Y (hard cap). Code: deadline is first_submit+Y. **Early starters get less total time than late starters.** |
| W1.b — access at 6:50, idle, deadline | `Survey.php:139-141` | UX-visible | Wiki: X+Y window. Code: X-rule fires at X without waiting for Y. Idle accessing user is cut off 30 min early in a "X=420, Y=30" study. |
| W2.a — string out for hours | `Survey.php:139-141` | UX-visible | Wiki: once accessed, only Z slides. Code: X-rule unconditionally overrides Z; cannot "string out for hours" past invitation+X. |
| W2.b — idle 40 min after access at 6:50 | `Survey.php:139-141` | UX-visible | Wiki: deadline = last_active+Z. Code: deadline = invitation+X. Cuts off ~20 min early in a "X=420, Z=30" study. |
| W4.a — accessed long ago, Y=Z=0, wiki: unlimited | `Survey.php:139-141` | UX-visible (**PROD REPORT**) | Wiki: once accessed with Y=Z=0, never expires. Code: X-rule fires unconditionally regardless of access. **This is the originally-reported bug.** |
| W5.a — X=0, never accessed | `Survey.php:132-134` (last_active fallback) | UX-visible | Wiki: with X=0, pre-access has no deadline. Code: Z-rule fires off `items_display.created` (set when survey row is auto-created on visit), so a user who simply loaded the page once gets a deadline. |

**Common cause across all 6 cells**: `Survey::getUnitSessionExpirationData`
walks three rules in fixed order, **each one fully overwriting the previous**,
instead of the wiki's `MIN(invitation+X+Y, last_active+Z)` combination.

```text
ACTUAL CODE:                      WIKI SPEC:
$expires = 0                      pre-access:
if Z>0:                              if X>0: invitation+X
    $expires = last_active+Z         else:   never
if X>0:                           post-access:
    $expires = invitation+X          if Y==0 and Z==0: never
                                     if Y>0  and Z==0: invitation+X+Y
if grace fires:                      if Y==0 and Z>0:  last_active+Z
    $expires = first_submit+Y        if Y>0  and Z>0:  MIN(both)
```

**Fix shape**: replace the three sequential overwrites with a single
expression that combines the rules per the wiki's pre/post-access
formula. The grace block's anchor needs to move from `first_submit` to
`invitation_sent + X*60` (i.e. `invitation+X+Y`).

---

## 2. Symptom reproductions

### Symptom A — `ended IS NULL AND expired IS NULL` with populated results-row

| # | Trigger | Cause | Severity |
|---|---|---|---|
| A2 (`survey-symptoms.spec.js`) | Force `survey_run_sessions.position` past a queued Survey, run queue. | Queue's END-q branch at `RunSession.php:242` doesn't fire because `getCurrentUnitSession()` doesn't find the Survey at the new position. moveOn → no next position → "dangling end" path at `RunSession.php:299-301` ends the run-session via `$this->end()`. The queued Survey stays at `queued=2`, ended/expired NULL forever. | silent-data-corruption |
| U10 (`survey-unfinished-pathways.spec.js`) | A SkipBackward / `runTo()` creates a duplicate unit-session for the same unit_id. | `UnitSession::create()` line 66-70 unconditionally supersedes all queued siblings to `queued=-9`. The prior queued session is now orphaned: `queued=-9, ended=NULL, expired=NULL, results-row exists`. | silent-data-corruption |
| U13 (AMOR Symptom A) | Any back-jump or moveOn that calls `createUnitSession(setAsCurrent=true)` while another Pause is queued. | Same supersede. **This is reachable in normal AMOR flow:** the SkipBackward at position 143 fires runTo(122) → `createUnitSession(Pause 122)` → flips any queued sibling Pause to `queued=-9`. | silent-data-corruption |

### Symptom B — `ended IS NOT NULL` with no results-row

| # | Trigger | Cause | Severity |
|---|---|---|---|
| B1 (`survey-symptoms.spec.js`) | Queue picks up a never-visited Survey while `survey_run_sessions.ended` is set; run-session-ended branch at `RunSession.php:204` calls `referenceUnitSession->end('ended_by_queue_rse')`. | `UnitSession::end()` UPDATEs the results table where `ended IS NULL` (matches 0 rows since no row exists) then UPDATEs `survey_unit_sessions.ended` unconditionally. Result row was never created (no participant visit to call `createSurveyStudyRecord`). | silent-data-corruption |
| U9 (P10 — most likely AMOR cause) | Survey with show-if items returning NULL (e.g., references data from a never-completed prior ESM survey). | `SpreadsheetRenderer::getStudyProgress()` at `:611-613` flips `progress=1` when every unanswered item is `hidden_but_rendered`. End-session fires immediately on first visit. Survey ends within 1 second of creation; results-row exists but has no answer columns set. | UX-visible (drop-through) |

### Symptom D — Pause skipped

| # | Trigger | Cause | Severity |
|---|---|---|---|
| D1 (`survey-symptoms.spec.js`) | Pause with `relative_to` evaluating to PHP `true` (e.g. R `TRUE` literal). | `Pause.php:150-152` sets `expire_relatively=true` and `condition='1=1'`; `Pause.php:249` sets BOTH `end_session=true` AND `expired=true`. Dispatcher at `RunSession.php:311-316` checks `expired` first (elseif) → `expire()` wins → DB shape: `expired=NOW`, NOT `ended=NOW`. | UX-visible (intended) |
| D2 | Pause with no fields set. | Fallback at `Pause.php:245-247` returns `$result=true` → same as D1 → expired=NOW. | UX-visible (intended) |
| D3 | Queue picks up queued Pause while run-session is ended. | `RunSession.php:204` ended-branch calls `end('ended_by_queue_rse')` then `removeItem`. For Pause type, `end()` keeps the explicit reason (no Survey-style overwrite). `removeItem` resets `queued=0`. DB: `ended=NOW, result='ended_by_queue_rse', queued=0`. | metric-only |
| U12 | Pause with `relative_to` R expression that returns a past timestamp (very common in AMOR's lubridate patterns). | `Pause.php:159` strtotime(past) → expires < NOW → SQL test true → expired=true. Pause ends immediately. **NOT a bug** — this is the documented "missed window" semantics. But every cron tick that is a few seconds late on a wall-clock-anchored expression hits this. | UX-visible (intended) |

**Important Symptom-D shape reminder for prod queries**: Pauses skipped
via D1/D2/D3/U12 have `expired IS NOT NULL`, not `ended IS NOT NULL`.
The `ended` column on the Survey/Pause is only set by `end()`; the
`expired` column is only set by `expire()`. Hunt for *either* when
diagnosing "skipped Pauses".

---

## 3. Side-bug: `end()` overwrites the explicit reason for Survey

`UnitSession::end()` at `:209-218`:

```php
if ($unit->type == "Survey" || $unit->type == "External") {
    if ($unit->type == "Survey") {
        ...
        $this->result = "survey_ended";
    } else if ($unit->type == "External") {
        $this->result = "external_ended";
    }
} else {
    if ($reason !== null) {
        $this->result = $reason;
    } else if ($unit->type == "Pause") { ... }
    ...
}
```

For Survey/External, the explicit `$reason` argument is discarded.
Confirmed in B1: a queued Survey ended via `end('ended_by_queue_rse')`
shows up with `result='survey_ended'`, masking the actual trigger.
Severity: **metric-only** (audit trail confusion; no participant impact)
but worth fixing alongside the main expiry rewrite.

---

## 4. `end()` vs `expire()` symmetry on `queued`

`expire()` at `UnitSession.php:198`:

```php
"UPDATE survey_unit_sessions SET expired = NOW(), result = 'expired', queued = 0 ..."
```

`end()` at `:234-246`:

```php
"UPDATE survey_unit_sessions SET ended = NOW(), result = :result, result_log = :result_log ..."
```

**`end()` does not touch `queued`.** Consequence: a queued unit-session
ended via the participant flow (admin forceTo, nextInRun, dangling-end)
keeps `queued=2`. The next createUnitSession in the same run-session
will then flip it to `-9` (Symptom A shape). Confirmed by P4 test.

Mitigated in the queue's run-session-ended path because
`UnitSessionQueue::removeItem()` runs immediately after `end()` and
sets `queued=0`. **Not** mitigated for any non-queue `end()` call site
(`forceTo`, `nextInRun`, `endLastExternal`, `endCurrentUnitSession` for
non-Survey-non-External units).

Severity: **silent-data-corruption** — the trailing `queued=2` makes
the row look "still pending" to admin tools, and is the trip-wire for
the supersede orphan.

---

## 5. Queue's END-q branch trusts the stored `expires` column

`RunSession.php:242-245`:

```php
if ($referenceUnitSession && $currentUnitSession && $referenceUnitSession->id == $currentUnitSession->id && !$executeReferenceUnit) {
    $this->debug("END-q");
    if ($this->endCurrentUnitSession()) {
        return $this->moveOn();
    }
}
```

`endCurrentUnitSession()` at `:452-465` calls `expire()` on Survey/External
**without recomputing the deadline**. The queue daemon's WHERE filter
(`expires <= NOW()`) is the only gate; once a row with `queued=2 AND
expires <= NOW()` is picked up, `expire()` fires.

Why this matters: the matrix tests showed `Survey::getUnitSessionExpirationData`
recomputes a different `expires` from current state (e.g., grace block fires
with later first_submit, deadline moves later). But the queue trusts the
*stored* value from when the row was last queued — not the current calc.
So a participant who just submitted an item (which shifts the wiki's deadline
later) still gets expired by the queue if the stored `expires` is past.

Severity: **silent-data-corruption** — the algorithm's verdict and the
queue's action can diverge when state changes between queue ticks. The
matrix divergences in §1 are independent of this; this row adds a second,
multiplicative source of unexpected expiration.

**Fix shape**: in `endCurrentUnitSession()` for Survey/External, re-run
`getUnitSessionExpirationData()` and only `expire()` if the calc still
says expired. Otherwise, re-queue with the new `expires`.

---

## 6. `Pause::wait_minutes=0` vs `wait_minutes=NULL` (incl. AMOR's `''`)

`Pause.php:96`:

```php
$this->has_wait_minutes = !($this->wait_minutes === null || $this->wait_minutes == '');
```

**In PHP 8 (`0 == ''` is FALSE)**, the two cases:
- `wait_minutes=0` → has_wait_minutes=true → `:175` branch
- `wait_minutes=NULL` (which AMOR's empty-string export coerces to in DB) → has_wait_minutes=false → `:148` branch

U11 confirmed: for the same `relative_to`, both branches produce the
same `expires`. **No divergence.** AMOR's mix of the two patterns is
safe in PHP 8. (In PHP 7, where `0 == ''` is TRUE, has_wait_minutes
would be false for both — also no divergence, but for a different
reason. PHP 5 and PHP 7 production deployments are not at risk here.)

Severity: **dead-code** as a divergence; but the two branches existing
is itself a code smell — they can be collapsed into one path.

---

## 7. AMOR-specific risk surface (X=Y=Z=0 across every Survey)

Given the user's report that drops happen with the access window off,
ranking the active drop pathways for AMOR in priority order:

1. **P10 / U9 — studyCompleted false-positive** (UX-visible, very likely).
   ESM Surveys with show-ifs that depend on data from prior ESM occasions.
   When a participant misses one ESM, the next ESM's show-ifs reference
   NULL fields → OpenCPU returns NULL → all unanswered items
   `hidden_but_rendered` → `progress=1` → end_session within 1 second of
   first visit. Symptom B in the wild.

2. **U13 — back-jump supersede** (silent-data-corruption, very likely).
   The SkipBackward at position 143 in the AMOR run runs every ESM day
   for 7 days. Each iteration's `runTo(122)` calls `createUnitSession`
   for the loop-start Pause, which supersedes any queued sibling unit-
   sessions in the same run-session to `queued=-9`. If a participant has
   a queued ESM Pause from earlier in the loop body that hasn't expired
   yet (rare but possible during clock-skew or queue-delay), it gets
   orphaned. Symptom A in the wild.

3. **U12 — Pause R expression returning past timestamp** (UX-visible by
   design). Lubridate-based "wait until next Friday at 10:00" patterns
   yield past timestamps if the cron tick fires after the wall-clock
   moment. The Pause expires immediately, the run advances, and any
   prior unit that was waiting for the ESM-time-of-day flow gets pushed
   past its window.

4. **§4 / §5 above (end()/queued asymmetry, queue trusts stored expires)**
   — possible contributors but require a more contrived state.

---

## 8. Recommended fix order

Ranked by {severity × blast_radius × code-change-size}:

1. **P10 in `SpreadsheetRenderer::getStudyProgress`** (one file, one
   function, ~5 lines). Current: `progress=1` if `not_answered ==
   hidden_but_rendered`. Fix: only count `hidden_but_rendered` items
   as "complete" when their show-if returned **false** (`probably_render
   = false`), not NULL (`probably_render = true`). Rationale: a NULL
   show-if means "we don't know", not "skip"; the renderer should
   either retry, error, or re-render after data changes — not auto-end.
2. **Survey expiry algorithm rewrite in `Survey::getUnitSessionExpirationData`**
   (one file, one function, ~30 lines). Replace the three sequential
   overwrites with the wiki's pre/post-access combination. Re-anchor the
   grace block on `invitation+X+Y` instead of `first_submit+Y`. Test gate:
   the 6 `test.fail()`-tagged matrix cells should flip to clean assertions.
3. **`UnitSession::end()` queued symmetry** (one line). Add `queued = 0`
   to the second UPDATE alongside `ended = NOW`. Test gate: P4 in
   `survey-symptoms.spec.js` will need its assertion inverted from
   "queued unchanged" to "queued reset".
4. **Queue's END-q branch should recompute the deadline** (a few lines
   in `RunSession::endCurrentUnitSession()`). Consult
   `getUnitSessionExpirationData()` and only `expire()` if the calc
   agrees. Test gate: matrix cells should pass even when the queue is
   driven explicitly at the same wall-clock moment.
5. **`UnitSession::end()` Survey reason overwrite** (a few lines, drop
   the `survey_ended`/`external_ended` overwrites; trust caller's
   `$reason`). Test gate: B1's `result` assertion needs to flip from
   `'survey_ended'` to `'ended_by_queue_rse'`.
6. **Supersede side-effect in `UnitSession::create()`** (out of scope
   for the expiry-only fix; needs a separate redesign because the
   side-effect serves a real purpose elsewhere — preventing two active
   unit-sessions for the same unit_id in the same run-session). For
   now, document it as a known "back-jump invalidates queued siblings"
   semantic and consider whether the back-jump path should reset the
   superseded sessions' `queued` symmetrically (avoiding Symptom A's
   "queued=-9 forever" ambiguity).

---

## 9. Test inventory

`tests/e2e/expiry-fixture.spec.js` — Phase 1, 1 test (smoke).

`tests/e2e/survey-symptoms.spec.js` — Phase 2, 7 tests. A1 (warm-up),
A2 (Symptom A via dangling-end), B1 (Symptom B via end-on-never-visited),
D1/D2/D3 (Pause skip variants), P4 (queued-asymmetry isolated).

`tests/e2e/survey-expiry-matrix.spec.js` — Phase 3, 13 tests. 6 with
`test.fail()` capturing wiki↔code divergences (W1.a, W1.b, W2.a, W2.b,
W4.a, W5.a). 7 cleanly green (W3.a, W4.b, W5.b, W5.c, B2, B3, B4).

`tests/e2e/survey-unfinished-pathways.spec.js` — Phase 4, 6 tests.
U10/U13 (supersede orphans), U11 (Pause two-paths equivalence), U12
(Pause past timestamp), U9 (P10 — the AMOR-likely cause), U7 (validation
race — skipped pending fixture extension).

**Total: 27 tests** (1 skipped, 6 expected-fail divergence-pinning,
20 cleanly green).

---

## 10. What this audit deliberately does not cover

- **Phase 5 (UI / JS drift)**: the in-browser ExpiryNotifier modal,
  the lock-timeout reload, `window.unit_session_expires` self-correction.
  Worth doing as a follow-up but the diagnostic value for the AMOR
  drops is lower than items 1-5 above.
- **Production DB inspection**: hypotheses tested only against the
  dev DB. If a prod-only shape resists reproduction, request a
  sanitised `survey_unit_sessions` snapshot from the prod report
  and compare row-by-row.
- **Pause `relative_to` data freshness**: a Pause whose R expression
  references a column populated AFTER queue-time can flip its expiry
  decision between ticks. We didn't construct a test for this; if
  the AMOR drops correlate with Pauses whose `relative_to` reads
  `last(some_field)` and that field becomes non-NULL between ticks,
  this is a candidate.
