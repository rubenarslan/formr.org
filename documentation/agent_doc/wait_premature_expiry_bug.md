# BUG: Wait unit records a participant's early return as `expired`

**Status: RESOLVED** — reproduced locally on v1.1.1 and fixed on
`fix/wait-premature-expiry`. **Sections 1–10 below are the original
handoff and are preserved for provenance, but several of their
conclusions are wrong.** Read this header first.
**First observed:** 2026-08-09, production run `esm` on
`formr-admin.uni-muenster.de`.
**Filed by:** prior investigation session.

## Root cause (2026-08-09)

Two defects compound; neither is sufficient alone.

1. **Hydration regression.** `RunSession::getCurrentUnitSession()`
   passed a full DB row to `new UnitSession()`, but the constructor's
   `$options` allowlist (`ed56a95f`, 2026-05-04, shipped in v1.0.0 —
   so present in v1.1.1 *and* v1.4.0) keeps only `id`/`load`, and the
   row carries no `load` key, so `load()` never ran. Every participant
   web request at a parked Wait therefore ran with `created = NULL`
   and `expires = NULL`, defeating both fast paths in
   `Pause::getUnitSessionExpirationData()` (`:120`, `:128`) and forcing
   the wait anchor through OpenCPU's
   `tail(survey_unit_sessions$created, 1)`.
2. **Unordered run-data frame.** The query behind that data frame
   (`UnitSession::getRunData()`) had **no `ORDER BY`**. The optimizer
   drives from `survey_run_units` and does a per-unit ref lookup, so
   rows come back grouped by unit: `tail(...,1)` returns the newest
   session of *whichever unit sorts last*, not the most recent session.
   Measured staleness: 72 s on a real local ESM session, two days at
   2.4k rows, two weeks at 20k rows.

When that stale anchor is older than `wait_minutes`, the Wait reports
`expired` the instant the participant returns. `UnitSession::execute()`
then short-circuits (`:176`) without ever calling
`Wait::getUnitSessionOutput()`, and `RunSession::executeUnitSession()`
tests `expired` first (`:370`) → `expire()` → `move_on`. Because
`expire()` never writes `expires`, the column keeps the correct value
the parking pass wrote — which is why the deadline *looked* correct.

**Fixes:** pass `['id', 'load' => true]` in `getCurrentUnitSession()`;
add `ORDER BY survey_unit_sessions.created, survey_unit_sessions.id`
to the run-data query. Regression harness:
`bin/test_wait_tail_anchor_probe.php` (exit code 0/1).

## Corrections to the sections below

- **§1 / §4 "the computed deadline is correct in every observed case"
  is unsound.** `expire()` never writes `expires`, so that column
  records the earlier *parking* pass, not the exit that classified the
  row. The exit-time deadline is not persisted anywhere.
- **§6 #4 ("`relative_to` resolving to a stale anchor — disproved") was
  the actual cause.** The old probe saw the `Pause.php:128` fast path
  taken only because it hydrates with `load => true`, which the real
  web path did not.
- **§6 #2 is void** for the same reason as §1.
- **§7's "sharpest structural lead" (the Wait three-way branch) is a
  dead end** — that branch is never reached on the failing path.
- **§8 H1, H2, H5 are not needed.** H3 stays excluded for row 1763062
  by the §7 numerical-trap argument, which survives. H4 is a real
  latent fail-open, still unfixed, tracked separately.
- **§2's version caveat resolves:** v1.1.1 contains the bug; the
  v1.4.0 source was never needed.
- **§5's non-reproduction is explained:** the local control session ran
  only 7 minutes, so a 10-minute Wait's anchor could not yet be stale
  enough to misfire.

---

## 1. Symptom in one sentence

A `Wait` unit whose participant returns **before** the wait elapses is
sometimes recorded with `result = 'expired'` and takes the `move_on`
branch (advance to the next position) instead of the `run_to = body`
branch (jump to the unit the Wait points at) — which on the `esm` run
sends a reminder Email that should have been skipped.

**This is not a broken timer.** The computed deadline is correct in
every observed case. What goes wrong is the *classification of the
exit*, not the arithmetic.

---

## 2. Version caveat — read this first

