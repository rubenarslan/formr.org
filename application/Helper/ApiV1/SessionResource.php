<?php

class SessionResource extends BaseResource
{

    private $run;

    public function handle($runName = null)
    {
        $this->validateRun($runName);
        if (!$this->run) {
            return $this;
        }

        $method = $this->getRequestMethod();
        $sessionsIndex = array_search('sessions', $this->path_segments);

        if ($sessionsIndex === false) {
            return $this->error(500, 'Routing Error');
        }

        $sessionCode = $this->path_segments[$sessionsIndex + 1] ?? null;
        $action = $this->path_segments[$sessionsIndex + 2] ?? null;

        if (empty($sessionCode) && $method === 'GET') {
            return $this->listSessions();
        }

        if (empty($sessionCode) && $method === 'POST') {
            return $this->createSession();
        }

        if ($sessionCode) {
            $runSession = new RunSession($sessionCode, $this->run);
            if (!$runSession->id) {
                return $this->error(404, "Session '$sessionCode' not found in run '$runName'");
            }

            if (empty($action) && $method === 'GET') {
                return $this->getSessionDetails($runSession);
            }

            if ($action === 'actions' && $method === 'POST') {
                return $this->performSessionAction($runSession);
            }
        }

        return $this->error(404, 'Endpoint not found or method not allowed');
    }

    private function validateRun($runName)
    {
        $mockRequest = (object) ['run' => (object) ['name' => $runName]];
        $this->run = $this->getRunFromRequest($mockRequest);
    }

