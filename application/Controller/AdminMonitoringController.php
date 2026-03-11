<?php

class AdminMonitoringController extends AdminAdvancedController {

    public function surveyResourceMonitoringAction() {
        $perPage = 50;
        $page = max(1, (int) $this->request->getParam('page', 1));

        $count = (int) $this->fdb->count('survey_resource_metrics');
        $pagination = new Pagination($count, $perPage, false);
        $pagination->setPage($page - 1);
        $limits = $pagination->getLimits();
        list($offset, $limit) = explode(',', $limits);

        $stmt = $this->fdb->prepare("
            SELECT rm.*, u.email
            FROM survey_resource_metrics rm
            LEFT JOIN survey_users u ON u.id = rm.user_id
            ORDER BY rm.user_id ASC
            LIMIT {$offset}, {$limit}
        ");
        $stmt->execute();
        $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->setView('monitoring/survey_resource_monitoring', [
            'metrics' => $metrics,
            'pagination' => $pagination,
        ]);
        return $this->sendResponse();
    }

    public function userResourceDetailsAction() {
        $userId = (int) $this->request->getParam('user_id');
        if (!$userId) {
            alert('Invalid user', 'alert-danger');
            return $this->request->redirect('admin/monitoring/survey-resource-monitoring');
        }

        $metrics = $this->fdb->findRow('survey_resource_metrics', ['user_id' => $userId]);
        $user = $this->fdb->findRow('survey_users', ['id' => $userId]);
        $surveySizes = $this->fdb->execute("
            SELECT srs.*, s.name as study_name
            FROM survey_resource_survey_sizes srs
            LEFT JOIN survey_studies s ON s.id = srs.study_id
            WHERE srs.user_id = :user_id
            ORDER BY srs.items_size_kb DESC
        ", ['user_id' => $userId]);

        $this->setView('monitoring/user_resource_details', [
            'metrics' => $metrics,
            'current_user' => $user,
            'surveySizes' => $surveySizes,
        ]);
        return $this->sendResponse();
    }

    public function unitSessionsMonitoringAction() {
        $perPage = 100;
        $page = max(1, (int) $this->request->getParam('page', 1));

        $count = (int) $this->fdb->count('survey_unit_execution_log');
        $pagination = new Pagination($count, $perPage, false);
        $pagination->setPage($page - 1);
        $limits = $pagination->getLimits();
        list($offset, $limit) = explode(',', $limits);

        $stmt = $this->fdb->prepare("
            SELECT el.*, r.name as run_name, r.user_id
            FROM survey_unit_execution_log el
            LEFT JOIN survey_runs r ON r.id = el.run_id
            ORDER BY el.execution_time_ms DESC
            LIMIT {$offset}, {$limit}
        ");
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->setView('monitoring/unit_sessions_monitoring', [
            'logs' => $logs,
            'pagination' => $pagination,
        ]);
        return $this->sendResponse();
    }
}
