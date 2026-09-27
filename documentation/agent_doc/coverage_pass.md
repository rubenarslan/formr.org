# Coverage pass — findings

What the test suites execute, merged across lanes, read for code **nothing**
runs. Tooling and method: `tests/coverage/README.md` (`tests/coverage/run.sh`).
Measured at `0f71a50e` on `feature/form_v2`: unit lane (619), all 25 smokes,
the full e2e suite in both projects (252 passed).

## Headline

| surface | lines run | notes |
| --- | --- | --- |
| PHP (application/, templates/) | 60.7% (15,229 / 25,071) | e2e adds ~19 points over unit + smokes |
| JS, our sources in the bundles | 61.1% (6,835 / 11,183) | e2e only; `main.js` 73.8% |
| `state.mjs` under `node --test` | 89.3% | the rest is `localStorage`, run by e2e |

The percentages are not the point. Two reading notes: Xdebug credits a
multi-line statement to its first line, so continuation lines of long SQL
strings or `sprintf` calls show as uncovered (noise); and `sendJsonResponse`
exits, so the `return` after it never counts.

## Gaps worth a test (v2 and data correctness first)

1. **`/form-sync`, the real endpoint** (`RunController::formSyncAction`).
   Every queue spec mocks it, so the server half of at-most-once delivery
   is unchecked: the `already_applied` pre-check, the rollback on a
   validation-error reply (nothing applied, ledger row gone, a resend
   applies), the rollback and ledger delete on an exception, the 400 for a
   bad envelope, a `NULL` client_ts. → e2e posting to the real endpoint
   with the participant's cookies.
2. **The shared v2 gate's refusals** (`formWriteContext`,
   `previewWriteContext`): no run session (403), rate limit (429), current
   unit not a v2 Form, stale tab (`stale_session`). → same spec.
3. **The browser's live-submit error handling** (`submitPageInner`,
   `main.js` ~1144–1190): stale-session reload, 403 reload, 409 → run URL,
   unparseable 200, server validation errors rendered on the page. None
   ran. → e2e with `/form-page-submit` answers mocked.
4. **Routing to a missing required item** (`routeToMissing`,
   `firstPageOfItems`): a final submit answered `missing` should open that
   page. Never ran in the browser (the navigation smoke covers the server
   reply only). → e2e.
5. **File uploads on v2** (`buildFormData`, `postQueueEntry` multipart,
   `FormSubmissionService::mergeUploadedFiles` without files): no spec
   picks a file, live or queued. `all_widgets_v2` has a `file` item that
   the showcase walk leaves empty. → pick a file in the walk, and queue one.
6. *(dropped: the `r(...)` unwrapping it pointed at was from an earlier v2
   spec and has been removed.)*
7. **R failure handling** (`RCallService`): the per-expression retry when a
   batch fails, and the `note_iframe` placeholder when knitting fails, both
   CHANGELOG claims, never ran; neither did the allowlist refusals (a call
   row without an item, or for a deleted item). → smoke against OpenCPU
   (`break_opencpu`-style expressions) and rows set up in the DB.
8. **Survey export** (`SurveyStudy::getItemsForSheet` / `getChoicesForSheet`,
   the xlsx/JSON export writing the `settings` sheet): never ran, so the
   export → re-upload round trip that brings the engine back is tested on
   the import side only. → smoke or e2e: export, re-import, compare.
9. **Results out** — the admin results view and the exports
   (`SurveyStudy::getResults`, `pivotedResultsStatement`,
   `getResultsByItemsPerSession`, `getItemDisplayResults`;
   `AdminSurveyController` results/export actions, `AdminRunController`
   exports): all zero. Getting data out is the product and it is untested
   in every lane. → smoke over a study with rows (wide and long reads),
   plus one e2e visiting the results page.
10. **Compat scanner branches** (`FormV2CompatScanner`): `not_on_form`,
    numeric `value`, and the R-token checks (`tail/head`, `is.na`, `<-`,
    `NA`, `$`). → PHPUnit.

## Gaps worth a smoke later (lower risk, harder to reach)

- **Wide-table reads** (`UnitSession::getRunData`, about 80 of its 111
  lines): the path R data takes when `form_v2_long_reads` is off, plus the
  run-level variables branch. Long reads are the default, so this is the
  fallback that can rot. → smoke with the flag off.
