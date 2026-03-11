<?php

/**
 * Main orchestrator for resource monitoring.
 * Coordinates all monitor classes and provides a unified interface for computing and retrieving user resource metrics.
 */
class ResourceMonitor {

    /**
     * @var DB
     */
    protected $db;

    /**
     * @var SurveyMonitor
     */
    protected $surveyMonitor;

    /**
     * @var RunMonitor
     */
    protected $runMonitor;

    /**
     * @var FileMonitor
     */
    protected $fileMonitor;

    public function __construct(DB $db) {
        $this->db = $db;
        $this->surveyMonitor = new SurveyMonitor($db);
        $this->runMonitor = new RunMonitor($db);
        $this->fileMonitor = new FileMonitor($db);
    }

    /**
     * Get user IDs that need resource recomputation (runs modified since last computation).
     *
     * @return array List of user IDs
     */
    public function getUsersNeedingComputation() {
        $query = "
            SELECT DISTINCT r.user_id
            FROM survey_runs r
            LEFT JOIN survey_resource_metrics rm ON rm.user_id = r.user_id
            WHERE COALESCE(r.modified, r.created, '1970-01-01') > COALESCE(rm.last_computed_at, '1970-01-01')
        ";
        $rows = $this->db->query($query);
        return array_column($rows, 'user_id');
    }

    /**
     * Compute and store resource metrics for a single user.
     *
     * @param int $userId
     * @return array Computed metrics
     */
    public function computeForUser($userId) {
        $now = mysql_now();

        $surveyCount = $this->surveyMonitor->getSurveyCount($userId);
        $surveySizes = $this->surveyMonitor->getSurveyItemsSizes($userId);
        $surveyItemsSizeKb = array_sum(array_column($surveySizes, 'items_size_kb'));

        $runCount = $this->runMonitor->getRunCount($userId);
        $unitSessionsCount = $this->runMonitor->getUnitSessionsCount($userId);

        $uploadedFilesSizeKb = $this->fileMonitor->getUploadedFilesSizeKb($userId);

        $lastRunActivity = $this->runMonitor->getLastRunActivityAt($userId);

        $metrics = [
            'user_id' => $userId,
            'survey_count' => $surveyCount,
            'survey_items_size_kb' => round($surveyItemsSizeKb, 2),
            'run_count' => $runCount,
            'uploaded_files_size_kb' => round($uploadedFilesSizeKb, 2),
            'unit_sessions_count' => $unitSessionsCount,
            'last_computed_at' => $now,
            'last_run_activity_at' => $lastRunActivity,
        ];

        $this->db->insert_update('survey_resource_metrics', $metrics, [
            'survey_count', 'survey_items_size_kb', 'run_count', 'uploaded_files_size_kb',
            'unit_sessions_count', 'last_computed_at', 'last_run_activity_at',
        ]);

        foreach ($surveySizes as $size) {
            $this->db->insert_update('survey_resource_survey_sizes', [
                'user_id' => $userId,
                'study_id' => $size['study_id'],
                'items_size_kb' => $size['items_size_kb'],
                'computed_at' => $now,
            ], ['items_size_kb', 'computed_at']);
        }

        return $metrics;
    }

    /**
     * Get stored resource metrics for a user.
     *
     * @param int $userId
     * @return array|null
     */
    public function getMetricsForUser($userId) {
        return $this->db->findRow('survey_resource_metrics', ['user_id' => $userId]);
    }

    /**
     * Get per-survey sizes for a user.
     *
     * @param int $userId
     * @return array
     */
    public function getSurveySizesForUser($userId) {
        return $this->db->select('study_id, items_size_kb, computed_at')
            ->from('survey_resource_survey_sizes')
            ->where(['user_id' => $userId])
            ->fetchAll();
    }
}
