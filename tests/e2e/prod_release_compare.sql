-- prod_release_compare.sql
--
-- Before/after comparison of v0.25.5 vs v0.25.6 outcomes on a single
-- run. Read-only. **No IPD selected** — every output is an aggregate
-- count or rate over unit/position groupings. No participant tokens,
-- no answer columns, no individual unit-session ids.
--
-- Each section reports the metric split by `survey_unit_sessions.created`
-- on either side of @deploy_ts. Cohorts are not strictly equivalent —
-- a participant enrolled pre-deploy whose ESM continues post-deploy
-- contributes to both sides — so read these as "what shape did
-- unit-sessions created in this window end up in", not "did the same
-- participants experience different outcomes".
--
-- Suggested cadence: run 7-14 days after deploy. Earlier runs will show
-- thin "after" cohorts.
--
-- Run with:
--   docker exec -i formr_db mariadb -u <user> -p<pwd> <db> -t \
--     < tests/e2e/prod_release_compare.sql > release_compare.txt 2>&1
--
-- Adjust @run_name and @deploy_ts at the top before running.

SET @run_name  := 'amor-hauptstudie';
-- v0.25.6 was merged 2026-05-08 20:29:45 UTC = 22:29 Europe/Berlin.
-- Use the deploy time on this host (production-pull or restart).
SET @deploy_ts := '2026-05-09 07:35:00';

SELECT id INTO @run_id FROM survey_runs WHERE name = @run_name;
SELECT @run_id AS run_id, @run_name AS run_name, @deploy_ts AS deploy_ts, NOW() AS query_time;

-- ==========================================================================
-- §A  Cohort sizes (no IPD: only counts)
-- ==========================================================================
SELECT '----- §A  Cohort sizes -----' AS section;

SELECT
    SUM(rs.created <  @deploy_ts)                                      AS run_sessions_before,
    SUM(rs.created >= @deploy_ts)                                      AS run_sessions_after,
    (SELECT COUNT(*) FROM survey_unit_sessions us
       JOIN survey_run_sessions rs2 ON rs2.id = us.run_session_id
      WHERE rs2.run_id = @run_id AND us.created <  @deploy_ts)          AS unit_sessions_before,
    (SELECT COUNT(*) FROM survey_unit_sessions us
       JOIN survey_run_sessions rs2 ON rs2.id = us.run_session_id
      WHERE rs2.run_id = @run_id AND us.created >= @deploy_ts)          AS unit_sessions_after
FROM survey_run_sessions rs
WHERE rs.run_id = @run_id;

-- ==========================================================================
-- §B  Symptom A — orphans (queued=-9, NULL/NULL): per position
--
-- Expected after Fix 1 + Fix 2 + §11: drops to near zero on Survey
-- positions 129/133/137 (AMOR ESM) and Pause positions 122/130/134/138.
-- ==========================================================================
SELECT '----- §B  Symptom A orphans (queued=-9, ended/expired NULL) -----' AS section;

SELECT
    sru.position                                                         AS pos,
    su.type                                                              AS unit_type,
    SUBSTRING(sru.description, 1, 35)                                    AS description,
    -- absolute counts:
    SUM(us.created <  @deploy_ts AND us.queued = -9
        AND us.ended IS NULL AND us.expired IS NULL)                     AS orphans_before,
    SUM(us.created >= @deploy_ts AND us.queued = -9
        AND us.ended IS NULL AND us.expired IS NULL)                     AS orphans_after,
    -- denominators (so reader can compute rate):
    SUM(us.created <  @deploy_ts)                                        AS total_before,
    SUM(us.created >= @deploy_ts)                                        AS total_after,
    -- percent (NULL when denom=0 to avoid divide-by-zero):
    ROUND(100 * SUM(us.created <  @deploy_ts AND us.queued = -9
                    AND us.ended IS NULL AND us.expired IS NULL)
              / NULLIF(SUM(us.created <  @deploy_ts), 0), 2)             AS pct_before,
    ROUND(100 * SUM(us.created >= @deploy_ts AND us.queued = -9
                    AND us.ended IS NULL AND us.expired IS NULL)
              / NULLIF(SUM(us.created >= @deploy_ts), 0), 2)             AS pct_after