The production instance that produced the evidence reports
**`v1.4.0`** in its admin footer (`FORMR_VERSION` is
`file_get_contents(VERSION)`, see `setup.php:3`). **This repo is
`v1.1.1`**, and no `v1.4.0` tag exists on `origin`
(github.com/timseidel/formr.org) or `upstream`
(github.com/rubenarslan/formr.org); `CHANGELOG-v1.md` archives the
0.19.x lineage, so it is not an older numbering scheme either.

So the production code is **not in this repo**. Every line reference
below is to **v1.1.1** and may not describe what actually ran.

The current working assumption for this investigation — set by the
repo owner — is: **assume v1.1.1 also contains the bug and hunt for it
here.** If a systematic pass through §8 exhausts the hypotheses without
finding it, that assumption is what to revisit; obtaining the v1.4.0
source becomes the next step.

---

## 3. The run under test

Run `esm` (local run_id **43**). Relevant units:

| position | type | key config |
|---|---|---|
| 10 | Survey | `state_master` — calculate + submit, auto-advances |
| 20 | SkipForward | `condition = FALSE`, `if_true = 120` |
| **30** | **Wait** | `wait_minutes = 1`, **`body = 100`** |
| 40 | PushMessage | — |
| **50** | **Wait** | `wait_minutes = 10`, **`body = 80`** |
| **60** | **Email** | the reminder that must not fire |
| 70 | Wait | `wait_minutes = 1`, `body` empty |
| 80 | Survey | `time_init_ping` |
| 90 | SkipBackward | `condition = TRUE`, `if_true = 10` |
| 100 | Survey | `self_init_ping` (1-minute inactivity expiry) |
| 110 | SkipBackward | `condition = TRUE`, `if_true = 10` |
| 120 | Page | endpage |

`body` on a Wait is a **jump target**, not display text. The design is:

- Wait(30) — 1 minute for the participant to self-initiate. If they
  show up → jump to **100** (`self_init_ping`). If not → fall through
  to **40** (push notification).
- Wait(50) — 10-minute response window after the push. If they show up
  → jump to **80** (`time_init_ping`). If not → fall through to **60**
  (Email reminder).

So **reaching position 60 is only correct after a full 10 minutes of
silence.** Every observed bad case reached it within 1 second.

---

## 4. Evidence — production (v1.4.0)

Run `esm`, session `FoYPw0roKpmK-nY_…`, 2026-08-09 13:34–13:56.
Source: admin user-detail view (the CSV export at the time lacked
`expires`; that gap is now fixed, see §11).

### Wait at position 50 (`wait_minutes = 10`, `body = 80`)

| unit_session | entered | `expires` | exited after | `result` | went to |
|---|---|---|---|---|---|
| 1762987 | 13:37:13 | 13:47:13 | 98 s | `wait_ended` | 80 ✅ |
| 1763013 | 13:41:38 | 13:51:38 | 0 s | `wait_ended` | 80 ✅ |
| **1763043** | 13:49:51 | **13:59:51** | **1 s** | **`expired`** | **60 → Email sent** ❌ |
| 1763064 | 13:51:40 | *(blank)* | 0 s | `wait_ended` | 80 ✅ |
| **1763079** | 13:54:21 | **14:04:21** | **0 s** | **`expired`** | **60 → Email sent** ❌ |

### Wait at position 30 (`wait_minutes = 1`, `body = 100`)

| unit_session | entered | `expires` | exited after | `result` | went to |
|---|---|---|---|---|---|
| 1763040 | 13:48:50 | 13:49:50 | 60 s | `wait_ended` | 40 ✅ (genuine elapse) |
| **1763062** | 13:51:26 | **13:52:26** | **13 s** | **`expired`** | **40** ❌ (47 s early) |
| 1763076 | 13:53:16 | 13:54:16 | 64 s | `wait_ended` | 40 ✅ |

**`expires` is correct on every row, including the bad ones.** The bug
is intermittent — the same unit takes the right branch most of the
time.

### Note on `1763064`

Blank `expires` means `queue()` never ran, i.e. the very first
execution of that unit session was a web request that took `run_to`
immediately. The bad rows *do* have `expires`, so they parked as cron
first and were exited by a **second** event.

---

## 5. Evidence — local control (v1.1.1), NOT reproduced

