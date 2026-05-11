# CLAUDE.md — formr.org application source

This file is for the formr **application** code in this repo
(`application/`, `bin/`, `templates/`, `webroot/`, etc.). The
deployment / docker layer (`/home/admin/formr-docker/`) has its own
`CLAUDE.md` describing the container stack, compose files, Atlas
migrations, traefik routing, and operational scripts. Read that one
for anything outside the PHP app.

## Stream Timeout Prevention

Never write a file longer than ~150 lines in a single tool call. If a
file will be longer, write it in multiple append/edit passes. Keep
individual grep/search outputs short — use `--include` and `-l` flags
to limit output. If you hit the timeout, retry the same step in a
shorter form; don't repeat from scratch.

## Agent documentation: `documentation/agent_doc/`

Long-form planning docs, design rationale, refactor plans, and the
diagrams that accompany them live under `documentation/agent_doc/`.

- **Don't put these in `tests/`.** `tests/` is for runnable test code
  (PHPUnit + Playwright + fixture SQL). Planning markdown and `.d2`
  / `.svg` diagrams that *describe* the system go in
  `documentation/agent_doc/`. `tests/EXPIRY_AUDIT.md`,
  `tests/EXPIRY_PLAN.md`, etc. predate this convention — leave them
  in place; move them when adjacent test code changes.
- **Diagrams are checked in as both `.d2` source and `.svg` render**
  so a reviewer can read either without a `d2` install. Re-render
  via `d2 documentation/agent_doc/<name>.d2 documentation/agent_doc/<name>.svg`.
- **Track A artifacts:** `REFACTOR_QUEUE_PLAN.md` (the plan),
  `unit_type_states.md` (per-RunUnit state-machine reference),
  `refactor_queue_current.d2/.svg` (pre-A2 architecture),
  `refactor_queue_track_a_states.d2/.svg` (shipped Track A state
  machine), `refactor_queue_proposed.d2` (deferred Track B end
  state).

## What formr is

A survey/study framework for psychology research: participants
traverse **runs** (ordered compositions of units — surveys, pauses,
emails, push notifications, external redirects, branches, shuffles,
skips, waits) that can chain into longitudinal/diary/network studies.
Items are authored in spreadsheets (loosely XLSform-based) rather
than a drag-and-drop builder. R code is evaluated via OpenCPU for
custom feedback, skip logic, and relative-time computations. Each
study can be delivered as an installable PWA with web push.

Stack: **PHP 8.2+ / MariaDB / jQuery** (no SPA framework). Composer
for PHP deps, NPM + Webpack + legacy `build-scripts/` for JS/CSS. The
dockerized dev environment lives in a separate repo
(`/home/admin/formr-docker/`); this repo is the app source.

## Commands

```bash
composer install
npm install

# Frontend build (Webpack preferred for new work):
npm run webpack:build        # production
npm run webpack:watch        # watch mode → webroot/assets/dev-build

# PHPUnit (SQLite in-memory; integration tests gated):
composer test
vendor/bin/phpunit --configuration tests/phpunit.xml --filter SomeTest::testName
vendor/bin/phpunit --configuration tests/phpunit.xml --exclude-group integration
```

Config: `config-dist/settings.php` is the dist default; overrides go
in `config/settings.php` (gitignored). `setup.php` loads dist first
then overrides. Don't edit `config-dist/` as the "real" config.

**Live config lives in `../formr_app/config/settings.php`** — the
running app reads `/var/www/formr/config/settings.php` which is
bind-mounted from `/home/admin/formr-docker/formr_app/config/settings.php`,
NOT from `formr_source/config/settings.php`. Editing the latter looks
correct in the repo but has no runtime effect.

### SQL migrations

`sql/schema.sql` is the fresh-install baseline; incremental
migrations are numbered `sql/patches/NNN_description.sql` and applied
in order. When adding a DB change, author a new patch — don't edit
`schema.sql` alone. `schema.sql` drifts from recent patches (043,
045, 046, 047, 048 may be missing columns there); ship just the
patch file unless separately reconciling. Atlas migration numbers
are coordinated with upstream — use the next sequential number
visible in `sql/patches/`.

### CLI entry points

