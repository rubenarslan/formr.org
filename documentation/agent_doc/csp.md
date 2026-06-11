# Content-Security-Policy (admin area)

Defense-in-depth against the participant→admin stored-XSS class: a nonce-based
CSP on `admin_domain` so injected inline scripts/handlers don't execute even
where output escaping is missed. Phase 1 is **admin only** (study/api deferred).

## How it works

- **Nonce** — `Site::getCspNonce()` returns a per-request base64url nonce. `Site`
  is serialized into the session (`webroot/index.php`), so the nonce is reset in
  `Site::updateRequestObject()` (runs every request) and lazily regenerated —
  otherwise it would cache and defeat the policy. Exposed to templates as
  `$cspNonce` via `Controller::setView()`'s `$global`.
- **Header** — `Controller::applyCspHeader()` (called from `sendResponse()`)
  emits the policy, gated to `Site::inAdminArea()` + `text/html` (JSON / file /
  AJAX responses are skipped). Built by `application/Csp.php`.
- **Mode** — `$settings['csp_mode']` = `off` | `report-only` | `enforce`
  (default `report-only` in `config-dist/settings.php`). `report-only` emits
  `Content-Security-Policy-Report-Only` (observe, don't block).
- **Reports** — `report-uri /api/csp-report` → `ApiController::cspReportAction()`
  (unauthenticated, POST-only, 16 KB cap) logs to `csp.log` via `formr_csp_log()`.

## The policy (`Csp::buildPolicy`)

`script-src 'self' 'nonce-…'` (no `'unsafe-inline'`, no `'unsafe-eval'`);
`style-src 'self' 'unsafe-inline'` (pragmatic — inline styles can't run JS);
`worker-src 'self' blob:` (**load-bearing for the Ace editors**);
`img-src 'self' data: blob:`; `connect-src`/`frame-src`/`form-action` allowlist
OpenCPU / OSF / R-fiddle / Google Sheets / social share. `object-src 'none'`,
`base-uri 'self'`, `frame-ancestors 'self'`.

The 3 admin inline scripts (`admin/header.php`, `account/parts/header.php`,
`account/index.php`) carry the nonce. The 2 `onclick="confirm()"` handlers were
replaced by a nonce'd delegated `[data-confirm]` handler in `admin/footer.php`.

## dev vs prod: `'unsafe-eval'` is deliberately omitted

The dev `webroot/assets/dev-build/` bundles (from `npm run webpack:watch`) wrap
every module in `eval()` (eval-source-map devtool) — hundreds of `eval`s. The
prod `webroot/assets/build/` bundles have **zero**. So a Report-Only crawl on a
dev box reports thousands of `script-src eval` violations that **do not exist in
prod**. The policy omits `'unsafe-eval'`; the sweep classifies these as
`devBuildEval` and excludes them. Crawl a `build/` instance for a noise-free run.

## Running the sweep

```bash
# Report-Only must be live (csp_mode=report-only). Crawl the admin surface:
npx playwright test --config tests/e2e/playwright.config.js --project=local-chromium csp-crawl
# → tests/e2e/artifacts/csp-violations.md  (gitignored)
```

Or the full orchestration (enumerate routes → merge manifest → crawl → triage →
synthesize) via the Workflow tool with
`tests/e2e/csp-sweep.workflow.mjs`. Last run: **49 admin pages, 0 real
violations.**

## Flipping to enforce

When a crawl is clean, set in the **live** config
(`/home/admin/formr-docker/formr_app/config/settings.php`, not `formr_source/`):

```php
$settings['csp_mode'] = 'enforce';
```

`docker compose restart formr_app`. `Csp::headerName()` then emits
`Content-Security-Policy`. Keep `report-uri` so post-enforce regressions stay
observable. Rollback = one-line revert to `'report-only'`.