Local Docker, run 43, session `wonderfulMillipede…`, 2026-08-09
14:42–14:49. Same run structure, push notifications working
(`result = 'sent'`).

| unit_session | pos | entered | `expires` | exited after | `result` | went to |
|---|---|---|---|---|---|---|
| 8193 | 30 | 14:46:30 | 14:47:30 | **13 s** | `wait_ended` | **100** ✅ |
| 8183 | 50 | 14:44:54 | 14:54:54 | **1 s** | `wait_ended` | **80** ✅ |
| 8205 | 50 | 14:48:54 | 14:58:54 | 7 s | `wait_ended` | 80 ✅ |
| 8181 | 30 | 14:43:50 | 14:44:50 | 63 s | `wait_ended` | 40 ✅ |
| 8203 | 30 | 14:47:50 | 14:48:50 | 64 s | `wait_ended` | 40 ✅ |

**The matched pairs are the sharpest evidence available:**

| | entered | `expires` | exited after | `result` | went to |
|---|---|---|---|---|---|
| local 8193 Wait(30) | 14:46:30 | 14:47:30 | 13 s | `wait_ended` | 100 ✅ |
| prod 1763062 Wait(30) | 13:51:26 | 13:52:26 | 13 s | **`expired`** | 40 ❌ |
| local 8183 Wait(50) | 14:44:54 | 14:54:54 | 1 s | `wait_ended` | 80 ✅ |
| prod 1763043 Wait(50) | 13:49:51 | 13:59:51 | 1 s | **`expired`** | 60 ❌ |

Identical timing, identical early exit, opposite classification.

The local session never left a Wait(50) idle for a full 10 minutes, so
the **genuine**-elapse path into the Email is untested locally. Worth
running once as a control (it should reach the Email legitimately).

---

## 6. What is already ruled out — do not redo

| # | Ruled out | How |
|---|---|---|
| 1 | `wait_minutes` not loading | `survey_pauses.wait_minutes = 10.00` on unit 707; probe confirms it reaches `parseRelativeTo()` intact |
| 2 | Deadline miscomputed | `expires` correct on all bad rows (§4) |
| 3 | Missing `survey_pauses` row → empty `$conditions` → fail-open | row exists and loads |
| 4 | `relative_to` resolving through OpenCPU to a stale anchor | disproved by 2; probe shows the fast path at `Pause.php:128` is taken, `relative_to_result` = the unit session's own `created` |
| 5 | `Wait::setDefaultRelativeTo()` corrupting the anchor | it is **dead code** in the normal path (see §8 H2) — probe shows `default_relative_to` unchanged after evaluation |
| 6 | Isolated Wait evaluation | 6 scenarios (fresh / stored-expires-future / stored-expires-past × web / cron) all correct — `bin/test_wait_expiry_probe.php` |
| 7 | Daemon cascade Wait(30)→Push(40)→Wait(50) | Wait(50) parked correctly with `expires = created + 600`, `queued = 2`, no Email — `bin/test_wait_cascade_probe.php 43 30` |
| 8 | Genuine elapse → Email | confirmed correct — `bin/test_wait_cascade_probe.php 43 50` |
| 9 | Multiple queue workers racing (locally) | one container, `php bin/queue.php -t UnitSession`, single process. **Still unverified on production** |

---

## 7. Code map and the sharpest structural lead (v1.1.1)

### The three-way branch — `application/Model/RunUnit/Wait.php:50-69`

```php
if (empty($expiration['expired']) && !$unitSession->isExecutedByCron()
        && empty($expiration['check_failed'])) {
    $output['end_session'] = true;
    $output['run_to']      = $this->body;      // ← participant returned
} elseif ($expiration['expired'] === true) {
    $output['end_session'] = true;
    $output['move_on']     = true;             // ← elapsed
} else {
    $output['wait_user'] = true;               // ← still waiting
}
```

### Other relevant sites

- `application/Model/RunUnit/Pause.php:111-253` — `getUnitSessionExpirationData()`
  - `:120-124` early return when a stored `expires` is still in the future
  - `:176-187` the `has_wait_minutes` branch that builds `$conditions['minute']`
  - `:226-248` SQL evaluation of `$conditions`, **including the fail-open
    `else { $result = true; }` at `:246`**
  - `:250` `$data['end_session'] = $data['expired'] = $result;`
