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
  (unauthenticated, POST-only, 16 KB cap) normalizes CSP-L2 / Reporting-API
  shapes via `Csp::extractReportFields()` and logs to `csp.log` via
  `formr_csp_log()` (rotates once at 20 MB → `csp.log.1`, so unauthenticated
  spam can't fill the disk; on `error_to_stderr` hosts it goes to docker logs).

## The policy (`Csp::buildPolicy`)

`script-src 'self' 'nonce-…'` (no `'unsafe-inline'`, no `'unsafe-eval'`);
`style-src 'self' 'unsafe-inline'` (pragmatic — inline styles can't run JS);
`worker-src 'self' blob:` (**load-bearing for the Ace editors**);
`img-src 'self' data: blob:`; `connect-src`/`frame-src`/`form-action` allowlist
OpenCPU / OSF / R-fiddle / Google Sheets / social share. `object-src 'none'`,
`base-uri 'self'`, `frame-ancestors 'self'`.

### Inline scripts: prefer an external file over a nonce

**Behaviour belongs in an external `'self'` file, not a nonce'd inline block.**
External scripts need no nonce and — crucially — don't depend on every template
correctly threading `$cspNonce`, which is the fragility that lets inline scripts
slip past enforcement (see the post-mortem below). Reserve inline + `nonce` for
*unavoidable* server-data injection (a one-line `window.formr = {…}` config).

Current state:
- **External `'self'` files** (no nonce): `admin/js/admin-ui.js` (delegated
  `[data-confirm]` confirm + `preventDefault` for bare-`#` anchors — loaded
  from `admin/header.php`, **not the footer**, so the confirm guard is active
  before any GET-executing delete link can be clicked),
  `admin/js/account-api-credentials.js` (the API-credentials panel behaviour),
  `admin/js/user_management.js` (superadmin reset-2FA). Server values reach them
  via `data-` attributes (`data-api-host`, `data-sa-ajax-url`, …).
- **Inline + nonce** (server-config only): the `window.formr = {…}` blocks in
  `admin/header.php` and `account/parts/header.php`.
- **No `javascript:` URLs**: CSP blocks `href="javascript:…"` navigations as
  script-src violations even for `void(0)`. JS-handled buttons use `href="#"`;
  `admin-ui.js` suppresses the default jump-to-top centrally.
- `public/head.php` inline scripts are **study-domain** → Phase 2, not yet nonced.

**Trailing slash gotcha:** the admin session cookie is scoped to
`Path=/admin/`, so a bare `/admin` URL is never authenticated (cookie not
sent). Always generate admin links via `admin_url()`/`site_url()` (they append
the slash); `redirect_to('admin')` is the bug class (fixed in the OSF flow).

### Post-mortem: why an inline script was missed

The static inventory grepped the literal `<script>` (with the closing `>`),
which matches only **attribute-less** opening tags. `user_management.php` used
`<script type="text/javascript">` — an attribute → skipped. It surfaced only via
the dynamic crawl (run as superadmin). Lessons: grep `<script` as a **prefix**
(then filter `src=` and non-JS `type=` like `text/formr`), scan **recursively**,
and trust the **dynamic crawl** over a static grep for completeness. Better still,
keep behaviour in files so there's no inline tag to miss.

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

**Superadmin coverage caveat:** the crawler authenticates as the test admin
(`survey_users.admin = 2`), so `/admin/advanced/*` superadmin pages return 403
and aren't CSP-tested with real content. To cover them, temporarily elevate the
account (`UPDATE survey_users SET admin=100 WHERE id=…`), crawl, then revert to
the original level. Doing this is how the `user_management.php` inline script was
caught — always restore the level afterward (`getSessionUser()` re-reads it from
the DB each request, so the revert is immediate).

## Flipping to enforce

When a crawl is clean, set in the **live** config
(`/home/admin/formr-docker/formr_app/config/settings.php`, not `formr_source/`):

```php
$settings['csp_mode'] = 'enforce';
```

`docker compose restart formr_app`. `Csp::headerName()` then emits
`Content-Security-Policy`. Keep `report-uri` so post-enforce regressions stay
observable. Rollback = one-line revert to `'report-only'`.
