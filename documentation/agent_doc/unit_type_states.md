# RunUnit types and the `survey_unit_sessions.state` values they reach

Companion reference for `refactor_queue_track_a_states.d2` (the
state-machine flowchart). Each unit type's row covers:

- which state is reached when `UnitSession::create()` runs (always
  PENDING; pinned here only to make the table self-contained),
- the next transition triggered by `RunSession::executeUnitSession`'s
  dispatch on the result keys returned from
  `<RunUnit>::getUnitSessionOutput`,
- terminal states reachable, and
- per-type quirks worth knowing (raw-UPDATE bypasses, deviations from
  the standard end-session contract, OpenCPU surface area).

The 7-value `state` ENUM is: `PENDING / RUNNING / WAITING_USER /
WAITING_TIMER / ENDED / EXPIRED / SUPERSEDED`. RUNNING is reserved for
Track B and never written by Track A code (synchronous cascades
transition straight to ENDED).

## Per-type matrix

| Unit type | Created → first transition | Terminal states reachable | Notes |
|---|---|---|---|
| **Survey** | PENDING → **WAITING_USER** via `addItem()` (`expires` set from X+Y/Z timer) | **ENDED** (participant submits final page → `end_session+move_on` → `end()`); **EXPIRED** (X+Y or Z deadline hits → `expired` → `expire()`) | Can cause SUPERSEDED on a prior queued same-`unit_id` row if it wasn't `end()`'d cleanly (diagnostic only — see §K of `tests/e2e/prod_release_compare.sql`). |
| **Pause** | PENDING → **WAITING_TIMER** via `addItem()` (`expires` = wall-clock resume time) | **ENDED** (cron tick at `expires <= NOW()` → END-q branch → `endCurrentUnitSession` → `end()`) | Does **not** reach EXPIRED in normal flow — `endCurrentUnitSession`'s switch routes Pause through `end()`, not `expire()`. |
| **Wait** | PENDING → **WAITING_TIMER** via `addItem()` (`expires` = deadline + dynamic `relative_to`) | **ENDED** by either arm: (a) participant arrives before deadline (non-cron tick, not expired) → `end_session+run_to=<body>` → ENDED with `result='wait_ended_by_user'`, run **jumps to body target** skipping any reminder chain that follows; (b) deadline fires (cron tick, expired) → `end_session+move_on` → ENDED with `result='wait_ended'`, run advances to the next position (typically the reminder unit). | Distinct from Pause: Pause blocks the participant for a fixed duration; Wait blocks the run for a fixed duration **but ends early if the participant arrives**. The load-bearing primitive for ESM / reminder-chain designs. The OpenCPU surface is for dynamic `relative_to` expressions only (same as Pause), **not** per-tick condition evaluation. |
| **External** | PENDING → **WAITING_USER** via `addItem()` (`expires` = `expire_after` deadline) | **ENDED** (web-return completion paths return `end_session+move_on` → `end()`); **EXPIRED** (cron tick on a queued External hits END-q → routes External through `expire()`, not `end()`); also **ENDED** via API callback path (`RunSession::endLastExternal`, the `/external-end` API endpoint hit by the external service when it's done) | Three different terminations: web-return = ENDED via `end()`; cron-expiry = EXPIRED via `expire()`; API-callback = ENDED via `endLastExternal` raw UPDATE. Track A A8 brought `endLastExternal` in line with the standard column set (`result`, `queued`, `state`, `state_log`); pre-fix it wrote only `ended=NOW()` and left state stuck at WAITING_USER. |
| **Email** | PENDING → directly to **ENDED** (synchronous; no `addItem()` because no `expires`) | **ENDED** only | `getUnitSessionOutput` returns `end_session+move_on` in one execute(). Track A A4's `idempotency_key` UNIQUE on `survey_email_log` (`"email:{us_id}:{email_id}"`) blocks a SIGKILL-restart double-send. Track A A5: `cron_only` Email gate now works correctly (`User->cron=true` is set in `UnitSessionQueue::processQueue`). |
| **PushMessage** | PENDING → directly to **ENDED** | **ENDED** | Track A A8 added `end_session` to every terminating return in `getUnitSessionOutput` so Push transitions cleanly (pre-A8 it returned only `move_on` and stranded the row at PENDING). Track A A4's `idempotency_key` UNIQUE on `push_logs` (`"push:{us_id}"`) blocks a SIGKILL-restart double-send; on duplicate INSERT the handler returns `['end_session' => true, 'move_on' => true]`. |
| **Page** (Endpage) | PENDING → directly to **ENDED** | **ENDED** | `getUnitSessionOutput` returns `end_session+end_run_session+content`. `end_session` triggers `UnitSession::end()`; `end_run_session` also triggers `RunSession::end()` (the **run-session** ends too). |
| **Shuffle** | PENDING → directly to **ENDED** (synchronous) | **ENDED** | Picks a branch and returns `end_session+move_on` in one execute(). |
| **SkipForward** (Branch) | PENDING → directly to **ENDED** | **ENDED** | Evaluates OpenCPU condition. True → `end_session+run_to=<target>`; False → `end_session+move_on`. Can cause SUPERSEDED on the `run_to` target's prior row (diagnostic only). |
| **SkipBackward** (Branch) | PENDING → directly to **ENDED** | **ENDED** | Same shape as SkipForward; back-jump nature means it's the **primary legitimate source** of duplicate-`unit_id` rows in a run-session. The `iteration` column distinguishes loop passes. |

## SUPERSEDED is a diagnostic, not a transition you design around

The SUPERSEDED state only fires when `UnitSession::create()` finds a
prior row for the same `(run_session_id, unit_id)` with `queued > 0` —
i.e. a row that **should have been `end()`'d before the back-jump but
wasn't**. In clean diary-loop / SkipBackward flow, the prior row is
ENDED with `queued=0`, and the supersede UPDATE's `WHERE queued > 0`
predicate matches nothing.

Any non-zero count in production (use `§K SUPERSEDED rate per (run_id,
position)` in `tests/e2e/prod_release_compare.sql`) is a signal of:
race-condition partial state, daemon-kill mid-cascade, or admin
force-to bypassing `end()`. Watch the trend across releases; ideally
it shrinks toward zero.

## See also

- `documentation/agent_doc/REFACTOR_QUEUE_PLAN.md` — full Track A
  design + deferred Track B rewrite
- `documentation/agent_doc/refactor_queue_track_a_states.d2/.svg` —
  the state-machine flowchart this table corresponds to
- `documentation/agent_doc/refactor_queue_current.d2/.svg` — pre-Track-A
  architecture diagram (for cross-reference)
- `documentation/agent_doc/refactor_queue_proposed.d2` — deferred Track B
  end state
- `tests/EmailPushIdempotencyTest.php`, `tests/IdempotencyKeyTest.php`,
  `tests/PushMessageStateTransitionTest.php`, `tests/EmailCronOnlyTest.php`,
  `tests/UnitSessionStateTest.php`, `tests/StateLogJsonTest.php` —
  state-machine assertions
- `bin/test_track_a_smoke.php`, `bin/test_track_a_backfill_smoke.php`,
  `bin/test_track_a_idempotency_smoke.php` — live-MariaDB
  integration smokes