FROM survey_unit_sessions us
JOIN survey_run_sessions  rs ON rs.id = us.run_session_id
JOIN survey_units         su ON su.id = us.unit_id
LEFT JOIN survey_run_units sru ON sru.unit_id = us.unit_id AND sru.run_id = rs.run_id
WHERE rs.run_id = @run_id
  AND su.type IN ('Pause', 'Survey')
GROUP BY pos, unit_type, description
HAVING orphans_before > 0 OR orphans_after > 0
ORDER BY pos;

-- ==========================================================================
-- §C  Survey expiry rates per position
--
-- The originally-reported W4.a bug: ESM Surveys at 129/133/137 with
-- X=60, Y=0, Z=0 expired actively-editing participants. Post-Fix-3:
-- expired column should ONLY get set when the participant truly never
-- accessed (pre-access X-rule). Expected: post-deploy `expired` rate on
-- 129/133/137 drops sharply.
-- ==========================================================================
SELECT '----- §C  Survey expiry rate per position (Fix 3 / W4.a) -----' AS section;

SELECT
    sru.position                                                         AS pos,
    SUBSTRING(sru.description, 1, 35)                                    AS description,
    SUM(us.created <  @deploy_ts AND us.expired IS NOT NULL)             AS expired_before,
    SUM(us.created >= @deploy_ts AND us.expired IS NOT NULL)             AS expired_after,
    SUM(us.created <  @deploy_ts)                                        AS total_before,
    SUM(us.created >= @deploy_ts)                                        AS total_after,
    ROUND(100 * SUM(us.created <  @deploy_ts AND us.expired IS NOT NULL)
              / NULLIF(SUM(us.created <  @deploy_ts), 0), 2)             AS pct_before,
    ROUND(100 * SUM(us.created >= @deploy_ts AND us.expired IS NOT NULL)
              / NULLIF(SUM(us.created >= @deploy_ts), 0), 2)             AS pct_after
FROM survey_unit_sessions us
JOIN survey_run_sessions  rs ON rs.id = us.run_session_id
JOIN survey_units         su ON su.id = us.unit_id
LEFT JOIN survey_run_units sru ON sru.unit_id = us.unit_id AND sru.run_id = rs.run_id
WHERE rs.run_id = @run_id
  AND su.type = 'Survey'
GROUP BY pos, description
ORDER BY pos;

-- ==========================================================================
-- §D  Survey terminal-shape distribution per position
--
-- Buckets:
--   completed      = ended IS NOT NULL AND result = 'survey_ended'
--                    (or 'ended_by_queue_rse' / 'manual_admin_push' —
--                    catch-all "ended via some non-expiry path")
--   expired        = expired IS NOT NULL
--   queued_orphan  = queued ∈ (-9, 2) AND ended/expired NULL
--                    (Symptom A; post-fix should be near zero)
--   in_flight      = queued = 0 AND ended/expired NULL (active or
--                    abandoned-but-not-yet-cleaned-up)
-- Reports counts per bucket, before vs after.
-- ==========================================================================
SELECT '----- §D  Survey terminal-shape distribution per position -----' AS section;

SELECT
    sru.position                                                         AS pos,
    SUBSTRING(sru.description, 1, 35)                                    AS description,
    -- before
    SUM(us.created <  @deploy_ts AND us.ended IS NOT NULL)               AS ended_before,
    SUM(us.created <  @deploy_ts AND us.expired IS NOT NULL)             AS expired_before,
    SUM(us.created <  @deploy_ts AND us.ended IS NULL
        AND us.expired IS NULL AND us.queued IN (-9, 2))                 AS sympA_before,
    SUM(us.created <  @deploy_ts AND us.ended IS NULL
        AND us.expired IS NULL AND us.queued = 0)                        AS inflight_before,
    -- after
    SUM(us.created >= @deploy_ts AND us.ended IS NOT NULL)               AS ended_after,
    SUM(us.created >= @deploy_ts AND us.expired IS NOT NULL)             AS expired_after,
    SUM(us.created >= @deploy_ts AND us.ended IS NULL
        AND us.expired IS NULL AND us.queued IN (-9, 2))                 AS sympA_after,
    SUM(us.created >= @deploy_ts AND us.ended IS NULL
        AND us.expired IS NULL AND us.queued = 0)                        AS inflight_after
