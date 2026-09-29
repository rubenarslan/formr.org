# Upgrading a formr instance to the form_v2 release

Operator runbook; follow the steps in order. Nothing here changes how
existing v1 surveys render — form_v2 is opt-in per study (step 5) — but
the schema and the `iteration` variable change for everyone, so read
steps 1 and 3 even if you never enable v2. For what form_v2 *is*, see
[form_v2.md](form_v2.md).

## 1. Schema migrations (100–115)

Sixteen new patches in `sql/patches/`. On the docker deployment they are
mirrored into `mysql/atlas/migrations/` and applied by `./update.sh`;
nothing extra to run.

| Patch | What it does |
| --- | --- |
| `100_survey_studies_rendering_mode` | `survey_studies.rendering_mode` ENUM('v1','v2'), **default `v1`** — the per-study engine switch. |
| `101_survey_units_form_study_id` | `survey_units.form_study_id` (+ index): lets a Form unit reference a SurveyStudy without sharing its primary key the way v1 Survey units do. |
| `102_survey_r_calls` | New `survey_r_calls` table: the per-study allowlist of the R expressions (dynamic values and labels) the server will evaluate. Raw R never reaches the browser. |
| `103_survey_form_submissions` | New `survey_form_submissions` table: UUID ledger so an offline-queued page submission that is retried is applied once. |
| `104_form_v2_survey_study_flags` | `survey_studies.offline_mode` (default 1) and `allow_previous` (default 0). v1 ignores both. |
| `105_survey_r_call_results` | New `survey_r_call_results` table: memoizes evaluated R results. |
| `106_survey_items_showif_js` | `survey_items.showif_js`: caches the R→JS transpile of `showif` at import time. Nullable; items without it re-transpile at request time. |
| `107_survey_r_call_results_session_scope` | Re-keys that cache on `(call_id, unit_session_id, args_hash)` and adds the cascade FK, closing a cross-participant cache leak. **`TRUNCATE`s the cache table** — that is a cold cache, not participant data. |
| `108_study_iteration` | `survey_unit_sessions.study_iteration` (+ index) and `survey_studies.last_iteration`: the long-format source of truth for the `iteration` variable. **Needs the backfill in step 3.** |
| `109_survey_studies_layout` | `survey_studies.layout` ENUM('default','solo') — the v2 presentation skin. |
| `110_survey_studies_option_keys` | `survey_studies.option_keys`. Dormant: the letter-key feature was removed end-to-end; the column ships unread so branch-tracking hosts stay consistent. |
| `111_unit_session_layout` | `survey_unit_sessions.layout`: paradata stamping which layout each response was collected under, so a later flip of the study's layout doesn't rewrite history. |
| `112_survey_items_deleted` | `survey_items.deleted`: removing/renaming an item mid-study becomes a soft delete, so its `survey_items_display` answers survive a sheet edit. |
| `113_formr_rate_limits` | New `formr_rate_limits` table: DB-backed fixed-window buckets, replacing a limiter that lived in `$_SESSION` and reset on cookie rotation. |
| `114_survey_studies_language` | `survey_studies.language` (BCP 47, default `en`): drives `<html lang>` / `dir="rtl"` and the v2 client's UI strings. |
| `115_survey_studies_button_labels` | `survey_studies.next_button_label` / `submit_button_label` (NULL = the built-in wording): the study's own Next / Submit button text in v2. |

**All sixteen are drift-tolerant** — every DDL statement carries an
`IF NOT EXISTS` / `IF EXISTS` guard, so a host that was hand-patched
ahead of the release re-runs them cleanly instead of dying mid-file on a
duplicate column or index.

## 2. Configuration keys

Six new `form_v2_*` keys in `config-dist/settings.php`, plus five
optional `bot_check_*` keys (shipped commented out, since every one of
them has a working built-in default). `setup.php` loads the dist file
first and your `config/settings.php` on top, so **you need not add
anything** — the defaults below apply automatically; add a key to your
own `config/settings.php` only to change it. (On the docker deployment
that file is `formr_app/config/settings.php`, never overwritten by an
update; restart the app and daemons after editing — step 4.)

