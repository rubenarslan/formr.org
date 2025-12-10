<?php

class ApiHelperV1 extends ApiBase
{

    protected $path_segments;


    // --- Entry Points (Public Methods mapping to top-level URL segments) ---

    /**
     * User Resource Endpoint (/user).
     * Handles operations for the authenticated user.
     * * @return ApiHelperV1
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
     * Retrieve the authenticated user's profile.
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
     * Update allowed fields for the authenticated user.
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
     * Surveys Resource Hub (/surveys)
     * * Handles operations for Survey objects (item tables, settings).
     * Distinguishes between Surveys (the forms) and Runs (the flow logic).
     *
     * @param string|null $surveyName
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

                $data = $study->getSettings(); // Returns array of settings
                $data['id'] = $study->id;
                $data['name'] = $study->name;
                $data['results_table'] = $study->results_table;
                $data['created'] = $study->created;
                $data['modified'] = $study->modified;

                // Include items in detail view
                $data['items'] = $study->getItemsWithChoices();

                return $this->response(200, 'Survey details', $data);

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

        // HANDLE CREATION (PUT)
        // We intercept PUT here because getRunFromRequest() returns 404 if the run doesn't exist yet.
        if ($method === 'PUT') {
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
                $responseData = [
                    'id' => (int) $run->id,
                    'name' => $run->name,
                    'title' => $run->title,
                    'description' => $run->description,
                    'public' => (int) $run->public,
                    'cron_active' => (bool) $run->cron_active,
                    'locked' => (bool) $run->locked,
                    'created' => $run->created,
                    'modified' => $run->modified,
                    'link' => run_url($run->name),
                ];
                return $this->response(200, 'Run details', $responseData);

            case 'PATCH':
                $this->checkScope('run:write');
                $input = $this->getJsonBody();

                if (isset($input['public'])) $run->togglePublic((int)$input['public']);
                if (isset($input['locked'])) $run->toggleLocked((int)$input['locked']);

                if ($run->saveSettings($input)) {
                    return $this->response(200, 'Run updated successfully');
                } else {
                    $errors = !empty($run->errors) ? implode('; ', $run->errors) : 'Unknown error';
                    return $this->error(400, 'Failed to update run: ' . $errors);
                }

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
     * Handles /runs/{name}/sessions
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

    private function listSessions(Run $run)
    {
        $this->checkScope('session:read');

        $limit = (int)$this->request->getParam('limit', 100);
        $offset = (int)$this->request->getParam('offset', 0);
        $active = $this->request->getParam('active'); // true/false
        $testing = $this->request->getParam('testing'); // true/false

        $select = $this->db->select('session, position, last_access, created, ended, testing')
            ->from('survey_run_sessions')
            ->where(['run_id' => $run->id]);

        if ($active !== null) {
            if ($active === 'true' || $active === '1') {
                $select->where('ended IS NULL');
            } elseif ($active === 'false' || $active === '0') {
                $select->where('ended IS NOT NULL');
            }
        }

        if ($testing !== null) {
            $select->where(['testing' => ($testing === 'true' || $testing === '1' ? 1 : 0)]);
        }

        $select->limit($limit, $offset);
        $select->order('created', 'DESC');

        $sessions = $select->fetchAll();
        return $this->response(200, 'Sessions list', $sessions);
    }

    private function createSession(Run $run)
    {
        $this->checkScope('session:write'); //ToDo: should this be an open Endpoint?
        $body = $this->getJsonBody();

        $codes = $body['code'] ?? null;
        $testing = !empty($body['testing']) ? 1 : 0;
        $createdSessions = [];

        // Support creating a single random session if no code provided
        if ($codes === null) {
            $runSession = new RunSession(null, $run);
            if ($runSession->create(null, $testing)) {
                $createdSessions[] = $runSession->session;
            }
        } else {
            // Support single code string or array of strings
            if (!is_array($codes)) {
                $codes = [$codes];
            }

            foreach ($codes as $code) {
                // Check regex validation from settings
                $code_rule = Config::get("user_code_regular_expression");
                if ($code && !preg_match($code_rule, $code)) {
                    // ToDo: Skip invalid codes or return error? For batch, maybe skip and report?
                    continue;
                }

                $runSession = new RunSession($code, $run);
                // create() checks if exists or creates new. 
                // Note: RunSession->create returns data on success, false on fail
                if ($runSession->create($code, $testing)) {
                    $createdSessions[] = $runSession->session;
                }
            }
        }

        if (empty($createdSessions)) {
            return $this->error(400, 'No sessions created. Check code validity or database constraints.');
        }

        return $this->response(201, 'Sessions created', [
            'count' => count($createdSessions),
            'sessions' => $createdSessions
        ]);
    }

    private function getSessionDetails(RunSession $runSession)
    {
        $this->checkScope('session:read');

        $data = $runSession->toArray();

        // Add current unit info if available
        $currentUnitSession = $runSession->getCurrentUnitSession();
        if ($currentUnitSession) {
            $data['current_unit'] = [
                'id' => $currentUnitSession->runUnit->id,
                'type' => $currentUnitSession->runUnit->type,
                'description' => $currentUnitSession->runUnit->description,
                'session_id' => $currentUnitSession->id
            ];
        }

        return $this->response(200, 'Session details', $data);
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
     * Results Sub-Resource Handler.
     * * GET /v1/runs/{name}/results
     * Retrieves survey results for a specific run. Supports filtering via 
     * query parameters (sessions, surveys, items).
     *
     * @param string $runName The name of the run.
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

        return $this->response(200, 'OK', $results);
    }

    /**
     * Handles /runs/{name}/files
     * * Supported actions:
     * - GET /runs/{name}/files : List all files uploaded to this run
     * - POST /runs/{name}/files : Upload a new file (Multipart form-data, key="file")
     * - DELETE /runs/{name}/files/{filename} : Delete a specific file
     *   ToDo: if file-names containe a "space", they cant be deleted via API
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

            // Wrap single file into array format expected by Run::uploadFiles
            $filesPayload = [
                'name'     => [$_FILES['file']['name']],
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
                'file' => $_FILES['file']['name']
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
     * Structure Sub-Resource Handler.
     * * GET /v1/runs/{name}/structure : Export Run JSON
     * * PUT /v1/runs/{name}/structure : Import Run JSON
     *
     * @param string $runName
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

            // Get raw JSON body
            $jsonString = file_get_contents('php://input');
            $jsonData = json_decode($jsonString);

            if (!$jsonData) {
                return $this->error(400, 'Invalid JSON body.');
            }

            // Basic validation of the import format
            if (!isset($jsonData->units) && !isset($jsonData->settings)) {
                return $this->error(400, 'Invalid import format. JSON must contain "units" or "settings".');
            }

            try {
                // importUnits expects a JSON string, not a decoded object
                // We use the raw input string to preserve structure fidelity
                $importedUnits = $run->importUnits($jsonString);

                if ($importedUnits === false) {
                    return $this->error(500, 'Import failed. Check run logs for details.');
                }

                $count = count($importedUnits);
                return $this->response(200, "Import successful. $count units imported/updated.");
            } catch (Exception $e) {
                return $this->error(500, 'Import error: ' . $e->getMessage());
            }
        }

        return $this->error(405, 'Method not allowed. Use GET to export or PUT to import.');
    }

    // --- Helpers ---

    /**
     * Standardizes responses - ToDo check against ApiBase implementation
     */
    private function response($code, $msg, $data = [])
    {
        $this->setData($code, $msg, $data);
        return $this;
    }

    // ToDo check against ApiBase implementation
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
}