FROM survey_unit_sessions us
JOIN survey_run_sessions  rs ON rs.id = us.run_session_id
JOIN survey_units         su ON su.id = us.unit_id
LEFT JOIN survey_run_units sru ON sru.unit_id = us.unit_id AND sru.run_id = rs.run_id
WHERE rs.run_id = @run_id
  AND su.type = 'Survey'
GROUP BY pos, description
ORDER BY pos;

-- ==========================================================================
-- §E  end()/queued asymmetry rate per position (Hygiene 4)
--
-- ended IS NOT NULL AND queued != 0 was the artefact of `end()` not
-- resetting queued. Post-Hygiene-4 should drop substantially for unit-
-- sessions created post-deploy.
-- ==========================================================================
SELECT '----- §E  ended-with-queued!=0 rate per position (Hygiene 4) -----' AS section;

SELECT
    sru.position                                                         AS pos,
    su.type                                                              AS unit_type,
    SUBSTRING(sru.description, 1, 35)                                    AS description,
    SUM(us.created <  @deploy_ts AND us.ended IS NOT NULL
        AND us.queued != 0)                                              AS asym_before,
    SUM(us.created >= @deploy_ts AND us.ended IS NOT NULL
        AND us.queued != 0)                                              AS asym_after,
    SUM(us.created <  @deploy_ts AND us.ended IS NOT NULL)               AS ended_before,
    SUM(us.created >= @deploy_ts AND us.ended IS NOT NULL)               AS ended_after,
    ROUND(100 * SUM(us.created <  @deploy_ts AND us.ended IS NOT NULL
                    AND us.queued != 0)
              / NULLIF(SUM(us.created <  @deploy_ts
                           AND us.ended IS NOT NULL), 0), 2)             AS pct_before,
    ROUND(100 * SUM(us.created >= @deploy_ts AND us.ended IS NOT NULL
                    AND us.queued != 0)
              / NULLIF(SUM(us.created >= @deploy_ts
                           AND us.ended IS NOT NULL), 0), 2)             AS pct_after
FROM survey_unit_sessions us
JOIN survey_run_sessions  rs ON rs.id = us.run_session_id
JOIN survey_units         su ON su.id = us.unit_id
LEFT JOIN survey_run_units sru ON sru.unit_id = us.unit_id AND sru.run_id = rs.run_id
WHERE rs.run_id = @run_id
GROUP BY pos, unit_type, description
HAVING ended_before > 0 OR ended_after > 0
ORDER BY pos;

-- ==========================================================================
-- §F  Result-string distribution post-Hygiene-5
--
-- Pre-fix, Survey/External `end()` always overwrote the caller's reason
-- with 'survey_ended'/'external_ended'. Post-Hygiene-5, the reason is
-- preserved. Expected after deploy: results like 'ended_by_queue_rse'
-- and 'manual_admin_push' start showing up where previously you'd see
-- only 'survey_ended'. Aggregate counts only.
-- ==========================================================================
SELECT '----- §F  Result-string distribution -----' AS section;

SELECT
    su.type                                                              AS unit_type,
    us.result                                                            AS result,
    SUM(us.created <  @deploy_ts)                                        AS count_before,
    SUM(us.created >= @deploy_ts)                                        AS count_after
FROM survey_unit_sessions us
JOIN survey_run_sessions  rs ON rs.id = us.run_session_id
JOIN survey_units         su ON su.id = us.unit_id
WHERE rs.run_id = @run_id
  AND us.result IS NOT NULL
GROUP BY unit_type, us.result
ORDER BY unit_type, count_after DESC, count_before DESC;

-- ==========================================================================
-- §G  Per-study Symptom-B probe SQL
--
-- Symptom B = Survey ended without ever inserting a row in the per-study
-- results table. Each Survey study has its own table; this section
-- generates the per-study SELECT you can run to count the shape, split
-- by deploy boundary. Aggregate-only (COUNT, no individual rows).
-- ==========================================================================
SELECT '----- §G  Per-study Symptom-B probe queries (run the emitted SQL) -----' AS section;

