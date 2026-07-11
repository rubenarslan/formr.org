# Run-engine audit — linearity, races, superseded units (2026-07)

Multi-agent audit of the RunUnit/RunSession/UnitSessionQueue engine on
`release/1.4.0` (after the July run-engine fix chain ea04eccb → 8529f5fa),
checking the documented invariant:

> A run is a linear sequence of units ordered by position; each
> participant's RunSession moves through it predictably; every move is
> deterministic given stored state; unit sessions are never duplicated,
> lost, or acted on by a stale actor after being superseded.

**Verdict: the per-request concurrency core holds; the invariant breaks at
four boundaries the July work did not reach.** All six analysis lenses
independently confirmed the lock scheme is sound: `GET_LOCK` +
`reloadFromDb()` under the lock (`RunSession.php:232-251`), the END-q
identity re-check and stale-reference drop (`:294-315`), patch 063's
`UNIQUE(run_session_id, run_unit_id, iteration)` with 23000
adopt-the-winner, placement-scoped supersede (D1), `ended IS NULL`
idempotent terminals, and the Email/Push idempotency claims are all present
and effective. The breaks are: (1) **terminal states are not write
barriers** (`ended` run sessions re-execute, march, and self-revive);
(2) **side-channel writers bypass the lock and placement identity**
(admin/API movers, the reminder path); (3) **the run structure is treated
as immutable but every admin surface mutates it mid-flight unguarded**;
(4) **time-based decisions act on stored deadlines nobody revalidates or
refreshes**.

Of 24 deduplicated findings (from 40 raw), **23 were confirmed and 1
refuted** by adversarial verification. Zero were downgraded to "plausible".

## What "confirmed" means here (method)

Verification was **adversarial code-path analysis, not live reproduction**.
No agent drove the UI or mutated the database. Each critical/high finding
was checked by two independent agents with full repo access and opposing
goals; medium/low findings got the skeptic only:

- a **refuter** instructed to kill the finding by locating any lock, DB
  constraint, guard clause, or ordering that prevents the scenario, and to
  default to "not real" when it could not conclusively trace the path;
- a **reproducer** instructed to rebuild the scenario step by step, citing
  for every step the code that makes it possible, rejecting the finding if
  any step is impossible — and checking git history so already-fixed
  issues don't count.

"Confirmed" = the complete path exists in the current code with no
blocking guard, per both (or, for medium/low, the skeptic). It does *not*
mean the flawed state was observed in a running instance. No e2e
reproduction has been attempted yet. A few agents ran read-only checks
(e.g. the dev DB shows zero hijacked reminder rows — consistent with the
finding, since no reminders have been sent here since patch 047).

### Likelihood tiers used below

- **T1 — routine, deterministic.** A single ordinary action (admin click,
  back button, config choice, authoring mistake) triggers it; no unlucky
  timing needed.
- **T2 — plausible window.** Needs a timing coincidence that recurring
  operations make inevitable over time (daemon restart/deploy, deadline
  passing mid-activity, DST).
- **T3 — narrow interleaving.** Needs two actors in the same sub-second
  window; rare, but unguarded.

## Confirmed findings — critical & high