| Key | Default | Change it when |
| --- | --- | --- |
| `form_v2_enabled` | `false` | You want admins to be able to author v2 forms. It gates the two ways to start a study on v2: the "Add Form (form_v2, beta)" button in the run editor (`AdminRunController::getUnitAddButtons`) and the **Engine** choice on "Add a new survey" (`AdminSurveyController::addSurveyAction`). Existing Form units and studies already on v2 keep loading and running regardless. |
| `form_v2_rcall_ttl` | `['value' => 300, 'label' => 300, 'choice_label' => 300]` (seconds) | Per-slot TTL of the `survey_r_call_results` cache (a slot not listed falls back to its built-in default, 60 s for an unknown one). Values, labels and choice labels resolve once per page show. Lower them if authors complain that edited R takes too long to take effect; raise to cut OpenCPU load. |
| `form_v2_rate_limits` | `['rcall_per_min' => 30, 'write_per_min' => 90, 'beacon_per_min' => 10, 'bot_challenge_per_min' => 20]` | Per fixed 60-second window. `rcall` covers `/form-render-page` (it triggers OpenCPU work); `write` covers `/form-page-submit`, `/form-save`, `/form-sync`; `beacon` covers the unauthenticated `/pwa-beacon`; `bot_challenge` covers `/form-bot-challenge` (one mint per `bot_check` render). Buckets are keyed by run session (beacon by IP). Raise these for fast-typing diary studies with many reactive showifs. The limiter **fails open** if `formr_rate_limits` is missing, so a half-applied patch 113 cannot lock participants out. |
| `form_v2_offline_blob_max_mb` | `10` | Per-file cap for offline queueing. A page with a larger file upload is not queued; the participant is told to retry online. Raise for studies that routinely collect long audio/video. |
| `form_v2_dual_write_results` | `true` | Controls only the *wide* per-study `survey_NN` write. The long-format write (`survey_items_display`) always happens. Leave on until every consumer you care about (your own scripts, the per-run backup TSV) has moved off the wide tables. |

**`form_v2_long_reads`** — default `false`. A form_v2 study always reads
its answers from the long format — `survey_items_display` +
`survey_unit_sessions` — for the OpenCPU R overlay
(`UnitSession::getRunData`), admin result counts and average time, and
`getResults` (exports, the results page, the per-run backup TSV). A v1
study reads its own wide results table exactly as before this release,
unless this is `true`, which opts v1 studies into the long read as well.
The long read re-types each column from the item definition (integer for
`number` items with an integer step and for choice items with integer
choices, float for fractional steps, string otherwise), so R sees the same
types it did from the wide table. Keep `form_v2_dual_write_results` on:
v1 studies read the wide table, and it is the fallback for v2's.

### `bot_check_*` — the proof-of-work human check

Only relevant if a study uses the `bot_check` item. All five are read
via `Config::get()` in `application/Services/BotCheckChallenge.php` and
ship **commented out** in `config-dist/settings.php`; the defaults in
the table are what the service uses when the key is absent.

| Key | Default | Change it when |
| --- | --- | --- |
| `bot_check_secret` | derived from the at-rest crypto key | You want the challenge-signing secret independent of `formr-crypto.key`. Unset or empty means "derive it", which every install can do. If neither the key nor this setting is readable the item fails **closed** — mint 503s, `verify()` refuses every token, and participants see "Human verification is temporarily unavailable". |
| `bot_check_prefix_bytes` | `1` (~256 expected hashes) | You want a harder default gate. Leading bytes of the derived key the client must match; valid `1`–`3`, out-of-range values clamp. A sheet can override per item with `bot_check N` in the type column — difficulty is always a server decision, never taken from the request. |
| `bot_check_argon_memory` | `4096` (KiB = 4 MiB) | You want more memory-hardness. This is the GPU/ASIC-resistant knob and it costs the attacker, not you — the server computes exactly one verification hash (~7 ms) per solve. Values below `256` are ignored. |
| `bot_check_argon_time` | `1` | You want more Argon2id passes. Values below `1` are ignored. |
| `bot_check_ttl` | `86400` (24 h) | A minted challenge should expire sooner. Tokens are session-bound and burned after a successful verify, so a long window buys an attacker nothing; the generous default exists so a diary page left open overnight still verifies. Values below `60` are ignored. |

