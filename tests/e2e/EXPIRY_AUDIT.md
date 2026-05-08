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

## 11. `getCurrentUnitSession` callers and the at-most-one assumption

`RunSession::getCurrentUnitSession()` at `RunSession.php:426-462`:

```php
->where('survey_unit_sessions.run_session_id = :run_session_id')
->where('survey_unit_sessions.unit_id = :unit_id')
->where('survey_unit_sessions.ended IS NULL AND survey_unit_sessions.expired IS NULL')
->order('survey_unit_sessions.id', 'desc')
->limit(1);
```

Returns the LATEST active unit-session for the unit at the run's
current position. The WHERE clause filters by `ended IS NULL AND
expired IS NULL` but **does NOT filter on `queued`**. Phase-4 U10
demonstrated multiple-active-unit-sessions-for-the-same-unit-id is
reachable: a SkipBackward / `runTo` creates a new unit-session,
`UnitSession::create()`'s supersede flips the *previous* one's
`queued` to -9 — but leaves `ended` and `expired` NULL. Both rows
match getCurrentUnitSession's filter. Only the latest is returned;
the older "superseded" sibling is invisible to every caller.

### Callers and risk

| File:line | Caller | Action on returned unit-session | Older active siblings |
|---|---|---|---|
| `RunSession.php:239` | `execute()` (cron + participant) | Compares id against `referenceUnitSession->id` for END-q branch (`:242`) and stale-reference branch (`:247`). | Older sibling never participates in END-q. Could be re-snapshotted by queue forever (queued=-9 excludes from queue WHERE, so safe) — but if queued were somehow > 0, the stale-reference branch would fire each tick. |
| `RunSession.php:382` | `forceTo()` (admin send-to-position) | Calls `end()` then sets `result='manual_admin_push'` and `logResult()`. | Older sibling stays untouched. |
| `RunSession.php:465` | `endCurrentUnitSession()` (queue END-q + admin nextInRun) | For Survey/External: `expire()`. Else: `end($reason)`. | Older sibling stays at `ended/expired NULL` forever. |
| `AdminAjaxController.php:210` | `ajaxSnipUnitSession()` (admin "snip") | Deletes the latest active row. | Older sibling stays as the new "current". The admin thinks they cleared the position; actually shifted to the previous one. |
| `Helper/RunHelper.php:79` | `nextInRun()` (admin "next in run") | Calls `end('moved')` then `moveOn()`. | Older sibling untouched. |
| `Helper/RunHelper.php:106` | `snipUnitSession()` (admin "snip" via Helper) | Same as `ajaxSnipUnitSession`. | Older sibling untouched. |

### Severity tag

**silent-data-corruption** for all six callers. Older active siblings
are left in `ended/expired NULL, queued=-9` state — the Symptom-A
shape from the prod report. With Fix 2 (scoping the supersede to
same `unit_id`) the *cross*-unit case is no longer affected. But
the *same*-unit case (back-jump duplicates) still produces multiple
actives, and the supersede only reaches `queued`, not `ended`.

### Reproducible example: U10's two-actives shape

After U10's setup, `survey_unit_sessions` has two rows for the same
Pause unit_id: `(ps1: queued=-9, ended=NULL, expired=NULL)` and
`(ps2: queued=2, ended=NULL, expired=NULL)`. `getCurrentUnitSession()`
returns ps2 (latest by id). ps1 is invisible to:
- `execute()` → ps1 never gets ended, never gets expired.
- `endCurrentUnitSession()` / admin actions → only ps2 gets ended.

ps1 sits forever in non-terminal limbo. Cron's queue WHERE
(`queued > 0`) excludes it because queued=-9 — so it doesn't cause
ongoing churn. But `Run::deleteRunData` and similar full-data
operations need to handle these orphans correctly.

### Fix shapes

Two candidates, in increasing order of intrusiveness:

1. **Tighten the WHERE clause** (1 line): add `survey_unit_sessions.queued != -9` to the
   filter. Excludes superseded siblings cleanly. Doesn't change the
   semantics of `getCurrentUnitSession()` for code that relies on
   "latest active"; just makes "active" mean "active and not superseded".
   Wouldn't fix the underlying "ended/expired NULL but supersede'd"
   ambiguity but masks the symptom for the six callers.

2. **Make supersede also set `ended` or a new `superseded` column**
   (small migration + ~5 lines in `UnitSession::create`): treats
   supersede as a terminal state. `getCurrentUnitSession` then
   correctly excludes them by the existing `ended IS NULL` filter,
   no WHERE-clause change needed. Bigger change but cleans up the
   data shape — a Symptom-A `queued=-9, ended=NULL, expired=NULL`
   row would no longer be possible.

