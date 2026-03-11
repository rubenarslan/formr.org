<?php

/**
 * Monitors uploaded file storage: total size of files uploaded by/for a user.
 * Covers: user_uploaded_files (survey/unit session uploads) and survey_uploaded_files (run-level uploads).
 */
class FileMonitor {

    /**
     * @var DB
     */
    protected $db;

    public function __construct(DB $db) {
        $this->db = $db;
    }

    /**
     * Get total size in KB of uploaded files for a user.
     * Includes: user_uploaded_files (via study or unit_session->run) and survey_uploaded_files (via run).
     *
     * @param int $userId
     * @return float Size in KB
     */
    public function getUploadedFilesSizeKb($userId) {
        $userFilesKb = $this->getUserUploadedFilesSizeKb($userId);
        $runFilesKb = $this->getRunUploadedFilesSizeKb($userId);
        return $userFilesKb + $runFilesKb;
    }

    /**
     * Size of user_uploaded_files - files from survey items (study_id) or unit sessions (run_session->run).
     *
     * @param int $userId
     * @return float
     */
    protected function getUserUploadedFilesSizeKb($userId) {
        $webroot = realpath(APPLICATION_ROOT . 'webroot/') ?: APPLICATION_ROOT . 'webroot/';
        $totalBytes = 0;

        // Files linked to studies owned by user
        $query = "
            SELECT uuf.id, uuf.stored_path
            FROM user_uploaded_files uuf
            INNER JOIN survey_studies s ON s.id = uuf.study_id AND s.user_id = :user_id
        ";
        $rows = $this->db->execute($query, ['user_id' => $userId]);
        foreach ($rows as $row) {
            $path = $this->resolveStoredPath($row['stored_path'], $webroot);
            if ($path && is_file($path)) {
                $totalBytes += filesize($path);
            }
        }

        // Files linked to unit sessions (run_session -> run -> user)
        $query = "
            SELECT uuf.id, uuf.stored_path
            FROM user_uploaded_files uuf
            INNER JOIN survey_unit_sessions sus ON sus.id = uuf.unit_session_id
            INNER JOIN survey_run_sessions srs ON srs.id = sus.run_session_id
            INNER JOIN survey_runs r ON r.id = srs.run_id AND r.user_id = :user_id
        ";
        $rows = $this->db->execute($query, ['user_id' => $userId]);
        foreach ($rows as $row) {
            $path = $this->resolveStoredPath($row['stored_path'], $webroot);
            if ($path && is_file($path)) {
                $totalBytes += filesize($path);
            }
        }

        return $totalBytes / 1024;
    }

    /**
     * Size of survey_uploaded_files - run-level admin uploads.
     *
     * @param int $userId
     * @return float
     */
    protected function getRunUploadedFilesSizeKb($userId) {
        $webroot = realpath(APPLICATION_ROOT . 'webroot/') ?: APPLICATION_ROOT . 'webroot/';
        $totalBytes = 0;

        $query = "
            SELECT suf.new_file_path
            FROM survey_uploaded_files suf
            INNER JOIN survey_runs r ON r.id = suf.run_id AND r.user_id = :user_id
        ";
        $rows = $this->db->execute($query, ['user_id' => $userId]);
        foreach ($rows as $row) {
            $path = $webroot . ltrim($row['new_file_path'], '/');
            if (is_file($path)) {
                $totalBytes += filesize($path);
            }
        }

        return $totalBytes / 1024;
    }

    /**
     * Resolve stored_path to filesystem path.
     * user_uploaded_files.stored_path can be a full URL (e.g. https://site.com/assets/tmp/user_uploaded_files/xxx),
     * a path (e.g. /assets/tmp/user_uploaded_files/xxx or assets/tmp/user_uploaded_files/xxx).
     *
     * @param string $storedPath
     * @param string $webroot
     * @return string|null
     */
    protected function resolveStoredPath($storedPath, $webroot) {
        if (empty($storedPath)) {
            return null;
        }
        $path = $storedPath;
        if (preg_match('#^https?://#', $path)) {
            $path = preg_replace('#^https?://[^/]+#', '', $path);
        }
        $path = preg_replace('#\?.*$#', '', $path);
        $path = $webroot . ltrim($path, '/');
        $resolved = realpath($path);
        return $resolved ?: (file_exists($path) ? $path : null);
    }
}
