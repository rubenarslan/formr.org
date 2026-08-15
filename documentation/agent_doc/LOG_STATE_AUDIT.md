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


## Result coverage: the browser lane

**Tests drive formr the way admins and participants do.** Fixtures are built
through the formr MCP — that is the admin API — and traversed in a browser.
Nothing is driven from PHP, and nothing is fabricated with SQL.

The PHP harness that used to hold this coverage is gone. Its own shape was
the argument against it: it had to call `asParticipant()` to force
`cron = false`, because `Model::boot()` derives that flag from
`Site::runningInConsole()`. Wait's early return, External's `api_end` branch
and Endpage's render are all gated on `!isExecutedByCron()`, so a CLI harness
silently exercises the *cron* arm of every unit unless it fakes the very
thing it is meant to observe. A browser needs no such fake. It also carried
`makeContactAnswers()` (a study, its items and an answer row, all INSERTed)
and `fakeSubscription()` — each a compensation for not being in a browser.

### The pieces

| | |
|---|---|
| `tests/fixtures/result_catalogue.php` | Every value that can land in `survey_unit_sessions.result`: owning unit, how it is reached, and for the unreachable ones, why. The checklist. |
| `tests/ResultCatalogueTest.php` | Unit lane, no DB. Fails if the source grows a result the catalogue lacks, or an entry goes stale. It already earned its keep: it caught `survey_completed` becoming reachable when `resolveEndResult()` changed `end()`. |
| `tests/e2e/result-coverage-*.spec.js` | The lane. One file per family. |
| `tests/e2e/helpers/results.js` | Shared reading side: `sessionRows`/`sessionTrace` (newest run session, with the position each row ran at), `enrol`, `recordObserved`. |
| `tests/e2e/fixtures/rescov-*.json` | Every fixture run, exported through the admin's own **Export** route, so it can be rebuilt by **Import**. |
| `bin/result_coverage_report.php` | Reconciles what the specs observed against the catalogue. |

Each spec is its own process, so no spec can hold a running total. They
deposit what they produced (`recordObserved`) into
`tests/e2e/.observed-results.json`, and the report reconciles the union
against the catalogue. The count is derived, never maintained by hand:

```
npx playwright test --config tests/e2e/playwright.config.js result-coverage
php bin/result_coverage_report.php
```

### Where it stands

**45 of 49 reachable entries, 0 of 9 unreachable ones persisted.** The four
left are `error_email` (needs the instance in synchronous mode),
`email_gave_up` (needs 20 minutes of blackholed SMTP, verified by hand),
`swept_stale` and `ended_by_queue_rse` (both need an interruption or a race
that no participant or admin action produces).

| Spec | Results |
|---|---|
| `result-coverage-traversal` | `skip_false`, `skip_true`, `skip_to`, `skip_to_invalid`, `error_opencpu_r`, `group_N`, `pause_waiting`, `wait_ended_by_user`, `ended` |
| `result-coverage-external` | `external_compute`, `external_r_call_no_redirect`, `external_redirect`, `external_r_redirect`, `external_wait_for_api`, `external_ended`, `error_opencpu` |
| `result-coverage-survey` | `survey_started`, `survey_filling_out`, `survey_completed`, `error_survey` |
| `result-coverage-email` | `no_recipient`, `no_email_subject`, `no_email_body`, `invalid_email`, `email_skipped_user_active`, `email_queued`, `email_sent`, `error_send_eligible` |
| `result-coverage-push` | `no_subscription`, and behind `RESCOV_PUSH=1`: `sent`, `message_parse_failed`, `title_parse_failed` |
| `result-coverage-admin` | `survey_ended`, `moved`, **and the negative**: `manual_admin_push` must stay absent |
| `result-coverage-queue` | `pause_ended`, `wait_ended` |
| `result-coverage-expiry-optout` | `email_skipped_user_disabled` (and a documented, skipped attempt at `expired`) |
| `result-coverage-cron` | `waiting_deprecated`, `expired` (and a documented, skipped attempt at `swept_stale`) |
| `result-coverage-structure` | `run_structure_replaced`, `unit_removed_from_run` |
| `result-coverage-nosender` | `no_sender` |
| `result-coverage-smtp` | `error_email_not_sent` |
| `result-coverage-outage` | behind `RESCOV_OUTAGE=1`: `error_opencpu_down`, and `error_email` when the instance is in synchronous mode |

The 15 that remain are not simply unwritten — each needs something a spec
cannot arrange for itself:

| Result | What it needs |
|---|---|
| `error_email` | `email.use_queue = false`, an instance-wide config change. Verified by hand once (flip, traverse `rescov-mail-syncfail`, flip back: it records `error_email`; queueing restored, it records `email_queued`). The spec detects the mode from behaviour and skips unless someone has flipped it |
| `email_gave_up` | four failed attempts, spaced by `max(60, queue_item_ttl / queue_item_tries)` — 20 minutes of blackholed SMTP at stock settings. Verified by hand with `queue_item_ttl` temporarily at 60s ("Gave up after 4 delivery attempts…"), config restored and diffed after. Not automated: too long to leave a shared instance unable to mail |
| ~~`error_email_not_sent`~~ COVERED, and the mechanism is worth keeping: The daemon only picks up rows whose account is `status = 1`; status 1 is reached solely through `EmailAccount::test()` — a successful send — and `changeSettings()` resets it to 0 on every save. So an account cannot be *configured* broken, only *become* broken (host moves, credentials rotate, outage), which no admin action reproduces on demand. Reaching these needs either a DB write or breaking name resolution to the real SMTP host from inside the container |
| `expired` | a participant who answers an item and then goes quiet. `expire_after` is the INACTIVITY timer: setting it through the survey settings form works (the spec does), but a freshly opened Survey still gets `expires = NULL` and `queued = 0`, so no daemon pass considers it. The attempt is checked in, skipped, with the finding |
| `waiting_deprecated`, `ended_by_queue_rse`, `swept_stale` | legacy flags the MCP does not expose, a run session that ends while another unit is still queued, and a sweep over a stalled row |
| `run_structure_replaced` | the v1 API's structure PUT, which needs OAuth client credentials; `.env.dev` carries only the admin login |
| `unit_removed_from_run` | deleting a unit from under a live session — destructive to the fixture it needs, so it wants a run created and thrown away per test |

### What this lane established that the PHP one could not

- **`wait_ended` is unreachable through a participant's own visit.** Any
  non-cron request on a parked Wait takes the early-return branch, so a Wait
  a participant walks into ends as `wait_ended_by_user` on arrival. The
  elapsed path needs the Wait to be entered *by the cron cascade* —
  participant parked on a Pause, daemon ends it and advances them while they
  are away — which is how a diary study reaches one. `rescov-waitcron` is
  built that way (Pause → Wait → Endpage); the first attempt put the Wait
  first and recorded `wait_ended_by_user`, which is not a flake but the
  v1.8.0 finding reproducing itself.
- **The `end-last-external` API callback was still 100% broken** after
  v1.8.0 fixed the `TypeError` inside it. `ApiController::doAction()`
  resolved token data unconditionally, but `authenticate()` deliberately
  builds no OAuth server for `$unrestrictedActions` — so every call died on
  `getAccessTokenData() on null`, one line before `ApiV0` was constructed,
  and the Throwable catch turned it into a JSON 500. Fixed here, with
  `ApiBase` now tolerating a null token for unrestricted actions. The
  External spec covers it end to end: the participant is redirected out
  carrying `{{login_code}}`, the service posts back, the row becomes
  `external_ended`.

- **`Email::changeSettings` read cron_only's PRESENCE, not its value.**
  `(int) isset($options['cron_only'])` is correct for the admin form, which
  posts the checkbox only when ticked, and wrong for every caller that sends
  the field explicitly — the v1 API, a run import, the MCP. `cron_only: 0`
  became 1. And `cron_only` is in `$export_attribs`, so **exporting a run and
  importing it back turned every Email in it cron-only**, after which a
  participant who reached one in a browser was skipped with
  `email_skipped_user_active` instead of being mailed. Found because all seven
  Email fixtures recorded that one result. Fixed in v1.8.0.