    private function listSessions()
    {
        $this->checkScope('session:read');

        $limit = (int)$this->request->getParam('limit', 100);
        $limit = min(max($limit, 1), 10000);
        $offset = (int)$this->request->getParam('offset', 0);
        $active = $this->request->getParam('active');
        $testing = $this->request->getParam('testing');

        $params = [':run_id' => $this->run->id];
        $where = ["survey_run_sessions.run_id = :run_id"];

        if ($active !== null) {
            if ($active === 'true' || $active === '1') {
                $where[] = 'survey_run_sessions.ended IS NULL';
            } elseif ($active === 'false' || $active === '0') {
                $where[] = 'survey_run_sessions.ended IS NOT NULL';
            }
        }

        if ($testing !== null) {
            $where[] = 'survey_run_sessions.testing = :testing';
            $params[':testing'] = ($testing === 'true' || $testing === '1' ? 1 : 0);
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT 
                survey_run_sessions.*, 
                MAX(us.id) as unit_session_id,
                MAX(u.id) as unit_id,
                MAX(u.type) as unit_type,
                COALESCE(MAX(ru.description), MAX(rsu.description)) as unit_description
            FROM survey_run_sessions
            LEFT JOIN survey_unit_sessions us ON us.id = survey_run_sessions.current_unit_session_id
            LEFT JOIN survey_units u ON u.id = us.unit_id
            LEFT JOIN survey_run_units ru ON ru.unit_id = u.id AND ru.run_id = survey_run_sessions.run_id AND ru.position = survey_run_sessions.position
            LEFT JOIN survey_run_special_units rsu ON rsu.id = u.id AND rsu.run_id = survey_run_sessions.run_id
            WHERE $whereSql
            GROUP BY survey_run_sessions.id
            ORDER BY survey_run_sessions.created DESC
            LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sessions = [];
        foreach ($rows as $row) {
            $sessions[] = $this->formatSessionRow($row);
        }

        return $this->response(200, 'Sessions list', $sessions);
    }

    private function createSession()
    {
        $this->checkScope('session:write');
        $body = $this->getJsonBody();

        $codes = $body['code'] ?? null;
        $testing = !empty($body['testing']) ? 1 : 0;

        $createdSessions = [];
        $failedSessions = [];

        if ($codes === null) {
            $runSession = new RunSession(null, $this->run);
            if ($runSession->create(null, $testing)) {
                return $this->response(201, 'Session created successfully.', [
                    'count_created' => 1,
                    'sessions' => [$runSession->session]
                ]);
            } else {
                return $this->error(500, 'Failed to create random session.');
            }
        } else {
            if (!is_array($codes)) {
                $codes = [$codes];
            }

            $code_rule = Config::get("user_code_regular_expression");

            foreach ($codes as $code) {
                if ($code && !preg_match('/^[a-zA-Z0-9_\-~]+$/', $code)) {
                    $failedSessions[] = [
                        'code' => $code,
                        'reason' => 'Invalid characters. Only alphanumeric and - _ ~ are allowed.'
                    ];
                    continue;
                }

                if ($code && !preg_match($code_rule, $code)) {
                    $failedSessions[] = [
                        'code' => $code,
                        'reason' => "Does not match required format: $code_rule"
                    ];
                    continue;
                }

                $runSession = new RunSession($code, $this->run);

                if ($runSession->id) {
                    $failedSessions[] = [
                        'code' => $code,
                        'reason' => 'Session already exists.'
                    ];
                    continue;
                }

                if ($runSession->create($code, $testing)) {
                    $createdSessions[] = $runSession->session;
                } else {
                    $failedSessions[] = [
                        'code' => $code,
                        'reason' => 'Creation failed (database error).'
                    ];
                }
            }

            $payload = [
                'count_created' => count($createdSessions),
                'sessions' => $createdSessions,
            ];

            if (!empty($failedSessions)) {
                $payload['count_failed'] = count($failedSessions);
                $payload['errors'] = $failedSessions;
            }

            if (empty($createdSessions) && !empty($failedSessions)) {
                return $this->error(400, 'No sessions were created.');
            }

            if (!empty($createdSessions) && !empty($failedSessions)) {
                return $this->response(207, 'Some sessions were created, but others failed.', $payload);
            }

            return $this->response(201, 'Sessions created successfully', $payload);
        }
    }

    private function getSessionDetails(RunSession $runSession)
    {
        $this->checkScope('session:read');

        $data = [
            'id' => (int)$runSession->id,
            'session' => $runSession->session,
            'run_id' => (int)$runSession->run_id,
            'user_id' => (int)$runSession->user_id,
            'position' => (int)$runSession->position,
            'current_unit_session_id' => $runSession->current_unit_session_id ? (int)$runSession->current_unit_session_id : null,
            'created' => $runSession->created,
            'last_access' => $runSession->last_access,
            'ended' => $runSession->ended,
            'deactivated' => (bool)$runSession->deactivated,
            'no_email' => (bool)$runSession->no_email,
            'testing' => (bool)$runSession->testing,
        ];

        $currentUnitSession = $runSession->getCurrentUnitSession();
        if ($currentUnitSession) {
            $data['current_unit'] = [
                'id' => (int)$currentUnitSession->runUnit->id,
                'type' => $currentUnitSession->runUnit->type,
                'description' => $currentUnitSession->runUnit->description,
                'session_id' => (int)$currentUnitSession->id
            ];
        }

        return $this->response(200, 'Session details', $data);
    }

    private function formatSessionRow($row)
    {
        $data = [
            'id' => (int)$row['id'],
            'session' => $row['session'],
            'run_id' => (int)$row['run_id'],
            'user_id' => (int)$row['user_id'],
            'position' => (int)$row['position'],
            'current_unit_session_id' => $row['current_unit_session_id'] ? (int)$row['current_unit_session_id'] : null,
            'created' => $row['created'],
            'last_access' => $row['last_access'],
            'ended' => $row['ended'],
            'deactivated' => (bool)$row['deactivated'],
            'no_email' => (bool)$row['no_email'],
            'testing' => (bool)$row['testing'],
        ];

        if (!empty($row['unit_id'])) {
            $data['current_unit'] = [
                'id' => (int)$row['unit_id'],
                'type' => $row['unit_type'],
                'description' => $row['unit_description'],
                'session_id' => (int)$row['unit_session_id']
            ];
        }

        return $data;
    }

    private function performSessionAction(RunSession $runSession)
    {
        $this->checkScope('session:write');
        $body = $this->getJsonBody();
        $action = $body['action'] ?? null;

        switch ($action) {
            case 'end_external':
                if ($runSession->endLastExternal()) {
                    return $this->response(200, 'External unit ended successfully');
                }
                return $this->error(400, 'Could not end external unit (maybe none active?)');

            case 'toggle_testing':
                $status = !empty($body['testing']) ? 1 : 0;
                $runSession->setTestingStatus($status);
                return $this->response(200, 'Testing status updated');

            case 'move_to_position':
                $position = $body['position'] ?? null;
                if ($position === null) {
                    return $this->error(400, 'Position is required for this action');
                }
                if ($runSession->forceTo((int)$position)) {
                    return $this->response(200, "Session moved to position $position");
                }
                return $this->error(500, 'Failed to move session');

            case 'execute':
                $runSession->execute();
                return $this->response(200, 'Session executed successfully.');

            case 'advance':
                if ($runSession->endCurrentUnitSession()) {
                    $runSession->moveOn(); 
                    
                    return $this->response(200, 'Session advanced successfully.');
                }
                return $this->error(400, 'Could not advance session. The current unit might not support manual ending.');
            
            default:
                return $this->error(400, "Invalid action: '$action'. Supported: end_external, toggle_testing, move_to_position");
        }
    }
}
