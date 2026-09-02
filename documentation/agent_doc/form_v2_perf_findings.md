# form_v2 performance findings — partial code review, 2026-08-09

Provenance and caveat: these came out of a `/code-review` run that was
*meant* for the v1.7.1 hotfix branch but ran against this working tree
(feature/form_v2 **including then-uncommitted WIP**) instead. The
review was killed mid-run by a session usage limit — only the
efficiency-finder angle completed, and the adversarial verify pass
never ran. So: **five plausible, unverified candidates**, not
confirmed defects. Triage them when finishing the WIP they touch;
line numbers are as of 2026-08-09 and will drift.

## 1. Long-only reconcile scans the display table nightly — HIGH if real

`application/Helper/StudyMetrics.php:188` (uncommitted WIP at review
time). The long-only branch of the reconcile counts completed
sessions via

    SELECT sid.session_id FROM survey_items_display sid
    JOIN survey_items i ... WHERE i.study_id = :study_id
    GROUP BY sid.session_id

`survey_items_display`'s secondary indexes are `(session_id,item_id)`,
`(item_id)`, `(session_id,saved)`, `(page)` — nothing covers
`item_id → session_id`, so every display row of the study takes a
clustered-PK lookback, then a GROUP BY temp table sized #sessions.
The shape was copied from `SurveyStudy::getResultCountLong()`
(~:1524), where it runs once per admin page view for one study; the
WIP promotes it into `reconcileAll` (~:161), which loops **every**
study with a results_table **nightly**. On an instance with
`form_v2_dual_write_results=false` that is a random-order traversal
of the largest table in the schema every night — buffer-pool
thrashing during the backup window. The wide branch it replaces read
one row per session from the per-study results table.

