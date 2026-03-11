<?php

/**
 * Cron job that computes resource metrics for users whose runs were modified since last computation.
 * Run daily or weekly via bin/cron_resource_monitoring.php
 */
class ResourceMonitoringCron extends Cron {
    protected $name = 'Formr.ResourceMonitoringCron';

    /**
     * @var ResourceMonitor
     */
    protected $resourceMonitor;

    protected function process(): void {
        $this->resourceMonitor = new ResourceMonitor($this->db);

        $userIds = $this->resourceMonitor->getUsersNeedingComputation();

        if (empty($userIds)) {
            formr_log('ResourceMonitoringCron: No users need resource computation', 'CRON_INFO');
            return;
        }

        formr_log('ResourceMonitoringCron: Computing resources for ' . count($userIds) . ' user(s)', 'CRON_INFO');

        foreach ($userIds as $userId) {
            try {
                $metrics = $this->resourceMonitor->computeForUser($userId);
                formr_log("ResourceMonitoringCron: Computed metrics for user {$userId}: " . json_encode($metrics), 'CRON_INFO');
            } catch (Exception $e) {
                formr_log("ResourceMonitoringCron: Error computing for user {$userId}: " . $e->getMessage(), 'CRON_ERROR');
            }
        }
    }
}
