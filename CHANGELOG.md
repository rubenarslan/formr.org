# Formr.org Change Log (check previous change logs in CHANGELOG-v1.md)

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]
### Added
- form_v2 Phase 0 (plumbing): new `Form` RunUnit type gated behind `$settings['form_v2_enabled']` (default `false`); when enabled, an "Add Form" button appears in the admin run editor alongside the existing unit types. Form keeps its own `survey_units` row (unlike Survey, which shares one with its study) and references its `SurveyStudy` via `survey_units.form_study_id`. Creating a Form stamps `rendering_mode='v2'` on the linked study. See `plan_form_v2.md`.
- form_v2 Phase 1 (single-page AJAX form):
  - New `FormRenderer` (`application/Spreadsheet/FormRenderer.php`) extends `SpreadsheetRenderer`, emitting all items inside `<section data-fmr-page>` wrappers with a BS5-flavoured form header and page-nav buttons.
  - New client bundle `webroot/assets/form/` (Webpack entry `form`) built from Alpine.js 3 + Bootstrap 5 (scoped via `bootstrap5` npm alias so admin BS3 is untouched) + Font Awesome 6 + Tom-select. Handles page navigation, item-view timing, and per-page AJAX submission; no jQuery, no webshim.
  - New view `templates/run/form_index.php` loads only the form bundle (distinct from the v1 `run/index.php` asset set).
  - New endpoint `POST /{runName}/form-page-submit` (`RunController::formPageSubmitAction`) accepts JSON `{page, data, item_views}`, saves via the same `UnitSession::updateSurveyStudyRecord` path v1 uses, returns JSON for the client to act on.
  - `Run::exec` and `RunSession::executeUnitSession` pass a `use_form_v2` flag through so the controller can pick the right view.
- form_v2 Phase 2 (item-type coverage):
  - Multipart file upload path: client auto-switches from JSON to `FormData` when the current page has a non-empty `<input type=file>`; `RunController::formPageSubmitAction` branches on Content-Type, reads `$_POST + $_FILES`, and re-projects `$_FILES['files'][name|type|tmp_name|error|size][itemName]` into the flat shape `File_Item::validateInput` expects. JSON path unchanged.
  - Button groups without webshim or jQuery: vanilla `initButtonGroups()` wires `.btn[data-for]` clicks to their paired hidden input (radio: clear siblings; checkbox: toggle independently) and fires `change` so showifs re-evaluate. `invalid` events on the hidden required inputs surface the browser's localized `validationMessage` as an inline `.fmr-btn-feedback` beside the visible button group. `.js_hidden { display:none !important }` re-asserted in `form.scss` (v1's frontend bundle shipped this globally; v2's scoped form bundle didn't). Covers mc_button / mc_multiple_button / check_button and their rating/scale button variants.
- form_v2 Phase 3 (client-side `showif` + `r(...)` opt-in):
  - `showif` is now client-side JS by default. `FormRenderer` forces `data-showif` on every item with a non-empty `showif` (v1 only emitted it when the server had hidden the item). Alpine 3 drives reactivity via a `fmrForm` data component + `x-showif` directive; the bundle promotes `data-showif` → `x-showif` at init so no Item.php changes are needed.
  - Standard-library helpers injected into every showif eval context: `isNA`, `answered`, `contains`, `containsWord`, `startsWith`, `endsWith`, `last`. v1's `(typeof(X) === 'undefined')` regex-transpile output is rewritten to `isNA(X)` client-side, since `collectAnswers` normalizes empty inputs to `null`, not `undefined`.
  - Runtime eval is wrapped in `(()=>{try{…}catch(e){return undefined}})()` so references to unknown names (run-level vars like `ran_group`, items on future pages) silently fall back to undefined (→ visible) instead of throwing ReferenceError every keystroke. Comment-stripping (`//`, `/* */`) before eval prevents v1's `//js_only` marker from commenting out the wrapping closing paren.
  - `r(...)` opt-in for showifs the JS transpiler can't translate. `RAllowlistExtractor` unwraps the top-level `r(...)` wrapper and `FormRenderer::processItems` UPSERTs the inner R into `survey_r_calls` (dedup by `study_id + expr_hash + slot + item_id`). The wrapper is emitted with `data-fmr-r-call="{id}"`; the client POSTs `{call_id, answers}` to `POST /{run}/form-r-call` debounced 300ms with seq-guarded stale-response protection. No R source ever reaches the client.
  - `bin/form_v2_compat_scan.php <study_id|study_name>`: CLI that classifies every non-empty `showif` / `value` as empty / r-wrapped / JS-OK / needs `r(...)` wrap. Heuristic scans the post-transpile expression for R-only tokens (ifelse/c/tail/paste/is.na/%in%/NA/`<-`/`$`-access). Exits 0 if clean, 2 if flagged — usable as a CI gate. Informational only, doesn't mutate `survey_items.showif`.
