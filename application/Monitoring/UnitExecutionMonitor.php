<?php

/**
 * Logs run unit execution times in real-time when units execute.
 * Used for monitoring performance and future billing of compute resources.
 */
class UnitExecutionMonitor {

    /**
     * @var DB
     */
    protected static $db;

    /**
     * Log the execution time of a run unit.
     *
     
     * @param int $runId survey_runs.id
     * @param int $runUnitId survey_run_units.id
     * @param int $unitSessionId survey_unit_sessions.id
     * @param string|null $unitType e.g. 'Survey', 'External', 'Branch'
     * @param int $executionTimeMs Execution duration in milliseconds
     */
    public static function logExecution($runId, $runUnitId, $unitSessionId, $unitType, $executionTimeMs) {
        try {
            $db = self::getDb();
            $db->insert('survey_unit_execution_log', [
                'run_unit_id' => $runUnitId,
                'run_id' => $runId,
                'unit_session_id' => $unitSessionId,
                'unit_type' => $unitType,
                'execution_time_ms' => $executionTimeMs,
                'created_at' => mysql_now(),
            ]);
        } catch (Exception $e) {
            formr_log('UnitExecutionMonitor: Failed to log execution: ' . $e->getMessage(), 'CRON_ERROR');
        }
    }

    /**
     * @return DB
     */
    protected static function getDb() {
        if (self::$db === null) {
            self::$db = DB::getInstance();
        }
        return self::$db;
    }

    /**
     * For testing: inject DB instance.
     *
     * @param DB|null $db
     */
    public static function setDb($db) {
        self::$db = $db;
    }
}