- `bin/cron.php`, `bin/cron_run_expiry.php`,
  `bin/cron_cleanup_orphaned_files.php` — scheduled via
  `config/formr_crontab`.
- `bin/queue.php -t Email` and `bin/queue.php -t UnitSession` —
  long-running queue workers (under supervisord; see
  `config/supervisord.conf`). `--once` flag for deterministic
  single-pass test driving.
- `bin/add_user.php`, `bin/reset_2fa.php`, `bin/import-results.php`
  — admin utilities.
- `bin/test_track_a_*_smoke.php` — live-MariaDB integration smokes
  for Track A (the SQLite test bootstrap can't host the JSON / ENUM
  / UNIQUE / window-function surface). Invoke via `docker exec
  formr_app php /var/www/formr/bin/<smoke>.php`.

## Architecture

### Request lifecycle

`webroot/.htaccess` rewrites every request to
`webroot/index.php?route=<path>`. `index.php` loads `setup.php`
(autoload, Functions, settings, custom Autoload), starts a `Session`,
builds `Site`, opens DB, hands off to `Router`. `Router` matches
route slugs against a small table in `setup.php` (`admin`,
`admin/run`, `admin/survey`, `admin/mail`, `admin/advanced`,
`admin/account`, `public`, `api`, `run`) to a controller. Unmatched
routes fall through to `PublicController` then `RunController::indexAction`
— any unknown top-level path is assumed to be a run name. Actions
derive from URL parts (`foo-bar` or `foo_bar` → `fooBarAction`).

### Study subdomains (security boundary)

When `use_study_subdomains = true` and the `FMRSD_CONTEXT` env var is
set on the vhost, the router extracts the run name from the
subdomain and forces `RunController::indexAction`. This is a
**security boundary** — admin lives on `admin_domain`, studies on
`study_domain` (typically `*.example.com`) to contain XSS and
study-authored R/HTML.

### Autoloading

`application/Autoloader.php` is a hand-rolled class-map-ish loader
that searches: `application/`, `Controller/`, `Model/RunUnit/`,
`Model/Item/`, `Model/`, `View/`, `Helper/`, `Queue/`, `Services/`,
`Spreadsheet/`. Class names map directly to filenames (no namespaces
for app code; composer vendor has its own PSR-4). Drop a new class
into one of these dirs; no manifest to update. **Path search order
matters**: a class-name collision between, say, `Model/` and
`Model/RunUnit/` would silently pick the first-found file. Keep names
unique.

### Domain model

- `Run` (`Model/Run.php`) — a study. Owner, privacy, PWA manifest,
  expiry, OSF, cookie lifetime.
- `RunUnit` subclasses (`Model/RunUnit/`) — `Survey`, `Pause`,
  `Email`, `PushMessage`, `External`, `Page`, `SkipBackward`,
  `SkipForward`, `Shuffle`, `Wait` (+ `Branch`, `Privacy` internal).
  Add a new type by extending `RunUnit`, dropping into
  `Model/RunUnit/`, and adding to `SupportedUnits`. See
  `documentation/agent_doc/unit_type_states.md` for what state each
  type reaches.
- `SurveyStudy` — a survey definition; built from spreadsheet via
  `Spreadsheet/SpreadsheetReader.php`.
- `Item` subclasses (`Model/Item/`, ~55 types) — one file per input
  type. Spreadsheet `type` column maps to a class.
- `RunSession` — a participant's traversal. `UnitSession` —
  participant's interaction with a single unit. Both own state
  machine logic.

Controllers are thin: fetch models, call `Run::exec()`, pass
`$run_vars` into a PHP view under `templates/`. Global vars
(`$user`, `$run`, `$study`, `$site`, `$css`, `$js`) threaded through
`Controller::__construct` — this is load-bearing, not an accident.

### Services and queues

- `Services/OpenCPU.php` — HTTP client for the R runtime
  (knitr/markdown, skip logic, Pause `relative_to`, feedback plots).
  Failures flow through `notify_study_admin()`.
- `Services/PushNotificationService.php` — wraps `minishlink/web-push`.
  iOS Safari 18.4+ requires **declarative web push** payloads
  (`web_push`/`notification` object alongside encrypted body); added
  v0.25.1. iOS terminates subscriptions after ~3 silent pushes —
  never drop empty payloads silently.