- `application/Model/UnitSession.php`
  - `:167-191` `execute()`
  - `:193-222` `isExpired()` — merges the whole `$expirationData` into
    `execResults` at `:198` **before** deciding, and checks
    `end_session` **before** `expires < time()`
  - `:241-277` `expire()` — the **only** writer of `survey_unit_sessions.expired`
- `application/Model/RunSession.php`
  - `:211-329` `execute()` — named lock, `reloadFromDb()`, and the
    **END-q** branch at `:289`
  - `:363-417` `executeUnitSession()` — tests `!empty($result['expired'])`
    **first**, before `end_session`, and calls `expire()` at `:370-372`
- `application/Queue/UnitSessionQueue.php`
  - `:107-134` `getSessionsStatement()` — `ORDER BY RAND()`, **no LIMIT,
    no atomic claim / `FOR UPDATE`**
  - `:172` sets `$runSession->user->cron = true`
  - `:178` `execute($unitSession, $queued == QUEUED_TO_EXECUTE)`
  - `:232-250` `addItem()` writes `expires` + `queued`

### ★ The sharpest lead: `expired` rules out the END-q path

`expire()` (`UnitSession.php:241`) is the only code that writes
`survey_unit_sessions.expired`. The daemon's normal path for a
`QUEUED_TO_END` row is the **END-q** branch at `RunSession.php:289`,
which calls `endCurrentUnitSession()` → `end()` and produces
`ended` + `result = 'wait_ended'` — **never `expired`**.

The bad production rows have `expired` set and `result = 'expired'`.
Therefore they did **not** come through END-q. They reached
`executeUnitSession()`, which happens only when:

- **(a)** a **web request** drove `RunSession::execute()`, or
- **(b)** the daemon drove it with `$executeReferenceUnit = true`,
  i.e. the row was `QUEUED_TO_EXECUTE` (1), not `QUEUED_TO_END` (2).

Path (b) is reachable only via `UnitSession::isExpired():200-207`,
which rewrites `expires` to `now + queue_expiration_extension`
(default `'+10 minutes'`) and `queued` to `QUEUED_TO_EXECUTE` when
`check_failed === true || expire_relatively === false`.

**Numerical trap when checking (b):** for the Wait at position 50,
`wait_minutes = 10`, so the correct deadline (`created + 600 s`) and
the failure deadline (`now + 10 minutes`) are **numerically
identical**. `expires = 13:59:51` on row 1763043 cannot distinguish
them. Use the Wait at position **30** to disambiguate:
`wait_minutes = 1`, so correct = `created + 60 s` = 13:52:26 while the
failure path would give 14:01:26. Row 1763062 shows **13:52:26**, i.e.
the normal path — which argues against (b) for that row, but the two
Wait(50) rows remain ambiguous.

---

## 8. Ranked hypotheses, each with a falsifiable check

### H1 — Web request racing the daemon cascade ★ most likely

**Mechanism.** The participant taps the push at position 40 while the
daemon is still cascading. `RunSession::execute()` takes a named lock
with a **10 s** timeout for web but only **0.1 s** for console
(`:222-225`), then `reloadFromDb()` (`:246`). Two actors interleaving
around the same run session can leave `execResults` describing one
unit while `$this->currentUnitSession` is another. `executeUnitSession()`
tests `!empty($result['expired'])` **first** (`:370`), so any leakage of
a truthy `expired` into `$result` expires the row regardless of what
the Wait's own branch intended.

**Fits:** intermittency; only production has a live participant; the
push at position 40 is exactly the event that makes both actors move
at once; the codebase already documents this failure class at
`RunSession.php:236-245` (the AMOR 2026-05-09 incident, duplicate
downstream unit sessions from two near-simultaneous requests).

**Check.** Set `$settings['unit_session']['debug'] = true` in
`config/settings.php` (default `false`,
`config-dist/settings.php:244`), then drive the run while hammering the
participant URL from a second shell so requests overlap the cascade.
Watch `docker logs -f formrsessionqueue`. Look for two executions of
the same `run_session_id` within the same second.

### H2 — Double evaluation with mutated RunUnit instance state

