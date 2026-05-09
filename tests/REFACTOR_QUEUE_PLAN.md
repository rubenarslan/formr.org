# Refactor plan: unit-session lifecycle and queue (vNext, major)

Status: draft, pre-implementation. Owner: discuss before any code lands.

## Goal

Today's `survey_unit_sessions` table conflates three roles: (1) the
queue of pending work for the cron daemon, (2) the per-participant
history of unit executions, and (3) the live state of the active
participant flow (`current_unit_session_id`, `position`). The
v0.25.6 / v0.25.7 fixes plugged real bugs at the leaf level (W4.a
expiry, stale-reference cascade, supersede-orphan, position-race,
non-idempotent send) but every fix was a defensive guard layered on
top of a single overloaded data structure. Each guard added another
implicit invariant — `queued != -9` here, `ended IS NULL AND
expired IS NULL` there, `reloadFromDb()` after lock — and the
invariants now interact in ways that are hard to reason about
without staring at five files in parallel.

Goal: redesign so the four concepts are independent.

- **Definition.** A `RunUnit` describes one step of a Run (Survey,
  Pause, Email, …). Static; admin-edited.
- **Execution.** A `UnitExecution` row records ONE participant's
  ONE attempt to run that unit. Has a state machine. Terminal.
- **Queue.** A `WorkItem` row is a job for a worker — execute /
  expire-check / send-email / send-push. Claimed via `SELECT … FOR
  UPDATE SKIP LOCKED`. Disposable.
- **Live participant pointer.** A `RunSessionPosition` row (or
  column on `survey_run_sessions`) names the currently-active
  `UnitExecution` plus its run-level `position`. Single source of
  truth.

This is a major-version change because the schema, the invariants
the codebase relies on, and the cron daemon's pickup logic all
move. We can phase the rollout (see §11) but the end state is
incompatible with v0.x consumers reading `survey_unit_sessions.queued`
directly.

## Non-goals

- Rewriting Survey rendering (`Survey::processStudy`, paged
  rendering, OpenCPU integration). Those are orthogonal.
- Replacing MariaDB with anything else. Postgres has nicer queue
  primitives but the operational delta isn't worth the disruption
  for this codebase.
- Replacing the formr admin UI's unit editor. Admin-side stays.
- Changing the participant URL contract (`/run-name/?code=…`).
- Touching SkipForward/SkipBackward semantics — they keep producing
  multiple unit-executions for the same `unit_id`. The new model
  represents that explicitly, but the run-author-facing behaviour
  is unchanged.

## Why this is major-version-worthy

A patch fix can plug one race. A minor can rearrange one method.
This refactor:

1. Adds new tables and rewrites every cron-side WHERE clause.
2. Changes the on-disk meaning of `queued` (deprecates it) — any
   external scripts that read it (operator runbooks, external
   monitoring, the admin UI's queue inspector at
   `templates/admin/run/sessions_queue.php`) need an update.
3. Rewrites `RunSession::execute` from a 100-line monolith with
   four-way dispatch into a small dispatcher that delegates to
   per-state handlers.
4. Introduces a worker-pool model. Today's single-process
   `formr_run_daemon` becomes one of N workers; multi-worker is the
   default. That changes the deployment story (`docker-compose`
   scale, supervisor model).
5. Establishes idempotency contracts that callers (Email, Push)
   need to honour. Existing call-sites change.

None of these are minor.

## Current architecture (concise)

### Tables

- `survey_units` — unit definitions (id, type, position-irrelevant).
- `survey_run_units` — links a unit to a run at a position.
- `survey_studies`, `survey_pauses`, `survey_pages`, `survey_emails`,
  `survey_push_messages`, `survey_externals`, `survey_branches`,
  `survey_shuffles`, `survey_waits` — per-type configuration.
- `survey_run_sessions` — one row per participant per run. Fields:
  `id`, `session` (token), `run_id`, `position`, `current_unit_session_id`,
  `ended`, `last_access`, `created`.
- `survey_unit_sessions` — the focus of this refactor. Fields:
  `id`, `unit_id`, `run_session_id`, `created`, `expires`, `queued`,
  `result`, `result_log`, `ended`, `expired`. Doubles as queue +
  history + active-state.
- `survey_email_log` — proper queue for email sending. `status` flag,
  `created`/`sent` timestamps. Decoupled from `survey_unit_sessions`
  but linked via `session_id`. **This is the model the rest of the
  refactor generalises.**
- `survey_items_display` — per-render per-item state for Survey units.

### Unit types

`RunUnitFactory::SupportedUnits` — `Survey`, `Pause`, `Email`,
`PushMessage`, `External`, `Page`, `SkipBackward`, `SkipForward`,
`Shuffle`, `Wait`. (`Branch` is an internal base class for the two
Skip variants and is not user-selectable.) Their behaviours, in one
line each:

- **Survey** — renders a form, accepts POSTs, ends when complete or
  when the X/Y/Z expiry algorithm fires.
