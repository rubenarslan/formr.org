<?php

class SurveyResource extends BaseResource
{

    public function handle()
    {
        $method = $this->getRequestMethod();
        $surveyName = $this->getUriSegment(1);

        if (empty($surveyName)) {
            if ($method === 'GET') {
                return $this->listSurveys();
            }
            if ($method === 'POST') {
                return $this->createOrUpdateSurvey();
            }
            if ($method === 'PUT') {
                return $this->error(405, 'Method not allowed. Use POST /surveys to create a survey.');
            }
            return $this->error(405, 'Method not allowed');
        }

        switch ($method) {
            case 'GET':
                $study = SurveyStudy::loadByUserAndName($this->user, $surveyName);
                if (!$study->valid) {
                    return $this->error(404, "Survey '$surveyName' not found.");
                }
                return $this->getSurvey($study);

            case 'PATCH':
                $study = SurveyStudy::loadByUserAndName($this->user, $surveyName);
                if (!$study->valid) {
                    return $this->error(404, "Survey '$surveyName' not found.");
                }
                return $this->updateSurvey($study);

            case 'DELETE':
                $study = SurveyStudy::loadByUserAndName($this->user, $surveyName);
                if (!$study->valid) {
                    return $this->error(404, "Survey '$surveyName' not found.");
                }
                return $this->deleteSurvey($study);
        }

        return $this->error(405, 'Method not allowed');
    }

    private function listSurveys()
    {
        $this->checkScope('survey:read');

        $select = $this->db->select('id, name, created, modified, results_table')
            ->from('survey_studies')
            ->where(['user_id' => $this->user->id]);

        if ($nameFilter = $this->request->getParam('name')) {
            $select->like('name', $nameFilter);
        }

        $surveys = $select->fetchAll();
        return $this->response(200, 'Surveys listed', $surveys);
    }

    private function createOrUpdateSurvey()
    {
        $this->checkScope('survey:write');

        $file = null;
        // Check for Google Sheet URL in POST request
        $googleSheetUrl = $this->request->str('google_sheet');

        if (!empty($googleSheetUrl)) {
            try {
                $file = $this->fetchAndValidateGoogleSheet($googleSheetUrl);
            } catch (Exception $e) {
                return $this->error(400, $e->getMessage());
            }
        } elseif (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['file'];
        } else {
            return $this->error(400, 'A valid file upload (key: "file") OR Google Sheet URL (key: "google_sheet") is required.');
        }

        // Validate extension
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $allowed = ['xls', 'xlsx', 'ods', 'csv', 'txt', 'xml'];
        if (!in_array(strtolower($ext), $allowed)) {
            if ($googleSheetUrl) delete_tmp_file($file);
            return $this->error(400, 'Invalid file type. Allowed: ' . implode(', ', $allowed));
        }

        $fileName = basename($file['name']);
        $derivedName = preg_filter("/^([a-zA-Z][a-zA-Z0-9_]{2,64})(-[a-z0-9A-Z]+)?\.[a-z]{3,4}$/", "$1", $fileName);

        if (!$derivedName) {
            if ($googleSheetUrl) delete_tmp_file($file);
            return $this->error(400, "Invalid file name. It must match the pattern for survey names (alphanumeric, 3-64 chars).");
        }

        $surveyName = $derivedName;

        try {
            $this->db->beginTransaction();

            $study = SurveyStudy::loadByUserAndName($this->user, $surveyName);

            $options = [
                'user_id' => $this->user->id,
                'survey_name' => $surveyName
            ];

            if ($study->valid) {
                if ($googleSheetUrl && isset($file['google_file_id'])) {
                    if ($study->google_file_id != $file['google_file_id']) {
                        $study->google_file_id = $file['google_file_id'];
                        $study->save();
                    }
                }

                $success = $study->uploadItems($file, true);
                $action = 'updated';
            } else {
                $study = new SurveyStudy();
                if (!$study->createFromFile($file, $options)) {
                    throw new Exception("Failed to initialize survey structure.");
                }

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
            if ($googleSheetUrl) delete_tmp_file($file);
            return $this->error(500, 'Processing failed: ' . $e->getMessage());
        }
    }

    private function getSurvey($study)
    {
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
                $reader->exportItemTableJSON($study);
                return;
        }
    }

