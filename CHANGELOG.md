# Formr.org Change Log (check previous change logs in CHANGELOG-v1.md)

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## [v0.26.0] - 11.05.2026
### Heads-up for self-hosters
- `cron_only=true` Email units will start delivering after this upgrade. They were silently never sent due to a latent bug; audit affected studies before deploying.
- Push and External completions now mark the unit-session as ended (was previously left open). Affects analysis queries that filter on `ended IS NOT NULL`.
- Admin queue inspector replaces the "To Execute" yes/no column with a named state badge and adds an iteration column.

### Fixes
- Daemon kill mid-cascade no longer causes a duplicate Email or Push send on restart (idempotency keys block the duplicate insert)
- `cron_only=true` Email units now deliver from the cron daemon
- PushMessage now properly ends its unit-session after a successful send
- External unit-sessions ended via the API callback now write the same audit columns as the standard end path
- Push notifications no longer write two `push_logs` rows per send

### Added
- New columns on `survey_unit_sessions`: `run_unit_id` and `iteration` (disambiguate the same survey reused at multiple positions, count back-jump / SkipBackward loops); `state` ENUM and `state_log` JSON (named lifecycle status alongside the legacy `queued` column)
- Admin queue inspector renders the new state and iteration columns

### Schema
- Patch 047: schema additions on `survey_unit_sessions`, `survey_email_log`, `push_logs`
- Patch 048: one-shot backfill of `state`, `run_unit_id`, `iteration` for historical rows; idempotent (re-runs are no-ops)

### Tests + docs
- 6 new PHPUnit files (35 cases) covering the state column, idempotency keys, the cron_only gate, the Push state-transition, and the state_log JSON shape
- 3 live-MariaDB integration smokes under `bin/test_track_a_*_smoke.php`
- Refactor plan and state-machine diagrams moved to `documentation/agent_doc/`

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

