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
structurally unindexable in the reverse direction — patch 061's
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

## Also noted (no action)

The reviewer explicitly cleared `FixedWindowRateLimiter`: the WIP
change there is a net win (one SELECT per hit removed;
`LAST_INSERT_ID()` is connection-local; the cleanup DELETE is
bounded and index-served).