**Suggested shape:** drive from `survey_unit_sessions` by unit
linkage — `us.unit_id IN (SELECT id FROM survey_units WHERE
form_study_id = :study_id UNION SELECT :study_id)` (v1 quirk: Survey
unit id = study id; served by the unit_id FK index) — with an
`EXISTS(SELECT 1 FROM survey_items_display sid WHERE sid.session_id
= us.id)` probe. O(#sessions) index probes, no temp table, same
"session has ≥1 display row" semantics.

## 2. `getAllSurveys` COALESCE join is one plan-flip from a full scan — HIGH if real

`application/Model/Run.php:~1121`. `getAllSurveys`/`getAllLinkedSurveys`
now join `survey_studies.id = COALESCE(survey_units.form_study_id,
survey_units.id)`. The query is hot: `getRunData` caches only
per-request, so this fires for every distinct R expression evaluated
(showif batches, Branch conditions, Pause relative_to, email
bodies). The plan is fine only while the optimizer drives
runs → run_units → units(PK) → studies(PK). The COALESCE is
structurally unindexable in the reverse direction — patch 079's
`form_study_id` index cannot serve it — so a mispick (stale stats,
small-table heuristics) degrades to a full `survey_units` scan
(all units, all users) per study row per R evaluation. On a large
instance that is seconds of DB time per participant page load.

**Suggested shape:** UNION of two independently-indexed queries (one
on `survey_run_units.unit_id = survey_studies.id` for the v1 quirk,
one on `survey_units.form_study_id = survey_studies.id` for Forms);
UNION DISTINCT on study id also deletes the PHP `dedupeStudyRows`
pass. At minimum, EXPLAIN the current form on a production-sized
dataset before shipping.

## 3. Push-notification item init runs twice, and the guard sits after the awaits — MEDIUM

`webroot/assets/form/js/main.js:~1583`. The init selector
`'.push-notification-wrapper, .form-group.item-push_notification'`
matches both the inner wrapper and its ancestor form-group, so the
async callback runs twice per item. The `fmrPushBound` guard is at
step 6 (~:1706), after the awaits — both visits fully execute steps
1–5 concurrently: 2× `serviceWorker.ready`, 2× `getSubscription()`,
2× `renderNotificationControls` (the second innerHTML replacement
discards the first run's freshly-bound listeners), and in the iOS
dropped-subscription recovery branch (~:1665–1678) **two racing
`pushManager.subscribe()` calls** plus duplicate POSTs to
`ajax_save_push_subscription` — a race on exactly the platform the
comment says drops subscriptions.

**Fix:** hoist the guard to the top of the callback (it runs
synchronously there, so the second visit bails before any await), or
dedupe the NodeList per form-group:
`new Set([...nodes].map(n => n.closest('.form-group') || n))`.

## 4. Same double-visit in add-to-homescreen init — LOW

`webroot/assets/form/js/main.js:~1253`. Same selector pattern; the
`fmrAtsBound` guard (~:1272) only dedupes the click listener. Still
doubled: `markAddedToHomeScreen` DOM writes, `setCustomValidity`,
querySelectors. All synchronous and small today, but anything added
above the guard silently doubles too. Same one-line fix as #3.

## 5. Long-only completion hook re-reads what PHP already holds — MICRO

`application/Model/UnitSession.php:~607` (uncommitted WIP at review
time). After `end()`'s UPDATE, `StudyMetrics::recordCompletionLong`
SELECTs `TIMESTAMPDIFF(SECOND, created, ended)` back by PK — one
extra synchronous round trip on the participant's final-submit path.
Both operands are in-process (`$this->created`, and `ended` is now).
The mirrored wide-table pattern existed to fetch timestamps PHP
didn't have; that doesn't apply here. `max(0, time() -
strtotime($this->created))` feeds `LN(GREATEST(seconds,1))` in a
geometric mean, so sub-second PHP/DB clock skew is immaterial. Keep
the query only if DB-clock purity is wanted — then note it as the
deliberate trade.

## Appendix — 2026-08-11 full review (10 finders → 11 verifiers)

Second `/code-review` that was aimed at a release branch and again ran
against this tree, so again: unverified-by-me candidates about form_v2,
not about the branch that was asked for. This pass DID run its verify
phase. Ranked; triage before shipping form_v2.

**Release blockers**

1. **Patch numbering divergence** — this branch's 070–085 assign
   different content than the release line's 070. Atlas keys pending
   work by version number, so a v1.7.x/1.8.0 host upgrading here skips
   every form_v2 schema patch (no `survey_r_calls`,
   `survey_form_submissions`, `rendering_mode`, `form_study_id` → v2
   endpoints fatal) and then hard-fails at `073_unit_session_execution_time`
   with error 1060, half-migrated. Renumber form_v2's patches above the
   release line's highest applied version (precedent: `8d60ae4e`). Note
   v1.8.0 adds `071_email_retry_state.sql`, so renumber above that too.
2. **`sql/schema.sql:674` folds `survey_r_call_results` in its
   PRE-067 shape** — no `unit_session_id`, old `uq_call_args
   (call_id, args_hash)`. Fresh installs are baselined past 067 without
   running it, so `RCallService::cachedResults` throws "Unknown column"
   on every `/form-r-call` and `/form-fill`, and the key structurally
   reinstates the cross-participant cache leak 067 closed.

**Correctness**

3. `webroot/assets/form/js/items/auto-submit.js:107` — in solo layout the
   timed fuse routes to `soloController.onContinue()` (advances ONE step)
   but the timer is one-shot, re-armed only on `fmr:pagechange`, which
   intra-page step changes never fire → timed pages never auto-submit;
   an unanswered current step swallows the single firing entirely.
   `submit_on_all_answered` burns the same way.
4. `main.js:459` — the `status==='errors'` guard `return`s out of the
   whole drain, and entries drain sorted by `client_ts`, so one
   permanently-rejected entry head-of-line blocks every later queued
   submission forever (queue is origin-wide, so other runs starve too).
   Should `continue` past it, or lane per `sync_url`.
5. `main.js:1114` — `addBrowserSwitchUI` builds its Open-in-Safari /
   copy-link targets from raw `location.href`, which the client has
   already rewritten to `?page=N`, dropping `?code=`. Following the
   escape hatch mints a NEW anonymous session and orphans the answers.
   `window.formr.runResumeUrl` already exists (see `request-phone.js:23`).
6. `main.js:137` — `completing` gates only `showPage`; auto-submit timers
   are never disarmed on completion and `submitPage` has no such gate, so
   a live fuse can fire after the final submit → 409 `stale_session` →
   `location.reload()` cancels the committed redirect.
7. `Form.php:27` — `Form::create` accepts a caller-supplied `study_id`
   with only an existence check, no `isOwnedBy` (Survey::create has one).
   A crafted admin POST links another user's study into the attacker's
   run; participants then WRITE into the victim's `results_table` and
   `survey_items_display`. Read surfaces re-scope, so this is dataset
   contamination, not a read leak. Mirror Survey's ownership gate.
8. `RunSession.php:981` / `Email.php:121` — the Form-linkage fix to
   `Run::getAllSurveys` was not propagated: `getRecipientEmail` still
   hard-filters `survey_units.type='Survey'` and the recipient-field
   query still joins on the v1 shared-PK convention, so Form-delivered
   surveys resolve no recipient (email silently fails) and the admin
   dropdown is empty.
9. `SurveyResource.php:85` / `ApiBase.php:357` — same un-propagated
   linkage: management/access surfaces 403 a Form-linked study while
   `/results/<run>` returns its participant data.
10. `StudyMetrics.php:70` + `:186` — `recordCompletionLong` measures
    unit-session `created`→`ended`, not the wide table's first-render
    `created`, so a diary study's "time it takes" jumps from minutes to
    hours on flipping to long-only; the nightly reconcile bakes the skew
    in. Closer analogue: `MIN(survey_items_display.shown/created)`.
11. `FixedWindowRateLimiter.php:49` — `:now2 - window_start` on an INT
    UNSIGNED column: a future `window_start` (NTP step-back, snapshot
    restore) raises error 1690, the catch fails open, and the row cannot
    self-heal. One-token fix: `CAST(window_start AS SIGNED)`.
12. `config-dist/settings.php:421` — the new `metrics_reconcile_enabled`
    comment promises study counts still self-maintain, but
    `RunMetrics::reconcile()` returns at line 37 before reaching
    `StudyMetrics::reconcileAll()`. Gate only the run rollup, or fix the
    comment.
13. `templates/admin/run/units/survey.php:13` — the escaping fix applied
    to `form.php:23` was not applied to its v1 sibling, which still emits
    `<?= $study->name ?>` unescaped in the run editor.
14. `main.js:1052` — `isIOSSafari` tests for a `Brave` UA token Brave
    never emits (v1 used `navigator.brave.isBrave()`), and `isIOSDevice`
    misses iPadOS 13+ (Macintosh UA), so the escape hatch never renders
    for two of its target audiences.

**Refuted during verification:** attribute-injection XSS via
`location.href` (modern URL serializers percent-encode `"`); a
cross-user *read* leak via the new `getAllSurveys` join (every read
surface re-scopes — the residual is the write-side gap in #7).

## Also noted (no action)

The reviewer explicitly cleared `FixedWindowRateLimiter`: the WIP
change there is a net win (one SELECT per hit removed;
`LAST_INSERT_ID()` is connection-local; the cleanup DELETE is
bounded and index-served).