    private function deleteSurvey($study)
    {
        $this->checkScope('survey:write');
        if ($study->delete()) {
            return $this->response(200, 'Survey deleted');
        }
        return $this->error(500, 'Failed to delete survey');
    }

    /**
     * Fields a client may set via PATCH /v1/surveys/{name}.
     * Whitelist only — never include id, user_id, name, results_table,
     * original_file, google_file_id, created, modified, valid.
     */
    private static $updatableFields = [
        'maximum_number_displayed',
        'displayed_percentage_maximum',
        'add_percentage_points',
        'expire_after',
        'expire_invitation_after',
        'expire_invitation_grace',
        'enable_instant_validation',
        'unlinked',
        'hide_results',
        'use_paging',
    ];

    private function updateSurvey($study)
    {
        $this->checkScope('survey:write');
        $payload = $this->getJsonBody();

        if (empty($payload)) {
            return $this->error(400, 'No updates provided');
        }

        try {
            // 1. Handle Google Sheet Sync (Side Effect of updating the sheet URL)
            if (isset($payload['google_sheet'])) {
                $googleSheetUrl = $payload['google_sheet'];

                // Use shared helper to validate and download
                $file = $this->fetchAndValidateGoogleSheet($googleSheetUrl);

                // Update the file ID linkage
                if (isset($file['google_file_id'])) {
                    $study->google_file_id = $file['google_file_id'];
                    // We assign this to the object, but save() is called inside uploadItems or update
                }

                // IMPORTANT: Trigger the sync logic
                // This ensures the questions in the DB match the new sheet
                $success = $study->uploadItems($file, true);

                // Cleanup temp file
                delete_tmp_file($file);

                if (!$success) {
                    throw new Exception("Failed to sync items from Google Sheet: " . implode("; ", $study->errors));
                }
            }

            // 2. Apply settings updates from a strict whitelist. Anything else is
            // dropped silently — this prevents mass-assignment of user_id,
            // results_table (used as a SQL identifier in UnitSession), id, etc.
            $updates = array_intersect_key($payload, array_flip(self::$updatableFields));

            if (!empty($updates)) {
                $study->update($updates);
            }

            return $this->response(200, 'Survey updated successfully.');
        } catch (Exception $e) {
            return $this->error(400, $e->getMessage()); // 400 Bad Request for validation/logic errors
        }
    }

    /**
     * Helper to validate and download Google Sheets.
     * Extracts logic previously buried in createOrUpdateSurvey.
     */
    private function fetchAndValidateGoogleSheet($url)
    {
        $parsedUrl = parse_url($url);

        // 1. Enforce HTTPS
        $scheme = isset($parsedUrl['scheme']) ? strtolower($parsedUrl['scheme']) : '';
        if ($scheme !== 'https') {
            throw new Exception("Invalid URL. Only secure (HTTPS) Google Sheet URLs are allowed.");
        }

        // 2. Strict Host Whitelist
        $host = strtolower($parsedUrl['host'] ?? '');
        $allowedHosts = ['docs.google.com'];

        $validHost = false;
        foreach ($allowedHosts as $allowed) {
            if ($host === $allowed || substr($host, -strlen('.' . $allowed)) === '.' . $allowed) {
                $validHost = true;
                break;
            }
        }

        if (!$validHost) {
            throw new Exception("Invalid Google Sheet URL. Domain not allowed.");
        }

        $file = google_download_survey_sheet($url);

        if (!$file) {
            throw new Exception("Unable to download the Google Sheet. Please check the link and permissions.");
        }

        return $file;
    }
}
