<?php

class CleanupOrphanedFilesOnCron extends Cron {
    protected $name = 'Formr.CleanupOrphanedFilesOnCron';

    protected function process(): void {
        $this->cleanupOrphanedFiles();
    }

    private function cleanupOrphanedFiles() {
        // Get orphaned file records
        $query = "SELECT * FROM user_uploaded_files WHERE study_id IS NULL OR unit_session_id IS NULL";
        $orphanedFiles = $this->db->query($query)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($orphanedFiles as $file) {
            // Delete physical file if it exists
            if (file_exists($file['stored_path'])) {
                @unlink($file['stored_path']);
            }

            // Delete database record
            $this->db->query(
                "DELETE FROM user_uploaded_files WHERE id = ?",
                [$file['id']]
            );

            formr_log("Deleted orphaned file: {$file['original_filename']} (ID: {$file['id']})", 'CRON_INFO');
        }
    }
} 