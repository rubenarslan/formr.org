-- 079: enforce run-name uniqueness at the schema level.
--
-- Why: run names are the routing key (participant URLs, ApiBase::getRunByName,
-- the OSF export-by-name path). Uniqueness was only ever a check-then-act in
-- the controller (Run::nameExists before create/rename), so two concurrent
-- requests could both pass it and create duplicate names, after which
-- Run::load()'s findRow-by-name resolves ambiguously. The app has always
-- prevented duplicates at the controller, so a correctly-behaving host has
-- none and this ALTER simply adds the guarantee.
--
-- IF NOT EXISTS: replayable against a schema.sql that already carries it
-- (fresh installs are baselined at the highest patch without running them).
-- NOTE: if a host somehow already holds duplicate run names (only possible
-- via the historical race), this ALTER fails loudly — dedupe those rows
-- before deploying rather than letting the ambiguity persist.

ALTER TABLE `survey_runs`
  ADD UNIQUE KEY IF NOT EXISTS `name` (`name`);
