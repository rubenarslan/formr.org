# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What formr is

formr is a survey/study framework for psychology-style research: participants traverse **runs** (ordered compositions of units — surveys, pauses, emails, push notifications, external redirects, branches, shuffles, skips) that can chain into longitudinal/diary/network studies. Items are authored in spreadsheets (loosely XLSform-based) rather than a drag-and-drop builder. R code is evaluated via OpenCPU for custom feedback, skip logic, and relative-time computations. Each study can be delivered as an installable PWA with web push.

Stack: **PHP 8.2+ / MariaDB-MySQL / jQuery** (no SPA framework). Composer for PHP deps, NPM + Webpack + legacy `build-scripts/` for JS/CSS. The dockerized dev environment lives in a separate repo (`rubenarslan/formr_dev_docker`); this repo is the app source.

## Commands

### Install / dev setup
```bash
composer install
npm install
```

Config lives in `config/settings.php` (gitignored), seeded from `config-dist/settings.php`. `setup.php` loads dist first, then overrides from `config/`. Don't edit `config-dist/` as the "real" config.

### Build frontend

Two build systems coexist. New code goes through Webpack (bundles `material`, `frontend`, `admin` from `webroot/assets/{site,admin}/js/main.js`); legacy pages still load the older concatenated bundles.

```bash
# Webpack (preferred for new work): outputs to webroot/assets/build or /dev-build
npm run webpack:build        # production
npm run webpack:watch        # watch mode → webroot/assets/dev-build

# Legacy build-scripts: concatenate + lint + uglify for formr.js, formr-material.js, formr-admin.js
npm run build                # = build:copy && build:css && build:js
npm run build:js             # runs ESLint (.eslintrc.js) and CSSLint; a lint error aborts the build
```

The legacy pipeline is driven by `build-scripts/assets.json` (asset bundle definitions) and outputs minified `*.min.js`/`*.min.css` under `webroot/assets/build/`. Source maps and `dev-build/` are gitignored.

### Run tests
```bash
composer test                # phpunit 11, config at tests/phpunit.xml, bootstraps via setup.php
vendor/bin/phpunit --configuration tests/phpunit.xml --filter SomeTest::testName   # single test
```

Tests live flat under `tests/` (e.g. `DBTest.php`, `CryptoTest.php`, `OpenCPUTest.php`). They require a working DB connection per `config/settings.php` — they are integration tests, not pure unit tests.

### SQL migrations
`sql/schema.sql` is the fresh-install baseline; incremental migrations are numbered files under `sql/patches/NNN_description.sql` and applied in order. When adding a DB change, author a new patch — don't edit `schema.sql` alone. Record the patch number in `CHANGELOG.md` under a **Schema** subsection.

### CLI entry points
`bin/cron.php`, `bin/cron_run_expiry.php`, `bin/cron_cleanup_orphaned_files.php` — scheduled via `config/formr_crontab`.
`bin/queue.php -t Email` and `bin/queue.php -t UnitSession` — long-running queue workers (optionally under supervisord; see `config/supervisord.conf`).
`bin/add_user.php`, `bin/reset_2fa.php`, `bin/import-results.php` — admin utilities.

## Architecture

### Request lifecycle
`webroot/.htaccess` rewrites every request to `webroot/index.php?route=<path>`. `index.php` loads `setup.php` (which loads `vendor/autoload.php`, `Functions.php`, settings, then the custom class `Autoload`), starts a `Session`, builds the `Site` singleton, opens the DB, then hands off to `Router`.

`Router` (`application/Router.php`) matches route slugs against a small table in `setup.php` (`admin`, `admin/run`, `admin/survey`, `admin/mail`, `admin/advanced`, `admin/account`, `public`, `api`, `run`) to a controller class. Unmatched routes fall through to `PublicController` and then to `RunController::indexAction` — the assumption being that any unknown top-level path is a run name. Actions are derived from URL parts (`foo-bar` or `foo_bar` → `fooBarAction`). `disabled_features` in config can gate actions globally with `SURVEY.methodName` / `RUN.methodName` entries.

### Study subdomains
When `use_study_subdomains = true` and the `FMRSD_CONTEXT` env var is set on the vhost, the router extracts the run name from the subdomain and forces `RunController::indexAction`. This is a **security boundary** (per-study origin isolation to contain XSS and study-authored R/HTML), not just cosmetics — admin lives on `admin_domain`, studies on `study_domain` (typically `*.example.com`).

