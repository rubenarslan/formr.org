# Write-time metrics accounting — design & implementation plan

Status: **proposed** (2026-07-13). Targets a follow-up release (v1.7.0).
Supersedes the timer-driven `survey_run_metrics` rollup shipped in v1.6.0
(`RunMetrics::refresh()` every 30 min) — see
[`slow_query_audit_2026-07.md`](slow_query_audit_2026-07.md) §6.

## Context — why this exists

The v1.6.0 audit replaced several O(history) aggregate scans
(SQ-13/16/17/18/21) with a per-run rollup table. But that rollup is
maintained by a **full recompute on a timer**: it re-scans all of
`survey_run_sessions` + `survey_unit_sessions` every 30 minutes,
whether or not anyone looks. That trades a rare pay-per-view cost for a
constant background cost — strictly worse on any instance whose
dashboards are viewed less than ~48×/day (i.e. all of them), and it
puts a recurring heavy scan on the hottest table right in the path of
the participant/daemon write workload.

The right shape is **write-time accounting**: maintain running counters
in the same transaction as the source mutation, so reads are O(1) and
the expensive full scan happens only as a nightly reconciliation.

### Decision & risk posture (from the maintainer)

- **Invest in write-time counters.** A wrong counter drifts an admin
  dashboard — annoying but tolerable. Slowing the instance is not.
  This asymmetry is the whole justification: we accept a fragile-ish
  maintenance surface (mitigated by nightly reconcile) to guarantee
  reads never scan history.
