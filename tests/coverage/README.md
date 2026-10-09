# Coverage pass

A map of what the test suites execute, used to find code no test reaches.
It is not a target: a covered line only means something ran it, not that
a test checked what it did. Read the output for **zero** rows (methods,
functions, uncovered line runs) in the code you care about, and give each
a test or a deliberate "not worth it".

```bash
tests/coverage/run.sh            # unit + smokes + e2e, about an hour
tests/coverage/run.sh --no-e2e   # unit + smokes, a minute
```

Output lands in `.coverage/` (gitignored):

| file | what |
| --- | --- |
| `php-summary.txt`, `php-html/` | PHP line/method coverage, all lanes merged (application/, templates/, setup.php, webroot/index.php) |
| `php-methods.tsv` | one row per method: executable and executed lines, and which lanes ran it (`unit`, `smoke`, `e2e`) |
| `js-summary.txt` | per source file under `webroot/assets/`: lines covered by e2e, functions never called |
| `js-uncovered.tsv` | per source file: runs of uncovered lines |
| `node-summary.txt` | `node --test` coverage of the pure modules (`state.mjs`) |

How each lane is measured:

- **unit** — PHPUnit `--coverage-php`, Xdebug in coverage mode for that
  `docker exec` only.
- **smoke** — each `bin/*_smoke.php` with `prepend.php` as
  `auto_prepend_file`: it records the lines the process ran and unions
  them into `.coverage/php/smoke.json` under a lock.
- **e2e, server** — `formr_app` recreated with `compose-coverage.yml`
  (Xdebug coverage mode, `coverage.ini` loading `prepend.php` for every
  request and every `docker exec formr_app php …`), into `e2e.json`. The
  daemons (`formr_run_daemon`, `formr_mail_daemon`) are not measured.
  `run.sh` recreates `formr_app` without the override on exit.
- **e2e, browser** — with `FORMR_JS_COVERAGE` set, the `page` fixture in
  `tests/e2e/helpers/test.js` records V8 coverage of our bundles
  (`helpers/jsCoverage.js`); `js-report.mjs` maps it onto the sources
  through the dev-build source map, line by line. Specs that import
  `@playwright/test` directly instead of `helpers/test`, pages other than
  the fixture's `page`, and the service worker are not measured.
