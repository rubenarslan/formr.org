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
  - `r(...)` opt-in for showifs the JS transpiler can't translate. `RAllowlistExtractor` unwraps the top-level `r(...)` wrapper and `FormRenderer::processItems` UPSERTs the inner R into `survey_r_calls` (dedup by `study_id + expr_hash + slot`). The wrapper is emitted with `data-fmr-r-call="{id}"`; the client POSTs `{call_id, answers}` to `POST /{run}/form-r-call` debounced 300ms with seq-guarded stale-response protection. No R source ever reaches the client.
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
- Playwright MCP operational notes added to `CLAUDE.md` along with a fixture inventory of `documentation/example_surveys/*.xlsx` and `documentation/run_components/*.json`.

### Schema
- SQL Patch 47: adds `rendering_mode` ENUM('v1','v2') NOT NULL DEFAULT 'v1' column to `survey_studies`.
- SQL Patch 48: adds `form_study_id` INT UNSIGNED NULL column to `survey_units` so Form units can reference a SurveyStudy without sharing its id.
- SQL Patch 49: adds `survey_r_calls` table — per-study allowlist of `r(...)`-wrapped expressions keyed by `(study_id, expr_hash, slot)`, recovered via `LAST_INSERT_ID(id)` on duplicate. Slot is one of `'showif' | 'value'`.
- SQL Patch 50: adds `survey_form_submissions` table — offline-queue dedupe ledger (`uuid` unique, FK CASCADE to `unit_session_id`, `client_ts`, `applied_at`).
- SQL Patch 51: adds `offline_mode` TINYINT(1) (default 1) and `allow_previous` TINYINT(1) (default 0) columns to `survey_studies` — per-study opt-out/opt-in flags for v2 behaviours.
- SQL Patch 52: adds `survey_r_call_results` table — per-(call_id, args_hash) cache with `created_at` index for TTL eviction. Rows expire at read time: 30s for showif, 5min for value.

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

