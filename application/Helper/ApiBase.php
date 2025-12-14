<?php

abstract class ApiBase
{

    /**
     * @var DB
     */
    protected $db;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var User
     */
    protected $user = null;

    /**
     * A container to hold processed request's outcome
     * @var array
     */
    protected $data = array(
        'statusCode' => Response::STATUS_OK,
        'statusText' => 'OK',
        'response' => array(),
    );

    /**
     * Error information
     * @var array
     */
    protected $error = array();

    protected $tokenData = array();

    public function __construct(Request $request, DB $db, $token_data)
    {
        $this->db = $db;
        $this->request = $request;
        $this->tokenData = $token_data;
        // Assuming OAuthHelper exists globally or statically
        $this->user = OAuthHelper::getInstance()->getUserByEmail($token_data['user_id']);

        global $user;
        $user = $this->user;
    }

    public function getData()
    {
        return $this->data;
    }

    /**
     * Magic Method Trait.
     * * Captures calls to non-existent methods to prevent fatal errors, 
     * returning a 405 Method Not Allowed JSON response instead.
     *
     * @param string $name Name of the method called.
     * @param array $arguments Arguments passed.
     * @return $this
     */
    public function __call($name, $arguments)
    {
        $this->setData(Response::STATUS_METHOD_NOT_ALLOWED, 'Method Not Allowed', ['error' => "Action '$name' is not available in this API version."]);
        return $this;
    }

    // --- SHARED HELPER METHODS ---

    /**
     * Run Validator and Retriever.
     * * extraction of the Run object based on the request. 
     * Validates that the run exists and the authenticated user has permission to access it.
     *
     * @param object $request The JSON request object.
     * @return Run|false Returns Run object on success, false on failure (sets error data).
     */
    protected function getRunFromRequest($request)
    {
        if (empty($request->run->name)) {
            $this->setData(Response::STATUS_NOT_FOUND, 'Not Found', null, 'Required "run : { name: }" parameter not found.');
            return false;
        }

        $run = new Run($request->run->name);
        if (!$run->valid || !$this->user) {
            $this->setData(Response::STATUS_NOT_FOUND, 'Not Found', null, 'Invalid Run or run/user not found');
            return false;
        } elseif (!$this->user->created($run)) {
            $this->setData(Response::STATUS_UNAUTHORIZED, 'Unauthorized Access', null, 'Unauthorized access to run');
            return false;
        }

        return $run;
    }

    protected function setData($statusCode = null, $statusText = null, $response = null, $error = null)
    {
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

    protected function setError($code = null, $error = null, $desc = null)
    {
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

    protected function parseJsonRequest()
    {
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

    /**
     * Survey Data Retriever.
     * Constructs and executes a complex SQL query to fetch survey results 
     * based on filters (Session ID, Survey Items, Run Name).
     *
     * @param Run $run The Run context.
     * @param string $survey_name Name of the survey.
     * @param string|null $survey_items Comma-separated list of items to filter.
     * @param array|null $sessions List of session IDs to filter.
     * @return array Associative array of results grouped by session.
     */
    protected function getSurveyResults(Run $run, $survey_name, $survey_items = null, $sessions = null)
    {
        $results = array();
        $query = $this->buildSurveyResultsQuery($run, $survey_name, $survey_items, $sessions);
        $stmt = $this->db->query($query, true);

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $session_id = $row['session_id'];
            if (!isset($results[$session_id])) {
                $results[$session_id] = array(
                    'session' => $row['run_session'],
                    'created' => $row['created'],
                    'current_position' => $row['current_position'] // Fixed missing bracket from original code
                );
            }
            if ($row['created'] && !$results[$session_id]['created']) {
                $results[$session_id]['created'] = $row['created'];
            }
            $results[$session_id][$row['item_name']] = $row['answer'];
        }

        return array_values($results);
    }

    protected function buildSurveyResultsQuery(Run $run, $survey_name, $survey_items = null, $sessions = null)
    {
        $params = array(
            'run_id' => $run->id,
            'user_id' => $this->user->id,
            'survey_name' => $this->db->quote($survey_name),
            'WHERE_survey_items' => null,
            'WHERE_run_sessions' => null,
        );

        $q = '
            SELECT itms_display.item_id, itms_display.session_id, itms_display.answer, itms_display.created,
                   survey_items.name AS item_name, survey_run_sessions.session AS run_session, survey_run_sessions.position AS current_position
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
                $itms[] = $this->db->quote(trim($itm));
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