SELECT
    sru.position                                                         AS pos,
    ss.name                                                              AS study_name,
    CONCAT(
        'SELECT ', sru.position, ' AS pos, ', QUOTE(ss.name), ' AS study, ',
        'SUM(us.created <  ''', @deploy_ts, ''') AS sympB_before, ',
        'SUM(us.created >= ''', @deploy_ts, ''') AS sympB_after ',
        'FROM survey_unit_sessions us ',
        'JOIN survey_run_sessions rs ON rs.id = us.run_session_id ',
        'LEFT JOIN `', ss.results_table, '` r ON r.session_id = us.id ',
        'WHERE rs.run_id = ', @run_id, ' AND us.unit_id = ', ss.id, ' ',
        'AND us.ended IS NOT NULL AND r.session_id IS NULL;'
    ) AS sympB_probe
FROM survey_run_units sru
JOIN survey_units    su ON su.id = sru.unit_id
JOIN survey_studies  ss ON ss.id = su.id
WHERE sru.run_id = @run_id
ORDER BY sru.position;

-- ==========================================================================
-- §H  Final-position distribution (where do participants stop?)
--
-- Compare across cohorts: did the post-deploy cohort make it deeper into
-- the run on average? Aggregate only.
-- ==========================================================================
SELECT '----- §H  Final-position distribution by cohort -----' AS section;

SELECT
    rs.position                                                          AS pos,
    SUBSTRING(sru.description, 1, 35)                                    AS at_unit,
    su.type                                                              AS unit_type,
    SUM(rs.created <  @deploy_ts)                                        AS sessions_before,
    SUM(rs.created >= @deploy_ts)                                        AS sessions_after
FROM survey_run_sessions rs
LEFT JOIN survey_run_units sru ON sru.run_id = rs.run_id AND sru.position = rs.position
LEFT JOIN survey_units     su  ON su.id = sru.unit_id
WHERE rs.run_id = @run_id
GROUP BY pos, at_unit, unit_type
ORDER BY pos;

-- ==========================================================================
-- §J  Duplicate-cascade detection
--
-- Symptom: a participant received two ESM emails + two pushes at adjacent
-- positions seconds apart, with two Survey-start records for the same
-- ESM. Investigation 2026-05-09 — see git log around this commit. Single-
-- daemon, lock-protected execution shouldn't reproduce this under post-fix
-- code; the row shape distinguishes which path actually fired:
--
--   • All counts zero in the first query → cascade fired once;
--     duplicate is at email/push delivery layer (PHPMailer SMTP retry at
--     EmailQueue.php:254, or push subscription delivering twice).
--   • Position 127/128/129 each have dup rows but Pause(124) does NOT →
--     cascade fired twice from the same Pause anchor. Most likely path:
--     daemon was killed mid-cascade leaving Email(127) row created but
--     not ended; a subsequent user request or cron tick at position 127
--     re-entered the cascade and re-sent.
--   • Position 124 ALSO has dup rows → two independent Pause(124)
--     anchors triggered two cascades. Look at the created timestamps to
--     diagnose source (back-jump? admin action? pre-Hygiene-4 stale).
-- ==========================================================================
SELECT '----- §J  Duplicate-cascade detection (was the cascade fired twice?) -----' AS section;

-- Per-position count of run_sessions that have >1 unit-session row at
-- each ESM-cascade position today. Adjust the date if checking a
-- different firing.
SELECT sru.position, su.type, COUNT(*) AS run_sessions_with_dup_us_rows
FROM (
  SELECT us.run_session_id, su.id AS unit_id, COUNT(*) AS n
  FROM survey_unit_sessions us
  JOIN survey_run_sessions rs ON rs.id = us.run_session_id
  JOIN survey_units su ON su.id = us.unit_id
  JOIN survey_run_units sru ON sru.unit_id = us.unit_id AND sru.run_id = rs.run_id
  WHERE rs.run_id = @run_id
    AND sru.position IN (124, 127, 128, 129, 130)
    AND us.created >= @deploy_ts
  GROUP BY us.run_session_id, su.id
  HAVING n > 1
) d
JOIN survey_units su ON su.id = d.unit_id
JOIN survey_run_units sru ON sru.unit_id = d.unit_id AND sru.run_id = @run_id
GROUP BY sru.position, su.type
ORDER BY sru.position;

-- Sample run_session_ids for follow-up dump (top 10 offenders at pos 127):
SELECT 'sample run_session_ids with dup pos-127 rows (use in §J-dump)' AS note,
       us.run_session_id,
       COUNT(*) AS dup_count
FROM survey_unit_sessions us
JOIN survey_run_sessions rs ON rs.id = us.run_session_id
JOIN survey_run_units sru ON sru.unit_id = us.unit_id AND sru.run_id = rs.run_id
WHERE rs.run_id = @run_id
  AND sru.position = 127
  AND us.created >= @deploy_ts
GROUP BY us.run_session_id
HAVING dup_count > 1
ORDER BY dup_count DESC, us.run_session_id
LIMIT 10;

-- Row-by-row dump for the top-3 offender run-sessions. Reveals the
-- created/ended timestamps that distinguish:
--   • two cascades 1s apart (daemon restart mid-cascade?)
--   • two cascades minutes apart (independent retrigger)
--   • two cascades with second one missing Pause(124) anchor (the
--     scenario the per-position counts already point at)
SELECT '----- §J-dump  Per-row dump for top-3 dup offenders -----' AS section;

SELECT sru.position, su.type,
       us.id, us.run_session_id, us.created, us.ended, us.expired,
       us.queued, us.result,
       SUBSTRING(us.result_log, 1, 120) AS log
FROM survey_unit_sessions us
JOIN survey_run_sessions rs ON rs.id = us.run_session_id
JOIN survey_units su ON su.id = us.unit_id
JOIN survey_run_units sru ON sru.unit_id = us.unit_id AND sru.run_id = rs.run_id
WHERE rs.run_id = @run_id
  -- NOTE: NO @deploy_ts filter — we want to see pre-deploy Pause(122/124)
  -- rows that may have been the cascade trigger (e.g., yesterday's
  -- queued Pause that pre-Hygiene-4 left at queued=2, ended IS NOT NULL).
  AND sru.position IN (122, 123, 124, 127, 128, 129, 130)
  AND us.run_session_id IN (
      -- top-3 offenders by dup count at position 127
      SELECT t.run_session_id FROM (
        SELECT us2.run_session_id, COUNT(*) AS n
        FROM survey_unit_sessions us2
        JOIN survey_run_sessions rs2 ON rs2.id = us2.run_session_id
        JOIN survey_run_units sru2 ON sru2.unit_id = us2.unit_id AND sru2.run_id = rs2.run_id
        WHERE rs2.run_id = @run_id
          AND sru2.position = 127
          AND us2.created >= @deploy_ts
        GROUP BY us2.run_session_id
        HAVING n > 1
        ORDER BY n DESC, us2.run_session_id
        LIMIT 3
      ) t
  )
  -- Show recent week only at positions 122/123/124 to avoid
  -- multi-month history dumps; cascade positions stay unfiltered.
  AND (sru.position NOT IN (122, 123, 124) OR us.created >= DATE_SUB(NOW(), INTERVAL 7 DAY))
ORDER BY us.run_session_id, sru.position, us.id;

-- Pre-Hygiene-4 leftover detection: rows ended (ended IS NOT NULL) but
-- still queued > 0 — these are the cascade-trigger candidates. The
-- daemon's cursor picks them up because queued > 0 AND expires <= NOW(),
-- and post-fix line 247 should removeItem() them, but each iteration
-- still costs one execute() roundtrip (lock + getCurrentUnitSession).
-- If the count is high, there's a long tail of legacy debt to clean up.
SELECT '----- §J-stale  Ended-but-still-queued legacy rows (pre-Hygiene-4 debt) -----' AS section;
SELECT sru.position, su.type, COUNT(*) AS n,
       MIN(us.ended) AS earliest_ended,
       MAX(us.ended) AS latest_ended
FROM survey_unit_sessions us
JOIN survey_run_sessions rs ON rs.id = us.run_session_id
JOIN survey_units su ON su.id = us.unit_id
JOIN survey_run_units sru ON sru.unit_id = us.unit_id AND sru.run_id = rs.run_id
WHERE rs.run_id = @run_id
  AND us.ended IS NOT NULL
  AND us.queued > 0
  AND us.expires <= NOW()
GROUP BY sru.position, su.type
HAVING n > 0
ORDER BY n DESC
LIMIT 20;

-- Note: §J-orphan was originally here but removed — Push's terminal
-- state is `ended IS NULL` by design (PushMessage::getUnitSessionOutput
-- doesn't return end_session), so 'sent' Push rows show as orphans
-- under that query. That's noise, not a daemon-kill signal. The Email
-- rate-limit row would still get caught here for non-AMOR runs; on AMOR
-- check via §J-stale + §J-dump instead.

-- ==========================================================================
-- §K  Track A diagnostic: SUPERSEDED rate per (run, position)
-- ==========================================================================
-- SUPERSEDED only fires when UnitSession::create() finds a prior row for
-- the same (run_session, unit_id) with queued > 0 — i.e. a row that
-- *should* have been end()'d before the back-jump but wasn't. In clean
-- diary-loop / SkipBackward flow, the prior row is ENDED (queued=0) and
-- the supersede UPDATE matches nothing. Any non-zero count here is a
-- "this shouldn't happen" diagnostic: race, partial-state recovery, or
-- admin force-to bypassing end(). Watch this number trend after
-- v0.26.0 ships; ideally it's tiny and shrinks. Only meaningful for
-- post-A2 rows (state is NULL pre-047).
SELECT '----- §K  SUPERSEDED rate per (run_id, position) — Track A diagnostic -----' AS section;
SELECT rs.run_id, sru.position, su.type, COUNT(*) AS n_superseded,
       MIN(us.created) AS earliest, MAX(us.created) AS latest
FROM survey_unit_sessions us
JOIN survey_run_sessions rs ON rs.id = us.run_session_id
JOIN survey_units su ON su.id = us.unit_id
LEFT JOIN survey_run_units sru ON sru.id = us.run_unit_id
WHERE us.state = 'SUPERSEDED'
GROUP BY rs.run_id, sru.position, su.type
HAVING n_superseded > 0
ORDER BY n_superseded DESC
LIMIT 30;

-- ==========================================================================
-- §I  Read-the-output guide
-- ==========================================================================
SELECT '----- §I  How to read the output -----' AS section;
SELECT 'See tests/e2e/prod_release_compare.sql header + EXPIRY_AUDIT.md §8 (recommended fix order). Quick guide:' AS guide
UNION ALL SELECT '  §B orphans_after / pct_after on positions 129/133/137 should be near zero.'
UNION ALL SELECT '  §C pct_after on positions 129/133/137 should be near zero (X-rule no longer fires unconditionally).'
UNION ALL SELECT '  §C pct_after on position 113/148/153 (Screening, X=2040) depends on whether you added Y/Z — see release notes.'
UNION ALL SELECT '  §D sympA_after columns near zero across the board.'
UNION ALL SELECT '  §E pct_after substantially lower than pct_before (Hygiene 4 reset queued on end()).'
UNION ALL SELECT '  §F: new results like "ended_by_queue_rse"/"manual_admin_push" appearing in count_after but not count_before is Hygiene 5 working.'
UNION ALL SELECT '  §G: paste each emitted probe SQL; sympB_after columns should be ≤ sympB_before / cohort-size ratio.'
UNION ALL SELECT '  §H: post-deploy participants should reach later positions on average (drop-through reduced).'
UNION ALL SELECT '  §J: any non-zero count is a duplicate cascade. Email(127)+Push(128) dup with Pause(124) clean = one anchor produced multiple cascades.'
UNION ALL SELECT '  §J-dump: row-level evidence for the top-3 offenders — read created timestamps to see cascade-spacing.'
UNION ALL SELECT '  §J-stale: count of pre-Hygiene-4 rows (ended IS NOT NULL AND queued > 0) — these are still in the daemon cursor; high N means legacy debt the cron has to chew through every tick.'
UNION ALL SELECT '  §K: any non-zero count is a row that was queued > 0 when a sibling for the same unit_id was created — diagnostic for race / partial-state / admin force-to bypassing end(). Watch trend across releases; ideally shrinks toward zero.';
