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
        // Description: per-run override (ru.description) wins, else the special
        // unit's description, mirroring SessionResource's COALESCE pattern.
        $sql = "SELECT
                us.id AS unit_session_id,
                srs.session AS session,
                srs.testing AS testing,
                us.unit_id AS unit_id,
                u.type AS unit_type,
                COALESCE(ru.description, rsu.description) AS unit_description,
                ru.position AS position,
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
            LEFT JOIN survey_run_units ru ON ru.unit_id = u.id AND ru.run_id = srs.run_id
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
