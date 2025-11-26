<?php

class CleanupOrphanedFilesOnCron extends Cron {
    protected $name = 'Formr.CleanupOrphanedFilesOnCron';

    protected function process(): void {
        $this->cleanupOrphanedFiles();
    }

    private function cleanupOrphanedFiles() {
        // Get orphaned file records
        $orphanedFiles = $this->db->select('id, stored_path, original_filename')
            ->from('user_uploaded_files')
            ->where('study_id IS NULL OR unit_session_id IS NULL')
            ->fetchAll();

        $tmpDir = realpath(APPLICATION_ROOT . '/webroot/assets/tmp');
        if ($tmpDir === false) {
            formr_log("Failed to resolve tmp directory path", 'CRON_ERROR');
            return;
        }

        foreach ($orphanedFiles as $file) {
            // Create absolute path and resolve any directory traversal attempts
            $absolutePath = realpath(APPLICATION_ROOT . '/' . ltrim($file['stored_path'], '/'));
            
            // Skip if file doesn't exist or realpath failed
            if ($absolutePath === false) {
                formr_log("Failed to resolve path for file: {$file['original_filename']} (ID: {$file['id']})", 'CRON_ERROR');
                continue;
            }
            
            // Check if file is strictly within the tmp directory by comparing path prefixes
            if (strpos($absolutePath, $tmpDir . DIRECTORY_SEPARATOR) === 0) {
                if (file_exists($absolutePath)) {
                    @unlink($absolutePath);
                }

                // Delete database record
                $this->db->delete('user_uploaded_files', ['id' => $file['id']]);
                formr_log("Deleted orphaned file: {$file['original_filename']} (ID: {$file['id']})", 'CRON_INFO');
            } else {
                formr_log("Orphaned file not deleted because it is not in the tmp directory: {$file['original_filename']} (ID: {$file['id']})", 'CRON_ERROR');
            }
        }
    }
} 