# form_v2 — admin guide

A practical guide to authoring surveys in the new "Form" engine. Operators upgrading an instance should read `form_v2_upgrade.md` (migrations, settings, the backfill step) first; engineers working on the engine itself want `agent_doc/plan_form_v2.md`.

---

## TL;DR

- **Form** is a new run-unit type that renders a survey as a single-page AJAX form: per-page submission without reloads, reactive `showif`s, autosave, an offline queue, and an optional one-item-per-screen layout. It coexists with the classic **Survey** unit; you opt in per study. Existing Surveys keep working unchanged.
- The one authoring rule that changes: **`showif` runs in the browser, as JavaScript.** A v1 study's showifs are R, so upgrading means rewriting each one in JS — or, when the condition really needs R, computing it in a hidden item's `value` and referencing that item by name. Server-side R still lives in the `value` column.
- Everything else stays the same — same spreadsheet format, same item types (plus a few new ones, see "What's new in v2") except `calculate`, which v2 does not run (use a `hidden` item, or compute the value where it is used — see below), same R data access in the rest of the run.

## When to use form_v2

Use it for any new study. Keep a running v1 study on Survey until you have run the compatibility scan on it and tested the upgraded study as yourself.

What participants notice:
- No full-page reload between pages — much snappier on poor connections.
- `showif` reacts to answers instantly, without a round-trip.
- Answers are autosaved while typing; a page submit that fails (no network, server error) is held in the browser and replayed once the participant is back online, so multi-page forms can be filled offline.
- Optionally, one item per screen ("solo" layout) and localized engine strings.

## Trying it out

`documentation/example_surveys/all_widgets_v2.json` has every item type v2 supports (the PWA items are in `pwa_items_default.xlsx`), grouped into five pages, with JavaScript showifs, a random group, hidden items computed in R, dynamic labels and a `block` guard. Upload it under "Add a new survey" (it declares `"rendering_mode": "v2"` in its settings, so it arrives on v2) and click **Test Survey**.

## Enabling form_v2 for a study

There are three ways in, all from the admin UI. None touches your items. A study on v2 renders with the Form engine only inside a **Form** unit: placed in a classic Survey unit it still renders through the v1 pipeline, so add it to a run with **Add Form**.

- **Upload a new survey on v2**: on "Add a new survey", pick **Engine: form_v2** (offered once the operator has set `form_v2_enabled`), or let the file say so — `"rendering_mode": "v2"` in a JSON file's `settings`, or a row `rendering_mode` | `v2` on a spreadsheet's `settings` sheet (columns `item` and `value`; the xlsx export writes this sheet, so an exported v2 survey comes back on v2). Items v2 cannot run (`calculate`) are refused at upload. A choice on the form wins over the file.

- **New study**: in the run editor, "Add Form (form_v2, beta)" creates a Form unit for the study you pick and switches that study to the v2 engine. The button only appears when the operator has set `$settings['form_v2_enabled'] = true`; studies already on v2 keep rendering on v2 regardless of that flag.
- **Existing study**: on the survey's settings page (`/admin/survey/<name>`) the "Rendering engine" box shows a compatibility-scan summary and an **Upgrade to form_v2** button. The box is shown where the operator enabled form_v2 (`form_v2_enabled`). The upgrade is refused while any run uses the study as a classic Survey — switching it would change the engine under that run's participants; to try v2 with a study that is in use, copy it (download it and upload it as a new survey) and use the copy in a Form unit. Linking a study in a Form unit is refused on the same condition. The upgrade is also refused while the study has items v2 cannot run (`calculate`), and while the scan flags showifs — each of those would keep its item hidden for good; fix them, or, if the scan is wrong about one, use **Upgrade anyway**. **Switch back to v1** is next to it and is safe at any time — a Form unit whose study is on v1 simply renders through the classic pipeline.

Every survey admin page shows the engine next to the study's name (**form_v2** or **v1**), as does the survey list. **Test Survey** previews the study on its engine: a v2 study is shown exactly as a Form unit shows it to participants — one document, JavaScript showifs, per-page submits, autosave — with a note at the top saying which engine the preview uses. The preview's answers go to a test session, as for v1.

Once a study is on v2, the same page shows the **Form_v2 settings**:

| Setting | Default | What it does |
| --- | --- | --- |
| Enable offline queue | on | A failed page submission is stored in the participant's browser (IndexedDB) and replayed when connectivity returns. Turn off for studies whose answers must not persist on the device — submissions then fail hard when offline. |
| Show "Previous" button | off | Lets participants move back between the form's pages. Leave off unless your design tolerates back-navigation (see "Known limitations"). |
| Layout | Default | *Default* renders all items of an authored page together. *Solo* shows one item per screen with auto-advance on answer, Typeform-style — good on mobile. Page boundaries (submit items) still define what is submitted together; only the visual rhythm changes. |
| Participant language | `en` | A BCP 47 tag. Sets the page's `lang`/`dir` and localizes the engine's own strings (buttons, save indicator, offline banner, validation fallbacks). English and German ship built-in; `de-AT` falls back to `de`, anything else to English strings while keeping the `lang` attribute you set. Your item labels are always shown as authored. |
| Button labels | empty | Your own wording for the Next and Submit buttons (plain text, up to 100 characters); empty keeps the built-in label in the participant language. The Next label also labels a `submit` item that has no label of its own. |

## Authoring `showif` and `value`

### The two rules

1. **`showif` is JavaScript.** It is evaluated in the participant's browser and re-evaluated on every input change. In v1 the same column held R, so every non-empty showif of a study you are upgrading has to be rewritten — most transpile as-is (`q1 == 1`), the rest need a JS rewrite or the hidden-item bridge below. The compatibility scanner tells you which is which before you switch.
2. **`value` is R.** Anything that isn't empty, a number, or the `sticky` keyword is evaluated as R on the server, as written.

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

**Only the browser evaluates a showif.** An item with a showif renders hidden and is revealed as soon as its showif is true; the server never runs it. The browser knows every item of this form — including answers saved on earlier pages when the participant reloads or comes back later. It does not know anything else: a run-level variable like `ran_group` or an item from another survey is an unknown name, and a showif that reads one stays false, so its item stays hidden. Bring such a value onto the form with a hidden item (next section). The compatibility scanner flags these names.

### `value`: server-side R

Just write the R. Numbers stay literal defaults; `sticky` keeps its v1 meaning (`tail(na.omit(survey$item), 1)`); everything else is evaluated on the server with the participant's answers overlaid on `tail(survey_name, 1)`, when the item's page is shown:

```
value: paste(answered_items, collapse=", ")
value: complex_score(current(q1), current(q2))
value: ifelse(is.na(other), "", nchar(other))
value: sample(c(1,1,1,2,2,2,2), 7)
value: sticky
value: 5
```

The R source never reaches the browser. At render time each dynamic value is recorded in `survey_r_calls` (slot `value`) and the client only ever sees the recorded call id, which it hands back when it asks the server to resolve the page. Results are cached for 5 minutes per `(call, answers)` pair. A logical result is written into the field as `TRUE` or `FALSE`.

**`calculate` items are not supported.** v2 runs R only for what a page shows; it does no computation of its own. A study with a `calculate` item — or any other item that takes no input but carries R in `value`, such as a `server` item with a value — cannot be switched to v2, uploaded into a v2 study, or linked to a Form unit; the compatibility scanner lists them. Replace each one by what it was for:

- **the form needs the value** (a showif, a pre-filled answer, a random assignment the next page branches on): a `hidden` item with the same `value` — see below;
- **feedback or a later unit needs it** (a score on the end page, eligibility for a Skip unit, a payment code): compute it there, in that unit's R, from the stored answers. The saving from storing a precomputed score is small, and the R stays next to the place it is used.

### Bridging R into a `showif`

To gate visibility on something only R can compute, put the R in a **hidden item's `value`** and reference that item's name from the showif:

```
name       type    showif           value
my_score   hidden                   complex_score(current(q1))
followup   text    my_score > 0.5
```

The hidden field is resolved on the server; the resolved scalar is written into the input, and the showif reacts to it like to any other answer. You can read the resolved value while testing, and several showifs can reuse `my_score` without paying the OpenCPU cost again. The value is stored with its page like an answer.

A hidden item resolves when its page is shown, against the answers of earlier pages — its own page's answers are still empty then. It is posted by the browser, so a participant (or a bot) can change it, and it is submitted empty if the page's dynamic content could not be loaded (network, rate limit, offline). Nothing on the form is secret: a value participants must not see (their experimental condition, say) belongs outside the form, for example in an earlier survey of the run, and must not be referenced from a showif.

### Page-scoped resolution

Server-side R only runs for the page the participant is looking at, and every page — the first one included — is resolved the same way:

- **Showing a page** (on load, and after each page submit): the client asks the server for that page (`/form-render-page`), which resolves all of its dynamic values, labels, choice labels and `note_iframe` documents in one OpenCPU batch against the latest answers. Until then they show as placeholders.
- **Submitting a page**: the server stores the answers and records which items the page's showifs hid; it runs no R.

Dynamic content on page N+1 usually depends on page N, so it can't be resolved earlier — and pre-resolving pages that may never be reached would waste OpenCPU time. Cost: one OpenCPU batch per page shown (cache-aware), rate-limited to 30 per minute per session (`form_v2_rate_limits` in `settings.php`).

### Dynamic labels (R-Markdown in labels)