Recommended for the next branch: **option 1 in this branch**
(minimal, surgical), **option 2 in a follow-up state-machine
refactor** (bigger blast radius, deserves its own focus).

### Carry to fix-order section

If we extend the recommended fix order with this finding, it slots
in between current fixes #2 (supersede side-effect) and #3 (queue
trusts stored expires). Severity is below the user-visible symptoms
already addressed; impact is mostly admin-action correctness and
audit-trail cleanliness.

### Status: option 1 SHIPPED

`RunSession::getCurrentUnitSession()` now filters
`survey_unit_sessions.queued != -9`. Regression-tested by U14 in
`tests/e2e/survey-unfinished-pathways.spec.js`. Option 2 (set `ended`
on supersede) deferred to a state-machine refactor.

---

## 12. Pause / Branch spec-vs-impl read

Companion to §1 (which covered Survey). Same overwrite-not-combine
interaction analysis applied to `Pause::getUnitSessionExpirationData`
and `Branch::getUnitSessionExpirationData`.

### Pause: three inputs, two SQL branches, one "next day" override

`Pause::getUnitSessionExpirationData` at `Pause.php:110-252` reads
three inputs (`relative_to`, `wait_minutes`, `wait_until_time` /
`wait_until_date`) and drives one of three code paths plus a
common SQL truth-table evaluator at `:228-244`:

| Branch | Trigger | Effect on `$data['expires']` |
|---|---|---|
| `:148` "relative_to without wait_minutes" | `has_relative_to && !has_wait_minutes` | If R returns `true` / `false` → `expire_relatively=true`/`false`, `$expires` left empty (SQL test resolves it). If R returns a timestamp → `$expires = strtotime($relative_to)`. If R returns garbage → `check_failed=true`, return early. |
| `:175` "wait_minutes" | `has_wait_minutes` | If `relative_to` resolves to a timestamp → `$expires = strtotime($relative_to) + wait_minutes*60`. Else `check_failed=true`, return early. |
| `:209` "wait_until_*" | `wait_date && wait_time && empty(expires)` | Only fires if NEITHER of the above branches set `expires`. Sets `$expires = strtotime("$wait_date $wait_time")`. |

**Subtle interaction**: the `:209` block has `&& empty($data['expires'])`,
so when `relative_to` already set expires, `wait_until_*` is silently
ignored. An admin who sets all three fields might expect "MIN of all"
or "AND of all"; they get whichever branch ran first.

**`:217` "next day" override**: if `wait_until_time` was set and
`wait_until_date` was NOT (the `!$wait_date_defined` guard), and the
participant arrived AFTER the time-of-day, expires is bumped 24h
forward. The implication for U12-style wall-clock ESM Pauses:
participants who reach a Pause AFTER its target time-of-day get
"snoozed to the same time tomorrow", which may or may not be the
intent. The wiki / help text don't cover this.

**Severity**: silent-data-corruption (admin misconfiguration with
all three fields set) — low blast radius. The `:217` next-day
override is **UX-visible** but documented behavior in
`templates/admin/run/units/pause.tpl` (per a quick check).

**Dispatcher convergence with Survey** (`:249`): `$data['end_session']
= $data['expired'] = $result` — both flags set together. Same
dispatcher quirk we documented at audit §3 / D1 / D2: `expire()` wins
the elseif chain at `RunSession.php:311-316`. So Pauses that "end via
this path" actually have `expired=NOW`, not `ended=NOW`. Already in
the audit's prod-query advice.

### Branch: clean dispatch but cron/participant fork

`Branch::getUnitSessionExpirationData` at `Branch.php:118-167` is
simpler: evaluate condition via OpenCPU, get TRUE/FALSE/NULL.

| Result | Dispatch | Runtime |
|---|---|---|
| `TRUE` and (`automatically_jump` OR participant request) | `end_session=true; run_to=if_true` → `runTo($if_true)` | Jumps to target position. |
| `FALSE` and (`automatically_go_on` OR participant request) | `end_session=true; move_on=true` → `moveOn()` | Falls through to next position. |
| Other (NULL, check_failed) | `check_failed=true` | UnitSession::isExpired re-queues with `expiration_extension` (default `+10 minutes`). |

**Cron/participant fork**: if `automatically_jump` or
`automatically_go_on` is FALSE AND the request is cron-driven
(`isExecutedByCron()` true), the branch *waits* (returns
check_failed). If the same request comes from the participant, the
branch fires. The intent is "auto-step only when admin opted in"
but it interacts with multi-tick processing in subtle ways: a
cron-snapshotted reference fires the wait on tick N, the next tick's
re-snapshot might evaluate differently if the underlying R data
changed.

**Severity**: metric-only — the wait-and-retry design absorbs most
of the variance.

---

## 13. ExpiryNotifier vs server: drift points

