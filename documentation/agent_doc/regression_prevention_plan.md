# Run-engine regression prevention plan (post-v1.7.1)

Status: **agreed, deferred until the v1.7.1 hotfix ships.**
Companions: `testing.md` (current lane layout + the deferred MariaDB
CI design this plan activates), `unit_type_states.md` (the per-unit
state contract several layers below assert against).

## Why this document exists

The v1.7.0 hardening wave introduced or left behind eight defects that
shipped to production and were fixed in v1.7.1: the two upstream
reports (PR #702 wait-expiry/data-frame ordering, PR #703
`seconds_stayed`) plus five more found by auditing the same seams
(D1 fan-out across eight queries, the inactivity-clock NULL sort, the
email hot-retry loop, the F19 backoff age-keying, the
`google_file_id` import drop). Every one of them postdates a
hardening change; most took weeks-to-months to surface; one
(`ed56a95f` hydration) was independently rediscovered upstream after
we had already fixed it in v1.5.0.

The reflex conclusion — "we lack run-behavior tests" — is wrong in an
instructive way. The repo has 21 `bin/test_*_smoke.php` regression
smokes and ~40 Playwright specs, and most v1.7.0 fixes shipped with a
red-pre-fix smoke. The instinct to test is healthy. Three structural
problems defeated it, and the plan below targets those, not test
volume.

## Why the existing tests were blind

1. **Gating.** The CI gate (`.github/workflows/test.yml`) runs the
   SQLite `:memory:` unit lane with `--exclude-group integration`.
   The 21 smokes and the `@group integration` tests run **zero times
   automatically** — only by hand via `docker exec`. A smoke guards
   exactly one bug, exactly when someone remembers to run it; the
   author of a hardening PR wasn't running other features' smokes.

2. **Shape.** The smokes are scenario-shaped: they assert the one
   outcome they were written for. Several v1.7.1 bugs were invisible
   to any "did the participant end up in the right place" assertion —
   the `expired` leak took the *identical* branch (`move_on`) and only
   the recorded `result` differed; `seconds_stayed` was only wrong on
   re-export; the fan-out only inflated counts. The bugs lived in the
   side-channels (audit columns, exports, pagination counts, timing),
   which scenario tests don't look at.

3. **Silent guards.** The root-cause class was a one-sided contract
   change: an allowlist/guard narrowed a producer while nobody
   re-checked all consumers — and the guard **silently dropped**
   instead of throwing. `ed56a95f`'s constructor allowlist discarded
   every non-allowlisted row field, so `getCurrentUnitSession()`
   produced a session with NULL `created`/`expires` on every web
   request for months, with zero errors. `Model::assignProperties`'
   `property_exists` silent-drop is the same trap (bit twice:
   `rendering_mode`, `offline_mode`).

Cross-cutting: the worst bugs were **volume- and optimizer-dependent**
(the missing `ORDER BY` produced a 72-second-stale anchor on a fresh
session, 17 hours at 1805 rows, ~2 weeks at 20k). Pre-built fixtures
rarely reach the row counts where these bite, which is why the plan
includes post-merge detection layers, not only pre-merge gates.

## Bug → layer map

| v1.7.1 fix | Would have been caught by |
|---|---|
| `ed56a95f` hydration (NULL created/expires) | Layer 1 (constructor throws on first dev request); Layer 4 |
| `getRunData` missing ORDER BY | Layer 2 (structural "frames handed to R are ordered" check); Layer 5 (stale-anchor anomaly) |
| `expired`-label leak (Pause/Wait elapse) | Layer 6 (state transcript diff); Layer 5 (label-distribution shift) |
| `seconds_stayed` export | Layer 6 (export snapshot); Layer 5 (same-instant fingerprint) |
| D1 fan-out ×8 queries | Layer 2 (grep lint + count invariant); Layer 4 (multi-position fixture) |
| Inactivity clock NULL sort | Layer 4/6 (mid-page state fixture) |
| Email hot-retry loop | Layer 5 (executions-per-row histogram); hard for any scenario test |
| F19 backoff keyed on age | **None** — wrong intent, tests would have pinned it (see Limits) |
| `google_file_id` import drop | Layer 2 (round-trip property test) |

## The plan, ordered by leverage per cost

### 1. Strict option allowlists — hours

Make the `UnitSession` / `RunSession` constructor `$options`
allowlists **throw on unknown keys** instead of silently dropping
them. Both have a small, audited caller set (that is what the
hardening commit itself enumerated — and mis-enumerated). Had this
been strict from day one, the hydration bug would have fataled on the
first dev web request instead of silently NULLing for months. A guard
that fails loudly needs no tests to catch its own regressions; a
guard that fails silently can't be fully covered by any number of
them. Leave `assignProperties` lenient — it is deliberately fed mixed
row data — but constructors are the cheap, high-value target.