| # | Sev | Tier | Finding | Anchor |
|---|-----|------|---------|--------|
| 1 | crit | T1 | Reminder emails create off-position unit sessions stamped with the *current placement's* `run_unit_id`; `getCurrentUnitSession`'s placement arm (no `unit_id` constraint, `ORDER BY id DESC`) adopts the never-ended reminder row as current, and the participant's next request executes an Email unit instead of their live unit, then moves on — skipping them past unfinished work. Introduced by the D1 fix itself (`ea04eccb` made these previously-unmatchable rows match). | `UnitSession.php:84`, `RunSession.php:598`, `AdminAjaxController.php:632` |
| 2 | high | T1 | Web request on an **ended** run session re-executes the current unit session with full side effects; survey POSTs write into the already-ended session (no `ended IS NULL` guard on `updateSurveyStudyRecord`'s UPDATEs) — participants can mutate submitted research data after completion via back-button re-POST. | `RunSession.php:266`, `UnitSession.php:745,813` |
| 3 | high | T3/T1 | Admin/API lifecycle mutators (`forceTo`/`runTo`/`positionSessions`/API advance) run entirely outside the run-session lock: racing a locked daemon cascade duplicates live unit sessions and clobbers `position`/`ended` (T3); `ajaxNextInRun` deterministically stalls cron-only participants (T1). `runTo` also unconditionally revives ended sessions (`SET ended = NULL`). | `RunSession.php:508,530` |
| 4 | high | T2 | Daemon END-q never revalidates the deadline: queue `expires` is written from pre-POST state and the web path never refreshes it, so sliding-window (Z>0) surveys expire actively-working participants at the stale timestamp; X=0/Z>0 configs get no deadline at all. | `RunSession.php:294` |
| 5 | high | T1 | A daemon cascade passing an External redirect unit advances `position` but never executes or queues the successor — the run session parks `PENDING/queued=0`, invisible to the daemon, until the participant happens to return. Timer-driven chains containing an External stall deterministically. | `RunSession.php:421` |
| 6 | high | T2 | The moveOn cascade is a chain of non-transactional autocommit writes with no outbox or recovery sweep: a daemon SIGKILL or exception mid-cascade (every deploy restarts the daemon) strands sessions in states the daemon's `queued>=1` SELECT can never see. Sessions are *lost from the schedule*, not duplicated. | `RunSession.php:296` |
| 7 | high | T1 | Reordering a live run: moveOn re-resolves "which unit" from the live structure at the stored numeric position on every request; a drag-reorder makes participants repeat completed units, skip pending ones, or strands their open unit session while re-creating the moved unit. | `RunSession.php:375` |
| 8 | high | T1 | API `PUT` run structure (`Run::replaceUnits`) over a live run silently splices, restarts, or force-ends every in-flight participant (positions beyond the new numbering are terminal). Note: the formr-mcp file workflow uses this endpoint. | `Run.php:1473` |
| 9 | high | T1 | Branch/Skip jump to a nonexistent position silently degrades into sequential fall-through — participants enter the exact arm the branch was meant to route them away from, with only a participant-facing alert (no admin notification). | `RunSession.php:426` |

## Confirmed findings — medium & low

| # | Sev | Tier | Finding | Anchor |
|---|-----|------|---------|--------|
| 10 | med | T1 | Duplicate `(run_id, position)` rows are accepted server-side (`position_run` is a plain KEY, reorder/import unvalidated); traversal resolves them via two independent unordered `LIMIT 1` lookups that can disagree — one unit is shadowed entirely, and `unit_id`/`run_unit_id` can bind from different placements. | `Run.php:554`, `schema.sql:607` |
| 11 | med | T2 | `MAX_EXECUTION_COUNT` spam() irreversibly ends run sessions during *legitimate* catch-up cascades: after a multi-day daemon outage, one participant click cascades through the backlog, trips the flat counter at 10, and ejects them from the study permanently. | `RunSession.php:273,672` |
| 12 | med | T1 | A unit at position 0 bricks the run for new participants (`getFirstPosition()` truthiness treats 0 as "no units") and moveOn's `if ($this->position && …)` treats a 0 target as end-of-run; reorder/import accept 0 unvalidated. | `RunSession.php:281,381` |
| 13 | med | T1 | Survey POSTs are bound to whatever unit session is *current*, not the one the form was rendered for (`updateSurveyStudyRecord` unsets the posted `session_id` instead of validating it): in looping/diary runs, a back-button resubmit of iteration N is silently stored as iteration N+1's answers. | `UnitSession.php:672` |
| 14 | med | T2/T1 | PushMessage claim-before-send: a daemon kill between claim INSERT and send silently loses the push (re-encounter ends with result NULL, claim stuck at `status='queued'` forever) (T2); *and* the routine `no_subscription`/parse-fail branches never update the claim row, so push_logs accumulates stuck rows that destroy the only audit signal that could detect real loss (T1). At-most-once is a documented trade-off; the undetectability is not. | `PushMessage.php:139-195` |
| 15 | med | T1 | The overdue-Pause fix (80e89dcb) guards on a *persisted* `expires`, which only the queue writes — on `cron_active=0` runs nothing persists it, so the original forever-slide (now-relative `relative_to` re-arming per page load) is still alive there. | `Pause.php:120` |
| 16 | med | T2 | The daemon's long-lived DB connection freezes MySQL session `time_zone` at connect-time offset: after a DST transition, queue pickup and every `NOW()` comparison skew by an hour until the daemon restarts; the fixed `+86400s` day-rollover in Pause has the same blind spot. | `DB.php:91` |
| 17 | med | T2 | Shuffle re-execution is non-idempotent: a crash between the shuffle INSERT and `end()` leaves a row that makes every retry throw an uncaught duplicate-key error — the participant is permanently stuck. | `Shuffle.php:85` |
| 18 | med | T1 | Shuffle group assignment is not stable per participant: every SkipBackward revisit re-randomizes (per-visit row keyed to the new unit session), and downstream R conditions then read a *mutated* group — condition assignment changes mid-study by design of the loop. | `Shuffle.php:84` |
| 19 | low | T1 | The Pause/Branch `check_failed` +10-minute re-arm loop is unbounded: permanently broken R code requeues forever, hammering OpenCPU and emailing the admin on every cycle. | `UnitSession.php:348` |
| 20 | low | T3 | `endLastExternal` ends ALL live External unit sessions of a run session, lock-free — a delayed callback for one External kills the participant's current, unrelated External. | `RunSession.php:639` |
| 21 | low | T2 | `Email::sendNow` (the `email.use_queue=false` path) has no idempotency claim — a crash between SMTP success and the audit write permits a duplicate send on retry (the queued path is protected; this path is not). | `Email.php:325` |
| 22 | low | T1 | Soft-unlinking Survey/Email/PushMessage units (placement-scoped `removeFromRun`) leaves live and queued unit sessions permanently open — the "participants will be affected" warning is decorative; nothing ends or reroutes them. | `RunUnit.php:189` |
| 23 | low | T1 | Pause/Wait can reach `EXPIRED/result='expired'` via the recompute path — the terminal *label* depends on which evaluation path fires, contradicting the documented per-type state machine and muddying analysis exports. | `RunSession.php:399` |

## Refuted (1)

**"Hard-delete of one placement destroys the unit at ALL placements."**
The code mechanics are accurate (`RunUnit::delete()` is unscoped; the FKs
cascade), but the precondition fails: only Surveys support
multi-placement reuse, and `Survey` does *not* override `removeFromRun` —
it inherits the placement-scoped base delete. For every type whose
`removeFromRun` is destructive, no supported path (editor, import, API
PUT, MCP) can create a multi-placement configuration in the first place.
The narrower true core — deleting a non-Survey unit cascade-destroys its
session history for all participants at its *single* placement — stands
and is subsumed by findings 7/22 and the ON DELETE CASCADE note below.

## Hand-verified addenda (traced in source by the reviewing session)

These were verified directly by reading the code, independent of the agent
panel:

- **Web-path `UnitSession` objects are skeletal.**
  `getCurrentUnitSession()` SELECTs the full row but the constructor
  allowlist from `ed56a95f` (2026-05-04, "block FK smuggling") keeps only
  `id`/`load`, and `load()` runs only when a `load` flag is passed — this
  call site doesn't pass it (`RunSession.php:614`, `UnitSession.php:58`).
  So on every interactive request, `isExpired()` evaluates unit deadline
  guards against `null` `expires`/`created`/`queued`/`result`. Verified
  consequences: the Pause overdue guard (`Pause.php:120`) is dead on the
  web path even for cron-active runs (the fix's own comment targets
  exactly the "daemon down or lagging" web case); External web-side expiry
  silently defers to the daemon (`External.php:115`); Survey web-side
  expiry returns `expires=0` and never refreshes the queue row — the
  no-refresh half of finding 4. Layered with finding 15: hydration kills
  the guard where `expires` *is* stored; non-persistence kills it where it
  isn't. The 80e89dcb fix therefore shipped dead on its primary target
  path. Fix shape: construct with `['id' => $row['id'], 'load' => true]`
  (as the ended-run branch already does) — or better, kill options-row
  hydration entirely (see refactor).
- **`acquireLock` binds the timeout as `PDO::PARAM_INT`**
  (`RunSession.php:1062`), truncating the configured queue timeout 0.1s
  to 0. Benign (the queue retries) but the config value is fiction.
- **Reminder hijack chain (finding 1) independently re-traced end-to-end**:
  `Run.php:764` → `createUnitSession($unit, false)` inserts via
  `create()`; `UnitSession.php:82-104` stamps `run_unit_id` from the run
  session's position with no unit-identity check (the `34378a8e` guard
  went into `load()`'s fallback only); `//$email->end();` commented out at
  `AdminAjaxController.php:632`; `RunSession.php:598-601` first disjunct
  matches without `unit_id`. Confirmed real; only latent here because no
  reminders were sent since patch 047.

## Per-lens invariant verdicts (condensed)

- **web-web**: happy-path concurrency holds (lock verified
  reference-counted on this MariaDB, so nested execute() re-acquisition is
  safe); breaks at state-machine boundaries — `ended` is not terminal,
  POSTs aren't bound to their session, admin movers unlocked.
- **web-daemon**: serialization core holds; fails at the edges — lock-free
  lifecycle mutators, END-q trusting stale stored deadlines, `ended` not
  terminal, PHP-only position ordering.
- **daemon-daemon**: single-worker mainline holds and every July fix was
  verified present; breaks via side-channel session creators (reminders),
  the non-transactional cascade with no recovery sweep, and lock-bypassing
  writers. Push double-send is closed at the cost of undetectable
  at-most-once loss.
- **superseded**: invariant holds only while structure is frozen; the
  stored numeric position is the sole cursor and every mutation surface
  (reorder, delete, API PUT) is unguarded — no lock, version stamp, or
  active-session check.
- **time**: core machinery holds; residuals are stored-deadline drift
  (finding 4), the cron-inactive gap (15), DST skew (16), and
  terminal-label nondeterminism (23).
- **linearity**: forward single-actor path holds post-Track A; fails for
  invalid jump targets (silent fall-through), Shuffle revisits
  (re-randomization), duplicate/zero positions, and ended-session revival.

## Refactor recommendation

**Yes — targeted (essentially Track B, re-prioritized by these findings) —
not a rewrite.** The July fixes prove the locking design is sound; what
the confirmed findings share is scattered authority: writes to
`survey_unit_sessions` from 7 files, two competing hydration paths, three
independently-written idempotency claims (one missing), and terminal
states enforced only where someone remembered a WHERE clause. Priorities:

1. **One hydration path** (hotfix first): always load by PK; then delete
   options-row hydration so the `ed56a95f` allowlist stops being
   load-bearing. Closes the addendum + halves of findings 4/15.
2. **Make `ended` a write barrier**: guard `moveOn`/`executeUnitSession`/
   `updateSurveyStudyRecord` on run-session and unit-session terminality;
   make the ended-branch render-only; drop `runTo`'s `ended = NULL`.
   Closes 2, part of 3, 23.
3. **One session-creation path with placement identity**: `create()` must
   verify the unit belongs at the stamped `run_unit_id` (reminders get
   `run_unit_id = NULL` or a dedicated special-unit marker). Closes 1.
4. **Route every lifecycle mutator through the lock** (extract
   acquire/release from `execute()`; wrap `forceTo`/`runTo`/
   `positionSessions`/reminder send). Closes 3, 20.
5. **Cascade atomicity or a recovery sweep**: either transactional
   move-and-enqueue, or a periodic sweep for `ended IS NULL AND queued=0`
   live sessions (also catches the External-park). Closes 5, 6, 17.
6. **Structure-mutation guards**: `UNIQUE(run_id, position)` + reject
   position ≤ 0 + two-phase reorder; block or migrate active sessions on
   delete/PUT; validate jump targets at save and alert admins at runtime.
   Closes 7, 8, 9, 10, 12; downgrades the ON DELETE CASCADE risk.
7. **Deadline revalidation**: END-q recomputes the deadline from current
   state before expiring; web activity refreshes queue `expires` (works
   once #1 lands). Closes 4; shrinks 11's blast radius (spam() should
   also distinguish catch-up cascades from loops).
8. Smaller: seed-stable Shuffle per (run_session, run_unit); idempotency
   claim for `Email::sendNow`; TTL/reconciliation for push claim rows;
   cap the +10min re-arm loop; reconnect-or-set `time_zone` per daemon
   tick.

## Provenance

Workflow `wf_ffd55829-9c2`, 4 attempts (3 spend-limit interruptions),
resumed via journal cache. 12 sonnet gatherer dossiers → 6 full-model
adversarial lenses (4 ran twice; the rerun set superseded the first) →
sonnet dedup (40 raw → 26 groups, top 24 verified) → 33 verifier agents
(refute+reproduce for critical/high, refute for medium/low). ~7M subagent
tokens total. Per-agent transcripts: session
`7c7b21d7`, `subagents/workflows/wf_ffd55829-9c2/journal.jsonl`. Full
finding JSON (scenarios, evidence, verifier notes): task outputs
`we4bpgjd2.output` (final), `wx044iyyh.output`, `wv2u4po54.output`.
No e2e reproduction attempted yet; findings 1, 2, 7, 13 are the natural
first candidates for Playwright confirmation on the dev instance.
