<?php

class Branch extends RunUnit {

    protected $condition = null;
    protected $if_true = null;
    protected $automatically_jump = 1;
    protected $automatically_go_on = 1;
    
    public $type = 'Branch';
    public $icon = 'fa-code-fork fa-flip-vertical';
    
    protected $outputData;

    public function __construct(Run $run, array $props = []) {
        parent::__construct($run, $props);

        if ($this->id) {
            $vars = $this->db->findRow('survey_branches', ['id' => $this->id], 'id, condition, if_true, automatically_jump, automatically_go_on');
            if ($vars) {
                array_walk($vars, "emptyNull");
                $vars['valid'] = true;
                $this->assignProperties($vars);
            }
        }
    }

    public function create($options = []) {
        $this->db->beginTransaction();
        parent::create($options);

        if (isset($options['condition'])) {
            array_walk($options, "emptyNull");
            $this->assignProperties($options);
        }
        
        $this->condition = cr2nl($this->condition);

        $this->db->insert_update('survey_branches', array(
            'id' => $this->id,
            'condition' => $this->condition,
            'if_true' => $this->if_true,
            'automatically_jump' => $this->automatically_jump,
            'automatically_go_on' => $this->automatically_go_on
        ));
        $this->db->commit();
        $this->valid = true;

        return $this;
    }

    public function displayForRun($prepend = '') {
        $dialog = Template::get($this->getTemplatePath(), array(
            'prepend' => $prepend,
            'condition' => $this->condition,
            'position' => $this->position,
            'ifTrue' => $this->if_true,
            'jump' => $this->automatically_jump,
            'goOn' => $this->automatically_go_on,
        ));

        return parent::runDialog($dialog);
    }

    public function removeFromRun($special = null) {
        return $this->delete($special);
    }

    public function test() {
        $results = $this->getSampleSessions();
        if (!$results) {
            $this->noTestSession();
            return null;
        }

        $test_tpl = '
			<table class="table table-striped">
				<thead>
					<tr>
						<th>Code (Position)</th>
						<th>Test</th>
						<th>Routes to</th>
					</tr>
					%{rows}
				</thead>
			</table>
		';

        $row_tpl = '
			<tr>
				<td style="word-wrap:break-word;max-width:150px"><small>%{session} (%{position})</small></td>
				<td>%{result}</td>
				<td><small>%{routing}</small></td>
			<tr>
		';

        // take the first sample session
        $unitSession = current($results);
        $opencpu_vars = $unitSession->getRunData($this->condition);
        $ocpu_session = opencpu_evaluate($this->condition, $opencpu_vars, 'text', null, true);
        $output = opencpu_debug($ocpu_session, null, 'text');

        // Maybe there is a way that we prevent 'calling opencpu' in a loop by gathering what is needed to be evaluated
        // at opencpu in some 'box' and sending one request (also create new func in formr R package to open this box, evaluate what is inside and return the box)
        $rows = '';
        foreach ($results as $unitSession) {
            $opencpu_vars = $unitSession->getRunData($this->condition);
            $eval = opencpu_evaluate($this->condition, $opencpu_vars);
            $rows .= Template::replace($row_tpl, array(
                'session' => $unitSession->runSession->session,
                'position' => $unitSession->runSession->position,
                'result' => stringBool($eval),
                'routing' => $this->describeRouting($eval, $unitSession),
            ));
        }

        $output .= Template::replace($test_tpl, array('rows' => $rows));

        return $output;
    }

    /**
     * Human-readable destination for a single evaluated result, mirroring the
     * routing in getUnitSessionExpirationData() so the Test view shows where a
     * participant would actually go (not just the raw R value).
     */
    protected function describeRouting($eval, UnitSession $unitSession) {
        if (is_array($eval)) {
            $eval = array_shift($eval);
        }
        if (!is_bool($eval) && is_numeric($eval)) {
            $target = (int) round((float) $eval);
            return $unitSession->runSession->getUnitIdAtPosition($target)
                ? "jump to position $target"
                : "continue (position $target has no unit)";
        }
        if ($eval === true) {
            return 'jump to position ' . $this->if_true;
        }
        if ($eval === false) {
            return 'continue';
        }
        // Non-boolean, non-numeric: mirror the legacy coercion path, including
        // the future/past timestamp handling in getUnitSessionExpirationData().
        if ($eval && ($time = strtotime($eval)) && $time > time()) {
            $result = false;
        } elseif ($eval && ($time = strtotime($eval)) && $time <= time()) {
            $result = true;
        } else {
            $result = (bool) $eval;
        }
        return ($result ? 'jump to position ' . $this->if_true : 'continue') . ' (coerced)';
    }

