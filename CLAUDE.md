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

## Git flow
Use git flow. I.e. development happens in `feature/` branches, no direct push to master/main.
Release: bump version (consistent in VERSION, package.json, changelog) tag and release on Github.

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
npm run webpack:build        # production → webroot/assets/build (slow, ~90s)
npm run webpack:watch        # watch mode  → webroot/assets/dev-build (rebuilds in <1s on save)

# PHPUnit:
composer test                 # unit lane: SQLite :memory:, --exclude-group integration; must stay green
composer test:integration     # integration lane: SQLite or live MariaDB, --group integration
vendor/bin/phpunit --configuration tests/phpunit.xml --filter SomeTest::testName
```

**Built assets are NOT committed (since v1.0.0).** `webroot/assets/build/` and `webroot/assets/dev-build/` are both gitignored. Production bundles are produced by the **`formr_app` Docker image build** (`npm ci && npm run webpack:build && npm run build`, in the deployment repo's `formr_app/Dockerfile`, driven by its `build-images.yml` CI) — there is no committed bundle to refresh and no manual rebuild step before a commit. Do **not** `git add` anything under `webroot/assets/build/`.

**When working on JS/CSS, run `npm run webpack:watch` as a background task and leave it running.** Watch emits to `webroot/assets/dev-build/` and rebuilds in <1s on save; `templates/run/form_index.php` (and the other views) auto-prefer `dev-build/` over `build/` whenever **both** the JS and the extracted CSS exist there, so edits go live on the dev instance with no manual build step. Caveats: (1) `webpack:watch` is killed when the Claude Code session restarts — restart it. (2) the one-shot `npm run webpack:build` is slow (~90s) and only needed to sanity-check a production build locally — not for committing. (3) production `webpack:build` skips the file-write when the output is byte-identical, so confirm a change landed by grepping the bundle for a distinctive string, not by mtime.

`composer test` is the gate for CI. `composer test:integration` covers `DBTest` (MariaDB-only `SHOW TABLES` / `SHOW COLUMNS` paths) and `PushNotificationExpireSubscriptionTest` (needs seeded `survey_studies` + `survey_unit_sessions` rows). Both lanes share `tests/bootstrap.php`, which currently forces SQLite via `Config::initialize` unconditionally — so under SQLite several `@group integration` tests still fail; the live-MariaDB CI lane that would make them pass is described in `documentation/agent_doc/testing.md` along with the per-test deferred-fix punch list.

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
`schema.sql` alone. `schema.sql` can drift from the patch series, and
drift is invisible on fresh installs (they seed from `schema.sql` and
then have every patch marked applied without running it) — run
`bin/check_schema_parity.sh` against a fully-migrated instance at
release time to catch it. Atlas migration numbers
are coordinated with upstream — use the next sequential number
visible in `sql/patches/` **within your line's range**:

- **Release line: `0xx`**, sequential (1.11.0 ends at 081). Until
  form_v2 ships, release-line patches must stay at `099` or below
  (082–099 is free). If that runs out first, move form_v2 once more to
  a higher range rather than letting the two collide.
- **form_v2: `100` and up**, sequential within the branch. Merging a
  release into form_v2 never moves them. Atlas keys its ledger
  (`atlas_schema_revisions`) on the version number alone, so a number
  that changes content marks the new file applied without running it.
  That is why the ranges exist.
- **When form_v2 merges into the release line**, the release line's
  next patch continues above form_v2's highest number (116, …), so
  production hosts only ever apply versions in increasing order.
- Hosts that track form_v2 set `ATLAS_EXEC_ORDER=non-linear` in
  formr-docker's `.env`: a release patch merged in later (082, …) sorts
  below the applied 1xx ones, and linear order would refuse it.
- Keep three digits. Atlas orders files by name, so mixing widths
  (e.g. timestamps) would put versions out of numeric order.

**Author every patch drift-tolerant.** Deployed instances are not all in
the same state: a fix is often hand-applied to a production host before
the release that ships it as a patch, and a host's Atlas ledger can end up
behind its schema (see formr-docker's `db_atlas_reconcile.sh`). A patch
that assumes a pristine starting schema then dies mid-file on the first
object that is already there — and because DDL auto-commits, it leaves the
host half-migrated. MariaDB accepts a guard on every form these patches
use, so write them that way:

```sql
ALTER TABLE `t` ADD COLUMN IF NOT EXISTS `c` INT NOT NULL DEFAULT 0;
ALTER TABLE `t` ADD INDEX IF NOT EXISTS `idx_c` (`c`);
ALTER TABLE `t` ADD UNIQUE KEY IF NOT EXISTS `uq_c` (`c`);
ALTER TABLE `t` ADD PRIMARY KEY IF NOT EXISTS (`id`);
ALTER TABLE `t` DROP INDEX IF EXISTS `old_idx`;
ALTER TABLE `t` DROP COLUMN IF EXISTS `old_col`;
ALTER TABLE `t` DROP FOREIGN KEY IF EXISTS `fk_old`;
ALTER TABLE `t` ADD CONSTRAINT `fk_new` FOREIGN KEY IF NOT EXISTS (`pid`) REFERENCES `p` (`id`);
CREATE TABLE IF NOT EXISTS `t2` ( ... );
```

Note the FK guard goes after `FOREIGN KEY`, not after `CONSTRAINT`.
`ALTER TABLE ... ENGINE=` and `MODIFY COLUMN` are already no-ops when the
target state matches. A guard cannot save an `ALTER` whose *table* is
missing, so anything a patch alters must have been created by an earlier
patch — not only by `schema.sql`, which is how patch 066's
`survey_resource_survey_sizes` line broke every instance predating that
table. DML has no equivalent guard: prefer `INSERT ... ON DUPLICATE KEY
UPDATE` over a bare `INSERT` so a re-run cannot double-write.

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
- `bin/form_v2_compat_scan.php <study_id|study_name>` — read-only
  form_v2 upgrade-readiness report for one study (delegates to
  `FormV2CompatScanner`). Exits 0 when nothing is flagged, 2 otherwise,
  so it works as a CI gate.
- `bin/backfill_study_iteration.php [--dry-run] [--study=N]` —
  one-shot, idempotent backfill of
  `survey_unit_sessions.study_iteration` +
  `survey_studies.last_iteration` from the per-study wide results
  tables, for instances that had data before patch 108 added the
  columns. Fills only rows where `study_iteration IS NULL` and raises
  `last_iteration` with `GREATEST(...)`, so it is safe to run after live
  allocation has started; a study is skipped only when it has no NULL
  rows left and its `last_iteration` is already caught up. (No `--force`
  — it was removed along with the old `last_iteration > 0` skip.)
  A `study_iteration` belongs to one session of its study (patch 118's
  UNIQUE `(unit_id, study_iteration)`), and `allocateStudyIteration`
  allocates above every number a session of the study holds, not only
  above the counter.
- `bin/gen_pwa_items_xlsx.php` — regenerates the committed
  `documentation/example_surveys/pwa_items_{default,solo}.xlsx` pair
  (byte-identical sheets; `layout` is a study setting, not a column,
  so the pair exists to be uploaded twice with the setting flipped).
  Run it after changing the PWA item set; don't hand-edit the xlsx.
- `bin/bot_check_smoke.php` — live-MariaDB smoke for the `bot_check`
  challenge (mint / verify / forged / re-bind / missing-key
  fail-closed). Invoke via `docker exec formr_app php
  /var/www/formr/bin/bot_check_smoke.php`.
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
  expiry, cookie lifetime.
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
  tokens. Auth relies on session cookies + same-site. The one
  deliberate exception is the signup form's `_form_minted` field
  (`RegistrationGuard`, v1.9.0): it is a bot-friction timestamp
  (signed, session-bound, single-use) on an *unauthenticated* form,
  not a CSRF token, and it must not spread to authenticated forms.
- **A POST is a submission only for the unit session it names.**
  The engine runs units inside whatever request moves the run session:
  a participant's page, a v2 endpoint (the final submit runs the next
  unit), the admin's send-to-position, the API's execute/advance, and
  every unit a cascade reaches. `Run::exec` (participant pages and
  survey previews) records the POST in `PageSubmission`; a unit gets it
  through `PageSubmission::forUnitSession()` only when the form's hidden
  `session_id` names that unit session. Units never read `$_POST`,
  `$_FILES` or the request method themselves — that is how a paged
  survey reached by send-to-position logged `error_survey`, and how a
  request's fields could be stored as a next survey's answers.
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

**Before cutting a release, run the pre-release test gate** — the unit
lane plus every `bin/*_smoke.php` live-MariaDB smoke, on the dev stack,
cutting only if green. The SQLite CI lane cannot host the smokes, which
is how the v1.10.3 regression shipped. Procedure and cut steps:
`documentation/agent_doc/releasing.md` (companion to `testing.md` and
`regression_prevention_plan.md`).

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

### Project-scoped subagent: `ui-playwright-tester`

Defined at `.claude/agents/ui-playwright-tester.md` and auto-registered for any Claude Code session started in this repo. Use it via the Agent tool with `subagent_type: "ui-playwright-tester"` when:

- You just modified `templates/`, `webroot/assets/`, a view-rendering controller, or RunUnit rendering logic, AND
- You want a real-browser E2E smoke test rather than only type-checks / unit tests / curl.

Typical invocations (the agent handles login, test-run setup, screenshot capture, and cleanup on its own):

- "Smoke-test the participant flow for a multi-page Form RunUnit."

The agent has the full Playwright MCP tool surface, project memory, and knowledge of the dev credentials layout. It knows the operational gotchas above (stale snapshot refs, cookie-consent dialog, xdebug leakage into AJAX responses, `.hidden` specificity, etc.) — you don't need to brief it on those. Do give it a specific scenario to test; a bare "please test everything" will have it run the golden-path smoke and stop.

**When NOT to delegate:** iterating on a specific selector or a specific bug you can reproduce in one click/snapshot cycle. Direct Playwright MCP calls from the main agent are faster. Delegate when the test involves multiple navigations, multi-page interactions, screenshot comparison, or cleanup of created state.

## form_v2 development notes

Specific gotchas worth knowing when touching `feature/form_v2` code. Deep-dive rationale is in `documentation/agent_doc/plan_form_v2.md` (§3 R evaluation, §5 offline queue, §8 remaining work).

- **v1 and v2 assets are physically separate bundles — not selector-scoped.** Editing `webroot/assets/form/**` (the v2 `form` webpack entry: `form.bundle.js` + `form.bundle.css`, source `webroot/assets/form/js,css/**`, e.g. `form.scss`) **cannot** affect v1, because v1 never loads that bundle. v2 is rendered by `FormRenderer` via `templates/run/form_index.php`, which is the *only* place `form.bundle.css/js` is `<link>`ed/`<script>`ed. v1 (`SpreadsheetRenderer`) renders via `templates/public/head.php` → `print_stylesheets()` (the legacy `build-scripts/` pipeline + `webroot/assets/site/**`), which never references the `assets/form/` tree. So: form_v2 styling/JS work goes in `webroot/assets/form/**` and is inherently v1-safe; v1 fixes go in `webroot/assets/site/**` / `build-scripts/`. The `.fmr-form-v2` selector scoping in `form.scss` is belt-and-suspenders, **not** what isolates v1 — the bundle split is. (Don't "protect v1" by adding selectors to a v2-only file, and don't assume a shared stylesheet exists.)
- **Form unit identity:** a Form unit, like a Survey unit, IS its study: every placement's `survey_run_units.unit_id` is the study id (patch 116), and the RunUnit factory picks the class from that `survey_units` row's type, which follows the study's engine — `'Form'` while `rendering_mode='v2'`, `'Survey'` otherwise (`SurveyStudy::syncUnitType`, run by `save()` and `adoptFormV2()`). So switching a study's engine switches every placement with it. There is one "Add Survey" button: a unit links a survey on either engine and never changes the engine (only the survey settings do, `AdminSurveyController::switchRenderingModeAction`); `Form` has no `create()` of its own and shares the Survey dialog (`templates/admin/run/units/survey.php`). If a Form unit renders like a Survey, check the study's `rendering_mode` and its unit row's type.
- **Undeclared DB columns are silently dropped.** `Model::assignProperties` uses `property_exists($this, $prop)` — every column you want to read must be declared as a public property on the Model subclass. When you add a patch that adds a column, also add the `public $column_name = <default>;` declaration. Skipping this is how `rendering_mode` on SurveyStudy silently returned the default and the v2 branch never fired.
- **`SurveyStudy::toArray()` is a write allowlist.** `Model::save()` (→ `Model::update()`) writes back only what `$this->toArray()` returns — not every public property. A brand-new `public $offline_mode = 1` makes the read side work but writes through `$study->update($settings)` silently drop the field. Three touch points for every new SurveyStudy column: (1) patch file, (2) `public $foo = <default>` declaration, (3) entry in `toArray()`. If a settings-form round-trip looks like a no-op, check `toArray()` first.
- **`form_v2` passthrough:** Form::getUnitSessionOutput → RunSession::executeUnitSession → Run::exec → RunController::indexAction / SurveyTestController. Each layer has a fixed-shape dict that drops other keys, so the v2 page state travels as ONE array under `form_v2` (`language`, `strings`), passed whole; its presence selects `run/form_index`. New v2 page state goes inside that array — no layer needs editing.
- **FormRenderer renders every item of the study, every time.** v1's `SpreadsheetRenderer::processItems` breaks after the first submit-delimited chunk and skips answered items; v2's `getStudyItems()` has neither filter, so the document is the same whatever the participant has done. What differs per session is the **state block** (`FormRenderer::stateBlock()` → `<script class="fmr-state">`: `unit_session_id` + per item `{id, page, answerable, required, answered, saved, value, hidden}`, shaped by `FormSubmissionService::itemState`) — it drives the browser's navigation and seeds the showif scope. Stored answers themselves are rendered by each item (`FormRenderer` sets `value_validated` from `survey_items_display.answer`, the way v1 re-renders after a validation error), so a slider, a rank or a ticked box comes back exactly; the browser does no restoring. A no-input item's value (random, ip, browser, server…) is shipped only if some showif reads its name (`FormSubmissionService::showifNames`). Required = `FormSubmissionService::isRequired` (the item's resolved `optional`; `blank` never), one rule for the state block and `UnitSession::unansweredRequiredItems`. Don't re-introduce chunking or answered-filters in v2 unless you mean it.
- **The browser owns "which page".** `webroot/assets/form/js/state.mjs`: `createStore(block)` holds the state block (merged with every submit reply's `items`, marked from a queued submit), and the pure `pageState(snapshot, store, remembered, allowPrevious)` derives the current page (the `localStorage`-remembered one for this unit session, else the first page with a visible answerable item lacking an answer), `nextAfter`, `previousOf`, first/last and progress. `main.js` feeds it `snapshotPages()` (live showif visibility per item) and every navigation consumer calls it: initial page, after a live or queued submit (`goToPage`), Previous/popstate, the progress bar, `applyNav` (Previous visibility + Next/Submit wording on `[data-fmr-nav-label]`) and the solo controller's `pageNav`. The server never names a page: a submit reply is `{status, items, complete?, missing?, redirect?}` (`FormSubmissionService::apply`; `redirect` is added by `RunController::withRunRedirect` once `complete`). A page of nothing but `hidden` items (snapshot `automatic`) is never shown: `pageState().ridersOf(p)` names the ones a submit of shown page `p` carries (the last shown page before each, else the first after), and `submitPageInner` resolves them first (`settleAutomaticPages`, repeated while a resolved value moves the next shown page), then adds their answers to the payload; those before the first shown page are resolved before the landing page is chosen. `npm run test:js` covers the rules; `tests/e2e/v2-resume.spec.js` the reload/offline/Previous agreement. Design: `documentation/agent_doc/plan_form_v2_navigation_state.md`.
- **"Test Survey" previews v2 as a Form.** `Run::testStudyUnit()` builds a `Form` (with `surveyStudy` set) for a v2 study, a `Survey` otherwise; `SurveyTestController` renders `run/form_index` for it, rewrites the TEST_RUN URLs (including the form's `data-*-url`s and `data-run-url`) to `/survey-test/<token>/`, and routes `/survey-test/<token>/form-page-submit|form-sync|form-save|form-render-page|form-bot-challenge` to `RunController::dispatchFormV2Preview()`, which runs the participant action unchanged with the preview's unit session as the gate's context (`previewWriteContext`; rate limits keyed by unit session). The final submit ends the unit session without moving a run (`RunSession::endFormUnitSession` short-circuits for the dummy test session) and redirects to the preview URL, where `Run::testStudy` sees the ended Form and shows "Finish". `study_engine_badge()` (Functions.php) is the admin's form_v2/v1 label; `SurveyTestController::previewNote()` the preview's. Covered by `tests/e2e/v2-preview.spec.js`.
- **Page grouping lives in `survey_items_display.page`.** `UnitSession::createSurveyStudyRecord` writes page numbers at initial render (bumping at each submit item). FormRenderer reads that back in `fetchPageMap()`. No new schema needed for multi-page — just query the existing column.
- **A v2 form is completed by its final submit, never by a GET.** The browser flags the submit of the last shown page `final: true`; `FormSubmissionService::apply` then checks `UnitSession::unansweredRequiredItems()` (required, `answer IS NULL`, stored `hidden <> 1`; the browser posts `hidden: [ids]` for the whole form on every submit so those flags are current), and either answers `missing` (name → message; the browser routes to that page) or ends the unit session **and** moves the run on (`RunSession::endFormUnitSession`, under the run-session lock): the next unit executes right away, as on a page load, so a participant who closes the tab still gets the Pause or Email that follows, and a redirect it returns (an External's address, a paged survey's first page) becomes the reply's `redirect` instead of the run URL. `Form::getUnitSessionOutput` does not call `studyCompleted()`.
- **MySQL datetime format matters.** Client timestamp fields (`.item_shown`, `.item_shown_relative`, etc.) must be `YYYY-MM-DD HH:MM:SS` — ISO-8601 with the trailing `.sssZ` crashes `survey_items_display.shown` with "Incorrect datetime value". Use `new Date().toISOString().slice(0, 19).replace('T', ' ')` (same helper as v1's `common/js/main.js`).
- **Client payload matches PHP `$_POST` semantics.** Names ending in `[]` are arrays; everything else is scalar-last-wins. A Check_Item emits a hidden+checkbox pair sharing a name — promoting same-named inputs into arrays turns that pair into `["0", "1"]` and crashes the server on `h(array)`. If you're writing JSON-payload client code, match these semantics or Check_Item will be the canary.
- **Bootstrap 3 and 5 coexist via an npm alias.** `package.json` has `"bootstrap": "^3.4.1"` (admin) and `"bootstrap5": "npm:bootstrap@^5.3.8"` (form_v2). The form bundle imports `from 'bootstrap5/...'`. Don't `npm install bootstrap@5` — it clobbers the admin's BS3 dep.
- **v2 showif reactivity is Alpine-driven.** The showif column is emitted as written: `FormRenderer::getStudyItems` calls `Item::useVerbatimShowif()` on every item (v1's regex R→JS translation, `Item::showifToJs`, is v1-only and runs on each render; nothing is cached). `Item::hideByDefaultPendingClient()` sets `data_showif` on every showif-bearing item so the attribute is emitted unconditionally. The form bundle promotes `data-showif` → `x-showif` on init and registers an `Alpine.directive('showif', …)` that wraps evaluation in `(()=>{try{…}catch(e){return undefined}})()` and strips `//` + `/* */` comments first (v1's `//js_only` marker otherwise swallows our wrapping closing paren → SyntaxError in `new AsyncFunction()`). The `fmrForm` component exposes one top-level reactive field per input name plus helper methods (`isNA`, `answered`, `contains`, `containsWord`, `startsWith`, `endsWith`, `last`). Alpine's `effect()` handles dep-tracking + re-run. Expressions referencing unknown names (a run variable, another survey's item) silently evaluate to undefined instead of spamming console. **Every showif item renders hidden** via `Item::hideByDefaultPendingClient()` (so nothing flashes visible / blocks before Alpine runs, the iOS-Safari symptom), and on an `undefined` result the directive **preserves the current visibility** (`!el.classList.contains('hidden')`) — so an unknown name means the item stays hidden. The names the browser does know: every input on the form (stored answers are rendered back into them), plus the values in the state block (`FormRenderer::stateBlock()` → `script.fmr-state` → `fmrForm._seed(values, true)` at init, and each submit reply through `absorbReply` in `main.js`) — the only way a no-input item's value (random, ip, …) reaches a showif. **Note:** the directive still toggles `.hidden` class + `style.display` + `input.disabled` — toggling `style.display` alone can't override Bootstrap's `.hidden` class (it ships `display:none !important`).
- **`:invalid` doesn't match readonly required inputs.** Geopoint's visible field is both; native client validation silently passes. Server is the fallback. Don't treat `page.querySelector(':invalid')` as the definitive gate.
- **v2 runs no R at render time for what the participant sees.** `FormRenderer::processItems` evaluates no showif and resolves no value/label: every dynamic `value`, label, choice label (slot `choice_label`) and `note_iframe` is recorded in `survey_r_calls` and resolved by the client through `/form-render-page` when its page is shown — the first page exactly like later ones (`main.js` calls it on load; page transitions wait at most `PAGE_RESOLVE_WAIT_MS` before showing the page). No R at render at all, and no server-side computation anywhere: **`calculate` is not supported in v2** (nor any other no-input item with an R `value`). `Item::formV2Unsupported()` is the one rule; it refuses such items at upload into a v2 study (`SurveyStudy`), at the switch to v2 (`AdminSurveyController::switchRenderingModeAction`, via `SurveyStudy::formV2UnsupportedItems()`), when a Form unit links a study (`Form::create`, which then leaves the Form unlinked), and the compat scanner lists them (`unsupported`, blocks the switch). What the form needs is a `hidden` item with that `value` (resolved on page show, submitted with the page); scores for feedback or later units are computed in those units' R. `FormRenderer` drops any such item that is still in a study, so its R source never reaches the page. `sticky` is recorded as written and expanded at evaluation (`RCallService::expandValueExpr`).
- **Tom-select doesn't react to direct DOM mutation.** `select.selectedIndex = 1` won't update the tom-select UI or fire its listeners. Use `select.tomselect?.setValue(...)` from test code and UI polish. Similarly, triggering client-side showif re-evaluation requires a bubbling `change` event — setting `.checked = true` directly won't re-run `applyShowifs`.
- **showif is JavaScript in v2 — that is the migration rule.** Every v1 showif is R evaluated server-side, so upgrading a study means rewriting each one as JS, or computing it in R via a **hidden item whose `value` is that R** and referencing that item's name from the (JS) showif. `FormV2CompatScanner` buckets a study's showifs (`js_ok` / `needs_js_rewrite`) for exactly this, and `bin/form_v2_compat_scan.php` runs the same scan from the CLI. The column is used as written — nothing translates R idioms (`&`, `TRUE`, `%contains%`, `is.na()`) for v2. A showif that isn't usable JS has **no** server-side re-evaluation path: the client's evaluator throws, the catch preserves the item's rendered state (hidden), and the author sees nothing — so treat a `needs_js_rewrite` or `not_on_form` finding (a name that isn't an item of the form, e.g. a run variable) as work to do before the switch, not a warning to sit on.
- **`DB::select()->…->fetch()` ≠ `DB::findRow($t, $w)`.** `fetch()` is the one-row method on `DB_Select` (the builder returned by `DB::select()->from()->where()`). `findRow` is the short-hand on `DB` itself. `fetchRow` is neither — the linter won't catch it because `fetchRow` isn't a typo on any PDO-ish class either. One end-to-end smoke after a controller edit is worth five linter passes.
- **File upload switches to FormData automatically.** The v2 client's default JSON submission can't carry file bytes. When `submitPage()` sees any `input[type=file]` with a selected file on the current page, it builds a `FormData` with flat keys `data[<name>]`, `data[<arrname>][]`, `item_views[<bucket>][<id>]`, **plus** `files[<name>]` for the blob — file bytes live outside `data`. Server `formPageSubmitAction` Content-Type-sniffs multipart, reads `$_POST['data']` + `$_POST['item_views']` parallel to the JSON path, and re-projects `$_FILES['files'][name|type|tmp_name|error|size][itemName]` into the flat `{name,type,tmp_name,error,size}` dict that `File_Item::validateInput` expects.
- **Playwright MCP `browser_file_upload` is path-restricted.** Only files under the repo root or `.playwright-mcp/` are allowed; `/tmp/…` throws "outside allowed roots". Stage fixtures under `.playwright-mcp/` (already gitignored) when you need to smoke-test an upload.
- **Participant subdomains on this dev use `study.researchmixtape.com/<runName>/`, not `<runName>.researchmixtape.com/`.** The latter 404s/cert-errors because wildcard subdomains aren't wired here (DNS_API isn't hetzner in this stack). Use `https://study.researchmixtape.com/<runName>/?code=<code>` for the "Use run as yourself" flow. If a test run has no Stop unit the session dangles after the Form completes ("Oops, creator forgot a Stop") — either add a Stop or reset with `DELETE FROM survey_unit_sessions WHERE run_session_id=?; DELETE FROM survey_run_sessions WHERE id=?;`. Dangling sessions also trip up v2 POST endpoints: a run session with `ended` set and `current_unit_session_id` pointing at the ended Form shows an empty page on GET (`Form::getUnitSessionOutput` renders nothing for an ended or expired unit session), and every v2 endpoint answers 409 "No current unit session" because `getCurrentUnitSession()` filters ended sessions. If a POST 409s, suspect stale state before debugging the endpoint.
- **The `value` column is the R path, and it resolves page-scoped.** `FormRenderer::processItems` Step 1 records the value, as written, in `survey_r_calls` slot='value' — dedup by `study_id + expr_hash + slot + item_id`, one row **per item** even for identical expressions because `/form-render-page` resolves a page's calls via `r.item_id` (`FormRenderer::ensureRCalls` → `RAllowlistExtractor::ensure`: one SELECT per study, INSERT only for missing rows) — emits `data-fmr-fill-id`, and blanks `$item->value` on **every** page, the first included. The client resolves a page's values, labels and choice labels in one POST to `/{run}/form-render-page` when the page is shown (`resolveAndSubstitutePage` in `main.js`: `.fmr-fill-pending` while in flight; `setInputValue` — the one fill path for R-resolved values — ticks the matching choice for mc/select/check items — their first named input is a hidden placeholder — and writes nothing into an item of the participant's whose answer is already stored (`saved`/`answered` in the store), so an answer given is never overwritten by its R default; fires input+change so showifs re-evaluate). A `hidden` item's value is replaced on every resolution when the reply lists its call in `follows` (`RCallService::valueFollowsAnswers`: the R, as written, names another item of the study — not `sticky`, not itself, not just the survey), and otherwise only fills an empty input, so a `sample()` is not redrawn. Page 1 therefore paints its dynamic content a moment late — a deliberate trade-off. The `answers` it posts are raw strings; the server types them per results column (`RCallService::formatROverlay`). The server sends the page's OpenCPU requests at once (`RCallService::resolvePage` → `opencpu_post_many` → `OpenCPU::postMany`, curl_multi): the values in one batch, the labels and choice labels in one knit, each `note_iframe` in its own.
- **Every v2 write goes through one gate and one apply path.** `RunController::formWriteContext($claimedUnitSessionId, $extra409, $rateBucket)` is the shared gate (run session → the run's access rule, `Run::admits`, as for the page → rate limit → current unit session → v2 Form → stale-tab check: a write must name the unit session its page was rendered for, a missing id is refused like a wrong one) for form-page-submit, form-sync, form-save, form-render-page and the bot_check mint; `FormSubmissionService::readPayload` reads JSON and multipart into one shape (JSON scalars normalised to `$_POST` strings; `hidden[]`, `final`, `files[name]`), and `FormSubmissionService::apply` is the one store-a-page path that form-page-submit and form-sync share (sync goes through `FormSubmissionService::applyQueued`: a named lock on the entry's uuid, and the uuid recorded in `survey_form_submissions` only once the page is applied, so a request that dies part way leaves nothing that blocks the retry). Client-side, `buildFormData` in `offline/queue.js` is the one multipart builder for live and queued submits. Add a step to the service, not to an action.
- **`classes_wrapper` is `protected` on Item.** `parent_attributes` is public, `classes_wrapper` is not. Touching it from outside `Item` (e.g. `$item->classes_wrapper[] = 'x'` in FormRenderer) yields `Fatal: Cannot access protected property`. To decorate the wrapper with a CSS class from FormRenderer-level code, either add a public `addWrapperClass()` method or tag the class client-side in the form bundle (what the deferred-fill pending indicator does).
- **Fixture uploads via curl + cookie jar, not inline base64.** `browser_file_upload` from Playwright MCP requires the page to have raised a native file-chooser modal (user activation on a real `<input type=file>`), and clicking the wrapper doesn't always trigger one. Don't go inlining xlsx as base64 into `browser_evaluate` — instead: `curl -c cookies.txt -X POST .../admin/account/login/ -F email=... -F password=...` to stash HttpOnly cookies, then `curl -b cookies.txt -X POST .../admin/survey/add_survey/ -F new_study=1 -F uploaded=@fixture.xlsx`. Same trick works for any multipart admin endpoint.
- **Button-group items (mc_button / check_button / mc_multiple_button / rating_button) lose their hide-real-inputs CSS in v2.** v1's `.js_hidden { display:none !important }` lives in the frontend bundle which v2 doesn't import; without a local copy in `webroot/assets/form/css/form.scss`, every mc_button item renders BOTH the raw `<label><input type=radio></label>` list AND the visible `.btn-group`. Re-assert the rule in form.scss. Click wiring: `initButtonGroups()` in `main.js` walks `.form-group.btn-radio / .btn-checkbox / .btn-check`, pairs each `.btn[data-for]` with `input#<data-for>`, toggles `btn-checked` + the real input, clears siblings on radio. Validation: listen for `invalid` on the hidden required inputs (native Constraint Validation fires even when `display:none`), surface the browser's localized `validationMessage` as an inline `.fmr-btn-feedback` next to the visible button group. No jQuery, no webshim.
- **The offline queue drains page-side; the service worker takes no part in it.** `webroot/assets/form/js/offline/queue.js` wraps IndexedDB (via `idb`): db `formrQueue`, store `queue`, keyPath `uuid`, `client_ts` index. On network failure or 5xx from `/form-page-submit` (`submitPageInner` in `main.js`) the payload is persisted and the banner shows "queued"; the participant advances locally. Drain triggers are the page's `online` event, initial load and a retry timer (entries drain oldest-first by `client_ts`, then the millisecond `queued_at`, a final page last within its unit session), POSTing each entry to `/{run}/form-sync` (`RunController::formSyncAction`, which also accepts multipart for queued files); the server applies each entry once (`FormSubmissionService::applyQueued`; a second request for the same entry while one applies it gets 429, which the drain retries) and applies through the same `UnitSession::updateSurveyStudyRecord` path as `/form-page-submit`. Entries carry their own `sync_url` because the queue DB is **origin-wide** — on path-routed deploys a drain running on another run's page must post each entry back to the run it belongs to — plus `unit_session_id`, so the server can `drop_entry` if the participant has since advanced to a different Form unit. Nothing drains while the tab is closed: there is no Background Sync hookup, and `registerFormSW` (`items/pwa.js`) registers the served worker (`webroot/assets/common/js/service-worker.js`, `RunController::serviceWorkerAction`) only on PWA runs (`window.vapidPublicKey`), like v1's `pwa-register.js`. A live page submit first drains only its own run + unit session group (`drainQueue('own')`). A page queued behind a waiting final page gives that final entry its newer `hidden` list (`queueRestampFinal`). Every write carries the document's id and a write number (`collectPayload`: `doc`, `seq`); an autosave numbered below a page submit of the same document that has landed is answered `stale` (`FormSubmissionService::autosaveIsStale`). Entries already queued still drain after the study's `offline_mode` is turned off (`ownSyncUrl`); only new submissions stop being queued (`syncUrl` is empty). **File submissions ARE queued** — `entry.files[<inputName>]` holds the `File` blobs, stripped out of `entry.data` so the server's `$_POST['data']` doesn't get a stringified File beside the `$_FILES` entry. Guard rail: any single file over `window.formr.form_v2.offline_blob_max_mb` (`Controller::getJsConfig` ← `$settings['form_v2_offline_blob_max_mb']`, default 10 MB, `config-dist/settings.php:432`) is **not** queued — the participant gets a "too large to queue offline" alert instead, because multi-hundred-MB Blobs in IDB can blow the per-origin quota and leave the queue undrainable. Same hard-error path for new submissions when the study has `offline_mode` off. **Timestamps going to the server must be MySQL DATETIME format** (`YYYY-MM-DD HH:MM:SS`) — `/form-sync` answers ISO-8601 with `.sssZ` with 400 (`FormSyncPayload`); the drain's `normalizeSyncEntry` fixes up entries an older bundle stored. Use the existing `mysqlDatetime()` helper. **UUIDs must be RFC 4122 8-4-4-4-12 hex** — the server regex-rejects anything else with 400 (`FormSyncPayload::UUID_PATTERN`).
- **Alpine drives v2 showif** (replaces the hand-rolled `applyShowifs` evaluator). `Alpine.data('fmrForm', …)` on the `<form>` exposes one reactive top-level field per input name plus helpers (`isNA`/`answered`/`contains`/`containsWord`/`startsWith`/`endsWith`/`last`); `Alpine.directive('showif', …)` runs expressions through `evaluateLater` + `effect()`. The bundle promotes server-emitted `data-showif` → `x-showif` at init and adds `x-data="fmrForm"` on the form — no server changes. Two hardening steps are load-bearing: **strip `//` and `/* */` comments** before wrapping in parens (v1's `//js_only` marker otherwise swallows the closing paren → SyntaxError at `new AsyncFunction()` time), and **wrap runtime eval in `(()=>{try{…}catch(e){return undefined}})()`** so references to server-only run vars (`ran_group`) fall back to undefined rather than flooding console. On `undefined` the directive **preserves the server's render decision** (NA is hidden by default — server emits `.hidden`, matching v1), NOT force-visible. Keep the separate `collectAnswers()` DOM-reading helper (`lib/inputs.js`) for the submit and `/form-render-page` payloads; reusing Alpine state cross-scope needs `root._x_dataStack[0]` which is fragile.
- **Programmatic `radio.checked = true` does NOT auto-uncheck same-`name` siblings.** The browser's native radio-group sync only fires on user-initiated events (click, keyboard). In Playwright/`browser_evaluate` or any DOM-scripted test, assigning `.checked` on a radio leaves previously-checked siblings in `checked=true` state; `document.querySelector('input[name=X]:checked')` returns the first one (document order), and Alpine's `_syncInput` captures the wrong value. Fix: either click the visible `.btn[data-for]` button (initButtonGroups clears siblings imperatively) or explicitly `[...document.querySelectorAll('input[name=X][type=radio]')].forEach(r => r.checked = false)` before setting the target. Same gotcha applies to any framework that mirrors DOM state.
- **Webpack production builds skip the file-write when output is byte-identical.** `npx webpack --mode production` compiles and reports "compiled with N warnings in Xs" but does NOT update `webroot/assets/build/js/form.bundle.js`'s mtime if the resulting bytes match the previous build's. `ls -la` thus misleads: "bundle is old, my edits must not have landed." They did — the build decided it didn't need to write. Confirm by grepping the bundle for a distinctive string from your source (`grep fmrForm webroot/assets/build/js/form.bundle.js`), not by mtime. `touch main.js` + rerun doesn't force a write either unless the emitted bundle changes. The admin layout's `?v=<timestamp>` query appends cache-bust, so browser-side reloads are fine once the bundle does change.
- **`bin/form_v2_compat_scan.php <study_id|study_name>`** classifies every non-empty `showif` / `value` in a study. The logic lives in `application/Spreadsheet/FormV2CompatScanner::scan()` so `AdminSurveyController::formV2CompatScanAction` can render the same report in the admin UI. Buckets follow the JS-only-showif rule: `showif` → `empty` / `js_ok` / `needs_js_rewrite` / `not_on_form`; `value` → `empty` / `literal` (numeric) / `r` (everything else, allowlisted and evaluated as R — never flagged); plus `unsupported` (column `type`: `Item::formV2Unsupported`, blocks the switch). Flagged rows carry a `suggested_fix` of `rewrite_in_js`, `bridge_hidden_item`, `use_hidden`, `edit_choices` (a `select_or_add_multiple` choice with a `;`, v2's answer separator — `SelectOrAddMultiple_Item::formV2Unsupported`) or `pack_with_semicolons` (v1 only: a select_or_add list written in one cell with commas, which v2 splits at `;` — `SelectOrAddOne_Item::unpackChoices`; soft-blocks the switch like a showif finding); the admin report and the CLI each map those to wording, so a new fix code needs both. The heuristic scans the showif as written for R (R-only calls incl. `current`/`tail`, `is.na`, `%…%` operators, single `&`/`|`, `TRUE`/`FALSE`, `::`, `NA`, `<-`, `$`) with comments and string literals stripped so tokens inside labels don't false-positive. Exits 0 if clean, 2 if anything flagged — usable as a CI gate. Informational only; doesn't mutate `survey_items.showif`. Use it before upgrading an existing v1 study to `rendering_mode='v2'`.

## Example surveys and run bundles

The `documentation/` directory ships fixtures you can feed straight
into the admin UI — prefer these over synthesizing fake data or
inserting rows by hand.

- **`documentation/example_surveys/*.xlsx`** — uploadable XLSform-
  style spreadsheets. Upload via `/admin/survey/` → "Add a new
  survey". Notable: `all_widgets_with_values.xlsx`,
  `just_submit.xlsx`, `just_notes.xlsx`, `test_skipifs.xlsx`,
  `random_order.xlsx`, `progress10.xlsx`, `page1.xlsx` /
  `page2.xlsx`, `break_opencpu.xlsx`. form_v2: `form_v2_basic.xlsx`
  (smallest v2 study: a `mc` with a JS showif on a follow-up, a
  `rank random touch`, a `visual_analog_scale`, a `bot_check`, one
  submit), `all_widgets_v2.json` (every item type v2 supports bar the
  PWA items, with JS showifs and hidden-item bridges; upload via "Add a new
  survey"; walked end to end by `v2-finish.spec.js` as run `e2e-awv2`),
  `pwa_items_default.xlsx` /
  `pwa_items_solo.xlsx` (the four PWA prompt items; see
  `bin/gen_pwa_items_xlsx.php`).
- **`documentation/run_components/*.json`** — exportable run
  bundles. Import via the admin run editor's "Import" button.
  Notable: `Form_v2_Basic.json` (a Form unit + Endpage; the Form
  unit embeds `form_v2_basic.xlsx` as `survey_data`, whose settings
  say v2, so one import creates the study on v2 and links it — Form units round-trip
  through export/import like Survey units since the 2026-09-03
  fixes), `Appstinence.json` (full PWA study, heavyweight),
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
  Track A's `state` ENUM dual-write, `run_unit_id`, `iteration`
  and `idempotency_key` columns flow through here (see `create`,
  `end`, `expire`, `logResult`, `resolveEndResult`). `result` and
  `result_log` are a matched pair — every writer sets both. The
  `state_log` JSON column was dropped in v1.8.0 (patch 073).
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
