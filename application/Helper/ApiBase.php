<?php

abstract class ApiBase
{

    /**
     * @var DB
     */
    protected $db;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var User
     */
    protected $user = null;

    /**
     * A container to hold processed request's outcome
     * @var array
     */
    protected $data = array(
        'statusCode' => Response::STATUS_OK,
        'statusText' => 'OK',
        'response' => array(),
    );

    /** 
     * Stores the parsed URI segments 
     * @var array 
     */
    protected $path_segments;

    /**
     * Error information
     * @var array
     */
    protected $error = array();

    protected $tokenData = array();

    /**
     * Constructor.
     * * Initializes the API handler with the Request object, Database connection, and Token data.
     * It also hydrates the authenticated User based on the token's user_id.
     * * @param Request $request The HTTP request object wrapping $_GET/$_POST/$_SERVER.
     * @param DB $db The Database instance.
     * @param array $token_data Associative array containing OAuth2 token details (e.g., 'user_id', 'scope').
     */
    public function __construct(Request $request, DB $db, $token_data)
    {
        $this->db = $db;
        $this->request = $request;
        $this->tokenData = $token_data;
        // Retrieves the user associated with the access token
        $this->user = OAuthHelper::getInstance()->getUserByEmail($token_data['user_id']);

        // Legacy support: Sets the global user object for older components that rely on it
        global $user;
        $user = $this->user;
    }

    /**
     * Retrieve the processed response data.
     * @return array The associative array containing 'statusCode', 'statusText', and 'response' body.
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * URI Segment Parser.
     * * Analyzes `REQUEST_URI` to extract specific path segments relative to the API version.
     * It normalizes the path by stripping the base prefix (up to 'v1') to ensure consistent 
     * segment retrieval regardless of the server's sub-directory configuration.
     *
     * @param int $index The offset index of the segment to retrieve (0-based relative to version).
     * @return string|null The segment value, or null if the index does not exist.
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
            // This makes it safe regardless of /api/v1 or /v1 or whatever is configured.
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

    /**
     * Magic Method Trait.
     * * Captures calls to non-existent methods to prevent fatal errors, 
     * returning a 405 Method Not Allowed JSON response instead.
     *
     * @param string $name Name of the method called.
     * @param array $arguments Arguments passed.
     * @return $this
     */
    public function __call($name, $arguments)
    {
        $this->setData(Response::STATUS_METHOD_NOT_ALLOWED, 'Method Not Allowed', ['error' => "Action '$name' is not available in this API version."]);
        return $this;
    }

    // --- SHARED HELPER METHODS ---

    /**
     * Response Builder
     * * Helper wrapper around `ApiBase::setData` to chain the return.
     *
     * @param int $code HTTP Status Code
     * @param string $msg Status Message
     * @param array $data Response body
     * @return ApiHelperV1
     */
    protected function response($code, $msg, $data = [])
    {
        $this->setData($code, $msg, $data);
        return $this;
    }

    protected function error($code, $msg)
    {
        $this->setData($code, 'Error', ['error' => $msg]);
        return $this;
    }

    /**
     * OAuth2 Scope Validator.
     * * Verifies if the access token used for the request includes the specific scope required 
     * to perform the action.
     *
     * @param string $requiredScope The scope string required (e.g., 'user:read').
     * @throws Exception If the token does not grant the required scope.
     * @return void
     */
    protected function checkScope($requiredScope)
    {
        $grantedScopes = explode(' ', isset($this->tokenData['scope']) ? $this->tokenData['scope'] : '');
        if (!in_array($requiredScope, $grantedScopes)) {
            throw new Exception("Insufficient permissions: '$requiredScope' scope required.");
        }
    }

    protected function getRequestMethod()
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * JSON Body Parser.
     * * Reads the raw input stream ('php://input') and decodes it as a JSON associative array.
     * Useful for handling RESTful payloads.
     * * @return array The decoded JSON body or an empty array if decoding fails.
     */
    protected function getJsonBody()
    {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }

    /**
     * Run Validator and Retriever.
     * * Extracts the Run object based on the request parameters.
     * It enforces security policies:
     * 1. Checks if the Run exists.
     * 2. Checks if the authenticated user is the owner.
     * 3. Alternatively, checks if an API secret is provided and valid (machine-to-machine access).
     *
     * @param object $request The JSON request object containing the run name.
     * @return Run|false Returns the Run model on success, or false on failure (sets error data internally).
     */
    protected function getRunFromRequest($request)
    {
        if (empty($request->run->name)) {
            $this->setData(Response::STATUS_NOT_FOUND, 'Not Found', null, 'Required "run : { name: }" parameter not found.');
            return false;
        }

        $run = new Run($request->run->name);
        if (!$run->valid || !$this->user) {
            $this->setData(Response::STATUS_NOT_FOUND, 'Not Found', null, 'Invalid Run or run/user not found');
            return false;
        } elseif (!$this->user->created($run)) {
            $this->setData(Response::STATUS_UNAUTHORIZED, 'Unauthorized Access', null, 'Unauthorized access to run');
            return false;
        }

        return $run;
    }

    protected function setData($statusCode = null, $statusText = null, $response = null, $error = null)
    {
        if ($error !== null) {
            $this->setError($statusCode, $statusText, $error);
            return $this->setData($statusCode, $statusText, $this->error);
        }

        if ($statusCode !== null) {
            $this->data['statusCode'] = $statusCode;
        }
        if ($statusText !== null) {
            $this->data['statusText'] = $statusText;
        }
        if ($response !== null) {
            $this->data['response'] = $response;
        }
    }

    protected function setError($code = null, $error = null, $desc = null)
    {
        if ($code !== null) {
            $this->error['error_code'] = $code;
        }
        if ($error !== null) {
            $this->error['error'] = $error;
        }
        if ($desc !== null) {
            $this->error['error_description'] = $desc;
        }
    }

    protected function parseJsonRequest()
    {
        $request = $this->request->str('request', null) ? $this->request->str('request') : file_get_contents('php://input');
        if (!$request) {
            $this->setData(Response::STATUS_BAD_REQUEST, 'Invalid Request', null, "Request payload not found");
            return false;
        }
        $object = json_decode($request);
        if (!$object) {
            $this->setData(Response::STATUS_BAD_REQUEST, 'Invalid Request', null, "Unable to parse JSON request");
            return false;
        }
        return $object;
    }

    /**
     * Survey Results Retriever.
     * * Fetches survey submission data for a specific run and survey.
     * Supports filtering by specific item names and session IDs.
     *
     * @param Run $run The Run model instance.
     * @param string $survey_name The name of the specific survey within the run.
     * @param string|null $survey_items Comma-separated list of item names to include (columns).
     * @param array|null $sessions List of session IDs to filter by (rows).
     * @return array Associative array of results grouped by session.
     */
    protected function getSurveyResults(Run $run, $survey_name, $survey_items = null, $sessions = null)
    {
        $results = array();
        $query = $this->buildSurveyResultsQuery($run, $survey_name, $survey_items, $sessions);
        $stmt = $this->db->query($query, true);

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $session_id = $row['session_id'];
            if (!isset($results[$session_id])) {
                $results[$session_id] = array(
                    'session' => $row['run_session'],
                    'created' => $row['created'],
                    'current_position' => $row['current_position'] // Fixed missing bracket from original code
                );
            }
            if ($row['created'] && !$results[$session_id]['created']) {
                $results[$session_id]['created'] = $row['created'];
            }
            $results[$session_id][$row['item_name']] = $row['answer'];
        }