    public function getUnitSessionExpirationData(UnitSession $unitSession) {
        $data = ['expire_relatively' => null, 'check_failed' => false];
        $opencpu_vars = $unitSession->getRunData($this->condition);
        $eval = opencpu_evaluate($this->condition, $opencpu_vars);
        
        if ($eval === null) {
            $error = (string) opencpu_last_error();
            notify_study_admin($unitSession, 'Branch unit: OpenCPU error evaluating condition. ' . $error, 'error');
            $data['log'] = $this->getLogMessage('error_opencpu_r', "OpenCPU error. Fix R code \n\n" . $error);
            $data['wait_opencpu'] = true;
            return $data;
        }
        if (is_array($eval)) {
            $eval = array_shift($eval);
            $data['log'] = $this->getLogMessage('opencpu_result_warn', "Your R code is returning more than one result. Please fix your code, so it returns only true/false");
        }

        // Numeric return → treat as absolute run position (computed jump).
        // Unlike the boolean path below, a computed jump is unconditional by
        // design: it ignores automatically_jump/automatically_go_on and the
        // cron gate (there is no "wait for user" semantics for a computed
        // destination), so it routes immediately whether executed live or by
        // cron. The explicit is_bool() guard keeps real booleans out of here.
        if (!is_bool($eval) && is_numeric($eval)) {
            $target = (int) round((float) $eval);
            if ($unitSession->runSession->getUnitIdAtPosition($target)) {
                $data['log'] = $this->getLogMessage('skip_to', "Computed jump to position $target.");
                $data['end_session'] = true;
                $data['run_to'] = $target;
            } else {
                $reason = "Skip unit: computed position $target has no unit; continuing after the skip.";
                notify_study_admin($unitSession, $reason, 'error');
                $data['log'] = $this->getLogMessage('skip_to_invalid', $reason);
                $data['end_session'] = $data['move_on'] = true;
            }
            $this->outputData = $data;
            return $data;
        }

        if($eval === true || $eval === false) {
            $result = $eval;
        } else {
            // If execution returned a timestamp in the future, then branching evaluates to FALSE
            if ($eval && ($time = strtotime($eval)) && $time > time()) {
                $result = false;
            } elseif ($eval && ($time = strtotime($eval)) && $time <= time()) {
                $result = true;
            } else {
                $result = (bool) $eval;
            }
            $data['log'] = $this->getLogMessage('opencpu_result_warn', "Your R code is not returning true/false. Please fix your code soon");
        }
        
        if ($result && ($this->automatically_jump || !$unitSession->isExecutedByCron())) {
            // if condition is true and we're set to jump automatically, or if the user reacted
            $data['log'] = $this->getLogMessage('skip_true');
            $data['end_session'] = true;
            $data['run_to'] = $this->if_true;
        } elseif (!$result && ($this->automatically_go_on || !$unitSession->isExecutedByCron())) {
             // the condition is false and it goes on
            $data['log'] = $this->getLogMessage('skip_false');
            $data['end_session'] = $data['move_on'] = true;
        } else {
            $data['log'] = $this->getLogMessage('waiting_deprecated', 'formr is phasing out support for delayed skipbackwards/forwards. Please switch to a different approach soon');
            $data['check_failed'] = true;
        }
        
        // We already computed needed output data so save and use when getting session output
        $this->outputData = $data;
        return $data;
    }
    
    public function getUnitSessionOutput(UnitSession $unitSession) {
        unset($this->outputData['log']);
        return $this->outputData;
    }


}
