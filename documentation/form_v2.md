# form_v2 — admin guide

A practical guide to authoring surveys in the new "Form" engine. This is the user-facing companion to `plan_form_v2.md` (which is for engineers working on form_v2 itself).

---

## TL;DR

- **Form** is a new RunUnit type that renders surveys as a single-page AJAX form. It coexists with the legacy **Survey** unit; you opt in per-study via `rendering_mode='v2'`. Existing Surveys keep working unchanged.
- The biggest behavioural change: **`showif` runs in the browser by default**. To keep an expression on the server (R via OpenCPU), wrap it in `r(...)`.
- Same change for the `value` column: server-side R is opt-in via `r(...)`; literals and the `sticky` keyword work as before.
- Admin authoring stays the same — same spreadsheet format, same item types.

## When to use form_v2

Use it for any new study you're starting, unless you're using `Survey`-only features (very-deep nested showifs your sheet relies on, embedded Rmd-rendered HTML chunks in labels — see "Known limitations" below). Keep existing studies on `Survey` until you're ready to test against `form_v2_compat_scan` (below).

The visible benefits to participants:
- No full-page reload between pages — much snappier on poor connections.
- Reactive `showif` updates without round-trips.
- Offline queue: a failed page submit is held in IndexedDB and replayed when connectivity returns. Multi-page forms can be filled offline.
- Optional Background Sync (Chromium / Firefox / Android Chrome) drains the queue even after the tab is closed.

## Enabling form_v2 for a study

1. **Server flag**: `$settings['form_v2_enabled'] = true` in `config/settings.php`. This gates the admin UI; existing v2 studies continue rendering even if the flag flips back off.
2. **Add a Form unit**: in the run editor, click "Add Form" (the icon is a fresh sheet — `fa-wpforms`). This creates a Form RunUnit, links it to the chosen `SurveyStudy`, and stamps `rendering_mode='v2'` on the study.
3. **Per-study flags** (visible only on v2 studies, under `/admin/survey/<name>` → "Form_v2 settings"):
   - **Offline queue** (default *on*): when off, a network-failed submission errors out instead of being persisted in IndexedDB. Disable for studies whose answers must not touch local storage.
   - **Allow "Previous" button** (default *off*): renders a Previous button on non-first pages. Enable if your study tolerates back-navigation; leave off if your design relies on participants only ever moving forward.

## Authoring `showif` and `value`

### The two rules

1. **`showif` is JavaScript-only.** Evaluated client-side, reactive on every input change. `r(...)` in `showif` is invalid — the compat scanner flags it and FormRenderer surfaces a validation error. To get server-side R into a showif, see "Bridging R into a showif" below.
2. **`value` is R-only.** Any non-empty, non-numeric value is treated as R, allowlisted automatically, and evaluated server-side. No wrapping required.

### Default: client-side JS

`showif` is interpreted as a JavaScript expression evaluated in the participant's browser, with one reactive variable per item name and a small helper stdlib:

| Helper       | Meaning                                                              |
|--------------|-----------------------------------------------------------------------|
| `isNA(x)`    | true for `null`/`undefined`/`""`/empty array/`NaN`                   |
| `answered(x)`| `!isNA(x)`                                                            |
| `contains(haystack, needle)` | substring or array-includes                          |
| `containsWord(haystack, word)` | matches `\bword\b`                                  |
| `startsWith(s, p)`           | `String(s).startsWith(p)`                            |
| `endsWith(s, p)`             | `String(s).endsWith(p)`                              |
| `last(arr)`                  | last element, or value as-is for non-arrays          |

A small set of R-isms is rewritten to JS automatically by the existing transpiler in `Item.php`: comparison operators (`==`, `!=`, `>`, `<=`), boolean operators (`&&`, `||`), the formr R-package infix ops (`%contains%`, `%begins_with%`, `%ends_with%`, `%starts_with%`, `%contains_word%`), `is.na()`, `tail(x, 1)`, `current(x)`, `stringr::str_length()`. So a v1 showif like `mc_polytheism == 2` works unchanged.

Examples:

```
showif: mc_polytheism == 2
showif: trigger == "yes" && answered(plain)
showif: !contains(animals, "cat")
showif: last(daily_mood) > 5
```

