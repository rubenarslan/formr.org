-- Diagnostic queries for the AMOR drop investigation.
-- Read-only. Safe to run on prod.
--
-- Adjust the @run_name and @days_back at the top, then run via:
--
--   docker exec -i formr_db mariadb -u <user> -p<pwd> <db> \
--     < tests/e2e/prod_expiry_audit.sql
--
-- Each block prints a section header so you can tell sections apart in the
-- raw output. The section names match the candidate causes from
-- tests/e2e/EXPIRY_AUDIT.md §7 (the "AMOR-specific risk surface").

SET @run_name := 'amor-hauptstudie';
SET @days_back := 14;

SELECT id INTO @run_id FROM survey_runs WHERE name = @run_name;
SELECT @run_id AS run_id, @run_name AS run_name, @days_back AS days_back;

-- ==========================================================================
-- §0  Inventory — total unit-sessions per run-unit, broken down by terminal shape
-- ==========================================================================
SELECT '----- §0  Inventory: terminal-shape counts per unit -----' AS section;

SELECT
    sru.position                                                        AS pos,
    su.type                                                             AS unit_type,
    SUBSTRING(sru.description, 1, 40)                                   AS description,
    SUM(us.ended  IS NULL AND us.expired IS NULL AND us.queued = -9)    AS A_queued_neg9,    -- U13 supersede orphan
    SUM(us.ended  IS NULL AND us.expired IS NULL AND us.queued =  2)    AS A_queued_two,     -- queue-pending forever
    SUM(us.ended  IS NULL AND us.expired IS NULL AND us.queued =  0)    AS A_queued_zero,    -- end() asymmetry / unfinished
    SUM(us.ended  IS NOT NULL AND us.queued != 0)                       AS ended_with_q,     -- §4 asymmetry
    SUM(us.ended  IS NOT NULL)                                          AS total_ended,
    SUM(us.expired IS NOT NULL)                                         AS total_expired,
    COUNT(*)                                                            AS total
FROM survey_unit_sessions us
JOIN survey_run_sessions rs ON rs.id = us.run_session_id
JOIN survey_units        su ON su.id = us.unit_id
LEFT JOIN survey_run_units sru ON sru.unit_id = us.unit_id AND sru.run_id = rs.run_id
WHERE rs.run_id = @run_id
  AND rs.created >= DATE_SUB(NOW(), INTERVAL @days_back DAY)
GROUP BY pos, unit_type, description
ORDER BY pos;

-- ==========================================================================
-- §1  Symptom A on Pauses (U13: back-jump supersede)
--     queued=-9, ended IS NULL, expired IS NULL — the smoking gun.
-- ==========================================================================
SELECT '----- §1  Symptom A on Pauses (U13: queued=-9, NULL/NULL) — counts -----' AS section;

SELECT
    sru.position                              AS pos,
    SUBSTRING(sru.description, 1, 60)         AS description,
    COUNT(*)                                  AS orphan_count,
    MIN(rs.created)                           AS first_seen,
    MAX(rs.created)                           AS last_seen
FROM survey_unit_sessions us
JOIN survey_run_sessions rs ON rs.id = us.run_session_id
JOIN survey_units        su ON su.id = us.unit_id
LEFT JOIN survey_run_units sru ON sru.unit_id = us.unit_id AND sru.run_id = rs.run_id
WHERE rs.run_id = @run_id
  AND rs.created >= DATE_SUB(NOW(), INTERVAL @days_back DAY)
  AND su.type = 'Pause'
  AND us.queued = -9
  AND us.ended  IS NULL
  AND us.expired IS NULL
GROUP BY pos, description
ORDER BY orphan_count DESC;

SELECT '----- §1b Symptom A on Pauses — recent samples (most recent 30) -----' AS section;

SELECT
    us.id                                  AS us_id,
    sru.position                           AS pos,
    SUBSTRING(sru.description, 1, 40)      AS description,
    rs.session                             AS run_session,
    us.created                             AS us_created,
    -- Show the unit-session row that *replaced* this one (the back-jump's
    -- new unit-session in the same run-session, created after this one).
    (SELECT us2.id   FROM survey_unit_sessions us2
       WHERE us2.run_session_id = us.run_session_id AND us2.id > us.id
       ORDER BY us2.id ASC LIMIT 1)        AS next_us_id,
    (SELECT su2.type FROM survey_unit_sessions us2
       JOIN survey_units su2 ON su2.id = us2.unit_id
       WHERE us2.run_session_id = us.run_session_id AND us2.id > us.id
       ORDER BY us2.id ASC LIMIT 1)        AS next_us_type,
    (SELECT us2.created FROM survey_unit_sessions us2
       WHERE us2.run_session_id = us.run_session_id AND us2.id > us.id
       ORDER BY us2.id ASC LIMIT 1)        AS next_us_created
