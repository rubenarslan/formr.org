-- SQL Patch 117: drop survey_items.showif_js
--
-- Patch 106 cached v1's regex translation of each showif into JavaScript.
-- A form_v2 showif is JavaScript used as written, and v1 translates its R
-- showifs on each render (Item::showifToJs), so nothing reads the column.

ALTER TABLE `survey_items` DROP COLUMN IF EXISTS `showif_js`;