A label containing inline R (`` `r expr` ``) is treated as **one** allowlisted call for the whole label — chunks are not extracted individually. The same goes for a choice label with inline R, and for a `note_iframe` (its label is a whole R Markdown document, knitted into the frame). All of them, on every page, are resolved through the page request described above, so dynamic content paints a moment after the rest of the page. Keep heavy dynamic content off the first page if that flicker matters to you.

## Compatibility scanner

Before upgrading a v1 study, run the scanner. It classifies every expression:

**Items v2 cannot run** — `calculate` items and other items that take no input but carry R in `value`. They block the switch to v2; replace them as described under `value` above.

**`showif`**
- **empty** — no expression
- **JS-OK** — transpiles to JavaScript cleanly
- **needs JS rewrite** — residual R-only tokens (`ifelse`, `c(`, `%in%`, `<-`, …): rewrite in JS, or move the R into a hidden item's `value` and reference that name
- **not on the form** — reads a name that isn't an item of this survey (`ran_group`, another survey's item): compute it in a hidden item's `value` and reference that item

**`value`**
- **empty**, **literal** (a number, kept as-is), or **R** (evaluated on the server)

Two ways to run it:

- **Admin UI** (recommended): the survey settings page (`/admin/survey/<name>`) shows the flagged count next to the **Upgrade to form_v2** button; **Run v2 compatibility scan** opens the full report with source, transpiled output, and the suggested fix per item.
- **CLI**: `php bin/form_v2_compat_scan.php <study_id|study_name>`. Exits 0 if clean, 2 if anything is flagged — usable as a CI gate.

The scan is advisory and changes nothing. But take the showif flags seriously: at runtime a showif the browser can't evaluate leaves its item hidden, for every participant.

## PWA features (AddToHomeScreen / PushNotification)

The `add_to_home_screen` and `push_notification` items work in v2 as in v1; the wiring is vanilla JS inside the form bundle (no jQuery), and admins shouldn't notice a difference.

1. Configure the run as installable (run settings → "Use as installable PWA"). This generates the manifest, the VAPID keypair and the icon set; check that `/{run}/manifest` responds.
2. Add the items to your survey sheet as usual:
   - `add_to_home_screen` — captures the browser's `beforeinstallprompt` and fires it on click. Where there is no programmatic install API (iOS Safari), it shows inline "Share → Add to Home Screen" guidance instead.
   - `push_notification` — calls `Notification.requestPermission`, subscribes through the service worker's push manager and POSTs the subscription to the run.
3. The participant page registers the service worker itself — on installable runs only; a study without the PWA setting gets no service worker.

iOS specifics: push only works **inside the installed PWA** on iOS 16.4+. The participant must add the study to their home screen first and reopen it from there; if they tap the push button before installing, v2 shows an error explaining this.

## Offline queue

When **Offline queue** is on (the default), a page submission that fails — no network, or a 5xx from the server — is stored in the browser's IndexedDB and the participant advances locally. The banner says "queued". The queue drains when the browser fires `online` and on the next load of any page of the study; each entry is replayed to `/{run}/form-sync`, which applies the answers through the same path as a normal page submit and ignores entries it has already seen (each entry carries a UUID; the server keeps a ledger in `survey_form_submissions`). A drain that hits a validation error stops that run's queue, keeps the entry and asks the participant to fix the highlighted answers on that page and submit it again; entries the server says no longer apply (the session ended, the participant moved on to another Form) are dropped.

- **Nothing drains while the tab is closed.** The queue is drained by the open page, not by the service worker. Tell participants to reopen the study once they are back online.
- **Turning the queue off does not strand what is already queued.** Entries stored while it was on are still sent the next time the participant opens the study; only new submissions are no longer queued.
- **File uploads are queued too**, up to `form_v2_offline_blob_max_mb` per file (`settings.php`, default 10 MB). A larger file is refused with a "too large to queue offline" message instead of risking the browser's storage quota.
- **Answers sit in plaintext on the device until they drain.** If that is unacceptable for your study, turn the queue off — submissions then fail visibly when offline and nothing is stored.
- The queue for a run is wiped when the participant clicks a logout link on the form page. That is the only wipe: a participant who reaches `/{run}/logout` from elsewhere, or uninstalls the PWA, keeps whatever is queued.

## Known limitations

