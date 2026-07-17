# Formr.org Change Log (check previous change logs in CHANGELOG-v1.md)

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

### Fixes
- **Re-ordering run units works again under the new UNIQUE (run_id, position) key, and no longer rejects position 0.** Two regressions arrived together: patch 064 promoted `position_run` to UNIQUE (audit F10), but `Run::reorder()` still wrote one `UPDATE` per unit — and MariaDB/InnoDB enforces UNIQUE per row *within* a statement (no deferred constraints), so any permutation that transiently reuses a live position (a plain swap, any rotation, a reorder of a consecutively-numbered run) failed with `23000 Duplicate entry` and rolled back to "Re-ordering failed"; and the F12 validation rejected any position `< 1`, so a run holding a unit at position 0 (a valid slot the rest of the engine now supports) could not be reordered at all because the editor posts every unit's position. `reorder()` now applies the batch in two phases inside one transaction — park every affected row in a band strictly above every current and requested position (collides with neither the not-yet-moved rows nor the finals), then write the finals — as two batched CASE `UPDATE`s (fewer round trips than the old per-row loop). Validation now rejects only *duplicate* positions within a batch; 0 and negative positions are allowed. Regression smoke `bin/test_reorder_smoke.php` (red on the pre-fix method: swap → 23000, position-0 batch → rejected).
- **Destructive survey operations now count the results table itself, never the metrics rollup.** `getResultCount()`'s switch to the write-time `survey_study_metrics` rollup (v1.7.0, SQ-11) had leaked into the gates that guard data deletion: `hasData()`/`hasRealData()`/`deleteResults()`/`backupResults()` decide whether a spreadsheet re-upload may recreate the results table and whether a backup is required first — and an unseeded (fresh patch 069), partial (first hook before the first reconcile) or drifted (direct imports, testing toggles) rollup row reading zero could let a re-upload `DROP`/`TRUNCATE` a populated results table with **no backup**. Those four gates now read a new `getResultCountLive()` (ground truth from the results table, cache-bypassing); the rollup continues to back the frequently-viewed display counts, where bounded staleness is fine. `deleteResults()` additionally resets the study's rollup row so display counts don't keep reporting deleted rows. Regression check: smoke section D (`bin/test_write_time_metrics_smoke.php`) poisons a rollup row to zeros and asserts display serves the rollup while the gates see the live data.
- **"Test Survey" no longer refuses the submit when the preview outlives its 15-minute token** (user-reported as "submission doesn't work for tested surveys"). The preview token minted by the admin "Test Survey" button expires after 15 minutes to bound how long a leaked link can start new previews — but the expiry check rejected *every* request, including the "Next"/"Finish" POST of a test already underway, so filling a survey for longer than 15 minutes ended in a 410 "Link expired" that silently discarded that page's answers (the pre-1.3.0 admin-session preview never expired mid-test). An expired-but-authentic token now *continues* a test that is already in flight in the same browser session (the `/survey-test/` session's `test_study_data` proves it started while the token was valid) and is still refused otherwise; finishing the test re-arms the refusal, so the replay window for leaked links is unchanged. Regression spec `tests/e2e/survey-test-token-expiry.spec.js` (red on the pre-fix controller); verified live end-to-end — answers POSTed after expiry are saved and the test finishes, while the same expired token in a fresh browser still gets 410.

## [Unreleased] — feature/write-time-metrics (v1.7.0)

Write-time metrics accounting — replaces v1.6.0's timer-driven rollup
refresh (a full scan every 30 min) with counters maintained in the same
transaction as the work, plus one nightly ground-truth reconcile. Reads
never scan history; the only full scan is the nightly pass. See
`documentation/agent_doc/write_time_metrics_plan.md`.

