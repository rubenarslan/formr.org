<?php

class UserHelper {

    public static function getUserManagementTablePdoStatement() {
        $count = DB::getInstance()->count('survey_users');
        $pagination = new Pagination($count, 200, true);
        $limits = $pagination->getLimits();

        $itemsQuery = "
            SELECT 
                `survey_users`.id,
                `survey_users`.created,
                `survey_users`.modified,
                `survey_users`.email,
                `survey_users`.admin,
                `survey_users`.email_verified
            FROM `survey_users`
            ORDER BY `survey_users`.id ASC  LIMIT $limits
        ";

        $stmt = DB::getInstance()->prepare($itemsQuery);
        $stmt->execute();

        return array(
            'pdoStatement' => $stmt,
            'pagination' => $pagination,
        );
    }

    public static function getActiveUsersTablePdoStatement() {
        $count = DB::getInstance()->count('survey_users');
        $pagination = new Pagination($count, 200, true);
        $limits = $pagination->getLimits();

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
                COUNT(`survey_run_sessions`.id) AS number_of_users_in_run,
                MAX(`survey_run_sessions`.last_access) AS last_edit
            FROM `survey_users`
            LEFT JOIN `survey_runs` ON `survey_runs`.user_id = `survey_users`.id
            LEFT JOIN `survey_run_sessions` ON `survey_runs`.id = `survey_run_sessions`.run_id
            WHERE `survey_users`.admin > 0
            GROUP BY `survey_runs`.id
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
