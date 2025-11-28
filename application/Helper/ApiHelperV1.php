<?php

class ApiHelperV1 extends ApiBase
{

    protected $path_segments;


    // --- Entry Points (Public Methods mapping to top-level URL segments) ---

    /**
     * User Resource Endpoint (/user).
     * * Handles operations for the authenticated user.
     * Supported Methods: GET (Profile), PATCH (Update).
     * * @return ApiHelperV1
     */
    public function user()
    {
        $method = $this->getRequestMethod(); // method from ApiBase or $_SERVER
        $subPath = $this->getUriSegment(1); // e.g., 'me'

        if ($subPath === 'me') {
            if ($method === 'GET') {
                $this->checkScope('user:read');
                // TODO: Retrieve authenticated user's profile (ID, email, code)
                return $this->response(200, 'User profile retrieved', [
                    'id' => $this->user->id,
                    'email' => $this->user->email
                ]);
            } elseif ($method === 'PATCH') {
                $this->checkScope('user:write');
                $data = $this->getJsonBody();
                // TODO: Update user details (name, affiliation)
                return $this->response(200, 'User updated');
            }
        }

        return $this->error(404, 'User endpoint not found');
    }

    /**
     * Runs Resource Hub (/runs).
     * * Acts as a sub-router for Run-related logic. Dispatches specific run operations
     * or delegates to sub-resources (Sessions, Results, Files, Surveys).
     *
     * @param string|null $runName The name of the specific run.
     * @param string|null $subResource The sub-resource (e.g., 'results').
     * @param mixed|null $extra Additional URL segments.
     * @return ApiHelperV1
     */
    public function runs($runName = null, $subResource = null, $extra = null)
    {
        $method = $this->getRequestMethod();
        $runName = $this->getUriSegment(1); // /runs/{run_name}
        $subResource = $this->getUriSegment(2); // /runs/{run_name}/{sub_resource}

        // 1. Root /runs endpoints
        if (empty($runName)) {
            if ($method === 'GET') {
                $this->checkScope('run:read');
                // TODO: List all runs (filter by name, public status)
                return $this->response(200, 'Runs listed');
            } elseif ($method === 'POST') {
                $this->checkScope('run:write');
                // TODO: Create new run
                return $this->response(201, 'Run created');
            }
            return $this->error(405, 'Method not allowed');
        }

        // 2. Sub-resource Dispatcher
        // If there is a sub-resource (e.g. /sessions, /results), hand off logic
        if ($subResource) {
            switch ($subResource) {
                case 'sessions':
                    return $this->handleSessions($runName);
                case 'results':
                    return $this->handleResults($runName);
                case 'files':
                    return $this->handleFiles($runName);
                case 'surveys':
                    return $this->handleSurveys($runName);
                default:
                    return $this->error(404, 'Run sub-resource not found');
            }
        }

        // 3. Specific Run endpoints (/runs/{run_name})
        switch ($method) {
            case 'GET':
                $this->checkScope('run:read');
                // TODO: Get detailed config of $runName
                return $this->response(200, 'Run details');
            case 'PATCH':
                $this->checkScope('run:write');
                // TODO: Update run settings
                return $this->response(200, 'Run updated');
            case 'DELETE':
                $this->checkScope('run:write');
                // TODO: Careful delete of $runName
                return $this->response(200, 'Run deleted');
        }

        return $this->error(400, 'Invalid request');
    }

    // --- Private Sub-Resource Handlers ---

    /**
     * Handles /runs/{name}/sessions
     */
    private function handleSessions($runName)
    {
        $method = $this->getRequestMethod();
        // Find the index of 'sessions' dynamically
        $sessionsIndex = array_search('sessions', $this->path_segments);

        // Safety check
        if ($sessionsIndex === false) {
            return $this->error(500, 'Routing Error');
        }

        $sessionCode = $this->path_segments[$sessionsIndex + 1] ?? null;
        $action      = $this->path_segments[$sessionsIndex + 2] ?? null;

        // List or Create Sessions
        if (empty($sessionCode)) {
            if ($method === 'GET') {
                $this->checkScope('session:read');
                // TODO: List sessions for $runName (filter by access/status)
                return $this->response(200, 'Sessions list');
            }
            if ($method === 'POST') {
                $this->checkScope('session:write');
                // TODO: Create new session (Migrate logic from /post/create-session)
                return $this->response(201, 'Session created');
            }
        }

        // Specific Session Logic
        if ($sessionCode && empty($action)) {
            if ($method === 'GET') {
                $this->checkScope('session:read');
                // TODO: Get details of $sessionCode
                return $this->response(200, 'Session details');
            }
        }

        // Session Actions
        if ($sessionCode && $action === 'actions' && $method === 'POST') {
            $this->checkScope('session:write');
            $body = $this->getJsonBody();
            // TODO: Handle "pause", "resume", "end_external" based on $body['action']
            return $this->response(200, 'Session action performed');
        }

        return $this->error(404, 'Session endpoint not found');
    }

