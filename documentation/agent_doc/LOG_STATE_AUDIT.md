# Terminal-state and log-loss audit (v1.8.0)

One-per-unit audit of every `RunUnit` subclass for a single defect class:
**`result`, `result_log` and `state_log` being overwritten or silently
lost.** Prompted by the `Branch` bug fixed in `25cbcc53`, where warnings
were assigned to `$data['log']` and then overwritten wholesale by every
terminal branch, so they never reached the row at all.

Findings are per-unit below. Read *The five mechanisms* first — almost
every finding is an instance of one of them, and fixing the mechanisms is
worth more than fixing the instances.

## The five mechanisms

**M1 — `$data['log']` is a single slot, last write wins.**
A unit that assigns a log and then reassigns it before returning loses the
first message; only the final value reaches `logOutput()`. This is the
canonical shape. Fixed in `Branch` (`joinNotes()`), still present in
`External` (api_end overwrites the redirect log) and `Email` (the
rate-limit warning in `$this->messages` is read only on the success path).

**M2 — `UnitSession::end()` re-derives `result` from the unit TYPE.**
`UnitSession.php:563-585`. When no `$reason` is passed, Survey, External,
Pause, Wait and Endpage have whatever the unit's own log recorded replaced
by a type default. Email, PushMessage, Shuffle and Branch/Skip fall into
the empty `else` and keep theirs. `result_log` is *not* clobbered here —
`end()` re-writes the in-memory value — so the text survives and the label
does not, which produces rows whose label and log disagree.

**M3 — `logResult()` writes wholesale, never merges.**
`UnitSession.php:653-671`. Any pass that logs replaces `result_log` and
rebuilds `state_log` with a fresh `at`. Two consequences, both confirmed
on every retrying unit: the *first* failure text (usually the actionable
one) is replaced by the last, and there is no record of when a failure
started. A successful recovery pass writes `result_log = NULL`, erasing
the evidence entirely — the normal recovery path deletes its own history.

**M4 — `AND ended IS NULL` silently discards post-terminal writes.**
`logResult()`, `end()` and `expire()` all carry it, and every call site
ignores the return value. Correct as immutability, but it means a log
written after the row ended vanishes with no signal. Two confirmed
victims: `RunSession::forceTo()` (below) and a re-knit failure on an
ended Endpage.

**M5 — `state` drifts away from `result`.**
`logResult()` never writes `state` and `stateForQueuedUnit()` derives it
from the unit type alone, so rows routinely carry a `state` that
contradicts their `result`. Compounding it, **`iteration` and `state`
appear in no admin template at all**.

*Resolved in part (v1.8.0):* the `state_log` JSON column is gone — patch
073. It had 8 writers and no production reader, and every field in it
duplicated the row (`reason` derived from `result`, `ctx.unit_type` from
the unit, `ctx.msg` a copy of `result_log`, `at` rewritten each pass). It
had also misled the reverted streak-marker work into storing cross-pass
state in a column every pass overwrites. `state` (the ENUM) is kept — it
has real readers — and the effort moved to `result` / `result_log`.

## Cross-cutting defects (not owned by any one unit)

**X1 — `RunSession::forceTo()` can never record its reason.**
`RunSession.php:587-591` calls `end()` (which sets `ended = NOW()`), then
assigns `result = 'manual_admin_push'` and calls `logResult()`, whose
`ended IS NULL` guard matches zero rows. The label has never existed in
the database, for any unit type. Found independently by the Survey,
Shuffle and SkipBackward agents.

*Severity is type-dependent.* For a **Survey** it is data corruption:
`end()` also stamps `results_table.ended = NOW()` and fires
`StudyMetrics::recordCompletion()`, so an admin "send to position" on a
participant parked mid-survey manufactures a row indistinguishable from a
real completion and increments the study's `finished` count and
geometric-mean duration. `RunSession::positionSessions()` does this in
bulk. The rollup is an accumulator, so the skew is permanent, and
`ended IS NOT NULL` is the standard completion filter in analysis.

*The obvious fix is a trap.* `end('manual_admin_push')` makes the label
land — and for a **Shuffle** that would replace `result='group_2'`,
destroying the API's only copy of the group assignment (see X2). Fix X2
first, or exempt units whose `result` is load-bearing.