Companion to §11 (`getCurrentUnitSession`). Phase-5 J5 confirmed
that `head.php:80-93` re-emits `unit_session_expires` from the
fresh `survey_unit_sessions.expires` value on **every** render —
which means the server-side `queue()` running during `execute()`
always normalises the column to the algorithm's current verdict
before the page renders. So the only drift between client and
server is *within a single rendered page*, not across renders.

### Within-render drift sources

| Source | When | Magnitude |
|---|---|---|
| `last_active+Z` slides as the user types | Z>0 set; user types but doesn't re-render | seconds → minutes per typing burst |
| `first_submit` becomes non-null on first POST | Y>0 set; first POST in a multi-page survey | one-time; deadline shifts post-access |
| `created` change (admin-only) | admin force-edits row | rare |
| Cron tick recomputes & writes new expires | Z>0 + queue picks up the row + re-queues | one tick window |

J2 in `survey-expiry-ui.spec.js` exercises the basic case: client
modal fires on the originally-rendered timestamp regardless of
server state. The drift is bounded — once the participant interacts
(POST, page navigation), they get a fresh `unit_session_expires`.

### Severity: **metric-only / UX-cosmetic**.

Worst case: the participant sees the modal a few minutes earlier
than they "really" should (server has slid the deadline later but
client doesn't know). Reload re-syncs them. No data is lost.

### Fix shape (deferred)

Adding a periodic `setInterval(checkExpiry, 30s)` polling endpoint
(GET `/run/<name>/expiry?code=...`) that returns the current server
expires, with the client adjusting its scheduled timeout as needed,
would close this drift. Not in scope for this branch.

---

## 14. Pause-skip "sixth path?" probe — deferred R-data inconsistency

Five known Pause-skip surfaces (D-1 … D-5) characterised in §2 and
in the spec files. The wiki/audit asked whether a sixth path
existed: a queue-driven `execute()` hits a Pause whose `relative_to`
references data that was inconsistent at queue-time and is now
resolved.

### Confirmed-reachable, characterised-but-not-tested

`Pause::getUnitSessionExpirationData` at `:130-141` evaluates the R
expression on every `execute()` call. There's no caching of the
result; the queue's stored `expires` was the previous evaluation's
output. If the R expression's data dependencies changed between
the queue's snapshot and the current tick, the result changes.

Specifically:
- **First evaluation, data missing** (e.g., `last(survey_X$field)`
  where `survey_X` has no rows yet) → R returns NA → PHP receives
  null → `check_failed=true` → re-queue with `+10 min`.
- **Subsequent evaluation, data present** (the participant has now
  filled `survey_X`) → R returns a valid value → expires set
  accordingly.

If the new value resolves to a *past* timestamp, the Pause expires
immediately on the next tick. **D-1 covers the boolean-true case;
this sixth-path covers the timestamp-flip case.** The DB shape is
identical to D-1 (`expired=NOW, result='expired', queued=0`).

### Severity: **UX-cosmetic** (mostly).

The participant whose Pause was extended by 10min then immediately
expired on the next tick effectively got a small wait. They then
proceed to the next unit. This is the "missed window" semantic
working as designed — just with a small grace built in via the
re-queue. No drop, no data loss.

### Why not testing it

A deterministic reproduction requires controlling OpenCPU's
response between cron ticks. The dev OpenCPU is shared with other
runs and can't be paused mid-eval. Worth a follow-up via either
(a) a dedicated OpenCPU stub or (b) a Pause whose `relative_to` is
mocked via a hardcoded short-circuit in `Pause.php:127` (already
in place for `tail(survey_unit_sessions$created,1)` — could extend).

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

`tests/e2e/survey-expiry-matrix.spec.js` — Phase 3, **15 tests** (was 13).
All 15 wiki / boundary cells assert cleanly post-fix; W1.c and W3.b
added in the carry-over branch.

`tests/e2e/survey-unfinished-pathways.spec.js` — Phase 4, **8 tests**
(was 6, plus U7 enabled). U10/U13 (supersede orphans), U11 (Pause
two-paths equivalence), U12 (Pause past timestamp), U9 (P10), U7
(validation race — now real, was skipped), U2 (admin removes unit),
U5 (admin forceTo past unvisited Survey).

`tests/e2e/survey-expiry-ui.spec.js` — Phase 5, **5 tests** (new file).
J1 (modal fires when client clock reaches expires), J2 (modal fires
client-only without server tick), J3 (lock-timeout reload), J4
(silent skip-after-expire), J5 (`window.unit_session_expires`
self-correction across renders).

**Total: 37 tests** all green (post-fix), 0 `test.fail()` annotations
remaining, 0 skipped. Future regressions turn the suite RED. Includes
U14 regression-gating the §11 `getCurrentUnitSession` fix.

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
