-- 080: skip units written before computed jumps keep their boolean reading.
--
-- Why: since v1.8.0 a SkipForward / SkipBackward condition that returns a
-- number of 2 or higher is read as an absolute run position to jump to.
-- Before that, every non-zero result meant TRUE (PHP's (bool) coercion of
-- the R value), so a condition such as `survey$age`, `nrow(diary)` or
-- `sum(screener$symptoms)` jumped to the unit's own if_true position; after
-- the upgrade the same code jumps to position 25 — or, when no unit sits
-- there, continues past the skip. Nothing in the run editor changed, so the
-- routing flipped silently, and it has already bitten a live study.
--
-- Fix: wrap the code every existing skip unit carries in as.logical(), the
-- R cast that reproduces the old reading (non-zero → TRUE, 0 → FALSE, NA
-- stays NA, TRUE/FALSE untouched). It is done in the condition text itself,
-- not in PHP, so the author sees exactly what runs and removes the wrapper
-- to opt a unit into computed jumps. `{expr} == TRUE` would NOT do: in R
-- `5 == TRUE` is FALSE, which turns the very conditions this protects from
-- "jump" into "continue".
--
-- Left alone: NULL / blank conditions; the literals TRUE / FALSE / T / F /
-- 1 / 0 (a no-op wrap); a bare integer of 2 or higher, which only ever
-- meant something as a computed jump and so was written for the feature.
-- Replayable: an already-wrapped condition is skipped, and the wrapper is
-- braces + newlines so multi-line code and a trailing `# comment` stay
-- valid R. Units created after this patch are not touched by it.

UPDATE `survey_branches` b
  JOIN `survey_units` u ON u.id = b.id AND u.type IN ('SkipForward', 'SkipBackward')
SET b.`condition` = CONCAT('as.logical({', CHAR(10), b.`condition`, CHAR(10), '})')
WHERE b.`condition` IS NOT NULL
  AND b.`condition` NOT REGEXP '^[[:space:]]*$'
  AND b.`condition` NOT REGEXP '^[[:space:]]*as[.]logical[(]'
  AND b.`condition` NOT REGEXP '^[[:space:]]*(TRUE|FALSE|T|F|1|0)[[:space:]]*$'
  AND b.`condition` NOT REGEXP '^[[:space:]]*([2-9]|[1-9][0-9]+)[[:space:]]*$';
