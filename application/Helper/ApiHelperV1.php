<?php

class ApiHelperV1 extends ApiBase
{
    // --- Entry Points (Public Methods mapping to top-level URL segments) ---

    /**
     * User Resource Handler (/user)
     * * Routes requests targeting the authenticated user's account.
     * * Supported Endpoints:
     * - GET /user/me: Retrieve user profile
     * - PATCH /user/me: Update allowed profile fields
     *
     * @return ApiHelperV1 Returns self for method chaining or sets error state.
     */
    public function user()
    {
        $method = $this->getRequestMethod();
        $subPath = $this->getUriSegment(1); // e.g., 'me'

        // Route: /user/me
        if ($subPath === 'me') {
            if ($method === 'GET') {
                return $this->getUserProfile();
            } elseif ($method === 'PATCH') {
                return $this->updateUserProfile();
            } else {
                return $this->error(405, 'Method not allowed');
            }
        }

        return $this->error(404, 'User endpoint not found');
    }

    /**
     * Get User Profile
     * * Retrieves the authenticated user's data.
     * * Scope required: `user:read`
     * * Filters sensitive data (password hashes) before returning.
     * * @return ApiHelperV1
     */
    private function getUserProfile()
    {
        $this->checkScope('user:read');

        // Construct a safe response object excluding sensitive data (password, hashes)
        $userData = [
            'id' => (int)$this->user->id,
            'email' => $this->user->email,
            'user_code' => $this->user->user_code,
            'first_name' => $this->user->first_name,
            'last_name' => $this->user->last_name,
            'affiliation' => $this->user->affiliation,
            'email_verified' => (bool)$this->user->email_verified,
            // 'created' is available on the model property
            'created' => $this->user->created
        ];

        return $this->response(200, 'User profile retrieved', $userData);
    }

    /**
     * Update User Profile
     * * Updates whitelist fields (first_name, last_name, affiliation).
     * * Scope required: `user:write`
     * * Ignores sensitive or system-managed fields (email, admin level).
     * * @return ApiHelperV1
     */
    private function updateUserProfile()
    {
        $this->checkScope('user:write');
        $body = $this->getJsonBody();

        // Whitelist editable fields
        $allowedFields = ['first_name', 'last_name', 'affiliation'];
        $updates = [];

        foreach ($allowedFields as $field) {
            if (isset($body[$field])) {
                $updates[$field] = trim(strip_tags($body[$field]));
            }
        }

        if (empty($updates)) {
            return $this->error(400, 'No valid fields provided for update. Allowed: ' . implode(', ', $allowedFields));
        }

        try {
            // required for the parent Model::update() method to work.
            $this->db->update('survey_users', $updates, ['id' => $this->user->id]);

            // Update the local user object to reflect changes immediately if reused
            foreach ($updates as $key => $val) {
                $this->user->$key = $val;
            }

            return $this->response(200, 'User profile updated', $updates);
        } catch (Exception $e) {
            return $this->error(500, 'Failed to update profile: ' . $e->getMessage());
        }
    }