The rate limit on the minting endpoint is **not** a `bot_check_*` key —
it is `form_v2_rate_limits['bot_challenge_per_min']` (default 20,
enforced in `RunController`).

## 3. Backfill `study_iteration` — do not skip this

Patch 108 moves the `iteration` variable off the wide per-study table
onto `survey_unit_sessions.study_iteration`, adding the columns empty.
**Historical values are carried across by a script, and until it has run
`iteration` means something different than it used to.** The docker
deployment's `update.sh` and `update_formr.sh` run it after every update
(it is idempotent: it only fills empty values); run it by hand on any other
install:

```bash
docker compose exec formr_app php bin/backfill_study_iteration.php --dry-run  # preview
docker compose exec formr_app php bin/backfill_study_iteration.php            # apply
docker compose exec formr_app php bin/backfill_study_iteration.php --study=42 # one study
```

**Idempotent, resumable, and safe to run late.** One transaction per
study. It **only ever fills rows where `study_iteration IS NULL`** and
never overwrites an allocated value, so a partial previous run is
continued rather than redone — and a study that already saw live traffic
between the deploy and the run still gets its historical rows filled.
(This is why `last_iteration > 0` is *not* a skip condition: an earlier
version of the script bailed on that and silently left late-deployed
instances unbackfilled.) A study counts as "nothing to do" only when
both the fill found no NULL rows and `last_iteration` is already caught
up; `last_iteration` is raised with `GREATEST(...)`, so the counter only
ever moves up and can never rewind below numbers already handed out.
Per-study failures print to stderr and the loop moves on; studies with
no results table, or with `valid <> 1`, are skipped. Cost is one
`UPDATE … JOIN` plus one `MAX()` per study — seconds to a few minutes on
a typical instance, but it writes to `survey_unit_sessions`, so prefer a
quiet window. The only flags are `--dry-run` and `--study=N`; there is
no `--force`.

**Run it after the migrations and before participant traffic returns** —
new sessions allocate from `survey_studies.last_iteration`, which is `0`
until the backfill runs. It doesn't care whether the daemons are up, so
the slot is: migrations → backfill → restart app + daemons (step 4).
With `form_v2_dual_write_results = true` (the default) there is a
backstop: the wide table's `UNIQUE` `iteration` rejects the colliding
insert, and `UnitSession::createSurveyStudyRecord` raises the counter to
`GREATEST(last_iteration, MAX(wide.iteration))` and retries
(`UnitSession::createSurveyStudyRecord`). With dual-write **off** there is none, and
new sessions get numbered 1, 2, 3… on top of your historical values.

### What `iteration` means before the backfill

`UnitSession::getSurveyDataLong` — the R overlay, i.e. what
`survey$iteration` sees in your run's R code — selects
`COALESCE(us.study_iteration, ROW_NUMBER() OVER (ORDER BY us.id ASC))`
over a row set scoped to the *current participant's* run session. So a
NULL `study_iteration` falls back to a 1..N sequence over that
participant's own rows for that survey — **not** the study-wide "Nth
response" counter the wide `iteration` column held. The export path
(`SurveyStudy::buildPivotSql`) uses the same `COALESCE` over the whole
study, so there the fallback is 1..N over all sessions. Until the
backfill runs: in **single-fill studies** every participant's
`iteration` reads `1` in R, so `iteration %% k` block randomisation and
"Nth participant" thresholds answer the same for everybody; in
**longitudinal / diary studies** old sessions get row numbers while new
ones get real allocated values, so one `iteration` vector mixes two
numbering schemes and comparing iterations across waves or across
participants is meaningless. Exports carry the same mixture — re-export
after backfilling if you pulled data in between.

## 4. Restart the app and the daemons