- **Pause** — waits until a wall-clock or relative time, then
  cascades to the next unit.
- **Email** — sends one mail (sync or via the email queue), then
  cascades. Has a `cron_only` flag.
- **PushMessage** — sends one web-push notification, then cascades.
  No `cron_only` equivalent; always fires on whichever path runs it.
- **External** — redirects the participant to an external URL.
- **Page** (a.k.a. Endpage) — terminal; renders the run's final
  page.
- **SkipForward / SkipBackward** — evaluate an OpenCPU R expression
  yielding TRUE/FALSE; on TRUE, set `position` to a target unit and
  cascade. SkipBackward is the back-jump primitive that legitimately
  produces multiple `unit_id` instances per run-session.
- **Shuffle** — picks one of N branches at random and `run_to`s it.
- **Wait** — `Pause`-like but with an OpenCPU-driven condition
  rather than a wall-clock. Effectively deprecated; see callsites.

### Execution paths

Two callers can drive `RunSession::execute()`:

- **Web request.** `RunController::run` instantiates `RunSession`
  from the participant's session token, calls `execute()` with no
  reference. Lock timeout 10 s.
- **Cron daemon.** `bin/queue.php -t UnitSession` runs
  `UnitSessionQueue::processQueue` in a loop. Each pickup
  instantiates a fresh `RunSession`, calls
  `execute($referenceUnitSession, $executeReferenceUnit)`. Lock
  timeout 0.1 s (effectively zero).

Inside `execute()` the dispatch is:

1. `acquireLock('run_session_<id>', $timeout)` — fail-fast for cron,
   wait-then-fail for web.
2. `reloadFromDb()` (added v0.25.7) — refresh `position`, `ended`,
   `current_unit_session_id`.
3. `if ($this->ended)` — handle ended-run-session paths.
4. `getCurrentUnitSession()` — derives "current" by querying
   `survey_unit_sessions` filtered by `unit_id = at_position(position)
   AND ended IS NULL AND expired IS NULL AND queued != -9`
   `ORDER BY id DESC LIMIT 1`.
5. Three-way dispatch based on (referenceUnitSession, currentUnitSession):
   - **END-q**: ref==current, !executeRef → `endCurrentUnitSession()`
     + `moveOn()`.
   - **stale-reference**: ref!=current → `removeItem(ref.id)`,
     return `body=''`.
   - **currently-active**: no-ref or executeRef-true → recurse into
     `executeUnitSession()`.
6. `executeUnitSession()` calls the unit's `execute()`, dispatches
   on the result keys (`expired` / `end_session` / `queue` /
   `wait_user` / `wait_opencpu` / `redirect` / `run_to` / `move_on`
   / `end_run_session` / `content`).

### Cascade

A single `moveOn()` from Pause(124) recursively: end current → set
`position = getNextPosition(current)` → `createUnitSession(nextUnit)`
→ recursive `execute()` (with lock held, reentrant via MariaDB
GET_LOCK on same connection) → `executeUnitSession()` → next unit's
execute → returns end_session+move_on or move_on → end → moveOn …

This unrolls 124→127→128→129 in one PHP call stack under one lock.

### Queue pickup

```sql
SELECT survey_unit_sessions.id, …
FROM survey_unit_sessions
LEFT JOIN survey_run_sessions ON …
LEFT JOIN survey_runs ON …
WHERE queued >= :queued AND survey_runs.cron_active = 1
  AND expires <= NOW()
ORDER BY RAND();
```

`queued` semantics:

- `0` — not in queue. Either never queued (e.g. Email after `end()`),
  or the participant is in flight.
- `1` — `QUEUED_TO_EXECUTE`. Re-queued for retry (Pause/Branch
  check_failed; OpenCPU error; re-runs `executeUnitSession` via the
  fall-through branch instead of END-q).
- `2` — `QUEUED_TO_END`. Normal "wait until expires, then cron will
  process". The default for Pause/Survey-with-Z/etc.
- `-9` — `QUEUED_SUPERCEDED`. A sibling row with the same `unit_id`
  was created (back-jump iteration; `UnitSession::create` flips
  prior queued siblings to -9).

## Assumptions the current codebase relies on

These are not all written down anywhere. They emerge from reading.
The refactor needs to make them explicit (preserve where load-bearing,
discard where they have caused bugs).

A1. **Single cron worker.** `formr_run_daemon` is one container,
    one process. The cursor uses `ORDER BY RAND()` with no
    pagination, no `FOR UPDATE`. Two daemons would corrupt state.

A2. **Reentrant run-session lock.** `MariaDB GET_LOCK` on the
    same connection from the same name is reentrant; nested
    `execute()` calls from `moveOn()` succeed without blocking.

A3. **`survey_run_sessions.position` advances monotonically** in
    almost all cases, with the back-jump primitives (SkipBackward,
    Wait, runTo from Branch) explicitly resetting it.

