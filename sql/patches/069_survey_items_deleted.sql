-- Soft-delete for survey items (form_v2 long-format preservation).
--
-- When a study's items are edited mid-study and an item is removed (or renamed,
-- which is delete-old-name + add-new-name), the reconciliation used to
-- `DELETE FROM survey_items`, which CASCADE-wiped the item's
-- survey_items_display rows (the long-format participant data). Instead, items
-- now flip to a `deleted` marker: the row + its survey_items_display answers are
-- preserved, the item stops rendering, and the data stays recoverable in
-- long-format exports. (The wide per-study results table is being phased out and
-- is unaffected here — its column is still dropped on edit, gated by the
-- existing confirmation/back-up flow.)
ALTER TABLE `survey_items`
    ADD COLUMN `deleted` DATETIME DEFAULT NULL AFTER `page_no`;
