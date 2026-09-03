# form_v2 — admin guide

A practical guide to authoring surveys in the new "Form" engine. Operators upgrading an instance should read `form_v2_upgrade.md` (migrations, settings, the backfill step) first; engineers working on the engine itself want `agent_doc/plan_form_v2.md`.

---

## TL;DR

- **Form** is a new run-unit type that renders a survey as a single-page AJAX form: per-page submission without reloads, reactive `showif`s, autosave, an offline queue, and an optional one-item-per-screen layout. It coexists with the classic **Survey** unit; you opt in per study. Existing Surveys keep working unchanged.
- The one authoring rule that changes: **`showif` runs in the browser, as JavaScript.** `r(...)` in a showif is rejected. Server-side R lives in the `value` column, and a hidden item's `value` is how you feed an R result into a showif.
- Everything else stays the same — same spreadsheet format, same item types (plus a few new ones, see "What's new in v2"), same R data access in the rest of the run.

## When to use form_v2

Use it for any new study. Keep a running v1 study on Survey until you have run the compatibility scan on it and tested the upgraded study as yourself.

What participants notice:
- No full-page reload between pages — much snappier on poor connections.
- `showif` reacts to answers instantly, without a round-trip.
- Answers are autosaved while typing; a page submit that fails (no network, server error) is held in the browser and replayed once the participant is back online, so multi-page forms can be filled offline.
- Optionally, one item per screen ("solo" layout) and localized engine strings.

## Enabling form_v2 for a study

There are two ways in, both from the admin UI. Neither touches your items.

- **New study**: in the run editor, "Add Form (form_v2, beta)" creates a Form unit for the study you pick and switches that study to the v2 engine. The button only appears when the operator has set `$settings['form_v2_enabled'] = true`; studies already on v2 keep rendering on v2 regardless of that flag.
- **Existing study**: on the survey's settings page (`/admin/survey/<name>`) the "Rendering engine" box shows a compatibility-scan summary and an **Upgrade to form_v2** button. Upgrading converts every run unit that uses the study as a classic Survey into a Form unit; participants who are mid-form finish on the old engine, new sessions get v2. **Switch back to v1** is next to it and is safe at any time — a Form unit whose study is on v1 simply renders through the classic pipeline.

Once a study is on v2, the same page shows the **Form_v2 settings**:

| Setting | Default | What it does |
| --- | --- | --- |
| Offline queue | on | A failed page submission is stored in the participant's browser (IndexedDB) and replayed when connectivity returns. Turn off for studies whose answers must not persist on the device — submissions then fail hard when offline. |
| Show "Previous" button | off | Lets participants move back between the form's pages. Leave off unless your design tolerates back-navigation (see "Known limitations"). |
| Layout | Default | *Default* renders all items of an authored page together. *Solo* shows one item per screen with auto-advance on answer, Typeform-style — good on mobile. Page boundaries (submit items) still define what is submitted together; only the visual rhythm changes. |
| Participant language | `en` | A BCP 47 tag. Sets the page's `lang`/`dir` and localizes the engine's own strings (buttons, save indicator, offline banner, validation fallbacks). English and German ship built-in; `de-AT` falls back to `de`, anything else to English strings while keeping the `lang` attribute you set. Your item labels are always shown as authored. |

## Authoring `showif` and `value`

### The two rules

1. **`showif` is JavaScript.** It is evaluated in the participant's browser and re-evaluated on every input change. `r(...)` in a showif is invalid: the compatibility scanner flags it and the form reports a validation error to you instead of rendering the item. To gate visibility on an R result, see "Bridging R into a showif".
2. **`value` is R.** Anything that isn't empty, a number, or the `sticky` keyword is evaluated as R on the server. You can write the R bare or wrapped in `r(...)` — both mean the same thing; the wrapper is just the idiom used in the bridge example below.

### `showif`: client-side JS

Each item's name is available as a reactive variable, and a small helper library is in scope:

| Helper | Meaning |
| --- | --- |
| `isNA(x)` | true for `null` / `undefined` / `""` / empty array / `NaN` |
| `answered(x)` | `!isNA(x)` |
| `contains(haystack, needle)` | substring or array-includes |
| `containsWord(haystack, word)` | matches `\bword\b` |
| `startsWith(s, p)`, `endsWith(s, p)` | `String(s).startsWith(p)` / `.endsWith(p)` |
| `last(arr)` | last element, or the value as-is for non-arrays |

The R-isms a v1 showif typically uses are rewritten to JS for you: comparison operators, `&&`/`||`, the formr infix operators (`%contains%`, `%begins_with%`, `%starts_with%`, `%ends_with%`, `%contains_word%`), `is.na()`, `tail(x, 1)`, `current(x)`, `stringr::str_length()`. So `mc_polytheism == 2` works unchanged.

```
showif: mc_polytheism == 2
showif: trigger == "yes" && answered(plain)
showif: !contains(animals, "cat")
showif: last(daily_mood) > 5
```

