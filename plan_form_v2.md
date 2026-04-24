# plan_form_v2: deep refactor of formr's survey engine

Status: **Draft for review** — this is a design spec, not a work plan yet. Nothing here is committed until we agree on the top-level decisions.

Branch: `feature/form_v2` (off `master` at v0.25.1).

---

## 0. TL;DR

We replace server-rendered, per-page POST-redirect surveys with **a single-page AJAX form** backed by **one new run-unit class (`Form`)** and a **deferred R-evaluation model** (client-side `showif` by default, explicit `r()` calls for server-side R through an allowlisted proxy, deferred AJAX fill for `value`/embedded Rmarkdown). We ship it **side-by-side with the current `Survey` unit** under a new `rendering_mode` flag on the existing `survey_studies` table — no forked item model, no separate DB tables, no fork of the admin UI for survey authoring. The participant-facing frontend gets a **fresh Webpack entry, a modern reactivity layer (Alpine.js 3), Bootstrap 5 scoped to participant pages, Font Awesome 6, and zero webshim**; the admin UI stays on Bootstrap 3/jQuery untouched for now. Offline is a service-worker-managed IndexedDB queue with Background Sync replay.

Phased rollout; sunset of `Survey` unit deferred by at least 18 months after form_v2 reaches feature parity.

---

## 0.5 Progress (as of 2026-04-23)

Branch `feature/form_v2`, not yet merged. Status reflects what's landed in code (not what's been verified by full test suites). See §9 for the phase definitions; the breakdown below mirrors them.

### Phase 0 — Plumbing
- [x] `Form` RunUnit class (`application/Model/RunUnit/Form.php`)
- [x] `rendering_mode` ENUM column on `survey_studies` (sql/patches/047)
- [x] `form_v2_enabled` feature flag in config
- [x] Admin "Add Form" button gated by the flag (fa-wpforms icon)
- [x] Admin editor template `templates/admin/run/units/form.php`
- [x] Form has its own `survey_units` row (type='Form'), references its study via `survey_units.form_study_id` (sql/patches/048) — not the v1 Survey quirk of sharing an id

### Phase 1 — Full-page rendering + AJAX page-submit, no R changes
Core (done):
- [x] New Webpack entry `form` + bundle `form.bundle.js`
- [x] Alpine.js 3 imported and registered (not yet used for reactive `showif`)
- [x] Bootstrap 5 scoped to participant pages via npm alias `bootstrap5`
- [x] Font Awesome 6
- [x] Tom-select installed and imported
- [x] jQuery dropped from the form bundle
- [x] `FormRenderer` single-document renderer (`application/Spreadsheet/FormRenderer.php`)
- [x] `Form::getUnitSessionOutput` branches on `rendering_mode='v2'`
- [x] New participant view `templates/run/form_index.php`
- [x] `Run::exec` + `RunSession::executeUnitSession` pass `use_form_v2` through
- [x] AJAX endpoint `POST /{runName}/form-page-submit` (`RunController::formPageSubmitAction`)
- [x] Client-side page show/hide via `<section data-fmr-page>` + vanilla JS nav
- [x] Per-page AJAX submit + native Constraint Validation
- [x] Multi-page grouping via `survey_items_display.page` (server) + `next_page` responses (client)
- [x] MySQL-format timestamps for item-view tracking (matches v1 contract)
- [x] Verified end-to-end with `enter_email` fixture

Gaps (Phase 1.5 — most closed during all_widgets smoke):
- [x] Tom-select auto-wired on every named `<select>` under `.fmr-form-v2`
- [x] IntersectionObserver for `shown`/`shown_relative` (threshold 0.25, one-shot)
- [ ] "Previous" button opt-in per form (button exists; per-form toggle not added)
- [ ] `history.pushState(?page=N)` verified across back-button + deep-link landings
- [x] Item-type parity audit: all_widgets exercises text/textarea/email/tel/url/number/date/time/month/week/datetime-local/color/range/range_ticks/radio/checkbox/select_one/select_multiple/mc_button/mc_multiple_button/check/check_button/rating_button variants/geopoint — renders correctly under v2
- [ ] "Not supported in v2" warning for gated item types (audio/video/file still deferred)
- [x] Inline `.is-invalid` + `.invalid-feedback` UI driven from server error response
- [x] BS3→BS5 compat pass in `form.scss` (form-group, labels, has-error, input-group-btn, square/button tiles, control-label with nested h/p, label-*, radio/check groups)
- [x] Multi-chunk processItems (FormRenderer walks every submit-delimited chunk)
- [x] Geopoint wiring (navigator.geolocation without webshim/jQuery)
- [x] PHP $_POST-semantics fix in client collectPayload (Check_Item crash on hidden+checkbox pair)

