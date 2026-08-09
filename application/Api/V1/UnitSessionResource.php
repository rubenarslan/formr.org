<?php

/**
 * GET /v1/runs/{name}/unit_sessions — per-unit interaction history for a run.
 *
 * The companion to SessionResource: where /sessions exposes the current state
 * of each participant (one row per run-session), this resource exposes every
 * unit the participant has touched (one row per survey_unit_sessions row).
 * Use it for:
 *   - trajectory plots (Sankey, alluvial) — order by (session, created) and
 *     the consecutive pairs are the edges
 *   - drop-off analytics (how many people ended at unit X)
 *   - debugging stuck participants (look at the most recent unit_session,
 *     check `state` / `state_log` / `expired` / `ended`)
 *
 * Special units (OverviewScriptPage, ServiceMessagePage, ReminderEmail) live
 * outside the ordered run flow; their unit_sessions surface with `position = null`
 * since they aren't a step any participant traverses.
 *
 * Scopes: `session:read`. This is a deeper read of the same data set
 * `/sessions` already exposes — not a new security boundary.
 */
class UnitSessionResource extends BaseResource
{

    public function handle($runName = null)
    {
        if ($this->getRequestMethod() !== 'GET') {
            return $this->error(405, 'Method not allowed. Use GET.');
        }

        $this->checkScope('session:read');

        $run = $this->getRunByName($runName);
        if (!$run) {
            return $this;
        }

        $limit  = min(max((int) $this->request->getParam('limit', 1000), 1), 10000);
        $offset = max((int) $this->request->getParam('offset', 0), 0);

        $params = [':run_id' => $run->id];
        $where  = ['srs.run_id = :run_id'];

        // session=abc or session=abc,def (or repeated ?session= params).
        // Narrows the result to one or more participants' histories.
        $sessionParam = $this->request->getParam('session');
        if ($sessionParam !== null && $sessionParam !== '') {
            $codes = is_array($sessionParam) ? $sessionParam : explode(',', $sessionParam);
            $codes = array_filter(array_map('trim', $codes), 'strlen');
            if (!empty($codes)) {
                $placeholders = [];
                foreach (array_values($codes) as $i => $c) {
                    $ph = ":session_$i";
                    $placeholders[] = $ph;
                    $params[$ph] = $c;
                }
                $where[] = 'srs.session IN (' . implode(',', $placeholders) . ')';
            }
        }

        // testing=true/false — mirrors SessionResource::listSessions
        $testing = $this->request->getParam('testing');
        if ($testing !== null) {
            $where[] = 'srs.testing = :testing';
            $params[':testing'] = ($testing === 'true' || $testing === '1' ? 1 : 0);
        }

        // since=<datetime> — incremental fetch for periodic poll-based dashboards.
        // Filters by us.created so a polling client never sees the same row twice
        // (rows are immutable on create; updates flip ended/expired/state which
        // can be picked up by re-querying within the same window).
        $since = $this->request->getParam('since');
        if ($since !== null && $since !== '') {
            $ts = strtotime((string) $since);
            if ($ts === false) {
                return $this->error(400, "Invalid 'since' parameter; expected an ISO 8601 datetime.");
            }
            $where[] = 'us.created >= :since';
            $params[':since'] = date('Y-m-d H:i:s', $ts);
        }

        $whereSql = implode(' AND ', $where);

        // Position: from survey_run_units when the unit is in the ordered flow;
        // NULL for special units (which have no position in the run path).
        // Description: per-run override (the run_unit's description) wins, else
        // the special unit's description, mirroring SessionResource's COALESCE
        // pattern — now COALESCEd across both run_unit arms (see the join
        // comment below) so it survives the D1 fan-out fix.
        $sql = "SELECT
                us.id AS unit_session_id,
                srs.session AS session,
                srs.testing AS testing,
                us.unit_id AS unit_id,
                u.type AS unit_type,
                COALESCE(ru_own.description, ru_fallback.description, rsu.description) AS unit_description,
                COALESCE(ru_own.position, ru_fallback.position) AS position,
                us.iteration AS iteration,
                us.created AS created,
                us.expires AS expires,
                us.ended AS ended,
                us.expired AS expired,
                us.result AS result,
                us.state AS state
            FROM survey_unit_sessions us
            INNER JOIN survey_run_sessions srs ON srs.id = us.run_session_id
            LEFT JOIN survey_units u ON u.id = us.unit_id
        ";
        // D1 fan-out fix (v1.7.1). The old join was
        //     LEFT JOIN survey_run_units ru ON ru.unit_id = u.id AND ru.run_id = srs.run_id
        // which keys on the unit DEFINITION, not on the placement. A run that
        // slots the same unit at N positions therefore multiplied EVERY matching
        // unit-session row by N, each copy carrying a different `position`.
        // That is worse here than at the other D1 sites: the fan-out happens
        // BEFORE LIMIT/OFFSET, so the duplicates consume page slots and real
        // rows fall off the end of the page — a paginating client silently
        // loses unit sessions it will never see on any page.
        // Two-alias form (see RunHelper::PLACEMENT_JOIN, the canonical wording):
        // `ru_own` pins the row to its OWN placement via
        // survey_unit_sessions.run_unit_id (patch 047 — indexed PK lookup, at
        // most one row); `ru_fallback` fires only when that misses — rows
        // predating 047, plus the multi-position rows the 048 backfill
        // intentionally left NULL — and keeps the legacy unit_id match, still
        // run_id-scoped, so no row silently disappears.
        // Unlike UnitSession::getRunData()'s two-alias join, the fallback arm
        // here pins to ONE placement (lowest id) rather than matching them all.
        // For a legacy row the true placement is unknowable, and this endpoint
        // is a row-per-unit-session view: returning the session N times under N
        // positions IS the bug being fixed, so a best-effort position on a
        // single row beats N wrong rows. It matters in practice — 26% of the
        // unit sessions on this instance's oldest run still have a NULL
        // run_unit_id, so without the pick the fix would only help sessions
        // created after 047. survey_run_units holds a few hundred rows
        // instance-wide and the subquery is served by (run_id) + (unit_id), so
        // the cost is negligible.
        $sql .= "
            LEFT JOIN survey_run_units ru_own ON ru_own.id = us.run_unit_id
            LEFT JOIN survey_run_units ru_fallback ON ru_own.id IS NULL
                AND ru_fallback.id = (
                    SELECT MIN(ru_pick.id) FROM survey_run_units AS ru_pick
                    WHERE ru_pick.unit_id = us.unit_id
                      AND ru_pick.run_id = srs.run_id
                )
            LEFT JOIN survey_run_special_units rsu ON rsu.id = u.id AND rsu.run_id = srs.run_id
            WHERE $whereSql
            ORDER BY us.run_session_id ASC, us.created ASC, us.id ASC
            LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'unit_session_id'  => (int) $r['unit_session_id'],
                'session'          => $r['session'],
                'testing'          => (bool) $r['testing'],
                'unit_id'          => $r['unit_id'] !== null ? (int) $r['unit_id'] : null,
                'unit_type'        => $r['unit_type'],
                'unit_description' => $r['unit_description'],
                'position'         => $r['position'] !== null ? (int) $r['position'] : null,
                'iteration'        => $r['iteration'] !== null ? (int) $r['iteration'] : null,
                'created'          => $r['created'],
                'expires'          => $r['expires'],
                'ended'            => $r['ended'],
                'expired'          => $r['expired'],
                'result'           => $r['result'],
                'state'            => $r['state'],
            ];
        }

        return $this->response(200, 'Unit sessions list', $out);
    }
}
