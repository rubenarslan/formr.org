# Formr.org Change Log (check previous change logs in CHANGELOG-v1.md)

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

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

