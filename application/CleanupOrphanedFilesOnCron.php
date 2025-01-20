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

        foreach ($orphanedFiles as $file) {
            // Delete physical file if it exists
            if (file_exists($file['stored_path'])) {
                @unlink($file['stored_path']);
            }

            // Delete database record
            $this->db->delete('user_uploaded_files', ['id' => $file['id']]);

            formr_log("Deleted orphaned file: {$file['original_filename']} (ID: {$file['id']})", 'CRON_INFO');
        }
    }
} 