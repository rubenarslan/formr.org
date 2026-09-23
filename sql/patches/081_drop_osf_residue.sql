-- 081: drop what the OSF removal left behind.
--
-- v1.10.2 removed the OSF integration (Services/OSF.php, admin/osf, api/osf,
-- the run-settings tab, $settings['osf']) but left its storage in place:
-- the `osf` table (patch 015; per-user OSF OAuth access tokens, nothing reads
-- or purges them any more) and `survey_runs.osf_project_id` (patch 016; the
-- Run model no longer selects or writes it). Both are dead schema that every
-- fresh install kept creating and every audit had to re-discover as dead.
--
-- Drift-tolerant: IF EXISTS on both, so a host that already removed them by
-- hand replays cleanly.

DROP TABLE IF EXISTS `osf`;

ALTER TABLE `survey_runs` DROP COLUMN IF EXISTS `osf_project_id`;
