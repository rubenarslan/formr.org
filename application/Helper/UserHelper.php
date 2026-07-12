<?php

class UserHelper {

    public static function getUserManagementTablePdoStatement($params = array()) {
        $count = DB::getInstance()->count('survey_users');
        $pagination = new Pagination($count, 200, true);
        $limits = $pagination->getLimits();
        $where = " ";
        $binds = array();
        if (!empty($params['email'])) {
            $where = " WHERE email LIKE :email ";
            $binds[':email'] = '%' . addcslashes($params['email'], '%_\\') . '%';
        }

        $itemsQuery = "
            SELECT
                `survey_users`.id,
                `survey_users`.created,
                `survey_users`.modified,
                `survey_users`.email,
                `survey_users`.admin,
                `survey_users`.email_verified
            FROM `survey_users`
            {$where}
            ORDER BY `survey_users`.id ASC  LIMIT $limits
        ";

        $stmt = DB::getInstance()->prepare($itemsQuery);
        $stmt->execute($binds);

        return array(
            'search_email' => $params['email'] ?? '',
            'pdoStatement' => $stmt,
            'pagination' => $pagination,
        );
    }

    public static function getActiveUsersTablePdoStatement() {
        $count = DB::getInstance()->count('survey_users');
        $pagination = new Pagination($count, 200, true);
        $limits = $pagination->getLimits();

        // per-run session count + last activity come from the maintained rollup
        // (audit SQ-21) instead of a live GROUP BY over survey_run_sessions
        $itemsQuery = "
            SELECT
                `survey_users`.id,
                `survey_users`.created,
                `survey_users`.modified,
                `survey_users`.email,
                `survey_users`.admin,
                `survey_users`.email_verified,
                `survey_runs`.name AS run_name,
                `survey_runs`.cron_active,
                `survey_runs`.public,
                COALESCE(`m`.n_run_sessions, 0) AS number_of_users_in_run,
                `m`.last_access AS last_edit
            FROM `survey_users`
            LEFT JOIN `survey_runs` ON `survey_runs`.user_id = `survey_users`.id
            LEFT JOIN `survey_run_metrics` `m` ON `m`.run_id = `survey_runs`.id
            WHERE `survey_users`.admin > 0
            ORDER BY `survey_users`.id ASC, last_edit DESC LIMIT $limits
        ";
        
        $stmt = DB::getInstance()->prepare($itemsQuery);
        $stmt->execute();

        return array(
            'pdoStatement' => $stmt,
            'pagination' => $pagination,
        );
    }

}