FROM survey_unit_sessions us
JOIN survey_run_sessions rs ON rs.id = us.run_session_id
JOIN survey_units        su ON su.id = us.unit_id
LEFT JOIN survey_run_units sru ON sru.unit_id = us.unit_id AND sru.run_id = rs.run_id
WHERE rs.run_id = @run_id
  AND rs.created >= DATE_SUB(NOW(), INTERVAL @days_back DAY)
  AND su.type = 'Pause'
  AND us.queued = -9
  AND us.ended  IS NULL
  AND us.expired IS NULL
ORDER BY us.id DESC
LIMIT 30;

-- ==========================================================================
-- §2  Symptom A on Surveys
-- ==========================================================================
SELECT '----- §2  Symptom A on Surveys (queued=-9 OR queued=2 with NULL/NULL) -----' AS section;

SELECT
    sru.position                              AS pos,
    SUBSTRING(sru.description, 1, 40)         AS description,
    us.queued                                 AS queued,
    COUNT(*)                                  AS cnt
FROM survey_unit_sessions us
JOIN survey_run_sessions rs ON rs.id = us.run_session_id
JOIN survey_units        su ON su.id = us.unit_id
LEFT JOIN survey_run_units sru ON sru.unit_id = us.unit_id AND sru.run_id = rs.run_id
WHERE rs.run_id = @run_id
  AND rs.created >= DATE_SUB(NOW(), INTERVAL @days_back DAY)
  AND su.type = 'Survey'
  AND us.ended  IS NULL
  AND us.expired IS NULL
  AND us.queued IN (-9, 2)
GROUP BY pos, description, us.queued
ORDER BY pos, us.queued;

-- ==========================================================================
-- §3  Pause "instant expire" — U12 / past wall-clock relative_to
--     Pauses where expired - created < 30 seconds: the cron picked up the
--     unit-session within seconds of creation, which means the relative_to
--     R expression yielded a past timestamp.
-- ==========================================================================
SELECT '----- §3  U12: Pauses that expired within 30s of creation -----' AS section;

SELECT
    sru.position                                                AS pos,
    SUBSTRING(sru.description, 1, 40)                           AS description,
    SUBSTRING(REPLACE(sp.relative_to, '\n', ' '), 1, 80)        AS relative_to,
    sp.wait_minutes                                             AS wait_minutes,
    COUNT(*)                                                    AS cnt,
    MIN(TIMESTAMPDIFF(SECOND, us.created, us.expired))          AS min_secs_to_expire,
    AVG(TIMESTAMPDIFF(SECOND, us.created, us.expired))          AS avg_secs_to_expire
FROM survey_unit_sessions us
JOIN survey_run_sessions rs ON rs.id = us.run_session_id
JOIN survey_units        su ON su.id = us.unit_id
LEFT JOIN survey_run_units sru ON sru.unit_id = us.unit_id AND sru.run_id = rs.run_id
LEFT JOIN survey_pauses    sp  ON sp.id  = us.unit_id
WHERE rs.run_id = @run_id
  AND rs.created >= DATE_SUB(NOW(), INTERVAL @days_back DAY)
  AND su.type = 'Pause'
  AND us.expired IS NOT NULL
  AND TIMESTAMPDIFF(SECOND, us.created, us.expired) < 30
GROUP BY pos, description, relative_to, sp.wait_minutes
ORDER BY cnt DESC;

-- ==========================================================================
-- §4  end()/queued asymmetry — `ended IS NOT NULL AND queued != 0`
--     If many rows have this shape, the queue's removeItem() isn't running
--     (i.e., end() was called outside the queue daemon — admin action,
--     forceTo, dangling-end, etc.) and §4 of the audit applies.
-- ==========================================================================
SELECT '----- §4  end()/queued asymmetry: ended IS NOT NULL AND queued != 0 -----' AS section;