- `Services/OSF.php` — OAuth2 with osf.io.
- `Services/RateLimitService.php` — generic rate limiter.
- `Queue/EmailQueue.php`, `Queue/UnitSessionQueue.php` — DB-backed
  (not Redis). Track A v0.26.0 added named `STATE_*` constants and
  the `stateForQueuedUnit($runUnit)` / `queueLabelForRow($row)`
  helpers on `UnitSessionQueue`.

### Templates & frontend

Templates: `templates/{admin,public,run,email}/` as plain PHP. No
templating engine — short tags + `htmlspecialchars` + helpers from
`Functions.php`. Webpack entry points: `webroot/assets/site/js/main.js`
(participant), `webroot/assets/admin/js/main.js` (admin),
`webroot/assets/site/js/material.js`, plus shared
`webroot/assets/common/js/`.

## Load-bearing / non-obvious

- **CSRF tokens were removed in v0.25.1.** Do not reintroduce
  `Session::REQUEST_TOKENS`, `getRequestToken`, or per-form hidden
  tokens. Auth relies on session cookies + same-site.
- **`Crypto` reads a key from `formr-crypto.key/`.** Breaking the
  key breaks decryption of at-rest data — treat like a database.
- **`expire_cookie` on a run drives `Session::setSessionLifetime`
  per-run.** PWA manifest generation auto-extends to 1 year.
- **`Model::assignProperties` uses `property_exists`.** Every column
  you want to round-trip must be declared as a public property on
  the Model subclass. Skipping this is how `rendering_mode` silently
  returned the default in v2 work.
- **`<Model>::toArray()` is a write allowlist.** `Model::save()`
  writes only what `toArray()` returns. Three touch points for every
  new column: (1) patch, (2) `public $foo = <default>`,
  (3) `toArray()` entry.
- **Tests bootstrap via `setup.php`** which means they get the same
  DB and crypto wiring as the live app. The PHPUnit `tests/bootstrap.php`
  forces SQLite-in-memory; `@group integration` tests reach live
  MariaDB and are gated out of CI.

## Release discipline

`CHANGELOG.md` (current) vs `CHANGELOG-v1.md` (archived). Each
release bumps `VERSION` and `package.json` version (`composer.json`
has a drifted version that is not kept in sync). Entries group under
**Added / Fixes / Changes / Schema**.

## Dev environment & operational gotchas

- **Dev instance:** `https://formr.researchmixtape.com` (login
  `/admin/account/login`). **Not production** — safe to create / edit
  / delete test forms and runs. Don't run destructive DB ops without
  asking; ordinary admin actions are fine.
- **Domain mismatch is intentional.** Admin email is on
  `researchmixtapes.com` (plural), the web instance on
  `researchmixtape.com` (singular). Not a typo.
- **Stack config** lives at `/home/admin/formr-docker/`. Active
  compose file is `docker-compose.yml` merged with
  `docker-compose-local.yml` (dev) / `docker-compose-dev-remote.yml`
  / `docker-compose-prod.yml` per host.
- **Admin test credentials** in `/home/admin/formr-docker/.env.dev`
  (gitignored both repos). `cat /home/admin/formr-docker/.env.dev`
  when needed. Never paste into chat, commit, or write into memory
  files.
- **Participant URLs** don't use subdomains (this dev has
  `use_study_subdomains=false`): `https://study.researchmixtape.com/<runName>/?code=<code>`.
  The admin and run live on different origins by design.
- **Daemons need restart for code changes.** PHP picks up
  `formr_app` changes within ~2s (`opcache.revalidate_freq=2`); no
  restart needed for the app. But `formr_mail_daemon` and
  `formr_run_daemon` load classes once at startup — `docker compose
  restart formr_mail_daemon formr_run_daemon` to see new PHP code.
- **Dev DB client is `mariadb`, not `mysql`.** Inside `formr_db`:
  `docker exec -i formr_db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"
  "$MARIADB_DATABASE"' < patch.sql`. Credentials are env vars inside
  the container — don't hardcode.
- **PHP error logs go to `docker logs formr_app`**, not
  `tmp/logs/errors.log`. `config/settings.php` has
  `error_to_stderr = 1`. To diagnose a silent AJAX failure:
  `docker logs --tail 100 formr_app 2>&1 | grep -A 15 <keyword>`.