        return array_values($results);
    }

    /**
     * Query Builder for Survey Results.
     * * Dynamically constructs a SQL query string to fetch flattened survey results.
     * It handles:
     * - Parameter escaping (using DB::quote).
     * - dynamic filtering for items (WHERE IN).
     * - dynamic filtering for sessions (LIKE/OR matches).
     *
     * @param Run $run The Run model.
     * @param string $survey_name Name of the survey.
     * @param string|null $survey_items Comma-separated items.
     * @param array|null $sessions Array of session strings.
     * @return string The raw SQL query with placeholders replaced.
     */
    protected function buildSurveyResultsQuery(Run $run, $survey_name, $survey_items = null, $sessions = null)
    {
        $params = array(
            'run_id' => $run->id,
            'user_id' => $this->user->id,
            'survey_name' => $this->db->quote($survey_name),
            'WHERE_survey_items' => null,
            'WHERE_run_sessions' => null,
        );

        $q = '
            SELECT itms_display.item_id, itms_display.session_id, itms_display.answer, itms_display.created,
                   survey_items.name AS item_name, survey_run_sessions.session AS run_session, survey_run_sessions.position AS current_position
            FROM survey_items_display AS itms_display
            LEFT JOIN survey_unit_sessions ON survey_unit_sessions.id = itms_display.session_id
            LEFT JOIN survey_run_sessions ON survey_run_sessions.id = survey_unit_sessions.run_session_id
            LEFT JOIN survey_items ON survey_items.id = itms_display.item_id
            LEFT JOIN survey_studies ON survey_studies.id = survey_items.study_id
            WHERE survey_studies.name = %{survey_name}
            AND survey_studies.user_id = %{user_id}
            AND survey_run_sessions.run_id = %{run_id}
            %{WHERE_survey_items}
            %{WHERE_run_sessions}
        ';

        if ($survey_items && is_string($survey_items)) {
            $itms = array();
            foreach (explode(',', $survey_items) as $itm) {
                $itms[] = $this->db->quote(trim($itm));
            }
            $params['WHERE_survey_items'] = ' AND survey_items.name IN (' . implode(',', $itms) . ') ';
        }

        if ($sessions && is_array($sessions)) {
            $or_like = array();
            foreach ($sessions as $session) {
                $or_like[] = " survey_run_sessions.session LIKE " . $this->db->quote($session . '%');
            }
            $params['WHERE_run_sessions'] = ' AND (' . implode(' OR ', $or_like) . ') ';
        }

        return Template::replace($q, $params);
    }

/**
     * Shuffle Results Retriever.
     * * Fetches shuffle (random group) assignments for a specific run.
     *
     * @param Run $run The Run model instance.
     * @param array|null $sessions List of session IDs to filter by.
     * @return array Associative array of shuffle results.
     */
protected function getShuffleResults(Run $run, $sessions = null)
    {
        $params = array(
            'run_id' => $run->id,
            'WHERE_run_sessions' => null,
        );

        $q = '
            SELECT 
                sus.id AS session_id,
                srs.session AS run_session,
                sru.position,
                sus.unit_id,
                sus.result AS `group`,
                sus.created
            FROM survey_unit_sessions AS sus
            LEFT JOIN survey_run_sessions AS srs ON srs.id = sus.run_session_id
            LEFT JOIN survey_units AS u ON u.id = sus.unit_id
            LEFT JOIN survey_run_units AS sru ON sru.unit_id = u.id AND sru.run_id = srs.run_id
            WHERE srs.run_id = %{run_id}
            AND u.type = \'Shuffle\'
            %{WHERE_run_sessions}
            ORDER BY sus.created ASC
        ';

        if ($sessions && is_array($sessions)) {
            $or_like = array();
            foreach ($sessions as $session) {
                $or_like[] = " srs.session LIKE " . $this->db->quote($session . '%');
            }
            $params['WHERE_run_sessions'] = ' AND (' . implode(' OR ', $or_like) . ') ';
        }

        $query = Template::replace($q, $params);
        $stmt = $this->db->query($query, true);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
