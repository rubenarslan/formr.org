# Data quality: AI-agent and careless-responding indicators

The design of `feature/data-quality` (off `feature/form_v2`): the prototype in
`rubenarslan/formr-dq-agent-detect` (run `custom_js` on a v1 formr) as an
opt-in, item-based feature of core, on both engines. Researcher-facing
documentation, with the codebook, is the admin-only page
`/admin/documentation/data-quality`.

## What ships

| Piece | Where |
| --- | --- |
| `data_quality` item | `application/Model/Item/DataQuality.php` |
| `agent_probe` item | `application/Model/Item/AgentProbe.php` |
| Recorder (the prototype's checks) | `webroot/assets/common/js/data-quality.js` |
| v1 hookup | `common/js/survey.js` calls `initDataQuality()` |
| v2 hookup | `form/js/items/data-quality.js`, called from `form/js/main.js` |
| CSS | `common/css/custom_item_classes.css` (both bundles import it) |
| Admin-only documentation | route `admin/documentation`, `AdminDocumentationController`, `templates/admin/documentation/` |
| R helpers for reading records | `documentation/data_quality/dq_agent.R`, shown and downloadable in the admin docs |
| Example survey | `documentation/example_surveys/data_quality.xlsx` (`bin/gen_data_quality_xlsx.php`) |

No schema change: `survey_items.type` is a `varchar`, and the items store into
the results table like any other.

## Items

**`data_quality`** — a hidden input the recorder fills with the page's record.
`MEDIUMTEXT`, always optional whatever the sheet says, one per page, never on a
consent page (a page without the item records nothing). The wrapper is
`display:none`. The server never blocks a page over it: `getReply()` keeps a
flat JSON object of scalars under 8 KB and stores `NULL` for anything else.
Its stored value is rendered back into the input (the recorder adds to it) but
left out of the v2 state block.

**`agent_probe`** — a note and a textarea people never see (clipped to 1 px,
still in the accessibility tree; `tabindex="-1"`, `autocomplete="off"`).
Always optional. The note is the prototype's `AGENT_NOTE`, in the same wrapper
tokens; `label` replaces the question (`AGENT_Q`), the first choice replaces
the note's sentence (for a study that cannot promise "is paid regardless", or
is not in English). Any stored text means something read the note.

Both are *unseen* (`Item::isUnseen()`, wrapper attribute `data-fmr-unseen`):
they take no part in deciding whether a page has anything to show (v1:
`SpreadsheetRenderer::processItems`, `PagedSpreadsheetRenderer::
displayedItemExists`; v2: `snapshotPages` in `main.js`), are not steps in the
solo layout, are not asked for by the all-answered auto-submit, never get the
page-change focus, and the admin's "show hidden items" and form monkey leave
them alone. v2's progress is by page and by solo step, so they are not in it;
v1's item-count bar counts them the way it counts notes and `hidden` items.
Neither takes a `value` (refused at upload).

The randomised-response question is a documented `mc_button` row, not a type.
The `browser` item is documented as the source of the User-Agent header.

Registration points (checked): `ItemFactory` finds the classes by name, so the
spreadsheet reader, the JSON upload and the API structure import (`createFromData`
→ `addItems`) accept the types as they are; `FormV2CompatScanner` is
type-agnostic and both items return `null` from `formV2Unsupported()` and
`v1Unsupported()`; `PersonalDataAudit::TYPE_CATEGORIES` gets `data_quality`
(technical); `all_widgets.json` and `all_widgets_v2.json` get a row each (the
catalogue spec fails otherwise); `item_types.php` gets one line pointing to the
admin documentation. `SurveyStudy::addItems` warns about a second
`data_quality` item on one page.

## Recorder

One module, `createRecorder()`, used by both engines. It keeps the prototype's
checks and thresholds; what changes is what bundling and v2 force:

- **Inert without the item.** Importing does nothing. Each adapter looks for
  `input[data-fmr-dq]` and creates a recorder only when it finds one; the
  recorder is what adds listeners, wraps `HTMLElement.prototype.focus`, starts
  the MutationObserver and the DRM probe. In v2 that happens when a page with
  the item is first shown, so a consent page earlier in the same form records
  nothing; on a page without the item the handlers return at once.