- **Routing: dash → camelCase, underscore stays literal.**
  `/run/form-page-submit` → `formPageSubmitAction`;
  `/run/ajax_save_push_subscription` → `ajax_save_push_subscriptionAction`.
- **DB cleanup has FK order.** `survey_runs` has no cascade to
  `survey_run_units`; deleting a run requires deleting
  `survey_run_units`, `survey_run_special_units`, and
  `survey_run_sessions` first. Easier to use admin "Danger Zone →
  Delete run" UI than scripting.

## UI testing via Playwright MCP

The Playwright MCP server is registered (`claude mcp list` should
show ✓ Connected). Use it for:

- Golden-path smoke after participant-UI changes (open a test run,
  step through pages, screenshot per page).
- Network/offline emulation for PWA work.

**Don't test against production.** This dev is the only safe target.

### Operational gotchas (learned the hard way)

- **First use on a fresh box:** `npx playwright install chrome`. The
  MCP server wants the branded Chrome binary.
- **Cookie-consent dialog blocks login.** Accept or reject the
  "Recognize this device again?" dialog before calling
  `browser_fill_form` — otherwise inputs may be obstructed or the
  dialog reappears after submit.
- **Snapshot refs (`e138`, `e140`, …) go stale after every DOM
  mutation.** AJAX inserts shift the entire ref map; a ref that
  pointed at "Add Form" pre-click can resolve to "Add Email"
  post-click. Re-snapshot before each click, OR click by selector
  via `browser_evaluate('() => document.querySelector(".add_form").click()')`.
- **Use `browser_evaluate` for state assertions.** Returning a small
  JSON object (`{buttonPresent: true, unitId: 278}`) is faster and
  more reliable than diffing accessibility snapshots. Deep snapshots
  also cost a lot of tokens.
- **Element screenshots frame only the named element.**
  `browser_take_screenshot` with `ref` on a 40×40 icon saves a
  40×40 PNG. For "show me the toolbar with context," omit `ref`.
- **Xdebug output can pollute AJAX HTML responses** in dev.
  Deprecation notices show as HTML garbage inside `#run_unit_<N>`.
  If you see unexpected `<font size=1>` or `xdebug-error` markup,
  it's noise, not a unit-rendering bug.
- **Save Playwright screenshots under `.playwright-mcp/`**
  (gitignored), never the repo root.

## Example surveys and run bundles

The `documentation/` directory ships fixtures you can feed straight
into the admin UI — prefer these over synthesizing fake data or
inserting rows by hand.

- **`documentation/example_surveys/*.xlsx`** — uploadable XLSform-
  style spreadsheets. Upload via `/admin/survey/` → "Add a new
  survey". Notable: `all_widgets_with_values.xlsx`,
  `just_submit.xlsx`, `just_notes.xlsx`, `test_skipifs.xlsx`,
  `random_order.xlsx`, `progress10.xlsx`, `page1.xlsx` /
  `page2.xlsx`, `break_opencpu.xlsx`.
- **`documentation/run_components/*.json`** — exportable run
  bundles. Import via the admin run editor's "Import" button.
  Notable: `Appstinence.json` (full PWA study, heavyweight),
  `Basic_Diary.json`, `Experience_sampling.json`,
  `Longitudinal_study.json`, `filter.json`, `Reminder.json`,
  `Text_message.json`, `Simple_Social_Network.json`.
- **Google Sheets via "Add a new survey → Import a Googlesheet":**
  All widgets `https://docs.google.com/spreadsheets/d/1vXJ8sbkh0p4pM5xNqOelRUmslcq2IHnY9o52RmQLKFw`.

**Rule of thumb:** start from the smallest fixture that exercises
the code path you changed. Running `Appstinence.json` every time is
wasteful and makes failures hard to isolate.

## BrowserStack real-device tests

`npm run test:bs` runs the suite on real iPhone Safari + Android
Chrome via `browserstack-node-sdk playwright test`. Requires
`BROWSERSTACK_USERNAME` / `BROWSERSTACK_ACCESS_KEY` in env (already
in `.env.dev`). Pin Playwright to ≤1.57; BS doesn't support newer
wire-protocol versions.