### Phase 2 — Item-type coverage
- [ ] Audio, video, ratingbutton variants
- [x] Geolocation (native navigator.geolocation in `main.js::initGeopoint`)
- [x] File uploads — client auto-switches to multipart when any `input[type=file]` on the page has a selected file; server `formPageSubmitAction` branches on Content-Type and reads `$_POST` + `$_FILES['files'][name]`
- [ ] Date/time/datetime-local/month/week across browsers
- [ ] Button groups (without `webshim`'s addShadowDom)
- [ ] Scale, slider, ratingbutton, mc_button
- [x] Submit item handling (v2 provides its own nav, skip v1's emitted submit — `FormRenderer::processItems` hides type='submit' items)

### Phase 3 — Client-side `showif` + transpiler hardening
- [x] JS evaluator wired to `data-showif` attribute on item wrappers; re-runs on every input/change against a live `answers` object; toggles `.hidden` + `display` + `input.disabled`
- [x] FormRenderer forces `data_showif=true` on every item with a non-empty `showif` (v1 only emitted `data-showif` when `setVisibility` hid the item server-side; v2 needs it always for reactivity)
- [x] Reuses v1's existing R→JS regex transpile in `Item.php` (covers `==`, `>`, `&&`, `||`, `%contains%`, `%begins_with%`, `is.na`, `tail(, 1)`, `current()`)
- [x] Standard library helpers in JS (`isNA`, `answered`, `contains`, `containsWord`, `startsWith`, `endsWith`, `last`) injected into every showif eval context via `Object.assign({}, helpers, ctx)` — answer keys shadow helpers. v1's `is.na(X)` regex transpile (`(typeof(X) === 'undefined')`) is re-rewritten client-side to `isNA(X)` since `collectAnswers` normalizes empty to `null`, not `undefined`
- [ ] Dedicated `showif_js` column on `survey_items` (presently re-transpiled at runtime from `showif`)
- [x] `r(...)` wrapper detection + auto-record in `survey_r_calls` at render time (`RAllowlistExtractor`, wired from `FormRenderer::processItems`)
- [x] `survey_r_calls` allowlist table (sql/patches/049)
- [x] `POST /{run}/form-r-call` endpoint for live r-call evaluation with answer overlay (`RunController::formRCallAction`)
- [x] Client r-call resolver: items with `data-fmr-r-call` POST debounced `{call_id, answers}`; apply `{result: bool}` with seq-guarded stale-response protection
- [ ] Hardened JS transpiler (proper parser, not regex)
- [ ] Upgrade-time compatibility scan (auto-wrap un-translatable R in `r()`)
- [ ] Alpine `x-show` bindings for JS `showif` expressions (current implementation is vanilla; Alpine is only imported, not yet used)
- [ ] Per-session rate-limit on r-call (deferred: `RateLimitService` is email-only today; needs a generic bucket API — Phase 4)

### Phase 4 — Deferred fill for `value` and embedded Rmd
- [x] `/form/r-call` proxy endpoint (Phase 3; slot='showif', reactive)
- [x] `/form/fill` deferred-fill endpoint (`RunController::formFillAction`, slot='value', one-shot on load). Shares `evaluateAllowlistedRCall` helper with form-r-call; enforces slot match so a showif call_id can't be used as a fill and vice versa
- [x] `r(...)` opt-in on `value` column: `FormRenderer::processItems` unwraps wrapped value, records in `survey_r_calls` with slot='value', clears `$item->value` so `needsDynamicValue()` returns false and OpenCPU batch skips it; emits `data-fmr-fill-id` on item wrapper
- [x] Client-side fill resolver: on load, POST `{call_id, answers}`; set the first `input/textarea/select[name]` inside the wrapper (only if empty — don't clobber user input on back-nav), fire input+change (so showifs re-evaluate), remove `.fmr-fill-pending`. On error, add `.fmr-fill-error` + inline feedback
- [x] Placeholder state: client adds `.fmr-fill-pending` on init before the fetch fires (classes_wrapper is protected on Item subclasses, so the server can't add it — the client does)
- [ ] `survey_r_call_results` cache table with TTL (deferred; no admin has complained about latency yet, and the cadence is one-per-participant-load)
- [ ] Rate limiting via `Services/RateLimitService` (blocked on generic bucket API; today it's email-only)
- [x] Bail-out UI when OpenCPU errors: client marks wrapper `.fmr-fill-error` and appends a `.fmr-fill-feedback` div with the server's error string (admin notification already wired via `notify_study_admin()`)
- [ ] Embedded Rmd in labels / page_body: not yet routed through r(...)+fill; continues to OpenCPU-knit at render time

### Phase 5 — Offline queue
- [x] IndexedDB store `formrQueue` — one object store `queue`, keyPath `uuid`, `client_ts` index for ordered drain
- [ ] Service-worker page-submit interception (MVP uses page-JS intercept only; SW comes later)
- [x] `/{run}/form-sync` endpoint (`RunController::formSyncAction`) — accepts one entry, dedups via `survey_form_submissions.uuid` pre-check + UNIQUE constraint backstop, applies via `UnitSession::updateSurveyStudyRecord` (same path as form-page-submit)
- [ ] Background Sync API (tab-lifetime only today via `online` event + initial-load drain; real SW + BS in a later slice)
- [x] Queued-submission UI banner — single `.fmr-queue-banner` node, variants: warning (offline/draining), success (done), danger (rejected validation)
- [x] Dedupe by client-generated submission UUID — `crypto.randomUUID()` fallback to v4-shape RNG; server regex-validates 8-4-4-4-12 hex shape so malformed inputs can't pollute the ledger
- [ ] `offline_mode` flag on `survey_studies` (opt-out) — not wired; MVP is unconditionally on for v2 Forms
- [ ] Unconditional SW registration on form_v2 runs
- [ ] iOS Safari compatibility pass
- [ ] File-size cap for queued uploads (default 10 MB) — file submissions skip the queue entirely today (multipart path, alerts on offline); Blob-in-IDB queueing is Phase 5 v2
- [x] Drain semantics: on success delete entry, if queue empty AND server returned `redirect` follow it so the run advances; on `drop_entry` (ended session) drop entry without retry; on transient failure stop and leave in place; on validation error surface `.alert-danger` banner
- [x] Patch 050 `survey_form_submissions` ledger (uuid PK, unit_session_id FK CASCADE, page, client_ts, applied_at)

### Phase 6 — Docs + migration tooling
- [ ] Admin UI for the compatibility scanner
- [ ] Documentation updates
- [ ] Example surveys ported to v2
- [ ] Automated v1↔v2 parity test suite (the "feature-parity gate")

### Cross-cutting (from §4.1 / §4.2)
- [x] Bootstrap 5 in form_v2 only (admin Bootstrap 3 untouched)
- [x] Font Awesome 6 in form_v2 only (admin FA 4.7 untouched)
- [x] `bootstrap-material-design` absent from form_v2 bundle
- [x] No jQuery in form_v2 bundle
- [ ] Webshim removal: native `setCustomValidity`/`reportValidity` helper module
- [ ] Webshim removal: `linkValidity` replacement for `addShadowDom` wrapper syncing
- [ ] Webshim removal: native geolocation wrapper for the `geolocation` item
- [ ] select2 replaced by tom-select in form_v2 templates (admin keeps select2 indefinitely — §10.6)

### Rollout gates (not yet reached)
- [ ] Feature-parity gate: automated v1↔v2 test suite green
- [ ] Default flip: new studies default to v2
- [ ] Sunset: deprecation warning on `Survey` units (v2 GA + 6 months)
- [ ] Sunset: hard removal target (v2 GA + 12 months per §10.1 — user shortened from 24)

---

## 1. Should `form_v2` exist in parallel? — **Yes, for now.**

### Arguments weighed

**For parallel (keep `Survey`, add `Form`):**
- Longitudinal studies in production can run for years. Breaking them is unacceptable; in-flight participants cannot be re-consented into a new rendering engine mid-study.
- The R-semantics change is *behavioral*, not just visual: current arbitrary-R `showif`/`value` surveys may not be mechanically translatable. We need an opt-in per-survey.
- The item type surface is ~60 subclasses with custom server-side render paths. Reaching parity in JS takes months. Incremental, item-by-item migration is safer than big-bang.
- Bootstrap 3 → 5 and webshim removal touch the entire admin UI if forced atomic. Isolating the change to participant forms limits blast radius.
- The underlying data model (`survey_studies`, `survey_items`, `survey_items_display`, per-survey results tables) is sound and **shared between both modes**. We don't fork data, only the rendering/execution path.

**Against parallel:**
- Forever maintenance burden if we don't commit to sunsetting `Survey`.
- Confusing matrix of features: admins ask "can I use this feature in form_v2?" for each one.
- Drift: bugs fixed in one path get missed in the other.

**Decision:** Parallel, but with a committed sunset. New studies default to `Form` once it passes a feature-parity gate (see §9). Existing `Survey` units remain functional. Hard deprecation warning surfaces for `Survey` 12 months after form_v2 GA; removal targeted for ~24 months. This is a long horizon for a hobby-ish research app — sped up if adoption is fast, slowed if we find load-bearing features that don't port.

### Scope of "parallel"

What's **shared** between `Survey` and `Form`:
- `survey_studies`, `survey_items`, `survey_item_choices`, `survey_items_display`, per-survey results tables.
- The admin UI for uploading items from spreadsheets, editing items, viewing results, deleting/expiring surveys.
- The spreadsheet format (XLSform-style) and `SpreadsheetReader`.
- The R package contract (data frame names, OpenCPU endpoints).
- The run-session / unit-session queue & state machine.

What's **new / forked**:
- `application/Model/RunUnit/Form.php` (new class, registered in `RunUnitFactory::SupportedUnits`).
- `application/Controller/FormController.php` or extension of `RunController` with AJAX endpoints for page-submit, r-call, deferred-fill, offline-sync.
- `application/Spreadsheet/FormRenderer.php` (single-page renderer, replaces `SpreadsheetRenderer` + `PagedSpreadsheetRenderer` for `Form` units only).
- `webroot/assets/form/` — new Webpack entry, new Bootstrap 5 + Alpine.js + modern deps. Clean split from `site/` / `admin/`.
- `templates/run/form/` — new participant templates.
- A new `rendering_mode` column on `survey_studies` (`'v1'` or `'v2'`, default `'v1'`, sets which renderer runs when this survey is used inside a `Form` or `Survey` unit).

What's **not changed now** (explicit non-goals):
- **All admin-facing changes are out of scope.** Admin UI stays Bootstrap 3 / jQuery / select2 / webshim. No modernization, no component swaps, no reworked workflows. The only admin-UI work this project does is the *minimum* affordances to create, edit, and upgrade form_v2 units — built on top of the existing admin stack, not a rebuild.
- Item type classes themselves (`Model/Item/*.php`). Each item still knows how to produce an HTML input; `FormRenderer` just consumes that differently. (§4.4 discusses future simplification.)
- The run-level engine (`Run`, `RunSession`, `UnitSession`). Form is just a new unit.
- OpenCPU server-side. Same endpoints, same R packages. We add a proxy layer on the formr side; OpenCPU doesn't know about it.

---

## 2. Unified `Form` RunUnit

### 2.1 Current split

- `Model/SurveyStudy.php` (~1270 lines) owns item metadata, spreadsheet import, schema migration of the per-survey results table, results querying.
- `Model/RunUnit/Survey.php` (~280 lines) is a thin wrapper: `create()` links a `SurveyStudy` to a run; `exec()`/`getUnitSessionOutput()` decides paged vs non-paged and hands off to a renderer.

The conceptual split is "reusable survey template vs. per-run usage". In practice, users confuse the two (you add a `Survey` unit, then you also manage the `SurveyStudy` under `/admin/survey`, and it's not clear which is which).

### 2.2 Proposed shape

Introduce `Model/RunUnit/Form.php` as a RunUnit that **exposes the form directly** — the unit *is* the form from the admin's point of view.

Internally, it still composes a `SurveyStudy` record (we keep the row for now — it's the natural home for results-table metadata and re-use across runs). But the admin UI and the UnitSession flow treat the `Form` as the unit of work:
- Adding a `Form` to a run creates or reuses a `SurveyStudy` row and wires it to the `survey_run_units` row in one step.
- Editing a `Form`'s items is a tab within the run-unit editor, not a separate destination.
- If a study re-uses a form across runs (valid today), that stays possible — the `Form` unit can point at an existing `SurveyStudy` (the "attach existing form" action) — but the UI deprioritizes this path; most admins don't need it.

In code:
```php
// application/Model/RunUnit/Form.php
class Form extends RunUnit {
    public $type = "Form";
    public $icon = "fa-wpforms";            // FA6; legacy surveys keep fa-pencil-square-o
    public $surveyStudy;                     // same field name as Survey for minimal churn
    public $rendering_mode = 'v2';

    public function exec($user, $run_vars = []) {
        $unitSession = $this->getUnitSessionFromContext(...);
        return (new FormSessionExecutor($this, $unitSession))->run();
    }
    // ...
}
```

### 2.3 What we DON'T do

- **No rename of `SurveyStudy`** in this project. It stays as the backing row/class. The admin-facing label becomes "Form" everywhere in v2 templates, but the DB column / class name churn is out of scope. When v1 is removed, we revisit.
- **No fork of item tables.** `survey_items` stays the source of truth. The new renderer just reads differently.
- **No new results-table layout.** The wide-format per-survey results table continues; v2 writes to it via the same code path (`UnitSession::updateSurveyStudyRecord()` or a modest variant).

### 2.4 Migration path for existing surveys

Admins can:
1. Leave existing surveys alone — they keep running as `Survey` units in `rendering_mode='v1'`.
2. For a study not yet live, click "Upgrade to form_v2" — flips `rendering_mode` to `v2`, runs the compatibility scan (§5.4), reports blockers.
3. For a live study, upgrade is disabled. A new clone at `v2` is offered instead.

---

## 3. Single-page rendering + AJAX submission

### 3.1 What changes

Today:
- **Non-paged**: whole form in one HTML document, POST submits everything, 302 redirect back to run URL.
- **Paged**: server renders one page at a time, POST submits current page, 302 redirect to next page URL. Page boundaries live in `survey_items_display.page`.

V2:
- The server renders **all items of the form, in all pages, in a single HTML response**, with `<section data-fmr-page="N">` wrappers. CSS hides all pages except page 1 on initial load.
- "Next" button: client validates the currently visible page, POSTs only that page's data to `/run/{name}/form/page-submit` (AJAX, `application/json`), on success hides current page, shows next, updates progress bar. No navigation, no 302.
- "Previous" button (new affordance, opt-in per form): goes back within the already-submitted pages. Server state is authoritative; if user edits a previous page, that page's data is re-POSTed on next forward.
- Submit on final page: same endpoint, server detects "last page", returns `{ next: 'run_advance' }`, client redirects to run URL, Run engine advances to the next unit.

### 3.2 Server-side changes

- `application/Spreadsheet/FormRenderer.php`: new class. Walks all items once, does an initial batch of OpenCPU calls for **only things that absolutely must be server-rendered at load time** (§5), emits the full-form HTML with pagination sections + deferred-fill placeholders.
- Controller endpoints (under `/run/{name}/form/…`):
  - `GET /` — renders the full form (if this is a `Form` unit).
  - `POST /page-submit` — body `{ page: N, data: {...}, item_views: {...} }`; validates & saves just that page's items via `UnitSession::updateSurveyStudyRecord($posted, $validate=true)` scoped to that page. Returns `{ status: 'ok', progress: 0.4, next_page: 3 }` or `{ status: 'errors', errors: {item: msg} }`.
  - `POST /r-call` — executes an allowlisted R expression (§5.3). Returns result or `{error}`.
  - `POST /sync` — drains the offline queue; body is an array of page-submits with client-side timestamps. Idempotent.
- `UnitSession::updateSurveyStudyRecord()` today also decides redirects and flushes the whole session. We factor that: the save-logic stays; redirect semantics move to the controller and become AJAX responses.

### 3.3 Client-side changes

- New entry: `webroot/assets/form/js/main.js`, bundled by Webpack as `form.bundle.js`. Loaded only on v2 participant pages.
- Alpine.js 3 for reactivity. Each `<section data-fmr-page>` is an `x-data` scope. `showif` expressions become `x-show` bindings. Inputs use `x-model` sparingly — we want native form semantics, so mostly `@input` handlers updating a reactive `answers` object.
- Page navigation is vanilla JS: validate visible page via Constraint Validation API, if OK then `fetch()` `page-submit`, on success hide `data-fmr-page="N"`, show `data-fmr-page="N+1"`, scroll to top, update `.fmr-progress`.
- Item display tracking (`_item_views[shown][id]`, `[answered]`) now fires from JS events (`IntersectionObserver` when a page becomes visible for `shown`; `input`/`change` for `answered`) and rides along with the page-submit payload.

### 3.4 Preserving timing data

The current `survey_items_display` table captures `shown`, `shown_relative`, `answered`, `answered_relative` via hidden inputs the JS updates on the fly. We keep exactly the same table schema and semantics — just populate the fields from an IntersectionObserver + input listener combo. This matters for researchers who use RT as data.

### 3.5 Browser back button & direct-URL paging

- V1 paging surfaces `?pageNo=3` in the URL; v2 doesn't, by default. That's a regression for some users (bookmarkable progress).
- Mitigation: push `?page=N` to history via `history.pushState` on page transitions. `popstate` handler shows the corresponding section without re-fetching.
- Users who land directly at `?page=5` — server-render with that section visible (still all pages in the DOM; just different initial `.visible` class).

---

## 4. Frontend stack: thin it, modernize it

### 4.1 What dies

| Dependency | Why | Replacement |
|---|---|---|
| `webshim` (1.16) | Unmaintained since 2018; only load-bearing for form validation polyfills and a ShadowDOM shim (§4.2). Our `browserslist` is Chrome ≥60 — everything it polyfills is native. | Native HTML5 Constraint Validation API + `setCustomValidity()`. ShadowDOM shim dropped (§4.2). |
| `select2` (3.5.1) | Abandoned. Bundle bloat (+jQuery coupling). | `tom-select` 2.x (vanilla, ~40kb min+gz, good a11y, same search/tag UX) in form_v2. Admin UI keeps select2 for now. |
| `bootstrap-material-design` (0.5.10) | Unmaintained, Bootstrap-3-era. | Remove from form_v2. Admin keeps it until the admin modernization project. |
| `bootstrap` 3.4.1 (in form_v2 only) | Form UX deserves modern components. | `bootstrap` 5.3.x, scoped to participant forms via a separate bundle. Admin keeps Bootstrap 3. |
| `font-awesome` 4.7.0 (in form_v2 only) | Old icon set, old class names. | `@fortawesome/fontawesome-free` 6.x. Class names largely compatible; migration is class renames in v2 templates only. |

### 4.2 Webshim removal: per-shim breakdown

Webshim is currently invoked for three things. Each has a concrete replacement:

1. **`forms` / `forms-ext` / `form-validators`** — constraint validation + custom validity rules.
   - Used in `survey.js` (custom rule `always_invalid` for OpenCPU-errored items, file-size validity).
   - Used in `PWAInstaller.js` to refresh after DOM mutations.
   - **Replacement:** native `setCustomValidity()` + `reportValidity()` + a tiny helper to set/clear custom validity on a list of elements after DOM changes. No polyfill needed on any browser in our target matrix.
2. **`dom-extend` / `addShadowDom`** — webshim's ShadowDOM-ish shim that keeps a wrapper element (e.g. `.button-group`, `.select2-container`) in sync with the real `<input>` for focus/validation/change events.
   - Used in `ButtonGroup.js`, `Select2Initializer.js`.
   - **Replacement:** a ~30-line shim (`linkValidity(realInput, wrapperEl)`) that forwards focus/blur/invalid events, delegates `validity`, and calls `setCustomValidity('')`/`reportValidity()` on the real input. Not ShadowDOM at all — just event plumbing, which is all webshim was doing in practice.
3. **`geolocation`** — wraps `navigator.geolocation` with error normalization.
   - Used in `survey.js` for the `geolocation` item type.
   - **Replacement:** native `navigator.geolocation.getCurrentPosition()` with a small promise wrapper and the same error-message mapping. One file change.

The `es5 es6` polyfills in `webshim.js` are already redundant with `core-js` in our Webpack config.

**Admin UI:** webshim stays in the admin bundle. Not touched in this project.

### 4.3 Reactivity / form library question

The user asked: "is there a dependency which would help us supply better forms?"

I evaluated:
- **React / Vue / Svelte + a form lib (react-hook-form / Felte / Conform)** — heavyweight for a hybrid PHP-rendered app; requires a full client-side model of the item types; SSR would have to translate PHP-rendered HTML into component state. High cost, diminishing returns: formr's item diversity is more varied than any form lib expects.
- **htmx** — natural fit for "server renders partial, swap into DOM." But: our core requirements are (a) reactive `showif` across an already-rendered page, (b) deferred AJAX fills, (c) offline queue. htmx is great at (b), fine at (a), awkward at (c). Would end up writing parallel JS anyway.
- **Alpine.js 3** (~15kb min+gz) — declarative (`x-show`, `x-init`, `x-effect`), no build step required, plays nicely with server-rendered HTML, lifecycle hooks suit deferred-fill. Easy mental model for admins editing templates. **Recommended default.**
- **Preact signals (`@preact/signals-core`)** (~2kb) — powerful reactive primitive; works without a framework. Good if we want fine-grained client reactivity without Alpine's conventions. Can be combined with Alpine (using signals as Alpine stores), or used standalone.
- **Lit + web components** — clean component model per item type. But: doubles the item-type surface (PHP class + Lit element), and admin UI doesn't use it. Over-architected for now.

**Recommendation:** Alpine.js 3 as the primary reactivity layer, optionally with `@preact/signals-core` for cross-page state (answers object shared between Alpine scopes and the offline-queue module). Do **not** pick a monolithic form library — the item-type surface doesn't fit the shape any library expects.

### 4.4 Item rendering — stay server-side

One temptation is to move item rendering to the client too (JSON-over-the-wire, JS components per item type). We don't, because:
- The 60-odd item types are PHP classes today. Porting each to JS is months of work for questionable benefit (HTML is the same either way).
- Search engines / accessibility tools / screen readers prefer server-rendered content.
- The PHP renderer is stable; the JS would re-derive the same HTML.

So: server renders all items. Client is responsible for **reactivity, transitions, validation UX, deferred fills, and submission**. HTML from the server is the source of truth; client progressively enhances.

### 4.5 Bootstrap 5 scoped to form_v2

New stylesheet entry `webroot/assets/form/css/form.scss` imports Bootstrap 5 with `@use 'bootstrap'` customizations. Scoped under a root `.fmr-form-v2` class to avoid conflicting with Bootstrap 3 on mixed pages (unlikely in practice since admin and participant are different routes, but belt-and-suspenders).

Templates in `templates/run/form/` are freshly authored against Bootstrap 5 markup — no mechanical port from v1 templates.

---

## 5. R evaluation transition

This is the biggest behavioral change and needs the most care.

### 5.1 Current behavior

- `showif` column on `survey_items`: arbitrary R string. Evaluated server-side via a batched OpenCPU call (`processDynamicValuesAndShowIfs` in `SpreadsheetRenderer.php`) at page render. Also pre-transpiled to rough JS in `Item.php` for client-side re-evaluation on input change (about 15 regexes; correct for simple expressions, wrong for anything complex — covered by a `//js_only` comment escape hatch that actually means *js-only, skip the R transpile*).
- `value` column: either literal, `sticky` (→ `tail(na.omit(survey_name$item_name), 1)`), or arbitrary R. Evaluated server-side at render time via the same batch.
- Embedded Rmarkdown in labels/pages: pre-parsed at import if static (stored in `label_parsed`), else OpenCPU-knit at render.

Problems:
- Every page render does an OpenCPU roundtrip. Slow.
- Arbitrary R from the admin flows untouched to OpenCPU. OpenCPU's sandbox is the only barrier.
- `js_showif` transpile is fragile; bugs manifest as client-side reactivity that disagrees with server.

### 5.2 Proposed model

- **Default: `showif` is client-side JS.** The column value is interpreted as a JS expression over the `answers` reactive object, e.g. `answers.q1 == 1 && answers.q2 !== "no"`. We provide a small standard library of helpers — `contains(str, substr)`, `last(arr)`, `isNA(v)` — that shim the ergonomic R-isms admins are used to.
- **Opt-in: server-side R via `r(...)`.** The admin wraps any R expression in `r(...)`, e.g. `r(complex_score(current(q1), current(q2)) > 0.5)`. The formr server detects `r(...)` wrappers during item import, records them in a new table, and emits a reference from the client instead of inlining.
- **Deferred fill for `value` and embedded Rmd:** Any dynamic value / Rmd section renders with a placeholder (`<span class="fmr-async" data-fmr-fill-id="…">…</span>` or a `<div>` skeleton). The client fetches via `/run/{name}/form/fill` and replaces.

Everything goes through an **allowlist** (§5.3) so admins can't just wrap `system("…")` in `r(...)` — though as today, an admin owning a study already has effective R capability; the allowlist is about (a) performance (pre-declared queries can be batched, memoized, cached) and (b) making the server/client split principled.

### 5.3 Allowlist mechanics

New table `survey_r_calls`:
```
id           BIGINT PK
study_id     references survey_studies.id
slot         'showif' | 'value' | 'label' | 'page_body' | 'choice_label'
item_id      nullable (for label / choice_label)
expr         text, the raw R string the admin wrote (inside r(...))
expr_hash    sha256 of expr for dedup/caching
created, modified
```

- Populated at item import (`SpreadsheetReader` changes): every `r(…)` occurrence in `showif`, `value`, `label`, `page_body`, `choice` gets a row.
- The renderer emits references, not the expression. HTML contains `data-fmr-r="{id}"` (or `data-fmr-fill-id="{id}"` for deferred).
- Server endpoint `/run/{name}/form/r-call` accepts `{id, args}` where `args` is a JSON object of current `answers` the expression needs. Server looks up `id`, forges the actual R call (injecting the session's data frame name like today), sends to OpenCPU, returns result.
- Idempotent + memoizable: if `expr_hash` + a fingerprint of `args` has been computed this session, we can return cached result. A separate `survey_r_call_results` cache with TTL is worthwhile for heavy Rmd blocks.
- Rate-limited (§5.5).

Result: admin-authored R still runs, but (a) the client never sees the R source, (b) formr controls when/how often it's called, (c) arbitrary improvised R from the client is rejected because there's no endpoint that accepts raw R.

### 5.4 Migration of existing `showif` / `value`

At form upgrade time (admin clicks "Upgrade to form_v2"), the spreadsheet is re-scanned:

- `showif` expressions: pass through a JS-transpiler (reuse & harden current `Item.php` regex-transpile; add a proper parser — probably [Esprima](https://esprima.org/) or a small custom one — and support more R built-ins). Result is one of:
  1. **Translatable:** JS expression written to new `showif_js` column. Original R stays in `showif` for audit.
  2. **Not translatable:** admin is shown a warning, told to wrap in `r(...)` to opt in to server-side R, or rewrite in JS.
- `value` expressions:
  1. **Literal numeric:** no change.
  2. **`'sticky'`:** becomes a client-side lookup (`lastAnsweredValue('item_name')`) — no R needed.
  3. **R code:** automatically wrapped in `r(...)` and recorded in `survey_r_calls`; the item gets a deferred-fill placeholder.
- Embedded Rmd in labels: same as today's `opencpu_knit_plaintext` path, but deferred (placeholder + fill). Static Rmd (no reactive deps) stays pre-parsed at import into `label_parsed`.

The migration pass is **non-destructive**: the original columns stay; we add `showif_js`, `showif_r_call_id`, `value_r_call_id` side-by-side.

### 5.5 Rate-limiting & safety

- `Services/RateLimitService.php` already exists. Add buckets: per-session r-calls per minute, per-session fills per page load.
- Bail-out UI: if OpenCPU errors for a deferred fill, placeholder shows a localized "failed to load" message; admin gets a `notify_study_admin()` error (existing machinery, wired in v0.25.0).
- No new OpenCPU security surface: we're adding a proxy, not exposing a new endpoint to participants.

### 5.6 What about showif expressions that *must* be server-evaluated for privacy reasons?

Some admins might use showif to hide an item based on a stored R-computed result that participants should not see the formula for. The `r(...)` wrapping preserves this: the R source is never shipped to the client, the client just gets `{shown: true/false}` back. Good.

---

## 6. Offline capability

### 6.1 Requirements

- If the page-submit `fetch()` fails because of network, queue the submission locally.
- Show the user a clear "offline, will submit later" banner.
- When connectivity returns, replay the queue in order.
- Idempotency: re-submitting the same page must not double-count. Server-side needs a client-generated submission UUID to dedupe.

### 6.2 Design

**IndexedDB store** `formrQueue`:
```
{
  id: uuid,                 // client-generated, unique
  unit_session_id: string,
  form_id: int,
  page: int,
  payload: { answers, item_views, client_ts },
  created_at: ISO string,
  attempts: int,
  last_error: string | null
}
```

**Service worker** intercepts `POST /run/{name}/form/page-submit`:
1. If online: pass through, return server response.
2. If offline OR server returns 5xx: stash in IndexedDB, register a `sync` event tag, return a synthetic `{ status: 'queued', id: … }` response.
3. Page JS shows "queued" badge; user can proceed to next page locally (we already have all items in the DOM; transitions are client-side).

**`sync` handler** drains the queue via `POST /run/{name}/form/sync` with an array of records. Server:
- Accepts batch, validates each with `client_ts` for ordering.
- Dedupe by `id`: if `id` was already stored, return 200 (idempotent).
- Persists each page's data via the same `UnitSession::updateSurveyStudyRecord` path.
- Returns per-record result.

**Fallback for browsers without Background Sync** (iOS Safari has been flaky): `online` event handler on the page fires a manual drain, plus a drain-on-load check.

### 6.3 Limits

- Offline mode is **page-level**, not item-level. The unit of replay is a page submission. If the participant closes the tab before connectivity returns, the queue persists in IndexedDB and drains next time they open the PWA.
- R calls (`/r-call`, `/fill`) are **not** queued — they fail gracefully with a placeholder indicating the admin content couldn't load. The `showif`/`value` is only "critical path" if the admin made it so; we surface this as a compatibility warning at form-design time.
- Files — `<input type=file>` — go through the queue as `FormData` serialized into IndexedDB as a `Blob`. Big uploads may fill quota; we reject files > 10MB for queued-offline mode (configurable).

### 6.4 Security

- Data in IndexedDB is **not encrypted** in this design. Queue entries persist plaintext answers on the participant's device. Offline mode is **default-on** for form_v2 runs; admins of studies collecting sensitive data can explicitly disable it per-form via a `survey_studies.offline_mode` flag (default `on`; set `off` to suppress queueing, in which case submissions fail hard when offline rather than being persisted locally).
- When the PWA is uninstalled or the participant logs out, the service worker wipes the queue.

### 6.5 Relationship to installable PWA

Service-worker registration and installable-PWA UX are two separate things today but bundled under one admin flag. For form_v2 we split them:

- **Service worker registration is unconditional for form_v2 runs.** The existing `webroot/assets/common/js/service-worker.js` is *extended* (not replaced) with page-submit interception + IndexedDB queue + Background Sync. Same file, additional responsibilities. SW scope is already the run's subdomain, so each run's queue is naturally isolated.
- **The run's "installable PWA" admin flag stays opt-in** and continues to control: PWA manifest generation, install prompts, the `AddToHomeScreen` item, iOS push usability (iOS requires install for push), and auto-extension of `expire_cookie` to 1 year.

Reasons to keep install opt-in rather than forcing full PWA on every form_v2 run:
- The 1-year cookie extension is a GDPR-relevant choice that should stay explicit.
- Install prompts are participant-visible friction; one-shot studies shouldn't fire them.
- A study that hasn't configured icons / app-name produces an ugly install prompt (default favicon, "formr" as the app name). Making admins opt in forces them to think about those fields.
- `PushNotification` and `AddToHomeScreen` items continue to require the installable flag — if added to a non-installable run, they surface a clear "enable installable PWA for this run" error at render time.

Net effect: offline queue works on every form_v2 run out of the box; the installable-app experience remains deliberate.

---

## 7. Validation

Native HTML5 Constraint Validation API is the single model:

- Required / pattern / min / max / step / type come from the `<input>` attributes the PHP renderer already emits.
- Custom server-known errors (e.g. OpenCPU `value` error, file too big) set via `elem.setCustomValidity('message')` on page load, cleared on input.
- "Invalid" items block page advance: `page.reportValidity()` before submit.
- Error display: Bootstrap 5 `.invalid-feedback` sibling, wired via a tiny helper that listens for `invalid`/`input` events.

Server re-validates on page-submit as today (via `Item::validateInput()` and `UnitSession::updateSurveyStudyRecord($posted, $validate=true)`). The server is still source of truth; client-side validation is a UX smoothing, not a trust boundary.

No webshim, no form lib, no React Hook Form — just the native API plus a ~100-line helper module.

---

## 8. File and code layout

```
application/
  Controller/
    FormController.php                 # new — or actions added to RunController
  Model/
    RunUnit/
      Form.php                         # new
  Spreadsheet/
    FormRenderer.php                   # new
    RAllowlistExtractor.php            # new — parses r(...) wrappers at import

webroot/assets/form/                   # new bundle root
  js/
    main.js                            # entry for form.bundle.js
    alpine-init.js
    reactivity/
      answers-store.js                 # @preact/signals-core wrapper
      showif-runtime.js
    submission/
      page-submit.js
      offline-queue.js
      sync-manager.js
    r/
      r-call.js                        # POSTs to /form/r-call, handles cache
      deferred-fill.js                 # resolves .fmr-async placeholders
    validation/
      constraint-api.js                # native-validation helper, replaces webshim code
      validity-link.js                 # replaces addShadowDom plumbing
    items/                             # JS-side item enhancements
      button-group.js                  # no-webshim version of ButtonGroup.js
      select.js                        # tom-select wrapper
      date.js                          # native <input type=date>, flatpickr fallback
      geolocation.js                   # native API
  css/
    form.scss                          # Bootstrap 5 + form-v2 styles
  img/

templates/run/form/                    # new participant templates
  index.php
  page_wrapper.php
  footer.php

webroot/assets/common/js/service-worker.js  # extended — page-submit interception, sync; unconditional on form_v2 runs
sql/patches/
  047_add_rendering_mode_to_survey_studies.sql
  048_create_survey_r_calls.sql
  049_add_offline_mode_to_survey_studies.sql
```

Webpack adds entries (`webpack.config.js`):
```js
entry: {
    material: '...',
    frontend: '...',
    admin: '...',
    form: './webroot/assets/form/js/main.js',   // NEW
},
```
Output bundle `form.bundle.js` loaded only when `Form` unit renders (controller decides, templates branch).

---

## 9. Phased rollout

Each phase is a mergeable milestone; a broken half-phase doesn't ship.

**Phase 0 — Plumbing (1-2 weeks).** Add `Form` RunUnit class (minimal: just "is a survey, renders via existing v1 pipeline under a feature flag"), `rendering_mode` column, phase-gated admin UI to create v2 forms. Feature flag `form_v2_enabled` in settings. No behavior change yet.

**Phase 1 — Full-page rendering + AJAX page-submit, no R changes (3-4 weeks).** New Webpack entry, Alpine.js, Bootstrap 5 templates. Server still evaluates `showif`/`value`/Rmd exactly as today (pretending the page is "non-paged" from the server's perspective). Client-side pagination hides/reveals sections. AJAX page-submit. No offline. No allowlist R yet. Item-type parity: support the 20 most-used types first, gate others behind "not supported in v2" warning.

**Phase 2 — Item-type coverage (2-3 weeks).** Fill out the remaining item types (audio, video, ratingbutton variants, geolocation, file, etc.).

**Phase 3 — Client-side `showif` + transpiler hardening (2-3 weeks).** Ship the JS-by-default model. Keep `r()` as fallback (server-side R). Migration tool for existing surveys. All showifs that can't be transpiled get `r()`-wrapped automatically, unless admin rewrites in JS.

**Phase 4 — Deferred fill for `value` and Rmd (2 weeks).** Placeholder/skeleton UI, `/form/r-call` + `/form/fill` endpoints, allowlist table, rate limits. Rollout behind a per-form flag first; on by default once stable.

**Phase 5 — Offline queue (3-4 weeks).** IndexedDB store, service-worker extension, Background Sync, `sync` endpoint, UI banners, dedupe. Default-on for form_v2 runs (opt-out per form for sensitive studies). SW registration promoted to unconditional at this phase. iOS Safari tested heavily — we already know from v0.25.x that it's finicky.

**Phase 6 — Docs + migration tooling (1-2 weeks).** Admin UI for the compatibility scanner, documentation updates, example surveys ported.

**Feature-parity gate (end of Phase 5):** a v1 survey upgraded to v2 produces the same results for a test participant traversing the same path. Enforced by an automated test suite (Phase 6 also adds this).

**Default flip:** new studies default to v2 after the parity gate. Admins can still opt for v1 for a couple of releases.

**Sunset:** deprecation warning banner on `Survey` units starts at v2 GA + 6 months. Hard removal targeted at v2 GA + 24 months. Revisit at each checkpoint; don't pull a version at cost of in-progress longitudinal studies.

Cumulative estimate: ~4-5 months of focused engineering by a single developer with design review help. Could be faster with parallel work on phases 2/3/4.

---

## 10. Risks and open questions

### 10.1 Risks

- **R-semantics drift.** JS-transpiled `showif` might behave subtly differently from R for edge cases (NA handling, coercion, vectorized ops). Mitigation: automatic parity test at migration — server evaluates R, client evaluates JS, compare; fail the upgrade if they disagree on recorded participant answers.
- **OpenCPU endpoint surface change.** The `/r-call` proxy is new. Any change in OpenCPU's R-package-freezing policy at upstream versions could land silently. Mitigation: add OpenCPU version asserts at form-run startup; fail visibly.
- **Browser back button + offline queue.** If a participant goes back to a queued but not-yet-replayed page and edits, do we rewrite the queued entry or enqueue a new one? Decision: rewrite (most-recent-wins; `id` keyed on `(unit_session_id, page)`).
- **Tom-select and select2 parity.** Tom-select covers most select2 use cases but admins may have CSS tweaks keyed to `.select2-*` selectors. Participant-facing v2 is new template — no tweaks inherited — so this is fine for form_v2. Admin UI keeps select2.
- **Alpine.js "magic."** Admins who embed raw HTML in labels may collide with Alpine directives (`x-data`, `@click`). Mitigation: strip/escape `x-*` and `@*` attrs in parsed Rmd output, document that admins shouldn't write them.
- **Bootstrap 3 / 5 style leakage on mixed pages.** If a v2 form ever ends up inside an admin page (preview mode), styles collide. Scope v2 CSS under `.fmr-form-v2` root class and check isolation in the admin preview path.

### 10.2 Open questions for the user

1. **Sunset aggressiveness.** Am I right that we should keep `Survey` alive for 24 months post-v2-GA, or is that too long given maintenance burden? Shorter (12 months) is doable if we're willing to tell longitudinal-study owners to clone into v2.
- 12 months is ok. At some point, I'd turn on the new engine by default for new surveys.
2. **Allowlist granularity.** Should the allowlist be at the *expression* level (every unique `r(...)` is a record, as proposed) or at the *function* level (list of allowed R function names, expressions can call any combination)? Expression-level gives stronger guarantees (no improvisation), function-level is more flexible for admins. I've proposed expression-level; worth your view.
  - expression level.
3. **Offline default.** Opt-in per form, or default on for non-sensitive studies? I've proposed opt-in; inverse is reasonable if most studies are low-sensitivity and want resilience by default.
  - opt-out is preferable.
4. **Admin UI modernization.** Explicitly out of scope here. Do you want it sequenced right after form_v2 (maybe months 5-8), or much later?
  - out of scope now.
5. **Drop jQuery in participant bundle?** Form_v2 can be jQuery-free (Alpine is the whole reactivity story; remaining code is vanilla JS). This would save ~30kb on participant pages. Admin keeps jQuery indefinitely. Worth it?
  - drop.
6. **Select2 → tom-select in admin.** Kept in scope for later, but worth deciding roughly when. Two follow-up months after form_v2 ships?
  - defer indefinitely — all admin-facing changes out of scope for this project.
7. **Accessibility targets.** Current formr has no declared a11y commitment. form_v2 is a good moment to adopt a baseline (WCAG 2.1 AA). Want to commit?
  - yes
8. **R package contract.** Some admins rely on the `formr` R package (`current()`, `last()`, `%contains%`). With client-side JS showif, we're shimming these in JS. Do we mirror exactly, or evolve? E.g. is `current(x)` still a nice spelling when there is no "previous wave" concept on the client?
  - mirror except stuff that doesn't make sense.

---

## 11. Appendix: what stays the same

To keep the reviewer's mental load low, a short list of things **not** changed:

- Run engine (`Run`, `RunUnit` base, `RunSession`, `UnitSession`) — new Form just plugs in.
- Other RunUnit types (Pause, Email, PushMessage, External, Page, SkipBackward/Forward, Shuffle, Wait, Branch, Privacy).
- Spreadsheet import format (XLSform-style columns). Admins author the same spreadsheet.
- `Item` subclasses and their PHP rendering.
- DB schema for `survey_studies`, `survey_items`, `survey_item_choices`, `survey_items_display`, per-survey results tables — we only add columns.
- OpenCPU server and the formr R package.
- PWA manifest generation, push-notification delivery, cookie consent — unchanged. Related change: the service worker is now registered unconditionally on form_v2 runs (§6.5), but the installable-PWA flag that gates manifest/install-prompt/cookie-extension stays opt-in.
- Admin UI (Bootstrap 3, jQuery, select2) — untouched in this project.
- CSRF model (already cookie-based after v0.25.1; don't reintroduce tokens).

---

## 12. References (files to read when implementing)

- `application/Model/SurveyStudy.php` — item CRUD, results-table schema migrations, spreadsheet import.
- `application/Model/RunUnit/Survey.php` — the v1 unit; v2's `Form` will look structurally similar.
- `application/Model/Item/Item.php` — 716-line base class; understand lifecycle before changing rendering.
- `application/Spreadsheet/SpreadsheetRenderer.php`, `PagedSpreadsheetRenderer.php` — current rendering; `FormRenderer` replaces both for v2.
- `application/Services/OpenCPU.php` — existing R client; `/r-call` proxy wraps this.
- `webroot/assets/common/js/survey.js` — v1 client; much of its logic is salvageable for v2 (geolocation, custom validity) after the webshim strip.
- `webroot/assets/common/js/service-worker.js`, `pwa-register.js` — where offline interception lands.
- `CHANGELOG.md` — v0.25.1 context for recent iOS push / CSRF removal / cookie-consent decisions that v2 inherits.

---

## 13. Implementation learnings (Phase 0 → 1 → 1.5 → 3)

Gathered as this branch was written. Some were footguns; some invalidate the design spec's earlier assumptions. Preserved so later phases don't re-learn them.

### 13.1 Form cannot share `survey_units.id` with its `SurveyStudy`

The original Phase 0 sketch inherited the v1 Survey pattern — v1's `Survey::create` re-points `survey_run_units.unit_id` at the attached study's id (Survey and SurveyStudy share a primary key via FK). For a Form wrapping a study, that re-point orphans the Form's own `survey_units` row, and on the next page load `RunUnitFactory` sees `type='Survey'` instead of `type='Form'` and instantiates the wrong class. Symptom: admin UI looked right but v2 rendering never fired.

Fix (patch 048): dedicated `survey_units.form_study_id` column. `Form::create` strips `study_id` from options before calling `Survey::create` (to skip the re-point), then writes `form_study_id` separately. `Form::getStudy` loads via `form_study_id`, not `$this->unit_id`. This means Form keeps its own survey_units row (type='Form') *and* references a SurveyStudy.

### 13.2 `Model::assignProperties` drops undeclared DB columns

`Model::assignProperties` checks `property_exists($this, $prop)` before copying — a column in the DB row that isn't declared as a public property on the model is silently discarded. First bug I hit: `rendering_mode` was in the DB row but `SurveyStudy` didn't declare it, so `$study->rendering_mode` came back as the class default ('v1'), and the v2 branch in `Form::getUnitSessionOutput` never fired. **When you add a column, always declare the property on the Model subclass.**

### 13.3 `SpreadsheetRenderer::processItems` only emits the first submit-delimited chunk

That's intentional for v1, which renders one paged chunk at a time. For v2's single-document all-pages render it's wrong. `FormRenderer::processItems` overrides with a new `getAllUnansweredItems()` query (same WHERE as `getNextStudyItems` but without the `$inPage` short-circuit) and runs one OpenCPU batch across every item. Submit items are hidden on the way through — v2's client provides its own nav, so the v1-emitted Submit button is noise.

### 13.4 Page numbers already live on `survey_items_display.page`

`UnitSession::createSurveyStudyRecord` inserts one row per item at initial render, with `page` incremented at each submit-type item. No new schema needed for multi-page: `FormRenderer::fetchPageMap()` reads that column back and `groupByPage()` buckets rendered items accordingly.

### 13.5 `use_form_v2` must passthrough three layers

`Form::getUnitSessionOutput` returns `['content' => ..., 'use_form_v2' => true]`. But `RunSession::executeUnitSession` rewrites the result into `['body' => $content]` (drops other keys), and `Run::exec` returns a fixed-shape dict (drops further keys). Each layer needed an explicit passthrough for the view-picker in `RunController::indexAction` to see the flag. Similar traps probably await any other v2 state we try to propagate up.

### 13.6 `SurveyStudy.rendering_mode` alone drives branching; feature flag gates *creation*, not execution

Initially I checked both `Config::get('form_v2_enabled')` and the study's `rendering_mode` in `Form::getUnitSessionOutput`. That would silently regress live v2 forms if the admin ever flips the flag off. Decision: the flag only gates the "Add Form" admin button; once a SurveyStudy has `rendering_mode='v2'`, the v2 pipeline fires regardless of the flag. If you need a circuit breaker, flip `rendering_mode` back to 'v1' instead.

### 13.7 MySQL `DATETIME` rejects ISO-8601 with milliseconds

`new Date().toISOString()` produces `2026-04-23T17:39:48.814Z` → MariaDB errors with "Incorrect datetime value" on `survey_items_display.shown`. Use the same `mysql_datetime()` helper v1 has: `toISOString().slice(0, 19).replace('T', ' ')`. Applies to any field typed `DATETIME` — item views, saved timestamps, etc.

### 13.8 Required + readonly inputs are *not* `:invalid`

Geopoint's visible field is `readonly` + `required`. Per HTML5, `:invalid` never matches it — the browser bypasses the required constraint on readonly inputs. So the client's `:invalid` pre-check passes while the server's validation still fails. Don't rely on `:invalid` alone to pre-flight a submission when an item's display is readonly. Two options: JS fills the field (geopoint does this via `navigator.geolocation`), or validation runs through `setCustomValidity` keyed off the hidden JSON companion.

### 13.9 Bootstrap 3's `.hidden` is `display:none !important`

V1 marks a hidden item by adding the `.hidden` class (via `hide()`). The v2 client tried to re-show an item by setting `style.display = ''` — no effect, because the class ships `!important`. Always toggle the class *as well*.

### 13.10 Client payload must match PHP `$_POST` semantics

PHP parses form bodies with these rules: names ending in `[]` are arrays; everything else is scalar, last-wins. The first pass of `collectPayload` naively promoted any same-named input to an array, which turned Check_Item's hidden+checkbox pair (both named `confirm1`) into `["0", "1"]` → `h()` crashed on array input. Always split on `[]` suffix as the array signal; otherwise last-wins scalar.

### 13.11 Bootstrap 3 + 5 coexistence via npm alias

`npm install bootstrap@5` clobbered the existing `bootstrap@3.4.1` dependency the admin bundle still depends on. Fix: install BS5 under an npm alias (`"bootstrap5": "npm:bootstrap@^5.3.8"`). Imports in the form bundle use `from 'bootstrap5/dist/css/bootstrap.min.css'`. Same trick works for tom-select, FA6, etc. if they ever clash with admin deps.

### 13.12 v1 already has an R→JS `showif` regex transpile

`Item::__construct` at ~line 221 runs `$this->showif` through a series of regex rewrites to produce `$this->js_showif`. Covers `==`/`>`/`<=`/..., `&` vs `&&`, `|` vs `||`, `FALSE`/`TRUE` → `false`/`true`, `%contains%`/`%begins_with%`/`%ends_with%`/`%starts_with%`/`%contains_word%`, `is.na()`, `stringr::str_length()`, `tail(x, 1)`, `current(x)`. Fails silently on anything more complex; the client's `new Function()` then throws and falls through to "show" as a conservative default. **Phase 3 rides on this transpile — a dedicated `showif_js` column and proper parser are deferred.**

### 13.13 v1 only emits `data-showif` when it server-side hides the item

`$item->data_showif` is set true inside `Item::hide()`, i.e., only for items the server-side `setVisibility` marked hidden at render. For v2 reactivity we need `data-showif` on *every* item with a non-empty `showif`, so `FormRenderer::render` forces `$item->data_showif = true` for all showif-bearing items before handing off to `$item->render()`.

### 13.14 Server-side showif evaluation at initial render can't know user answers

In v2's single-document layout, all items render at once — OpenCPU evaluates every showif with *empty* answers. Items whose showif depends on a yet-to-be-answered item either end up visible (NA → conservative show) or hidden (NA → conservative hide) *at load time*, and neither matches what the participant actually sees after they fill in the dependency. Phase 3's client-side JS evaluator fixes this for *transpilable* showifs. Items whose showif contains R-only constructs the regex transpiler can't handle need Phase 3's `r()` opt-in + a server proxy to re-evaluate after each answer — not yet implemented.

### 13.15 TomSelect `controlInput: null` vs. search input

Default TomSelect mounts a search input for every select. On small MC-style selects that's noise. Gate the search UI on `options.length > 20 || classList.contains('select2zone')` to match v1's "large list needs search" heuristic.

### 13.16 The `hidden` HTML attribute vs the `.hidden` CSS class

DOM `el.hidden = true` sets the HTML `hidden` attribute, which most browsers paint as `display:none` (low specificity, overridable). Bootstrap's `.hidden` class ships `display:none !important`. v2 uses both for different things: page-section visibility via the `hidden` attribute (`<section hidden>`), item-level visibility via the `.hidden` class + `display:none` inline. Mixing the two without realising the specificity difference caused several "why isn't this showing" debugging detours.

### 13.17 `all_widgets_with_values.xlsx` is not a substitute for the live `all_widgets` Google sheet

The xlsx fixture in `documentation/example_surveys/` hit ~40 "OpenCPU showif error" badges in this dev env — the Google-sheet version the user pointed at loaded cleanly. Before debugging an issue against the xlsx, import from the live sheet: `https://docs.google.com/spreadsheets/d/1vXJ8sbkh0p4pM5xNqOelRUmslcq2IHnY9o52RmQLKFw`.

### 13.18 Reactive test harness — TomSelect and showif break naïve "fill every input" scripts

Setting `select.selectedIndex = 1` doesn't notify TomSelect. Use `select.tomselect?.setValue(...)` or `dispatchEvent(new Event('change', {bubbles:true}))` to trigger the v2 client's reactive handlers. Similarly, showif dependents won't reveal unless the dependency's change event actually fires. Any "fill-the-form" test utility needs to be explicit about firing change events in the right order.

### 13.19 Allowlist is populated at render time, not at import

The spec's original Phase 3 shape (§5.3) populated `survey_r_calls` in the spreadsheet reader. That's workable but forces every admin save to rescan the full sheet for `r()` wraps and produces stale rows when admins edit. The current implementation does it in `FormRenderer::processItems`: per render, each item's `showif` is passed through `RAllowlistExtractor::unwrap`, and wrapped expressions are UPSERTed into `survey_r_calls` (dedup by `study_id + expr_hash + slot`, id recovered via `LAST_INSERT_ID(id)`). Security invariant is identical — at r-call time the server only evaluates what's already in the table keyed by id — and there's no schema drift between import and render. The `expr_hash` unique key also means an expression used in ten items shares one row, so downstream caching/rate-limiting can key off call id.

### 13.20 `r()` unwrap must strip the wrapper before OpenCPU sees the expression

OpenCPU only has the base + formr R packages loaded; `r()` is not a defined function. If `Item::getShowIf()` returns `r(nchar(trigger) > 2)` verbatim, OpenCPU errors with "could not find function 'r'" and initial server-side visibility is lost for *every* r()-wrapped item. Fix: `FormRenderer::processItems` mutates `$item->showif` to the unwrapped inner R before calling `parent::processDynamicValuesAndShowIfs`, and clears the mangled-by-transpile `$item->js_showif` so no stale JS ever reaches `data-showif`.

### 13.21 DB_Select uses `fetch()`, not `fetchRow()`

Confused with `DB::findRow()`. `DB::select()->from()->where()->fetch()` is the one-row fetch on DB_Select; `DB::findRow($table, $where)` is the higher-level table lookup. They return the same shape but live on different classes — and the linter won't catch this because `fetchRow` isn't a typo on any PDO-ish class either. Lint is not a substitute for one end-to-end smoke.

### 13.22 File upload: multipart on demand, `$_FILES['files'][name]` namespace

v2's default page-submit is JSON; files can't ride in JSON, so the client switches to `FormData` only when the current page has a non-empty `<input type=file>`. The FormData is keyed `data[<scalar_name>]`, `data[<array_name>][]`, `item_views[<bucket>][<item_id>]`, **and** `files[<item_name>]` — keeping file bytes outside `data` so `$_POST['data']` stays parallel to the JSON path. Server reads `$_FILES['files']['name']` etc. and re-projects into the flat `{name,type,tmp_name,error,size}` shape File_Item::validateInput expects. Verified end-to-end with a minimal `file_smoke.xlsx` fixture: row appears in the per-study table and in `user_uploaded_files`.

### 13.23 Playwright MCP file uploads are path-restricted

`browser_file_upload` only accepts paths under the repo root or `.playwright-mcp/`. Handing it `/tmp/whatever.txt` errors with "outside allowed roots". For test fixtures you need to upload from, stage them under `.playwright-mcp/` (already gitignored) or another repo path.

### 13.24 Study subdomain DNS + session reset gotcha

Per-study URLs under `study.researchmixtape.com/<runName>/` work because the dev instance is *not* using wildcard subdomains for studies (the `FMRSD_CONTEXT` path from the subdomain-based model isn't wired). `https://<runName>.researchmixtape.com/` fails with a cert error. Always use `https://study.researchmixtape.com/<runName>/`. Separately: if a test run doesn't have a Stop unit, the session "dangles" after the Form completes and subsequent Test-run visits show "Oops, creator forgot a Stop". To re-test, either add a Stop unit or `DELETE FROM survey_unit_sessions WHERE run_session_id=?` + `DELETE FROM survey_run_sessions WHERE id=?` to reset the session for that user code.

### 13.25 v1's `is.na(X)` regex transpile silently never fires on the v2 client

`Item.php` rewrites R `is.na(X)` → `(typeof(X) === 'undefined')`. Seemed fine — until you notice `collectAnswers()` normalizes every unanswered/empty/unchecked input to `null`, not `undefined`, which means the check evaluates to `false` for a participant who hasn't touched the field yet. Net effect: any v1 showif of the form `is.na(X)` always returns false client-side, so the item never appears even though the server would have hidden it. v2 fix is two-part: (a) provide an `isNA(v)` helper that matches R's NA semantics (null/undefined/""/empty-array/NaN), (b) rewrite `(typeof(X) === 'undefined')` → `isNA(X)` in `compileShowif` before `new Function`. Same applies to any future transpile emission that claims NA-ness — route it through `isNA`, don't open-code a `typeof` check.

### 13.26 Deferred-fill: clear `$item->value`, not `$input_attributes['value']`

For r(...)-wrapped `value`, FormRenderer unwraps and records in `survey_r_calls`, then has to prevent v1's OpenCPU batch (`processDynamicValuesAndShowIfs`) from trying to evaluate the wrapped string — `r()` isn't a function in OpenCPU's R environment and one bad entry torches the whole batch for the page. The right lever is `$item->value = ''`: `needsDynamicValue()` trims to falsy, returns false, the item is skipped in the batch, `input_attributes['value']` is left untouched (it's only written from `setDynamicValue($value)`, which is called based on the batch results dict and keyed by item name). Setting `$input_attributes['value']` directly would also silence the batch for this item but then the server-rendered HTML would emit `value="r(...)"` — a literal attribute the client'd clobber anyway, but ugly. One-line clear of `$item->value`, let the rest of the pipeline be.

### 13.27 `classes_wrapper` is `protected` on every Item subclass

`public $parent_attributes = []` — anyone can poke a `data-*` onto it. `protected $classes_wrapper = ['form-group', 'form-row']` — FormRenderer can't push a new class from outside. Tried `$item->classes_wrapper[] = 'fmr-fill-pending'` and got a fatal `Cannot access protected property Text_Item::$classes_wrapper`. Two options: (a) add a public `addWrapperClass($c)` method on Item, (b) let the client tag the class on init. Went with (b) for MVP since the class is a loading indicator that only matters during the fetch window the client controls anyway — `fillItems.forEach(w => w.classList.add('fmr-fill-pending'))` before the fetch, `.classList.remove('fmr-fill-pending')` on resolve/error. If other server-side code needs to decorate wrappers, add the method then; don't special-case every caller.

### 13.28 Dangling run sessions break form-fill auth differently from form-r-call

The r-call smoke worked because rcallsmoke was a fresh run for my user. filesmoke had a prior session 75889 with `ended=<stamp>` + `user_id=NULL` + `current_unit_session_id` pointing at an ended unit session. When I reopened the run, the server rendered the Form preview (admin view reuses the ended session as a preview container) but `getCurrentUnitSession()` returned null for POST endpoints — the ended unit session is filtered out. So the GET works, the POST 409s. `DELETE survey_items_display WHERE session_id IN (…)` then the same for `survey_unit_sessions` / `survey_run_sessions` — that reset makes the next visit create a fresh active session tied to the user_code. Expect the same gotcha when iterating on any new endpoint that goes through `RunSession::getCurrentUnitSession()`; check session state before debugging the endpoint code.

### 13.29 Offline queue client_ts must be MySQL DATETIME format, not ISO-8601

Stored `client_ts: new Date().toISOString()` in the IDB entry, shipped it to `/form-sync`, and the endpoint 500'd with `Invalid datetime value: '2026-04-24T11:25:54.799Z' for column survey_form_submissions.client_ts`. Same gotcha as the item_shown timestamps — MariaDB's DATETIME wants `YYYY-MM-DD HH:MM:SS`, no `T`, no `Z`, no `.sss`. Use the existing `mysqlDatetime()` helper (`new Date().toISOString().slice(0, 19).replace('T', ' ')`) everywhere a PHP-side column is DATETIME. If you ever add a new server-bound timestamp, do this conversion at the emission site, not at the receiver — centralizing in one helper keeps semantics consistent.

### 13.30 `window.fetch` restore after monkey-patch survives an iframe ONLY if you keep the iframe

To simulate offline in a Playwright-driven smoke, I patched `window.fetch` to reject for a specific URL, ran the submit flow, then tried to restore by grabbing `iframe.contentWindow.fetch` from a freshly-created iframe and calling `iframe.remove()`. The next fetch via the restored reference failed silently (drain left the entry in place, no network error surfaced) — turns out detaching the iframe invalidates its globals. Keep the iframe attached (`style.display='none'`) while the restored fetch is in use; remove it after the test is fully done. Alternative: reload the whole page to reset the monkey-patch cleanly. Don't trust "I restored fetch" without a probe request to verify it actually works.