**Mechanism.** `getUnitSessionExpirationData()` is called **twice** per
`execute()` on the *same* `Wait` object — once from
`UnitSession::isExpired()` (`UnitSession.php:194`) and again from
`Wait::getUnitSessionOutput()` (`Wait.php:52`). `parseRelativeTo()`
mutates instance state on every call: it reassigns `$this->relative_to`,
casts/trims `$this->wait_minutes` to a string, and sets the
`has_relative_to` / `has_wait_minutes` flags.

Related, and confirmed: **`Wait::setDefaultRelativeTo()` is dead code.**
`Wait.php:46` calls it *before* `parseRelativeTo()` sets
`has_wait_minutes`, so its own guard
(`$this->has_wait_minutes && !$this->has_relative_to`, `:33`) is false
on the first call; on later calls `has_relative_to` is already true, so
it is false again. It never fires. When it *would* fire it writes
`json_encode($created)` — a quoted string that no longer matches the
`tail(...)` literal at `Pause.php:128`, diverting the anchor through
OpenCPU. Harmless today, but a trap if the ordering is ever "fixed"
without care.

**Check.** Log both calls' full return arrays plus the instance state
between them. Extend `bin/test_wait_expiry_probe.php` (it already has a
reflection helper `peek()`).

### H3 — `QUEUED_TO_EXECUTE` re-entry via `check_failed`

**Mechanism.** `UnitSession::isExpired():200-207` rewrites `expires` to
`now + '+10 minutes'` and `queued` to `QUEUED_TO_EXECUTE` when
`check_failed === true` (OpenCPU failure) or
`expire_relatively === false`. A `QUEUED_TO_EXECUTE` row makes the
daemon pass `$executeReferenceUnit = true` (`UnitSessionQueue.php:178`),
which **skips END-q** and goes to `executeUnitSession()` — the only
daemon path that can call `expire()`.

**Note:** `expire_relatively` is `null` for a `wait_minutes` Wait, and
`null === false` is false, so only `check_failed` can trigger this.

**Fits partially.** Explains `expired` rather than `ended`. But see the
numerical trap in §7: row 1763062 (Wait 30, `wait_minutes = 1`) has
`expires = created + 60 s`, not `+10 minutes`, so this path did **not**
produce that row. It remains possible for the two Wait(50) rows.

**Check.** Grep the production/OpenCPU logs for errors at 13:49:5x and
13:54:2x. Locally, force it: point `relative_to` at deliberately broken
R, or stop the `opencpu` container mid-cascade.

### H4 — `$conditions` fail-open at `Pause.php:246`

**Mechanism.** If `$conditions` ends up empty, the `else` branch sets
`$result = true` unconditionally → `expired = true`. This is a
**fail-open** guard: a Wait with no usable condition is declared
elapsed rather than raising.

**Fits poorly** — reaching an empty `$conditions` requires
`has_wait_minutes` false, which contradicts the correct `expires`
values. Listed because it is a genuine latent defect worth fixing
regardless, and because instance-state mutation (H2) could in principle
produce it on the *second* call.

**Check.** Assert `$conditions` non-empty for any unit with
`wait_minutes > 0`; log and raise instead of defaulting to `true`.

### H5 — Multiple queue workers on production

**Mechanism.** `getSessionsStatement()` (`UnitSessionQueue.php:119-126`)
does `ORDER BY RAND()` with **no LIMIT, no `FOR UPDATE`, no atomic
claim**. Two workers select overlapping row sets and both process them.
The per-run-session named lock is the only guard, and console callers
give up after 0.1 s.

**Check (production only, cannot be checked locally).** Ask the
Münster admin for the supervisord/compose config: does
`bin/queue.php -t UnitSession` run with `-n`/`-p` sharding or
`numprocs > 1`? Locally it is a single process, which is consistent
with the local non-reproduction.

---

## 9. Tools already built

Both live in `bin/` alongside this report:

- **`bin/test_wait_expiry_probe.php <run_id> <unit_id>`** — drives
  `UnitSession::execute()` through six scenarios (fresh /
  stored-expires-future / stored-expires-past × web / cron) and dumps
  the branch taken plus protected internals via reflection.
  `docker exec formr_app php /formr/bin/test_wait_expiry_probe.php 43 707`