The daemons load PHP classes once at startup, so new code and config only
reach them on restart: `docker compose restart formr_app
formr_mail_daemon formr_run_daemon`. (`./update.sh` in the deployment
repo already does this; do it by hand only if you edited `settings.php`
afterwards.)

## 5. Migrating a study to the v2 engine (opt-in, per study)

`rendering_mode` defaults to `v1`, so **no existing study changes
behaviour** after the upgrade. `form_v2_enabled` doesn't switch any study
over either — it only offers the ways in: the "Add Form" button in the run
editor and the Engine choice on "Add a new survey".

### The one authoring rule that changes

**`showif` is JavaScript; `value` is R.** A v2 `showif` is evaluated in
the participant's browser and re-evaluated on every input change. In v1
the same column held R evaluated on the server, so **every non-empty
showif in the study has to be rewritten as JavaScript** — this is the
work an upgrade actually costs you. Most transpile unchanged (`q1 == 1`,
`age > 40`); the scanner below lists the ones that don't.

Server-side R lives in the `value` column, and the supported way to gate
visibility on an R result is a **hidden item whose `value` is the R**,
referenced by name from the (JS) showif:

```
name       type    showif           value
my_score   hidden                   complex_score(current(q1))
followup   text    my_score > 0.5
```

The hidden item resolves on the server, its scalar is written into the
input, and the showif reacts to it like to any other answer.

### Run the scanner

Two equivalent entry points — the CLI

```bash
docker compose exec formr_app php bin/form_v2_compat_scan.php <study_id|study_name>
```

or, in the admin UI, the survey's settings page → **Run v2 compatibility
scan**; the same page's "Rendering engine" box shows the flagged count
next to the **Upgrade to form_v2** button. The scan is read-only (it
never rewrites `survey_items`) and the CLI exits 0 when nothing is
flagged, 2 otherwise, so it works as a CI gate.

`FormV2CompatScanner::scan()` puts every item in exactly one bucket per
column — the same names the admin report and `form_v2.md` use:

| Column | Bucket | Flagged? | What to do |
| --- | --- | --- | --- |
| `showif` | **empty** | no | Nothing — no expression. |
| `showif` | **JS-OK** (`js_ok`) | no | Nothing — the R→JS transpile came out clean. |
| `showif` | **needs JS rewrite** (`needs_js_rewrite`, fix `rewrite_in_js`) | yes | The transpiled expression still contains R-only tokens — an R-only function call (`ifelse`, `c(`, `paste`, `grepl`, `unlist`, …), `tail`/`head` with a non-1 argument, an untranspiled `is.na()`, `%in%` / `%%`, an assignment arrow, an `NA` constant, or R `$`-member access. Rewrite the expression in JS (the helpers `isNA`, `answered`, `contains`, `containsWord`, `startsWith`, `endsWith`, `last` are in scope), **or** move the R into a hidden item's `value` and test that item's name. |
| `showif` | **not on the form** (`not_on_form`, fix `bridge_hidden_item`) | yes | The showif reads a name that is not an item of this form — a run variable such as `ran_group`, or another survey's item. The browser knows only the form's own items, so the showif can never become true. Compute the value in a hidden item's `value` and reference that item. |
| `type` | **not supported in v2** (`unsupported`, fix `use_hidden`) | yes, **blocks the switch** | A `calculate` item, or another item that takes no input but carries R in `value` (`Item::formV2Unsupported`). Replace it with a `hidden` item if the form needs the value, or compute it in the R of the unit that uses it. |
| `value` | **empty** / **literal** (numeric, kept as-is) / **R** (everything else, evaluated server-side) | no | Nothing. `value` is never flagged — every non-empty, non-numeric entry is allowlisted and evaluated as R, `sticky` included. |