- Enforcement (`ComputeLimitCron`, issue #608) currently reads live for
  exactly this trust reason. Once counters are reconciled-nightly and
  proven, enforcement *may* move to the rollup too — but that is a
  later, separate decision; this plan keeps enforcement live.

## Core principles

1. **Counters live in rollup tables; reads never scan facts.** Every
   dashboard/list read becomes a single-row (or O(runs)) lookup.
2. **Hook the hot *additive* paths precisely; reconcile the cold
   *destructive* ones.** The frequent, well-defined writes (session
   create, `execution_time += delta`, survey start/complete) get exact
   in-transaction counter updates. Rare destructive paths (bulk delete,
   results wipe) may be hooked *or* left to the nightly reconcile —
   drift is then bounded to <24 h and self-heals. This keeps the risky
   hook surface small.
3. **Nightly reconciliation is the safety net.** A single full recompute
   at a low-traffic hour overwrites every counter from a ground-truth
   scan — correcting drift from missed hooks, manual DB edits, the
   historic backfill, or replication quirks. It is the *same* scan we
   run today, demoted from every-30-min to once-nightly.
4. **Only aggregates that are additive/mergeable are eligible.** SUM,
   COUNT, MAX, and — crucially — the **geometric mean** (a running
   Σlog + count). The old median is *not* incrementally maintainable
   (needs all values or a t-digest); swapping it for a geometric mean
   is what lets the duration statistic join this scheme (see §4).
5. **Counters are in-transaction with the source write.** If the source
   transaction rolls back, so does the counter update — no divergence
   from a half-applied change.

## 1. Rollup schema

Two tables. Grain is **per-run** for session/compute metrics and
**per-study** for survey result/duration metrics — both chosen so that
per-user and instance-wide figures are cheap sums over O(runs)/O(studies)
rows, and so the write hooks have a single row to touch.

### `survey_run_metrics` (extend the v1.6.0 table)

| column | aggregate | source / hook |
| --- | --- | --- |
| `run_id` PK | — | FK → survey_runs (cascade) |
| `n_run_sessions` | COUNT | run-session create ++ / delete −− |
| `last_access` | MAX | reconcile-only (see §7 — not a write hook) |
| `n_exec_sessions` | COUNT | first `execution_time` NULL→value ++ |
| `total_execution_time` | SUM | `+= delta` at each execute |
| `month_execution_time` | SUM (this month) | `+= delta`, reset on month_key change |
| `month_key` CHAR(7) | — | current YYYY-MM stamped on write |
| `max_execution_time` | MAX | `GREATEST(stored, row's new value)` |
| `updated_at` | — | touch on any write |

### `survey_study_metrics` (new)

Per `study_id` (a `SurveyStudy` / results table). Serves `getResultCount`
(SQ-11) and the revived duration statistic (§4).

| column | aggregate | source / hook |
| --- | --- | --- |
| `study_id` PK | — | FK → survey_studies (cascade) |
| `begun` | COUNT | real session starts survey ++, on complete −− |
| `finished` | COUNT | results row `ended` set ++ |
| `testers` | COUNT | testing session start ++ / toggle |
| `real_users` | COUNT | non-testing session start ++ / toggle |
| `sum_log_duration` DOUBLE | Σ ln(seconds) | on complete, if duration ≥ 1s |
| `n_durations` | COUNT | on complete, if duration ≥ 1s |
| `updated_at` | — | touch on any write |

`getResultCount` returns `begun/finished/testers/real_users` directly;
the geometric-mean duration is `exp(sum_log_duration / n_durations)`
(§4). The rarer run-scoped `getResultCount($run_id, …)` stays live —
low-frequency, and not worth a per-(study,run) grain.

## 2. The write-hook surface (the risk, enumerated)

Each row is a mutation site and the exact counter effect. Sites are the
*only* code that may diverge; keeping this list short and reviewed is the
safety discipline. All updates are single-row PK `UPDATE`s in the source
transaction.

**Hot additive paths — hooked precisely:**

1. **`execution_time += delta`** — `UnitSession.php:337`. →
   `survey_run_metrics`: `total_execution_time += delta`;
   `month_execution_time += delta` (reset first if `month_key` stale);
   `max_execution_time = GREATEST(…, new row value)`;
   `n_exec_sessions += 1` **iff** the row's `execution_time` was NULL
   before this update (guard on pre-update state, already in scope).
2. **Run-session create** — `RunSession` create path. →
   `n_run_sessions += 1` (upsert the metrics row if absent).
3. **Survey results row create** (participant enters a survey unit) →
   `survey_study_metrics`: `begun += 1` if the run session is
   non-testing else `testers += 1`; `real_users += 1` if non-testing.
   Hook at the `createSurveyStudyRecord` / `survey_started` write.
4. **Survey completion** (`ended` stamped on the results row) →
   `finished += 1`, `begun −= 1` (if it had been counted begun);
   duration `d = TIMESTAMPDIFF(SECOND, created, ended)`: if `d ≥ 1`,
   `sum_log_duration += LN(d)`, `n_durations += 1`. (Use `TIMESTAMPDIFF`,
   **not** raw `ended − created` — see §4.)

**Cold destructive / rare paths — hook OR reconcile (drift <24 h):**

5. **Testing toggle** (`RunSession::toggleTestingStatus`, SQ-39) — moves
   sessions between `real_users`↔`testers` (and their begun/finished).
   Well-defined and batchable; recommend hooking it.
6. **Bulk session delete** (`deleteSessions`) → `n_run_sessions −= n`;
   study buckets −= affected. Hook if cheap, else reconcile.
7. **Results wipe** (`deleteResults` / truncate) → zero the study row.
   Hook (it already knows the study).
8. **Run / user delete** — FK cascade drops the metrics row entirely;
   no counter math needed.

## 3. Reads after the change

Every flagged read becomes a counter lookup; the live-scan versions are
kept only as the reconcile query (§5) and an optional fallback (§9).

- **SQ-11 `SurveyStudy::getResultCount`** → one row from
  `survey_study_metrics`. Drop the within-request cache added in v1.6.0
  (no longer needed). The run-scoped variant stays live.
- **SQ-16/17/18 `ComputeUsageHelper`** → sums/reads over
  `survey_run_metrics` (already the v1.6.0 shape via `RunMetrics`).
- **SQ-13 run list / SQ-21 active users** → `n_run_sessions`,
  `last_access` from `survey_run_metrics` (already v1.6.0).
- **SQ-06 user-detail count, SQ-14 push-log count, SQ-37 email-log
  count** → optional follow-ups: per-run counts of the same additive
  shape; add columns/tables if/when those views matter at scale.
- **Duration (SQ-10 revived)** → `getGeometricMeanDuration()` on
  `SurveyStudy`, reading `survey_study_metrics` (§4). Restores the
  survey-unit dialog line, now `(GM ≈ X m)` instead of the removed
  `(median ≈ X m)`.

## 4. Geometric-mean duration — the median replacement

The old `getAverageTimeItTakes` (SQ-10, removed in v1.6.0) computed a
**median** of `ended − created` via the `@row:=@row+1` hack — a double
full scan, and un-maintainable incrementally because a median needs the
full value set (or a t-digest sketch). It also used raw datetime
subtraction (`ended − created`), which in MariaDB is a *numeric* subtract
of the `YYYYMMDDHHMMSS` encodings, not a duration — wrong across
minute/hour/day boundaries. The replacement uses
`TIMESTAMPDIFF(SECOND, created, ended)`, so it is both maintainable and
*more correct* than what it replaces.

A **geometric mean** collapses to two running scalars:

```
GM = exp( (Σ ln s_i) / n )        maintained as:
  on completion with duration s (seconds), s ≥ 1:
     sum_log_duration += LN(s)
     n_durations      += 1
  read:  EXP(sum_log_duration / n_durations)     (n_durations > 0)
```

That is the entire reason a duration statistic can join write-time
accounting at all. It is also *statistically the better choice* here:
completion times are right-skewed / roughly log-normal, for which the
geometric mean is the natural central tendency — less outlier-sensitive
than the arithmetic mean and more informative than the median for the
"typical time this takes" the dialog is trying to convey.

**Edge cases:**
- `s < 1 s` (instant/degenerate completions, clock skew, `ended == created`)
  are **excluded** — `LN` needs a positive argument and sub-second survey
  completions are noise. Document that GM is over completions ≥ 1 s.
- `n_durations == 0` → display nothing (as today when there's no data).
- Storage: `DOUBLE` for `sum_log_duration` (log magnitudes are small;
  Σ over millions of sessions stays well within double precision).
- On completion-delete (rare) subtract `LN(s)` and decrement, or let
  reconcile correct it.

## 5. Reconciliation & backfill

`RunMetrics::refresh()` (the current full recompute) is **renamed/kept as
`reconcile()`** and extended to also rebuild `survey_study_metrics`. It:

- runs the same ground-truth aggregation and **overwrites** every counter
  (INSERT … ON DUPLICATE KEY UPDATE over all runs / studies);
- is scheduled **nightly** at a low-traffic hour (e.g. 03:2x, off the
  round marks) instead of every 30 min;
- doubles as the **migration seed** — the new patch creates
  `survey_study_metrics`, then the first `reconcile()` populates both
  tables from history (including `sum_log_duration`/`n_durations` from a
  one-time per-results-table scan of
  `LN(TIMESTAMPDIFF(SECOND, created, ended))` over completions ≥ 1 s).

Reconcile is the invariant enforcer: **after a reconcile, every counter
equals a fresh live scan.** That equality is the core test (§8).

## 6. Migration & rollout (phased)

1. **Schema** — new patch `NNN_write_time_metrics.sql`: add
   `survey_study_metrics`; add any new columns to `survey_run_metrics`
   (it already has the run/compute set). Author under `sql/patches/`,
   sync via Atlas (`db_atlas_apply.sh`).
2. **Reconcile + seed** — extend `RunMetrics::reconcile()`; run once at
   migration to populate.
3. **Reads first, still reconcile-backed** — point `getResultCount` and
   the duration accessor at the tables while maintenance is still the
   (now nightly) reconcile. Safe: reads are correct to within a day.
4. **Add write hooks** — land the §2 hot-path hooks behind the same
   transactions. After each, assert reconcile-equality (§8).
5. **Retire the 30-min cron** — replace with the nightly reconcile in
   `config-dist/formr_crontab` + the ofelia labels
   (`docker-compose-prod.yml` / `-local.yml`).
6. **Revive the duration display** — `SurveyStudy::getGeometricMeanDuration`
   + the dialog line in `templates/admin/run/units/survey.php`.

## 7. Concurrency, contention & `last_access`

Write-time counters convert "occasional big scan" into "a tiny single-row
`UPDATE` on every relevant write." The cost moves to **row-lock
contention on the per-run / per-study counter row**: two participants in
the same run completing at the same instant serialize on that run's
`survey_run_metrics` row. For research surveys (low per-run concurrency)
this is negligible — the update is a sub-millisecond increment holding
the row lock briefly. It is worth naming for a viral study with thousands
of simultaneous participants, where the run's counter row becomes a
serialization point. Mitigations if that ever bites: counter sharding
(N sub-rows summed on read), or moving the hottest column out of the
hot path (below). Not built now — noted.

**`last_access` is deliberately NOT a write hook.** It is bumped on
*every* participant page access — the highest-frequency write in the
app — so hooking it would create the worst contention for the least
valuable column (the active-users list's "last edit" is a coarse admin
overview). Instead: **`last_access` is maintained by the nightly
reconcile only.** A value up to a day stale is fine there. This removes
the single worst write-amplifier from the design.

General rule this encodes: *hook a counter at write time only when the
write frequency is acceptable and the read needs freshness.* Compute
deltas (per execute) and survey start/complete (per session) clear that
bar; per-access `last_access` does not.

## 8. Verification & testing

- **Reconcile-equality invariant (the central test).** A live-MariaDB
  integration test (`bin/test_*_smoke.php` style, per the app CLAUDE.md)
  that: seeds runs/sessions, runs `reconcile()`, then drives real
  mutations through the app (participant submit, complete, testing
  toggle, delete) and asserts each counter moved by the expected delta
  **and** that a fresh `reconcile()` leaves every counter unchanged
  (hooks agree with ground truth).
- **Geometric mean** — unit-check `EXP(Σ ln s / n)` against a known
  duration set, incl. the ≥1 s exclusion and the empty case.
- **Drive the participant flow** (Playwright / curl, as in the v1.6.0
  hot-path verification) and read the study/run counters after each
  step.
- **No enforcement change** — `ComputeLimitCron` still reads live, so its
  behaviour is unchanged; assert that explicitly.

## 9. Risks & rollback

- **A wrong/missing hook drifts a dashboard** for up to a day, then the
  nightly reconcile heals it. Tolerable by the maintainer's stated
  posture; the instance never slows.
- **Rollback / kill switch:** a config flag (e.g.
  `metrics_read_source = counters|live`) lets each read fall back to the
  live scan if a counter is ever suspect — the old query bodies are
  retained as that fallback and as the reconcile query, so nothing is
  deleted outright.
- **Contention regression** (§7) — watch for lock waits on the metrics
  tables after enabling hooks; shard or drop-to-reconcile the offending
  column if observed.
- **Replication** — counter `UPDATE`s are ordinary row events; no special
  handling. Reconcile runs on the primary.

## 10. Open decisions (need sign-off)

1. **Scope of first cut.** Recommend: `survey_study_metrics` +
   `getResultCount` + geometric-mean duration (your "secret list"
   items), and the run/compute hooks; defer SQ-06/14/37 count columns.
   Agree, or do all at once?
2. **`last_access` reconcile-only** (§7) — accept up-to-a-day staleness
   on the active-users "last edit" to avoid the per-access hotspot?
3. **Destructive-path hooks** — hook testing-toggle + deletes precisely,
   or lean on nightly reconcile for those (drift <24 h)?
4. **Duration floor** — exclude completions `< 1 s` from the geometric
   mean; confirm 1 s is the right floor (vs > 0, or a higher cutoff).
5. **Reconcile cadence** — nightly is proposed; a busy hosted instance
   could want it 2–4×/day. Pick a default.
6. **Enforcement** — keep `ComputeLimitCron` live for now (recommended);
   revisit moving it onto reconciled counters after the hooks are proven.
