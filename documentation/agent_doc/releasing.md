# Releasing — the pre-release test gate

Companion to `testing.md` (the two lanes and the deferred live-MariaDB CI
design) and `regression_prevention_plan.md` (why hardening changes keep
shipping regressions). This doc is the concrete, agent-run gate to apply
**every time a release is cut**, until the automated live-DB lane
(`testing.md` Layer 4) exists.

## Why a manual gate

The CI unit lane (`composer test`) runs on SQLite in memory, so it cannot
exercise the seams where the last several regressions lived: the run-session
lock (`GET_LOCK`), the admin "send to position" push (`RunSession::runTo`),
reminders, OpenCPU skip/branch evaluation, and the Track A ENUM / JSON /
window-function paths. Those are covered by the `bin/*_smoke.php` scripts,
which only mean anything against a live MariaDB (plus OpenCPU and mail for a
few of them).

Releases also go out as a direct push to a `release/X.Y.Z` branch on the
embargo repo — no PR — so before v1.10.5 they triggered no CI at all.
v1.10.2–v1.10.4 shipped ungated; the **v1.10.3 `valid`/`body_parsed`
regression** (an `assignProperties()` override stripped the flags
`RunUnit::find()` sets, so admin push and unit-delete failed on
perfectly-placed units, and parsed bodies blanked) rode along and only
surfaced when a user hit it. This is the exact failure class
`regression_prevention_plan.md` is about.

The agent cuts releases now, so the agent is the reliable place to run the
battery. The local dev stack has MariaDB, OpenCPU and the mail daemon up, so
here — unlike CI — every smoke actually runs.

## The gate

With the release branch checked out (the dev stack bind-mounts
`formr_source`, so the running app reflects it), run both and cut only if
green:

1. **Unit lane** — must print `OK`:
   ```bash
   docker exec formr_app sh -c 'cd /var/www/formr && \
     php vendor/bin/phpunit --configuration tests/phpunit.xml --exclude-group integration'
   ```
2. **Live-MariaDB smokes** — each must exit `0` (the pass banner differs per
   script, so key on the exit status, not on a phrase); a `FAIL` line or
   non-zero exit blocks the release:
   ```bash
   for f in bin/*_smoke.php; do
     echo "== $f"; docker exec formr_app php "/var/www/formr/$f"; echo "exit=$?"
   done
   ```
   The smokes build and tear down their own throwaway run / units /
   participant, so they leave no residue. Investigate any failure before
   cutting — do not release around a red smoke. The only acceptable skip is a
   smoke whose service is genuinely down on the stack, stated explicitly.

The guards for the 1.10.3 class specifically are
`tests/RunUnitValidHydrationTest.php` (unit; red on the broken strip list,
green on the fix) and `bin/test_run_unit_delete_push_smoke.php` (smoke;
reproduces the "Could not create unit session" symptom end to end).

## Machine backstop (does not replace the gate)

`.github/workflows/test.yml` triggers the unit lane on `release/**` and
`hotfix/**` pushes (and on PRs) since v1.10.5, so a release-branch push runs
`composer test`. It is SQLite-only, so it still cannot see the smoke class —
the manual battery above stays mandatory until Layer 4 lands.

## Cut steps

Once green: bump `VERSION` + `package.json`, add the dated `CHANGELOG.md`
section (grouped Added / Fixes / Changes / Schema), commit, annotate-tag
`vX.Y.Z`, push branch + tag to `embargo`, publish the GitHub release, then
build images from the deployment repo (`formr-docker`, see its
`RELEASING.md`). No AI attribution trailers on commits or PRs.