If an expression references a variable that doesn't exist (a future-page item, a run-level variable like `ran_group`), the evaluator silently treats it as `undefined` and the item stays visible — no console errors, no flickering.

### Server-side R on the `value` column

Just write the R. No wrapping required. Numeric values stay as literal defaults; everything else is evaluated as R server-side. Examples:

```
value: paste(answered_items, collapse=", ")
value: complex_score(current(q1), current(q2))
value: ifelse(is.na(other), "", nchar(other))
value: sample(c(1,1,1,2,2,2,2), 7)
value: sticky                              # v1 keyword: tail(na.omit(survey$item), 1)
value: 5                                   # numeric literal — not evaluated
```

The R source never reaches the browser — every non-empty, non-numeric value is recorded in `survey_r_calls` (slot=`value`) at render time, and the client only ever sees the recorded call ID. The server overlays the participant's answers on `tail(survey_name, 1)` and evaluates inside an allowlist-only path. Results are cached for 5 minutes per `(call_id, sorted answers)` pair.

### Bridging R into a `showif`

`r(...)` in `showif` is no longer supported. Showifs are JS-only. To gate visibility on a server-computed R result, **add a hidden item with `value: r(...)` and reference its field name from the showif**:

```
name           type     showif                    value
my_score       hidden                             r(complex_score(current(q1)))
followup       text     my_score > 0.5
```

