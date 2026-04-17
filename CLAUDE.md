# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

formr is a survey/study framework: PHP 8.2+ backend, MariaDB, jQuery/Bootstrap frontend, with R/knitr feedback rendered through OpenCPU. Users define surveys as item spreadsheets and chain them into "runs" (longitudinal/diary/experimental designs). Each study can also be served as a Progressive Web App.

Local development uses the dockerized stack at https://github.com/rubenarslan/formr_dev_docker (which provides Apache+PHP, MariaDB, and OpenCPU). This repo on its own does not run; it expects to be mounted into that environment.

## Commands

PHP (run inside the docker app container):
- `composer install` — install PHP deps
- `composer test` — runs `phpunit --configuration tests/phpunit.xml`. PHPUnit bootstraps via `setup.php`, so tests have full access to `Config`, `DB`, etc.
- Run a single test: `vendor/bin/phpunit --configuration tests/phpunit.xml --filter TestName tests/SomeTest.php`

Frontend assets (run on host or container):
- `npm run webpack:build` — production bundles into `webroot/assets/build/`
- `npm run webpack:watch` — dev bundles into `webroot/assets/dev-build/` (watched)
- `npm run build` — legacy copy/css/js pipeline (`build-scripts/`); webpack is the primary path

CLI tasks (in `bin/`):
- `bin/cron.php` — main cron loop (processes run sessions, emails, expiry). Skipped when `unit_session.use_queue` is true.
- `bin/queue.php` — queue worker (used when `unit_session.use_queue` is enabled)
- `bin/cron_run_expiry.php`, `bin/cron_cleanup_orphaned_files.php` — periodic maintenance
- `bin/add_user.php`, `bin/reset_2fa.php` — admin operations
- `bin/import-results.php` — import survey results from spreadsheets

API tests live in `tests/APIV1_bruno_tests/` (Bruno collections, not PHPUnit).

## Architecture

### Bootstrap and routing
- `webroot/index.php` is the single entry point; everything goes through `setup.php` → `Router::route()` → `Controller::execute()`.
- `setup.php` defines core constants (`APPLICATION_ROOT`, `DEBUG`, etc.), registers the autoloader, initializes `Config`, `Crypto`, and the session, then calls `determine_session_context()` which sets `SESSION_CONTEXT` and (when on a study subdomain) `STUDY_NAME`.
- `Router` dispatches to controllers from `$settings['routes']` in `setup.php`. Study subdomains (`use_study_subdomains`) are part of the security model — survey/run pages live on `<studyname>.example.com`, admin lives on `admin_domain`. Don't break this separation.
- API routes `/api/v1/<resource>/...` go through `ApiController::dispatchV1` → `ApiHelperV1` → resource classes in `application/Helper/ApiV1/` (`UserResource`, `RunResource`, etc.). Auth is OAuth2 via `bshaffer/oauth2-server-php`.

### Domain model (`application/Model/`)
- `Run` — a study (sequence of units a participant moves through)
- `RunUnit/*` — the unit types: `Survey`, `Pause`, `Email`, `PushMessage`, `Branch`, `Shuffle`, `SkipForward/Backward`, `External`, `Page`, `Privacy`, `Wait`. New unit types extend `RunUnit\RunUnit`.
- `RunSession` / `UnitSession` — a participant's progress through a run / a single unit
- `SurveyStudy` — a survey definition; rows come from spreadsheets via `application/Spreadsheet/SpreadsheetReader.php`
- `Item/*` — one class per item type (~50 of them: `Text`, `Mc`, `Range`, `Geopoint`, `PushNotification`, `AddToHomeScreen`, etc.). New item types subclass `Item\Item` and are auto-registered by class name.
- `User` — accounts and 2FA (`robthree/twofactorauth`)

### Services and helpers
- `Services/OpenCPU.php` — wraps the R execution endpoint used everywhere knitr/Markdown text is rendered with participant data. Don't bypass — it manages session reuse and timing.
- `Services/PushNotificationService.php` — web push (VAPID) for PWA push messages
- `Services/RateLimitService.php` — login throttling (timing-attack protection is intentional)
- `Helper/RunHelper.php`, `Helper/UserHelper.php`, `Helper/OAuthHelper.php` — controller-side helpers
- `Crypto.php` — at-rest encryption of participant data (`paragonie/halite`); the key is loaded from `formr-crypto.key`

### Queue and cron
Two execution models for processing run sessions: synchronous via `bin/cron.php`, or queued via `application/Queue/UnitSessionQueue.php` (toggled by `unit_session.use_queue`). Email sending also has a queue (`Queue/EmailQueue.php`). When changing session/unit logic, check both paths.

### Frontend (`webroot/assets/`)
- Three webpack entry points: `frontend` (participant-facing surveys), `admin` (study creator UI), `material` (Material Design styles). See `webpack.config.js`.
- jQuery 2.x + Bootstrap 3 + Select2 + Ace editor + webshim. jQuery is exposed globally via `expose-loader`.
- PWA bits: `webroot/assets/common/js/service-worker.js`, `pwa-register.js`, `components/PWAInstaller.js`. Each study gets its own manifest from `templates/run/manifest_template.json`. Prefer `async/await` over `then()` chains in this code (per `.cursor/rules/formr-js.mdc`).

### Templates and views
PHP templates live in `templates/` (not `application/View/`, which is mostly empty). Areas: `templates/admin/`, `templates/run/`, `templates/public/`, `templates/email/`. Public-facing documentation pages are HTML files under `templates/public/documentation/`.

### Config layers
1. `config-dist/settings.php` — defaults (committed)
2. `config/settings.php` — local overrides (gitignored, required at runtime)
Both are loaded in `setup.php` in that order. When adding a setting, add the default in `config-dist/` and document overrides separately.

### SQL
- `sql/schema.sql` — current full schema
- `sql/patches/NNN_*.sql` — incremental migrations applied in numeric order. Add new schema changes as a new patch file rather than editing `schema.sql` alone.

## Conventions worth knowing

- The `Controller` base class injects globals (`$user`, `$run`, `$study`) via a `global` declaration in its constructor — there's a `@todo` to switch to DI but for now controllers expect these to exist.
- API resource classes follow a uniform `handle()` dispatch pattern; mirror an existing resource (e.g., `SurveyResource`) when adding a new one rather than introducing a new pattern.
- R/knitr text appears in surveys, pauses, emails, and feedback pages. Anywhere you accept such text, it must round-trip through `OpenCPU` for rendering — never `eval` R-looking input directly.
- Production hardening (AppArmor for OpenCPU, encrypted-at-rest, study subdomains, daemon supervision) is documented in `INSTALLATION.md`. Local dev does not exercise these paths.