- **Writes with the wide table off** (`UnitSession::createSurveyStudyRecord`
  1071–1078, `form_v2_dual_write_results` off) and the
  **study_iteration duplicate-key retry** (1034–1056, a race).
- **`FormSubmissionService::apply`**: the store failing for a
  non-validation reason (170) and a final submit without a run session
  (198); `FormRenderer`: a submit dropped on a page with nothing to answer
  (232), a choice label without a call id (197).
- **Auto-submit timers** (`items/auto-submit.js`: `addFuse`,
  `checkAllAnswered`) and the **expiry modal** (`expiry-notifier.js`:
  `showExpiredModal`, `handleExpiry`).
- **Queue banner "Go to page"** for a needs-attention entry on another page.

## Not worth a local test

- **Push-notification item flow** (`main.js` ~1716–2131: subscribe,
  unsubscribe, test notification, controls) and the **install stack**
  (`beforeinstallprompt`, 1567–1709): need a push service and a real
  install prompt; headless Chromium has neither; only real-device runs
  can exercise them.
- **The service worker itself** is not measured (V8 coverage is taken per
  page); `sw-worker.spec.js` covers its behaviours directly.
- **Admin preview helpers** (`items/admin-preview.js`), **geopoint**,
  **choose-weekdays**: UI item widgets whose e2e would mostly assert
  rendering; take them when they change.
- v1 admin surface outside results (run renaming, file uploads, panic,
  email/push logs, user detail): out of this pass's scope.

## Dead code found

No caller anywhere (grep over application/, templates/, bin/, webroot/):

- `offline/queue.js` `wipeQueue()` (only `wipeQueueForRun` is used).
- `RCallService::rResultToBool`.
- `Item::refresh`, `Item::hasBeenRendered`, `Item::hasBeenViewed`,
  `Item::evaluateDynamicValue`.

`Form::load()` never ran either, but `load()` is reached through the parent
model, so it needs a closer look before it goes.

## Tests written for gaps 1–10

| gap | test | lane |
| --- | --- | --- |
| 1, 2 | `tests/e2e/v2-write-endpoints.spec.js` (real `/form-sync` ledger: resend, refusal, failure, envelope; gate: stale, 403, 429, not a v2 Form) and the preview's stale refusal in `v2-preview.spec.js` | e2e |
| 3, 4 | `tests/e2e/v2-submit-failures.spec.js` (live-submit replies mocked; `missing` routing) | e2e |
| 5 | `tests/e2e/v2-file-upload.spec.js` (live and queued multipart, file stored) | e2e |
| 6 | dropped — `r(...)` unwrapping was an earlier spec's; removed from code and docs | — |
| 7 | `bin/test_form_v2_rcall_smoke.php` (batch retry, note_iframe placeholder, allowlist revocation), `tests/RCallServiceAllowlistTest.php` | smoke, unit |
| 8 | `bin/test_survey_export_roundtrip_smoke.php` (xlsx/xls/JSON export → re-read → re-upload, settings sheet included) | smoke |
| 9 | `bin/test_survey_results_smoke.php` (long and wide reads, filters, paging, long-form readers, downloads) | smoke |
| 10 | `tests/FormV2CompatScannerScanTest.php` | unit |

Each smoke's header lists the bugs below that it guards against.

## Bugs the new tests found (fixed)

1. Run-bundle import left new Survey units unplaced (since `b4b3539d`,
   v1.10.3) — `SurveyStudy::createFromFile` now builds the unit on the run
   in `$options['run']`.
2. Sheet export blanked every 0 (and the wide-read backup TSV its zeros) —
   every `fromArray` call passes `$strictNullComparison = true`.
3. Sheet export folded the choice list into `type` — it has its own
   `choice_list` column now, ahead of `type`; the reader no longer drops an
   option token when a list is already set, and no longer reads a
   `choice_list` column as inline choice `_list` (`choice\d+` only).
4. The JSON survey/run downloads used `JSON_NUMERIC_CHECK` — dropped.
5. `getResultsByItemsPerSession` built `IN ()` — `IN (0)` when nothing
   matches.
6. The `.tab` exports never quoted — a field is quoted when it holds a tab,
   quote or newline (`setEnclosureRequired(false)`).
7. Fixture only: `e2e_v2_inputs`' results table predated the pattern-item
   column fix (`i_zip` was `tinyint`); widened on the dev DB.
