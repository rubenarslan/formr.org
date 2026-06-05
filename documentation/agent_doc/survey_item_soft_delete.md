# Survey-item soft-delete

Editing a study mid-collection to **remove or rename** an item no longer wipes
the long-format (`survey_items_display`) participant data. The item flips to a
`deleted` marker instead; the row and its answers are preserved, recoverable,
and revived if the name is re-added.

## Why (the cascade gotcha)

`survey_items_display.item_id → survey_items.id` is **ON DELETE CASCADE**. The
old reconciliation in `SurveyStudy::saveUploadedItemsFromReader` did
`DELETE FROM survey_items` for any name gone from the re-uploaded sheet, so the
cascade silently deleted that item's long-format answers. A **rename** is
delete-old-name + insert-new-name (the item upsert is keyed on
`UNIQUE(study_id, name)`), so renames lost data the same way.

## What changed (patch 069: `survey_items.deleted DATETIME`)

- **saveUploadedItemsFromReader**: the removed-items `DELETE` is now
  `UPDATE survey_items SET deleted = NOW()`. No row removal → no cascade → the
  long data survives.
- **addItems** upsert appends `deleted = NULL`, so re-adding a name revives the
  same row (and its preserved answers re-enter the form).
- **Excluded from render / create / edit** (so deleted items disappear from the
  form and the editable sheet):
  - `getItems()` — excludes deleted by default; takes `$includeDeleted` and now
    returns the `deleted` column.
  - `getOrderedItemsIds()` — no new `survey_items_display` rows for deleted items.
  - `FormRenderer::getAllUnansweredItems` (v2), `SpreadsheetRenderer::
    getNextStudyItems` + `getStudyProgress` (v1).
  - `getItemsForSheet()` — keeps deleted items out of the round-trip sheet so a
    download→re-upload doesn't silently revive them.
- **Long-format export includes + marks** them: `getItemDisplayResults` selects
  `survey_items.deleted AS item_deleted`. The data is in the export, flagged.

## Deliberately unchanged

- **Confirmation gate** kept as-is: when `hasRealData()` (real_users > 1), a
  removal still needs the type-the-survey-name confirmation + the results backup.
  (Soft-delete means long data survives regardless, but the gate behaviour was
  left intact per request.)
- **Wide per-study results table**: still has its column dropped on edit
  (`alterResultsTable`); it's being phased out, so its data is not preserved here.
  Soft-delete is **long-format only**.

## Scope note

The change is in shared study/render code, so v1 surveys benefit too (their
`survey_items_display` data is likewise preserved). Verified end-to-end
(`tests/e2e/survey-item-soft-delete.spec.js`): remove → SOFT-DELETED with answer
preserved → re-add → same row id revived, data intact.
