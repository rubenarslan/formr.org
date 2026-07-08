#!/usr/bin/php
<?php
/**
 * Heal duplicated Pause/Wait unit-sessions (v0.27.0).
 *
 * Wait extends Pause (same UnitSession::create() path, same non-atomic
 * iteration + non-unique idx_run_unit_iter), so both are in scope by
 * default; --all-units widens to every unit type. Decision logic lives in
 * the unit-tested Services/DuplicatePauseHealer::classify().
 *
 * SYMPTOM: a participant has more than one unit-session row for the SAME
 * pause placement — "repeated pause 201" — and one of the copies re-arms
 * the deadline (e.g. the monthly-gate rule recomputes "first Monday of
 * next month" a month later), stranding the participant.
 *
 * MECHANISM: UnitSession::create() derives `iteration` as
 * `SELECT MAX(iteration)+1` THEN INSERT — a non-atomic read-modify-write —
 * and patch 047's `idx_run_unit_iter (run_session_id, run_unit_id,
 * iteration)` is a plain KEY, not UNIQUE. Two requests racing (the
 * pre-lock stale-position race, cf. v0.25.7) both read the same MAX and
 * both INSERT the SAME iteration, so the duplicate is identifiable by an
 * exact tuple collision. Legitimate SkipBackward / loop re-entries always
 * get DISTINCT iterations (they are serialized behind the run-session
 * lock), so this detector is loop-safe: it never flags an ESM re-run.
 *
 * SCOPE OF THE AUTO-HEAL (safe subset only):
 *   - Detects duplicates as >1 row sharing (run_session_id, placement,
 *     iteration), placement = run_unit_id (or unit_id for legacy NULL rows).
 *   - Auto-supersedes ONLY spurious rows that are still LIVE (never ended
 *     -> never cascaded -> zero side effects by construction), keeping the
 *     canonical sibling. Supersede = queued=-9, state=SUPERSEDED: the
 *     engine's own primitive (UnitSession::create), non-destructive and
 *     auditable. NEVER deletes.
 *   - Repoints current_unit_session_id / position off a superseded row only
 *     to an unambiguous safe target (the live canonical sibling, or a single
 *     live downstream frontier). Anything ambiguous is left for review.
 *   - Clusters with >=2 TERMINAL rows (both cascaded -> possibly real
 *     Email/Push sends or answered items) are REPORTED with side-effect
 *     evidence and never auto-touched.
 *
 * SAFETY: defaults to DRY-RUN. Pass --apply to write. Idempotent.
 *
 * Usage:
 *   php bin/heal_duplicate_pause_sessions.php [--apply] [--run-name=NAME|--run-id=N] [--all-units]
 */
require_once dirname(__FILE__) . '/../setup.php';

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line\n");
}

$apply     = in_array('--apply', $argv, true);
$all_units = in_array('--all-units', $argv, true);
$fail_on_blocking = in_array('--fail-on-blocking', $argv, true); // exit 2 if the UNIQUE key would still be blocked (deploy gate)
$run_id    = null;
$rs_id     = null;   // canary: scope to a single run_session (one participant)
foreach ($argv as $arg) {
    if (strpos($arg, '--run-session=') === 0) {
        $rs_id = (int) substr($arg, strlen('--run-session='));
    } elseif (strpos($arg, '--run-id=') === 0) {
        $run_id = (int) substr($arg, strlen('--run-id='));
    } elseif (strpos($arg, '--run-name=') === 0) {
        $name = substr($arg, strlen('--run-name='));
        $row = DB::getInstance()->execute('SELECT id FROM survey_runs WHERE name = :n', ['n' => $name], false, true);
        if (!$row) {
            die("No run named " . var_export($name, true) . "\n");
        }
        $run_id = (int) $row['id'];
    }
}

$db = DB::getInstance();
// Wait extends Pause: same create() path, same duplicate exposure -> both in scope.
$type_filter = $all_units ? '' : "AND su.type IN ('Pause','Wait')";
$scope_filter = '';
$scope_params = [];
if ($run_id)  { $scope_filter .= ' AND rs.run_id = :run_id';           $scope_params['run_id'] = $run_id; }
if ($rs_id)   { $scope_filter .= ' AND us.run_session_id = :rs_id';     $scope_params['rs_id']  = $rs_id; }