SELECT
    sru.position                              AS pos,
    su.type                                   AS unit_type,
    SUBSTRING(sru.description, 1, 40)         AS description,
    us.queued                                 AS queued,
    us.result                                 AS result,
    COUNT(*)                                  AS cnt
FROM survey_unit_sessions us
JOIN survey_run_sessions rs ON rs.id = us.run_session_id
JOIN survey_units        su ON su.id = us.unit_id
LEFT JOIN survey_run_units sru ON sru.unit_id = us.unit_id AND sru.run_id = rs.run_id
WHERE rs.run_id = @run_id
  AND rs.created >= DATE_SUB(NOW(), INTERVAL @days_back DAY)
  AND us.ended IS NOT NULL
  AND us.queued != 0
GROUP BY pos, unit_type, description, us.queued, us.result
ORDER BY cnt DESC
LIMIT 30;

-- ==========================================================================
-- §5  Symptom B detection (ended Survey with no results-row)
--     Each Survey has its own results_table; this query EMITS a per-study
--     SELECT statement you can copy + run. Each emitted statement counts
--     ended Survey unit-sessions whose study row is missing.
-- ==========================================================================
SELECT '----- §5  Symptom B per-study probe queries (run the emitted SQL) -----' AS section;

SELECT
    sru.position AS pos,
    su.id        AS study_id,
    ss.name      AS study_name,
    CONCAT(
        'SELECT ', sru.position, ' AS pos, ', su.id, ' AS study_id, ',
        QUOTE(ss.name), ' AS study, COUNT(*) AS symptom_B_count ',
        'FROM survey_unit_sessions us ',
        'JOIN survey_run_sessions rs ON rs.id = us.run_session_id ',
        'LEFT JOIN `', ss.results_table, '` r ON r.session_id = us.id ',
        'WHERE rs.run_id = ', @run_id, ' AND us.unit_id = ', su.id, ' ',
        'AND rs.created >= DATE_SUB(NOW(), INTERVAL ', @days_back, ' DAY) ',
        'AND us.ended IS NOT NULL AND r.session_id IS NULL;'
    ) AS symptom_B_probe
FROM survey_units      su
JOIN survey_studies    ss  ON ss.id = su.id
JOIN survey_run_units  sru ON sru.unit_id = su.id
WHERE sru.run_id = @run_id
ORDER BY sru.position;

-- ==========================================================================
-- §6  Where do participants stop?
--     If a position has many participants stuck on it but few completing,
--     that position is a drop-magnet.
-- ==========================================================================
SELECT '----- §6  Final-position distribution -----' AS section;

SELECT
    rs.position                                       AS pos,
    SUBSTRING(sru.description, 1, 40)                 AS at_unit,
    su.type                                           AS unit_type,
    COUNT(DISTINCT rs.id)                             AS run_sessions_here,
    SUM(rs.ended IS NOT NULL)                         AS run_session_ended_here
FROM survey_run_sessions rs
LEFT JOIN survey_run_units sru ON sru.run_id = rs.run_id AND sru.position = rs.position
LEFT JOIN survey_units     su  ON su.id = sru.unit_id
WHERE rs.run_id = @run_id
  AND rs.created >= DATE_SUB(NOW(), INTERVAL @days_back DAY)
GROUP BY pos, at_unit, unit_type
ORDER BY pos;

-- ==========================================================================
-- §7  AMOR sanity: confirm "all access windows are off" hypothesis
-- ==========================================================================
SELECT '----- §7  Survey expire settings (sanity) -----' AS section;

SELECT
    sru.position                              AS pos,
    ss.name                                   AS study_name,
    ss.expire_invitation_after                AS X,
    ss.expire_invitation_grace                AS Y,
    ss.expire_after                           AS Z
FROM survey_run_units sru
JOIN survey_units    su ON su.id = sru.unit_id
JOIN survey_studies  ss ON ss.id = su.id
WHERE sru.run_id = @run_id
ORDER BY sru.position;

