<?php

class ApiHelper {

    /**
     * @var DB
     */
    protected $fdb;

    /**
     * @var Request
     */
    protected $request;

    /**
     * A conainer to hold processed request's outcome
     *
     * @var array
     */
    protected $data = array(
        'statusCode' => Response::STATUS_OK,
        'statusText' => 'OK',
        'response' => array(),
    );

    /**
     * Formr user object current accessing data
     * @var User
     */
    protected $user = null;

    /**
     * Error information
     *
     * @var array
     */
    protected $error = array();

    public function __construct(Request $request, DB $db) {
        $this->fdb = $db;
        $this->request = $request;
        $this->user = OAuthHelper::getInstance()->getUserByAccessToken($this->request->getParam('access_token'));
    }

    public function results() {
		ini_set('memory_limit', Config::get('memory_limit.run_get_data'));
		
        // Get run object from request
        $request_run = $this->request->arr('run');
        $request_surveys = $this->request->arr('surveys');
        $request = array('run' => array(
            'name' => array_val($request_run, 'name', null),
            'session' => array_val($request_run, 'session', null),
            'sessions' => array_filter(explode(',', array_val($request_run, 'sessions', false))),
            'surveys' => array()
        ));

        foreach ($request_surveys as $survey_name => $survey_fields) {
            $request['run']['surveys'][] = (object) array(
                'name' => $survey_name,
                'items' => $survey_fields,
            );
        }

        $request = json_decode(json_encode($request));
        if (!($run = $this->getRunFromRequest($request))) {
            return $this;
        }
        
        // If sessions are still not available then run is empty
        if (!$this->fdb->count('survey_run_sessions', array('run_id' => $run->id), 'id')) {
            $this->setData(Response::STATUS_NOT_FOUND, 'Empty Run', null, 'No sessions were found in this run.');
            return $this;
        }

        $requested_run = $request->run;
        
        // Determine which surveys in the run for which to collect data
        if (!empty($requested_run->survey)) {
            $surveys = array($requested_run->survey);
        } elseif (!empty($requested_run->surveys)) {
            $surveys = $requested_run->surveys;
        } else {
            $surveys = array();
            $run_surveys = $run->getAllSurveys();
            foreach ($run_surveys as $survey) {
                 $surveys[] = (object) array(
                    'name' => $survey['name'],
                    'items' => null,
                );
            }
        }

        // Determine which run sessions in the run will be returned.
        // (For now let's prevent returning data of all sessions so this should be required)
        if (!empty($requested_run->session)) {
            $requested_run->sessions = array($requested_run->session);
        }

        $results = array();
        foreach ($surveys as $s) {
            $results[$s->name] = $this->getSurveyResults($run, $s->name, $s->items, $requested_run->sessions);
        }

        $this->setData(Response::STATUS_OK, 'OK', $results);
        return $this;
    }

    public function createSession() {
        if (!($request = $this->parseJsonRequest()) || !($run = $this->getRunFromRequest($request))) {
            return $this;
        }

        $i = 0;
        $run_session = new RunSession($this->fdb, $run->id, null, null, null);
        $code = null;
        if (!empty($request->run->code)) {
            $code = $request->run->code;
        }

        if (!is_array($code)) {
            $code = array($code);
        }

        $sessions = array();
        foreach ($code as $session) {
            if (($created = $run_session->create($session))) {
                $sessions[] = $run_session->session;
                $i++;
            }
        }

        if ($i) {
            $this->setData(Response::STATUS_OK, 'OK', array('created_sessions' => $i, 'sessions' => $sessions));
        } else {
            $this->setData(Response::STATUS_INTERNAL_SERVER_ERROR, 'Error Request', null, 'Error occured when creating session');
        }

        return $this;
    }

    public function endLastExternal() {
        if (!($request = $this->parseJsonRequest())) {
            return $this;
        }

        $run = new Run($this->fdb, $request->run->name);
        if (!$run->valid) {
            $this->setData(Response::STATUS_NOT_FOUND, 'Not Found', null, 'Invalid Run');
            return $this;
        }

        if (!empty($request->run->session)) {
            $session_code = $request->run->session;
            $run_session = new RunSession($this->fdb, $run->id, null, $session_code, null);

            if ($run_session->session !== NULL) {
                $run_session->endLastExternal();
                $this->setData(Response::STATUS_OK, 'OK', array('success' => 'external unit ended'));
            } else {
                $this->setData(Response::STATUS_NOT_FOUND, 'Not Found', null, 'Invalid user session');
            }
        } else {
            $this->setData(Response::STATUS_NOT_FOUND, 'Not Found', null, 'Session code not found');
        }

        return $this;
    }

    public function getData() {
        return $this->data;
    }

