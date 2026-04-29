<?php

class FileResource extends BaseResource
{

    private $run;

    public function handle($runName = null)
    {
        $method = $this->getRequestMethod();
        $fileName = $this->getUriSegment(3);

        // Scope first: a token without file:read/write should get 403 even
        // if the run doesn't exist or isn't theirs.
        if ($method === 'GET' && empty($fileName)) {
            $this->checkScope('file:read');
        } elseif (($method === 'POST' && empty($fileName)) || ($method === 'DELETE' && $fileName)) {
            $this->checkScope('file:write');
        } else {
            return $this->error(405, 'Method not allowed');
        }

        $this->run = $this->getRunByName($runName);
        if (!$this->run) {
            return $this;
        }

        if (empty($fileName) && $method === 'GET') {
            return $this->listFiles();
        }

        if (empty($fileName) && $method === 'POST') {
            return $this->uploadFile();
        }

        if ($fileName && $method === 'DELETE') {
            return $this->deleteFile($fileName);
        }

        return $this->error(405, 'Method not allowed');
    }

    private function listFiles()
    {
        $files = $this->run->getUploadedFiles();
        $fileList = [];

        $protocol = Config::get('protocol');
        $admin_domain = Config::get('admin_domain');
        $baseUrl = rtrim($protocol . $admin_domain, '/') . '/';

        foreach ($files as $f) {
            $relativePath = $f['new_file_path'];
            $pathParts = explode('/', $relativePath);
            $encodedParts = array_map('rawurlencode', $pathParts);
            $encodedPath = implode('/', $encodedParts);

            $queryString = '';
            $fullPhysicalPath = APPLICATION_ROOT . "webroot/" . $relativePath;
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

    private function uploadFile()
    {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return $this->error(400, 'No valid file uploaded. Send file as multipart/form-data with key "file".');
        }

        $originalName = $_FILES['file']['name'];
        $sanitizedName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);

        $filesPayload = [
            'name'     => [$sanitizedName],
            'type'     => [$_FILES['file']['type']],
            'tmp_name' => [$_FILES['file']['tmp_name']],
            'error'    => [$_FILES['file']['error']],
            'size'     => [$_FILES['file']['size']]
        ];

        $result = $this->run->uploadFiles($filesPayload);

        if ($result === false && !empty($this->run->errors)) {
            return $this->error(400, implode(' ', $this->run->errors));
        }

        return $this->response(201, 'File uploaded successfully', [
            'messages' => $this->run->messages,
            'file' => $sanitizedName
        ]);
    }

    private function deleteFile($fileName)
    {
        $decodedFileName = urldecode($fileName);

        $fileRecord = $this->db->findRow('survey_uploaded_files', [
            'run_id' => $this->run->id,
            'original_file_name' => $decodedFileName
        ]);

        if (!$fileRecord) {
            return $this->error(404, "File '$decodedFileName' not found in this run.");
        }

        if ($this->run->deleteFile($fileRecord['id'], $fileRecord['original_file_name'])) {
            return $this->response(200, "File '$decodedFileName' deleted successfully");
        }

        return $this->error(500, "Failed to delete file '$decodedFileName'.");
    }
}
