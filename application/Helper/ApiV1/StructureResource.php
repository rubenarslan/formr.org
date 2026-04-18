<?php

class StructureResource extends BaseResource
{

    private $run;

    public function handle($runName = null)
    {
        $this->run = $this->getRunByName($runName);
        if (!$this->run) {
            return $this;
        }

        $method = $this->getRequestMethod();

        if ($method === 'GET') {
            return $this->exportStructure();
        }

        if ($method === 'PUT') {
            return $this->importStructure();
        }

        return $this->error(405, 'Method not allowed. Use GET to export or PUT to import.');
    }

    private function exportStructure()
    {
        $this->checkScope('run:read');

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
        $this->checkScope('run:write');

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
            $this->run->replaceUnits($jsonString);
        } catch (Exception $e) {
            return $this->error(500, 'Import exception: ' . $e->getMessage());
        }

        $runUnits = $this->run->getAllUnitIds();
        $actualRunCount = is_array($runUnits) ? count($runUnits) : 0;
        $alertsText = trim(strip_tags($site->renderAlerts()));

        if ($actualRunCount < $expectedCount) {
            $errorMsg = "Import incomplete: run has $actualRunCount units, expected $expectedCount.";
            if ($alertsText) {
                $errorMsg .= ' ' . $alertsText;
            }
            return $this->error(500, $errorMsg);
        }

        $payload = ['units_imported' => $actualRunCount];
        if ($alertsText) {
            $payload['warnings'] = $alertsText;
        }
        return $this->response(200, 'Run structure imported', $payload);
    }
}