    /**
     * Survey Resource Handler (/surveys)
     * * Manages CRUD operations for Survey studies (the forms/questionnaires).
     * * Supported Endpoints:
     * - GET /surveys: List all surveys belonging to user.
     * - POST /surveys/{name}: Create or Update a survey via file upload or Google Sheet URL.
     * - GET /surveys/{name}: Retrieve survey settings and item structure.
     * - PATCH /surveys/{name}: Update survey metadata/settings.
     * - DELETE /surveys/{name}: Delete a survey.
     *
     * @param string|null $surveyName The name of the survey (from URL segment 2).
     * @return ApiHelperV1
     */
    public function surveys($surveyName = null)
    {
        $method = $this->getRequestMethod();

        // 1. List Surveys (GET /surveys)
        if (empty($surveyName)) {
            if ($method === 'GET') {
                $this->checkScope('survey:read');

                $select = $this->db->select('id, name, created, modified, results_table')
                    ->from('survey_studies')
                    ->where(['user_id' => $this->user->id]);

                // Filter by name if provided in query string
                if ($nameFilter = $this->request->getParam('name')) {
                    $select->like('name', $nameFilter);
                }

                $surveys = $select->fetchAll();
                return $this->response(200, 'Surveys listed', $surveys);
            }

            // Create requires a name in the URL (PUT /surveys/{name})
            if ($method === 'PUT') {
                return $this->error(405, 'Method not allowed. Use POST /surveys/{survey_name} to create a survey.');
            }

            return $this->error(405, 'Method not allowed');
        }

        // 2. Specific Survey Operations (/surveys/{name})

        // Create/Update (POST)
        if ($method === 'POST') {
            $this->checkScope('run:write');

            $file = null;
            $googleSheetUrl = $this->request->str('google_sheet'); // Check for Google Sheet URL

            // --- 1. DETERMINE SOURCE ---
            if (!empty($googleSheetUrl)) {
                // Handle Google Sheet Import
                // google_download_survey_sheet returns an array structure compatible with $_FILES
                // or false on failure.
                $file = google_download_survey_sheet($googleSheetUrl);

                if (!$file) {
                    return $this->error(400, "Unable to download the Google Sheet. Please check the link and permissions.");
                }
            } elseif (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                // Handle Direct File Upload
                $file = $_FILES['file'];
            } else {
                return $this->error(400, 'A valid file upload (key: "file") OR Google Sheet URL (key: "google_sheet") is required.');
            }

            // --- 2. VALIDATION ---
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $allowed = ['xls', 'xlsx', 'ods', 'csv', 'txt', 'xml'];
            if (!in_array(strtolower($ext), $allowed)) {
                // Cleanup temp file if it was a Google download
                if ($googleSheetUrl) delete_tmp_file($file);
                return $this->error(400, 'Invalid file type. Allowed: ' . implode(', ', $allowed));
            }

            try {
                $this->db->beginTransaction();

                // 3. Initialize Model
                $study = SurveyStudy::loadByUserAndName($this->user, $surveyName);

                // Override filename to match API resource name for consistency
                $file['name'] = $surveyName . '.' . $ext;

                $options = [
                    'user_id' => $this->user->id,
                    'survey_name' => $surveyName
                ];

                if ($study->valid) {
                    // --- UPDATE EXISTING ---

                    // If this is a Google Sheet import, update the stored ID
                    if ($googleSheetUrl && isset($file['google_file_id'])) {
                        if ($study->google_file_id != $file['google_file_id']) {
                            $study->google_file_id = $file['google_file_id'];
                            $study->save();
                        }
                    }

                    $success = $study->uploadItems($file, true);
                    $action = 'updated';
                } else {
                    // --- CREATE NEW ---
                    $study = new SurveyStudy();
                    if (!$study->createFromFile($file, $options)) {
                        throw new Exception("Failed to initialize survey structure.");
                    }

                    // Save Google ID for new study
                    if ($googleSheetUrl && isset($file['google_file_id'])) {
                        $study->google_file_id = $file['google_file_id'];
                        $study->save();
                    }

                    $success = $study->uploadItems($file, true, true);
                    $action = 'created';
                }

                if ($success) {
                    $this->db->commit();
                    $messages = array_merge($study->messages, $study->warnings);

                    // Cleanup temp file if it came from Google
                    if ($googleSheetUrl) delete_tmp_file($file);

                    return $this->response(
                        $action === 'created' ? 201 : 200,
                        "Survey successfully $action.",
                        [
                            'id' => (int)$study->id,
                            'name' => $study->name,
                            'logs' => $messages
                        ]
                    );
                } else {
                    throw new Exception(implode("; ", $study->errors));
                }
            } catch (Exception $e) {
                $this->db->rollBack();
                // Ensure temp file is cleaned up on error
                if ($googleSheetUrl) delete_tmp_file($file);
                return $this->error(500, 'Processing failed: ' . $e->getMessage());
            }
        }

        // Load existing survey for GET, PATCH, DELETE
        $study = SurveyStudy::loadByUserAndName($this->user, $surveyName);
        if (!$study->valid) {
            return $this->error(404, "Survey '$surveyName' not found.");
        }

        switch ($method) {
            case 'GET':
                $this->checkScope('survey:read');

                $reader = new \SpreadsheetReader();
                $format = $this->request->getParam('format');

                switch ($format) {
                    case 'xlsx':
                        $reader->exportItemTableXLSX($study);
                        return;
                    case 'xls':
                        $reader->exportItemTableXLS($study);
                        return;
                    case 'json':
                    default:
                        // This function sets headers, outputs the JSON, and exits the script.
                        // It serves as the fallback for standard GET requests.
                        $reader->exportItemTableJSON($study);
                        return;
                }

            case 'PATCH':
                $this->checkScope('survey:write');
                $updates = $this->getJsonBody();

                if (empty($updates)) {
                    return $this->error(400, 'No updates provided');
                }

                // SurveyStudy->update($arr) performs DB update
                // Note: Does not perform deep item updates, only settings/properties
                $study->update($updates);
                return $this->response(200, 'Survey settings updated');

            case 'DELETE':
                $this->checkScope('survey:write');
                if ($study->delete()) {
                    return $this->response(200, 'Survey deleted');
                }
                return $this->error(500, 'Failed to delete survey');
        }

        return $this->error(405, 'Method not allowed');
    }

