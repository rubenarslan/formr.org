# form_v2: one owner for "where is the participant"

Plan for making the browser the sole place that decides which page of a
Form is shown, from state the server hands it, so that a reload, an
online submit and an offline submit all land on the same page. Companion
to `plan_form_v2.md` (the v2 design) and `form_v2_review_2026-09-23.md`
(the review that found the mismatches this plan removes).

## 1. What is wrong

Three rules decide the same thing and only agree by coincidence:

| decision | page load (PHP) | after submit (PHP) | in the browser |
| --- | --- | --- | --- |
| which items exist | `FormRenderer::getAllUnansweredItems` — `saved IS NULL`, not hidden | — | whatever was rendered |
| next / current page | first rendered page | `RunController::findNextRenderablePage` (own SQL) | `pages[currentIndex + 1]` when offline |
| progress | — | — | `(i+1) / pages.length` over rendered pages |
| Previous target | not rendered ⇒ unreachable | — | `pages[currentIndex - 1]` |
| first / last page | `renderPageNav($showPrev, $isLast)` | — | — |

Consequences (all verified in code): the progress bar restarts after a
reload; Previous cannot reach pages submitted before the reload; a
half-finished page loses its autosaved items on reload (autosave stamps
`survey_items_display.saved`, so the renderer drops them and they can no
longer be edited); a fully autosaved but never-submitted page vanishes
and comes back as an empty page holding only its showif-hidden items;
offline advance and online advance follow different rules.

Root cause: `saved` means both "autosaved" and "submitted with its page",
and the page only carries the items the server thinks are left, so the
browser cannot reason about anything it wasn't given.

## 2. Target

**Server owns data, browser owns navigation.**

The server:
- renders **every** item of the study (bar `mc_heading` and soft-deleted
  rows), in the session's stored order, grouped into pages by
  `survey_items_display.page` — the order and page split stay
  server-decided, stored once per session, as now;
- ships a **state block** with the page: per item `{value, saved, hidden}`
  plus computed values, `unit_session_id`, `allow_previous`, and the
  participant code (the pieces the page needs, in one place; replaces
  `script.fmr-saved-answers` and the hidden `session_id` input);
- validates and stores a submitted page, records which items the browser
  hid, and answers with the **stored state of the items it just wrote**
  (`items: {name: {value, saved, hidden}}`) plus any `computed` values, or
  a `redirect` when the form is complete. No `next_page`.
