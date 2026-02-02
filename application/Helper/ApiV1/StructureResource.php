<?php

class StructureResource extends BaseResource
{

    private $run;

    public function handle($runName = null)
    {
        $this->validateRun($runName);
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

    private function validateRun($runName)
    {
        $mockRequest = (object) ['run' => (object) ['name' => $runName]];
        $this->run = $this->getRunFromRequest($mockRequest);
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

        $expectedCount = 0;
        if (isset($jsonData->units) && is_array($jsonData->units)) {
            $expectedCount = count($jsonData->units);
        } elseif (isset($jsonData->units) && is_object($jsonData->units)) {
            $expectedCount = count((array)$jsonData->units);
        }

        try {
            Site::getInstance()->renderAlerts();

            $importedUnits = $this->run->replaceUnits($jsonString);

            $runUnits = $this->run->getAllUnitIds();
            $actualRunCount = is_array($runUnits) ? count($runUnits) : 0;

            if ($actualRunCount >= $expectedCount) {
                $msg = "Import successful. Run contains $actualRunCount units.";

                $alertsHtml = Site::getInstance()->renderAlerts();
                if (stripos($alertsHtml, 'alert-danger') !== false) {
                    $msg .= " (Note: Some internal alerts were triggered, but the run structure appears complete.)";
                }

                return $this->response(200, $msg);
            }

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
}
