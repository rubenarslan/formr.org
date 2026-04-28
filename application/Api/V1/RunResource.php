<?php

class RunResource extends BaseResource
{

    public function handle()
    {
        $runName = $this->getUriSegment(1);
        $subResource = $this->getUriSegment(2);

        if (empty($runName)) {
            return $this->handleRoot();
        }

        if ($subResource) {
            return $this->handleSubResource($runName, $subResource);
        }

        return $this->handleSpecificRun($runName);
    }

    private function handleRoot()
    {
        $method = $this->getRequestMethod();

        if ($method === 'GET') {
            $this->checkScope('run:read');

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

        return $this->error(405, 'Method not allowed. Use POST /runs/{name} to create a run.');
    }

    private function handleSubResource($runName, $subResource)
    {
        $resourceClass = null;
        switch ($subResource) {
            case 'sessions':
                $resourceClass = new SessionResource($this->request, $this->db, $this->tokenData);
                break;
            case 'results':
                $resourceClass = new ResultsResource($this->request, $this->db, $this->tokenData);
                break;
            case 'files':
                $resourceClass = new FileResource($this->request, $this->db, $this->tokenData);
                break;
            case 'structure':
                $resourceClass = new StructureResource($this->request, $this->db, $this->tokenData);
                break;
            default:
                return $this->error(404, 'Run sub-resource not found');
        }

        $resourceClass->setPathSegments($this->path_segments);
        return $resourceClass->handle($runName);
    }

    private function handleSpecificRun($runName)
    {
        $method = $this->getRequestMethod();

        if ($method === 'POST') {
            return $this->createRun($runName);
        }

        $run = $this->getRunByName($runName);

        if (!$run) {
            return $this;
        }

        switch ($method) {
            case 'GET':
                return $this->getRun($run);

            case 'PATCH':
                return $this->updateRun($run);

            case 'DELETE':
                return $this->deleteRun($run);
        }

        return $this->error(405, 'Method not allowed');
    }

    private function createRun($runName)
    {
        $this->checkScope('run:write');

        if (Run::nameExists($runName)) {
            return $this->error(409, "A run with the name '$runName' already exists.");
        }

        if (!preg_match("/^[a-zA-Z][a-zA-Z0-9-]{2,255}$/", $runName)) {
            return $this->error(400, "Invalid run name. Must start with a letter, contain only a-z, 0-9, hyphens, and be 3-255 chars long.");
        }

        if (Run::isReservedName($runName)) {
            return $this->error(400, "Run name '$runName' uses a reserved name or prefix.");
        }

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

    private function getRun($run)
    {
        $this->checkScope('run:read');

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
            'expire_cookie_value' => (int) $run->expire_cookie_value,
            'expire_cookie_unit' => $run->expire_cookie_unit,
            'custom_css' => $run->getCustomCSS(),
            'custom_js' => $run->getCustomJS(),
            'manifest_json' => $run->getManifestJSON(),
        ];

        $responseData = array_merge($responseData, $settings);

        return $this->response(200, 'Run details', $responseData);
    }

    private function updateRun($run)
    {
        $this->checkScope('run:write');
        $input = $this->getJsonBody();

        $restrictedFields = ['vapid_public_key', 'vapid_private_key', 'osf_project_id', 'name'];
        foreach ($restrictedFields as $field) {
            if (isset($input[$field])) {
                unset($input[$field]);
            }
        }

        $textFields = ['title', 'description', 'footer_text', 'public_blurb', 'privacy', 'tos'];
        foreach ($textFields as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = htmlspecialchars(trim($input[$field]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $settingsSaved = $run->saveSettings($input);

        if (!$settingsSaved) {
            $errors = !empty($run->errors) ? implode('; ', $run->errors) : 'Unknown error saving settings';
            return $this->error(400, 'Failed to update run: ' . $errors);
        }

        if (isset($input['expiresOn'])) {
            $run->expiresOn = $input['expiresOn'];
        }

        if (isset($input['public'])) $run->togglePublic((int)$input['public']);
        if (isset($input['locked'])) $run->toggleLocked((int)$input['locked']);

        return $this->response(200, 'Run updated successfully');
    }

    private function deleteRun($run)
    {
        $this->checkScope('run:write');
        if ($run->delete()) {
            return $this->response(200, 'Run deleted successfully');
        } else {
            $errors = !empty($run->errors) ? implode('; ', $run->errors) : 'Unable to delete run';
            return $this->error(500, $errors);
        }
    }
}
