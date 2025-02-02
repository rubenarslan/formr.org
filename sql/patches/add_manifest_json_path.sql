-- SQL Patch: Add manifest_json_path column to survey_runs table

ALTER TABLE `survey_runs`
ADD COLUMN `manifest_json_path` VARCHAR(255) DEFAULT NULL AFTER `custom_js_path`; 