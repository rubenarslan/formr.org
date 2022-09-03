<?php

class RunHelper {

    /**
     *
     * @var Request
     */
    protected $request;

    /**
     *
     * @var string
     */
    protected $run_name;

    /**
     * @var Run
     */
    protected $run;

    /**
     *
     * @var RunSession
     */
    protected $runSession;

    /**
     *
     * @var DB
     */
    protected $db;
    protected $errors = array();
    protected $message = null;

    public function __construct(Run $run, DB $db, Request $r) {
        $this->request = $r;
        $this->db = $db;
        $this->run = $run;
        $this->run_name = $run->name;

        if (!$this->run->valid) {
            throw new Exception("Run with name {$run} not found");
        }

        if ($this->request->session) {
            $this->runSession = new RunSession($this->request->session, $this->run);
        }
    }

    public function sendToPosition() {
        if ($this->request->session === null || $this->request->new_position === null) {
            $this->errors[] = 'Missing session or position parameter';
            return false;
        }

        $run_session = $this->runSession;
        if (!$run_session->forceTo($this->request->new_position)) {
            $this->errors[] = 'Something went wrong with the position change in run ' . $this->run->name;
            return false;
        }

        $this->message = 'Run-session successfully set to position ' . $this->request->new_position;
        return true;
    }

    public function remind() {
        $emailSession = $this->run->getReminderSession($this->request->reminder_id, $this->request->session, $this->request->run_session_id);
        if ($emailSession->execute() === false) {
            $this->errors[] = 'Something went wrong with the reminder. in run ' . $this->run->name;
            return false;
        }

        $this->message = 'Reminder sent';
        return true;
    }

    public function nextInRun() {
        if (!$this->runSession->endUnitSession()) {
            $this->errors[] = 'Unable to move to next unit in run ' . $this->run->name;
            return false;
        }
        $this->message = 'Move done';
        return true;
    }

    public function deleteUser() {
        $session = $this->request->session;
        if (($deleted = $this->db->delete('survey_run_sessions', array('id' => $this->request->run_session_id)))) {
            $this->message = "User with session '{$session}' was deleted";
        } else {
            $this->errors[] = "User with session '{$session}' could not be deleted";
        }
    }

    public function snipUnitSession() {
        $run = $this->run;
        $session = $this->request->session;
        $run_session = new RunSession($session, $run);

        $unit_session = $run_session->getCurrentUnitSession();
        if ($unit_session):
            $deleted = $this->db->delete('survey_unit_sessions', array('id' => $unit_session->id));
            if ($deleted):
                $this->message = '<strong>Success.</strong> You deleted the data at the current position.';
                if (!$run_session->forceTo($run_session->position)):
                    $this->errors[] = 'Data was deleted, but could not stay at position. ' . $run->name;
                    return false;
                endif;
            else:
                $this->errors[] = '<strong>Couldn\'t delete.</strong>';
            endif;
        else:
            $this->errors[] = "No unit session found";
        endif;
    }
    
    public function getRunSession() {
        return $this->runSession;
    }

    public function getErrors() {
        return $this->errors;
    }

    public function getMessage() {
        return $this->message;
    }

    public function getUserOverviewTable($queryParams, $page = null) {
        ini_set('memory_limit', Config::get('memory_limit.run_get_data'));
        
        $query = array(' `survey_run_sessions`.run_id = :run_id ');
        if (!empty($queryParams['session'])) {
            $query[] = ' `survey_run_sessions`.session LIKE :session ';
        }

        if (!empty($queryParams['sessions'])) {
            $query[] = ' `survey_run_sessions`.session IN (' . implode( ',', $queryParams['sessions']) . ') ';
        }

        if (!empty($queryParams['position'])) {
            $query[] = " `survey_run_sessions`.position {$queryParams['position_operator']} :position ";
        }
        $adminCode = $queryParams['admin_code'];
        unset($queryParams['position_operator'], $queryParams['admin_code']);

        $where = implode(' AND ', $query);
        $count_query = "SELECT COUNT(`survey_run_sessions`.id) AS count FROM `survey_run_sessions` WHERE {$where}";
        $count = $this->db->execute($count_query, $queryParams, true);

        $pagination = new Pagination($count, 200, true);
        $limits = $pagination->getLimits();
        $queryParams['admin_code'] = $adminCode;

        $itemsQuery = 
            "SELECT
                `survey_run_sessions`.id AS run_session_id,
                `survey_run_sessions`.session,
                `survey_run_sessions`.position,
                `survey_run_units`.description,
                `survey_run_sessions`.last_access,
                `survey_run_sessions`.created,
                `survey_run_sessions`.testing,
                `survey_run_sessions`.current_unit_session_id,
                `survey_runs`.name AS run_name,
                `survey_units`.type AS unit_type,
                `survey_run_sessions`.last_access,
                `us`.result,
                `us`.result_log,
                `us`.expires
            FROM `survey_run_sessions`
            LEFT JOIN `survey_runs` ON `survey_run_sessions`.run_id = `survey_runs`.id
            LEFT JOIN `survey_run_units` ON `survey_run_sessions`.position = `survey_run_units`.position AND `survey_run_units`.run_id = `survey_run_sessions`.run_id
            LEFT JOIN `survey_units` ON `survey_run_units`.unit_id = `survey_units`.id
            LEFT JOIN `survey_unit_sessions` us ON  `survey_run_sessions`.current_unit_session_id = `us`.id
            WHERE {$where}
            ORDER BY `survey_run_sessions`.session != :admin_code, `survey_run_sessions`.last_access DESC
            LIMIT $limits
        ";

        return array(
            'data' => $this->db->execute($itemsQuery, $queryParams),
            'pagination' => $pagination,
        );
    }

