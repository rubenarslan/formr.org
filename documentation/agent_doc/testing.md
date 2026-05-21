# Testing — current state and deferred work

This document is a companion to the `## Commands` section in
`CLAUDE.md`. It explains why the PHPUnit suite has two lanes, which
tests run where, what's deferred, and what would be needed to close
each gap.

## Two lanes

| Lane | Script | What runs | Database |
|---|---|---|---|
| Unit | `composer test` | everything except `@group integration` | SQLite `:memory:` (via `tests/bootstrap.php`) |
| Integration | `composer test:integration` | only `@group integration` | currently still SQLite (bootstrap unconditionally forces it); designed for a live MariaDB |

The unit lane is the CI gate and must stay green. The integration
lane is exploratory until the bootstrap supports a real-DB switch
(see below).

## What's tagged `@group integration`

Four classes carry the tag today:

- `tests/DBTest.php` — exercises the `DB` helper. Several methods
  (`SHOW TABLES`, `SHOW COLUMNS`) are MariaDB-only, so the SQLite
  bootstrap trips on dialect differences.
- `tests/PushNotificationExpireSubscriptionTest.php` — the
  `markSubscriptionExpired` cleanup. Needs anchor rows in
  `survey_studies` + `survey_unit_sessions` (FK targets); seeds its
  own `survey_items` + `survey_items_display` and tears them down.
- `tests/FirstTest.php` — fetches WEBROOT pages over HTTP. Needs a
  running stack.
- `tests/OpenCPUTest.php` — hits a live OpenCPU instance.

The unit lane skips all four; running them under SQLite is not
expected to work for most cases.

## Deferred fixes inside `DBTest`

After v0.26.1 the following remain broken when DBTest is run against
SQLite. Each could be fixed independently; none block the unit lane.

### `testGetTableDefinition`

- **What it asserts.** `DB::getTableDefinition('users')` returns the
  column definitions for the test `users` table; first two rows are
  `Field => id` and `Field => username`.
- **Why it fails on SQLite.** `getTableDefinition` (`application/DB.php:583`)
  issues `SHOW COLUMNS FROM \`$table\``. MySQL/MariaDB only. SQLite's
  equivalent is `PRAGMA table_info(<name>)`, which returns a
  different column shape (`cid, name, type, notnull, dflt_value, pk`).
- **Fix shapes.**
  - **(a)** Teach `DB::getTableDefinition` to branch on
    `$this->driver === 'sqlite'` and map `PRAGMA table_info` rows to
    the MariaDB shape (`Field`, `Type`, `Null`, `Key`, `Default`,
    `Extra`). ~15 lines.
  - **(b)** Leave the method MariaDB-only and rely on the live-DB
    lane below.
- **Recommendation.** (b). `getTableDefinition` is only used by
  admin/import tooling that already requires a live DB; carrying a
  SQLite shim just to pass one unit test isn't worth the maintenance
  cost.

### `testTransactions`

- **What it asserts.** After `beginTransaction` + `insert` +
  `rollBack`, the inserted row is gone. The test calls
  `findRow('users', ['username' => 'transact_user'])` and asserts
  `assertNull($user)`.
- **Why it fails on SQLite.** `findRow` → `select()->fetch()` →
  `PDOStatement::fetch()` which returns `false` (not `null`) for an
  empty result. Asserts `null is null` but gets `false`.
- **Fix shapes.**
  - **(a)** Change the test to `assertFalse($user)`.
  - **(b)** Normalize `DB::findRow` (and `findValue`) to return
    `null` instead of `false`. Cleaner API but a behaviour change
    visible to many callers; needs an audit of every caller that
    does `if ($row === false)` vs `if (!$row)`.
- **Recommendation.** (a) first, file (b) as a separate API-cleanup
  task.

### `PushNotificationExpireSubscriptionTest` × 4

- **What they assert.** That `markSubscriptionExpired($endpoint)`
  flips the matching `survey_items_display.answer` rows to the
  sentinel and leaves unrelated rows alone.
