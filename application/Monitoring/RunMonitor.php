<?php

/**
 * Monitors run-related resources: run count and unit sessions count.
 */
class RunMonitor {

    /**
     * @var DB
     */
    protected $db;

    public function __construct(DB $db) {
        $this->db = $db;
    }

    /**
     * Get the number of runs created by a user.
     *
     * @param int $userId
     * @return int
     */
    public function getRunCount($userId) {
        return (int) $this->db->count('survey_runs', ['user_id' => $userId]);
    }

    /**
     * Get the total number of unit sessions across all runs belonging to a user.
     *
     * @param int $userId
     * @return int
     */
    public function getUnitSessionsCount($userId) {
        $query = "
            SELECT COUNT(sus.id) AS cnt
            FROM survey_unit_sessions sus
            INNER JOIN survey_run_sessions srs ON srs.id = sus.run_session_id
            INNER JOIN survey_runs r ON r.id = srs.run_id
            WHERE r.user_id = :user_id
        ";
        $result = $this->db->execute($query, ['user_id' => $userId], false, true);
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Get the most recent run activity (max of modified/created) for a user's runs.
     *
     * @param int $userId
     * @return string|null MySQL datetime
     */
    public function getLastRunActivityAt($userId) {
        $query = "
            SELECT MAX(GREATEST(COALESCE(modified, '1970-01-01'), COALESCE(created, '1970-01-01'))) AS last_activity
            FROM survey_runs
            WHERE user_id = :user_id
        ";
        $result = $this->db->execute($query, ['user_id' => $userId], false, true);
        $lastActivity = $result['last_activity'] ?? null;
        return ($lastActivity && $lastActivity !== '1970-01-01 00:00:00') ? $lastActivity : null;
    }
}