### 2. Property/contract tests + lint — an afternoon, runs in the existing SQLite gate

Class-catching checks, not scenario checks:

- `SurveyStudy::getSettings()` keys ⊆ `createFromData`'s
  `$allowed_settings` (one reflection test; catches the
  `google_file_id` class — export→import must round-trip).
- Every public DB-backed property appears in `toArray()` — makes the
  "three touch points" CLAUDE.md rule executable.
- CI grep lint: fail on any `JOIN survey_run_units … unit_id` not
  paired with a `run_unit_id` arm. Catches all eight fan-out sites at
  once, and the future ninth.
- Structural assertion that every `getRunData` frame query carries an
  `ORDER BY` (string-level is fine; the point is a tripwire).

### 3. Nightly smoke run on the dev instance — an hour

A cron on the dev box: `docker exec formr_app` loops all 21
`bin/test_*_smoke.php` + `composer test:integration`, reports
failures (email/notify). This activates every smoke the team already
owns in the exact environment they were written for, and bounds
detection latency to ~1 day (the v1.7.1 bugs took months). Not a
merge gate — that's Layer 4 — but 90% of the value at 5% of the cost.

### 4. Hermetic MariaDB CI lane — the real project; size it honestly

`testing.md` has the full design (bootstrap env switch,
`integration.yml`, schema seed). Two corrections to its optimism:

- **Seed with the fixture-clean subset only** (the Track A smokes and
  peers that create/tear down their own rows), and grow it as smokes
  prove stable. A flaky gate gets skipped within a month, which is
  worse than no gate.
- **Some smokes can never be hermetic** in a DB-service-container
  job: `test_survey_test_two_tabs_smoke.php` needs real HTTPS against
  the vhost (Secure cookie); those stay in Layer 3 permanently, or
  need a full-compose CI runner (a much bigger lift — decide
  separately).

New fixtures this lane should gain from the v1.7.1 experience: a
multi-position-unit run, a Wait+reminder ESM loop, a Pause with
`relative_to`, a mid-page inactivity state.

### 5. Data-quality anomaly cron — a day

The run engine writes a rich audit trail; almost nothing reads it
adversarially. Yet that is how the v1.7.1 bugs were *actually* found:
the reporter read exported CSVs (every expired row's
`entered + seconds_stayed` resolving to the same wall-clock instant —
a mechanical fingerprint), and the audit A/B'd queries on live rows.
A weekly cron over the dev DB (later prod) flags:

- result-label distribution shifts per unit type/week (the `expired`
  leak);
- executions-per-row histograms (the email hot loop: one row,
  thousands of passes);
- `ended IS NULL AND expired IS NOT NULL` spikes;
- placement-join count consistency (fan-out);
- unit sessions whose `ended`/`expired` precedes their own stored
  `expires` (the classic premature-expiry fingerprint).

This is the only layer that catches bug *shapes nobody predicted*,
which for volume/optimizer-dependent defects is the common case.

### 6. Golden state transcripts — largest; depends on Layer 4

Drive a participant through each canonical fixture run and snapshot
the **entire** unit-session state after every step — `result`,
`state`, `queued`, `expires`, `ended`/`expired`, plus the export
rows — and diff against a committed golden file. The `expired` leak,
`seconds_stayed`, and fan-out counts would each have been a one-line
diff. This is `unit_type_states.md` made executable. Needs the
MariaDB lane (ENUM/JSON/window functions), hence last.

## Process rules (no code)

- **Hardening = caller census.** Any PR narrowing a contract
  (allowlist, validation, query rewrite) must enumerate the
  consumers of the narrowed thing and say how each was checked.
  `ed56a95f`'s commit message named two of three callers; the third
  was the bug.
- **No big-bang hardening releases.** v1.7.0 changed dozens of
  engine behaviors in one release; interactions had no soak time.
  Ship hardening in small releases, each soaked on the dev instance
  (which runs real ESM-style traffic hourly) before the next.
- **Keep the adversarial audits.** See Limits.

## Limits — what tests cannot do

Tests catch divergence from intent, not wrong intent. The F19 backoff
bug existed because its author believed session age was a valid proxy
for failure count — the code comment says so. Any test written
alongside it would have pinned the 6-hour tier as *expected
behavior*. Only a second reader asking "is age the right key?"
catches that class. The v1.7.1 audit (three parallel adversarial
review passes over hydration/allowlists, query determinism, and guard
false-positives) found all five non-upstream fixes; that practice is
a layer of this plan, not a one-off.

## Non-goals

- **SQLite/MariaDB parity shims.** These bugs need the real
  optimizer and real schema; SQLite would return *differently* wrong
  row orders. Parity work is maintenance spent making tests pass that
  still couldn't catch the target class.
- **Playwright for DB-state assertions.** e2e is for "does the flow
  render and submit" (well covered). Asserting `result` columns
  across thousands of rows belongs under the UI, in Layers 4–6.
