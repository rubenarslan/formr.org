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
| R functions for reading records | the formr R package, 1.3.0 or later: `dq_parse()`, `dq_flags()`, `dq_flags_by_session()`, `dq_rrt()` (its `R/data_quality.R`). formr.org holds no copy. |
| Example survey | `documentation/example_surveys/data_quality.xlsx` (`bin/gen_data_quality_xlsx.php`) |
| Example run | `documentation/run_components/Data_Quality.json`, `Data_Quality_v2.json` (`bin/gen_data_quality_run.php`), downloadable from the admin page |

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

Nothing a participant cannot see may block a page, and `Item::$unseen` is
where that is kept: the constructor makes such an item optional whatever the
sheet says, `rejectNonScalarReply()` refuses nothing for it (each item's
`getReply()` cleans what is stored) and `alwaysInvalid()` leaves it alone.
On the classic engine an unseen item takes none of a page's places under
*Items Per Page* and is never what a page is cut at
(`SpreadsheetRenderer::withinItemsPerPage`): it stays with the last items
shown, so a page shown as several screens still takes its record, on the
last of them.

The randomised-response question is a documented `mc_button` row, not a type.
The `browser` item is documented as the source of the User-Agent header, and
`server` items (`Server_Item::VARIABLES`) as the source of two client hints and
of the `Signature-Agent` header an AI agent that identifies itself sends; the
R helpers compare the hints with the User-Agent header.

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
  `input[data-fmr-dq]` and creates a recorder only when it finds one in use;
  the recorder is what adds listeners, wraps `HTMLElement.prototype.focus` and
  `Element.prototype.scrollIntoView`, lets error stacks run to 50 frames,
  starts the MutationObserver and the DRM probe. In v2 that happens when a
  page with the item is first shown, so a consent page earlier in the same
  form records nothing; on a page without the item the handlers return at
  once. A row with a showif is in use only while the showif shows it
  (`liveFields`: hidden, its input is disabled, as for any withdrawn item):
  v2 asks again when a showif of the page on screen toggles, v1 after
  `Survey.update()` has run the page's showifs.
