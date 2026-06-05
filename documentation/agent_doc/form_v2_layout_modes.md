# form_v2 layout modes — design notes

Agent/architecture companion to the user-facing `documentation/form_v2.md`.
Covers the **two presentation layouts** (`default`/scrolling and `solo`/paged),
the December-2025/June-2026 alignment to the *layout-modes spec*, and the
cross-cutting changes that came with it (incremental autosave, a11y grouping,
per-response paradata). Read `documentation/form_v2.md` first for the
admin-facing "what/when"; this doc is the "how/why" for maintainers.

Terminology bridge: the spec calls them **paged** and **scrolling**; formr calls
them **`solo`** and **`default`** (`survey_studies.layout` ENUM, patch 066). The
renderer emits `data-layout="solo|default"` on the `<form>`; everything branches
off that attribute.

## Where the code lives

| Concern | File |
| --- | --- |
| Solo step controller | `webroot/assets/form/js/solo/controller.js` |
| Solo skin | `webroot/assets/form/css/_solo.scss` |
| Default skin + BS3→BS5 compat | `webroot/assets/form/css/form.scss` |
| Client runtime (submit, autosave, offline, showif) | `webroot/assets/form/js/main.js` |
| Inline validation feedback | `webroot/assets/form/js/validation/feedback.js` |
| Server renderer (single document, page-scoped OpenCPU) | `application/Spreadsheet/FormRenderer.php` |
| Item rendering (group a11y, inputmode) | `application/Model/Item/*.php` |
| Form/autosave/sync endpoints | `application/Controller/RunController.php` |
| Per-response write path | `application/Model/UnitSession.php` |

`SurveyStudy.layout` (066) and `SurveyStudy.option_keys` (067) gate the solo
behaviours; `survey_unit_sessions.layout` (068) records the mode per response.

## Solo (paged) layout — the step controller

`js/solo/controller.js` **replaced** the original patch-066 CSS scroll-snap skin.
Scroll-snap broke on multi-page forms (nested 100vh scroll containers), had no
explicit forward/back affordance, auto-advanced on the first `change` of a
multi-checkbox item, and let the sticky progress bar overlap content.

Model: **one `.form-group` visible at a time** as `.fmr-solo-current`; all items
stay mounted (so Alpine `x-showif`, validation, r-calls, the offline queue and
`allow_previous` keep their normal semantics). The controller only manages which
single step is visible and the nav/progress chrome. Authored page boundaries
still drive real submission — clicking OK past the last step of a page calls the
host's `submitPage()`, and `onPageShown()` re-seats on the new page's first step.

Key behaviours:
- **Auto-advance on commit** for single-choice radio cards, single `<select>`
  (incl. tom-select) and range sliders (`autoAdvances()`); checkboxes,
  multi-selects, free text and textareas need an explicit OK. Multi-select is
  **never** auto-advanced (spec do-not).
- **OK is hidden** only on single-radio cards (`isRadioChoice()`), where picking
  advances — Typeform-style; kept visible on select/range/terminal/answered-via-Back.
- **Fixed Back/OK nav** (`.fmr-solo-nav`). DOM order is OK-first so Tab from an
  input lands on OK; CSS `order:-1` keeps Back on the visual left.
- **Fit-lock** (`html.fmr-solo-locked`): a step is `min-height:100vh`, so when its
  content fits, the entrance animation's transient `translateY(26px)` would let
  the wheel bounce. The controller measures the seated step's `offsetHeight`
  (transform-immune) and locks `overflow:hidden` when it fits; a `ResizeObserver`
  re-checks after late-loading images/plots so genuinely tall steps stay
  scrollable. Released while the mobile keyboard shrinks the viewport.