    public function getUserOverviewExportPdoStatement($queryParams) {
        $query = "SELECT
                `survey_run_sessions`.position,
                `survey_units`.type AS unit_type,
                `survey_run_units`.description,
                `survey_run_sessions`.session,
                `survey_run_sessions`.created,
                `survey_run_sessions`.current_unit_session_id,
                `survey_run_sessions`.last_access,
                `us`.result,
                `us`.result_log,
                `us`.expires
            FROM `survey_run_sessions`
            LEFT JOIN `survey_runs` ON `survey_run_sessions`.run_id = `survey_runs`.id
            LEFT JOIN `survey_run_units` ON `survey_run_sessions`.position = `survey_run_units`.position AND `survey_run_units`.run_id = `survey_run_sessions`.run_id
            LEFT JOIN `survey_units` ON `survey_run_units`.unit_id = `survey_units`.id
            LEFT JOIN `survey_unit_sessions` us ON  `survey_run_sessions`.current_unit_session_id = `us`.id
            WHERE `survey_run_sessions`.run_id = :run_id ORDER BY `survey_run_sessions`.session != :admin_code,`survey_run_sessions`.last_access DESC
        ";
        $stmt = $this->db->prepare($query);
        $stmt->execute($queryParams);

        return $stmt;
    }

    public function getUserDetailTable($queryParams, $page = null) {
        $query = array(' `survey_run_sessions`.run_id = :run_id ');
        if (!empty($queryParams['session'])) {
            $query[] = ' `survey_run_sessions`.session LIKE :session ';
        }

        if (!empty($queryParams['position'])) {
            $query[] = " `survey_run_units`.position {$queryParams['position_operator']} :position ";
        }
        unset($queryParams['position_operator']);

        $where = implode(' AND ', $query);
        $count_query = "SELECT COUNT(`survey_unit_sessions`.id) AS count FROM `survey_unit_sessions` 
			LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
			LEFT JOIN `survey_run_units` ON `survey_unit_sessions`.`unit_id` = `survey_run_units`.`unit_id`
            WHERE {$where}
        ";
        $count = $this->db->execute($count_query, $queryParams, true);
        $pagination = new Pagination($count, 200, true);
        $limits = $pagination->getLimits();

        $query[] = ' `survey_runs`.id = :run_id2 ';
        $queryParams['run_id2'] = $queryParams['run_id'];
        $where = implode(' AND ', $query);

        $itemsQuery = "SELECT 
                `survey_run_sessions`.session,
                `survey_unit_sessions`.id AS session_id,
                `survey_runs`.name AS run_name,
                `survey_run_units`.position,
                `survey_run_units`.description,
                `survey_units`.type AS unit_type,
                `survey_unit_sessions`.created,
                `survey_unit_sessions`.ended,
                `survey_unit_sessions`.expired,
                `survey_unit_sessions`.expires,
                `survey_unit_sessions`.`queued`,
                `survey_unit_sessions`.result,
                `survey_unit_sessions`.result_log
            FROM `survey_unit_sessions`
            LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
            LEFT JOIN `survey_units` ON `survey_unit_sessions`.unit_id = `survey_units`.id
            LEFT JOIN `survey_run_units` ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
            LEFT JOIN `survey_runs` ON `survey_runs`.id = `survey_run_units`.run_id
            WHERE {$where}
            ORDER BY `survey_run_sessions`.id DESC,`survey_unit_sessions`.id ASC LIMIT {$limits}
        ";

        return array(
            'data' => $this->db->execute($itemsQuery, $queryParams),
            'pagination' => $pagination,
        );
    }

