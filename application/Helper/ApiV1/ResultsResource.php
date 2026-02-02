<?php

class ResultsResource extends BaseResource
{

    private $run;

    public function handle($runName = null)
    {
        $this->validateRun($runName);
        if (!$this->run) {
            return $this;
        }

        if ($this->getRequestMethod() !== 'GET') {
            return $this->error(405, 'Method not allowed. Use GET.');
        }

        $this->checkScope('data:read');
        ini_set('memory_limit', Config::get('memory_limit.run_get_data'));

        if (!$this->db->count('survey_run_sessions', array('run_id' => $this->run->id), 'id')) {
            return $this->error(404, 'No sessions were found in this run.');
        }

        $getParam = function ($key) {
            $val = $_GET[$key] ?? null;
            if (!$val) return null;
            return is_array($val) ? $val : array_map('trim', explode(',', $val));
        };

        $filterSessions = $getParam('sessions');
        $filterSurveys  = $getParam('surveys');
        $filterItems    = $getParam('items');

        $surveysToProcess = [];

        if (!empty($filterSurveys)) {
            foreach ($filterSurveys as $sName) {
                $surveysToProcess[] = (object) [
                    'name' => $sName,
                    'items' => $filterItems
                ];
            }
        } else {
            $allSurveys = $this->run->getAllSurveys();
            foreach ($allSurveys as $s) {
                $surveysToProcess[] = (object) [
                    'name' => $s['name'],
                    'items' => $filterItems
                ];
            }
        }

        $results = [];

        foreach ($surveysToProcess as $s) {
            $itemsString = ($s->items && is_array($s->items)) ? implode(',', $s->items) : $s->items;

            $surveyData = $this->getSurveyResults(
                $this->run,
                $s->name,
                $itemsString,
                $filterSessions
            );

            $results[$s->name] = $surveyData;
        }

        $results['shuffles'] = $this->getShuffleResults($this->run, $filterSessions);

        return $this->response(200, 'OK', $results);
    }

    private function validateRun($runName)
    {
        $mockRequest = (object) ['run' => (object) ['name' => $runName]];
        $this->run = $this->getRunFromRequest($mockRequest);
    }
}