    /**
     * Get Run object from an API request
     * $request object must have a root element called 'run' which must have child elements called 'name' and 'api_secret'
     * Example:
     * $request = '{
     * 		run: {
     * 			name: 'some_run_name',
     * 			api_secret: 'run_api_secret_as_show_in_run_settings'
     * 		}
     * }'
     *
     * @param object $request A JSON object of the sent request
     * @return boolean|Run Returns a run object if a valid run is found or FALSE otherwise 
     */
    protected function getRunFromRequest($request) {
        if (empty($request->run->name)) {
            $this->setData(Response::STATUS_NOT_FOUND, 'Not Found', null, 'Required "run : { name: }" parameter not found.');
            return false;
        }

        $run = new Run($this->fdb, $request->run->name);
        if (!$run->valid || !$this->user) {
            $this->setData(Response::STATUS_NOT_FOUND, 'Not Found', null, 'Invalid Run or run/user not found');
            return false;
        } elseif (!$this->user->created($run)) {
            $this->setData(Response::STATUS_UNAUTHORIZED, 'Unauthorized Access', null, 'Unauthorized access to run');
            return false;
        }

        return $run;
    }

    private function setData($statusCode = null, $statusText = null, $response = null, $error = null) {
        if ($error !== null) {
            $this->setError($statusCode, $statusText, $error);
            return $this->setData($statusCode, $statusText, $this->error);
        }

        if ($statusCode !== null) {
            $this->data['statusCode'] = $statusCode;
        }
        if ($statusText !== null) {
            $this->data['statusText'] = $statusText;
        }
        if ($response !== null) {
            $this->data['response'] = $response;
        }
    }

    private function setError($code = null, $error = null, $desc = null) {
        if ($code !== null) {
            $this->error['error_code'] = $code;
        }
        if ($error !== null) {
            $this->error['error'] = $error;
        }
        if ($desc !== null) {
            $this->error['error_description'] = $desc;
        }
    }

    private function parseJsonRequest() {
        $request = $this->request->str('request', null) ? $this->request->str('request') : file_get_contents('php://input');
        if (!$request) {
            $this->setData(Response::STATUS_BAD_REQUEST, 'Invalid Request', null, "Request payload not found");
            return false;
        }
        $object = json_decode($request);
        if (!$object) {
            $this->setData(Response::STATUS_BAD_REQUEST, 'Invalid Request', null, "Unable to parse JSON request");
            return false;
        }
        return $object;
    }
  
    private function getSurveyResults(Run $run, $survey_name, $survey_items = null, $sessions = null) {
        $results = array();
        $query = $query = $this->buildSurveyResultsQuery($run, $survey_name, $survey_items, $sessions);
        $stmt = $this->fdb->query($query, true);

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $session_id = $row['session_id'];
            if (!isset($results[$session_id])) {
                $results[$session_id] = array('session' => $row['run_session'], 'created' => $row['created']);
            }
			if ($row['created'] && !$results[$session_id]['created']) {
				$results[$session_id]['created'] = $row['created'];
			}
            $results[$session_id][$row['item_name']] = $row['answer'];
        }

        return array_values($results);
    }

    private function buildSurveyResultsQuery(Run $run, $survey_name, $survey_items = null, $sessions = null) {
        $params = array(
            'run_id' => $run->id,
            'user_id' => $this->user->id,
            'survey_name' => $this->fdb->quote($survey_name),
            'WHERE_survey_items' => null,
            'WHERE_run_sessions' => null,
        );
                
        $q = '
            SELECT itms_display.item_id, itms_display.session_id, itms_display.answer, itms_display.created,
                   survey_items.name AS item_name, survey_run_sessions.session AS run_session
            FROM survey_items_display AS itms_display
            LEFT JOIN survey_unit_sessions ON survey_unit_sessions.id = itms_display.session_id
            LEFT JOIN survey_run_sessions ON survey_run_sessions.id = survey_unit_sessions.run_session_id
            LEFT JOIN survey_items ON survey_items.id = itms_display.item_id
            LEFT JOIN survey_studies ON survey_studies.id = survey_items.study_id
            WHERE survey_studies.name = %{survey_name}
            AND survey_studies.user_id = %{user_id}
            AND survey_run_sessions.run_id = %{run_id}
            %{WHERE_survey_items}
            %{WHERE_run_sessions}
        ';
        
        if ($survey_items && is_string($survey_items)) {
            $itms = array();
            foreach (explode(',', $survey_items) as $itm) {
                $itms[] = $this->fdb->quote(trim($itm));
            }
            $params['WHERE_survey_items'] = ' AND survey_items.name IN (' . implode(',', $itms) . ') ';
        }
        
        if ($sessions && is_array($sessions)) {
            $or_like = array();
            foreach ($sessions as $session) {
                $or_like[] = " survey_run_sessions.session LIKE '{$session}%' ";
            }
            $params['WHERE_run_sessions'] = ' AND (' . implode('OR', $or_like) . ') ';
        }
        
        return Template::replace($q, $params);
    }

}