### Autoloading
`application/Autoloader.php` is a hand-rolled class-map-ish loader that searches, in order: `application/`, `Controller/`, `Model/RunUnit/`, `Model/Item/`, `Model/`, `View/`, `Helper/`, `Queue/`, `Services/`, `Spreadsheet/`. Class names map directly to filenames (no namespaces for app code — composer vendor libs have their own PSR-4). If you add a new class, just drop it into one of these dirs; no manifest to update.

### Domain model (the mental picture)
- `Run` (`Model/Run.php`, ~60k lines) — a study. Has owner, privacy settings, PWA manifest config, expiry, OSF linkage, cookie lifetime, etc.
- `RunUnit` subclasses (`Model/RunUnit/`) — the steps in a run. `RunUnitFactory::SupportedUnits` enumerates the valid types: `Survey`, `Pause`, `Email`, `PushMessage`, `External`, `Page`, `SkipBackward`, `SkipForward`, `Shuffle`, `Wait` (plus `Branch`, `Privacy` used internally). Add a new unit type by extending `RunUnit`, dropping the file in `Model/RunUnit/`, and adding it to `SupportedUnits`.
- `SurveyStudy` (`Model/SurveyStudy.php`) — a survey definition (items, settings); built from an uploaded spreadsheet via `application/Spreadsheet/SpreadsheetReader.php` and rendered by `SpreadsheetRenderer`/`PagedSpreadsheetRenderer`.
- `Item` subclasses (`Model/Item/`, ~55 types) — one file per input type. The spreadsheet's `type` column maps to a class here. PWA-adjacent items: `AddToHomeScreen`, `PushNotification`, `RequestCookie`, `RequestPhone`, `Timezone`.
- `RunSession` — a participant's traversal of a run. `UnitSession` — a participant's interaction with a single unit. Both own a chunk of state machine logic (expiry, queueing, retries).

Controllers are thin by design: they fetch models, call a method like `Run::exec()`, and pass the resulting `$run_vars` into a PHP view under `templates/`. Global vars (`$user`, `$run`, `$study`, `$site`, `$css`, `$js`) are threaded through `Controller::__construct` — this is load-bearing, not an accident.

### Services
- `Services/OpenCPU.php` — HTTP client for the R runtime. Used for knitr/markdown rendering, skip-logic expressions, Pause `relative_to` evaluation, feedback plots. OpenCPU failures should flow through `notify_study_admin()` (see `application/Notification.php`) so study owners get throttled emails per the `$settings['notification']` config.
- `Services/PushNotificationService.php` — wraps `minishlink/web-push`. iOS Safari 18.4+ requires **declarative web push** payloads (a `web_push`/`notification` object alongside the encrypted body); this was added in v0.25.1. Also: iOS terminates subscriptions after ~3 silent pushes, so never drop empty payloads silently.
- `Services/OSF.php` — OAuth2 integration with osf.io for project linkage.
- `Services/RateLimitService.php` — generic rate limiter used across controllers.

### Queues
`application/Queue/` contains `EmailQueue` and `UnitSessionQueue`. Both are DB-backed (not Redis). The unit-session queue advances participants through runs in the background; the email queue is used for study-admin-sent emails and confirmations. Tunables live under `$settings['email']` and `$settings['unit_session']`.

### Frontend
Entry points:
- `webroot/assets/site/js/main.js` → Webpack `frontend.bundle.js` (participant-facing)
- `webroot/assets/admin/js/main.js` → Webpack `admin.bundle.js` (admin UI)
- `webroot/assets/site/js/material.js` → Webpack `material.bundle.js`
- Shared code under `webroot/assets/common/js/` — notably `service-worker.js`, `pwa-register.js`, `components/PWAInstaller.js` for PWA/push; `survey.js` for form logic; `run.js`, `run_settings.js`, `run_users.js` for admin.

Templates live in `templates/{admin,public,run,email}/` as plain PHP. There is no templating engine — PHP short tags and `htmlspecialchars` / helpers from `Functions.php`.

### Config and settings
Two-layer config: defaults in `config-dist/settings.php`, overrides in `config/settings.php`. `Config::initialize()` ingests the merged array. Runtime-editable instance settings go through `Site::getSettings()` and are stored in the DB (`settings` table, patch 031) — not the PHP file.