    /**
     * Run Resource Handler (/runs)
     * * Manages Run entities (study flows) and routes to sub-resources.
     * * Supported Endpoints:
     * - GET /runs: List all runs.
     * - POST /runs/{name}: Create a new Run.
     * - GET /runs/{name}: Retrieve Run details/settings.
     * - PATCH /runs/{name}: Update Run settings (lock, public status, etc.).
     * - DELETE /runs/{name}: Delete a Run.
     * * Sub-resources dispatched:
     * - /sessions: `handleSessions`
     * - /results: `handleResults`
     * - /files: `handleFiles`
     * - /structure: `handleStructure`
     *
     * @param string|null $runName The name of the run.
     * @param string|null $subResource The sub-resource (e.g., 'sessions').
     * @param mixed $extra Additional segments.
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

                // Build query to fetch runs for the authenticated user
                $select = $this->db->select('id, name, title, public, cron_active, locked, created, modified')
                    ->from('survey_runs')
                    ->where(['user_id' => $this->user->id]);

                if ($nameFilter = $this->request->getParam('name')) {
                    $select->like('name', $nameFilter);
                }
                $publicFilter = $this->request->getParam('public');
                if ($publicFilter !== null && $publicFilter !== '') {
                    $select->where(['public' => (int)$publicFilter]);
                }

                $runs = $select->fetchAll();
                return $this->response(200, 'Runs listed', $runs);
            }
            // Root endpoint doesn't support direct creation via POST if name is required in URL
            // Users should use PUT /runs/{name}
            return $this->error(405, 'Method not allowed. Use PUT /runs/{name} to create a run.');
        }

        // 2. Sub-resource Dispatcher
        if ($subResource) {
            switch ($subResource) {
                case 'sessions':
                    return $this->handleSessions($runName);
                case 'results':
                    return $this->handleResults($runName);
                case 'files':
                    return $this->handleFiles($runName);
                case 'structure':
                    return $this->handleStructure($runName);
                default:
                    return $this->error(404, 'Run sub-resource not found');
            }
        }

        // 3. Specific Run endpoints (/runs/{run_name})

        // HANDLE CREATION (POST)
        // We intercept POST here because getRunFromRequest() returns 404 if the run doesn't exist yet.
        if ($method === 'POST') {
            $this->checkScope('run:write');

            // 1. Check if name already exists
            if (Run::nameExists($runName)) {
                return $this->error(409, "A run with the name '$runName' already exists.");
            }

            // 2. Validate name format (Alpha-numeric, starts with letter, hyphens allowed)
            if (!preg_match("/^[a-zA-Z][a-zA-Z0-9-]{2,255}$/", $runName)) {
                return $this->error(400, "Invalid run name. Must start with a letter, contain only a-z, 0-9, hyphens, and be 3-255 chars long.");
            }

            // 3. Check reserved names
            if (in_array($runName, Config::get('reserved_run_names', []))) {
                return $this->error(400, "Run name '$runName' is reserved.");
            }

            // 4. Create the run
            try {
                $run = new Run();
                $createdName = $run->create([
                    'run_name' => $runName,
                    'user_id'  => $this->user->id
                ]);

                if ($createdName) {
                    return $this->response(201, 'Run created successfully', [
                        'name' => $createdName,
                        'link' => run_url($createdName)
                    ]);
                } else {
                    return $this->error(500, 'Failed to create run.');
                }
            } catch (Exception $e) {
                return $this->error(500, $e->getMessage());
            }
        }

        // HANDLE EXISTING RESOURCES (GET, PATCH, DELETE)
        // Now we verify the run exists and belongs to user
        $mockRequest = (object) ['run' => (object) ['name' => $runName]];
        $run = $this->getRunFromRequest($mockRequest);

        if (!$run) {
            return $this;
        }

        switch ($method) {
            case 'GET':
                $this->checkScope('run:read');

                // Base run data
                $responseData = [
                    'id' => (int) $run->id,
                    'name' => $run->name,
                    'link' => run_url($run->name),
                    'public' => (int) $run->public,
                    'locked' => (bool) $run->locked,
                    'cron_active' => (bool) $run->cron_active,
                    'created' => $run->created,
                    'modified' => $run->modified,
                ];

                // Add patchable settings
                // These match the fields handled in Run::saveSettings()
                // We exclude osf_project_id and vapid keys as they are system-managed
                $settings = [
                    'title' => $run->title,
                    'description' => $run->description,
                    'header_image_path' => $run->header_image_path,
                    'footer_text' => $run->footer_text,
                    'public_blurb' => $run->public_blurb,
                    'privacy' => $run->privacy,
                    'tos' => $run->tos,
                    'use_material_design' => (bool) $run->use_material_design,
                    'expiresOn' => $run->expiresOn,

                    // Cookie expiration settings
                    'expire_cookie_value' => (int) $run->expire_cookie_value,
                    'expire_cookie_unit' => $run->expire_cookie_unit,

                    // Content fields (fetching content, not paths)
                    'custom_css' => $run->getCustomCSS(),
                    'custom_js' => $run->getCustomJS(),
                    'manifest_json' => $run->getManifestJSON(),
                ];

                $responseData = array_merge($responseData, $settings);

                return $this->response(200, 'Run details', $responseData);

            case 'PATCH':
                $this->checkScope('run:write');
                $input = $this->getJsonBody();

                // Prevent updating sensitive or system-managed fields via API
                $restrictedFields = ['vapid_public_key', 'vapid_private_key', 'osf_project_id'];
                foreach ($restrictedFields as $field) {
                    if (isset($input[$field])) {
                        unset($input[$field]);
                    }
                }

                // 1. Update settings FIRST
                // This saves 'expiresOn' to the database, but does NOT update the $run object in memory.
                $settingsSaved = $run->saveSettings($input);

                if (!$settingsSaved) {
                    $errors = !empty($run->errors) ? implode('; ', $run->errors) : 'Unknown error saving settings';
                    return $this->error(400, 'Failed to update run: ' . $errors);
                }

                // 2. Workaround: Manually refresh the in-memory object
                // We must update the local object so togglePublic() sees the new expiration date.
                if (isset($input['expiresOn'])) {
                    $run->expiresOn = $input['expiresOn'];
                }

                // 3. Now perform status toggles
                // These checks will now see the correct 'expiresOn' date.
                if (isset($input['public'])) $run->togglePublic((int)$input['public']);
                if (isset($input['locked'])) $run->toggleLocked((int)$input['locked']);

                return $this->response(200, 'Run updated successfully');

            case 'DELETE':
                $this->checkScope('run:write');
                if ($run->delete()) {
                    return $this->response(200, 'Run deleted successfully');
                } else {
                    $errors = !empty($run->errors) ? implode('; ', $run->errors) : 'Unable to delete run';
                    return $this->error(500, $errors);
                }
        }

        return $this->error(405, 'Method not allowed');
    }

    // --- Private Sub-Resource Handlers ---

    /**
     * Session Sub-Resource Router
     * * Routes /runs/{name}/sessions requests.
     * * Endpoints:
     * - GET /.../sessions: List sessions (supports pagination/filtering).
     * - POST /.../sessions: Create new session(s) (random or specific codes).
     * - GET /.../sessions/{code}: Get details for a specific session.
     * - POST /.../sessions/{code}/actions: Perform logical actions (e.g., 'toggle_testing').
     *
     * @param string $runName
     * @return ApiHelperV1
     */
    private function handleSessions($runName)
    {
        // 1. Validate Run and permissions using parent logic
        $mockRequest = (object) ['run' => (object) ['name' => $runName]];
        $run = $this->getRunFromRequest($mockRequest);
        if (!$run) {
            return $this; // Error already set
        }

        $method = $this->getRequestMethod();
        $sessionsIndex = array_search('sessions', $this->path_segments);

        if ($sessionsIndex === false) {
            return $this->error(500, 'Routing Error');
        }

        $sessionCode = $this->path_segments[$sessionsIndex + 1] ?? null;
        $action      = $this->path_segments[$sessionsIndex + 2] ?? null;

        // 1. List Sessions (GET /runs/{name}/sessions)
        if (empty($sessionCode) && $method === 'GET') {
            return $this->listSessions($run);
        }

        // 2. Create Session (POST /runs/{name}/sessions)
        if (empty($sessionCode) && $method === 'POST') {
            return $this->createSession($run);
        }

        // Validate specific session existence for subsequent actions
        if ($sessionCode) {
            $runSession = new RunSession($sessionCode, $run);
            if (!$runSession->id) {
                return $this->error(404, "Session '$sessionCode' not found in run '$runName'");
            }

            // 3. Get Session Details (GET /runs/{name}/sessions/{code})
            if (empty($action) && $method === 'GET') {
                return $this->getSessionDetails($runSession);
            }

            // 4. Session Actions (POST /runs/{name}/sessions/{code}/actions)
            if ($action === 'actions' && $method === 'POST') {
                return $this->performSessionAction($runSession);
            }
        }

        return $this->error(404, 'Endpoint not found or method not allowed');
    }