    /**
     * Results Sub-Resource Handler.
     * * GET /v1/runs/{name}/results
     * Retrieves survey results for a specific run. Supports filtering via 
     * query parameters (sessions, surveys, items).
     *
     * @param string $runName The name of the run.
     * @return ApiHelperV1
     */
    private function handleResults($runName)
    // DEV deactivated: checkScope('run:read');
    {
        if ($this->getRequestMethod() !== 'GET') {
            return $this->error(405, 'Method not allowed. Use GET.');
        }

        // 1. Security & Config
        // DEBUG: $this->checkScope('data:read');
        ini_set('memory_limit', Config::get('memory_limit.run_get_data'));

        // 2. Validate Run
        // We construct a lightweight object to satisfy the parent ApiBase::getRunFromRequest signature
        $mockRequest = (object) ['run' => (object) ['name' => $runName]];

        // This calls the parent method to ensure we reuse the existing ownership/permission logic
        $run = $this->getRunFromRequest($mockRequest);

        // If parent returned false, it already called setData() with the error.
        if (!$run) {
            return $this;
        }

        // 3. Early Exit: Check if run has data
        if (!$this->db->count('survey_run_sessions', array('run_id' => $run->id), 'id')) {
            return $this->error(404, 'No sessions were found in this run.');
        }

        // 4. Parse Query Parameters
        $getParam = function ($key) {
            $val = $_GET[$key] ?? null;
            if (!$val) return null;
            return is_array($val) ? $val : array_map('trim', explode(',', $val));
        };

        $filterSessions = $getParam('sessions');
        $filterSurveys  = $getParam('surveys');
        $filterItems    = $getParam('items');

        $surveysToProcess = [];

        if (!empty($filterSurveys)) {
            foreach ($filterSurveys as $sName) {
                $surveysToProcess[] = (object) [
                    'name' => $sName,
                    'items' => $filterItems
                ];
            }
        } else {
            $allSurveys = $run->getAllSurveys();
            foreach ($allSurveys as $s) {
                $surveysToProcess[] = (object) [
                    'name' => $s['name'],
                    'items' => $filterItems
                ];
            }
        }

        // 6. Fetch Data
        $results = [];

        foreach ($surveysToProcess as $s) {

            // --- THE FIX IS HERE ---
            // ApiBase::getSurveyResults expects a comma-separated string, not an array.
            $itemsString = ($s->items && is_array($s->items)) ? implode(',', $s->items) : $s->items;

            $surveyData = $this->getSurveyResults(
                $run,
                $s->name,
                $itemsString, // <--- Passed as "item1,item2"
                $filterSessions
            );

            $results[$s->name] = $surveyData;
        }

        return $this->response(200, 'OK', $results);
    }

    /**
     * Handles /runs/{name}/files
     */
    private function handleFiles($runName)
    {
        $method = $this->getRequestMethod();
        $fileName = $this->getUriSegment(3);

        if (empty($fileName)) {
            if ($method === 'GET') {
                $this->checkScope('file:read');
                // TODO: List files for $runName
                return $this->response(200, 'Files list');
            }
            if ($method === 'POST') {
                $this->checkScope('file:write');
                // TODO: Handle file upload
                return $this->response(201, 'File uploaded');
            }
        } else {
            if ($method === 'DELETE') {
                $this->checkScope('file:write');
                // TODO: Delete $fileName
                return $this->response(200, 'File deleted');
            }
        }
        return $this->error(405, 'Method not allowed');
    }

    /**
     * Handles /runs/{name}/surveys
     */
    private function handleSurveys($runName)
    {
        $method = $this->getRequestMethod();
        $surveyName = $this->getUriSegment(3);

        if ($method === 'GET') {
            $this->checkScope('run:read'); // Using run:read as surveys are part of structure

            if ($surveyName) {
                // TODO: Get structure for $surveyName
                return $this->response(200, 'Survey structure');
            } else {
                // TODO: List surveys for $runName
                return $this->response(200, 'Surveys list');
            }
        }
        return $this->error(405, 'Method not allowed');
    }

    // --- Helpers (Simulated for this skeleton) ---

    /**
     * Standardizes responses
     */
    private function response($code, $msg, $data = [])
    {
        $this->setData($code, $msg, $data);
        return $this;
    }

    private function error($code, $msg)
    {
        $this->setData($code, 'Error', ['error' => $msg]);
        return $this;
    }

    /**
     * Validates if the current token has the required scope
     * Throws exception or terminates if failed
     */
    private function checkScope($requiredScope)
    {
        // TODO: Implement actual scope check logic against $this->user or $this->token
        // if (!in_array($requiredScope, $this->tokenScopes)) {
        //    throw new Exception("Insufficient permissions: $requiredScope required.");
        // }
    }

    private function getRequestMethod()
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    private function getJsonBody()
    {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }

    /**
     * URI Segment Parser.
     * * Analyzes `REQUEST_URI` to determine path segments relative to the API version.
     * Ensures correct segment retrieval regardless of base path (e.g. /api/v1 vs /v1).
     *
     * @param int $index The segment offset index.
     * @return string|null The segment value or null if not found.
     */
    protected function getUriSegment($index)
    {
        if (!isset($this->path_segments)) {
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

            // 1. Trim slashes
            $path = trim($path, '/');

            // 2. Explode first
            $segments = explode('/', $path);

            // 3. Find where 'v1' is and slice array from there
            // This makes it safe regardless of /api/v1 or /formr/api/v1
            $v1Index = array_search('v1', $segments);

            if ($v1Index !== false) {
                // Keep everything AFTER 'v1'
                $this->path_segments = array_slice($segments, $v1Index + 1);
            } else {
                // Fallback or legacy handling
                $this->path_segments = $segments;
            }
        }

        return $this->path_segments[$index] ?? null;
    }
}