- **One record per page.** v1: per page load, written into the input every
  second and on submit, as in the prototype. v2: counters are kept per page
  (`<section data-fmr-page>`); `showPage` tells the recorder which page is on
  screen; the record is written into that page's input in `collectPayload`
  (page submit, and the autosave of that page's changed answers) with a plain
  `.value =` — no event, no timer, so it never triggers an autosave. A page
  shown again (Previous) keeps counting into the same record; after a reload
  the stored record, rendered into the input, is the base the new counts are
  added to (`mergeRecords`).
- **Whose code called?** `foc_prog` counted `focus()` calls with no frame from
  `location.origin`, and left out the script's own frame by its URL. In a
  bundle, the wrapper and formr's own callers share one file, and the bundle
  may be served from another origin. Now the stack is taken inside the wrapper
  itself: its first frame *is* the bundle, and names its address; a call is
  the page's own when any later frame is from that origin or the page's
  (`callerKind`). The same test is applied to script-made (untrusted) events:
  v2's widgets dispatch `change`/`input` themselves (button groups, rank,
  R-resolved values), which the prototype never met on v1. Events from the
  page's own code are not counted; a value the page writes into an untouched
  item (an R default) becomes that item's baseline instead of an "answer
  without interaction".
- **v2 page changes are not the participant's.** The page's own scroll to top
  and focus move on a page change fall into the same grace periods the
  prototype gives a page load (measured from the page being shown), and a
  focus move the page's own code made is not "focus without click or key".
- **Version check against native features.** The bundle is built with
  `useBuiltIns: 'usage'`, so `[].findLast` and friends would be polyfilled and
  every older Chrome would fail `ver_mm`. The features are named as strings and
  read from a fresh iframe's window.
- **`Permissions-Policy`.** Nothing in formr or formr-docker sets one (checked:
  source, Apache templates, traefik config, live response headers), so real
  Chrome keeps Widevine. If a policy ever disallows `encrypted-media`, the probe
  is skipped and the record says `drm: "blocked"` instead of flagging.
- Record format `v: 2` (v1 = the prototype). Data minimisation unchanged.

## Admin-only documentation

`admin/documentation/<page>` → `AdminDocumentationController` (extends
`AdminController`: login and admin level required, as for every admin page).
`noindex` by header and meta tag; linked in the admin header next to Help, for
admins only. An unknown page is a 404. First page: `data-quality` — the four
checks, the rows to add, the consent paragraph, reading results (R), the
codebook, what the tests showed, guardrails, the bot ecosystem, limits, why it
is not fingerprinting, and how it relates to `bot_check`.

## Tests

- PHPUnit: `DataQualityItemTest`, `AgentProbeItemTest` (rendering, always
  optional, what is stored, no `value`), the per-page warning, the audit map.
- `npm run test:js`: `mergeRecords`, `parseRecord`, `callerKind`.
- Playwright `tests/e2e/data-quality.spec.js` on `e2e-dq-v1` / `e2e-dq-v2`
  (the example survey): a human-like run (`helpers/human.js`) raises no
  behaviour flag, a plain Playwright run does, the hidden field is stored when
  filled, code injected into the page is told apart from formr's own, v2
  stores one record per page (Previous and a reload add to it; also through
  the offline queue), no autosave without a change, and the consent page and a
  survey without the items stay inert. `e2e-dqskip-v1` / `-v2`
  (`setup/e2e_dq_skip.csv`): a page left with only the two items is skipped.
- `tests/e2e/admin-documentation.spec.js`: login required, `noindex`, 404,
  downloads, the header link, and the public documentation's one line.
- The fixtures are made by `setup/create-v2-fixtures.js`.

## Not done

- No test on a phone or tablet, and none in Firefox or Safari (the e2e lane
  is Chromium): the recorder's mobile exemptions are the prototype's, as is.
- The R helpers are a file (`documentation/data_quality/dq_agent.R`), not yet
  part of the formr R package.
- `server` items still take only their fixed list of request headers, so the
  signed-agent headers (`Signature-Agent`, as ChatGPT agent sends) and the
  client-hint headers cannot be stored yet.
- In v2 a `browser` item is stored when the form is first rendered, whichever
  page it is on (`FormRenderer` stores all automatic items then). The admin
  page tells authors to keep the consent in a survey of its own, or to leave
  the row out.
