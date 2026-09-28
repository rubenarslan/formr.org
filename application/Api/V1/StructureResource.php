<?php

class StructureResource extends BaseResource
{

    private $run;

    public function handle($runName = null)
    {
        $method = $this->getRequestMethod();

        // Scope check before run lookup (403 over 404 for nonexistent runs).
        if ($method === 'GET') {
            $this->checkScope('run:read');
        } elseif ($method === 'PUT') {
            $this->checkScope('run:write');
        } else {
            return $this->error(405, 'Method not allowed. Use GET to export or PUT to import.');
        }

        $this->run = $this->getRunByName($runName);
        if (!$this->run) {
            return $this;
        }

        if ($method === 'GET') {
            return $this->exportStructure();
        }

        return $this->importStructure();
    }

    private function exportStructure()
    {
        try {
            $exportData = $this->run->exportStructure();

            if (!$exportData) {
                return $this->error(500, 'Failed to generate export structure.');
            }

            return $this->response(200, 'Run structure exported', $exportData);
        } catch (Exception $e) {
            return $this->error(500, 'Export error: ' . $e->getMessage());
        }
    }

    private function importStructure()
    {
        $jsonString = file_get_contents('php://input');
        $jsonData = json_decode($jsonString);

        if (!$jsonData) {
            return $this->error(400, 'Invalid JSON body.');
        }

        $expectedCount = isset($jsonData->units) ? count((array) $jsonData->units) : 0;

        // Run::importUnits signals errors via global alert() calls instead of
        // structured returns. Flush any pre-existing alerts so anything left
        // after the import can be attributed to this request.
        $site = Site::getInstance();
        $site->renderAlerts();

        try {
            $imported = $this->run->replaceUnits($jsonString);
        } catch (Throwable $e) {
            return $this->error(500, 'Import exception: ' . $e->getMessage());
        }

        $runUnits = $this->run->getAllUnitIds();
        $actualRunCount = is_array($runUnits) ? count($runUnits) : 0;
        $alertsText = trim(strip_tags($site->renderAlerts()));
        $importErrors = $this->run->getImportErrors();

        // Decide on replaceUnits()' own verdict first. The unit count alone
        // is not evidence: a rolled-back import leaves the PREVIOUS
        // structure in place, which can have exactly the expected count
        // (a rejected upload then reported 200 "imported").
        if ($imported === false || $actualRunCount < $expectedCount) {
            // A problem with the payload is the client's (400); a database
            // failure is ours (500), and a retry may succeed.
            $status = ($importErrors && !$this->run->importFailedOnServer()) ? 400 : 500;
            $errorMsg = $importErrors
                ? 'Import failed: ' . implode(' | ', $importErrors)
                : "Import incomplete: run has $actualRunCount units, expected $expectedCount.";
            if ($alertsText) {
                $errorMsg .= ' ' . $alertsText;
            }
            return $this->error($status, $errorMsg);
        }

        $payload = ['units_imported' => $actualRunCount];
        if ($alertsText) {
            $payload['warnings'] = $alertsText;
        }
        return $this->response(200, 'Run structure imported', $payload);
    }
}