- **One record per page.** v1: per page load, written into the input every
  second and on submit, as in the prototype. v2: counters are kept per page
  (`<section data-fmr-page>`); `showPage` tells the recorder which page is on
  screen; the record is written into that page's input in `collectPayload`
  (page submit, and the autosave of that page's changed answers) with a plain
  `.value =` — no event, no timer, so it never triggers an autosave. A page
  shown again (Previous) keeps counting into the same record; after a reload
  the stored record, rendered into the input, is the base the new counts are
  added to (`mergeRecords`). The record is stored with the participant's
  answers, not instead of them: an autosave of which nothing but the record
  was accepted is a `noop` (`FormSubmissionService::holdsAnAnswer`), so the
  page does not say "Saved" for an answer that was refused.
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
- **Phones and tablets that do not say so.** The prototype read "mobile"
  from the User-Agent. An iPad calls itself a Mac and Chrome on a large
  Android tablet a Linux PC (both seen on the BrowserStack devices), which
  left the keyboard, window and DRM checks on. `mob` now also takes a "Mac"
  with a touch screen and a device whose pointer is coarse and cannot hover.
- **A widget set off by a script is not the page acting.** v2's button
  groups announce their change themselves. When a foreign `click()` set one
  off, that own event must not re-base the item (the answer was given
  without a touch). Chrome and Firefox show the foreign caller on the stack
  (`callerKind` "mixed"); WebKit shows no frame for code without a file, so
  the recorder also remembers that a foreign event is being handled. With no
  such event, a "mixed" call that code other than the page's began
  (`startedByOwn`: the stack's last frame) is that code acting through the
  page's own, as with the classic engine's global jQuery (`$(el).click()`),
  and counts as its act (`actor`). The `input` and `change` a browser fires
  for a script's `click()` on a radio button or checkbox are trusted; while
  that script's event is being handled they do not touch the item.
  A stack is read only as far as the calls that are running now
  (`ASYNC_FRAME_RX`): V8 goes on with the functions that await, and Firefox,
  when a tool drives or debugs it, with the whole stack from before an await,
  a timer or a listener's registration. What the page does after awaiting a
  reply is the page's own act in every engine.
- **The survey's own markup is not a tool.** Ids and classes inside the form,
  and the classic engine's `study-name-…` block around it, hold names an
  author chose: the study's, a choice's key in an input's id, an item's
  classes, a label's HTML. The scan for tool names (`ext`) reads tags and
  attribute names everywhere, ids and classes only outside that markup
  (`OWN_MARKUP`, `elementNames`).
- **Clicks the browser makes itself.** A click on a label is passed on to its
  control as a second click; Enter, Space and the arrow keys (in a group of
  radio buttons) click what has the focus. All are trusted clicks without a
  pointer, and `clk_nopt` counts none of them.
- **Text written on the visit.** `txt_ch` and `fld_nokey` go by the characters
  that are new against what a field held when the page was shown
  (`newChars`): a stored answer that comes back with its page, or a default,
  was not written now. Only fields a person types into count
  (`isTypedField`): not a geopoint's read-only field, the input behind a
  `select_or_add` list or the clickable map's. `txt_ch` adds up over visits,
  as `keys` does.
- **Where a word starts** (`startsWord`): after a space or punctuation, on a
  new line, and with the first key in another field. A long pause there is
  where people pause.
- **The page's own scroll to an item** (`scrollIntoView`, to what a server
  reply found wrong) is not the participant scrolling without a gesture; the
  wrapper tells whose call it is the way the `focus()` wrapper does.
- **Brave** keeps Widevine off until a page asks, and then offers to install
  it: the DRM question is not put there (`drm: "na"`).
- Record format `v: 2` (v1 = the prototype). Data minimisation unchanged.

## Admin-only documentation

`admin/documentation/<page>` → `AdminDocumentationController` (extends
`AdminController`: login and admin level required, as for every admin page).
`noindex` by header and meta tag; linked in the admin header next to Help, for
admins only. An unknown page is a 404. First page: `data-quality` — the four
checks, the rows to add, the consent paragraph, reading results (R), the
codebook, what the tests showed, guardrails, the bot ecosystem, limits, why it
is not fingerprinting, and how it relates to `bot_check`.

## Example run

`Data_Quality.json` / `Data_Quality_v2.json`, modelled on the prototype's
weekend study. Five units: a consent survey (no `data_quality` item, so
nothing is recorded; a survey of its own, so that on v2 the `browser` and
`server` rows of the study are stored only after consent), a skip to the last
page for those who decline, the study (part 1: five personality items, an open
question, the `browser` and `server` rows; part 2: the plausible-deniability
question, age, a second open question; each page ends with an `agent_probe`
and a `data_quality` row), an end page with the analysis, and an end page for
no consent.

The analysis page is R markdown (`documentation/data_quality/example_analysis_page.md`):
`dq_flags_by_session()` on `tail(<study>, 1)`, the participant's own row,
shown as a table of what is flagged, what the hidden field holds and the
answer to the three statements, with the code for a whole sample beside it.
Run R receives only the survey columns it names (`UnitSession::getRunDataNeeded`
matches names, and names without their trailing digits, as words in the
code), so the page passes the column names and patterns explicitly. It exists
for the researcher trying the example; the admin page says that a real study
never shows participants what was flagged.

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
- Playwright `tests/e2e/data-quality-example.spec.js` on `e2e-dq-example-v1`
  / `-v2` (the example run, imported from its bundle): a human-like visit ends
  on the analysis page with no input flag, a scripted one that fills the
  hidden field is flagged for both, and without consent the run ends with
  nothing of the study stored. The page is knitted by OpenCPU with the R
  package, so this is also the check that the installation has formr 1.3.0.
- `tests/e2e/admin-documentation.spec.js`: login required, `noindex`, 404,
  downloads, the header link, and the public documentation's one line.
- The fixtures are made by `setup/create-v2-fixtures.js`.

- `tests/e2e/data-quality-devices.spec.js` on BrowserStack
  (`npm run test:bs:dq`, `browserstack.data-quality.yml`): an iPhone and an
  Android phone, an iPad and an Android tablet, Firefox on Windows and WebKit
  on macOS. It holds what a device run can show — the record is stored, the
  real engine is recognised and nothing says the browser lies, a phone or
  tablet is known as one, script-made input is told from formr's own in each
  engine's stack format, nothing of the probe shows and the page fits the
  screen.

## Not done

- How a person's typing and tapping look on a phone or tablet. Under
  automation the on-screen keyboard does not come up and taps come through
  the bridge, so the device run cannot tell; the typing checks that need a
  physical keyboard are off on phones and tablets, the rest is untested with
  real hands there.
- Apple's Safari on a desktop: Playwright cannot drive it. The device run
  uses WebKit on macOS, and real Safari on the iPhone and iPad.
- In v2 a `browser` or `server` item is stored when the form is first
  rendered, whichever page it is on (`FormRenderer` stores all automatic items
  then). The admin page tells authors to keep the consent in a survey of its
  own, or to leave the rows out.