**X2 — the API and the admin read group assignment from different columns.**
`ApiBase.php:528` selects `sus.result AS group`, so the v1 API returns the
string `"group_2"`; the admin randomisation page and its CSV/XLSX export
read the authoritative `shuffle`.`group`, an integer. Two value spaces,
nothing keeping them in sync, and the API's source is the
wholesale-overwrite column. Anything that writes a reason into `result`
before the participant returns (`forceMoveOn('moved')`, reachable from the
admin "next in run" button) makes the API report `group: "moved"` for a
participant whose R still reads `2`.

**X3 — `opencpu_last_error()` reports the wrong error after a knit failure.**
Two independent mechanisms on one line (`Page.php:137`): the
`$GLOBALS['opencpu_local_error']` flag is cleared only by
`opencpu_evaluate()`, so a stale "expression is empty" from an earlier
Pause/Branch is reported as the Page's failure; and when the knit throws
no session is constructed, so the fallback reads the *previous* session,
whose `hasError()` consults the OpenCPU singleton's `curl_info` — which
the failed knit just overwrote. After a 502 it returns the body of an
earlier successful call. With no prior call, the admin email ends
`"...knitting page content. "` with no detail at all.

**X4 — the second `notify_study_admin()` is always throttled away.**
`Page.php:138` fires an `error` notification microseconds after
`getParsedBody()` already fired one; `Notification::canBeSent` throttles
per run + recipient + type at 10 minutes, so the second is dropped. Any
other `error` notification in that run within 10 minutes also suppresses
it — leaving several of the losses below with no channel at all.

## Per-unit findings

Severity: **P** participant-visible · **D** data/analysis integrity ·
**A** admin-diagnostic only.

### Survey — not clean
- **D** `forceTo()` manufactures false completions (X1). Bulk via
  `positionSessions()`. Permanent skew to `StudyMetrics`.
- **A** Expiry records no reason: `getUnitSessionExpirationData()` returns
  no `log` key at all, and `expire()` writes no `result_log`. The X/Y/Z
  matrix computes *which* rule fired (never opened / hard cap / inactive)
  and discards it. The stale `result_log` from an earlier pass survives
  next to `result='expired'`, so the admin reads an "expired" event
  annotated with an unrelated DB error.
- **A** `survey_completed` is write-only — always replaced by `end()`'s
  `survey_ended` (M2). This is *why* the false completions are invisible:
  Survey has no way to say "this end was a real completion".
- **A** Four failed-POST paths persist nothing: rolled-back save, stale-form
  POST, terminal-session POST, `post_max_size` exceeded. The participant's
  answers are discarded with no row-level trace.
- Latent: the `$ignore_post` block (`Survey.php:300-306`) is dead code from
  the v0.25.1 CSRF removal — `$ignore_post` is passed by value and set
  nowhere. If revived it would be a textbook M1.

### Pause — not clean
- **A** *Every* Pause R error is overwritten inside the same request.
  `isExpired()` genuinely persists `error_pause_relative_to`, then returns
  without a terminal flag, so `execute()` continues into
  `getUnitSessionOutput()` which returns `pause_waiting` with a null log
  and rewrites the row. `error_pause_relative_to` and
  `error_pause_wait_minutes` are **effectively unreachable as persisted
  values**. Holds on both the web and daemon paths.
- **A** `end()` stamps `pause_ended` over a stale `error_opencpu_r` log, so
  the row reads as though the pause ended *because of* an R error —
  misleading rather than merely lost.
- **A** Five distinct terminations collapse into one unlabelled
  `pause_ended`, including the operationally important pair "elapsed on
  time" vs "ended three days late because the daemon was down".
- **A** Bulk-expiry writers use `COALESCE(us.result, …)`, which never fires
  on a Pause because `pause_waiting` is always already there. Deleting a
  unit from under 200 parked participants expires every row labelled
  "waiting", with no `state_log` written.