echo "== heal_duplicate_pause_sessions ==\n";
echo ($apply ? "MODE: APPLY (will write)\n" : "MODE: dry-run (no writes; pass --apply to heal)\n");
echo 'SCOPE: ' . ($rs_id ? "run_session={$rs_id}" : ($run_id ? "run_id={$run_id}" : 'ALL runs'))
   . ($all_units ? ', ALL unit types' : ', Pause/Wait units') . "\n\n";

// Track A columns (patch 047) must exist. On a pre-047 instance (a big
// version jump where 047 is applied in the same batch) there are no
// run_unit_id-keyed duplicates to heal yet — no-op cleanly so the
// update_formr.sh deploy gate doesn't misfire on a missing column.
$hasCol = $db->execute("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'survey_unit_sessions'
      AND COLUMN_NAME = 'run_unit_id'", [], true);
if ((int) $hasCol === 0) {
    echo "run_unit_id column absent (pre-047) — nothing to heal.\n";
    exit(0);
}

// --- Detection: same-(placement, iteration) collisions -------------------
// Primary pass keys on run_unit_id; legacy pass on unit_id where
// run_unit_id IS NULL (pre-047 / 048-left-NULL multi-position rows).
$clusters = [];
$primary = $db->execute("
    SELECT us.run_session_id AS rsid, us.run_unit_id AS placement, us.iteration AS iter
    FROM survey_unit_sessions us
    JOIN survey_run_sessions rs ON rs.id = us.run_session_id
    JOIN survey_units su ON su.id = us.unit_id
    WHERE us.run_unit_id IS NOT NULL {$type_filter} {$scope_filter}
    GROUP BY us.run_session_id, us.run_unit_id, us.iteration
    HAVING COUNT(*) > 1
", $scope_params);
foreach ($primary as $c) {
    $clusters[] = ['rsid' => (int) $c['rsid'], 'placement' => (int) $c['placement'],
                   'iter' => $c['iter'], 'legacy' => false];
}
$legacy = $db->execute("
    SELECT us.run_session_id AS rsid, us.unit_id AS placement, us.iteration AS iter
    FROM survey_unit_sessions us
    JOIN survey_run_sessions rs ON rs.id = us.run_session_id
    JOIN survey_units su ON su.id = us.unit_id
    WHERE us.run_unit_id IS NULL {$type_filter} {$scope_filter}
    GROUP BY us.run_session_id, us.unit_id, us.iteration
    HAVING COUNT(*) > 1
", $scope_params);
foreach ($legacy as $c) {
    $clusters[] = ['rsid' => (int) $c['rsid'], 'placement' => (int) $c['placement'],
                   'iter' => $c['iter'], 'legacy' => true];
}

if (!$clusters) {
    echo "No same-iteration duplicate clusters found.\n\n";
}

/** Cascade-footprint proxy: participant-visible side effects on sessions of
 *  this run_session created in [since, until). Cascades carry no parent
 *  pointer, so this is a windowed run-session-level signal, not exact
 *  attribution — evidence for a human, never an auto-heal gate. */
function side_effect_footprint($rsid, $since, $until = null) {
    $db = DB::getInstance();
    $win = 'AND u.created >= :since' . ($until ? ' AND u.created < :until' : '');
    $p = ['rsid' => $rsid, 'since' => $since] + ($until ? ['until' => $until] : []);
    $emails = (int) $db->execute("SELECT COUNT(*) FROM survey_email_log el
        JOIN survey_unit_sessions u ON u.id = el.session_id
        WHERE u.run_session_id = :rsid AND el.sent IS NOT NULL {$win}", $p, true);
    $pushes = (int) $db->execute("SELECT COUNT(*) FROM push_logs pl
        JOIN survey_unit_sessions u ON u.id = pl.unit_session_id
        WHERE u.run_session_id = :rsid {$win}", $p, true);
    $items  = (int) $db->execute("SELECT COUNT(*) FROM survey_items_display d
        JOIN survey_unit_sessions u ON u.id = d.session_id
        WHERE u.run_session_id = :rsid AND d.answered IS NOT NULL {$win}", $p, true);
    return ['emails_sent' => $emails, 'push_log_rows' => $pushes, 'items_answered' => $items];
}

/** Single live session strictly downstream of $pos in this run_session,
 *  excluding the cluster rows — the frontier to repoint a re-park to.
 *  Returns [id, position] iff exactly one exists (else null = ambiguous). */
function live_downstream_frontier($rsid, $pos, array $exclude_ids) {
    if ($pos === null) return null;
    $db = DB::getInstance();
    $ids = implode(',', array_map('intval', $exclude_ids)) ?: '0';
    $rows = $db->execute("SELECT us.id, sru.position FROM survey_unit_sessions us
        JOIN survey_run_units sru ON sru.id = us.run_unit_id
        WHERE us.run_session_id = :rsid AND us.ended IS NULL AND us.expired IS NULL
          AND us.queued <> -9 AND sru.position > :pos AND us.id NOT IN ({$ids})
        ORDER BY sru.position DESC", ['rsid' => $rsid, 'pos' => $pos]);
    return count($rows) === 1 ? ['id' => (int) $rows[0]['id'], 'position' => $rows[0]['position']] : null;
}

/** Does this unit-session already carry a participant-visible side effect?
 *  For Pause/Wait this is always false (they hold no answers/sends). But a
 *  Survey accrues survey_items_display answers, and a Shuffle writes a
 *  `shuffle` group assignment, BEFORE the session ends — so a spurious row
 *  we classified as "live" (never ended) is not necessarily inert for those
 *  types. Gate the auto-supersede on this so --all-units stays safe. */
function row_has_side_effects($usid) {
    $n = (int) DB::getInstance()->execute(
        "SELECT
           (SELECT COUNT(*) FROM survey_items_display WHERE session_id = :a AND (answered IS NOT NULL OR saved IS NOT NULL))
         + (SELECT COUNT(*) FROM survey_email_log     WHERE session_id = :b AND sent IS NOT NULL)
         + (SELECT COUNT(*) FROM push_logs            WHERE unit_session_id = :c)
         + (SELECT COUNT(*) FROM shuffle              WHERE session_id = :d) AS n",
        ['a' => $usid, 'b' => $usid, 'c' => $usid, 'd' => $usid], true);
    return $n > 0;
}

$SUP = UnitSessionQueue::QUEUED_SUPERCEDED;   // -9
$plan_supersede = [];   // rows to flip queued/state
$plan_repoint   = [];   // run_session pointer moves
$reviews        = [];   // clusters needing a human
$already_healed = 0;

foreach ($clusters as $cl) {
    if ($cl['legacy']) {
        $rows = $db->execute("SELECT us.id, us.created, us.expires, us.ended, us.expired,
                us.queued, us.state, us.run_unit_id, us.unit_id, us.iteration,
                sru.position AS placement_position, rs.position AS rs_position,
                (rs.current_unit_session_id = us.id) AS is_current
            FROM survey_unit_sessions us
            JOIN survey_run_sessions rs ON rs.id = us.run_session_id
            LEFT JOIN survey_run_units sru ON sru.run_id = rs.run_id
                 AND sru.unit_id = us.unit_id AND sru.position = rs.position
            WHERE us.run_session_id = :rsid AND us.run_unit_id IS NULL
              AND us.unit_id = :placement AND us.iteration " . ($cl['iter'] === null ? 'IS NULL' : '= :iter') . "
            ORDER BY us.created ASC, us.id ASC",
            ['rsid' => $cl['rsid'], 'placement' => $cl['placement']] + ($cl['iter'] === null ? [] : ['iter' => $cl['iter']]));
    } else {
        $rows = $db->execute("SELECT us.id, us.created, us.expires, us.ended, us.expired,
                us.queued, us.state, us.run_unit_id, us.unit_id, us.iteration,
                sru.position AS placement_position, rs.position AS rs_position,
                (rs.current_unit_session_id = us.id) AS is_current
            FROM survey_unit_sessions us
            JOIN survey_run_sessions rs ON rs.id = us.run_session_id
            LEFT JOIN survey_run_units sru ON sru.id = us.run_unit_id
            WHERE us.run_session_id = :rsid AND us.run_unit_id = :placement
              AND us.iteration " . ($cl['iter'] === null ? 'IS NULL' : '= :iter') . "
            ORDER BY us.created ASC, us.id ASC",
            ['rsid' => $cl['rsid'], 'placement' => $cl['placement']] + ($cl['iter'] === null ? [] : ['iter' => $cl['iter']]));
    }

    $considered = array_values(array_filter($rows, fn($r) => (int) $r['queued'] !== $SUP));
    if (count($considered) <= 1) { $already_healed++; continue; }

    $by_id = [];
    foreach ($considered as $r) { $by_id[(int) $r['id']] = $r; }

    // All the risky decisioning lives in the (unit-tested) classifier.
    $decision = DuplicatePauseHealer::classify(
        $considered, $cl['legacy'],
        fn($pos, $excl) => live_downstream_frontier($cl['rsid'], $pos, $excl)
    );

    if ($decision['action'] === 'review') {
        $since = min(array_map(fn($r) => $r['created'], $considered));
        $reviews[] = ['cl' => $cl, 'reason' => $decision['reason'],
                      'rows' => $considered, 'evidence' => side_effect_footprint($cl['rsid'], $since)];
        continue;
    }
    if ($decision['action'] !== 'heal') { continue; }

    // Safety net for non-Pause/Wait types: never supersede a "live" row that
    // already produced a footprint (Survey answers, Shuffle assignment, a
    // send). No-op for Pause/Wait. Hands the whole cluster to a human.
    $dirty = array_values(array_filter($decision['supersede_ids'], 'row_has_side_effects'));
    if ($dirty) {
        $since = min(array_map(fn($r) => $r['created'], $considered));
        $reviews[] = ['cl' => $cl,
            'reason' => 'live spurious row(s) ' . implode(',', $dirty) . ' carry side effects (answers/assignment/send) — review',
            'rows' => $considered, 'evidence' => side_effect_footprint($cl['rsid'], $since)];
        continue;
    }

    foreach ($decision['supersede_ids'] as $sid) {
        $sp = $by_id[$sid];
        $plan_supersede[] = ['id' => $sid, 'rsid' => $cl['rsid'],
                             'placement' => $cl['placement'], 'iter' => $cl['iter'],
                             'expires' => $sp['expires'], 'created' => $sp['created']];
    }
    if ($decision['repoint'] !== null) {
        $plan_repoint[] = ['rsid' => $cl['rsid'], 'cid' => $decision['repoint']['cid'],
                           'pos' => $decision['repoint']['pos']];
    }
}

// --- Report --------------------------------------------------------------
echo "Clusters found: " . count($clusters)
   . " | already-healed (extras already superseded): {$already_healed}\n";
echo "Auto-heal plan: " . count($plan_supersede) . " row(s) to supersede, "
   . count($plan_repoint) . " pointer repoint(s).\n\n";

if ($plan_supersede) {
    echo "-- SUPERSEDE (queued=-9, state=SUPERSEDED) — spurious live duplicates --\n";
    foreach ($plan_supersede as $s) {
        echo "  us {$s['id']}  run_session {$s['rsid']}  placement {$s['placement']}"
           . "  iter " . var_export($s['iter'], true)
           . "  created {$s['created']}  expires " . var_export($s['expires'], true) . "\n";
    }
    echo "\n";
}
if ($plan_repoint) {
    echo "-- REPOINT current_unit_session_id/position to the canonical target --\n";
    foreach ($plan_repoint as $r) {
        echo "  run_session {$r['rsid']}  ->  current_unit_session_id={$r['cid']}, position={$r['pos']}\n";
    }
    echo "\n";
}
if ($reviews) {
    echo "-- MANUAL REVIEW (not auto-touched) --\n";
    foreach ($reviews as $rv) {
        $c = $rv['cl'];
        echo "  run_session {$c['rsid']}  placement " . ($c['legacy'] ? "unit {$c['placement']} (legacy)" : $c['placement'])
           . "  iter " . var_export($c['iter'], true) . "\n";
        echo "     reason: {$rv['reason']}\n";
        $e = $rv['evidence'];
        echo "     side-effects since first arrival: {$e['emails_sent']} email(s) sent, "
           . "{$e['push_log_rows']} push-log row(s), {$e['items_answered']} item(s) answered\n";
        foreach ($rv['rows'] as $r) {
            echo "       us {$r['id']}  created {$r['created']}  expires " . var_export($r['expires'], true)
               . "  ended " . var_export($r['ended'], true) . "  queued {$r['queued']}"
               . ((int) $r['is_current'] === 1 ? '  <= current' : '') . "\n";
        }
    }
    echo "\n";
}

// --- Apply ---------------------------------------------------------------
if ($apply && ($plan_supersede || $plan_repoint)) {
    $db->beginTransaction();
    try {
        foreach ($plan_supersede as $s) {
            // Also NULL iteration: the row is kept for audit (queued=-9,
            // SUPERSEDED) but its (run_session_id, run_unit_id, iteration)
            // tuple is freed so the UNIQUE key in patch 049 can be added.
            // MySQL UNIQUE permits multiple NULLs; MAX(iteration) ignores
            // NULL, so create()'s next-iteration count is unaffected.
            $db->exec("UPDATE `survey_unit_sessions`
                       SET `queued` = :sup, `state` = :st, `iteration` = NULL
                       WHERE `id` = :id AND `queued` > 0",
                ['sup' => $SUP, 'st' => UnitSessionQueue::STATE_SUPERSEDED, 'id' => $s['id']]);
        }
        foreach ($plan_repoint as $r) {
            $db->exec("UPDATE `survey_run_sessions` SET `current_unit_session_id` = :cid, `position` = :pos
                       WHERE `id` = :rsid",
                ['cid' => $r['cid'], 'pos' => $r['pos'], 'rsid' => $r['rsid']]);
        }
        $db->commit();
        echo "APPLIED: superseded " . count($plan_supersede) . " row(s), repointed "
           . count($plan_repoint) . " run session(s).\n\n";
    } catch (Exception $e) {
        $db->rollBack();
        echo "ERROR — rolled back, nothing changed: " . $e->getMessage() . "\n";
        exit(1);
    }
} elseif (!$apply && ($plan_supersede || $plan_repoint)) {
    echo "Dry-run: pass --apply to perform the above.\n\n";
}

// --- Precondition for the permanent fix (UNIQUE key) ---------------------
// Count tuples that would BLOCK `ADD UNIQUE (run_session_id, run_unit_id,
// iteration)` — i.e. any collision among rows where BOTH cols are non-NULL
// (NULLs are permitted to repeat). Superseded rows healed above have
// iteration=NULL and drop out; what remains are review clusters a human
// must resolve before the migration.
$remaining = (int) $db->execute("
    SELECT COUNT(*) FROM (
        SELECT 1 FROM survey_unit_sessions
        WHERE run_unit_id IS NOT NULL AND iteration IS NOT NULL
        GROUP BY run_session_id, run_unit_id, iteration
        HAVING COUNT(*) > 1
    ) t", [], true);
echo "Duplicate tuples that would block the UNIQUE key: {$remaining}\n";
if ($remaining === 0) {
    echo "None left — safe to make the index UNIQUE so this can't recur:\n";
    echo "  ALTER TABLE `survey_unit_sessions` DROP KEY `idx_run_unit_iter`,\n";
    echo "    ADD UNIQUE KEY `idx_run_unit_iter` (`run_session_id`, `run_unit_id`, `iteration`);\n";
} else {
    echo "Resolve these (and legacy-NULL clusters) before adding the UNIQUE key.\n";
}

// Deploy gate (update_formr.sh): fail loudly so the 049 ALTER can't blow up
// mid-migration. Run with --apply --fail-on-blocking; a non-zero exit means
// review clusters remain that a human must resolve first.
if ($fail_on_blocking && $remaining > 0) {
    fwrite(STDERR, "--fail-on-blocking: {$remaining} tuple(s) still block the UNIQUE key (patch 049); resolve the review clusters above.\n");
    exit(2);
}
exit(0);