- **`error_send_eligible` is reachable**, contrary to the audit's own claim
  above. The reasoning ("written to `$this->messages`, read only on the
  success path") describes the rate limiter's WARNING branch; its BLOCKING
  branch writes `$this->errors['log']`, which the caller reads on failure.
  The catalogue is corrected. Reaching it needs a prior *delivered* message,
  because `getMessageCounts` counts `status = 1` rows only — a queued one does
  not yet exist as far as the limiter is concerned.

- **`error_email_invalid_recipient` and `error_email_invalid_subject` are
  unreachable**, and the catalogue now says so. They are defensive
  re-validations inside the mail daemon, but every INSERT into
  `survey_email_log` goes through `queueNow()` or `claimSyncSend()`, both
  downstream of `sendMail()`'s own `FILTER_VALIDATE_EMAIL` and empty-subject
  checks — a row cannot arrive invalid. (`logMail()`, the one INSERT that
  skips those checks, has no callers.) Kept as guards; if a future writer
  bypasses `sendMail`, the coverage report flags them as newly reachable.
- **A deliberately broken email account is never picked up.** The daemon's
  poll requires `survey_email_accounts.status = 1`, and status 1 comes only
  from `EmailAccount::test()` succeeding, while `changeSettings()` resets it
  to 0 on every save. So the v1.8.0 give-up path can only ever fire for an
  account that broke *after* verification — never for one misconfigured from
  the start, whose mail sits at `status = 0` unqueued. That is the same shape
  as the known gap for accounts deactivated after queueing.

- **Removing a unit from a run DELETED participants' history**, for six of
  the nine unit types. Page/Endpage, Pause, External, Branch,
  SkipForward/SkipBackward and Shuffle each overrode `removeFromRun()` with
  `return $this->delete($special)` — and `delete()` drops the `survey_units`
  row, which `survey_unit_sessions.unit_id` references **ON DELETE CASCADE**.
  So an admin tidying a run silently erased every unit session for that unit:
  not the dangling-placement strand the F22/F7 audit fixed, but the loss of
  the rows an admin might be reading. The three types that use the base
  `removeFromRun()` (Survey, Email, PushMessage) only ever unlinked, which is
  why the difference went unnoticed. Fixed in v1.8.0: the overrides delegate
  to the base, which ends live sessions at the placement and then deletes only
  the `survey_run_units` row. Found by this suite — the coverage test deleted
  an Endpage and its parked row was not blank, it was *gone*.

### Building fixtures

**Through the app, never SQL.** A row written by INSERT is a state the
application never agreed to. An earlier harness hand-rolled them and every
"schema gotcha" it appeared to discover was self-inflicted:
`survey_studies.id` is a foreign key onto `survey_units.id`;
`survey_studies.id` and `survey_items_display.id` are not auto_increment; an
Email needs `body_parsed` and not just `body`.

The MCP recipe, and the whole of it:

```
create_run('rescov-skips')
get_run_structure_to_file('rescov-skips')
add_run_unit(...)                               # once per unit
update_run_structure_from_file('rescov-skips')
update_run_settings('rescov-skips', {public: 2})
```

`public: 2` is load-bearing — it is what lets a fresh browser context enrol
without a code, which is what makes these plain participant traversals.

`add_run_unit` with `survey_data` creates a Survey's study and items inline,
so even a survey fixture needs no spreadsheet upload. **Give those items both
`order` and `item_order`.** Without them formr renders the items in whatever
sequence the query returns: `rescov-pages` first rendered correctly, then
flipped to `q2, q1, submit` on a later run — two pages silently collapsed
into one, and the spec failed on a missing input rather than on anything it
was testing. Reading rows back to assert is fine; only writing them is not.

Fixtures live on the dev instance and are shared. Export each one via the
run editor's Export button (`admin/run/<name>/export_run_structure`) into
`tests/e2e/fixtures/`, so a lost fixture can be rebuilt with Import.

### Traps, all paid for once already

- **Survey pagination comes from item order**: an item, a `submit`, then
  another item gives two pages. `page_no` is NULL either way; editing that
  column by hand yields an incoherent page, not a second one.
- **Serialise specs that mutate shared state.** The `error_survey` test
  renames a results table. In parallel it breaks a concurrent traversal, and
  the symptom is a `fill()` timing out on an input a standalone browser finds
  perfectly visible.
- **Never revisit a parked Wait in the browser** unless the point is
  `wait_ended_by_user`.
- **Don't put a widget the test environment can't render alone on a page.**
  The previous Survey fixture had an optional `push_notification` item as all
  of page 2; headless Chromium collapsed the page and sometimes crashed
  outright. A survey fixture should exercise paging, not push support.
- **The per-recipient rate limiter makes Email fixtures interfere.** Its
  thresholds are instance-wide per address (1/minute by default), so fixtures
  sharing one recipient block each other and the symptom reads as a bug in
  the unit. Give each fixture its own address, and where a fixture is re-run
  often, generate a fresh one per execution — `recipient_field` is R:
  `paste0('rescov-queued-', as.integer(Sys.time()), '@invalid.invalid')`.
- **`email_queued` is transient.** The mail daemon runs continuously in the
  dev stack and overwrites the row with `email_sent` within ~15s, through an
  UPDATE with no `ended IS NULL` guard. Read it the instant the traversal
  returns, then poll for the daemon's value.
- **A spec that is also an admin needs two contexts.** `test.use({
  storageState })` authenticates the whole context, so enrolling a
  participant on that page clears the admin's cookies. Every later admin POST
  then lands on the login page, returns 200, and does nothing — the giveaway
  is not an error but an action that quietly had no effect.
- **`node_modules` in a release worktree is a symlink** into
  `formr-docker/formr_source`. `playwright.config.js` now finds `.env.dev`
  from either checkout (`FORMR_ENV_FILE` overrides).

### Bugs found here and NOT fixed

- **PushMessage `error` writes no row at all.** A send against an unreachable
  endpoint left only the fixture's rows. Suspected the same
  `Error`-vs-`Exception` shape as the Branch `TypeError` fixed in `d6be93c6`:
  `catch (Exception $e)` does not catch an `Error`. Worth checking before
  release — if so, a push send failure loses its own record entirely, which
  is this document's whole subject.
- Everything in *Per-unit findings* above that is not marked fixed.
  `forceTo()` manufacturing false Survey completions (X1) is the one that
  reaches published numbers, and the admin-actions spec is where it gets
  pinned.