**Names the browser doesn't know.** A showif may reference something that isn't an item on the form — a run-level variable like `ran_group`, or an item from an earlier survey. The server evaluates every showif once when it renders the form (with the participant's full run data, exactly as v1 does) and hides items whose showif is false or unknown. In the browser, an expression that hits an unknown name is not an error: the item simply keeps whatever visibility the server gave it. Only showifs whose inputs are all on the form are live.

### `value`: server-side R

Just write the R. Numbers stay literal defaults; `sticky` keeps its v1 meaning (`tail(na.omit(survey$item), 1)`); everything else is evaluated on the server with the participant's answers overlaid on `tail(survey_name, 1)`:

```
value: paste(answered_items, collapse=", ")
value: complex_score(current(q1), current(q2))
value: ifelse(is.na(other), "", nchar(other))
value: sample(c(1,1,1,2,2,2,2), 7)
value: sticky
value: 5
```

The R source never reaches the browser. At render time each dynamic value is recorded in `survey_r_calls` (slot `value`) and the client only ever sees the recorded call id, which it hands back when it asks the server to resolve the page. Results are cached for 5 minutes per `(call, answers)` pair.

### Bridging R into a `showif`

To gate visibility on something only R can compute, put the R in a **hidden item's `value`** and reference that item's name from the showif:

```
name       type    showif           value
my_score   hidden                   r(complex_score(current(q1)))
followup   text    my_score > 0.5
```

The hidden field is resolved on the server; the resolved scalar is written into the input, and the showif reacts to it like to any other answer. This is more inspectable than the old server-side showif (you can read the hidden field's resolved value while testing) and you can reuse `my_score` from several showifs without paying the OpenCPU cost again.

### Page-scoped resolution

Server-side R only runs for the page the participant is looking at:

- **Initial load**: the first page's dynamic values are resolved inline. Items on later pages get placeholders.
- **Page transition**: when page N is submitted, the server stores the answers, then the client asks for page N+1 (`/form-render-page`), which resolves all of N+1's dynamic values and labels in one OpenCPU batch against the latest answers.

Dynamic content on page N+1 usually depends on page N, so it can't be resolved earlier — and pre-resolving pages that may never be reached would waste OpenCPU time. Cost: one OpenCPU batch per page transition (cache-aware), rate-limited to 30 per minute per session (`form_v2_rate_limits` in `settings.php`).

### Dynamic labels (R-Markdown in labels)

A label containing inline R (`` `r expr` ``) is treated as **one** allowlisted call for the whole label — chunks are not extracted individually. Dynamic labels on every page, including the first, are resolved through the page request described above, so a dynamic label on page 1 paints a moment after the rest of the page. Keep heavy dynamic content off the first page if that flicker matters to you.

## Compatibility scanner

Before upgrading a v1 study, run the scanner. It classifies every expression:

**`showif`**
- **empty** — no expression
- **JS-OK** — transpiles to JavaScript cleanly
- **needs JS rewrite** — residual R-only tokens (`ifelse`, `c(`, `%in%`, `<-`, …): rewrite in JS, or move the R into a hidden item's `value` and reference that name
- **invalid: r() in showif** — same fix: hidden item + `value`

**`value`**
- **empty**, **literal** (a number, kept as-is), or **R** (evaluated on the server)

Two ways to run it:

- **Admin UI** (recommended): the survey settings page (`/admin/survey/<name>`) shows the flagged count next to the **Upgrade to form_v2** button; **Run v2 compatibility scan** opens the full report with source, transpiled output, and the suggested fix per item.
- **CLI**: `php bin/form_v2_compat_scan.php <study_id|study_name>`. Exits 0 if clean, 2 if anything is flagged — usable as a CI gate.

The scan is advisory and changes nothing. At runtime a showif that can't be evaluated leaves the item as the server rendered it, so a flagged item isn't necessarily broken — it just isn't guaranteed to behave like the R would have.

## PWA features (AddToHomeScreen / PushNotification)

The `add_to_home_screen` and `push_notification` items work in v2 as in v1; the wiring is vanilla JS inside the form bundle (no jQuery), and admins shouldn't notice a difference.

1. Configure the run as installable (run settings → "Use as installable PWA"). This generates the manifest, the VAPID keypair and the icon set; check that `/{run}/manifest` responds.
2. Add the items to your survey sheet as usual:
   - `add_to_home_screen` — captures the browser's `beforeinstallprompt` and fires it on click. Where there is no programmatic install API (iOS Safari), it shows inline "Share → Add to Home Screen" guidance instead.
   - `push_notification` — calls `Notification.requestPermission`, subscribes through the service worker's push manager and POSTs the subscription to the run.
3. The participant page registers the service worker itself.

iOS specifics: push only works **inside the installed PWA** on iOS 16.4+. The participant must add the study to their home screen first and reopen it from there; if they tap the push button before installing, v2 shows an error explaining this.

## Offline queue

When **Offline queue** is on (the default), a page submission that fails — no network, or a 5xx from the server — is stored in the browser's IndexedDB and the participant advances locally. The banner says "queued". The queue drains when the browser fires `online` and on the next load of any page of the study; each entry is replayed to `/{run}/form-sync`, which applies the answers through the same path as a normal page submit and ignores entries it has already seen (each entry carries a UUID; the server keeps a ledger in `survey_form_submissions`). A drain that hits a validation error stops, keeps the entry queued and shows "A queued submission was rejected. Please review the page."; entries the server says no longer apply (the session ended, the participant moved on to another Form) are dropped.

- **Nothing drains while the tab is closed.** The page registers a Background Sync tag, but the service worker does not yet act on it, and iOS Safari has no Background Sync at all. Tell participants to reopen the study once they are back online.
- **File uploads are queued too**, up to `form_v2_offline_blob_max_mb` per file (`settings.php`, default 10 MB). A larger file is refused with a "too large to queue offline" message instead of risking the browser's storage quota.
- **Answers sit in plaintext on the device until they drain.** If that is unacceptable for your study, turn the queue off — submissions then fail visibly when offline and nothing is stored.
- The queue for a run is wiped when the participant clicks a logout link on the form page. That is the only wipe: a participant who reaches `/{run}/logout` from elsewhere, or uninstalls the PWA, keeps whatever is queued.

## Known limitations

- **Background Sync**: see above — page-side drain only, and none on iOS.
- **Previous button**: going back reveals the already-rendered page; it is not refetched from the server. Dynamic values and labels on it are whatever they resolved to the first time. Leave the toggle off unless your design tolerates that.
- **Range slider**: v1's floating value tooltip came from the `webshim` plugin, which v2 doesn't ship. `range` / `range_ticks` render as a native slider with side labels; the participant sees their position on the thumb only. `visual_analog_scale` is the v2-native answer for "no default" rating items.
- **Solo layout**: auto-advance fires on the first commit of single-choice radios, single selects and range sliders. Checkboxes, multi-selects and free-text items never auto-advance — the participant uses the Next button.
- **Spreadsheet-side admin classes** (`mc_width70`, `rotate_label45`, `mc_vertical`, `rating_button_label_width50`, …) work as in v1; the same `custom_item_classes.css` ships in the v2 bundle.

## What's new in v2

Beyond the rendering model:

- **New item types**: `rank` (drag-to-order list) and `bot_check` (a proof-of-work human check, ALTCHA-style, no third party). Both are v2-only. `visual_analog_scale` renders on both engines. See `templates/public/documentation/item_types.php` for the item reference.
- **`bot_check` fails closed.** If the server has no crypto key it refuses every token and the item shows "Human verification is temporarily unavailable" instead of waving everyone through; the challenge endpoint requires an active participant session and is rate-limited (`form_v2_rate_limits.bot_challenge_per_min`, default 20).
- **Page-expired modal**: when a unit session's expiry passes mid-form, participants see the same "page expired" prompt as in v1.
- **Localized engine strings** (`language` setting, see the settings table).
- **Solo layout** (`layout` setting).

## Migration checklist for an existing study

1. Run the compatibility scan (survey settings page, or `bin/form_v2_compat_scan.php`).
2. For every flagged item: rewrite the showif in JS, or move the R into a hidden item's `value` and reference that item from the showif. Re-upload the sheet.
3. Decide the per-study settings — offline queue, Previous button, layout, language.
4. If the study uses install or push, configure the run as installable and verify `/{run}/manifest` responds.
5. Test as yourself via the run's "Use as yourself" link. Walk every page; check flagged items and dynamic values behave as expected.
6. Click **Upgrade to form_v2** on the survey settings page. Participants mid-form finish on the old engine; new sessions get v2. **Switch back to v1** undoes it at any time.

## See also

- `form_v2_upgrade.md` — instance upgrade: migrations 078–092, settings, the backfill step, rollback.
- `agent_doc/form_v2_layout_modes.md` — the solo layout design.
- `agent_doc/plan_form_v2.md` — engine design and remaining work (for engineers).
- `CLAUDE.md` § "form_v2 development notes" — code-level gotchas.
- `bin/form_v2_compat_scan.php` — CLI scanner.
- `documentation/example_surveys/form_v2_basic.xlsx` and `documentation/run_components/Form_v2_Basic.json` — a minimal v2 study (JS showif, `rank`, `visual_analog_scale`, `bot_check`) and an importable run using it. The run bundle embeds the study, so importing it in the run editor creates `form_v2_basic` and links the Form unit to it (the sheet is the same study, for uploading by hand). Your own runs export the same way: "Export" with *Include survey items* ticked ships each Form's study, and importing the bundle recreates it — under the current user, reusing a same-named study if one exists.