- **Monotonic progress**: `updateProgress()` clamps the bar to never regress.
  showif reveal/hide changes the step total, which would otherwise jump the bar
  backward (the spec's "don't misrepresent length"). The "N of M" label still
  reflects current known totals. Default mode is page-based, already monotonic.
- **Letter badges** (A·B·C, also keyboard shortcuts) on plain card choices,
  per-study via `option_keys` (067) → `data-option-keys`.

### Solo bug-fix pass (the "single-page-widgets" report)

Seven participant-reported solo bugs, all fixed and e2e-covered:

1. Phantom over-scroll on fitting steps → fit-lock + ResizeObserver (above).
2. Tab landed on Back → nav DOM order OK-first, `order:-1` on Back.
3. `range_ticks`/`range`/VAS slider shoved off-screen → neutralise v1's `leftNNN`
   label-width modifiers in solo; slider flexes within the column (`_solo.scss`).
4. `select_one`/range didn't auto-advance → added to `autoAdvances()`.
5. Run footer rendered as a stray "page" under each step → pinned above the nav
   (`body:has([data-layout=solo]) .container-fluid > p { position:fixed }`).
6. Invalid (out-of-range) values blocked silently on the 2nd attempt → a stale
   `fmr-has-client-error` dedup flag leaked across validation passes; see
   *Validation* below.
7. `block` item showed a generic "check this box" complaint → its own red
   message is the explanation; also suppressed the misfiring "Choose all that
   apply" hint and contained the full-bleed `alert-danger` into a centred card.

### iOS keyboard hand-off on OK

iOS only raises the soft keyboard for a `focus()` that runs **inside the
user-gesture task**. The controller used to focus inside two nested
`setTimeout`s (the 150ms slide + 60ms), so tapping OK never raised the keyboard
for the next field — the participant had to tap the field too. Now a tap/Enter
that advances to a **text-field step** seats it *synchronously* and focuses the
field in the gesture (`onContinue(userGesture) → seat(el, dir, sync=true) →
focusFirst(el, sync)`), so the keyboard rises like tabbing to the next field.

Two consequences that bit us:
- The sync seat **skips the entrance `translateY(26px→0)` animation** — otherwise
  the field slid up *as the keyboard rose*, which read as "the input jumps when I
  type into it" (confirmed on a real iPhone: 26px → fixed to 0px). The slide
  stays for every normal (non-keyboard) advance.
- **BrowserStack Automate can't drive the soft keyboard** (the visual viewport
  never shrinks, typed text never enters), so keyboard-rise / during-typing
  behavior must be verified on a physical device; automation can only check the
  keyboard-independent parts (the entrance-jump regression, focus-in-gesture,
  geometry). See the BS iOS quirks memory.

## Default (scrolling) layout

- **Top-aligned labels, single column** (`form.scss .form-group.form-row` →
  `flex-direction:column`). Replaced the old desktop label-left, right-aligned
  260px grid, which wrapped long sentence stems badly. Label-input gap is tighter
  than the inter-item gap (proximity).
- **Sticky Next/Submit** (`.fmr-page-nav { position:sticky; bottom:0 }`) so it's
  reachable without scrolling a long page. (Mobile ≤768px keeps the pre-existing
  `position:fixed` bottom rule.)
- Matrix items keep their v1 geometry here (this is where they belong); solo
  splits a matrix into disconnected per-screen rows — see *v1 vs solo* below.

## Shared (both layouts)

### a11y group semantics
Radio/checkbox sets announce their stem to screen readers via **ARIA grouping,
not literal `<fieldset>`/`<legend>`** — fieldset would fight the BS3-derived
flex/grid CSS and the button-group/showif JS that target the current
`.control-label`/`.controls` structure. `Item.php` carries a `$group_role`
property; `render_inner()` puts `role` + `aria-labelledby="item{id}-label"` on the
`.controls` wrapper, and the stem `.control-label` carries that id.
- `Mc` → `radiogroup` (inherited by `McButton`, `RatingButton`).
- `McMultiple` → `group` (inherited by `McMultipleButton`).
- `Check`/`CheckButton` (single checkbox — stem `for=` already associates it) and
  `McHeading` (header row, radios disabled — announcing an empty radiogroup would
  mislead) opt **out** (`$group_role = null`).
