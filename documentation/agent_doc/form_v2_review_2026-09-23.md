# form_v2 review — 2026-09-23 (resumable notes)

Scope: `git diff v1.11.0...HEAD` on `feature/form_v2` (release-line merges
excluded by diffing against the last merged tag). App layer ~14k lines,
114 files. Review is recall-oriented: bugs, doc mismatches, scope mismatches.

## Progress (tick as each area is done)

- [x] RunController v2 endpoints (form-page-submit, form-sync, form-fill, form-render-page, advancePastPage)
- [x] RCallService
- [x] FormRenderer
- [x] Form RunUnit + SurveyStudy + UnitSession deltas
- [x] FormSyncPayload, RAllowlistExtractor, FormV2CompatScanner
- [x] Item deltas (BotCheck, Rank skimmed) (Rank, BotCheck, VAS, File, Item.php)
- [x] JS bundle (main.js submit/queue/resolve, offline/queue.js, showif/alpine.js)
- [x] Patches 082–097 drift tolerance
- [x] Docs vs code (CLAUDE.md form_v2 notes, plan_form_v2.md, form_v2.md)

## Candidate findings (raw, pre-dedup)

(append as found: file:line — summary — scenario)
- main.js:776-787 collectAllAnswers coerces every numeric-looking string via Number() ("07743"→7743, "0x10"→16, " 7"→7) before /form-render-page; RCallService::formatRValue's column-typed quoting then quotes the already-mangled number → text answers reach R retyped. Defeats RCallService.php:225-230 intent.
- main.js:718-726 deferred value fill writes to the wrapper's FIRST named input; Mc/SelectOne render a hidden placeholder `<input type=hidden value="">` first (Mc.php:56, SelectOne.php:20), Check a hidden value="0" (Check.php:60) → `value`/`sticky` on mc/select/check never preselects the visible control; for optional mc the hidden value is posted silently as an answer.
- main.js:495 queue drain sorts by second-resolution client_ts; same-second entries fall back to IDB key (random uuid) order → page N+1 can apply before page N; advancePastPage then runs computeCalculations against data missing page N and stores them (saved) permanently; also breaks "rejected page halts later pages" invariant.
- FormRenderer.php:349-366 getAllUnansweredItems doesn't SELECT survey_items.showif_js → the patch-088 cache (Item.php:313) is never hit on the v2 render path; CLAUDE.md claims v2 "prefers the import-time cached transpile". Every v2 render re-runs the regexes and emits the /*sjv1*/ stamp.
- RunController.php:776 / :1311-1315 comments claim "0 (older clients) accepted leniently" / "Older queue entries from before this gate landed" — v2 never shipped, so no older clients exist (memory: describe as it ships); leniency lets any client omit unit_session_id to bypass the stale-tab gate.
- RunController.php:882-899 formSave doc comment carries dated spec-evolution history ("F1, 2026-09 ... used to call with validate=false").
- RunController.php:1132-1139 findNextRenderablePage counts rows the renderer never shows: the page's `submit` row (never saved — <button> isn't collected), `mc_heading` (excluded from v2), soft-deleted items. (a) advancePastPage's calc-only skip never fires (docs form_v2.md:121 claim false) → blank page with only Next; (b) when the renderer dropped a page (all answered via autosave then reload, or only deleted items left) server returns next_page the DOM lacks → main.js:1075 nextIdx -1 → falls through, no nav, no error: participant stuck on "Saved".
- Form.php:79-83 Form::create flips linked study to rendering_mode v2 without converting its plain-Survey placements (AdminSurveyController::switchRenderingModeAction does); RunController::formV2Study accepts any `instanceof Survey` → v1 Survey units of that study accept /form-save|/form-page-submit writes that skip v1 gating; v1 Survey never renders v2.
- UnitSession.php:1198-1222 + BotCheck: bot_check token burned in validation loop before other items' errors are known → any other validation error on the page burns the token; resubmit gets "verify you are human" while widget still shows verified (bot-check.js has no reset).
- main.js:1245-1249,408-412 comments claim Background Sync drains when tab closed / interleaved SW drains; SW has no sync listener (service-worker.js:353 handles CACHE_ASSETS only).
- showif/alpine.js:16-19,283-289 comments: "server already resolved" run vars — server no longer evaluates showif.
- CLAUDE.md "The `value` column is the R path…" bullet says first-page values resolve server-side at render + stale main.js:480-547 refs; contradicts the "v2 runs no R at render" bullet and FormRenderer rule 2.
- CHANGELOG.md [Unreleased] still narrates v2 evolution with now-false claims (l.42 SW sync handler; l.54-55 first-page server resolve; l.41/48/111 /form-r-call endpoint that no longer exists; l.20 unknown→visible).
- RunController.php:1686-1688 "DB-keyed bucket (patch 076)" → rate-limit table is patch 095; main.js:357 "pre-patch-051", solo/controller.js:4 "patch-066" stale.
- Reuse: RCallService::rTypeForResultField duplicates UnitSession::getLongReadCasts type parse; $_FILES reprojection duplicated formPageSubmit/formSync; hidden-id extraction duplicated.
- Efficiency: FormRenderer::renderPageNav rebuilds FormStrings::table per page (render() already has $strings).
- (sweep) RunController.php:1166 markClientHiddenItems `d.saved IS NULL`: an answer autosaved (/form-save) before a showif change hid its item is never retracted — stays in survey_items_display + wide table although alpine.js _onShowifToggled "retracts" it and v1 would store NULL.

## Deduped + ranked (phase 2/3 done) — fix status

1 findNextRenderablePage vs renderer page model (+ main.js no fallback) — FIX
2 autosaved answer of later-hidden item kept — FIX
3 queue drain same-second ordering — FIX (monotonic seq)
4 collectAllAnswers Number() coercion — FIX (send strings)
5 deferred fill writes hidden placeholder (mc/select/check) — FIX (choice-aware fill)
6 bot_check burned despite other errors — FIX (validate side-effect items last)
7 Form::create flips study, formV2Study accepts Survey — FIX (require Form)
8 showif_js cache unused on v2 path — FIX (SELECT column)
9 CLAUDE.md stale value/first-page bullet — FIX
10 CHANGELOG false v2 claims — SKIP (editorial rewrite for author)
11 stale comments (Background Sync, server showif, older clients, patch numbers) — FIX
12 $_FILES reprojection duplicated — FIX (helper)
13 renderPageNav FormStrings per page — FIX

## Status (end of pass)

Fixes applied to working tree (uncommitted): 1–9, 11–13. Verified: PHPUnit unit
lane 593/593 green; bot_check / batch_update / survey_test_two_tabs smokes
green; new SQL checked on MariaDB; dev bundle rebuilt; e2e v2-dynamic,
crosspage-showif, v2-finish, required-gating (12/12) green.
Skipped: 10 (CHANGELOG [Unreleased] narrates v2 evolution with false claims —
needs the author's editorial rewrite); Form::create still flips the study to
v2 without converting its Survey placements (now harmless for the v2
endpoints; whether to auto-convert is a product call); admin "Test Survey"
previews v2 studies through v1 (Run::testStudy builds a Survey) — scope gap,
not fixed.