### Email — not clean
- **P/D** `Email::test()` writes onto a **real participant's** row.
  `grabRandomSession()` has no `testing` filter; `queueNow()` stamps that
  participant's `session_id` onto the `survey_email_log` row; the daemon's
  `logResult()` (`EmailQueue.php:306`) then UPDATEs `survey_unit_sessions`
  with no `unit_id` and no `ended IS NULL` guard. An opted-out
  participant's row becomes `result='email_sent'`. If the row was still in
  its retry window, the run daemon's next pass matches the terminal-result
  allowlist and **advances the participant past an email never sent to
  them**. The same call also reports success when the idempotency key
  collides and no mail was sent.
- **A** `giveUp()` overwrites `result_log` wholesale (destroying the
  rate-limit warning that explains the failures), writes `result` with no
  `state_log`, and at default config can fire up to four times on one row —
  each time hardcoding "Gave up after 4 delivery attempts" when 1, 2 or 3
  were made, and each time claiming the participant never received it while
  the row is still retryable.
- **A** `logResult()` on success passes no `result_log`, so a late delivery
  NULLs the give-up note — erasing the evidence anything went wrong.
- **A** A genuine SMTP failure on the sync path records `error_email` with
  `result_log = NULL`: the error text goes only to `alert()`, a
  request-scoped bag that a daemon-driven execute discards entirely.
- **A** Permanent opt-out and active snooze share one `result`, though
  `no_email` holds the distinguishing timestamp.
- **A** `STATUS_INVALID_RECIPIENT` / `STATUS_INVALID_SUBJECT` are silently
  terminal — not in `pickupWhere()`, so they never reach `giveUp()` and the
  v1.8.0 "giving up is a visible act" guarantee does not cover them.

### PushMessage — not clean
- **P** A PushMessage with **no title never delivers**. The admin field
  reads "Optional title for message categorization", but an empty topic is
  falsy, `PushMessage.php:216` treats that as a parse failure, and the run
  advances. `PushNotificationService.php:102` already carries a
  `'Notification'` fallback that this guard makes unreachable. Same for any
  title/message knitting to `""` or `"0"`.
- **A** The idempotency-collision path — the daemon-killed-mid-send case,
  i.e. exactly "we tried, can't tell if it landed" — ends the row with
  `result`, `result_log` and `state_log` all NULL. `push_logs` says
  `queued`; the sweep can't repair the unit session because its query needs
  `ended IS NULL`. Two ledgers, and the authoritative one is blank.
- **A** Three early-exit branches pass their message only to
  `notify_study_admin()`, never onto the row, so transient OpenCPU failure,
  permanently broken template and knits-to-empty are one label.