- runs `calculate` items when the browser asks to show a page
  (`/form-render-page` for page N computes every calculate on pages ≤ N
  that isn't computed yet) and at completion (the rest). This removes
  Step 0 from `FormRenderer::processItems` and `advancePastPage` entirely:
  calculations have one trigger per moment the participant reaches.
- keeps `survey_items_display.saved` as the per-item **"when this answer
  was stored"** timestamp; autosave and page submit both set it, per
  item, as now. Pagination never depends on answers — the page split is
  structural and stored; answers only fill inputs.
- **stores nothing about pages.** Which page to open on load is the
  browser's business: it remembers the page it is on in `localStorage`
  (key `fmr:page:<unit_session_id>`, written on every page change,
  cleared on completion and logout, honoured only if that page exists).
  With no memory — another browser, cleared storage — it opens the first
  page with a visible item that has no answer. That fallback can land on
  a submitted page holding a blank optional item; accepted, it needs a
  browser switch mid-form.
- **ends the form only when told to.** The browser marks the submit of
  the last page with a visible item `final: true`. The server applies
  it, checks that no `required` item is unanswered with stored
  `hidden <> 1`, ends the unit session and answers `redirect`; a failed
  check answers `errors` keyed by item name, and the browser routes to
  the first such page. A GET never completes a v2 form (an ended unit
  session doesn't render), so `studyCompleted()` / `getStudyProgress`
  become v1-only. For the stored `hidden` flags to be right, every
  submit posts the ids of **all** items the browser currently hides,
  not only the submitted page's — every page is in the document, so it
  knows them.
- **`allow_previous = off`** stays a browser-side rule, as today. The
  server can only approximate "already submitted" without a record; the
  approximation on offer (409 when the submitted page is below the
  highest page that has any `saved` item) is listed in §5 as optional.

The browser holds the state block in memory, updates it from every
server reply (or optimistically when it queues offline), and derives
everything else from it through **one pure function**:

```
pageState(state, pages, visibility, remembered) → {
  currentPage,        // `remembered` if it is a page with a visible item; else first page with a visible item lacking an answer; else the last visible page
  nextAfter(p),       // next page > p with a visible item; null = p is the last one (its submit is `final`)
  previousOf(p),      // nearest page < p with a visible item (only if allow_previous)
  isFirstShown(p), isLastShown(p),
  progress            // position of the current page among pages with a visible item
}
```

`visibility` is Alpine's live showif result, so a page whose items are all
hidden is stepped over without being shown, and the hidden ids of the
whole form ride along with every submit, so the server's final required
check agrees with the browser. Pages are never skipped because they are
answered: an answered page shows its answers.

Every navigation consumer calls this function: initial page, after a
submit (online or queued), Previous, popstate, the progress bar, the
solo-layout step controller, and the Next/Submit button label. The
server's only remaining "where are they" rule is `pageIsSubmitted` for
the `allow_previous` gate, and it is the same predicate as the browser's
`saved-or-hidden`, written once in SQL.

## 3. Work items

Ordered so each step leaves the branch working; the e2e lane
(`tests/e2e/v2-*.spec.js`, `pwa-queue-rejection.spec.js`,
`rank-item-v2.spec.js`) is the gate after every step.

### Server

1. **Docblocks.** Delete the "known limitation" paragraph in
   `formSaveAction` (autosave stamping `saved` per item is the intended
   meaning: when the answer was stored). No schema change anywhere in
   this plan.
2. **Render everything.** `FormRenderer::getAllUnansweredItems` →
   `getStudyItems`: drop the `saved IS NULL` / `hidden` filters, keep
   `deleted` and `mc_heading`. `processItems` keeps Step 1 (record
   `survey_r_calls`, blank dynamic values) and Step 2 (labels) for all
   items; delete Step 0 (calculations) and the `firstShownPage` scan.
   `render()` emits every page; Previous and Next/Submit buttons on every
   page (the browser shows/labels them); `renderPageNav` loses
   `$showPrev` / `$isLast`.
3. **State block.** `FormRenderer::stateBlock()` replaces `savedAnswers()`
   and the `session_id` input: `<script type="application/json"
   class="fmr-state">` with `{unit_session_id, code, allow_previous,
   items: {name: {id, page, required, value, hidden}}, computed: {...}}`.
   One query over `survey_items_display` for the session.
4. **One submit path.** `RunController::applyFormSubmission($unitSession,
   $page, $data, $itemViews)` does what `formPageSubmitAction` does after
   its gates: `createSurveyStudyRecord`, `updateSurveyStudyRecord`,
   `markClientHiddenItems` (now over the whole form: hidden = 1 for the
   posted ids, 0 for the rest), and when the payload says `final`, the
   required check and ending the unit session (the same call
   `RunSession` makes on `end_session` today); returns
   `{status, items, computed?, redirect?}` or `{status: 'errors', errors}`. `formPageSubmitAction` and
   `formSyncAction` both call it; `formSyncAction` wraps it in the uuid
   ledger as today. `readFormPayload()` replaces the copy-pasted
   JSON/multipart parsing in `formPageSubmitAction`, `formSyncAction` and
   `formSaveAction`. Delete `advancePastPage` and
   `findNextRenderablePage`.
5. **Calculations on show.** `formRenderPageAction` calls
   `RCallService::computeCalculations($page)` before resolving the page's
   values/labels and returns the results as `computed`. Completion
   (`Form::getUnitSessionOutput` when `studyCompleted()`) computes the
   remaining ones, as now.
6. **`allow_previous` gate (optional, §5).** 409 `{page_locked: true}`
   when the study disallows Previous and the submitted page is below the
   highest page holding a `saved` item. The browser reloads on it, like
   `stale_session`.
7. **Completion.** `Form::getUnitSessionOutput` no longer calls
   `studyCompleted()` for v2; the `final` submit ends the unit session
   (item 4). The required check lives in one method,
   `UnitSession::unansweredRequiredItems()` (`required`, `answer IS
   NULL`, `hidden <> 1`, not `calculate`), used by the final submit and
   nothing else.

### Browser (`webroot/assets/form/js`)

8. **`state.js`**: the state store (`load(stateBlock)`, `merge(items)`,
   `mergeComputed()`, `markQueued(page, names, hiddenIds)`) and the pure
   `pageState()` function from §2. No DOM access; unit-testable in node.
9. **One fill path.** Seeding saved values into inputs and applying an
   R-resolved value use the same `setInputValue(name, value)` (the
   review's `applyDeferredValue`, generalised). Saved values are applied
   at init from the state block; Alpine's scope is seeded from the store.
10. **Navigation consumers** in `main.js` and `solo/controller.js` call
    `pageState()`: initial page (ignore `?page=` beyond the current
    page), after submit (`nextAfter(p)`, then `resolveAndSubstitutePage`),
    offline enqueue (`markQueued` then `nextAfter(p)` — no more
    `currentIndex + 1`), Previous/popstate (`previousOf`), progress,
    button visibility and label. `pages` stays the DOM section list;
    `data-fmr-page` stays the page number.
11. **Hidden ids and the final flag.** The submit payload gains
    `hidden: [ids]` for every item the form currently hides (all pages)
    and `final: true` when `nextAfter(p)` is null. The remembered page
    (`localStorage`, wrapped in try/catch, keyed by unit session) is
    written by `showPage` and removed on `redirect` and by the logout
    wipe next to `wipeQueueForRun`.
12. **Queue drain** merges the server's `items` into the store on
    `applied`, and routes to the page named in a `needs_attention` entry
    via `pageState` (the entry's page is by definition not submitted).

### Tests and docs

13. Node unit tests for `pageState()`: fresh form; resumed with autosaved
    page; all-hidden page skipped; allow_previous on/off; complete.
14. e2e `v2-resume.spec.js`: (a) fill half a page, reload → same page,
    same items, values present, same progress; (b) submit two pages,
    reload → page 3, progress unchanged, Previous reaches page 2 when
    allowed; (c) offline submit then reconnect → same page as the online
    path; (d) reload in a fresh context (no localStorage) → first page
    with a visible unanswered item; (e) final submit with a required
    item unhidden on an earlier page → errors, routed to that page.
15. Update the specs that assert `next_page` or count rendered pages;
    the `bot_check`, `batch_update`, `survey_test_two_tabs` smokes.
16. Docs: `form_v2.md` §"Offline queue", the Previous note (line ~174),
    the `calculate`/`hidden` table (R runs "when the page is shown" for
    both); `CLAUDE.md` form_v2 notes on `FormRenderer processes all
    submit-delimited chunks`, "only R at render is calculate items", and
    the `value` column note; `plan_form_v2.md` checklist; this file.

## 4. Other places v2 decides the same thing twice

Found while surveying for this plan. The first group is absorbed by the
work items above; the second is separate work, listed so it isn't lost.

### Absorbed by this plan

- **Applying a submission** is spelled out inline in both
  `formPageSubmitAction` and `formSyncAction` (gates, create record,
  update record, mark hidden, advance). → item 4.
- **Reading the request**: JSON and multipart parsed separately in three
  actions; JSON scalars are normalised to `$_POST` strings only in
  autosave (`postScalar`), so submit/sync pass ints and bools straight
  into `validateInput`. → item 4 (`readFormPayload` normalises once).
- **Building the multipart request**: `main.js` `submitPage` and
  `offline/queue.js` `postQueueEntry` are near-identical FormData
  builders. → item 10/12: one `buildRequest(entry)` in `state.js` or a
  `transport.js`, used by both the live submit and the drain.
- **Which calculates are due**: `FormRenderer` Step 0 (stops at the first
  page holding *any* rendered item, submit buttons included) vs
  `advancePastPage` (loops, excludes submit) — a page with only a
  calculate + submit leaves the next page's calculates uncomputed. →
  item 5 (single trigger).
- **`computed` values after a queued submit**: the live submit seeds them
  into Alpine (`main.js:1113`), the drain ignores `body.computed`
  (`main.js:546`) — a showif reading a calculate stays hidden until
  reload. → item 12 (drain merges `items` + `computed` like the live
  path).
- **What "hidden" means**: Alpine sets `.hidden` + `data-fmr-hidden`;
  `collectPayload` reports only `data-fmr-hidden`; the required check
  (`validation/feedback.js:180`), the solo step filter
  (`solo/controller.js:57`) and the server's `hideByDefaultPendingClient`
  use only `.hidden`; `markClientHiddenItems` accepts only items with a
  showif. An item that is `.hidden` but never promoted to Alpine is
  skipped client-side yet never reported, so it keeps its page alive. →
  `pageState`'s `visibility` input becomes the one definition
  (item 8/10/11), and `markClientHiddenItems` accepts any item of the
  study (item 4).

### Separate work (not in this plan)

- **Three DOM readers for answers**: `collectPayload` (submit),
  `collectAllAnswers` (R overlay), Alpine `_syncInput`/`coerceField`
  (showif scope) each have their own empty-value / `[]` / checkbox-"0"
  rules. Once `state.js` exists it is the natural single reader (the
  submit payload and the R overlay are projections of the store), but
  that is a follow-up: the store in this plan holds server state, not
  live input.
- **Client answers overlaid on the R data** two ways in `RCallService`
  (values: `with()` over a copied last row, any name; labels: existing
  columns only, skipped when empty), so `survey$x` sees the browser's
  answers in a label but not in a value.
- **showif contract in three copies**: reserved names in `Item.php:86`,
  `FormV2CompatScanner.php:126`, `alpine.js:37`; `is.na` rewritten to
  `typeof` in PHP and back to `isNA()` by regex in Alpine.
- **Inherently dual, keep**: required-field hint (client) vs
  `validateInput` (server); autosave eligibility (client skips files,
  server skips files + bot_check); the solo layout's hand-kept list of
  non-step item types vs the server's `no_user_input_required`.

## 5. Decisions to confirm before starting

1. No page record on the server. The browser remembers its page in
   `localStorage`; the fallback is the first page with a visible item
   lacking an answer (change to "required item" if blank optionals send
   too many browser-switchers backwards). Rejected: a page-submissions
   table; stamping the submit item's display row (no such row on
   `max_items_per_page` pages or a trailing page); repurposing `saved`
   as a page marker.
1b. Optional: the approximate server gate for `allow_previous = off`
   (item 6). Without it the rule stays browser-only, as today.
2. Calculations move to `/form-render-page` + completion. Consequence:
   offline, a calculate on a not-yet-shown page waits for the next online
   page show or completion — already the documented behaviour for
   dynamic content.
3. `allow_previous = off` is enforced server-side (409 on a re-submit).
4. Page size: long forms render every item on every load. Acceptable;
   dynamic content still resolves per page.

## 6. Status

Built as described above, with these deviations from §3:

- Item 6 (the approximate server gate for `allow_previous = off`) was not
  built; the rule stays browser-side, as the study setting's help text says.
- The state block also carries `answerable` per item (`requiresUserInput`
  + `save_in_results_table`): the browser cannot tell from the DOM whether
  an item takes input (a rank item's inputs are all `type=hidden`).
- Ending the unit session is not enough: `FormSubmissionService::apply`
  also calls `RunSession::moveOn(false, false)` after `end()`, as
  `RunSession` does for an External's redirect — otherwise the redirect to
  the run URL starts a fresh session of the same Form.
- A submit reply carries the stored state of what it wrote (`items`) and,
  on a final submit, `missing` (name → message) when a required item is
  still unanswered; the page itself is stored either way.
- Automatic items (browser, ip, random, …) are computed on the first render
  only; `getStudyItems` selects `saved` so a re-render skips stored ones.
- `UnitSession::updateSurveyStudyRecord` resets `$this->errors` per call.
- The shared request gate is `RunController::formWriteContext` (also used
  by form-fill and the bot_check mint); `evaluateAllowlistedRCall` is gone.
- Client: `buildFormData` (queue.js) is the one multipart builder;
  `setInputValue` the one fill path; `absorbReply` merges every reply.

Tests: `tests/FormSubmissionServiceTest.php`, `tests/js/state.test.mjs`
(`npm run test:js`), `bin/test_form_v2_navigation_smoke.php`,
`tests/e2e/v2-resume.spec.js`; the existing v2 specs were updated for the
reply shape (`v2.advanced`, `v2.unitSessionId`).