Don't commit `log/` or `playwright-browserstack-sdk.config.*` —
SDK runtime artifacts.

## Browser and PWA testing stack — when to use which tool

You have three browser-control surfaces. Use the cheapest one that
answers the question.

1. **Chrome DevTools MCP (`chrome-devtools-mcp`)** — default for
   everyday work. Configure with `--autoConnect` so you attach to
   the already-running Chrome (keeps login state, extensions, the
   tab being debugged). Best for console messages with source-mapped
   stacks, network panel, performance/Lighthouse, viewport/CPU/network
   throttling. Prefer `take_snapshot` (a11y tree) over screenshots —
   more token-efficient and less ambiguous.
2. **Playwright MCP (`@playwright/mcp`)** — when you need cross-
   browser verification (WebKit/Firefox) OR a reusable test rather
   than ad-hoc exploration. Anything non-trivial debugged via
   Playwright MCP should leave behind a script under `tests/e2e/`
   as a regression test.
3. **BrowserStack Automate** (via Playwright, not their MCP) — only
   for real-device verification on iPhone Safari and Android
   Chrome. Open-source plan covers Live, Automate, and Percy. App
   Automate (Appium) is **not** included; PWA-as-website testing on
   real devices uses Automate.

### Standard inner loop

1. Make the code change.
2. Reload via Chrome DevTools MCP at the dev URL.
3. `list_console_messages`. Errors and new warnings are not OK;
   resolve before declaring done.
4. `take_snapshot` and verify DOM/a11y matches intent. Screenshot
   only when the question is genuinely visual.
5. If layout changed, emulate at least 375px (iPhone), 768px
   (tablet), and 1440px (desktop) widths.
6. If non-trivial, run a Lighthouse audit and report any regression
   in PWA / performance / accessibility scores.

**Don't loop more than three times on the same failing assertion
without stopping** to explain what you tried, what evidence is in
front of you, and your two best hypotheses. Looping silently is
worse than stopping.

### Anti-patterns

- Opening a BrowserStack Live (manual) session programmatically —
  burns parallel slots, no structured output.
- Driving a remote real-device session click-by-click via
  screenshots — write a Playwright script and run it as a batch.
- Skipping the console-error check "because it looked fine."
- Hardcoding credentials, even temporarily, even in scratch files.
- Generating UI mockups or fake tool outputs to "demonstrate"
  something. If the real tool didn't return what you needed, say so.

## Track A code surface (v0.26.0)

- `application/Model/UnitSession.php` — the unit-session model.
  Track A's `state` ENUM dual-write, `run_unit_id`, `iteration`,
  `state_log` JSON, and `idempotency_key` columns flow through here
  (see `create`, `end`, `expire`, `logResult`, `buildStateLog`).
- `application/Model/RunSession.php` — orchestrates `execute()`,
  `moveOn`, cascade dispatch, run-session lock,
  `getRunUnitIdAtPosition` helper.
- `application/Queue/UnitSessionQueue.php` — daemon pickup loop.
  Four `QUEUED_*` + seven `STATE_*` constants,
  `stateForQueuedUnit($runUnit)`, `queueLabelForRow($row)`. Sets
  `$runSession->user->cron = true` before `execute()` (A5 closes
  cron_only latent bug).
- `application/Model/RunUnit/{Email,PushMessage,External}.php` —
  v0.25.7 terminal-result guards + Track A `idempotency_key` claims
  (A4 closes R5 daemon-kill orphan double-send).
- `sql/patches/047_uxec_track_a.sql` (schema additions),
  `sql/patches/048_uxec_track_a_backfill.sql` (one-shot historic
  backfill).
- See `documentation/agent_doc/REFACTOR_QUEUE_PLAN.md` and
  `documentation/agent_doc/unit_type_states.md` for the design and
  per-RunUnit state-machine reference.

## When you get stuck

After three failed attempts on the same problem, stop and write up:

1. What I asked for, in your words.
2. What you tried, in order.
3. The actual evidence in front of you now (specific console
   messages, network responses, snapshot diffs).
4. Your two best hypotheses, ranked, with what would distinguish
   them.

Then ask. Don't guess a fourth time.