- **Why they fail on SQLite.** Bootstrap seeds only `survey_studies`
  and `survey_users`. The test's `setUp` queries
  `survey_unit_sessions` (which the bootstrap doesn't create), and
  `DBTest::setUp` nulls the `DB` singleton, so by the time
  PushNotification's `setUp` runs after DBTest it gets a fresh empty
  `:memory:` connection with zero tables.
- **Fix shapes.**
  - **(a)** Wrap the two pre-skip SELECTs in a try/catch and route
    PDOException through `markTestSkipped`. Cheap, makes the skip
    robust against bootstrap drift, doesn't actually make the test
    *run*.
  - **(b)** Extend `tests/bootstrap.php` to also create
    `survey_unit_sessions`, `survey_items`, `survey_items_display`,
    `survey_results_data` etc. Risk: each new schema seed is one
    more place that drifts from the real schema.
  - **(c)** Add the live-MariaDB CI lane (below). The tests already
    work there.
- **Recommendation.** (c). Anything else is busywork that delays the
  real fix.

## The live-MariaDB CI lane (not yet implemented)

`tests/bootstrap.php` currently hard-codes the SQLite config:

```php
Config::initialize(array(
    'database' => (object) array(
        'driver' => 'sqlite',
        'database' => ':memory:',
    ),
));
```

To support a real database lane the bootstrap needs to branch on an
env var, e.g.:

```php
if (getenv('FORMR_TEST_DB') === 'mariadb') {
    Config::initialize(array(
        'database' => (object) array(
            'driver'   => 'mysql',
            'host'     => getenv('FORMR_TEST_DB_HOST') ?: '127.0.0.1',
            'database' => getenv('FORMR_TEST_DB_NAME') ?: 'formr_test',
            'login'    => getenv('FORMR_TEST_DB_USER') ?: 'root',
            'password' => getenv('FORMR_TEST_DB_PASS') ?: '',
        ),
    ));
} else {
    // existing SQLite :memory: path
}
```

### CI side

A `.github/workflows/integration.yml` would declare MariaDB as a
service container, seed the schema, then run `composer test:integration`:

```yaml
services:
  mariadb:
    image: mariadb:11
    env:
      MARIADB_ROOT_PASSWORD: test
      MARIADB_DATABASE: formr_test
    ports: ["3306:3306"]
    options: >-
      --health-cmd="healthcheck.sh --connect"
      --health-interval=5s --health-timeout=2s --health-retries=10

steps:
  - uses: actions/checkout@v4
  - run: composer install --no-progress
  - name: Seed schema
    run: |
      mysql -h127.0.0.1 -uroot -ptest formr_test < sql/schema.sql
      for p in sql/patches/*.sql; do
        mysql -h127.0.0.1 -uroot -ptest formr_test < "$p" || true
      done
  - env:
      FORMR_TEST_DB: mariadb
      FORMR_TEST_DB_HOST: 127.0.0.1
      FORMR_TEST_DB_PASS: test
    run: composer test:integration
```

Cost: ~30s cold per CI run for the MariaDB container + schema seed.
Two lanes to keep green.

### Schema-drift risk

`sql/schema.sql` is the fresh-install baseline and is known to drift
from the patch series (043, 045, 046, 047, 048 may add columns not
reflected in `schema.sql`). The CI seed loop above applies patches on
top to converge; alternative is the Atlas runner used by the
deployment repo (`/home/admin/formr-docker/db_atlas_apply.sh`), which
is what production uses but adds a Docker-in-Docker dependency.

### Fixture model

The PushNotification tests already follow the right pattern: insert
your own anchor rows in `setUp`, delete them in `tearDown`. Other
integration tests should do the same rather than relying on the dev
DB having data. The Track A smokes under `bin/test_track_a_*_smoke.php`
follow a similar discipline and are a useful reference.

## Punch list

In order of impact:

1. **Build the live-MariaDB CI lane.** Env-var switch in
   `bootstrap.php`, GitHub Actions workflow, schema seed step.
   Unblocks `PushNotificationExpireSubscriptionTest` × 4 and
   `testGetTableDefinition`.
2. **Fix `testTransactions` assertion.** One-line change to use
   `assertFalse`. Doesn't require the live lane.
3. **Audit `findRow` / `findValue` callers** and consider normalizing
   their not-found return to `null`. API-cleanup ticket, separate PR.
4. **Decide on `testGetTableDefinition`:** SQLite shim in `DB.php` or
   keep it MariaDB-only. Recommendation above is MariaDB-only.
5. **PHPUnit 11 deprecations** (11 warnings in the unit lane). Pre-
   existing and not addressed by this branch; mostly tests using
   `@dataProvider` and `getMockBuilder` patterns that PHPUnit 12 will
   remove.