Plain inputs get no group role (verified: `item-text .controls` has none).

### inputmode
`type` already drives the right mobile keyboard for email/number/tel/date. The
one gap: `Year` used `type="year"` (not a real HTML type → text fallback → alpha
keyboard), so it now sets `inputmode="numeric"`.

### Validation
- **On submit**: `validatePageAndShowFeedback()` (feedback.js) renders inline
  `.fmr-invalid-feedback` next to each offender + focuses the first.
- **On blur** (`installBlurValidation`): surfaces **format** errors (bad email,
  out-of-range) when a *filled* field loses focus; deliberately does NOT nag about
  empty-required on blur (kept for submit) — per-field nagging is a breakoff driver.
- **Stale-flag fix (solo bug #6)**: `fmr-has-client-error` is a within-pass dedup
  flag. In solo, `validate(current)` passes a single `.form-group` as `pageEl`, so
  the flag landed on `pageEl` itself and survived `querySelectorAll()` (descendants
  only) → the next attempt `return`ed early before rendering feedback (invalid
  field blocked silently). Fix: reset `is-invalid` + `fmr-has-client-error` on
  `pageEl` *and* descendants at the top of each pass, and clear the flag on input.
- **Double-submit guard**: `submitPage` is wrapped in a single-flight guard
  (`pageSubmitInFlight`) so a rapid double-tap / Enter-repeat can't fire two POSTs
  (covers default Next + the Enter-submit path; solo also guards in the controller).

## Incremental autosave

Goal (spec): persist answers as they're given, not only on explicit page submit,
so a mid-page breakoff still yields partial data. **It is a safety net — the
durable/authoritative path is still `/form-page-submit` (+ the offline queue).**

Client (`main.js`):
- Endpoint URL from `data-save-url` (`/{run}/form-save`, emitted by FormRenderer).
- Triggered by `change` (commit) events on the form root — a natural proxy for
  "on advance" in solo and "on blur" in default. Ignores `_item_views` tracking
  inputs and `file` inputs (files ride the explicit multipart submit).
- **Throttle, not debounce** (`SAVE_MIN_INTERVAL = 20000`, the spec's ≤1 req/20s):
  leading edge fires the first change in a window immediately (breakoff
  protection for the first answer), a single trailing timer lumps the rest of the
  window into one request. A debounce was rejected: it never fires during steady
  answering, so a participant filling continuously then bailing would have saved
  nothing. `collectPayload` sends the whole page, so the trailing request already
  lumps every answered item in the window.
- Best-effort + silent: fetch uses `keepalive:true`; errors are swallowed.
- **Breakoff flush**: `pagehide` + `visibilitychange:hidden` → `beaconFlush()`
  via `navigator.sendBeacon` (fetch-keepalive fallback). Chosen over `unload`/
  `beforeunload` because those are unreliable for sending (fetch killed) and on
  iOS/Android often don't fire, and because they disqualify the back/forward
  cache (bfcache).
- **bfcache-scoped `beforeunload` guard**: a "leave with unsaved changes?" prompt
  is armed (`updateUnloadGuard()`) ONLY while a change is pending (`savePending`)
  and removed the instant a flush starts. A `beforeunload` listener disqualifies
  bfcache on iOS Safari / Android Chrome, so scoping it to the throttle window
  keeps Back instant whenever nothing's at risk. The prompt is courtesy only —
  the beacon still persists the data if they leave.

Server (`RunController::formSaveAction`, `/{run}/form-save`, JSON only):
- **Drops empty values** before saving — `collectPayload` also emits each mc's
  empty hidden placeholder (`value=""`); writing `""` to a typed results column
  (e.g. a TINYINT mc) errors, and it would mark an unanswered *required* item as
  saved (bypass). So autosave persists only what's actually answered.
- Calls `UnitSession::updateSurveyStudyRecord($posted, validate:false, quiet:true)`:
  no required-gating, **no page advance/redirect**; `quiet` suppresses the
  user-error + study-admin email on a lost race (a missed best-effort save is
  harmless). Writes `survey_items_display` + the per-study results table.
- **Idempotent** (upserts by session+item / study+session), so a later validated
  page submit cleanly overwrites. Marks items `saved`, so resume continues from
  unsaved items (consistent with v2's page-level resume, just finer-grained;
  autosaved items aren't re-rendered for editing).

## FormRenderer: choices on later pages

`FormRenderer` renders all pages into one document and keeps OpenCPU
**page-scoped** (only the first visible page resolves inline; later pages defer to
`/form-render-page`). The bug: the parent's `processDynamicLabelsAndChoices` does
both OpenCPU label parsing *and* the static `setChoices()`, and it only ran on the
first page — so every `mc`/`mc_heading`/`select` on page 2+ rendered an **empty
`.mc-table`** (no radios), making a required later-page question an unanswerable
dead screen (and in solo it silently auto-advanced). Fix: `attachStaticChoices()`
attaches choices to **all** items straight from the DB (no OpenCPU) before the
first-page OpenCPU pass; dynamic *choice labels* on later pages degrade to raw
text rather than vanishing. Verified server-side (raw curl): radios 43→66.

## Measurement equivalence (researcher guardrails)

The spec's most formr-specific section. Co-locating items on one screen
(scrolling/default) raises their inter-item correlations vs. one-per-screen
(paged/solo) — a real, replicated effect, so the same scale can show different
reliability/factor structure across modes.

- **Layout recorded per response** — `survey_unit_sessions.layout` (patch 068),
  stamped from `SurveyStudy.layout` at first render in
  `UnitSession::createSurveyStudyRecord` (captured at start, so a later flip of
  the study's layout doesn't rewrite history). This is paradata, queryable
  alongside each response.
- **Item order is byte-identical across modes** — order comes from
  `survey_items.order`; layout never reorders.
- Still **NOT** done: a UI warning to the researcher about the correlation
  effect; per-*item* (vs per-response) layout stamping (the spec's "ideally").

## Spec alignment summary

Met: single-item paged screens; top-aligned labels (both modes now); no
placeholder-as-label; no Reset-by-submit; never auto-advance multi-select;
keyboard + focus-to-first-error; group a11y; on-blur format validation; sticky
Next; idempotent + per-item-ish persistence; offline queue; identical item order;
layout recorded per response.

Partial / by-design divergences (documented, not bugs):
- **Paged mode hard-gates each required item on advance** — the spec argues
  against per-screen hard gating. formr keeps it (author-controlled via
  `optional`); a "soft-required" solo mode was scoped out.
- **Page-boundary advance is not optimistic** — `submitPage` awaits the POST
  (with a loading cue); within-page solo steps are instant, and autosave covers
  mid-page persistence, but the boundary still blocks on the round-trip.
- **Progress is monotonic, not "section X of Y by topic"** — the latter needs
  section metadata formr doesn't have.
- **Resume is page-structured** (autosave makes it finer-grained, but autosaved
  items aren't re-rendered for editing).
- Default-mode short `mc` lists still render radios inline (pre-existing).

## v1 vs v2/solo — where solo is worse

For reviewers weighing solo for a given study:
- **Matrix items** (`mc_heading` + rows): v1 = one aligned table (header +
  labeled rows + aligned cells visible together). Solo = header is its own screen
  and each row is a separate screen of bare radios (scale labels are `hide_label`
  on the rows, stranded on the header screen). Worst case for solo.
- **`blank`**: an empty screen in solo (just chrome); harmless filler in v1.
- **Cross-item context**: related items the participant should see together (e.g.
  a "spend $100" pair guarded by a `block` on their sum) are split across screens
  in solo; v1 shows them together.
- **Density/speed**: power users scan-and-fill a v1 page faster than clicking
  through N solo screens.
Matrix / blank / combined-block handling were **deliberately left as-is** (the
one-item-per-screen model genuinely fights that content).

Where solo is better: focus, big tap targets + letter-badge cards, auto-advance,
cleaner mobile ergonomics.

## Test coverage

| Spec | Covers |
| --- | --- |
| `tests/e2e/form-v2-spec.spec.js` | group a11y, top labels, later-page choices, on-blur validation, double-submit guard, autosave (DB-verified, rate-limit-robust), layout paradata, bfcache-scoped beforeunload guard |
| `tests/e2e/solo-layout.spec.js` | step controller (one step, viewport-fill + nav, fit-lock, single-choice auto-advance), solo paradata, monotonic progress, admin layout round-trip, **geometry at 375×667** (no control under fixed chrome; every control reachable) |
| `tests/e2e/solo-real-device.spec.js` | **BrowserStack** real iPhone Safari: solo renders, every catalogued item type renders a control in solo, tap-to-advance, **per-step geometry + screenshots**. `npm run test:bs -- solo-real-device` |
| `tests/e2e/v2-polish.spec.js` | save pill, completion overlay, sticky progress + nav, focus management |

Notes: specs run against the dev instance, `workers:1`; `solo-layout` and
`solo-real-device` toggle `survey_studies.layout` and restore it in `afterAll`.
The autosave test polls the DB (the 20s throttle defers the trailing save, so
racing the network response is flaky). BrowserStack iOS Automate works
(`browserstack-node-sdk`, Playwright pinned 1.57); Android is parked.

### Geometry assertions (why DOM checks weren't enough)

A real iPhone bug shipped despite green tests: the first item's text box was
painted **under** the fixed Back/OK footer, and on short screens controls were
trapped by the scroll-lock and unreachable. Every existing check passed because
they asserted the **DOM** (element present, `offsetHeight <= innerHeight`,
`activeElement === input`) — none asserted **pixels**. The lesson: layout bugs
need pixel-geometry assertions measured against `visualViewport` (the
keyboard/toolbar-aware viewport), not `window.innerHeight` (which on iOS counts
area the browser chrome covers).

`tests/e2e/helpers/geometry.js` provides two in-page probes (both no-arg, so they
survive the BrowserStack arg-mangling bridge — see `helpers/test.js`):

- **`overlapProbeFn`** — does any interactive control on the seated step
  intersect a fixed/sticky chrome element (progress bar, `.fmr-solo-nav`, the
  pinned run footer `<p>`)? Catches "text box overlaps footer".
- **`reachProbeFn`** — after `scrollIntoView`, does every control land inside the
  safe band (between top- and bottom-anchored chrome, within `visualViewport`)?
  Catches "control unreachable through scroll" (the lock-trap).

Wrappers `assertNoChromeOverlap` / `assertControlsReachable` / `assertSoloStepGeometry`
fail with the offending selector + its rect + the chrome it hit.
`tests/e2e/helpers/solo.js` adds `walkSolo(page, {onStep})` to run a gate at each
seated step. The local spec runs this at **375×667** (the short-phone size that
was never covered — only 375×812 was). The BrowserStack spec runs the same probes
on the real device **and writes a screenshot per step** to
`tests/e2e/artifacts/solo-ios/` (gitignored) + attaches them to the report, so a
geometry regression leaves an artifact to open rather than just a red assertion.
The harness is self-validated: a negative control (shoving a control into the nav
band) confirms `overlapProbeFn` flags it, so the green result isn't trivial.

## Key commits (feature/form_v2)

`fa664c27` solo bug pass · `5948b2e8` later-page choices · `bfd6e5b8` top labels +
group a11y + blur + sticky Next · `18712172` incremental autosave · `42891ee9`
e2e + step-controller specs · `de6a04f4` layout-per-response + monotonic progress
· `49bbf6f8` BrowserStack solo spec · `76811f52` bfcache-scoped beforeunload guard.