- **`modified` in R is the last answer stored**, not the last page view: a form_v2 survey writes nothing when a page is only looked at. Before the first answer it is the time the participant started the survey. For "inactive since" logic, use the answers' own times, or the survey's `created` and `ended`.
- **No background drain**: see above — the queue is sent by the open page only.
- **Previous button**: going back reveals the already-rendered page with the answers given so far; it is not refetched from the server. Dynamic values and labels on it are whatever they resolved to the first time. Leave the toggle off unless your design tolerates that. The server does not police it: with the toggle off, the browser hides the way back, nothing more.
- **Where a participant resumes**: the browser remembers the page it last showed (in `localStorage`, per unit session), so a reload or a reopened tab lands where they were, with every answer given so far in its input. On another browser or after clearing site data there is no memory, and the form opens on the first page that holds an unanswered item; an optional item left blank on a submitted page counts as done.
- **Range slider**: v1's floating value tooltip came from the `webshim` plugin, which v2 doesn't ship. `range` / `range_ticks` render as a native slider with side labels; the participant sees their position on the thumb only. `visual_analog_scale` is the v2-native answer for "no default" rating items.
- **Solo layout**: auto-advance fires on the first commit of single-choice radios, single selects and range sliders. Checkboxes, multi-selects and free-text items never auto-advance — the participant uses the Next button.
- **Spreadsheet-side admin classes** (`mc_width70`, `rotate_label45`, `mc_vertical`, `rating_button_label_width50`, …) work as in v1; the same `custom_item_classes.css` ships in the v2 bundle.

## What's new in v2

Beyond the rendering model:

- **A choice value `0` is stored in multiple-choice answers.** In `mc_multiple`, `mc_multiple_button`, `select_multiple` and `select_or_add_multiple`, v1 drops a ticked choice whose value is `0` (ticking 0 and 2 stores `2`); v2 stores `0, 2`. Switching a study to v2 widens those results columns where they were sized without the `0`. Studies on v1 keep storing as before.
- **Clearing an answer withdraws it.** When a participant empties a field or deselects a choice, the next autosave removes the answer stored earlier (from both the item table and the results table), so it neither comes back after a reload nor stays in the data if they break off.

- **New item types**: `rank` (drag-to-order list) and `bot_check` (a proof-of-work human check, ALTCHA-style, no third party). Both are v2-only. `visual_analog_scale` renders on both engines. See `templates/public/documentation/item_types.php` for the item reference.
- **`bot_check` fails closed.** If the server has no crypto key it refuses every token and the item shows "Human verification is temporarily unavailable" instead of waving everyone through; the challenge endpoint requires an active participant session and is rate-limited (`form_v2_rate_limits.bot_challenge_per_min`, default 20). Difficulty is a server decision: write `bot_check 2` in the type column for a harder proof-of-work on one item (1–3), or have your operator raise the instance defaults — `bot_check_prefix_bytes` (default 1), `bot_check_argon_memory` (4096 KiB), `bot_check_argon_time` (1), `bot_check_ttl` (86400 s) and `bot_check_secret` (default: derived from the instance crypto key), all documented and shipped commented-out in `config-dist/settings.php`. Nothing about the participant leaves the server.
- **Page-expired modal**: when a unit session's expiry passes mid-form, participants see the same "page expired" prompt as in v1.
- **Localized engine strings** (`language` setting, see the settings table).
- **Solo layout** (`layout` setting).

## Migration checklist for an existing study

1. Run the compatibility scan (survey settings page, or `bin/form_v2_compat_scan.php`).
2. For every flagged item: rewrite the showif in JS, or move the R into a hidden item's `value` and reference that item from the showif. Re-upload the sheet.
3. Decide the per-study settings — offline queue, Previous button, layout, language, button labels (they appear on the settings page once the study is on v2).
4. If the study uses install or push, configure the run as installable and verify `/{run}/manifest` responds.
5. Try the study on v2 before switching it — until the switch, "Use as yourself" and **Test Survey** still show v1: export the survey as xlsx, upload the file under a new name with **Engine: form_v2**, and walk every page with **Test Survey**; check flagged items and dynamic values behave as expected.
6. Use the copy in a Form unit. **Upgrade to form_v2** on the survey settings page works only for a survey that no run uses as a classic Survey; **Switch back to v1** undoes it at any time.

## See also

- `form_v2_upgrade.md` — instance upgrade: migrations 082–097, settings, the backfill step, rollback.
- `agent_doc/form_v2_layout_modes.md` — the solo layout design.
- `agent_doc/plan_form_v2.md` — engine design and remaining work (for engineers).
- `CLAUDE.md` § "form_v2 development notes" — code-level gotchas.
- `bin/form_v2_compat_scan.php` — CLI scanner.
- `documentation/example_surveys/form_v2_basic.xlsx` and `documentation/run_components/Form_v2_Basic.json` — a minimal v2 study (JS showif, `rank`, `visual_analog_scale`, `bot_check`) and an importable run using it. The run bundle embeds the study, so importing it in the run editor creates `form_v2_basic` and links the Form unit to it (the sheet is the same study, for uploading by hand). Your own runs export the same way: "Export" with *Include survey items* ticked ships each Form's study, and importing the bundle recreates it — under the current user, reusing a same-named study if one exists.
