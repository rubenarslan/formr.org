<?php

class ApiDAO {

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
		$this->user = OAuthDAO::getInstance()->getUserByAccessToken($this->request->getParam('access_token'));
	}

	public function results() {
		/**
		 * $request in this method is expected to be an object of the following format
		 * $request = '{
		 * 		run: {
		 * 			name: 'some_run_name',
		 * 			api_secret: 'run_api_secret_as_show_in_run_settings'
		 *			surveys: [{
		 *				name: 'survey_name',
		 *				items: 'name, email, pov_1'
		 *			}],
		 *			sessions: ['xxxx', 'xxxx']
		 * 		}
		 * }'
		 */
		if (!($request = $this->parseJsonRequest()) || !($run = $this->getRunFromRequest($request))) {
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
				/**  @var Survey $svy */
				$svy = Survey::loadById($survey['id']);
				$items = $svy->getItems('id, type, name');
				$items_names = array();
				foreach ($items as $item) {
					$items_names[] = $item['name'];
				}
				$surveys[] = (object) array(
					'name' => $svy->name,
					'items' => implode(',', $items_names),
					'object' => $svy,
				);
			}
		}

		// Determine which run sessions in the run will be returned.
		// (For now let's prevent returning data of all sessions so this should be required)
		if (!empty($requested_run->session)) {
			$requested_run->sessions = array($requested_run->session);
		}
		// single session or multiple sessions were not requested.
		if (empty($requested_run->sessions)) {
			$this->setData(Response::STATUS_BAD_REQUEST, 'Missing parameter', null, 'The sessions for the requested run were not specified');
			return $this;
		}


		// Get result for each survey for each session
		$results = array();
		foreach ($surveys as $s) {
			if (empty($s->name)) {
				continue;
			}
			if (empty($s->object)) {
				$s->object = Survey::loadByUserAndName($this->user, $s->name);
			}

			/** @var Survey $svy */
			$svy = $s->object;
			if (empty($svy->valid)) {
				$results[$s->name] = null;
				continue;
			}

			if (empty($s->items)) {
				$items = array();
			} elseif (is_array($s->items)) {
				$items = array_map('trim', $s->items);
			} elseif (is_string($s->items)) {
				$items = array_map('trim', explode(',', $s->items));
			} else {
				throw new Exception("Invalid type for survey items. Type: " . gettype($s->itmes));
			}

			//Get data for all requested sessions in this survey
			$results[$s->name] = array();
			foreach ($requested_run->sessions as $session) {
				$results[$s->name] = array_merge($results[$s->name], $this->getSurveyResults($svy, $session, $items));
			}
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
		if(!$run->valid || !$this->user) {
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
		$object = json_decode($this->request->getParam('request'));
		if (!$object) {
			$this->setData(Response::STATUS_BAD_REQUEST, 'Invalid Request', null, "Unable to parse 'request' parameter");
			return false;
		}
		return $object;
	}

	private function getSurveyResults(Survey $survey, $session = null, $requested_items = array()) {
		$data = $survey->getItemDisplayResults($requested_items, $session);
		// Get requested item names to match by id
		$select = $this->fdb->select('id, name')
			->from('survey_items')
			->where(array('study_id' => $survey->id));
		if ($requested_items) {
			$select->whereIn('name', $requested_items);
		}
		$stmt = $select->statement();
		$items = array();
		while (($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
			$items[$row['id']] = $row['name'];
		}

		$results = array();
		foreach ($data as $row) {
			if (!isset($items[$row['item_id']])) {
				continue;
			}
			$session_id = $row['unit_session_id'];
			if (!isset($results[$session_id])) {
				$results[$session_id] =  array('session' => $session);
			}
			$results[$session_id][$items[$row['item_id']]] = $row['answer'];
		}
		return array_values($results);
	}

}