A4. **Only one `unit-session` row per `(run_session, unit_id)`
    has `ended IS NULL AND expired IS NULL AND queued != -9`** at
    a time. Violating this — which only the Survey-rate-limit and
    bookmarked-URL re-entry paths historically did, and the
    position-race path discovered in v0.25.7 — produces ambiguous
    `getCurrentUnitSession` answers.

A5. **`UnitSession::create` is a transaction-bracketed atomic
    advance** (insert + supersede siblings + set
    `current_unit_session_id`). Any rollback returns control to
    the caller without partial state.

A6. **`MariaDB autocommit` is on for everything outside of explicit
    `beginTransaction`.** Each `end()`, `expire()`, `save()` UPDATE
    is its own committed write. No multi-row atomicity unless a
    transaction is open. In particular, `moveOn()`'s position-UPDATE
    + createUnitSession + cascade is a sequence of separately-
    committed writes; partial failure leaves partial state.

A7. **`isExecutedByCron()` returns `runSession->user->cron`, and
    `User::$cron` is never set to true anywhere in the codebase.**
    The `Email::cron_only` gate therefore always treats every
    request as user-driven. This is a latent bug; `cron_only=true`
    emails are still sent from cron paths because the `!isCron`
    test fires on web AND cron. Tests in v0.25.6 confirmed this
    incidentally.

A8. **The `survey_email_log` queue is single-worker
    (`formr_mail_daemon`).** It does NOT use `FOR UPDATE`. Multi-
    worker would double-send.

A9. **PushMessage has no separate queue.** Each unit-session's
    `getUnitSessionOutput` calls `PushNotificationService::sendPushMessage`
    inline. No retry. No idempotency at the row level pre-v0.25.7.

A10. **`expires <= NOW()` is the only queue-readiness signal.** No
     priority, no SLAs, no separate "ready" flag. Pause and
     Survey-with-Z both write `expires` to the same column and
     compete for the same cron pickup.

