# Upgrading a formr instance to the form_v2 release

Operator runbook; follow the steps in order. Nothing here changes how
existing v1 surveys render — form_v2 is opt-in per study (step 5) — but
the schema and the `iteration` variable change for everyone, so read
steps 1 and 3 even if you never enable v2. For what form_v2 *is*, see
[form_v2.md](form_v2.md).

## 1. Schema migrations (078–092)

Fifteen new patches in `sql/patches/`. On the docker deployment they are
mirrored into `mysql/atlas/migrations/` and applied by `./update.sh`;
nothing extra to run.

| Patch | What it does |
| --- | --- |
| `078_survey_studies_rendering_mode` | `survey_studies.rendering_mode` ENUM('v1','v2'), **default `v1`** — the per-study engine switch. |
| `079_survey_units_form_study_id` | `survey_units.form_study_id` (+ index): lets a Form unit reference a SurveyStudy without sharing its primary key the way v1 Survey units do. |
| `080_survey_r_calls` | New `survey_r_calls` table: the per-study allowlist of `r(...)` expressions the server will evaluate. Raw R never reaches the browser. |
| `081_survey_form_submissions` | New `survey_form_submissions` table: UUID ledger so an offline-queued page submission that is retried is applied once. |
| `082_form_v2_survey_study_flags` | `survey_studies.offline_mode` (default 1) and `allow_previous` (default 0). v1 ignores both. |
| `083_survey_r_call_results` | New `survey_r_call_results` table: memoizes evaluated `r(...)` results. |
| `084_survey_items_showif_js` | `survey_items.showif_js`: caches the R→JS transpile of `showif` at import time. Nullable; items without it re-transpile at request time. |
| `085_survey_r_call_results_session_scope` | Re-keys that cache on `(call_id, unit_session_id, args_hash)` and adds the cascade FK, closing a cross-participant cache leak. **`TRUNCATE`s the cache table** — that is a cold cache, not participant data. |
| `086_study_iteration` | `survey_unit_sessions.study_iteration` (+ index) and `survey_studies.last_iteration`: the long-format source of truth for the `iteration` variable. **Needs the backfill in step 3.** |
| `087_survey_studies_layout` | `survey_studies.layout` ENUM('default','solo') — the v2 presentation skin. |
| `088_survey_studies_option_keys` | `survey_studies.option_keys`. Dormant: the letter-key feature was removed end-to-end; the column ships unread so branch-tracking hosts stay consistent. |
| `089_unit_session_layout` | `survey_unit_sessions.layout`: paradata stamping which layout each response was collected under, so a later flip of the study's layout doesn't rewrite history. |
| `090_survey_items_deleted` | `survey_items.deleted`: removing/renaming an item mid-study becomes a soft delete, so its `survey_items_display` answers survive a sheet edit. |
| `091_formr_rate_limits` | New `formr_rate_limits` table: DB-backed fixed-window buckets, replacing a limiter that lived in `$_SESSION` and reset on cookie rotation. |
| `092_survey_studies_language` | `survey_studies.language` (BCP 47, default `en`): drives `<html lang>` / `dir="rtl"` and the v2 client's UI strings. |

**All fifteen are drift-tolerant** — every DDL statement carries an
`IF NOT EXISTS` / `IF EXISTS` guard, so a host that was hand-patched
ahead of the release re-runs them cleanly instead of dying mid-file on a
duplicate column or index.

## 2. Configuration keys

Six new keys in `config-dist/settings.php`. `setup.php` loads the dist
file first and your `config/settings.php` on top, so **you need not add
anything** — the defaults below apply automatically; add a key to your
own `config/settings.php` only to change it. (On the docker deployment
that file is `formr_app/config/settings.php`, never overwritten by an
update; restart the app and daemons after editing — step 4.)

| Key | Default | Change it when |
| --- | --- | --- |
| `form_v2_enabled` | `false` | You want admins to be able to author v2 forms. It gates exactly one thing: the "Add Form (form_v2, beta)" button in the run editor (`AdminRunController::getUnitAddButtons`). Existing Form units keep loading and running regardless. |
| `form_v2_rcall_ttl` | `['showif' => 30, 'value' => 300, 'label' => 300]` (seconds) | Per-slot TTL of the `survey_r_call_results` cache. `showif` is short so admin edits to R flow through quickly; `value`/`label` resolve once per page show. Lower them if authors complain that edited R takes too long to take effect; raise to cut OpenCPU load. |
| `form_v2_rate_limits` | `['rcall_per_min' => 30, 'write_per_min' => 90, 'beacon_per_min' => 10, 'bot_challenge_per_min' => 20]` | Per fixed 60-second window. `rcall` covers `/form-r-call`, `/form-fill`, `/form-render-page` (they trigger OpenCPU work); `write` covers `/form-page-submit`, `/form-save`, `/form-sync`; `beacon` covers the unauthenticated `/pwa-beacon`; `bot_challenge` covers `/form-bot-challenge` (one mint per `bot_check` render). Buckets are keyed by run session (beacon by IP). Raise these for fast-typing diary studies with many reactive showifs. The limiter **fails open** if `formr_rate_limits` is missing, so a half-applied patch 091 cannot lock participants out. |
| `form_v2_offline_blob_max_mb` | `10` | Per-file cap for offline queueing. A page with a larger file upload is not queued; the participant is told to retry online. Raise for studies that routinely collect long audio/video. |
| `form_v2_dual_write_results` | `true` | Controls only the *wide* per-study `survey_NN` write. The long-format write (`survey_items_display`) always happens. Leave on until every consumer you care about (your own scripts, the per-run backup TSV) has moved off the wide tables. |

