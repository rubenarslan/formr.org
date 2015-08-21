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

	protected $run;

	/**
	 * Error information
	 *
	 * @var array
	 */
	protected $error = array();

	public function __construct(Request $request, DB $db) {
		$this->fdb = $db;
		$this->request = $request;
	}

	public function results() {
		
		
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
			$this->setError(Response::STATUS_INTERNAL_SERVER_ERROR, 'Error occured when creating session');
			$this->setData(Response::STATUS_INTERNAL_SERVER_ERROR, 'Error Request', $this->error);
		}
	
		return $this;
	}

	public function endLastExternal() {
		if (!($request = $this->parseJsonRequest()) || !($run = $this->getRunFromRequest($request))) {
			return $this;
		}

		if(!empty($request->run->session)) {
			$session_code = $request->run->session;
			$run_session = new RunSession($this->fdb, $run->id, null, $session_code, null);

			if($run_session->session !== NULL) {
				$run_session->endLastExternal();
				$this->setData(Response::STATUS_OK, 'OK', array('success' => 'external unit ended'));
			} else {
				$this->setError(Response::STATUS_NOT_FOUND, 'Invalid Session Token');
				$this->setData(Response::STATUS_INTERNAL_SERVER_ERROR, 'Error Request', $this->error);
			}
		} else {
			$this->setError(Response::STATUS_NOT_FOUND, 'Session code not found');
			$this->setData(Response::STATUS_NOT_FOUND, 'Not Found', $this->error);
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
	 *		run: {
	 *			name: 'some_run_name',
	 *			api_secret: 'run_api_secret_as_show_in_run_settings
	 *		}
	 * }'
	 *
	 * @param object $request A JSON object of the sent request
	 * @return boolean|Run
	 */
	protected function getRunFromRequest($request) {
		if (empty($request->run->name)) {
			$this->setError(Response::STATUS_NOT_FOUND, 'Required "run_name" parameter not found!.');
			$this->setData(Response::STATUS_NOT_FOUND, 'Not Found', $this->error);
			return false;
		}

		$run = new Run($this->fdb, $request->run->name);
		if (!$run->valid) {
			$this->setError(Response::STATUS_NOT_FOUND, 'Invalid Run or run not found');
		} elseif (!$run->hasApiAccess($request->run->api_secret)) {
			$this->setError(Response::STATUS_UNAUTHORIZED, 'Unauthorized access to run');
		}

		if ($this->error) {
			$this->setData(Response::STATUS_NOT_FOUND, 'Not Found', $this->error);
			return false;
		}

		return $run;
	}

	private function setData($statusCode = null, $statusText = null, $response = null) {
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
		$object = @json_decode($this->request->getParam('request'));
		if (!$object) {
			return false;
		}
		return $object;
	}
}