A11. **`survey_unit_sessions.id ORDER BY DESC LIMIT 1`** is the
     "latest sibling" tie-breaker. This is fine because rows are
     append-only, but it means `getCurrentUnitSession`'s answer
     can shift mid-cascade (a fresh `create()` in cascade #N
     becomes the new "current" for cascade #N+1's queries).

A12. **Cron-active-but-paused runs never appear in cursor** because
     `survey_runs.cron_active = 1` is a hard filter. Toggling
     cron_active off freezes the run for the queue but leaves
     queued rows lingering.

## Race conditions inventory

R1. **Position-race (FIXED v0.25.7).** Two web requests' RunSession
    constructors load `position` before either acquires the lock.
    Second-to-acquire drives `moveOn` from cached stale position
    → duplicate downstream cascades. Fix: `reloadFromDb()` after
    `acquireLock`.

R2. **Stale-reference cascade (FIXED v0.25.6).** Cron's cursor
    pickup of a queued sibling whose run-session has advanced past
    it. Pre-v0.25.6 the line-247 branch called `moveOn()` and
    cascaded again. Fix: `removeItem` only.

R3. **Supersede-orphan blanket scope (FIXED v0.25.6).**
    `UnitSession::create` flipped EVERY queued sibling to -9, not
    just same-`unit_id`. Fix: WHERE clause scoped.

R4. **Email/Push double-send via re-execute (FIXED v0.25.7).** No
    row-level idempotency. Fix: bail when `result` is terminal.

R5. **Daemon kill mid-cascade.** SIGKILL between Email row create
    and end() leaves Email in `(created, ended IS NULL, queued=0,
    result=NULL)`. The cascade is half-done. On restart, no
    cursor pickup (queued=0); the orphan stalls until the next web
    request advances position. **Not yet addressed.** A user-driven
    visit at position 127 would re-execute Email (idempotency
    guard catches if `result` already set; but `result IS NULL`
    means re-attempt — could double-send if email_log already has a
    queueNow row). Mitigation: end() inside the same transaction
    as create() for cascade-internal units (Email/Push). See §6.

R6. **Multi-process daemon.** Today blocked by single-container
    deployment. If anyone scales `formr_run_daemon` to >1 replica,
    cursor races explode (no `FOR UPDATE`). Mitigated only by
    convention. **Not yet addressed.**

R7. **Cron tick + user request lock contention.** Cron's 0.1 s
    timeout means cron always loses to user requests. If user
    requests dominate (high traffic), cron can starve. Today cron
    is single-process; if a participant holds the lock for a slow
    Survey render (OpenCPU evaluation, large body parse, network),
    cron skips them and tries again 15 s later. Acceptable but
    wastes daemon ticks.

R8. **`current_unit_session_id` vs derived current.** Two sources
    of truth. `RunSession::execute`'s ended-branch line 207 uses
    `current_unit_session_id` directly; everywhere else uses
    `getCurrentUnitSession()` which derives from `position`. They
    can disagree mid-cascade. Hasn't bitten in prod that we know
    of; latent.

R9. **`mail_daemon` retry on PHPMailer failure** (EmailQueue.php:254)
    can deliver twice when SMTP transport reports failure but the
    relay actually accepted. Not a code bug, an SMTP-protocol
    quirk. Mitigation: idempotency at the SMTP-relay level (the
    relay should dedupe on Message-ID), or by tracking
    `survey_email_log.status` more granularly.

R10. **Survey expires recomputed on every render.** A
     `survey_unit_sessions.expires` value is not stable across
     renders — `Survey::queue()` calls `addItem` which UPDATEs
     expires every time the participant POSTs/GETs. The `last_active`
     sliding deadline is implemented this way. Means:
     `window.unit_session_expires` shown to the client at render
     N can be wrong by render N+1. Caught by the v0.25.6 J5 test.

R11. **MariaDB connection drop during cascade.** GET_LOCK auto-
     releases when the connection dies. If the daemon's connection
     drops mid-`moveOn`, lock releases, partial state remains. A
     fresh request can acquire and re-cascade. The v0.25.7 fix
     reduces blast radius (idempotency + position-recheck) but
     doesn't eliminate.

R12. **`processQueue` cursor staleness.** Cursor's snapshot is
     taken at SELECT time; rows may have been ENDED by another
     process during iteration. The cursor still hands them to
     `runSession.execute()`; the lock + `getCurrentUnitSession`
     re-read catches it. Wastes work but doesn't corrupt.

## Proposed architecture

### New tables

```sql
-- One row per execution attempt of a (run_session, unit) pair.
-- Replaces survey_unit_sessions for the participant-visible state
-- (history + active pointer). Append-only after creation; state
-- transitions are explicit in the `state` column with timestamps.
CREATE TABLE unit_executions (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    run_session_id  INT UNSIGNED NOT NULL,
    unit_id         INT UNSIGNED NOT NULL,
    iteration       INT UNSIGNED NOT NULL DEFAULT 1,    -- back-jump iteration
    state           ENUM('PENDING','RUNNING','WAITING_USER','WAITING_TIMER',
                         'ENDED','EXPIRED','SUPERSEDED') NOT NULL DEFAULT 'PENDING',
    state_reason    VARCHAR(64) NULL,         -- e.g. 'survey_ended', 'pause_ended'
    state_log       TEXT NULL,                -- prior result_log
    created_at      DATETIME(3) NOT NULL,
    started_at      DATETIME(3) NULL,
    ended_at        DATETIME(3) NULL,         -- set on ENDED/EXPIRED/SUPERSEDED
    waiting_until   DATETIME(3) NULL,         -- replaces `expires` for WAITING_TIMER
    superseded_by   BIGINT UNSIGNED NULL,     -- FK to unit_executions.id; replaces queued=-9
    UNIQUE KEY (run_session_id, unit_id, iteration),
    KEY (state, waiting_until),               -- queue-side lookups (see below)
    KEY (run_session_id, state)               -- "current" lookup
);

-- Job queue for async work (cron-driven cascades, expiry checks,
-- email send, push send). Each job is one unit of work for a worker.
CREATE TABLE work_items (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    kind            ENUM('expire_check','cascade_advance',
                         'send_email','send_push','external_callback') NOT NULL,
    target_id       BIGINT UNSIGNED NOT NULL,             -- unit_executions.id (usually)
    payload         JSON NULL,                            -- per-kind extras
    available_at    DATETIME(3) NOT NULL,                 -- "ready when wallclock >= this"
    claimed_at      DATETIME(3) NULL,                     -- non-null while a worker holds it
    claimed_by      VARCHAR(64) NULL,                     -- worker id
    attempt_count   INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts    INT UNSIGNED NOT NULL DEFAULT 3,
    last_error      TEXT NULL,
    completed_at    DATETIME(3) NULL,
    failed_at       DATETIME(3) NULL,
    idempotency_key VARCHAR(128) NULL UNIQUE,             -- per-kind dedup
    KEY (available_at, claimed_at),                       -- worker pickup
    KEY (target_id)
);

-- Decoupled email send queue (unchanged in spirit; the existing
-- survey_email_log already does this). Add status='sending' for
-- mid-flight visibility.
ALTER TABLE survey_email_log
    ADD COLUMN claimed_at DATETIME(3) NULL,
    ADD COLUMN claimed_by VARCHAR(64) NULL,
    ADD KEY (status, claimed_at);

-- Push has no current queue; mirror the email model.
CREATE TABLE push_log (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    unit_execution_id BIGINT UNSIGNED NOT NULL,
    subscription_id INT UNSIGNED NOT NULL,
    payload         JSON NOT NULL,
    status          ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
    claimed_at      DATETIME(3) NULL,
    claimed_by      VARCHAR(64) NULL,
    created_at      DATETIME(3) NOT NULL,
    sent_at         DATETIME(3) NULL,
    failed_at       DATETIME(3) NULL,
    last_error      TEXT NULL,
    KEY (status, claimed_at)
);
```

`survey_unit_sessions` stays during the migration as the source-of-
truth for the legacy queue, gradually demoted to a read-replica view
of `unit_executions` (see §11). Eventually dropped.

### State machine

```
PENDING ──create()──► WAITING_USER  (Survey, Page, External)
                  └─► WAITING_TIMER (Pause, Wait)
                  └─► RUNNING       (Email, PushMessage, SkipForward,
                                     SkipBackward, Shuffle, Branch)
                                     synchronous; transitions to
                                     ENDED in same transaction

WAITING_USER  ──participant submits final page─► ENDED
              ──X+Y deadline hits──────────────► EXPIRED
              ──admin forceTo / supersede─────► SUPERSEDED

WAITING_TIMER ──waiting_until ≤ NOW────────────► ENDED   (Pause)
              ──admin forceTo / supersede─────► SUPERSEDED

RUNNING       ──unit completes synchronously──► ENDED
              ──unit returns deferred (Email
                queued, Push queued)─────────► ENDED (with side-job
                                                in work_items)
              ──worker fails permanently────► EXPIRED  (rare)

(All terminal states ENDED / EXPIRED / SUPERSEDED never transition
out. Terminal write is atomic.)
```

Transitions are validated by a dispatcher class
`UnitExecution::transition(toState, reason, log)`. Database CHECK
constraint enforces the state-machine grammar.

### Worker model

`bin/queue.php` rewritten as a generic worker that claims one job
from `work_items` with:

```sql
SELECT id, kind, target_id, payload
FROM work_items
WHERE claimed_at IS NULL
  AND completed_at IS NULL
  AND failed_at IS NULL
  AND available_at <= NOW(3)
ORDER BY available_at, id
LIMIT 1
FOR UPDATE SKIP LOCKED;

-- claim
UPDATE work_items SET claimed_at = NOW(3), claimed_by = :wid
WHERE id = :id;
```

`FOR UPDATE SKIP LOCKED` is MariaDB 10.6+. We're on 10.11+ in prod
per `docker-compose-prod.yml`. Multi-worker safe by construction.

After processing:

```sql
UPDATE work_items SET completed_at = NOW(3), claimed_at = NULL
WHERE id = :id;
```

On retryable failure: increment `attempt_count`, set `available_at`
to NOW + backoff, clear `claimed_at`. On permanent failure: set
`failed_at`. Idempotency_key on insert means re-enqueuing the same
work doesn't double up.

Three worker pools (or one pool dispatching by `kind`):

- `cascade-worker` — runs cascade_advance / expire_check.
- `mail-worker` — drains survey_email_log and runs send_email.
- `push-worker` — drains push_log and runs send_push.

Each is a docker service; scale via `docker-compose up --scale`.

## D2 diagram — current architecture

Save as `tests/refactor_queue_current.d2` and render with `d2`:

```d2
direction: right

participant: "Web request" {shape: person}
cron: "formr_run_daemon\n(single process)" {shape: hexagon}

run_session: "RunSession::execute()" {
  shape: rectangle
  style.fill: "#fef3c7"
  acquire_lock: "GET_LOCK\nrun_session_<id>\nweb=10s, cron=0.1s" {shape: cloud}
  reload: "reloadFromDb()\n(v0.25.7)" {shape: cloud}
  current: "getCurrentUnitSession()\nORDER BY id DESC" {shape: cloud}
  dispatch: "3-way dispatch\non (ref, current)" {shape: diamond}
  acquire_lock -> reload -> current -> dispatch
}

end_q: "END-q branch:\nendCurrentUnitSession()\n+ moveOn()" {shape: rectangle}
stale: "stale-reference branch:\nremoveItem()\nreturn body=''" {shape: rectangle}
active: "currently-active:\nexecuteUnitSession()" {shape: rectangle}

run_session.dispatch -> end_q: "ref==current\n!executeRef"
run_session.dispatch -> stale: "ref!=current"
run_session.dispatch -> active: "no-ref OR\nexecuteRef=true"

unit_session_table: "survey_unit_sessions\n(history + queue + active)" {
  shape: cylinder
  style.fill: "#fee2e2"
}

unit_session_table.queued_2: "queued=2 (waiting cron)"
unit_session_table.queued_1: "queued=1 (retry / executeRef)"
unit_session_table.queued_0: "queued=0 (in-flight participant\nor terminal)"
unit_session_table.queued_minus9: "queued=-9 (superseded sibling)"

participant -> run_session: HTTP GET/POST
cron -> run_session: cursor pickup\nWHERE queued > 0\nAND expires <= NOW()
unit_session_table -> cron: ORDER BY RAND()

active -> unit_execute: "calls"
unit_execute: "RunUnit::getUnitSessionOutput()\nper unit type" {
  shape: rectangle
  style.fill: "#dbeafe"
  survey: "Survey: render form / processStudy\nreturns content / end_session+move_on"
  pause: "Pause: getUnitSessionExpirationData\nreturns content + queue:{expires, queued=2}\nor expired+end_session+move_on"
  email: "Email: sendMail (queueNow → mail_log\nor sendNow inline)\nreturns end_session+move_on"
  push: "PushMessage: sendPushMessage\nreturns move_on (no end_session!)"
  external: "External: redirect URL"
  page: "Page (Endpage): render"
  branch: "SkipForward/Backward/Shuffle:\nrun_to=position"
}

end_q -> moveOn
active -> moveOn: "result has\nmove_on / end_session"
moveOn: "RunSession::moveOn()" {
  shape: rectangle
  style.fill: "#fef3c7"
  advance: "position++"
  create: "createUnitSession(nextUnit)\n— transactional INSERT\n— supersede same-unit_id siblings\n  to queued=-9"
  recurse: "execute() recursive\n(reentrant lock)"
  advance -> create -> recurse
}
moveOn.recurse -> run_session: "loop while move_on"

mail_daemon: "formr_mail_daemon\n(separate process)" {shape: hexagon}
email_log: "survey_email_log" {shape: cylinder}
unit_execute.email -> email_log: queueNow INSERT
mail_daemon -> email_log: SELECT WHERE status=0\n(no FOR UPDATE)
mail_daemon -> smtp: "PHPMailer\n(retry once on failure)" {shape: cloud}
smtp -> recipient: SMTP

push_provider: "Apple/FCM push" {shape: cloud}
unit_execute.push -> push_provider: "inline\n(no queue, no retry)"

# Race annotations
race_R1: "R1: position-race\n(FIXED v0.25.7)" {style.fill: "#10b981"}
race_R5: "R5: daemon kill\nmid-cascade\n(open)" {style.fill: "#ef4444"}
race_R6: "R6: multi-worker\n(blocked by convention)" {style.fill: "#ef4444"}
race_R7: "R7: lock contention\nuser vs cron\n(starves cron)" {style.fill: "#f59e0b"}

run_session.acquire_lock -> race_R7
moveOn.recurse -> race_R5
cron -> race_R6
```

## D2 diagram — proposed architecture

Save as `tests/refactor_queue_proposed.d2`:

```d2
direction: right

participant: "Web request" {shape: person}

# Web request path: read-mostly, only commits when participant
# submits a final page.
web: "WebDispatcher::handle()" {
  shape: rectangle
  style.fill: "#dbeafe"
  read_only: "Read run-session pointer\n(no write before lock)" {shape: cloud}
  lock: "GET_LOCK\nrun_session_<id>" {shape: cloud}
  load: "Load UnitExecution\nFROM unit_executions WHERE id =\nrun_sessions.current_unit_execution_id" {shape: cloud}
  state_machine: "Dispatch on state" {shape: diamond}
  read_only -> lock -> load -> state_machine
}

worker_pool: "Worker pool\n(N replicas, claim via\nFOR UPDATE SKIP LOCKED)" {
  shape: hexagon
  cascade: "cascade-worker\n(handles expire_check\n+ cascade_advance)"
  mail: "mail-worker"
  push: "push-worker"
}

work_items: "work_items\n(jobs, transient)" {
  shape: cylinder
  style.fill: "#dcfce7"
}

unit_executions: "unit_executions\n(state machine, append-only)" {
  shape: cylinder
  style.fill: "#dcfce7"
  state_pending: "PENDING"
  state_running: "RUNNING"
  state_waiting_user: "WAITING_USER"
  state_waiting_timer: "WAITING_TIMER"
  state_ended: "ENDED (terminal)"
  state_expired: "EXPIRED (terminal)"
  state_superseded: "SUPERSEDED (terminal)"
}

run_session_pointer: "survey_run_sessions\n.current_unit_execution_id\n(single source of truth)" {shape: cylinder}

participant -> web: HTTP
web -> unit_executions: read state
web -> run_session_pointer: read pointer
web -> work_items: enqueue cascade_advance\non state-machine transition

worker_pool.cascade -> work_items: "FOR UPDATE SKIP LOCKED\nLIMIT 1"
worker_pool.cascade -> dispatcher
dispatcher: "CascadeDispatcher" {
  shape: rectangle
  style.fill: "#fef3c7"
  by_kind: "Dispatch by\nUnit type + state" {shape: diamond}
  survey_handler: "Survey handler"
  pause_handler: "Pause handler"
  email_handler: "Email handler"
  push_handler: "Push handler"
  branch_handler: "Branch / Skip handler"
  by_kind -> survey_handler
  by_kind -> pause_handler
  by_kind -> email_handler
  by_kind -> push_handler
  by_kind -> branch_handler
}

dispatcher.email_handler -> work_items: "enqueue send_email\n(idempotency_key=\nunit_exec.id+attempt)"
dispatcher.push_handler -> work_items: "enqueue send_push\n(idempotency_key=\nunit_exec.id+attempt)"

worker_pool.mail -> survey_email_log: "FOR UPDATE SKIP LOCKED"
worker_pool.push -> push_log: "FOR UPDATE SKIP LOCKED"

# Atomic cascade: each unit transition is one TX
tx_box: "Transaction boundary\n(single TX per state transition)" {
  shape: rectangle
  style.fill: "#fee2e2"
  start: "BEGIN"
  load_lock: "SELECT unit_executions ... FOR UPDATE"
  call_handler: "handler.transition()"
  insert_next: "INSERT next unit_execution if cascade"
  enqueue_jobs: "INSERT work_items for async work"
  commit: "COMMIT"
  start -> load_lock -> call_handler -> insert_next -> enqueue_jobs -> commit
}

dispatcher -> tx_box

idempotency: "Idempotency contract" {
  shape: rectangle
  style.fill: "#dcfce7"
  rule_1: "Each unit_execution row\nis processed AT MOST ONCE"
  rule_2: "Each work_item.idempotency_key\nis unique"
  rule_3: "send_email/send_push handlers\nbail if email_log/push_log\nrow is already 'sent'"
}

worker_pool -> idempotency
```

(Both diagrams are checked into `tests/` next to this plan; render
with `d2 tests/refactor_queue_proposed.d2 -o tests/refactor_queue_proposed.svg`
when needed for review.)

## Migration plan

### Phase 0 — telemetry baseline (1 day)

Before touching anything, instrument the existing code to count
race-condition occurrences in prod. The position-race fix in
v0.25.7 lets us verify it's actually closed and gives a baseline
against which to measure the refactor's improvements. New `error_log`
calls when:

- `getCurrentUnitSession` returns null at a non-first position
  (suggests cascade gap).
- `endCurrentUnitSession()` returns false (race with another path
  ending first).
- `moveOn` fires while `position` differs from
  `survey_run_sessions.position` (would catch any residual
  position-race).
- `survey_email_log.status` was 1 when a fresh send was attempted
  for the same `session_id` (would catch idempotency-bypass paths).

Ship as v0.25.8 hotfix. Watch for a week.

### Phase 1 — introduce `unit_executions` as a shadow read-replica (1–2 weeks)

- Create `unit_executions` table.
- Triggers (or app-side dual-writes) populate `unit_executions` on
  every `INSERT`/`UPDATE` of `survey_unit_sessions`. Keep them in
  sync — `survey_unit_sessions` remains source of truth.
- Read paths can opt-in to `unit_executions` via a feature flag
  (`Config::get('use_unit_executions')`).
- Verification: count rows in both tables match, state derivations
  agree.

Ship as v0.26.0 (bumping minor for new table) — still backwards-
compatible, no behaviour change.

### Phase 2 — introduce `work_items` for new work (2–3 weeks)

- Create `work_items` table.
- Migrate Email send queue: existing `survey_email_log` drains via
  `mail-worker` reading from `survey_email_log` (unchanged); new
  enqueues go through `work_items` with a `send_email` job
  inserting a `survey_email_log` row in the worker.
- Add `push-worker` and `push_log` table.
- Toggle behind a feature flag.

Ship as v0.27.0 (push queue is observable change to ops).

### Phase 3 — refactor RunSession::execute into a state-machine dispatcher (2–4 weeks)

- New class `RunSessionDispatcher` wrapping the lock + reload +
  state-machine logic.
- `RunSession::execute` becomes a thin shim that delegates to the
  dispatcher when the feature flag is on.
- Unit-type handlers (`SurveyHandler`, `PauseHandler`, …) extracted
  from `RunUnit::execute()` via a sister method
  `RunUnit::transition($execution)` returning the next state +
  side-jobs.
- Dispatcher writes to `unit_executions` (which dual-writes to
  `survey_unit_sessions` for the legacy queue's benefit).
- Cron daemon runs against `work_items` exclusively.

Ship as v0.28.0. Feature-flag rollout: enable per run, then per
host. e2e suite must pass under both legacy and dispatcher modes
during the rollout window.

### Phase 4 — cut over the queue (1 week)

- All cron-side reads come from `work_items`. `survey_unit_sessions`
  stops being a queue (it stays a history table).
- `queued` column deprecated; existing values stay for audit.
- Dual-write triggers removed.

Ship as v1.0.0 — major version bump. The deprecated `queued`
column stays for at least one minor version (warn-on-read in admin
SQL inspector).

### Phase 5 — drop legacy (1 release later)

- Drop `survey_unit_sessions.queued` column.
- Drop dual-write triggers.
- `survey_unit_sessions` becomes a denormalised history view (or
  is dropped entirely in favour of `unit_executions`).

Ship as v1.1.0 once at least 90 days have passed without any
hosts running pre-v1.0.

## Rollout / deployment story

- Per-host feature flag in `config/settings.php`:
  `use_unit_executions = false` initially. Hosts opt-in.
- Per-run cron-active toggle to drain work for a single run before
  cutover.
- Multi-worker default in `docker-compose-prod.yml`:
  `formr_run_daemon` becomes `formr_cascade_worker` with
  `deploy.replicas: 3`.
- Migration scripts (Atlas) are forward-only; the rollback story
  is "stay on v0.x for that host until v1.0 is debugged on
  others". Document this loudly.

## Risks

- **Trigger-driven dual-write desync.** If a write to
  `survey_unit_sessions` slips a trigger (mass-update from admin
  tool, manual SQL repair), `unit_executions` falls behind. Mitigation:
  weekly consistency check via SQL diff query, alarm on diff > 0.

- **Atlas migration runs out of order on hosts pinned to different
  `FORMR_TAG`s.** The CLAUDE.md notes `mysql/atlas/migrations/` is
  per-host. New tables get migration numbers reserved early; document
  ordering carefully.

- **`FOR UPDATE SKIP LOCKED` requires MariaDB 10.6+.** Confirmed
  in current `docker-compose-prod.yml` (10.11). If anyone is on
  pre-10.6 they need to upgrade before Phase 2.

- **Worker pool replaces a process the operations docs reference
  by name.** All runbooks mentioning `formr_run_daemon` need
  updating in lockstep with Phase 4. Add a deprecation alias
  (`docker compose up formr_run_daemon` resolves to the new
  cascade-worker) for the v0.x → v1.0 cutover window.

- **Behaviour-preservation regression test surface is enormous.**
  The refactor must not change observable behaviour for
  participants. Mitigation: every existing e2e spec runs under
  both legacy and dispatcher modes via a config-flag test
  parameterisation.

- **Deferred-execute idempotency contract is new.** Email/Push
  handlers must not double-send. Need explicit unit tests + an
  end-to-end test that kills the worker mid-send and confirms the
  retry doesn't double-deliver.

## Testing strategy

The v0.25.6 + v0.25.7 e2e suite (`tests/e2e/{survey-symptoms,survey-
expiry-matrix,survey-unfinished-pathways,survey-expiry-ui,double-
expiry}.spec.js`) is the regression baseline. Every spec must pass
under both `use_unit_executions = false` and `= true` during Phases
1–3. CI matrix: two runs per change.

New tests added during the refactor:

- **Worker idempotency.** `work_items.idempotency_key` collision
  test — enqueue twice, assert one job runs.
- **Multi-worker race.** Spawn 3 workers, enqueue 1000 jobs, assert
  each runs exactly once. Uses Playwright + a Node script that
  triggers worker spawns via `docker compose run --rm`.
- **State-machine transitions.** Each illegal transition (e.g.
  ENDED → RUNNING) must throw / be rejected by the DB CHECK.
- **Cascade interrupt recovery.** Kill the worker mid-cascade, restart,
  assert the cascade resumes from the last committed transition.
- **Crash-only test for Email/Push.** SIGKILL the mail worker
  mid-`sendMail`; assert `survey_email_log` gets retried but the
  recipient SMTP only sees one message.

## Open questions

1. Do we want per-run-author observability (a "queue health"
   admin page showing pending/claimed/failed work_items)? The
   v0.25.6-era `templates/admin/run/sessions_queue.php` is the
   precedent. Likely yes, but not a Phase-1 deliverable.
2. Should `work_items` be partitioned by `kind`? Single-table is
   simpler; per-kind tables avoid cross-kind contention. For our
   scale (single-digit thousands of participants) one table is
   fine; revisit if we hit five-figure participant cohorts.
3. Should the `iteration` field on `unit_executions` be exposed in
   the data export? Today users see "rows per session_id" with
   the back-jump iterations represented as separate rows but no
   explicit iteration counter. Adding one is a backwards-compatible
   improvement.
4. R9 (PHPMailer retry double-delivery) is upstream; out of scope.
   Document and live with it.

## Effort estimate

- Phase 0: 1–2 days (hotfix + watch).
- Phase 1: 5–10 days (table + triggers + verification).
- Phase 2: 10–15 days (work_items + migrate email + add push queue).
- Phase 3: 15–25 days (the actual dispatcher rewrite + handler
  extraction).
- Phase 4: 5 days (cutover, runbook updates).
- Phase 5: 2 days (drop legacy).

Total: ~6–10 weeks of focused work for a single engineer. Could be
parallelised across phases 1+2 (independent) and 3+4 (sequential).

## Decision needed before starting

This document IS a decision artefact. Approval gates:

- Are we OK with the major-version cutover? (yes = proceed; no =
  scope to Phase 0–2 only.)
- Multi-worker deployment OK with the operations team? (yes =
  Phase 2 design as written; no = single-worker default with
  scale-up flag.)
- MariaDB 10.6+ confirmed across all production hosts? (yes =
  proceed; no = bump that first.)

If any answer is "no", scope drops to a Phase-0/1 hardening pass:
keep `survey_unit_sessions` as the queue, add `idempotency_key`,
add explicit `state` enum, drop `queued` magic numbers in favour
of named constants, add structured `state_log`. That's a minor
version bump (v0.26.0) and ~2 weeks of work — still useful, but
doesn't unlock multi-worker.
