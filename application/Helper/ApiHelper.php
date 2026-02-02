<?php

class ApiHelper extends ApiBase {


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
        if (!$this->db->count('survey_run_sessions', array('run_id' => $run->id), 'id')) {
            $this->setData(Response::STATUS_NOT_FOUND, 'Not Found', null, 'No sessions were found in this run.');
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
        $run_session = new RunSession(null, $run);
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
            $this->setData(Response::STATUS_INTERNAL_SERVER_ERROR, 'Internal Server Error', null, 'Error occurred when creating session');
        }

        return $this;
    }

    public function endLastExternal() {
        if (!($request = $this->parseJsonRequest())) {
            return $this;
        }

        $run = new Run($request->run->name);
        if (!$run->valid) {
            $this->setData(Response::STATUS_NOT_FOUND, 'Not Found', null, 'Invalid run');
            return $this;
        }

        if (!empty($request->run->session)) {
            $session_code = $request->run->session;
            $run_session = new RunSession($session_code, null);

            if ($run_session->id) {
                $run_session->endLastExternal();
                $this->setData(Response::STATUS_OK, 'OK', array('success' => 'external unit ended'));
            } else {
                $this->setData(Response::STATUS_NOT_FOUND, 'Not Found', null, 'Invalid session');
            }
        } else {
            $this->setData(Response::STATUS_BAD_REQUEST, 'Bad Request', null, 'Session code not found');
        }

        return $this;
    }
}