    /**
     * List Sessions
     * * Returns a paginated list of sessions for the run.
     * * Supports filtering by `active` (ongoing) or `testing` status.
     *
     * @param Run $run
     * @return ApiHelperV1
     */
    private function listSessions(Run $run)
    {
        $this->checkScope('session:read');

        $limit = (int)$this->request->getParam('limit', 100);
        $offset = (int)$this->request->getParam('offset', 0);
        $active = $this->request->getParam('active'); // true/false
        $testing = $this->request->getParam('testing'); // true/false

        // Build the WHERE clause
        $params = [':run_id' => $run->id];
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

        // Raw SQL bc formrs own SQL-wrapper does not allow for standard aliases (us, u, ru) without errors
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

        // Execute the query
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        // Bind limit/offset as integers
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

    /**
     * Create Sessions
     * * Creates one or more sessions for a run.
     * * Two modes:
     * 1. Random Code: If no body provided, creates 1 session with a crypto-token.
     * 2. Named Codes: Accepts a list of codes. Validates format and checks for duplicates.
     * * @param Run $run
     * @return ApiHelperV1 201 Created (success), 207 Multi-Status (partial), or 400 (fail).
     */
    private function createSession(Run $run)
    {
        $this->checkScope('session:write');
        $body = $this->getJsonBody();

        $codes = $body['code'] ?? null;
        $testing = !empty($body['testing']) ? 1 : 0;

        $createdSessions = [];
        $failedSessions = [];

        // Case A: Create a single random session (no code provided)
        if ($codes === null) {
            $runSession = new RunSession(null, $run);
            if ($runSession->create(null, $testing)) {
                return $this->response(201, 'Session created successfully.', [
                    'count_created' => 1,
                    'sessions' => [$runSession->session]
                ]);
            } else {
                return $this->error(500, 'Failed to create random session.');
            }
        }
        // Case B: Create specific named sessions (codes provided)
        else {
            if (!is_array($codes)) {
                $codes = [$codes];
            }

            $code_rule = Config::get("user_code_regular_expression");

            foreach ($codes as $code) {
                // 1. Enforce URL-safe characters (Global Safety)
                if ($code && !preg_match('/^[a-zA-Z0-9_\-~]+$/', $code)) {
                    $failedSessions[] = [
                        'code' => $code,
                        'reason' => 'Invalid characters. Only alphanumeric and - _ ~ are allowed.'
                    ];
                    continue;
                }

                // 2. Check instance-wide regex (Instance Config)
                if ($code && !preg_match($code_rule, $code)) {
                    $failedSessions[] = [
                        'code' => $code,
                        'reason' => "Does not match required format: $code_rule"
                    ];
                    continue;
                }

                // Initialize session object
                $runSession = new RunSession($code, $run);

                // 3. Check for Duplicate
                // The constructor automatically loads existing data if found.
                // If ID is set, the session already exists.
                if ($runSession->id) {
                    $failedSessions[] = [
                        'code' => $code,
                        'reason' => 'Session already exists.'
                    ];
                    continue;
                }

                // 4. Attempt creation (Database)
                if ($runSession->create($code, $testing)) {
                    $createdSessions[] = $runSession->session;
                } else {
                    $failedSessions[] = [
                        'code' => $code,
                        'reason' => 'Creation failed (database error).'
                    ];
                }
            }

            // Construct the response payload
            $payload = [
                'count_created' => count($createdSessions),
                'sessions' => $createdSessions,
            ];

            // If we had failures, include them in the response
            if (!empty($failedSessions)) {
                $payload['count_failed'] = count($failedSessions);
                $payload['errors'] = $failedSessions;
            }

            // --- Determine Status Code ---

            // 1. Complete Failure: 400 Bad Request
            if (empty($createdSessions) && !empty($failedSessions)) {
                return $this->error(400, 'No sessions were created.');
            }

            // 2. Partial Success: 207 Multi-Status
            if (!empty($createdSessions) && !empty($failedSessions)) {
                return $this->response(207, 'Some sessions were created, but others failed.', $payload);
            }

            // 3. Complete Success: 201 Created
            return $this->response(201, 'Sessions created successfully', $payload);
        }
    }

    /**
     * Get Session Details
     * * Retrieves and formats detailed information for a specific RunSession.
     * This method constructs a standardized response payload including the session's 
     * metadata and, if applicable, the currently active unit details.
     * * @param RunSession $runSession The loaded run session object.
     * @return ApiHelperV1 Standardized API response with session data.
     */
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

        // Add current unit info if available
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

    /**
     * Format Session Row
     * * Transforms a raw database result row into a standardized session array.
     * This helper ensures consistent data types (ints, bools) and structures the 
     * 'current_unit' nested object if joined unit data is present in the row.
     * * @param array $row The raw associative array from the database fetch.
     * @return array The formatted session data array.
     */
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

    /**
     * Execute Session Action
     * * Performs state-changing actions on a specific session.
     * * Actions:
     * - `end_external`: Forces an external unit (like a redirect) to complete.
     * - `toggle_testing`: Switches the session's testing flag.
     * - `move_to_position`: Jumps the user to a specific position index.
     *
     * @param RunSession $runSession
     * @return ApiHelperV1
     */
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

            case 'toggle_testing': // ToDo Test if this is the right way to highlight a session for testing?
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

            default:
                return $this->error(400, "Invalid action: '$action'. Supported: end_external, toggle_testing, move_to_position");
        }
    }

    /**
     * Results Retrieval Handler
     * * Fetches flattened survey results for a run.
     * * Scope required: `data:read`
     * * Supports filtering by specific surveys, items, or sessions via query params.
     *
     * @param string $runName
     * @return ApiHelperV1
     */
    private function handleResults($runName)
    {
        if ($this->getRequestMethod() !== 'GET') {
            return $this->error(405, 'Method not allowed. Use GET.');
        }

        // 1. Security & Config
        $this->checkScope('data:read');
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

            $itemsString = ($s->items && is_array($s->items)) ? implode(',', $s->items) : $s->items;

            $surveyData = $this->getSurveyResults(
                $run,
                $s->name,
                $itemsString, // <--- Passed as "item1,item2"
                $filterSessions
            );

            $results[$s->name] = $surveyData;
        }

        $results['shuffles'] = $this->getShuffleResults($run, $filterSessions);

        return $this->response(200, 'OK', $results);
    }

    /**
     * File Management Handler
     * * Manages media/documents attached to a run.
     * * Endpoints:
     * - GET: List files with their public URLs.
     * - POST: Upload a file (multipart/form-data).
     * - DELETE: Remove a file.
     * * @param string $runName
     * @return ApiHelperV1
     */
    private function handleFiles($runName)
    {
        // 1. Initialize Run Model
        $mockRequest = (object) ['run' => (object) ['name' => $runName]];
        $run = $this->getRunFromRequest($mockRequest);

        if (!$run) {
            return $this;
        }

        $method = $this->getRequestMethod();
        $fileName = $this->getUriSegment(3);

        // --- LIST FILES (GET) ---
        if (empty($fileName) && $method === 'GET') {
            $this->checkScope('file:read');

            $files = $run->getUploadedFiles();
            $fileList = [];

            // 1. Construct Base URL using Config (ignores current API subdomain)
            $protocol = Config::get('protocol');
            $admin_domain = Config::get('admin_domain');
            $baseUrl = rtrim($protocol . $admin_domain, '/') . '/';

            foreach ($files as $f) {
                // 2. Prepare Path: Encode spaces/special chars, but keep directory slashes
                $relativePath = $f['new_file_path'];
                $pathParts = explode('/', $relativePath);
                $encodedParts = array_map('rawurlencode', $pathParts);
                $encodedPath = implode('/', $encodedParts);

                // 3. Add Cache Busting (?v=timestamp)
                // We check the physical file to get the modification time
                $fullPhysicalPath = APPLICATION_ROOT . "webroot/" . $relativePath;
                $queryString = '';
                if (file_exists($fullPhysicalPath)) {
                    $mtime = filemtime($fullPhysicalPath);
                    if ($mtime) {
                        $queryString = "?v=" . $mtime;
                    }
                }

                $fileList[] = [
                    'id' => (int)$f['id'],
                    'name' => $f['original_file_name'],
                    'path' => $relativePath,
                    'url'  => $baseUrl . $encodedPath . $queryString,
                    'created' => $f['created'],
                    'modified' => $f['modified']
                ];
            }

            return $this->response(200, 'Files retrieved successfully', $fileList);
        }

        // --- UPLOAD FILE (POST) ---
        if (empty($fileName) && $method === 'POST') {
            $this->checkScope('file:write');

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                return $this->error(400, 'No valid file uploaded. Send file as multipart/form-data with key "file".');
            }

            // 1. Sanitize the filename (Replace spaces with underscores)
            $originalName = $_FILES['file']['name'];
            $sanitizedName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);

            // 2. Wrap single file into array format expected by Run::uploadFiles
            $filesPayload = [
                'name'     => [$sanitizedName],
                'type'     => [$_FILES['file']['type']],
                'tmp_name' => [$_FILES['file']['tmp_name']],
                'error'    => [$_FILES['file']['error']],
                'size'     => [$_FILES['file']['size']]
            ];

            $result = $run->uploadFiles($filesPayload);

            if ($result === false && !empty($run->errors)) {
                return $this->error(400, implode(' ', $run->errors));
            }

            return $this->response(201, 'File uploaded successfully', [
                'messages' => $run->messages,
                'file' => $sanitizedName
            ]);
        }

        // --- DELETE FILE (DELETE) ---
        if ($fileName && $method === 'DELETE') {
            $this->checkScope('file:write');

            // Decode the filename from URL (e.g. "my%20image.jpg" -> "my image.jpg")
            $decodedFileName = urldecode($fileName);

            $fileRecord = $this->db->findRow('survey_uploaded_files', [
                'run_id' => $run->id,
                'original_file_name' => $decodedFileName
            ]);

            if (!$fileRecord) {
                return $this->error(404, "File '$decodedFileName' not found in this run.");
            }

            if ($run->deleteFile($fileRecord['id'], $fileRecord['original_file_name'])) {
                return $this->response(200, "File '$decodedFileName' deleted successfully");
            }

            return $this->error(500, "Failed to delete file '$decodedFileName'.");
        }

        return $this->error(405, 'Method not allowed');
    }

    /**
     * Structure Import/Export Handler
     * * Manages the JSON representation of the Run's flow logic (RunUnits).
     * * Endpoints:
     * - GET: Export full run structure as JSON.
     * - PUT: Import structure from JSON (replaces existing units).
     * * @param string $runName
     * @return ApiHelperV1
     */
    private function handleStructure($runName)
    {
        // 1. Get the run object
        $mockRequest = (object) ['run' => (object) ['name' => $runName]];
        $run = $this->getRunFromRequest($mockRequest);

        if (!$run) {
            return $this;
        }

        $method = $this->getRequestMethod();

        // 2. EXPORT (GET)
        if ($method === 'GET') {
            $this->checkScope('run:read');

            try {
                // Use the model's native export method
                // exportStructure() returns an array ready for JSON encoding
                $exportData = $run->exportStructure();

                if (!$exportData) {
                    return $this->error(500, 'Failed to generate export structure.');
                }

                return $this->response(200, 'Run structure exported', $exportData);
            } catch (Exception $e) {
                return $this->error(500, 'Export error: ' . $e->getMessage());
            }
        }

        // 3. IMPORT (PUT)
        if ($method === 'PUT') {
            $this->checkScope('run:write');

            $jsonString = file_get_contents('php://input');
            $jsonData = json_decode($jsonString);

            if (!$jsonData) {
                return $this->error(400, 'Invalid JSON body.');
            }

            // [STEP 1] Determine expected count
            $expectedCount = 0;
            if (isset($jsonData->units) && is_array($jsonData->units)) {
                $expectedCount = count($jsonData->units);
            } elseif (isset($jsonData->units) && is_object($jsonData->units)) {
                // Handle case where units might be an object keyed by ID
                $expectedCount = count((array)$jsonData->units);
            }

            try {
                Site::getInstance()->renderAlerts(); // Clear alerts

                // [STEP 2] Execute Import
                // We ignore the return value's count for validation now
                $importedUnits = $run->replaceUnits($jsonString);

                // [STEP 3] Verify the FINAL STATE of the Run
                // We check the database: Does the run have the units?
                $runUnits = $run->getAllUnitIds();
                $actualRunCount = is_array($runUnits) ? count($runUnits) : 0;

                // [STEP 4] Validation Logic
                // If the Run has as many (or more) units as we tried to import, it's a success.
                // This bypasses the issue where "Table Exists" errors cause importUnits to return an empty set.
                if ($actualRunCount >= $expectedCount) {
                    $msg = "Import successful. Run contains $actualRunCount units.";

                    // Check for warnings
                    $alertsHtml = Site::getInstance()->renderAlerts();
                    if (stripos($alertsHtml, 'alert-danger') !== false) {
                        $msg .= " (Note: Some internal alerts were triggered, but the run structure appears complete.)";
                    }

                    return $this->response(200, $msg);
                }

                // [STEP 5] Genuine Failure
                // The run has FEWER units than expected. Something actually went wrong.
                $alertsText = trim(strip_tags(Site::getInstance()->renderAlerts()));
                $errorMsg = "Import incomplete: Run has $actualRunCount units, expected $expectedCount.";

                if ($alertsText) {
                    $errorMsg .= " Reason: " . $alertsText;
                } else {
                    $errorMsg .= " The import failed because the run structure contains invalid data. Please ensure that all units have valid, numeric 'position' values and that all jump destinations (e.g., in SkipForward/Backward units) are numbers, not strings.";
                }

                return $this->error(500, $errorMsg);
            } catch (Exception $e) {
                return $this->error(500, 'Import exception: ' . $e->getMessage());
            }
        }

        return $this->error(405, 'Method not allowed. Use GET to export or PUT to import.');
    }
}