### What's load-bearing and non-obvious
- The request-token / CSRF mechanism was **removed** in v0.25.1 — do not reintroduce `Session::REQUEST_TOKENS`, `getRequestToken`, or per-form hidden tokens. Auth now relies on session cookies + same-site.
- `Crypto` (`application/Crypto.php`) uses paragonie/halite and reads a key from `formr-crypto.key/`. Breaking that key breaks decryption of existing encrypted data at rest — treat it like a database.
- `expire_cookie` on a run drives `Session::setSessionLifetime` per-run; PWA manifest generation auto-extends cookie lifetime to 1 year and reports that back to the admin.
- The `Autoloader`'s path search order means a class name collision between, say, `Model/` and `Model/RunUnit/` would silently pick the first-found file. Keep class names unique across those dirs.
- Tests bootstrap via `tests/../setup.php`, which means they get the same DB and crypto wiring as the live app. Running the suite against a production DB will mutate it.

## Frontend conventions (from .cursor/rules)
- Prefer `async/await` over `.then()` chains in new JS — especially in `PWAInstaller.js`, `service-worker.js`, `pwa-register.js`.
- Per-study PWA manifests are generated from `templates/run/manifest_template.json`; study-specific values (name, icons, theme) are substituted server-side, so don't hardcode app identity there.

## Production notes (abridged from INSTALLATION.md)
This repo's setup is **not** safe to expose as-is. Production deployments additionally need: OpenCPU hardened with AppArmor, per-study subdomain isolation, HTTPS everywhere, encrypted data at rest, running cron + queue daemons, and careful handling of OpenCPU R package upgrades (OpenCPU freezes package versions per release). Dev/local uses the separate `formr_dev_docker` repo.

## Release discipline
`CHANGELOG.md` (current format) vs `CHANGELOG-v1.md` (archived). Each release bumps `VERSION` and `package.json` version; `composer.json` has a drifted version field that is not kept in sync. Entries group under **Added / Fixes / Changes / Schema**.

## Dev environment & UI testing

- **Dev instance:** `https://formr.researchmixtape.com` (login at `/admin/account/login`). **Not production** — safe to create, edit, and delete test forms/runs. Do not run destructive DB operations here without asking first, but ordinary admin actions are fine.
- **Domain mismatch is intentional:** admin email is on `researchmixtapes.com` (plural), the web instance is on `researchmixtape.com` (singular). Not a typo.
- **Stack config** lives one directory up at `/home/admin/formr-docker/` — see `docker-compose.yml`, `.env`, `README.md` there for how the containers (app, MariaDB, OpenCPU, proxy) are wired. The active compose file defaults to `docker-compose.yml`; `docker-compose-prod.yml` and `docker-compose-local.yml` are alternatives.
- **Admin test credentials:** stored in `/home/admin/formr-docker/.env.dev` (gitignored in both this repo and the docker repo). Read with `cat /home/admin/formr-docker/.env.dev` when you need them. Never paste into chat, never commit, never write into memory files.
- **Participant URLs** use subdomains because `use_study_subdomains=true`: a run named `foo` is reachable at `https://foo.researchmixtape.com/`. The admin and run live on different origins by design — this is part of formr's security model, not incidental.

### UI testing via Playwright MCP

The Playwright MCP server is registered (`claude mcp list` shows it as ✓ Connected) and exposes browser automation tools (navigate / click / type / screenshot / accessibility snapshot / network control).

- **If the Playwright tools don't appear** in a fresh session (e.g. `ToolSearch "playwright"` returns nothing), quit and restart the `claude` CLI — MCP servers added mid-session sometimes connect without their tools being exposed to the model until a fresh start. `claude mcp list` tells you whether the server itself is healthy.
- **Golden-path smoke test** after any participant-UI change:
  1. Navigate to the admin login, authenticate with dev creds from `.env.dev`.
  2. Create (or open) a test run containing a `Form` RunUnit with a simple multi-page form.
  3. Open the run's participant subdomain, step through each page, take a screenshot per page.
  4. For form_v2 offline work specifically: toggle network off in the browser context, submit a page, verify the "queued" banner appears and the user can proceed; toggle network back on, verify the queue drains and results land in the DB.
- **Clean up test runs** after a session unless you explicitly need them to persist for a later check. Orphaned test runs clutter the dev admin view and confuse later sessions.
- **Don't use Playwright against production instances.** This dev instance is the only safe target.