**`form_v2_long_reads`** — default `true`. A temporary reader-migration
escape hatch, to be removed after one release. When true, the remaining
wide-table readers (the OpenCPU R overlay in `UnitSession::getRunData`,
admin result counts and average time, `getResults` for v1 studies and
with it the per-run backup TSV) read from the long format —
`survey_items_display` + `survey_unit_sessions` — for **all** studies,
not just v2 ones. The long read re-types each column from the item
definition (integer for `number` items with an integer step and for
choice items with integer choices, float for fractional steps, string
otherwise), so R sees the same types it did from the wide table.

Set it `false` to fall back to the legacy wide reads if you find a
discrepancy in the field. Two consequences of doing that: the R overlay
for **v2 studies too** goes back to the wide table (only their admin
counts and `getResults` stay long), so `form_v2_dual_write_results` must
stay `true` for as long as `form_v2_long_reads` is `false`; and
soft-deleted items (patch 090) are excluded from the overlay explicitly,
since the wide table has no notion of them. Once the long read has
baked, `form_v2_dual_write_results` can be turned off.

## 3. Backfill `study_iteration` — do not skip this

Patch 086 moves the `iteration` variable off the wide per-study table
onto `survey_unit_sessions.study_iteration`, adding the columns empty.
**Historical values are carried across by a script, and until you run it
`iteration` means something different than it used to.**

```bash
docker compose exec formr_app php bin/backfill_study_iteration.php --dry-run  # preview
docker compose exec formr_app php bin/backfill_study_iteration.php            # apply
docker compose exec formr_app php bin/backfill_study_iteration.php --study=42 # one study
```

**Idempotent and resumable.** One transaction per study; a study is
skipped when `survey_studies.last_iteration > 0` (already backfilled, or
live allocation has started) and the `UPDATE` only touches rows where
`study_iteration IS NULL`, so a partial previous run is continued rather
than redone. Per-study failures print to stderr and the loop moves on;
studies with no results table or with `valid <> 1` are skipped. Cost is
one `UPDATE … JOIN` plus one `MAX()` per study — seconds to a few minutes
on a typical instance, but it writes to `survey_unit_sessions`, so prefer
a quiet window. **`--force` is a foot-gun:** it re-processes already-done
studies and *sets* `last_iteration` to the wide `MAX(iteration)`, which
can rewind the counter below values already handed out.

**Run it after the migrations and before participant traffic returns** —
new sessions allocate from `survey_studies.last_iteration`, which is `0`
until the backfill runs. It doesn't care whether the daemons are up, so
the slot is: migrations → backfill → restart app + daemons (step 4).
With `form_v2_dual_write_results = true` (the default) there is a
backstop: the wide table's `UNIQUE` `iteration` rejects the colliding
insert, and `UnitSession::createSurveyStudyRecord` resyncs the counter to
the wide `MAX` and retries. With dual-write **off** there is none, and
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
over either — it only shows the "Add Form" button in the run editor.

Before switching a study, run the compatibility scanner and resolve what
it flags: it classifies every `showif` and `value` expression against the
v2 rules, and expressions the client-side evaluator can't handle need an
`r(...)` wrap. Two equivalent entry points — the CLI

```bash
docker compose exec formr_app php bin/form_v2_compat_scan.php <study_id|study_name>
```

or, in the admin UI, the survey's settings page → **Run v2 compatibility
scan**; the same page's "Rendering engine" box shows the flagged count
next to the **Upgrade to form_v2** button. See [form_v2.md](form_v2.md)
for what the findings mean and how to fix them. Upgrading
(`AdminSurveyController::switchRenderingModeAction`) sets
`rendering_mode='v2'` and re-points every run unit that referenced the
study as a plain Survey at a freshly created Form unit; participants
mid-form finish on the old engine, new sessions get v2.

## 6. Rollback

**Reversible:** the config keys, and the engine choice. Set
`form_v2_enabled` back to `false`; set `form_v2_long_reads` to `false` to
return the wide readers to the legacy path; switch an upgraded study back
with **Switch back to v1** on its settings page (that sets
`rendering_mode='v1'` only — Form units fall through to the classic
pipeline, and you can switch forward again at any time).

**Not reversible by flipping a flag:** migrations 078–092, which Atlas
has applied and which stay applied. The deployment repo's
`./db_atlas_down.sh` can compute and run a reversal, but treat it as a
deliberate, review-the-plan-first, back-up-first operation — and note
that dropping patch 090's `survey_items.deleted` would make previously
soft-deleted items visible again.