- **`bin/test_wait_cascade_probe.php <run_id> <start_position>`** —
  parks a throwaway participant with an already-past `expires`, runs
  one real `UnitSessionQueue` pass, and prints every unit session the
  cascade produced plus whether the Email was reached.
  `docker exec formr_app php /formr/bin/test_wait_cascade_probe.php 43 30`

**Environment gotchas** (these cost the last session time):

- The repo bind-mounts at **`/formr`**, not `/var/www/formr`.
- Daemon containers are **`formrsessionqueue`** / **`formrmailqueue`**,
  not the `formr_run_daemon` / `formr_mail_daemon` names in CLAUDE.md.
  **Stop `formrsessionqueue` before driving `runOnce()` by hand**, or
  the live daemon races the deterministic pass.
- `survey_run_sessions` has `UNIQUE KEY run_user (user_id, run_id)` —
  a harness making several participants on one run must insert a
  distinct `survey_users` row per session, else the second insert dies
  with a bare `Error [1] in application/DB.php` (the 500 handler hides
  the PDO message).
- DB client is `mariadb`, not `mysql`; credentials are env vars inside
  `formr_db`.

---

## 10. Suggested order of work

1. **Control run first.** Leave a Wait(50) untouched for a full 10
   minutes locally and confirm the Email fires legitimately. Establishes
   the baseline the bug deviates from.
2. **H1.** Enable queue debug, drive the run with overlapping web
   requests. Highest prior, and the only hypothesis that explains the
   intermittency without extra machinery.
3. **H2.** Instrument the two `getUnitSessionExpirationData()` calls.
   Cheap, and rules in/out a whole class of state-mutation bugs.
4. **H3.** Force an OpenCPU failure mid-cascade.
5. **H4/H5.** Fix H4 regardless (fail-open → fail-closed). H5 needs
   production access.

**The signature to watch for**, now visible in the CSV export: a Wait
with `result = 'expired'` whose *next* unit session is the position
after it rather than its `body` target. `expires` alone will not reveal
it — it is correct on the bad rows.

---

## 11. Related export fixes — check whether your tree has them

Two fixes to `RunHelper::getUserDetailExportPdoStatement()`
(`application/Helper/RunHelper.php`) came out of this investigation.
They ship on their own branch, so **the tree you are reading this in
may or may not contain them.** Check before trusting any CSV export.

**(a) `seconds_stayed` was wrong for every expired unit session.** The
column was computed as:

```sql
IF (ended > 0, UNIX_TIMESTAMP(ended)   - UNIX_TIMESTAMP(created),
               UNIX_TIMESTAMP(NOW())   - UNIX_TIMESTAMP(created))
```

An expired session has `ended` NULL, so it fell into the `NOW()` branch
and reported *time since the participant entered the unit, as of the
moment of export* — not a duration. The number grew on every
re-export and contradicted the timestamps in the web view. Fixed by
falling back to `expired` before `NOW()`, so `NOW()` applies only to
sessions still genuinely open.

*Symptom if your tree lacks it:* in the production evidence a Wait that
actually lasted 1 s exported as `424`, and all nine expired rows
resolved to the same wall-clock instant (`entered + seconds_stayed` ==
export time).

**(b) The export was missing `expires`, `queued`, `result` and
`result_log`.** It carried only `created` / `ended` / `expired`, while
the admin web view (`templates/admin/run/user_detail.php`) also renders
`expires` — bolded while `queued > 0` — and `result` with `result_log`
as its tooltip. Fixed by appending the four columns (appended, not
inserted, so positional parsers of the old 9-column format keep
working).

*Why this matters here:* **without `expires` the bug documented above is
invisible in an export.** The deadline a Pause/Wait actually computed
lives only in that column, so an export showing a 10-minute Wait ending
after 1 second gives no way to tell whether the deadline was
miscomputed or a correct deadline was ignored. Establishing that it was
the latter — the finding §1 rests on — originally required a PDF print
of the admin web view.

**Verify quickly.** A fixed export has 13 columns:

```
session_id, position, unit_type, description, session, entered,
seconds_stayed, left, expired, expires, queued, result, result_log
```

If yours has 9 and stops at `expired`, neither fix is present; export
parity is a prerequisite for the diagnostic in §10.