### External — not clean
- **P** An **expired External still redirects** on the web path.
  `getUnitSessionExpirationData()` sets `expired` but no `expires`, so
  `isExpired()` returns false at its empty-`expires` guard and `execute()`
  continues; the unit's own expiry check is gated on `isExecutedByCron()`.
  `expire()` relabels the row and `isset($result['redirect'])` still fires.
  Narrow (needs cron inactive or a visit before the daemon's pass).
- **A** M1 verbatim: `external_wait_for_api` overwrites the redirect log.
  And **the destination URL is never recorded on any branch** — neither
  redirect branch passes a second argument to `getLogMessage()`, and the
  target is resolved a line later.
- **A** Four of five outcomes collapse into `external_ended` (M2).
- **A** `state` reads `WAITING_USER` on rows waiting on a retry timer.
- **A** Excluded from the F19 escalating backoff, so a permanently broken R
  address retries every 10 minutes forever, re-firing its side effects.

### Wait — the v1.8.0 fix works, but is not symmetric
- **P/A** A failed `run_to` jump cannot be recorded: `end()` freezes the row
  before `runTo()` runs (M4). A `body` naming a missing position, or left
  blank by the admin, routes the participant somewhere else while the row
  reads like a clean early jump.
- **A** The early-exit label is written twice — `getLogMessage()` at
  `Wait.php:112` and `end_reason` at `:128`. `end_reason` wins for both
  `result` and `state_log.reason`, so the first is dead on arrival and the
  two can silently diverge if either is edited alone.
- **A** Wait's own "elapsed" branch (`Wait.php:129-132`) is **unreachable**:
  `isExpired()` converts the elapsed deadline into `end_session` and
  `execute()` returns before `getUnitSessionOutput()` is called. `wait_ended`
  is produced solely by `end()`'s type default. So the apparent symmetry is
  illusory, and `tests/WaitEndReasonTest.php:61-71` pins a branch production
  cannot reach. Removing the type default as a cleanup would make elapsed
  Waits record `result = NULL`.
- **A** `getUnitSessionOutput()` calls `getUnitSessionExpirationData()` a
  *second* time and drops its `log` (M1) — total loss when the first call
  succeeds and the second fails, which for an authored `relative to` is two
  separate OpenCPU round-trips. Also double OpenCPU cost per pass.
- **A** Advancing a Wait that had been failing replaces the failure history
  with the request-shape line (M3).

### Page / Endpage — not clean
- **P/A** A re-knit failure on an **ended** run session is silently dropped
  (M4): the F2 ended-branch re-dispatches the last unit session on every
  reload, and `logResult()`'s `ended IS NULL` guard means 0 rows. A
  participant who bookmarked their feedback page sees an empty page forever
  while the row still reads `result='ended'`, `result_log=NULL`.
- **A** X3 and X4 both live here.
- **A** A successful retry NULLs `result_log`, permanently erasing the
  record that the endpage failed — generic (M3) but *terminal* for Page,
  since nothing writes that row again.
- **A** The cron pre-render records nothing; Page never reads
  `$this->messages` (compare `Email.php:522`). Endpage is also deliberately
  excluded from the stalled sweep, so a participant parked there is
  invisible to every recovery mechanism.

### Shuffle — clean on the core axis
The chosen group is written to the authoritative `shuffle` table, re-read so
a retry reports the stored value, and `end()` has no Shuffle arm so
`group_N` survives. Caveats: X2 above; `result_log` is always NULL; and
`survey_shuffles.groups` is mutated in place with no versioning, so an
author who changes 2 groups to 4 mid-study leaves historic rows with no
record of the cardinality they were drawn from.

### Branch / SkipForward / SkipBackward
`Branch` carries all the logic; the two skip files add no result/log surface
of their own. The `25cbcc53` fix is complete after `d6be93c6`, which closed
the dangling-`if_true` return and the nested-list fatal (a PHP 8 `TypeError`
that reached the participant as a 500 and crash-looped the run daemon,
because `Error` is caught by neither `RunSession::execute()`'s `finally` nor
`UnitSessionQueue::run()`'s `catch (Exception)`).

Remaining, all **A** and all M3: every backoff pass rewrites `result_log`
and `state_log` with a fresh `at`, so a skip broken for 20 minutes looks
like one broken for 3 weeks; and a later clean pass NULLs the failure
entirely, reverting the admin overview's error colouring (which keys on
`strpos($result, 'error')`). Two different faults — unevaluable condition
and missing `if_true` target — share `error_opencpu_r`, and the *same*
fault (a jump target with no unit) is `skip_to_invalid` + continue on the
computed path but `error_opencpu_r` + halt on the `if_true` path.

**SkipBackward only, P:** loop re-entry permanently disables the
failed-create repair. `placementHasAnyUnitSession()` filters on
`(run_session_id, unit_id, run_unit_id)` with no `iteration`, `ended` or
recency predicate — always true from pass 2 onward — so a failed `create()`
on a later iteration takes `moveOn()` instead of repairing, skipping the
unit with **no row written at all**. Its Test view is also inverted: the
`position >= $this->position` filter excludes exactly the participants
currently in the loop.

### Privacy — dead code
Not in `SupportedUnits`, so the factory throws; its admin template is gone
from the tree; and `survey_privacy`, which the class reads and writes,
exists in no schema file or patch and not on the dev DB. A legacy run still
containing one throws for the participant and aborts `importUnits()`
wholesale (no per-unit try/catch). Delete the class, or restore it properly
with a patch, a template, a factory entry and a decline-side log — as it
stands a refusal is recorded nowhere and an acceptance carries no policy
version.

## Suggested order of work

1. **X1 for Survey** — false completions are the only finding that reaches
   published numbers. Fix X2 first so the Shuffle trap is disarmed.
2. **`Email::test()`** (P/D) and **PushMessage empty title** (P) — both
   participant-affecting, both small and self-contained.
3. ~~**M2**~~ **Done (v1.8.0)** — `UnitSession::resolveEndResult()`:
   explicit reason → this pass's own log → type default, and `result_log`
   is cleared whenever the result did not come from this pass, so a label
   and its text always describe the same event. Wait's `end_reason` lever
   is now the general case. The type defaults stay as the fallback (Wait's
   elapsed branch is unreachable and depends on one). Pinned by
   `tests/EndResultPairingTest.php`.
4. **M3** — make recovery non-destructive: fold prior failure text forward
   (the `joinNotes()` shape) rather than NULLing it, and keep a
   first-failure timestamp somewhere `logResult()` does not own. This is the
   column the reverted streak-marker rework needed.
5. **M5 / admin visibility** — surface `iteration`, `state` and `state_log`
   in the user-detail view. Several findings above are only *invisible*
   rather than *lost*, and this is the cheapest way to recover them.
6. **Pause**, **External** and **Page** unit-level items.
7. Delete `Privacy`.

## Result coverage harness

`tests/fixtures/result_catalogue.php` inventories every value that can land
in `survey_unit_sessions.result` — 49 reachable plus 8 declared unreachable
or dead. Two consumers keep it honest:

- `tests/ResultCatalogueTest.php` (unit lane, no DB) scans the source and
  fails if the code grows a result the catalogue doesn't list, or if an
  entry no longer appears in the code.
- `bin/test_result_coverage_smoke.php` (live MariaDB) builds fixture runs,
  drives real `RunSession::execute()` traversals, and reads the resulting
  rows back — never the return value of `execute()`, since the whole defect
  class here is a result the code computes and the row never receives.

It also asserts the *negative*: `manual_admin_push` is driven deliberately
via `forceTo()` and the run fails if it ever persists, because the
catalogue declares it unreachable. That turns each unreachability claim in
this document into something a future change trips over.

Current state: **30/50 driven** by the PHP lane plus 4 more by the browser
lane, 0 unreachable results unexpectedly persisted. Covered end to end: all four skip outcomes plus its OpenCPU
error, `group_N`, both Pause outcomes, `wait_ended_by_user`, all five
External outcomes plus its OpenCPU error, `ended`, and `moved`.

Not yet driven, and why:

- **Survey** (4) needs a real `survey_studies` row with items and a results
  table; the fixture builder only creates unit definitions.
- **Email** (11) and **PushMessage** (5) need an email account and a push
  subscription respectively, and the `smtp` ones need a mail-daemon pass.
- **`expired`, `wait_ended`, `waiting_deprecated`, `ended_by_queue_rse`,
  `swept_stale`** need a real queue/sweep pass rather than a direct
  `execute()`.
- **`external_ended`, `run_structure_replaced`, `unit_removed_from_run`**
  are admin/callback paths with no scenario yet.

One trap worth recording for anyone extending it: `Model::boot()` sets
`cron = Site::runningInConsole()`, so **every** `execute()` driven from a
CLI script reports `isExecutedByCron() === true`. Wait's early-return,
External's `api_end` branch and Endpage's render are all gated on
`!isExecutedByCron()`, so without `asParticipant()` the harness silently
exercises the cron arm of every unit and reports the web-side results as
unreachable. `UnitSessionQueue::processQueue` does the same thing in
reverse, and for the same reason.


### Fixture notes (learned the hard way)

- `survey_studies.id` is a **foreign key onto `survey_units.id`** — a study
  *is* a unit, so the unit row must exist first and lend it its id.
- `survey_studies.id` and `survey_items_display.id` are **not**
  auto_increment; both need an explicit value.
- An Email inserted by raw SQL needs `body_parsed`, not just `body`:
  `getBody()` reads the parsed column, and `create()` is what normally fills
  it, so an unparsed body reads as empty and yields `no_email_body`.
- Pagination in a survey comes from **item order** — an item, a `submit`,
  then another item gives two pages. `page_no` is NULL either way and
  editing it by hand does not paginate anything.
- Playwright tests that mutate shared state (the `error_survey` test renames
  a results table) **must be serial**. In parallel the symptom is a
  `fill()` timing out on an input a standalone browser finds perfectly
  visible.
- A real push subscription cannot be earned in a test: headless Chromium has
  no push service, and Brave disables Google's by default
  (`brave://settings/privacy`), both yielding
  `AbortError: Registration failed - push service error`. A well-formed
  synthetic subscription row in `survey_items_display` exercises the same
  code paths.