-- ==========================================================================
-- §8  Supersede-mechanism diagnostic (dedup'd)
--     For each Symptom-A orphan, find the IMMEDIATELY-NEXT unit-session in
--     the same run-session by id (not by position — the previous join-on-
--     position multiplied rows when a unit_id sat at multiple positions,
--     e.g. AMOR's ESM Survey study at 129/133/137).
--
--     gap_seconds = 0 across the board means the supersede happens inside
--     a single cron-tick moveOn cascade (NOT across days from a back-jump).
--     gap_seconds clustering in hours/days would point to the back-jump
--     pathway (e.g. SkipBackward at AMOR pos 143 → runTo 122).
-- ==========================================================================
SELECT '----- §8  Symptom-A orphan → next unit-session in same run-session (dedup) -----' AS section;

WITH orphans AS (
    SELECT us.id        AS orphan_id,
           us.run_session_id,
           us.unit_id,
           us.created
    FROM survey_unit_sessions us
    JOIN survey_run_sessions  rs ON rs.id = us.run_session_id
    JOIN survey_units         su ON su.id = us.unit_id
    WHERE rs.run_id = @run_id
      AND rs.created >= DATE_SUB(NOW(), INTERVAL @days_back DAY)
      AND us.queued  = -9
      AND us.ended   IS NULL
      AND us.expired IS NULL
      AND su.type IN ('Pause', 'Survey')
),
next_us AS (
    SELECT
        o.orphan_id,
        (SELECT MIN(us2.id)
           FROM survey_unit_sessions us2
          WHERE us2.run_session_id = o.run_session_id
            AND us2.id > o.orphan_id) AS next_us_id
    FROM orphans o
)
SELECT
    o.orphan_id,
    o_su.type                                                       AS orphan_type,
    -- Show all positions this unit_id appears at in the run, comma-joined,
    -- so we don't multiply rows when a study sits at multiple positions.
    (SELECT GROUP_CONCAT(DISTINCT sru.position ORDER BY sru.position)
       FROM survey_run_units sru
      WHERE sru.run_id = @run_id AND sru.unit_id = o.unit_id)        AS orphan_positions,
    n.next_us_id,
    r_su.type                                                        AS replacer_type,
    (SELECT GROUP_CONCAT(DISTINCT sru.position ORDER BY sru.position)
       FROM survey_run_units sru
      WHERE sru.run_id = @run_id AND sru.unit_id = r_us.unit_id)     AS replacer_positions,
    TIMESTAMPDIFF(SECOND, o.created, r_us.created)                   AS gap_seconds,
    o.created                                                        AS orphan_created
FROM orphans o
JOIN next_us n  ON n.orphan_id = o.orphan_id
JOIN survey_units o_su ON o_su.id = o.unit_id
LEFT JOIN survey_unit_sessions r_us ON r_us.id = n.next_us_id
LEFT JOIN survey_units         r_su ON r_su.id = r_us.unit_id
ORDER BY gap_seconds, o.orphan_id DESC
LIMIT 100;

-- ==========================================================================
-- §9  Single-run-session walkthrough — for use after §8 picks out a
--     candidate. Shows the full unit-session history of one participant's
--     run-session in id order, so you can eyeball the cron-cascade chain.
--
--     Pick a `run_session` token from §1b (the recent-samples block) or
--     from §8's orphan rows by joining back to survey_run_sessions, then
--     paste it as @session_token below and re-run this section.
-- ==========================================================================
SELECT '----- §9  Single-run-session walkthrough (set @session_token first) -----' AS section;

-- Default to one of the more interesting run-sessions surfaced by §1b
-- on the dev environment (the same-second Pause→Pause case). Override on
-- prod by editing this line before running.
SET @session_token := 'n7kRqPqQjd6kNidRQiOQ9z-02yQUV5rWCph3jMUfVvxaf7qOubA-M0D3kriUKc3u';

SELECT
    us.id,
    su.type,
    -- A unit_id can sit at multiple positions in one run; concatenate so
    -- the row count matches the unit-session count.
    (SELECT GROUP_CONCAT(DISTINCT sru.position ORDER BY sru.position)
       FROM survey_run_units sru
      WHERE sru.run_id = @run_id AND sru.unit_id = us.unit_id)        AS positions,
    SUBSTRING((SELECT GROUP_CONCAT(DISTINCT sru.description SEPARATOR ' | ')
       FROM survey_run_units sru
      WHERE sru.run_id = @run_id AND sru.unit_id = us.unit_id), 1, 50) AS descriptions,
    us.created,
    us.queued,
    us.ended,
    us.expired,
    us.result
FROM survey_unit_sessions us
JOIN survey_run_sessions   rs ON rs.id = us.run_session_id
JOIN survey_units          su ON su.id = us.unit_id
WHERE rs.session = @session_token
  AND rs.run_id  = @run_id
ORDER BY us.id;