- form_v2 Phase 4 (deferred fill for `r(...)`-wrapped `value` columns):
  - `FormRenderer` detects `r(...)` on the `value` column, unwraps via `RAllowlistExtractor` with `slot='value'`, clears `$item->value` so the v1 OpenCPU batch skips it (`r()` isn't an R function; passing the wrapped string torches the whole batch), and emits `data-fmr-fill-id`.
  - New endpoint `POST /{run}/form-fill` (`RunController::formFillAction`) resolves one `{call_id, answers}` once on page load. Shared helper `evaluateAllowlistedRCall($id, $slot, $answers)` with `/form-r-call` enforces slot match so a showif call_id can't be used as a fill and vice versa.
  - Client fill resolver sets the first named `input/textarea/select` inside the wrapper — only if empty, so back-navigation doesn't clobber user input — then fires `input + change` so showifs re-evaluate. On OpenCPU error the wrapper flips to `.fmr-fill-error` with inline feedback.
- form_v2 Phase 5 (page-lifetime offline queue for JSON submissions):
  - IndexedDB store `formrQueue` (one object store `queue`, keyPath `uuid`, index `client_ts`) persists failed `/form-page-submit` JSON posts with a client-generated RFC 4122 UUID and shows a `.fmr-queue-banner`; the participant advances locally.
  - New endpoint `POST /{run}/form-sync` (`RunController::formSyncAction`) accepts one entry, dedups via `survey_form_submissions.uuid` pre-check + UNIQUE constraint backstop, and applies through the same `UnitSession::updateSurveyStudyRecord` path as `/form-page-submit`. Regex-validates UUID shape and enforces MySQL `DATETIME` format on `client_ts` (not ISO-8601).
  - Drain triggers: `online` event + initial page-load check. Success → delete entry; empty queue + `redirect` in final response → follow it; server `drop_entry` → stop retrying; validation error → surface `.alert-danger` banner.
  - Not yet: service-worker interception, Background Sync, file-blob queueing, iOS Safari pass. Multipart/file pages currently alert "offline" without queueing.
- form_v2 per-study admin flags:
  - `offline_mode` (default on) — when off, the v2 client skips IndexedDB queueing on network failure and surfaces a hard error instead. Rendered as `data-offline-mode` on the form root; client treats the sync URL as empty when off so both the submit-path queue hand-off and the drain-on-load no-op.
  - `allow_previous` (default off) — when on, the "Previous" page-nav button is rendered on non-first pages so participants can navigate backwards.
  - Both toggles appear in the admin survey settings page (`/admin/survey/<name>`) under a new "Form_v2 settings" section, visible only when the study is on the v2 pipeline (`rendering_mode='v2'`).
  - `SurveyStudy::toArray` now includes `rendering_mode`, `offline_mode`, and `allow_previous`; without the additions, `$study->update($settings)` silently dropped the new columns because `Model::save()` writes only what `toArray()` returns.
- form_v2 unverified-item-type notice: when a v2 form contains `audio` or `video` items, a `.fmr-unverified-types` banner is rendered above the form header noting that the capture UX hasn't been end-to-end smoke-tested. Soft notice — items still render and submit through the same multipart path as `File_Item`.
- form_v2 UI: two-column label-left layout (260px right-aligned label column + flex-grow controls column, stacks under 768px); slim green sticky progress bar with "Page N of M" right-aligned; v1's admin-choosable layout classes (`mc_width*`, `rotate_label*`, `mc_vertical`, `mc_block`, `rating_button_label_width*`, `hide_label`) re-activated by importing `webroot/assets/common/css/custom_item_classes.css` into the form bundle and adding `form-horizontal` to the form wrapper; FA4.7 class names (`fa-check-square-o`, `fa-lightbulb-o`, `fa-user-md`, `fa-trash-o`, …) rendered via `fontawesome-free/css/v4-shims.min.css`; debug panels (`.hidden_debug_message`) hidden unscoped so v1's `.render-alerts` sibling doesn't bleed into the participant view.
- form_v2 monkey bar: all three admin-preview buttons are now wired in v2. `.show_hidden_items` un-hides showif-hidden `.form-group.hidden`. `.show_hidden_debugging_messages` toggles `.hidden` on OpenCPU debug panels. `button.monkey` auto-fills the visible page (vanilla port of `FormMonkey.doMonkey` — picks first radio/checkbox/select option, plausible defaults for text/email/url/date/tel/color/number, midpoint for ranges). BS5-styled fixed bottom-right pill.
- form_v2 tom-select wiring extended: `select_or_add_one` + `select_or_add_multiple` items (which render as `input.select2add` with `data-select2add`/`data-select2multiple`) now bind tom-select on the `<input>` directly, honor `data-select2maximum-selection-size`/`data-select2maximum-input-length`, and opt into free-text entry unless the wrapper carries `.network_select`/`.ratgeber_class`/`.cant_add_choice`.
- form_v2 vanilla ports of `RequestCookie` and `RequestPhone` item wiring (enough for the happy path; QR-code + browser-switch guidance still lives in `PWAInstaller.js`).
- form_v2 client-side `showif` rate limit: the `/form-r-call` endpoint now enforces a per-run-session token bucket (30 calls / 60s) stored in `$_SESSION`, returning HTTP 429 on overflow.
- form_v2 offline queue — service-worker interception + Background Sync. `webroot/assets/common/js/service-worker.js` gains IDB queue helpers + a `sync` handler (tag `form-v2-drain`) that drains `formrQueue` on wake-up; the v2 bundle unconditionally registers the SW at `/{runName}/service-worker` (scope `/{runName}/`) on page load and registers the sync tag on each enqueue. Page-side `online` fallback still drains on browsers without Background Sync. iOS Safari compatibility pass is still P1.
- form_v2 offline queue — file-blob queueing. Submissions with a selected file that fail transiently now persist the `File` object into IndexedDB alongside the JSON data; the drain path reconstructs the `FormData` with the Blob and POSTs multipart to `/form-sync`. Server `formSyncAction` Content-Type-sniffs multipart the same way `formPageSubmitAction` does. Single-file cap: 10 MB (over-cap submissions surface a hard error rather than filling IDB quota).
- form_v2 admin compat-scan UI: `/admin/survey/<name>/form_v2_compat_scan` renders the same per-item showif/value classification as `bin/form_v2_compat_scan.php`. Scanner logic extracted to `application/Spreadsheet/FormV2CompatScanner.php`; linked from the survey settings "Form_v2 settings" section.
- form_v2 deep-link / pushState fix: initial `?page=N` landing now looks up the target section by `data-fmr-page` attribute (server page number) rather than array index, so back-navigation and link-sharing work when the server only renders the participant's remaining pages.
- Dev-mode template convenience: `templates/run/form_index.php` prefers `webroot/assets/dev-build/js/form.bundle.js` when present so `npm run webpack:watch` iteration works without editing the template. Falls back to `build/` in prod.
- Service-worker install hardening: pre-cache failures (missing PWA manifest, offline install) no longer discard the SW. Needed so v2 forms without a configured manifest can still register the SW for Background Sync.
- form_v2 r-call result cache: `/form-r-call` and `/form-fill` now memoize OpenCPU evaluations in `survey_r_call_results` keyed on `(call_id, sha256(sorted answers))`. Observed ~18× faster cache hits in the dev smoke (512 ms cold → 29 ms warm). Hot-path eviction: reads older than TTL are re-evaluated; writes `REPLACE` so stale rows bump to current timestamp.
- form_v2 PWA item port: AddToHomeScreen and PushNotification items now work in v2 with a vanilla (no-jQuery, no-webshim) wiring in the form bundle. AddToHomeScreen captures `beforeinstallprompt` early, fires the prompt on click, writes the resulting state (`added` / `not_added` / `not_prompted` / `already_added` / `ios_not_prompted`) into the hidden input — matches `AddToHomeScreen_Item::validateInput`'s allowlist. iOS Safari falls back to inline "tap Share → Add to Home Screen" guidance since there's no programmatic install API. PushNotification calls `pushManager.subscribe` with `urlBase64ToUint8Array(window.vapidPublicKey)`, POSTs the subscription to `/{run}/ajax_save_push_subscription`, and stores the full subscription JSON in the hidden input on success (so server-side validation passes for required items). `templates/run/form_index.php` now emits the manifest link, apple-touch-icons, mobile-web-app-capable + apple-mobile-web-app-* metas, and `window.vapidPublicKey` — mirroring `templates/public/head.php`'s logic so v2 forms with configured PWA assets behave identically to v1. Items dropped from `FormRenderer::$unverifiedTypes` (only `audio` and `video` remain there).
- form_v2 admin documentation: `documentation/form_v2.md` covers when to use v2, enabling per-study, authoring `showif`/`value` (JS by default + `r(...)` opt-in), the helper stdlib, the compatibility scanner (CLI + admin UI), PWA setup, the offline queue, and the migration checklist. README now points to it from the Surveys section.
- form_v2 R-evaluation redesign:
  - `r(...)` in `showif` is **no longer supported**. Showifs are JS-only. To get server-side R into a showif, admins now use a hidden item with `value: r(...)` and reference the field name from the JS showif. `FormRenderer` raises a validation error at render if r() appears in showif. The compat scanner classifies it as `invalid_r` and the admin UI suggests the hidden-field migration with the actual rewrite spelled out.
  - `value` accepts R authored bare **or** wrapped in `r(...)` — both route through the allowlist (`RAllowlistExtractor::unwrap($value) ?? $value`); the wrapper is the documented bridge syntax, not a requirement. The allowlist populates at render time, so it self-updates when admins edit the sheet.
  - Dynamic labels (admin-authored Rmd) are now item-keyed allowlist entries: `survey_r_calls.slot='label'` records the **whole label text** as the expression. No partial Rmd extraction — the entire label is one server call.
  - **Page-scoped resolution.** At initial render only the first visible page's `r(...)` values + dynamic labels run through OpenCPU. Items on later pages get `data-fmr-fill-id` / `data-fmr-label-id` placeholders but no evaluation. New endpoint `POST /{run}/form-render-page` resolves the upcoming page's content in one batched OpenCPU call (values + labels) when the participant transitions, with the latest answer state overlaid. Cache-aware (TTL 300s for both slots); rate-limited (shares the 30/min bucket with `/form-r-call`). Client substitutes labels into `.control-label` innerHTML and writes resolved values into `data-fmr-fill-id` inputs (firing input+change so showifs re-evaluate).
  - The initial-load `/form-fill` resolver in main.js is removed: first-page values are filled server-side at FormRenderer time; later-page values wait for `/form-render-page`. The endpoint itself stays live for one-off live re-triggers (e.g. a future "refresh computed values" admin tool).
- form_v2 PWA install: AddToHomeScreen now uses the same `add-to-homescreen` lib + `<pwa-install>` web component pair v1 uses. Captures `beforeinstallprompt` early; on click hands off to the native install dialog when available, falls back to the polished cross-platform AddToHomeScreen modal otherwise. Wired listeners for `pwa-install-success-event` / `pwa-install-fail-event` / `pwa-user-choice-result-event` so the hidden input transitions through `prompted` → `added`/`not_added` automatically. iOS-specific guidance still surfaces inline. Replaces the previous bare `beforeinstallprompt.prompt()` port that the user reported as too crude (and that incidentally produced wrong-looking install dialogs when the manifest was misconfigured).
- form_v2 "solo" layout (`survey_studies.layout='solo'`): Typeform-style one-item-per-screen step controller (`webroot/assets/form/js/solo/controller.js`) with per-item progress, keyboard navigation, visualViewport-aware keyboard handling on mobile, and a big centred submit. Layout each response was collected under is stamped into `survey_unit_sessions.layout` (paradata). Design rationale in `documentation/agent_doc/form_v2_layout_modes.md`.
- `bot_check` item (Altcha + Argon2id proof-of-work): privacy-preserving bot detection without third-party services. Server (`application/Services/BotCheckChallenge.php`) mints HMAC-signed challenges bound to the participant's `user_code` with 24h expiry; verification checks signature, PoW, expiry, and session binding, failing open only when no crypto key exists. Self-hosted widget + worker (webpack-copied; no CDN). `bin/bot_check_smoke.php` covers forged-signature / wrong-PoW / re-bind negatives. Design doc: `documentation/agent_doc/bot_check_altcha.md`.
- `visual_analog_scale` item type: continuous 0–100 slider with extreme labels, documented in the public item-types reference.
- form_v2 media recorders: `class=record_audio` / `record_video` items get a vanilla MediaRecorder widget (record / play / delete, duration display, video preview); recorded Blobs ride the existing multipart submit via DataTransfer. iOS Safari's mp4-only MediaRecorder is handled (correct `.mp4`/`.m4a` extensions).
- form_v2 client-side required gating across ALL item types (geopoint, file/image/audio/video, button groups, VAS, bot_check, …) via a shared `isAnswered()` that understands hidden value-carriers and readonly fields native Constraint Validation can't see; inline `.fmr-invalid-feedback` rendering replaces native tooltips (unreliable on iOS Safari).
- Survey item soft-delete (`survey_items.deleted`): re-uploading a spreadsheet without an item marks it deleted instead of orphaning rows; re-adding the name revives it. See `documentation/agent_doc/survey_item_soft_delete.md`.
- form_v2 per-study iteration counter (`study_iteration`): wide-table-equivalent "Nth participant to start this study", allocated atomically via `survey_studies.last_iteration`; backfill script `bin/backfill_study_iteration.php`.
- form_v2 completion beat + save indicator: a "Saved" pill tracks autosave/submit state; finishing a form shows a brief completion screen before redirecting to the next unit.
- form_v2 `request_phone` hand-off: QR code + copy-link affordance for moving an ongoing session to a phone; mobile UA auto-answers.
- Playwright e2e suite under `tests/e2e/` (~25 specs: widget catalog v1+v2 parity, required gating, solo layout, PWA high/low/items, offline queue, bot_check, media recorders, soft-delete, JSON import) with helpers for BrowserStack real-device runs (iPhone Safari). Provisioning runbook in `tests/e2e/setup/runbook.md`.
- Playwright MCP operational notes added to `CLAUDE.md` along with a fixture inventory of `documentation/example_surveys/*.xlsx` and `documentation/run_components/*.json`.
- form_v2 `choose_two_weekdays` now enforces its two-day cap client-side (`items/choose-weekdays.js`): the third check is reverted with an inline message. Nothing enforced this before — not even v1.

### Fixes (from the 2026-06-10 agent test-drive of form_v2)
- Showif batch evaluation hardened (v1 + v2, `SpreadsheetRenderer`): each `si.*` expression and dynamic value is wrapped in `tryCatch(..., error = NA)`, and showif-gated values gate on `isTRUE(as.logical(...))` instead of `if(si)`. Previously one erroring showif (`object not found`, `if(NA)`) failed the whole OpenCPU batch: participants saw raw "problem evaluating showifs" banners and every item went `.has-error`/always-invalid.
- form_v2 evaluates **visibility for all pages** in the initial OpenCPU batch (values/labels stay page-scoped). Later-page showifs over server-only run vars (`ran_group == 1`) were never server-resolved and Alpine preserves the server decision — so BOTH branches of a randomized split rendered visible. Items the batch prunes (definite-FALSE showif or computed+saved) are no longer resurrected by the first-page merge.
- form_v2 R answer overlay (`RunController::formatRValue`): multi-select answers are now serialized as the same `", "`-joined string the results table stores, not `c(1, 2)` — which errored every `/form-render-page`/`/form-r-call` batch with "replacement has 2 rows, data has 1" as soon as a participant picked two checkboxes. Empty arrays become `NA` (a `c()`/NULL assignment deletes the data.frame column).
- form_v2 Alpine `x-showif` reveal now re-enables `<button>` controls too, not just input/select/textarea. A showif-revealed `add_to_home_screen`/`push_notification` item kept its server-rendered `disabled` forever, so the install button was unclickable on exactly the platforms that support installs.
- Solo layout: when the seated step is hidden by a showif flip (e.g. a `block` guard whose condition the participant just fixed), the controller now re-seats on the nearest navigable step instead of stranding the participant on an invisible step; nav + progress refresh on every showif toggle (`fmr:showif-toggled` event).
- Solo layout: Back/OK footer buttons align with the centred 720px step column on wide screens instead of pinning to the viewport edges.
- `note_iframe` no longer renders as nothing when the OpenCPU knit fails (missing R package, R error, OpenCPU down): participants get a neutral "temporarily unavailable" placeholder, test/admin sessions get the OpenCPU debug banner. (The all-widgets fixture's `rbokeh` example fails on the slim OpenCPU image — package absent — which is how this surfaced.)
- form_v2 head: `apple-touch-icon` falls back to the default `/assets/pwa/icon.png` (same default the manifest uses) when no custom icon set is uploaded, so iOS "Add to Home Screen" gets an app icon instead of a page snapshot.
- Server-error feedback (`applyErrors`) no longer calls `reportValidity()` — the browser's native focus-first-invalid could land on a 0×0 control (tom-select's hidden input) and yank a long page's scroll to the wrong end; inline feedback + explicit focus on the first placed error instead.
- Spreadsheet import: the four PWA prompt items (`add_to_home_screen`, `push_notification`, `request_cookie`, `request_phone`) treat choices as optional (`Item::$choicesOptional`) — they only ever used choices as a button-label override, but validation hard-required (or hard-rejected, for `request_phone`) them, so fixture sheets without choices imported as item-less husk studies.

### Schema
- SQL Patch 057: adds `rendering_mode` ENUM('v1','v2') NOT NULL DEFAULT 'v1' column to `survey_studies`.
- SQL Patch 058: adds `form_study_id` INT UNSIGNED NULL column to `survey_units` so Form units can reference a SurveyStudy without sharing its id.
- SQL Patch 059: adds `survey_r_calls` table — per-study allowlist of server-evaluated R expressions, UNIQUE on `(study_id, expr_hash, slot, item_id)` (one row per item even for identical expressions — `/form-render-page` joins on `item_id`), id recovered via `LAST_INSERT_ID(id)` on duplicate.
- SQL Patch 060: adds `survey_form_submissions` table — offline-queue dedupe ledger (`uuid` unique, FK CASCADE to `unit_session_id`, `client_ts`, `applied_at`).
- SQL Patch 061: adds `offline_mode` TINYINT(1) (default 1) and `allow_previous` TINYINT(1) (default 0) columns to `survey_studies` — per-study opt-out/opt-in flags for v2 behaviours.
- SQL Patch 062: adds `survey_r_call_results` table — OpenCPU result cache with `created_at` index. Rows expire at read time (30s showif / 5min value) plus a bounded write-time eviction of rows older than a day.
- SQL Patch 063: adds `survey_items.showif_js` — admin-authored JS override for the transpiled showif.
- SQL Patch 064: scopes `survey_r_call_results` to the unit session — adds `unit_session_id` (INT(10) UNSIGNED, FK CASCADE to `survey_unit_sessions`) to the cache key so participants never see each other's cached values; truncates the cache. Guarded (`IF [NOT] EXISTS`) so hosts that applied the pre-rebase phantom patch 054 re-run cleanly.
- SQL Patch 065: adds `survey_unit_sessions.study_iteration` (per-study participant sequence; allocated via `survey_studies.last_iteration` counter). One-time backfill via `bin/backfill_study_iteration.php` — must be run manually after the migration.
- SQL Patch 066: adds `survey_studies.layout` ENUM('default','solo') — per-study v2 layout mode.
- SQL Patch 067: adds `survey_studies.option_keys` — **dormant**: the solo letter-key badges feature was removed end-to-end before release; the column ships unread (kept because branch-tracking hosts already applied it).
- SQL Patch 068: adds `survey_unit_sessions.layout` — paradata stamp of the layout mode each response was collected under.
- SQL Patch 069: adds `survey_items.deleted` — soft-delete for items removed on spreadsheet re-upload (revived if a later upload restores the name; both renderers filter `deleted IS NULL`).

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