The hidden field gets resolved server-side; the resolved scalar is written into the input and Alpine reactivity makes it available to any showif that references it. Cleaner separation, more inspectable (admins can debug by reading the hidden field's resolved value), and you can reuse `my_score` from multiple showifs without paying the OpenCPU cost more than once per page.

### Page-scoped resolution

Server-side R only fires for items on the page the participant is currently viewing:

- **Initial page load**: only the first visible page's `r(...)` values + dynamic labels are resolved server-side. Items on later pages are placeholders.
- **Page transition**: when the participant submits page N, the server persists answers; the client then POSTs `/form-render-page` for page N+1, which batch-resolves all of N+1's dynamic content in one OpenCPU call against the latest answer state. The participant sees page N+1 with everything resolved.
- **Last page**: same model — resolved on the transition that brings the participant to it.

Why: dynamic content on page N+1 often depends on answers given on page N, so we can't resolve it until N is submitted. And we don't pre-resolve future pages because that work would be discarded on every keystroke.

Cost: one OpenCPU batch per page transition (cache-aware). Rate limit: 30 calls / 60 s per session.

### Embedded Rmd in labels

If a label contains R-Markdown syntax (inline R chunks like `` `r expr` ``), the **entire label** is treated as one allowlisted call. We don't extract partial Rmd chunks. The compat scanner's "label slot" entries are item-keyed; the server batches all of a page's dynamic labels into one knit call alongside the value evaluations.


## Compatibility scanner

Before flipping a busy v1 study to v2, run the compatibility scanner. It classifies expressions per the new rules:

**For `showif`:**
- **empty** — no expression
- **JS-OK** — the regex transpile produced valid JS
- **needs JS rewrite** — residual R-only tokens (`ifelse`, `c(`, `%in%`, `<-`, …); rewrite in JS or move the R into a hidden field's value
- **invalid: r() in showif** — flagged for migration to a hidden-field bridge

**For `value`:**
- **empty** — no expression
- **literal** — numeric default, kept as-is
- **R** — admin-authored R, automatically allowlisted and evaluated server-side

Two ways to run it:

- **Admin UI** (recommended): on the survey settings page (`/admin/survey/<name>`), click **"Run v2 compatibility scan"**. Reports inline with the source, the transpile output, the detected problems, and a suggested wrapping.
- **CLI**: `php bin/form_v2_compat_scan.php <study_id|study_name>`. Exits 0 if clean, 2 if anything is flagged — usable as a CI gate.

The scan is *advisory*. The runtime evaluator wraps every expression in `try/catch` and falls back to "show" on error, so a flagged item isn't necessarily broken; it just isn't *guaranteed* to behave the way the R source would. If you care about deterministic behaviour for a flagged item, wrap it in `r(...)`.

## PWA features (AddToHomeScreen / PushNotification)

These items work in v2 the same way as in v1, with one technical change: the wiring is vanilla JS in the form bundle (no jQuery). Functionally, an admin shouldn't notice a difference.

Setup:

1. Configure the run as installable (run settings → "Use as installable PWA"). This generates the manifest, the VAPID keypair, and the icon set.
2. Add the items to your survey sheet as usual:
   - `add_to_home_screen` — renders an "Add to Home Screen" button. Captures the browser's `beforeinstallprompt`, fires it on click. iOS Safari shows inline "tap Share → Add to Home Screen" guidance instead (iOS has no programmatic install API).
   - `push_notification` — renders an "Allow Notifications" button. Calls `Notification.requestPermission`, subscribes via the SW's pushManager, POSTs the subscription to the run.
3. The participant page automatically registers the service worker and enables Background Sync for the offline queue.

iOS specifics: PushNotification only works **inside the installed PWA** on iOS 16.4+. The participant must add the study to their home screen first, then reopen it from there. If they tap the push button before installing, v2 surfaces a clear error message explaining this.

## Offline queue (Phase 5)

When a page submit fails (no network, server 5xx, etc.), v2:

1. Persists the submission to IndexedDB (store `formrQueue`, keyed by a client-generated UUID).
2. Shows a yellow banner: "You're offline. This submission is queued and will be sent when you reconnect."
3. Lets the participant continue to the next page locally — the form holds its full state in memory.
4. On the `online` event, on next page load, or on a Background Sync wake-up (if the browser supports it), drains the queue against `/{run}/form-sync`. Server is idempotent — replaying the same UUID is a no-op.

File uploads ≤10 MB are queued the same way (the file Blob is stored alongside the JSON payload). Larger files surface a hard error rather than fill the IndexedDB quota.

The whole thing is on by default. Disable per-study via the **Offline queue** toggle in survey settings if your study can't tolerate plaintext answers persisting on the participant's device.

## Known limitations vs. v1

- **Embedded Rmd in labels**: still rendered server-side at page-load; not yet routed through r(...)+fill (cache softens the cost).
- **Audio / Video items**: render through the same multipart path as File items, but the `getUserMedia` capture UX hasn't been smoke-tested cross-browser. The form shows an admin notice ("Heads up: this form uses audio…"). Use a real device to verify before relying on it.
- **iOS Safari + Background Sync**: not supported in iOS Safari. The page's own `online` event still drains the queue when the participant reopens the tab; Background Sync just isn't an extra layer there.
- **`Previous` button mid-flow**: the button reveals already-rendered pages but doesn't refetch them from the server. If your study mutates per-page state on the server (e.g. via Pause units), back-navigation may show stale content. Leave the toggle off unless your design tolerates this.
- **Spreadsheet-side admin classes** like `mc_width70`, `rotate_label45`, `mc_vertical`, `rating_button_label_width50` — supported. Works the same as v1 (the same `custom_item_classes.css` ships in the v2 bundle).

## Migration checklist

Migrating a study from `Survey` to `Form`:

1. Run the compat scanner. Resolve flagged rows: wrap in `r(...)` or rewrite the expression in JS.
2. Decide on the per-study flags:
   - Offline queue: on (recommended unless sensitive data + no participant device persistence).
   - Allow Previous: usually off.
3. If the study uses PWA install or push, configure the run as installable and verify the manifest URL responds (`/{run}/manifest`).
4. Test as yourself via the run's "Use as yourself" link. Walk through every page; verify any flagged or `r(...)`-wrapped items behave as expected.
5. Flip the study's `rendering_mode` to `'v2'` (the "Add Form" button does this automatically when you wire a new Form unit; for an existing Survey unit's study, set the column directly: `UPDATE survey_studies SET rendering_mode='v2' WHERE id=…`).

## See also

- `plan_form_v2.md` — the engineering-side plan: phase status, remaining work, deferred designs.
- `CLAUDE.md` → "form_v2 development notes" — day-to-day dev gotchas (DOM specificity, BS3↔BS5 coexistence, PHP `$_POST` semantics, etc.).
- `bin/form_v2_compat_scan.php` — CLI source for the compatibility scanner (`application/Spreadsheet/FormV2CompatScanner.php`).
- `documentation/example_surveys/` — XLSform fixtures that exercise specific item types or behaviours.