### Added
- **Compute-limit enforcement now fully pauses a run** (issue #608). Closing a
  run for exceeding the monthly compute limit set `public=0` but left cron
  running, so in-flight sessions kept consuming compute. It now also pauses
  `cron_active` (remembering the prior value in `survey_runs.compute_closed_cron_active`,
  patch 070) and the reopen path restores both.
- **Enforcement is visible in the admin UI.** The compute-usage tab shows an
  "N runs paused" badge per over-limit user; the runs-management table gains a
  Public column (lock/key/link/globe icon per level) and a "compute-paused"
  badge for auto-paused runs.
- **Geometric-mean survey completion time** — the "typical duration" line
  removed with the median hack in v1.6.0 (SQ-10) returns to the survey-unit
  dialog as `exp(mean(ln seconds))`, read O(1) from the study rollup. Unlike a
  median it is incrementally maintainable (a running Σln + count), it is a
  better central tendency for right-skewed durations, and it uses
  `TIMESTAMPDIFF(SECOND, …)` — fixing the old raw datetime-subtraction bug too.
- **`metrics_reconcile_enabled`** config flag (default true): an instance that
  doesn't watch compute can disable the nightly reconcile scan; the write-hooked
  study counts still self-maintain.

### Changes
- **`getResultCount` (SQ-11) reads a write-time study rollup** (`survey_study_metrics`),
  maintained by hooks at survey start/complete — `begun/finished/testers/real_users`
  are fresh without any scan. Scoped/filtered variants stay live; a study with no
  rollup row yet falls back to a live count.
- **Compute-usage dashboards, run/user lists, and the SQ-06/14/37 pagination
  counts** read the reconcile-maintained run rollup. `ComputeLimitCron`
  enforcement continues to read live, so rollup staleness never affects a quota.
- **Nightly reconcile (03:23) replaces the 30-minute refresh.** `RunMetrics::refresh()`
  → `reconcile()` (idempotent full recompute + drift correction), config-gated;
  the hourly compute-limit-cron refresh fallback is removed.
- **The compute-usage dashboard reconciles on view when stale**
  (`RunMetrics::reconcileIfStale`, TTL-gated), so recent compute and any
  resulting over-limit closures show immediately rather than waiting for the
  nightly pass — a rarely-viewed superadmin tab, so one scan per view-burst.

### Schema
- **Patch 069:** new `survey_study_metrics` (per-study response counts +
  `sum_log_duration`/`n_durations` geometric-mean accumulator); `n_unit_sessions`/
  `n_push_logs`/`n_email_logs` on `survey_run_metrics`. Seeded by the first reconcile.
- **Patch 070:** `survey_runs.compute_closed_cron_active` — remembers a run's
  prior `cron_active` when the compute limiter pauses it (issue #608).

## [v1.6.0] - 12.07.2026

Slow / inefficient MariaDB query audit and remediation (see
`documentation/agent_doc/slow_query_audit_2026-07.md` — 54 verified
findings from a live-`EXPLAIN` pass over every DB call site). This
release implements every actionable finding: the index, correctness,
rewrite, cleanup, and safety findings, plus the two larger deferred
items (a maintained per-run rollup for the historical aggregates, and
per-item write batching on the hottest participant paths). The
remaining tail is low/fine-at-current-volume or a by-design no-op
(SQ-28/29/31/32/44/46/47/48/49/50/51 — see the audit doc §0).

### Fixes
- **User-detail pagination count matched to the displayed rows (SQ-06).** `RunHelper::getUserDetailTable`'s count query omitted the run scope its sibling items query applies and joined `survey_run_units` on `unit_id` alone, so a unit reused across runs fanned the count out — pagination totals could disagree with the table. Both queries now share one run-scoped `WHERE`/join.
- **Run-management list shows every run again (SQ-13).** `getRunsManagementTablePdoStatement` grouped by the nullable joined `survey_run_sessions.run_id`, collapsing all zero-session runs into a single `NULL`-keyed row; `GROUP BY survey_runs.id` restores one row per run.

### Changes
- **Per-run metrics rollup for historical-aggregate reads (SQ-13/16/17/18/21).** The compute-usage dashboards (`ComputeUsageHelper`) and the admin run-list / active-users tables each re-aggregated the full `survey_run_sessions`/`survey_unit_sessions` history (~94k/120k rows) per view. A new `survey_run_metrics` rollup (patch 068), maintained by `RunMetrics::refresh()` (a half-hourly cron at odd minutes plus a fallback at the end of the hourly compute-limit cron), now backs those reads in `O(runs)`. Verified hash-identical to the old live queries; enforcement (`ComputeLimitCron`) still reads live, so rollup staleness never affects a quota decision. The active-users list additionally stops collapsing run-less admins into one row.
- **Per-item write batching on the hottest participant paths (SQ-40/41/42).** Survey answer submission (`UnitSession::updateSurveyStudyRecord`) and page render (`SpreadsheetRenderer` show-if `hidden` flags + `displaycount` bumps) issued one UPDATE per item; each is now a single batched statement per page (multi-column CASE / IN-list), preserving exact semantics. A ~45-item page drops from ~45 sequential round trips to one per loop. Verified end-to-end against a real participant submission.
- **Batched OAuth allowlist + runs-management save (SQ-30/33).** `listClientsForUser` fetches all clients' run allowlists in one query; the runs-management form saves via one CASE UPDATE instead of one per run.
- **Hot admin/export/cron queries rewritten (SQ-01/02/03/11/12/14/22/23).** User-overview table + export sort by `last_access DESC` (served by a new index) with the admin-session pin moved to PHP, instead of an unindexable `session != code` expression sort + `%substring%` search; the long-form results export orders on `(session, unit_session_id, display_order)` — identical row order, far smaller filesort. `ComputeLimitCron::candidateUsers` pushes the month bound into a derived table so the hourly cron scans this month's unit-sessions, not all history (result set verified byte-identical). `getResultCount` now actually caches its unscoped result (admin survey pages stopped re-scanning 2–3×/load). Push-message log paginates with a real `COUNT`+`LIMIT`. `markSubscriptionExpired` resolves the handful of `push_notification` item ids first and ranges `survey_items_display` instead of leading-wildcard-scanning 114k rows per expired subscription. The superadmin user-detail browser's unfiltered view is index-ordered (newest first) with `result_log` bounded and prefix session search.
- **`ORDER BY RAND()` removed from Test-dialog session sampling (SQ-19/20)** in favour of most-recent-first — the old form materialised and sorted the whole matching set before `LIMIT`.
- **Bulk admin actions are bounded (SQ-39/43).** `positionSessions`/`sendReminder` reject selections above `max_bulk_session_actions` (config, default 500) instead of turning one request into thousands of sequential queries + locks; `DB_Select::whereIn()` throws above 5,000 values.

### Removed
- **Deprecated median-duration display (SQ-10)** — `SurveyStudy::getAverageTimeItTakes()` and the "(in ~ Xm)" line in the Survey unit dialog. The `@row:=@row+1` median hack double-full-scanned the results table on every dialog open and its evaluation order is unspecified in modern MariaDB.
- **Dead code:** `SurveyStudy::getResultsByItemAndSession()` and `Run::getCronDues()` (zero callers); `bin/queue-migration.php` (migrated off the long-gone `survey_sessions_queue` table).

### Schema
- **Patch 065:** missing-index batch (SQ-04/05/07/08/09/14/15/24/32/35/38) — `survey_unit_sessions` gains `run_unit_id`-leading, current-lookup, and `(run_session_id, created, id)` composites; `survey_run_sessions`/`push_logs` gain `(run_id, created)`; `survey_email_log` gains `status` and `(recipient, status, created)`; `survey_notifications` gains `(run_id, recipient_id, type)`; the oauth token tables gain `client_id`/`user_id`.
- **Patch 066:** housekeeping (SCH-01/02/05/06) — drop 25 redundant indexes (~5.7 MB; 22 duplicate/left-prefix + 3 superseded by 065's composites, every FK keeps covering coverage), add a real `PRIMARY KEY` to `oauth_scopes`, drop the low-cardinality standalone `survey_run_sessions.position` index, migrate `osf`/`survey_run_settings` to InnoDB.
- **Patch 067:** `survey_run_sessions (run_id, last_access)` for the rewritten user-overview ordering (SQ-02/12).
- **Patch 068:** new `survey_run_metrics` per-run rollup table (seeded at migration) backing the compute-usage dashboards and admin lists (SQ-13/16/17/18/21).

## [v1.5.0] - 11.07.2026

### Fixes
- **Run-engine audit hardening — all 23 confirmed findings** (see `documentation/agent_doc/run_engine_audit_2026-07.md`. Ops tooling in `formr-docker/`: `diagnose_run_engine.sh` checks whether an instance was already affected, `rescue_run_engine.sh` remediates existing damage, `f4_deepdive.sh` classifies survey expiry against each study's own X/Y/Z to separate genuine premature expiry from normal sliding-window completion).
  - **Web requests see stored unit-session state again (audit addendum).** `RunSession::getCurrentUnitSession()` handed out `UnitSession` objects hydrated with only their `id` (constructor allowlist, `ed56a95f`), so every interactive request evaluated deadline guards against `null` — silently disabling web-side Pause/Survey/External expiry, the queue-`expires` refresh, and the v1.4.0 overdue-Pause fix on its primary target path. The current session now loads through the trusted PK path.
  - **Reminder emails can no longer hijack a participant's run (F1, critical).** `UnitSession::create()` stamps `run_unit_id` only when the created unit is the unit hosted at the current position (off-position sessions keep it NULL); `getCurrentUnitSession()`'s placement arm additionally requires `unit_id` to match, neutralizing existing impostor rows; and `Run::sendReminder()` now ends the reminder session instead of leaving it live forever.
  - **Ended run sessions are terminal (F2).** `moveOn()`/`runTo()` no longer advance an ended run session, `runTo()` revives only with the explicit admin flag `forceTo()` passes, and `updateSurveyStudyRecord()` refuses writes into an ended/expired session (back-button re-POST after completion).
  - **Lock-free admin/API movers (F3).** `forceTo()` and the new `forceMoveOn()` (used by `ajaxNextInRun`, which previously ended the unit without ever advancing — stalling cron-only participants) hold the run-session named lock and reload under it.
  - **Daemon deadline handling (F4/F15) — defense-in-depth on an already-closed bug.** The headline F4 damage (sliding-window ESM surveys expiring actively-working participants at the pre-access `invitation + X` deadline — the "X-rule expires mid-editing users" bug) was **already fixed in v1.4.0** by `4c9581c9` (2026-05-08, the wiki-aligned `getUnitSessionExpirationData` rewrite). Forensics on a prod ESM run (`formr-docker/f4_deepdive.sh`) confirmed 175 genuine premature expiries that stop dead the day that fix landed; everything since is correct sliding behaviour. This release adds two independent, lower-value guards on top: (a) the END-q path revalidates the deadline against current state before expiring, closing a residual last-second-start race the render-time re-normalisation doesn't cover at the daemon layer (no occurrences observed in prod post-2026-05-08); (b) deadlines are persisted even on cron-inactive runs so the web overdue guard has a stored value to act on. The genuinely load-bearing v1.5.0 expiry fix is the hydration addendum above, which restores correct `created`-anchored (X, X+Y) computation on the web path — a separate regression (`ed56a95f`) that `4c9581c9` did not touch.
  - **Cron cascades and recovery (F5/F6).** A cron cascade no longer advances past a redirect External (which parked the successor invisibly); `bin/sweep_stalled_unit_sessions.php` (hourly) re-executes genuinely-parked current sessions and terminal-stamps stale litter.
  - **Structure mutation (F7/F8/F22).** `Run::reorder()` rejects non-positive/duplicate positions; `RunUnit::removeFromRun()` and `Run::replaceUnits()` expire in-flight sessions before unlinking placements so participants aren't stranded on a dangling `run_unit_id`; `sql/patches/064_unique_run_unit_position.sql` adds `UNIQUE(run_id, position)`.
  - **Flow control (F9/F12).** Branch/Skip validate the jump target before ending (a dangling `if_true` no longer drops the participant into the un-skipped arm; the admin is notified); position-`0` units start and traverse correctly instead of reading as "study undefined"/"end of run".
  - **Survey POST binding (F13).** A POST is bound to the unit session it was rendered for (hidden `session_id`), so a back-button resubmit in a looping/diary run no longer lands in the next iteration.
  - **Messaging idempotency (F14/F20/F21).** `Email::sendNow()` claims before sending; `PushMessage` resolves its claim on the no-subscription/parse-fail/error branches; `endLastExternal()` ends only the newest live External.
  - **Loop/rate bounds (F11/F19).** `spam()` distinguishes a real loop (revisited position) from a daemon-outage catch-up cascade and no longer ejects the latter; the Pause/Branch re-check loop uses an escalating backoff instead of hammering OpenCPU every 10 minutes forever.
  - **Randomisation (F17/F18).** Shuffle group assignment is stable per participant across SkipBackward revisits and the insert is idempotent (no duplicate-key crash on retry).
  - **Time (F16/F23).** Daemon DB connections re-sync `time_zone` each pass so deadlines don't skew an hour across DST; an elapsed Pause/Wait ends (`pause_ended`/`wait_ended`) instead of being labelled `expired`.
  - **`GET_LOCK` timeouts honor fractions:** the queue's configured 0.1 s lock timeout was truncated to 0 by an integer bind.

### Schema
- **Patch 064:** `position_run` KEY → `UNIQUE(run_id, position)` on `survey_run_units`. **Precondition:** no duplicate `(run_id, position)` rows (the `ALTER` fails loudly otherwise); `diagnose_run_engine.sh` check F10 / `rescue_run_engine.sh` report them.

## [v1.4.0] - 08.07.2026

### Fixes
- **Backport of the 0.26.4 run-engine fixes and the 0.27.0 duplicate-row forever-fix** (this 1.x line diverged at v0.26.2 and never received them; Track A / patches 047–048 were already shared):
  - **Reused-unit misrouting:** `RunSession::getCurrentUnitSession()` and `UnitSession::create()`'s supersede now key on `run_unit_id`, not `unit_id` — a run that slots the same survey at multiple positions no longer advances a participant past the intervening units, nor zombifies their still-active earlier-occurrence session. `getRunData`'s `survey_unit_sessions` join is pinned per-placement (no N× fan-out for reused units).
  - **Overdue Pause no longer re-evaluates its R deadline on a web request** — a now-relative rule (e.g. "first Monday of next month") no longer re-arms itself a month forward when the daemon is lagging.
  - **A failed unit-session INSERT no longer corrupts the run's flow**, and the "no live session at this position" recovery hop is bounded.
  - **Duplicate rows are now prevented structurally:** patch 063 promotes `idx_run_unit_iter` to UNIQUE and `create()` adopts the winner's row on the duplicate-key error (idempotent create). `bin/heal_duplicate_pause_sessions.php` (+ `Services/DuplicatePauseHealer`) remediates existing duplicates; `bin/backfill_run_unit_id_active.php` backfills in-flight legacy sessions.

### Schema
- Patch 063: `idx_run_unit_iter` KEY → UNIQUE on `survey_unit_sessions (run_session_id, run_unit_id, iteration)`. **Precondition:** heal duplicate tuples to zero first (the `ALTER` fails loudly otherwise).

### Changes
- **Compute-usage dashboards are index-only now** (issue #608, user-reported slowness on a large instance). The per-run/per-user/instance aggregates over `survey_unit_sessions.execution_time` previously read every joined row and tested `execution_time IS NOT NULL` afterwards (`Using where` — a whole-table scan with random row IO to find the measured fraction). A covering index `idx_uxec_compute (run_session_id, execution_time, created)` makes all five dashboard queries `Using index` (no row fetches; the instance-wide total becomes an index scan instead of a table scan). Big established instances should `./db_atlas_apply.sh apply` (patch 062) to pick it up. If the instance-wide superadmin view is still heavy at extreme scale, the next step would be precomputed per-run roll-up counters (O(runs) instead of O(unit-sessions)) — not done here.
- **The per-user "Compute" link moved off the main admin nav into the account view** (issue #608). It now sits in the profile box on `/admin/account` alongside Surveys / Runs / Email Accounts, showing the account's total compute and linking to `/admin/compute`; surfaced only to admins (who can open the dashboard). The superadmin instance-wide view stays under Advanced → Compute Usage.

### Schema
- `sql/patches/062_unit_session_execution_time_index.sql` — adds covering index `idx_uxec_compute (run_session_id, execution_time, created)` on `survey_unit_sessions` so the compute-usage dashboards aggregate index-only.

## [v1.3.1] - 21.06.2026

### Added
- **Compute/runtime usage logging and dashboards** (issue #608). Each unit session now records the cumulative wall-clock seconds it spent executing, including OpenCPU/R calls, in a new `survey_unit_sessions.execution_time` column. `UnitSession::execute()` is wrapped in a monotonic `hrtime()` timer whose `finally` clause accumulates onto the column via an in-place `COALESCE(...) + delta` increment (so units that execute repeatedly — surveys paging forward, pauses re-checked by the daemon — add up, per the issue's open question, and concurrent passes never lose time to a read-modify-write race). Timing is best-effort: a failed/missing-column write is logged and swallowed, never breaking a participant's run. Two read-only dashboards surface it: **`/admin/compute`** for study admins (their own runs — total, this-month, and per-run totals with avg/slowest/last-activity), and **`/admin/advanced/compute_usage`** for superadmins (instance-wide, broken down by user and by heaviest run). Both exclude pre-feature rows (`execution_time IS NULL`) so totals reflect only measured compute. New `application/Helper/ComputeUsageHelper.php` holds the aggregate queries and a duration formatter. Regression-tested in `tests/e2e/compute-dashboard.spec.js`. **Coverage note:** the `execute()` wrap captures all participant run-traversal compute — survey render + paging + submission, Branch/Pause `relative_to`, External R, and Email/Push rendering (web and daemon). It does **not** count admin-side OpenCPU (survey-test preview, the `advanced/test_opencpu*` tools), which is dev activity rather than study resource use.
- **Configurable monthly compute limits, auto-closing over-budget studies** (issue #608). A per-user monthly compute budget (seconds of execution time summed across all the owner's runs in the current calendar month). The effective limit is the per-user `survey_users.compute_limit_monthly` when set, else the instance-wide `compute_limit_monthly_default` config; in both, **0 = unlimited and is the default**, so nothing is limited out of the box. A new hourly cron (`bin/cron_compute_limits.php` → `application/ComputeLimitCron.php`) enforces it: when a user is over budget it sets every still-public run of theirs to `public = 0`, remembering the prior level in a new `survey_runs.compute_closed_from` column, and emails them once (`email/compute-limit-reached.ftpl`); when usage drops back under the limit (typically when the month rolls over) it restores those runs to their prior visibility and emails once (`email/compute-limit-restored.ftpl`). Runs the owner closed by hand are never touched. Limits are **superadmin-set only** — run owners cannot change their own — via an editor on the superadmin compute dashboard (plain PRG form, no inline JS, CSP-safe). When the global default is 0 (the open-source default), the cron only considers users with an explicit override, so it stays cheap.

### Fixes
- **Runtime error emails to study admins now contain the actual error, and are suppressed in test sessions** (issue #608, user-reported). The Page-unit OpenCPU/knit failure notification previously sent a generic "OpenCPU error while knitting page content." with no detail; it now appends `opencpu_last_error()`, matching what Branch/External/Pause units already include — so the email is actionable. Separately, `Notification::canBeSent()` now returns false when the run session `isTesting()`, so the expected R errors an admin hits while building/previewing a run (toggled-testing sessions, the survey-test harness) no longer email them and bury real participant-facing errors. The 10-minute per-run/recipient/type throttle and the daily-digest idea from the issue were intentionally left for later.
- **Superadmin User Management: the row actions (Manage API Access, Delete User, Verify Email, Add Default Email Account) silently did nothing** — a regression from the v1.2.0 admin-CSP externalization. Those four buttons are bound in the admin bundle (`webroot/assets/common/js/run_users.js`), which reads the AJAX endpoint from a **global** `saAjaxUrl`. That global used to be defined by the page's inline `<script>`; when the page's behaviour was externalized to `webroot/assets/admin/js/user_management.js` for CSP, only the Reset 2FA handler (which reads the endpoint into a *local* var) was carried across, so `saAjaxUrl` was left undefined and every one of those clicks threw `ReferenceError: saAjaxUrl is not defined` and aborted before opening its modal / sending the request. (Reset 2FA was unaffected, which is why it kept working.) Fixed by re-exposing the endpoint as `window.saAjaxUrl` in `user_management.js`, read from the page's existing `data-sa-ajax-url` attribute — no inline script, so the admin CSP stays strict. The v1.2.0 CSP violation crawler could not catch this: it only GET-renders pages (it never clicks, so a click-handler `ReferenceError` never fires) and it runs as a non-superadmin (the page 403s and is skipped). A dedicated interaction-level guard was added in `tests/e2e/user-management-actions.spec.js`.
- **`/admin/run/add_run`: the run-name field's HTML `pattern` was rejected by the browser, silently disabling its client-side validation** (console: `Invalid regular expression: /^[a-zA-Z][a-zA-Z0-9-]*$/v: Invalid character class`). Browsers now compile the `pattern` attribute with the RegExp **`v`** flag, under which a bare `-` in a character class must be escaped. Changed the attribute to `^[a-zA-Z][a-zA-Z0-9\-]*$` (`templates/admin/run/add_run.php`) — valid under both `v` and `u`, accepting/rejecting exactly the same names. Server-side run-name validation (`AdminRunController`, PCRE) was never affected; this only restores the in-browser hint.
- **"Test Survey" preview was stuck or looping on multi-page surveys** (user-reported): the first page rendered, but "Next" either bounced back to page 1 (submit-item surveys) or hung in an infinite redirect (surveys with **Custom Paging**, `use_paging`). "Test Run" — the same survey inside a run — was unaffected, which localized it to the preview path. A v1.3.0 regression: moving the preview render off `/admin/` to fix the CSP issue (above) folded seeding, rendering, and the page-submit POSTs into a single `SurveyTestController::indexAction`, which broke two assumptions of the shared render engine. (1) It re-seeded `test_study_data` on **every** request; `Run::testStudy()` writes the created `unit_session_id` back into that array so later "Next" POSTs can resume it, and the unconditional re-seed dropped it each request — so every "Next" spawned a fresh unit-session stuck on page 1. (2) `PagedSpreadsheetRenderer` navigates by a URL page segment and redirects to add it when absent, but `indexAction` never read that segment (the `pageNo` request global stayed unset) and its redirect branch always targeted the bare, page-less `/survey-test/<token>/` — so the renderer redirected forever. Fixed by seeding `test_study_data` only when there is no live test session for that study yet, surfacing the page segment as the `pageNo` global (mirroring `RunController`), and translating the engine's redirect the same way `run_content` URLs are already rewritten so the page survives the PRG hop (`application/Controller/SurveyTestController.php`). Regression-tested in `tests/e2e/survey-test-paging.spec.js` (paged + non-paged).

### Changes
- **Every outbound email now identifies the instance it came from** (issue #608). `Template::get_replace()` injects a default `instance` value (`formr_instance_label()` — sender name + admin URL, resolved from the `admin_domain` config so it is correct in CLI/cron, where `WEBROOT`/`site_url()` collapse to a bare scheme) for any `email/*` template; explicit values still win. An `Instance: %{instance}` line was added to `notification`, `compute-limit-reached`, `compute-limit-restored`, `verify-email`, `forgot-password`, `test-account`, `auto-delete-reminder`, `auto-delete-notification`, and `email-queue-problem`. The compute-limit and error notifications additionally name the affected run/study and the time. (`reg-account.ftpl` is a user-composed request-to-admin body, not a formr-sent email, so it was left as-is.) Same audit caught and fixed a pre-existing bug: the **auto-delete reminder email's login link** was built with `admin_url()`, which renders host-less (`https:///admin/`) in cron context — it now resolves via `formr_admin_base_url()`.

### Schema
- `sql/patches/060_unit_session_execution_time.sql` — adds `survey_unit_sessions.execution_time DECIMAL(12,3) UNSIGNED NULL` (cumulative execution seconds; NULL = never measured).
- `sql/patches/061_compute_limits.sql` — adds `survey_users.compute_limit_monthly DECIMAL(12,3) UNSIGNED NULL` (per-user monthly budget in seconds; NULL = inherit default, 0 = unlimited) and `survey_runs.compute_closed_from TINYINT NULL` (prior public level when auto-closed by the limiter; NULL = not compute-closed). New config `compute_limit_monthly_default` (default 0 = unlimited) in `config-dist/settings.php`.

## [v1.3.0] - 18.06.2026

### Added
- **API capability surface on `/v1/user/me`.** The user-profile endpoint now also returns `admin` (the caller's admin level), `scopes` (the OAuth scopes granted to the calling token, from the same source `ApiBase::checkScope()` validates against), and `allowed_runs` (the token's run allowlist as `{id, name}`, or `null` when the credential is unrestricted). This lets API clients (e.g. the formr MCP server) explain up front what a token can and cannot do, instead of surfacing limits only as 403s. Read-only; no extra queries beyond one id→name lookup for the run allowlist (`application/Api/V1/UserResource.php`).

### Fixes
- **Survey testing broke under the admin CSP** — live `showif` fields (e.g. "Other, please specify") stopped appearing on selection and only showed after submitting. v1's client-side showif evaluator uses `new Function()` (`webroot/assets/common/js/survey.js`), which needs `'unsafe-eval'`; the enforce-mode admin CSP (added in v1.2.0) has none, and the survey **test/preview** rendered under `/admin/` where the CSP applies. Fixed by moving the "Test Survey" render off `/admin/` to a token-gated `/survey-test/<token>` path (new `SurveyTestController`; `AdminSurveyController::accessAction` now mints a short-lived signed Halite token and redirects there instead of seeding the admin Session). That path isn't in the admin area → no CSP, so `new Function()` works; and being outside `/admin/`, the `/admin/`-scoped admin session cookie is never sent to the participant-authored survey render — strictly safer than CSP-exempting an admin page. `eval` is kept and the admin CSP stays strict everywhere on `/admin/`. Regression-tested in `tests/e2e/survey-test-csp.spec.js`.
- **API documentation: `admin/account#api` rendered as plain text, not a link** (user-reported). The four prose references on the API docs page (`documentation/#api`) are now working links to the account page, and deep-linking to `/admin/account#api` now activates the **API Credentials** tab — Bootstrap 3 does not open a tab from the URL hash on its own, so `account-api-credentials.js` now does it (and runs even for users without API access, who still see the tab).

### Changes
- **Expanded the API documentation** to address a recurring point of confusion. Added an up-front callout that **API credentials are not the same as a study's R-Secrets** — R-Secrets (run *Settings → R Secrets*) are values your own R code reads as `.formr$secret_<name>` and never grant anyone API access — and a step-by-step recipe for creating a **read-only, single-run** credential (`data:read` + run allowlist) to give a collaborator data-download access without sharing an account login. Also clarified that **R code running inside formr needs no credential at all**: when called in an OpenCPU context (showif, value/label, condition, page/email body, External unit, overview script), `formr_api_authenticate()` with no arguments auto-fills a short-lived (180s), owner- and run-scoped token, so `formr_store_keys()` is only for driving the API from outside formr.
- **Embedded the full API documentation in the account API Credentials tab** (`admin/account#api`), in a collapsible panel below the create/rotate UI, so credential authors have the how-to (auth flow, scopes, the read-only single-run recipe, R usage) right where they manage credentials. Rendered from the same `public/documentation/api` template as `/documentation/#api` — single source — via Bootstrap collapse, so it stays within the admin enforce-CSP (no inline script).

## [v1.2.0] - 12.06.2026

### Added
- **Content-Security-Policy on the admin area** (defense-in-depth for the same participant→admin stored-XSS class fixed below). A nonce-based policy on `admin_domain`: `script-src 'self' 'nonce-…'` with **no** `'unsafe-inline'` and **no** `'unsafe-eval'`, so an injected inline `<script>`/`onclick` does not execute even where output-escaping is missed. Built in `application/Csp.php`, emitted from `Controller::sendResponse()` (gated to admin + `text/html`), with a per-request nonce from `Site::getCspNonce()`. Driven by `$settings['csp_mode']` = `off` | `report-only` | `enforce`, **defaulting to `enforce`** — set it to `report-only` on an instance to observe-and-report without blocking if the policy needs widening (e.g. a non-default OpenCPU origin reached from the browser). Violations POST to `/api/csp-report` (unauthenticated, POST-only, 16 KB cap) and are logged to `csp.log`. To make the policy enforceable, every inline admin script was moved to external `'self'` files (`admin/js/{admin-ui,account-api-credentials,user_management}.js`, server values passed via `data-` attributes) and all `javascript:void(0)` hrefs became `href="#"` with a delegated handler. A Playwright violation crawler (`tests/e2e/csp-crawl.spec.js`) and a sweep workflow cover the admin route surface. See `documentation/agent_doc/csp.md`.

### Fixes
- **Security (stored XSS, participant → admin):** participant-submitted answers were echoed unescaped in the admin **Survey Results** (`show_results`) and **Detailed Results** (`show_itemdisplay`) tables, which run on `admin_domain`. A participant could store `<img src=x onerror=…>` in an open-text answer — or in the User-Agent/Referer captured by a Server/Referrer/Browser item — and execute script in the researcher's authenticated admin session, crossing the admin/study subdomain security boundary. (No CSP was in place to mitigate.) Answer cells are now rendered through a new `Item::getEscapedResult()` that HTML-escapes by default; `File`/`Audio`/`Video`/`Image` override it to pass their server-generated embed markup through (the `src` is a `crypto_token()` asset path, never participant text), so media previews still render. Result-table meta columns (timestamps, session codes) are now escaped in their `<abbr>`/`data-` attributes as well. Regression-tested in `tests/ItemEscapedResultTest.php`. Spreadsheet exports (CSV/XLSX/JSON) and the admin-authored item-definition table are unaffected.
- **Security (stored XSS, participant → admin):** a follow-up sweep for the same class fixed the **email log** (`email_log`: recipient, subject, `result_log` and status all rendered unescaped — `survey_email_log.recipient` and `survey_unit_sessions.result_log` carry participant-influenced text, e.g. the raw validation-failed recipient and participant-knitted subjects) and the **user-session status** cells in `run/user_overview`, `run/user_detail` and `advanced/user_detail` (the `result_log` tooltip in `advanced/user_detail` was raw; the adjacent `result` status string was unescaped in all three). `result_log` is now `h()`-escaped at every render site. (Session codes are server-generated `crypto_token`s and participant emails pass `FILTER_VALIDATE_EMAIL`, so those displays were not exploitable; left for a separate hardening pass.)

## [v1.1.1] - 11.06.2026

### Fixes
- ParsedownExtra fatal error when a survey item label or run text field contains malformed HTML (e.g. a bare `<head>` tag). ParsedownExtra's DOMDocument block processor (`processTagRoutine`) dereferences DOM nodes without null checks, crashing with a PHP `Error` (`TypeError: DOMNode::replaceChild(): Argument #1 ($node) must be of type DOMNode, null given`, surfacing on some setups as `Call to a member function getAttribute() on null`) — not an `Exception` — so the existing `catch (Exception)` guard in `SurveyStudy::addItems` did not intercept it. All Parsedown `text()` call sites now go through a new `parsedown_text_safe()` helper (`Functions.php`) that catches `\Throwable`, stores the raw (unparsed) text, logs via `formr_log_exception`, and shows the study author a warning naming the affected field. Covered sites: survey item labels, choice labels, run description/public_blurb/footer_text/privacy/tos, email body, pause text, and page body (the chained call in `Page::create()` was previously unguarded). Regression-tested in `tests/ParsedownTextSafeTest.php`.

### Changes
- Updated `erusev/parsedown` 1.7.4 → 1.8.0 and `erusev/parsedown-extra` 0.8.1 → 0.9.0 (the February 2026 maintenance releases). Verified that the upgrade alone does **not** fix the DOMDocument crash above — 0.9.0 ships the same unguarded `processTagRoutine` — hence the call-site guard.

## [v1.1.0] - 11.06.2026

### Added
- **Run-level custom R functions** (`custom_r`, settings → "R Functions" tab). Stored like `custom_css`/`custom_js`, injected after `library(formr)` into every OpenCPU evaluation and knit context (showif, value, feedback, `relative_to`, branch conditions, external URLs, email bodies, overview scripts) via a single `eval(parse(text = …))` statement, so syntax errors surface as clear runtime errors and Rmd chunks can't be broken by the injected code. Exposed through the v1 API (`custom_r` read/update) and run export/import.
- **Run-level secrets** (settings → "R Secrets" tab). Encrypted at rest via Halite (`survey_run_secrets`), available in R as `.formr$secret_<name>`, and injected **only** when that literal reference appears in the unit's R code or the run's custom R functions. The admin UI is write-only: a stored value is never sent back to the browser — it can only be replaced or deleted. Names are restricted to `[A-Za-z0-9_]` server- and client-side. Run export carries secret *names* only; import recreates them as empty placeholders to be re-entered.
- **Secret redaction** in OpenCPU debug output, error notifications, result logs, and `opencpu.log`. Secret values of 6+ characters are replaced with `[SECRET REDACTED]`. Best-effort by design: transformed occurrences (e.g. JSON-escaped non-ASCII) can evade a literal match, and the OpenCPU session itself still receives the plaintext — the deployment-level denypaths on `/console`/`/source` remain the wire-level guard.
- **R syntax validation** for custom R code ("Save & Test R Syntax"): runs `base::parse()` on OpenCPU (parse only — nothing is executed) and reports errors inline, with a "Copy for LLM" export of code + error.
- **"Open in R Fiddle" links in the OpenCPU debugger.** The R Markdown / R Code panels now link to an in-browser webR fiddle (default `https://fiddle.rforms.org/`, configurable/disableable via `$settings['r_fiddle_url']`). The (secret-redacted) code travels in the URL fragment, which browsers never transmit to the fiddle host.
- **Optional `api_internal_url` setting** for R→formr API calls from OpenCPU. When set (e.g. `http://formr_app/api` on the shared Docker network), `opencpu_prepare_api_access` injects it as `.formr$host` instead of the public `api_base_url()`, so `formr_api_*()` calls skip DNS, TLS, and the reverse proxy (~30% faster per call in benchmarks). Empty (the default) keeps the previous behaviour. The internal hostname must resolve to a vhost that serves the API; the bearer token travels as plaintext HTTP on this URL, so only enable it on a trusted single-host Docker network.

### Fixes
- Run settings page: the special-unit links (service message, reminder, overview script) redirect back to the right settings tab again. `site_url()` appends a trailing slash to fragment-free URIs, so building the URL before converting `:::` to `#` produced `…settings#service_message/` — a fragment no tab matches. The `:::` → `#` conversion now happens before the URL is built (`createRunUnitAction` / `deleteRunUnitAction`).
- `api_base_url()` no longer appends `/api` when a dedicated `api_domain` (different from `admin_domain`) is configured (#695). A dedicated API host serves the API at its root — its vhost rewrites `/` to `route=api/…` — so the docs page, the account-page R snippets, and the OpenCPU bridge previously pointed clients at a doubled `api/api/…` path. The `/api` suffix remains for the admin-domain fallback.
- Run settings page: the "Settings saved" response alert now appears in the tab whose form was saved, replacing the previous alert instead of stacking. It used to be inserted after `#app_heading` — which lives in the manifest tab — so saving from any other tab gave no visible feedback.
- `RunHelper::nextInRun()` fatal on boolean: `getCurrentUnitSession()` returns `false` when no active unit-session row exists; the guard now checks falsy (not just null) so the friendly "No unit session found" alert renders instead of a 500.
- `SpreadsheetReader` quadratic import time on bloated `.xlsx` files: `getHighestDataColumn()` was called once per row inside both sheet-reading loops, scanning the entire cell index each time. Now computed once per sheet, bounded to the rightmost allowlisted column. Synthetic 1500×60 whitespace-bloated sheet: 161 s → 9 s; output digest unchanged.
- Run export JSON was missing `condition` from `SkipForward` and several attributes from `External`, `Page`, and `SkipBackward`.

### Changes
- Run settings page layout homogenized to match the newer admin credential page: consistent `row`/`col` grid, `h4.lead` section headings, equal-height markdown editors, empty-state hints for service messages/reminders/overview scripts, a clearer "Delete Reminder" button, and the Test Run link opens in a new tab. Removes the five duplicated `id="run_settings"` form ids (the save handlers are class-based).
- `survey_unit_sessions.result_log` widened to MEDIUMTEXT; `truncate_result_log()` caps writes at the same 16 MiB limit (byte-safe for UTF-8) in the unit-session and email-queue paths.

### Schema
- `057_custom_r_path.sql` — `survey_runs.custom_r_path`.
- `058_result_log_mediumtext.sql` — `result_log` TEXT → MEDIUMTEXT.
- `059_create_run_secrets.sql` — `survey_run_secrets` (unique per run+name, FK cascade on run delete).

## [v1.0.0] - 16.05.2026

### Upgrade procedure — REQUIRED

Patches `050_hash_oauth_tokens`, `051_hash_client_secrets`, and
`052_oauth_client_runs` invalidate every outstanding OAuth access /
refresh / authorization token and zero every `oauth_clients.client_secret`.
Atlas applies them silently as part of any routine `update.sh` /
`db_atlas_apply.sh apply` — so unattended jobs holding long-lived
tokens will start 401-ing immediately on deploy with no advance signal.

**Before you bump `FORMR_TAG`:** see
[`UPGRADING-v1.0.0.md`](UPGRADING-v1.0.0.md) for the audit + rotation
checklist (identify clients, schedule downtime, rotate secrets, restate
scopes + run allowlists, re-test). Skip it and you'll spend the next
24 hours fielding "the API stopped working" tickets.

### Added
- **Versioned RESTful v1 API** at `/api/v1/<resource>`. OAuth2 client_credentials grant with 1 hour access tokens. Resources: `user`, `surveys`, `runs/{name}`, plus per-run sub-resources `sessions`, `results`, `files`, `structure`. Twelve scopes (`user:read/write`, `survey:read/write`, `run:read/write`, `session:read/write`, `data:read`, `file:read/write`); scope is checked before resource lookup, so a token without the right scope returns 403 regardless of whether the run/survey exists or belongs to the caller.
- New admin level `2` ("API access"). Only users at `admin >= 2` can mint or use API credentials. Existing `admin = 1` accounts keep web-admin rights but lose API access until a SuperAdmin promotes them via the user-management page.
- One-time client-secret display at `admin/account` → API tab. Secret is shown only at issuance and rotation; storage holds a SHA-256 hash, so a forgotten secret must be rotated, not recovered.
- **Multiple labelled API credentials per user.** Patch `054_oauth_client_labels.sql` adds `oauth_clients.label` with `UNIQUE(user_id, label)`. The admin/account API tab is now a credential table — each row has its own scopes, run allowlist, rotate button, and delete button. A common pattern is one narrow read-only credential for a dashboard plus a broader credential for a cron job. The label `internal` is reserved for the auto-managed OpenCPU bridge credential and is hidden from the listing.
- **Unit-session history endpoint** `GET /v1/runs/{name}/unit_sessions`. One row per (participant × unit × iteration) — the complement to `/v1/runs/{name}/sessions`, which only exposes each participant's *current* unit. Rows arrive ordered by `(session, created, unit_session_id)`, so consecutive rows per participant are trajectory edges. Filters: `?session=`, `?testing=`, `?since=`; pagination via `limit` (default 1000, max 10000) + `offset`. Scope: `session:read`. R wrapper: `formr_api_unit_sessions()` in the formr package.
- **Trajectory-Sankey default for new runs' Overview script.** `RunUnit::getDefaults('OverviewScriptPage')` replaces the prior `plot(cars)` placeholder with a slim knitr template that calls `formr_overview_sankey()` from the formr R package. The helper pulls history via `formr_api_unit_sessions()`, collapses re-iterations to one node per position (so diary / longitudinal designs don't create cycles a Sankey can't draw), and surfaces the per-participant average visit count as an `(avg N visits)` label suffix when it exceeds 1. Top-to-bottom orientation by default. Terminal arrows route to `Completed` / `Expired` / `Active @ <position>`. New runs only — existing OverviewScriptPage bodies are not touched.

### Changed (BREAKING)
- **OAuth bearer credentials are now stored as SHA-256 hashes at rest.** On upgrade, patch `050_hash_oauth_tokens.sql` truncates `oauth_access_tokens`, `oauth_refresh_tokens`, and `oauth_authorization_codes`; patch `051_hash_client_secrets.sql` zeroes `oauth_clients.client_secret`. **All currently-issued tokens are invalidated** and **every existing OAuth client must mint a new secret** at `/admin/account#api` after upgrade. Plan a maintenance window for any unattended cron jobs that hold long-lived tokens.
- **API credentials must now explicitly request scopes and (optionally) be restricted to specific runs.** Patch `052_oauth_client_runs.sql` adds `oauth_client_runs(client_id, run_id)` (empty rows = unrestricted) and widens `oauth_clients.scope` to VARCHAR(2000); it also clears `oauth_scopes.is_default` for every row and wipes existing access/refresh/authorization tokens. After upgrade, users open the API tab at `admin/account#api`, pick the read/write scopes their credential should carry, optionally limit it to specific runs, and click **Create credential** / Rotate. Tokens minted under the old "all scopes" default no longer work.
- **Internal tokens (OpenCPU R-callbacks) now carry a per-token run allowlist.** Patch `053_oauth_access_token_run_ids.sql` adds `oauth_access_tokens.run_ids`. `OAuthHelper::createAccessTokenForUser($user, $scope, ..., $forRun)` stamps the column at mint time; `opencpu_prepare_api_access` passes the active `Run`. `ApiBase::allowedRunIds()` prefers per-token `run_ids` over the per-client `oauth_client_runs` allowlist, so a token minted to render run X cannot touch run Y even if the owner's client is unrestricted. External (`client_credentials` grant) tokens leave the column NULL and continue to use the per-client allowlist — back-compat.
- **`OAuthHelper` API reshaped for multi-client per user.** `createClient` now takes a `$label`; the old single-client lookups (`getClient(User)`, `refreshToken(User, ...)`, `deleteClient(User)`) are replaced by `listClientsForUser(User)`, `getClientForUser(User, $clientId)`, `rotateClient(User, $clientId, ...)`, `deleteClient(User, $clientId)`, and the emergency-revoke `deleteAllClientsForUser(User)`. Direct callers of the old API in this repo were migrated in the same commit; downstream integrations that subclass / call these helpers will need to update.

### Fixes
- **Settings chunk in `opencpu_knit_iframe` is now `include=FALSE`.** Previously the rendered chunk source was echoed in admin / test contexts (`echo=$show_warnings`), which leaked the `.formr$access_token = '…'` assignment that `opencpu_prepare_api_access` injects. Side effects (`opts_chunk$set` + variable assignment) still apply to subsequent user chunks.
- **`Page::render` (OverviewScriptPage admin path) now inlines the OpenCPU debugger on error.** The overview template renders alerts *before* calling `render()`, so the previous code path's `notify_user_error()` landed in an already-flushed buffer and the admin saw a blank Overview box. The error now appears as a red banner + collapsible `<details>` block with the full debugger output, in the spot the iframe would have rendered.
- **`notify_user_error` surfaces the error body in admin contexts too.** Gate broadened from `$run_session && (isCron || isTesting)` to `!$run_session || isCron || isTesting`. Real participants still see only the public_message; admin-context callers (no `$run_session`) and cron / queue daemons see the actual error. Matches the same logic that drives `$show_errors='TRUE'` in the OpenCPU helpers.
- **`SurveyResource::updateSurvey` tmp file cleanup.** Google-Sheet download is now wrapped in try/finally so the tmp file is removed on the `uploadItems` exception path too. Matches the pattern already used by `createOrUpdateSurvey`.
- **`OAuthHelper::createClientInternal` wraps the credential creation in a real transaction.** `Site::getOauthServer()` now passes the shared `Site::getDb()->pdo()` into `HashedTokenOAuth2StoragePdo`, so the stub `INSERT`, the `setClientDetails` `UPDATE`, and the `replaceClientRuns` writes commit or rollback as a unit. Previously the storage layer had its own PDO connection and a `setClientDetails` failure could leave a stub credential with empty scope/secret.

### Security
- **Strict identifier validation in `DB_Select::order()`.** The previous pass-through let any string flow into `ORDER BY` verbatim. A future caller threading a `?sort=` request param to `paginate['order_by']` would have been a SQLi sink (UNION-based row exfiltration). The new `parseStrictIdentifier()` accepts only `column` or `table.column` (with optional backticks); SQL function calls (`RAND()`, `COUNT(*)`, `COALESCE(...)`) now require the new `DB::raw()` escape hatch.
- **Parameterised the last interpolating SQL helpers.** `DB::like()` was discarding `PDO::quote()`'s return value and concatenating user input into a LIKE literal, which let any `name=` filter forwarded to the v1 API break out of the `WHERE user_id = ?` clause. Two `LIKE`/`session = '...'` sites in `UserHelper::getUserManagementTablePdoStatement` (superadmin `email` filter) and `SurveyStudy` (admin results `session` filter) had the same shape and are now bound through `:placeholder` with wildcard escaping.
- Operators are encouraged to upgrade and to rotate any OAuth client secrets after upgrading. A detailed advisory will follow once adoption is broader.

## [v0.26.2] - 13.05.2026
### Fixes
- `composer test` now passes `--exclude-group integration` (matches the bootstrap docstring + CI intent); new `composer test:integration` script runs only that group. Unit lane is green again (was 12 errors + 2 failures from MariaDB-only SQL hitting the SQLite :memory: bootstrap).
- `DB::table_exists()` validates the table-name argument against `/^[A-Za-z0-9_]+$/` and throws `InvalidArgumentException` on mismatch. Closes a SQL-injection sink (raw concat into `SHOW TABLES LIKE '...'`).
- `DB::whereIn()` returns `$this` for builder-API parity with `where()` / `like()` (latent bug — no production callers chained through it).

### Tests + docs
- `documentation/agent_doc/testing.md` catalogs the two PHPUnit lanes, every `@group integration` class, root cause + fix shape for the six deferred test cases, and the env-var bootstrap switch + GitHub Actions service-container sketch for a real-DB CI lane.

## [v0.26.1] - 13.05.2026
### Fixes
- Tighten `phpoffice/phpspreadsheet` composer constraint from `1.*` to `^1.30`, locking out 19 Dependabot-tracked CVEs (XXE, reflected XSS, SSRF, path traversal). Lockfile moves from 1.30.0 to 1.30.4.

## [v0.26.0] - 13.05.2026
### Fixes
- Daemon kill mid-cascade no longer causes a duplicate Email or Push send on restart (idempotency keys block the duplicate insert)
- `cron_only=true` Email units will start delivering after this upgrade. They were silently never sent due to a latent bug; audit affected studies before deploying.
- PushMessage now properly ends its unit-session after a successful send
- External unit-sessions ended via the API callback now write the same audit columns as the standard end path
- Push notifications no longer write two `push_logs` rows per send
- Push and External completions now mark the unit-session as ended (was previously left open). Affects analysis queries that filter on `ended IS NOT NULL`.

### Added
- New columns on `survey_unit_sessions`: `run_unit_id` and `iteration` (disambiguate the same survey reused at multiple positions, count back-jump / SkipBackward loops); `state` ENUM and `state_log` JSON (named lifecycle status alongside the legacy `queued` column)
- Admin queue inspector replaces the "To Execute" yes/no column with a named state badge and adds an iteration column.

### Schema
- Patch 047: schema additions on `survey_unit_sessions`, `survey_email_log`, `push_logs`
- Patch 048: one-shot backfill of `state`, `run_unit_id`, `iteration` for historical rows; idempotent (re-runs are no-ops)

### Tests + docs
- 6 new PHPUnit files (35 cases) covering the state column, idempotency keys, the cron_only gate, the Push state-transition, and the state_log JSON shape
- 3 live-MariaDB integration smokes under `bin/test_track_a_*_smoke.php`
- Refactor plan and state-machine diagrams moved to `documentation/agent_doc/`

## [v0.25.8] - 12.05.2026
### Fixes
- PushMessage save no longer errors "Message is required" when the message was typed into the editor.

### Tests
- `tests/e2e/push-message-save.spec.js` — logs into the dev admin, creates a throw-away run, clicks "Add Push Notification", types into the new unit's ACE editor via `ace.edit(el).setValue(...)`, clicks Save, and asserts no `.run_units .alert-danger "Message is required"` appears and the Save button settles back to a disabled "Saved". Best-effort cleanup deletes the run after. Failed pre-fix (`Received: 1` for the validation-error locator), passes post-fix.

## [v0.25.7] - 09.05.2026
### Fixes
- **Prevent duplicate cascade ("double expiry").** Observed in prod on AMOR 2026-05-09 at 10:03–10:11: 18 participants received 2× ESM email + 2× push notifications and ended up with two Survey unit-session rows from one Pause(124) anchor (one participant got four cascades within five seconds). Root cause: when a participant has the run open in two clients (PWA + browser tab) and the Pause's `expires` arrives, both clients fire `window.location.reload()` simultaneously. Both PHP requests construct their `RunSession` with cached `position=124` *before* either acquires the run-session named lock. Whichever wins the lock cascades through 124→127→128→129 and commits position=129; the second request, holding the lock afterwards, drives `moveOn` from its stale cached position=124 and creates a duplicate downstream cascade. Three guards:
  - `RunSession::execute` calls `reloadFromDb()` immediately after `acquireLock` so cached `position` / `ended` / `current_unit_session_id` reflect any UPDATEs committed by a concurrent request that won the lock first. Primary fix; closes the position-race entirely. `application/Model/RunSession.php`.
  - `Email::getUnitSessionOutput` and `PushMessage::getUnitSessionOutput` early-return when the unit-session row already shows a terminal send result (`email_sent` / `email_queued` / `sent` / `no_subscription` / etc.). Belt-and-braces: even if some other path re-executes a terminated row, no duplicate delivery. `application/Model/RunUnit/Email.php`, `application/Model/RunUnit/PushMessage.php`.
  - `ExpiryNotifier` auto-reload throttled to once per 30 seconds via a `localStorage` timestamp. Reduces redundant duplicate reload requests per client. `webroot/assets/common/js/components/ExpiryNotifier.js`.

### Tests
- `tests/e2e/double-expiry.spec.js` — D1 races two HTTP GETs through the run-session lock and verifies exactly one downstream cascade fires (failed pre-fix with 2 Endpage rows; passes post-fix). D4 exercises the `localStorage` throttle key.
- `tests/e2e/helpers/race.js` — `raceTwoGets` / `raceTwoGetsBehindLock` helpers fanning out two parallel `APIRequestContext` objects against the same run URL while a third process holds the named lock externally to make the bug deterministic.
- `tests/EmailPushIdempotencyTest.php` — 11 cases via `ReflectionClass::newInstanceWithoutConstructor` probing each guard's terminal-result list.

### Diagnostic
- `tests/e2e/prod_release_compare.sql` extended with `§J` (per-position duplicate-cascade count), `§J-dump` (per-row evidence for the top-3 offenders) and `§J-stale` (pre-Hygiene-4 ended-but-still-queued legacy debt). Use to verify the duplicate-cascade rate drops to zero post-deploy by re-running 7–14 days later.

## [v0.25.6] - 08.05.2026
### Fixes
- **Survey expiry algorithm rewrite** to match the [Expiry wiki spec](https://github.com/rubenarslan/formr.org/wiki/Expiry). The pre-fix code walked three rules (inactivity, start-window, grace) in fixed order with each *overwriting* the previous; the rewrite combines them per the wiki's pre/post-access formula (pre-access: `invitation+X`; post-access: `MIN(invitation+X+Y, last_active+Z)`). Eliminates the originally-reported bug where surveys with `X=60, Y=0, Z=0` expired participants who were actively editing. `application/Model/RunUnit/Survey.php`.
- **Cron stale-reference branch no longer advances the run.** When the queue daemon picks up a unit-session whose run-session has already moved past it, `RunSession::execute()` previously called `removeItem()` AND `moveOn()` — the moveOn cascaded `createUnitSession` calls past the participant's still-active unit, and the supersede side-effect orphaned that active unit's queue entry. Symptom A in the wild: `ended IS NULL, expired IS NULL, queued = -9` while the participant was mid-survey. Now drops the stale reference and stops; active unit-session preserved. `application/Model/RunSession.php:247-251`.
- **Supersede side-effect scoped to same `unit_id`.** `UnitSession::create()` flipped *every* queued sibling in the run-session to `queued=-9`, regardless of unit. The blanket scope amplified the cron-stale-reference orphan path and could clobber unrelated queued ESM Surveys during a moveOn cascade. Now scopes the supersede WHERE clause to `unit_id = $this->runUnit->id`, catching only genuine duplicates from back-jumps. `application/Model/UnitSession.php:66-70`.
- **`getCurrentUnitSession` excludes superseded siblings.** The query filtered on `ended IS NULL AND expired IS NULL` but not on `queued`, so once an active sibling's `ended` got set, ORDER BY id DESC LIMIT 1 returned the older `queued=-9` ghost. Adds `queued != -9` to the WHERE. `application/Model/RunSession.php:446`.

### Hygiene
- `UnitSession::end()` now resets `queued = 0` symmetrically with `expire()`. Pre-fix the asymmetry was masked by the queue daemon's `removeItem` post-end, but exposed in admin / dangling-end / participant flows — leaving `ended IS NOT NULL AND queued != 0` rows that the next `createUnitSession` would supersede.
- `UnitSession::end()` honours an explicit `$reason` argument for Survey/External (was hardcoded to `'survey_ended'` / `'external_ended'`). Fixes the audit-trail issue where the queue's run-session-ended path passed `'ended_by_queue_rse'` and got it silently overwritten.
- `getUnitSessionFirstVisit`/`LastVisit` now accept an optional bind-params array, so the `survey_items_display.saved != ...` WHERE clause uses a placeholder instead of string-concatenating `$unitSession->created`.

### Tests + docs
- 37-test e2e suite (`tests/e2e/{expiry-fixture,survey-symptoms,survey-expiry-matrix,survey-unfinished-pathways,survey-expiry-ui}.spec.js`) characterising the expiry algorithm, the four prod-reported symptom shapes, and the JS/UI drift surfaces. Drives via Playwright + a PHP fixture script (`bin/expiry_fixture.php`) and a diagnostic helper (`bin/expiry_compute.php`).
- `tests/e2e/EXPIRY_AUDIT.md` — 14-section audit document mapping every wiki↔code divergence, each Symptom-A/B/D pathway, and follow-up fix shapes. `tests/e2e/EXPIRY_PLAN.md` — fix-order rationale.
- `tests/e2e/prod_expiry_audit.sql` — 9-section diagnostic for re-running on the prod DB to verify orphan-count drop 7-14 days post-deploy.

### Internal
- `bin/queue.php` gains a `--once` flag (and `UnitSessionQueue::runOnce()`) for deterministic test driving — runs `processQueue()` exactly once, no daemon loop.

## [v0.25.5] - 07.05.2026
### Fixes
- iOS standalone PWAs: tapping a push notification now reloads the open PWA. The previous iOS-specific reload technique (`window.focus(); window.location.href = window.location.href`) was a no-op on iOS — `window.focus()` outside a user gesture does nothing, and assigning `location.href` to a byte-identical URL gets optimised away. Replaced with `window.location.reload()` (works on every engine).
- Stuck `handling-reload` flag in `PWAInstaller.js` is now self-recovering. The flag was only cleared in `DOMContentLoaded`, so any reload that didn't make it that far (BFCache transition, navigation cancelled, hidden-tab throttling, browser crash mid-reload) left it sticky and silently dropped every subsequent `NOTIFICATION_CLICK` / `STATE_INVALIDATED` message. The flag now stores `Date.now()` and is treated as stale after 10s.

### Service-worker upgrade plumbing
- `sw_version` bump to `v7`. Required so installed PWAs actually pick up the fix above — without a version bump the SW cache served the old `frontend.bundle.js` indefinitely.
- `install` handler calls `self.skipWaiting()` so a `sw_version` bump activates immediately rather than waiting for every PWA window to close.
- `activate` handler deletes every `formr-*` cache that isn't the current `CACHE_NAME`, so subsequent fetches go to network for fresh assets.
- `activate` handler broadcasts `STATE_INVALIDATED` to every claimed client, so pages running pre-fix `PWAInstaller.js` reload themselves and pick up the new bundle without a manual force-quit.
- `fetch` handler scopes `caches.match()` to `CACHE_NAME` (defence-in-depth — without this, an unscoped match falls back to any cache the browser holds, including older `sw_version` caches).
- `pwa-register.js` calls `registration.update()` on every page load when an existing registration is found, so future `sw_version` bumps reach iOS Safari standalone PWAs without relying on the browser's lazy 24 h check.

### Tests
- `tests/e2e/pwa-notification-reload.spec.js` pins the page-side reload contract on both local-chromium and BrowserStack iPhone 15 Pro Max iOS 17 (`npm run test:bs -- pwa-notification-reload`). Includes a regression test for the stuck-`handling-reload` failure mode.
- `npm run test:bs` now sources `../.env.dev` before exec so `BROWSERSTACK_USERNAME` / `_ACCESS_KEY` reach the SDK without manual `export`. New top-level `browserstack.yml` (single-platform iOS target).

## [v0.25.4] - 07.05.2026
### Added
- New runs default `expiresOn` to the configured retention maximum (`keep_study_data_for_months_maximum`) so admins don't hit the "you must set an expiry before going public" gate on first attempt. An info-level alert after run creation surfaces the date and links to the admin run settings page where it can be shortened. Behaviour is unchanged for deployments where the maximum is `INF` — `expiresOn` stays `null`.

### Fixes
- (CI) PHPUnit suite now runs against PHPUnit 11 + the no-DB CI: data providers made static (`ConfigTest`, `OpenCPUTest`), `DB::__construct` branches on `driver=sqlite` for tests, `tests/bootstrap.php` seeds the columns `Model::load`'s filters touch (`survey_studies`, `survey_users`), and the `utf8mb` typo (should be `utf8mb4`) in `config-dist/settings.php` is corrected — the latter was a real bug for any deployment using the distributed default verbatim. `DBTest` itself stays `@group integration` because it tickles MySQL-only helpers.
- (CI) `mkdir -p config` before seeding `config/settings.php` from `config-dist` so PHPUnit can bootstrap on a fresh checkout (`config/` is gitignored).

## [v0.25.3] - 06.05.2026
### Added
- PWA persistence — survive cookie eviction without losing the participant's session
  - Manifest endpoint personalises `start_url`, `id`, `shortcuts[].url`, and `protocol_handlers[].url` with `?code=<participant_session>` when an active RunSession exists, so iOS captures the tokenised URL into the home-screen icon at install time
  - Manifest `<link>` in run pages now emits the tokenised URL when the request has a participant context, falling back to the public clean manifest otherwise
  - Server-side cookie self-heal: a bare GET on the run URL with a cookie that resolves to a participant in this run 302s to `?code=<their_session>`, so the URL becomes the authoritative session identifier
  - Server-side recovery prompt rendered when the request lands at a run URL in standalone PWA shell with no resolvable session — replaces the silent auto-enrolment that previously created orphan participants
  - Client-side recovery banner detects standalone-shell + no `?code=` cold launches (the case where `_pwa=true` hasn't been replaced yet) and prompts the participant to paste their code; banner's HTML5 `pattern=` attribute derives from the configured `user_code_regular_expression` so client-side validation matches the deployment's actual code shape
  - New `user_code_html_pattern()` helper exposed via `window.formr.user_code_pattern` for any other code-entry surface
- Service worker hardening
  - `pushsubscriptionchange` handler reports the new endpoint to the server when browsers rotate the push subscription
  - `safeAddAll` cache pre-population: per-URL `cache.put` instead of `cache.addAll`, so a single 404 in the asset list no longer puts the whole SW into `redundant`
  - `pwa-beacon` POST endpoint at `/<run>/pwa-beacon` accepts up to 4 KB JSON and writes SW lifecycle failures (install, activate, fetch handler) to the formr error log with run name, capped UA, and remote IP — gives the maintainer a signal when an install fails silently in the participant's browser
- CI workflows in `.github/workflows/`
  - `test.yml` — PHPUnit on PRs and `master`/`develop` pushes, seeds `config/settings.php` from `config-dist/`, excludes `@group integration` (live-DB / live-OpenCPU / HTTP smoke tests) so default CI doesn't need the dev stack
  - `migrations.yml` — Atlas migrate-lint on PRs touching `sql/patches/**`, catches duplicate version prefixes, retroactive edits to merged patches, and destructive ops

### Fixes
- Push subscription cleanup: when web-push reports 404/410 from the push provider (browser uninstalled, permission revoked, iOS dropped the subscription), `PushNotificationService` rewrites the matching `survey_items_display.answer` to the sentinel `'expired'` and stops retrying that endpoint. Subsequent `PushMessage` units on the same session see no subscription and skip cleanly instead of looping retries against a dead endpoint
- `RunSession::getSubscription` now also skips the `'expired'` sentinel (alongside the pre-existing `not_requested` / `not_supported` / `ios_version_not_supported` filters)
- PWA installer no longer leaves the install button permanently disabled after an uninstall: the `pwa-app-installed` localStorage flag is cleared on a non-standalone load and the standalone branch is the sole authority for the installed state
- Asset cache: `pwa-register.js` now `await`s `navigator.serviceWorker.ready` before `postMessage(CACHE_ASSETS)` and posts on every load (not just first install), fixing two race conditions and a missing branch that left the asset cache empty for everything beyond what the install handler precaches from the manifest
- `pwa-register.js` now beacons SW install failures back to the server via the new `pwa-beacon` endpoint before the SW transitions to redundant

## [v0.25.2] - 29.04.2026
### Fixes
- Survey form validation messages render again. The dependency-bot bump to jQuery 3.7.1 in v0.25.1 broke webshim's bundled `jquery.ui.position` — `$(window).offset()` throws on jQuery 3 because window has no `getClientRects`, and that throw fired inside `validityAlert.show()` → `position()` while the popover was being placed, halting the show flow before `display:block` could be set. Webshim itself is unmaintained and not jQuery-3-compatible; pin back to jQuery 2.2.4 until webshim is retired.
  - Reverts the source-side `$.parseJSON` → `JSON.parse` and `$.isNumeric` → manual numeric check edits introduced alongside the bump (jQuery 2 still ships them, no functional change).
- `notify_study_admin` now logs failures via `formr_log_exception` instead of swallowing them silently, so admin-notification breakage shows up in `tmp/logs/errors.log`.

## [v0.25.1] - 21.04.2026
### Added
- Google Sheets survey update workflow
  - New "Update survey" button on the run unit view that re-imports items directly from the source Google Sheet (only shown when the study has no real users yet)
  - "Create new sheet" button on the add-survey page that opens a copy of the formr survey template
  - Surface survey expiration settings (`expire_invitation_after`, `expire_invitation_grace`, `expire_after`) on the run unit view
- Declarative Web Push support (RFC 8030, Safari 18.4+): payloads now include a `web_push`/`notification` object so iOS falls back to a native notification if the service worker fails, preventing Apple from terminating the subscription after ~3 "silent" pushes
- `SpreadsheetReader` now recognises `type_options` and `choice_list` as first-class columns and preserves author-supplied values instead of overwriting them from parsed `type`
- `optional` column accepts `1`/`0`/`true`/`false`/`yes`/`no` in addition to `*`/`!`
- Makes it easier to use a template for Google Sheets
- `class` column values are normalised (commas and runs of whitespace collapsed to single spaces)
- Compliance: Registration terms updated; cookie settings link added to footer

### Fixes
- Removed the old request-token CSRF mechanism
  - Removed `Session::REQUEST_TOKENS`, `getRequestToken()`, `canValidateRequestToken()` and per-form hidden token inputs
  - Fixes a bug where the CSRF cookie could end up in the URL
- PWA / push notifications on iOS
  - Service worker now `await`s `showNotification()` inside `waitUntil`, so iOS Safari no longer terminates subscriptions
  - Empty push payloads now show a fallback notification instead of being silently dropped
  - PWA installer auto-resubscribes when iOS spontaneously drops an active push subscription (if permission is still granted)
  - Guide users to install the PWA to home screen before attempting to subscribe on iOS Safari
  - `isSupported()` no longer requires `window.PushManager` (not reliably exposed on iOS)
  - PWA manifest generation now explicitly tells the admin whether cookie expiry was auto-extended to 1 year, and returns the manifest under a `manifest` key
  - Fixes session timeout handling and user-ID loss when the service worker is terminated (#654, #628)
- Pagination links in `PagedSpreadsheetRenderer` now build from `$_GET` instead of `array_diff_key($_REQUEST, $_POST)`, avoiding leaking cookie-derived params into page URLs
- Cookie consent: "manage cookies" button now calls `preventDefault()` so it no longer appends `#` to the URL
- Removed GDPR-problematic Zenodo DOI badge images on the About/Publications page; replaced with plain DOI links
- Improved Google Sheets integration: better error handling for invalid survey names extracted from Sheet filenames (#608); spreadsheet reader trims and normalises whitespace in the `class` column (#661)
- Misc dependency bumps: jquery 2.2.4 → 3.7.1, phpoffice/phpspreadsheet 1.29.9 → 1.30.0, webpack-dev-server, http-proxy-middleware, on-headers, compression, js-yaml

## [v0.25.0] - 20.04.2026
### Added
- Study-admin notifications: email the run owner when units fail
  - New `Notification` class with per-type throttling configurable via `$settings['notification']` (`default_throttle_minutes`, `throttle_map` for `error`/`warning`/`info`)
  - Notifications are logged to the new `survey_notifications` table and throttled per run + recipient + type
  - `notify_study_admin()` helper wired into OpenCPU rendering errors (`RunUnit`), Pause unit `relative_to` failures (both OpenCPU and invalid-result paths), External unit, Page unit, and survey-data save failures in `UnitSession`
  - New `templates/email/notification.ftpl` with colored severity border

### Fixes
- OpenCPU error messages for Pause, Page and External units now include the actual R error text in the log, and are forwarded to the study admin notification

### Schema
- SQL Patch 46: adds `survey_notifications` table


## [v0.24.13] - 03.03.2026
### Changes
- Improved configurability

## [v0.24.12] - 27.02.2026
### Changes
- Session-code collision/deletion handling tightened now that session-code length is configurable
- Removed an external dependency

## [v0.24.11] - 07.01.2026
### Fixes
- Bulk actions in the user overview could affect sessions across multiple runs if session codes were unexpectedly non-unique (possible with shortened custom session codes)

## [v0.24.10] - 22.11.2025
### Fixes
- Automated JavaScript expiry messages did not transmit the timezone to the browser, causing them to trigger incorrectly

## [v0.24.9] - 17.10.2025
### Fixes
- Second pass at transpiling JavaScript for older browsers (#630)

## [v0.24.8] - 16.10.2025
### Changes
- Stopped emitting separate CSS assets (overkill); bundled back into the main build

## [v0.24.7] - 16.10.2025
### Changes
- Webpack config adjusted to be more accommodating to old browsers (#629)

## [v0.24.6] - 15.09.2025
### Fixes
- Fix bug with security token error (#627)

## [v0.24.5] - 27.08.2025
### Fixes
- Use HTTPS wherever reasonable
- Fix an issue where long pauses overflowed the new interactive-modal pause timeout; disabled for durations longer than 27 days

## [v0.24.4] - 31.07.2025
### Fixes
- Fixes survey import via run (broken in v0.24.0)
- Fixes code/Rmarkdown download when testing
- Fixes redirect when run is accessed without trailing slash so query string is preserved
- Fixes expiry date for formrcookieconsent by redelivering the long expiry duration via HTTP (Brave/iOS limit to 7 days when set using JS)
- Fixes a problem with Google Spreadsheet on some servers

## [v0.24.3] - 19.06.2025
### Fixes
- Run omitted build step for material design.

## [v0.24.2] - 20.06.2025
### Fixes
- Fix material design

## [v0.24.1] - 19.06.2025
### Fixes
- Fixes the special item type defined by the class counter.

## [v0.24.0] - 24.05.2025
### Added
- Progressive Web App (PWA) support. 
  - Formr studies can now be turned into web apps that are installable to devices running Android, iOS, MacOS, Windows, etc.
  - Each study is its own app
      - Can be added to phone home screen
      - Service worker and configurable manifest endpoints for each run/study.
      - Logos, names, settings are configurable
  - Push message support in the run
  - Surveys get three new items: request_phone, add_to_home_screen, and push_notification which help configure the app
- Switch from grunt/bower to npm/webpack for clientside dependencies

### Fixes
- Cookies are now set to SameSite: Lax, so that cookies are always set upon first visit to the page
  - Fixed a bug where expired CSRF tokens caused confusing errors, will also give more informative error messages now
- New cookie management improves compliance with GDPR. By default, only session cookies are set, if user consents, these cookies are kept for longer (a configurable duration). formr continues not to set any third-party cookies by default.
- Unlinking surveys and hiding results works again


## [v0.23.2] - 07.02.2025
### Fixed
- It wasn't possible to specify a maximal file size for audio/video uploads

## [v0.23.1] - 04.02.2025
### Changes
- change paths for user uploaded files
  - make it easier to group user uploaded files in tmp. also, store full paths.

## [v0.23.0] - 23.01.2025
### Added
* Added two-factor authentication (2FA) thanks to groundwork by @EliasAhlers and @Epd02
  * 2FA is now enabled by default
  * 2FA can be made required for all users
  * The formr R package now supports 2FA
* Runs/Studies can now be exported noninteractively
  * This enables a new R package function `formr::formr_backup_study()` which can be used to export runs/studies, all user data, and all user uploaded files
* Authentication was improved
  * Minimal wait times to avoid timing attacks and brute force attacks
* Process runs that need to be reminded or deleted (thanks to @eliasheithecker for some groundwork) for simpler compliance with GDPR and other regulations
  * Autodeletion is not turned on by default, but can be required in settings.php
  * We loop over the reminder intervals and process the runs that need to be reminded or deleted.
  * Reminders are sent 6, 2, and 1 month(s) and 1 week and 1 day before expiry.
  * To avoid spamming, we only send a reminder if the run has not received a reminder in the last 6 days.
  * If the study owner has received 2 reminders and the first reminder was at least two weeks ago, we delete the run data.
  * The expiry routine is configured in such a way that run data may not be deleted on the day of expiry if the study owner was not given sufficient notice (e.g., because of problems with the email server or because they recently changed their expiry date).
* Orphaned files which were uploaded within a survey are now automatically deleted every night.

### Fixed
* User account deletion is now working again
* link to ToS on signup page was incorrect

## [v0.22.0] - 01.10.2024
## [v0.22.0] - 19.12.2024
### Fixed
* superadmin OpenCPU timing graph
* bug where (backup) server-side errors for invalid items weren't displayed
* issues with file uploads in the survey where error messages were not displayed, could be cryptic
* maxlength for textarea items was not respected
* fixed an issue where a minimum of 0 for number-type inputs was not respected

### Changed
* when you upload a survey from a Google spreadsheet, the name of a survey is now automatically read from the spreadsheet file. The name set in formr has to match the Google spreadsheet name to ensure consistency
* documentation has been updated for item types, on how formr auto-enriches data in R code etc. In addition, documentation is available in more places.

### Added
* compliance work
  * added special user-facing static pages for privacy policy and terms of service
  * added an option to require that a privacy policy exists before studies go public
  * improved default footer text/imprint to include admin email address, links to privacy policy, ToS, settings, make referral tokens optional
  * added setting for extended agreements to conditions when uploading files in runs
* audio type items, including `record_audio` class for a recorder button
* video type items
* the submit button item now allows for negative "timeouts" — i.e. the user has to wait until they can submit 

## [v0.21.4] - 10.07.2024
### Fixed
* bug fix for default session code regex

### Added
* implement JS changes for material design too
* default to exporting items when exporting run JSONs
* all newly created surveys have a default field "iteration" which is simply an auto-increment number from 1 to number of responses to survey

## [v0.21.3] - 21.06.2024
### Added
* autoset timezone for timezone inputs
* make user id/session code length flexible/configurable
* webshim number inputs to make the regional number formatting configurable

## [v0.21.2] - 02.06.2024
### Fixed
* bug fix (minify changed JS correctly)

## [v0.21.1] - 01.06.2024
### Added
* simplify integration with labjs et al by 
  * not changing file names on upload
  * allowing larger amounts of data to be stored in text fields
  * allowing uploadable file types to be configurable
* add Reply-To option for email accounts
* allow default email accounts to be configured in settings.php, Reply-To defaults to admin email address
* allow superadmins to manually set admin account email addresses as verified


## [v0.21.0] - 07.03.2024
### Fixed
* fixed broken redirects to the login page
### Added
* make it easier to dockerise formr
  * added a setting to send error logs to stderr
  * adapted OpenCPU handling to make it possible to POST (run R commands) to a different URL (e.g., inside a docker network) than where we GET results (e.g., render user-facing feedback). If the old setting base url is used, it should be used for both POST and GET.
* improve cookie handling, 
  * formr now works similarly, whether you use study-specific subdomains or not. 
  * cookies are now always valid only for the specific domain on which they were set. 
  * we now recommend hosting the admin area on a different subdomain than the studies, not on the top level domain.
  * removed redundant settings related to cookies from settings.php
* track bower_components to make it easier to collaborate on changes in CSS/JS
* update to halite 5

## [v0.20.8] - 29.11.2023
* remove outdated instructions for self hosting

## [v0.20.7] - 02.05.2023
### Fixed
* Adding SMTP accounts that do not support password
### Added
* User account deletion

## [v0.20.6] - 02.05.2023
### Fixed
* Display a warning message for orphaned run units and enable deletion.
* Other minor bug fixes

## [v0.20.5] - 20.10.2022
### Added
* User search by email in admin
* User deletion

### Fixed
* Various bug fixes

## [v0.20.4] - 13.09.2022
### Fixed
* Restart database transactions in case of lock wait timeout or deadlock.
* Check for orphan unit sessions before executing
* Deprecation warnings

## [v0.20.1] - 04.09.2022
### Fixed
* Deprecation warnings.

## [v0.20.1] - 03.09.2022
## [v0.20.0] - 03.09.2022
### Added
* *Require PHP 8.1 or greater*
* Page content configuration (some menu pages can now  be hidden and footer links / logo can be changed)
* Branding configurability.

### Changed
* Re-factor queue-ing mechanism (run units should instruct run session on the next steps)
* Bug fixes