**Flagged showifs stop the switch.** An item with a showif renders
hidden and only the browser reveals it, so at runtime a showif the client
can't evaluate keeps its item hidden for every participant. The upgrade
button therefore refuses while any showif is flagged; the scan is a
heuristic, so an author who is sure a finding is wrong can confirm it with
**Upgrade anyway**. Unsupported items block the switch without that
override. See [form_v2.md](form_v2.md) § "Authoring
`showif` and `value`" for the full authoring rules. Upgrading
(`AdminSurveyController::switchRenderingModeAction`) sets
`rendering_mode='v2'` and re-points every run unit that referenced the
study as a plain Survey at a freshly created Form unit — and moves that
placement's unit sessions across with it
(`convertSurveyPlacementsToForm`), so **participants who are mid-form
continue on v2 from where they are** rather than finishing on v1. Both
engines read and write the same `survey_items_display` rows, so nothing
is lost in the handover.

## 6. Rollback

**Reversible:** the config keys, and the engine choice. Set
`form_v2_enabled` back to `false`; leave `form_v2_long_reads` at `false`
(the default) and v1 studies read the legacy wide path; switch an upgraded study back
with **Switch back to v1** on its settings page (that sets
`rendering_mode='v1'` only — Form units fall through to the classic
pipeline, and you can switch forward again at any time).

**Not reversible by flipping a flag:** migrations 100–115, which Atlas
has applied and which stay applied. The deployment repo's
`./db_atlas_down.sh` can compute and run a reversal, but treat it as a
deliberate, review-the-plan-first, back-up-first operation — and note
that dropping patch 112's `survey_items.deleted` would make previously
soft-deleted items visible again.

## 7. Post-upgrade

Three things that are easy to forget once the stack is back up.

**Run the `study_iteration` backfill promptly.** Patch 108 adds the
columns empty; until `bin/backfill_study_iteration.php` has run, historic
sessions have `study_iteration IS NULL` and `iteration` in your R code
means a per-participant row number rather than the study-wide counter
(§3). Prompt is better than late because researchers may pull data in
between — but it is **not** a one-shot window you can miss: the script
fills only sessions that still lack `study_iteration`, never touches an
already-allocated value, and raises `survey_studies.last_iteration` with
`GREATEST(...)`, so running it after participants have already been
allocated live numbers is safe and does the right thing. Re-export any
data you pulled before the backfill.

```bash
docker compose exec formr_app php bin/backfill_study_iteration.php --dry-run
docker compose exec formr_app php bin/backfill_study_iteration.php
```

**Scan every study you intend to move, then switch it.** The upgrade
itself changes no study's behaviour (§5), so this step is on your
schedule, not the deploy's. For each candidate study run

```bash
docker compose exec formr_app php bin/form_v2_compat_scan.php <study_id|study_name>
```

(or the **Run v2 compatibility scan** button on the survey's settings
page). It is read-only and exits 2 when anything is flagged, so it also
works as a CI gate. Resolve the flagged showifs the way §5 describes —
rewrite them in JavaScript, or put the R in a `hidden` item whose `value`
holds it and reference that name from a plain JS showif. `calculate` items
(and other no-input items with an R `value`) are not supported in v2 and
block the switch: replace each with a `hidden` item if the form needs the
value, or compute it in the unit that uses it (feedback, a Skip unit's R).
The switch is also refused while any run uses the study as a classic
Survey (it would change the engine under that run's participants): copy the
survey and put the copy in a Form unit instead.

**Importing historical results: write the display rows.**
`bin/import-results.php` generates the SQL that loads a backup file into
a study. The wide `survey_NN` insert alone is no longer enough: with
a v2 study (or `form_v2_long_reads` on), admin result counts, average
time, `getResults`/the per-run backup TSV and the OpenCPU R overlay all
read the **long** format — `survey_items_display` — so imported data
that has no display rows is invisible to them even though the wide table
is populated. Pass `--include-itemsdisplay` so the generated SQL also
inserts into `survey_items_display`:

```bash
docker compose exec formr_app php bin/import-results.php \
  --survey-id=<sid> --run-id=<rid> --backup-file=<path> --include-itemsdisplay
```

Both inserts are `ON DUPLICATE KEY UPDATE`, so re-running the generated
SQL is safe. If you imported without the flag before upgrading, re-run
the import with it (or the imported rows will keep showing as missing in
the admin result views).