    public function getUserDetailExportPdoStatement($queryParams) {
        $query = "
            SELECT
                `survey_unit_sessions`.id AS session_id,
                `survey_run_units`.position,
			    `survey_units`.type AS unit_type,
			    `survey_run_units`.description,
			    `survey_run_sessions`.session,
			    `survey_unit_sessions`.created AS entered,
			    IF (`survey_unit_sessions`.ended > 0, UNIX_TIMESTAMP(`survey_unit_sessions`.ended)-UNIX_TIMESTAMP(`survey_unit_sessions`.created), UNIX_TIMESTAMP(NOW())-UNIX_TIMESTAMP(`survey_unit_sessions`.created)) AS 'seconds_stayed',
				`survey_unit_sessions`.ended AS 'left',
			    `survey_unit_sessions`.expired
			FROM `survey_unit_sessions`
			LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
			LEFT JOIN `survey_units` ON `survey_unit_sessions`.unit_id = `survey_units`.id
			LEFT JOIN `survey_run_units` ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
			LEFT JOIN `survey_runs` ON `survey_runs`.id = `survey_run_units`.run_id
			WHERE `survey_runs`.id = :run_id AND `survey_run_sessions`.run_id = :run_id2
			ORDER BY `survey_run_sessions`.id DESC,`survey_unit_sessions`.id ASC;
        ";
        $stmt = $this->db->prepare($query);
        $stmt->execute($queryParams);

        return $stmt;
    }

    public function getEmailLogTable($queryParams) {
        $count_query = "
            SELECT COUNT(`survey_email_log`.id) AS count
            FROM `survey_email_log`
            LEFT JOIN `survey_unit_sessions` ON `survey_unit_sessions`.id = `survey_email_log`.session_id 
            LEFT JOIN `survey_run_sessions` ON `survey_unit_sessions`.run_session_id = `survey_run_sessions`.id
            WHERE `survey_run_sessions`.run_id = :run_id
        ";
        $count = $this->db->execute($count_query, $queryParams, true);
        $pagination = new Pagination($count, 75, true);
        $limits = $pagination->getLimits();

        $itemsQuery = "
            SELECT 
                `survey_email_accounts`.from_name, 
                `survey_email_accounts`.`from`, 
                `survey_email_log`.recipient AS `to`,
                `survey_email_log`.`status`,
                `survey_email_log`.subject,
                `survey_email_log`.sent,
                `survey_email_log`.created,
                `survey_unit_sessions`.result,
                `survey_unit_sessions`.result_log,
                `survey_run_units`.position AS position_in_run
            FROM `survey_email_log`
            LEFT JOIN `survey_run_units` ON `survey_email_log`.email_id = `survey_run_units`.unit_id
            LEFT JOIN `survey_email_accounts` ON `survey_email_log`.account_id = `survey_email_accounts`.id
            LEFT JOIN `survey_unit_sessions` ON `survey_unit_sessions`.id = `survey_email_log`.session_id
            LEFT JOIN `survey_run_sessions` ON `survey_unit_sessions`.run_session_id = `survey_run_sessions`.id
            WHERE `survey_run_sessions`.run_id = :run_id
            ORDER BY `survey_email_log`.id DESC LIMIT $limits
        ";

        return array(
            'data' => $this->db->execute($itemsQuery, $queryParams),
            'pagination' => $pagination,
        );
    }

    public static function getPublicRuns() {
        return DB::getInstance()->select('name, title, public_blurb_parsed')
                                ->from('survey_runs')
                                ->where('public > 2')
                                ->fetchAll();
    }

    public static function getRunsManagementTablePdoStatement() {
        $count = DB::getInstance()->count('survey_runs');
        $pagination = new Pagination($count, 100, true);
        $limits = $pagination->getLimits();

        $itemsQuery = "
            SELECT survey_runs.id AS run_id, name, survey_runs.user_id, cron_active, cron_fork, locked, count(survey_run_sessions.id) AS sessions, survey_users.email
			FROM survey_runs 
			LEFT JOIN survey_users ON survey_users.id = survey_runs.user_id 
			LEFT JOIN survey_run_sessions ON survey_run_sessions.run_id = survey_runs.id 
			GROUP BY survey_run_sessions.run_id
            ORDER BY survey_runs.name ASC LIMIT $limits
        ";
        
        $stmt = DB::getInstance()->prepare($itemsQuery);
        $stmt->execute();

        return array(
            'pdoStatement' => $stmt,
            'pagination' => $pagination,
            'count' => $count,
        );
    }

}
