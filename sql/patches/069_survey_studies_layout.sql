-- SQL Patch 066: form_v2 presentation layout (skin).
--
-- `layout` switches the v2 renderer between the default multi-item page
-- and a "solo" skin that presents one item per screen (scroll-snap-based,
-- Typeform-like). v1 surveys ignore the column. ENUM (not BOOL) so future
-- skins can be added without another migration.

ALTER TABLE `survey_studies`
ADD COLUMN `layout` ENUM('default','solo') NOT NULL DEFAULT 'default' AFTER `allow_previous`;
