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
            if ($method === 'PUT') {
                return $this->error(405, 'Method not allowed. Use POST /surveys/{survey_name} to create a survey.');
            }
            return $this->error(405, 'Method not allowed');
        }

        switch ($method) {
            case 'POST':
                return $this->createOrUpdateSurvey($surveyName);

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

    private function createOrUpdateSurvey($surveyName)
    {
        $this->checkScope('run:write');

        $file = null;
        $googleSheetUrl = $this->request->str('google_sheet');

        if (!empty($googleSheetUrl)) {
            $file = google_download_survey_sheet($googleSheetUrl);

            if (!$file) {
                return $this->error(400, "Unable to download the Google Sheet. Please check the link and permissions.");
            }
        } elseif (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['file'];
        } else {
            return $this->error(400, 'A valid file upload (key: "file") OR Google Sheet URL (key: "google_sheet") is required.');
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $allowed = ['xls', 'xlsx', 'ods', 'csv', 'txt', 'xml'];
        if (!in_array(strtolower($ext), $allowed)) {
            if ($googleSheetUrl) delete_tmp_file($file);
            return $this->error(400, 'Invalid file type. Allowed: ' . implode(', ', $allowed));
        }

        try {
            $this->db->beginTransaction();

            $study = SurveyStudy::loadByUserAndName($this->user, $surveyName);

            $file['name'] = $surveyName . '.' . $ext;

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

    private function updateSurvey($study)
    {
        $this->checkScope('survey:write');
        $updates = $this->getJsonBody();

        if (empty($updates)) {
            return $this->error(400, 'No updates provided');
        }

        $study->update($updates);
        return $this->response(200, 'Survey settings updated');
    }

    private function deleteSurvey($study)
    {
        $this->checkScope('survey:write');
        if ($study->delete()) {
            return $this->response(200, 'Survey deleted');
        }
        return $this->error(500, 'Failed to delete survey');
    }
}
