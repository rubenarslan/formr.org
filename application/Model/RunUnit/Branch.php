<?php

class Branch extends RunUnit {

    protected $condition = null;
    protected $if_true = null;
    protected $automatically_jump = 1;
    protected $automatically_go_on = 1;
    
    public $type = 'Branch';
    public $icon = 'fa-code-fork fa-flip-vertical';

    /**
     * Lowest run position a computed jump may target (v1.8.0).
     *
     * 0 and 1 are reserved for the boolean reading of a condition, so a
     * computed jump to position 0 or 1 is deliberately not expressible. R
     * has no distinct boolean-vs-integer signal that survives the round
     * trip — OpenCPU returns JSON, so `TRUE`, `1L` and `1` all reach PHP as
     * the same value — and the conditions people actually write return 1/0
     * constantly: `screener$consent` on a 1/0 coded item, `sum(x)`,
     * `nrow(df)`, `as.numeric(...)`. Reading those as positions silently
     * reverses the routing of every skip that predates computed jumps, and
     * the failure is quiet: on a run numbered 10/20/30 there is no unit at
     * position 1, so the "target does not exist" arm continues past the
     * skip — the exact branch the skip existed to avoid.
     *
     * Nothing is lost by the reservation. A run's first unit is virtually
     * never at position 1 (the admin UI numbers in tens), and any run that
     * does have one can be reached by renumbering. Agreed with @timseidel
     * before release; see CHANGELOG v1.8.0.
     */
    const MIN_COMPUTED_JUMP_POSITION = 2;

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
        // v1.8.0: unlink the placement, do NOT delete the unit definition.
        //
        // This override used to be `return $this->delete($special)`, and
        // delete() drops the survey_units row — which
        // survey_unit_sessions.unit_id references ON DELETE CASCADE. So
        // removing one of these units from a run silently deleted every
        // participant's unit session for it: not a strand, an erasure of the
        // history an admin was still looking at. Six of the nine unit types
        // did this (Page/Endpage, Pause, External, Branch, SkipForward,
        // SkipBackward, Shuffle); the three that use the base removeFromRun
        // (Survey, Email, PushMessage) only ever unlinked, which is why the
        // difference went unnoticed.
        //
        // The base ends live sessions at the placement first (audit F22/F7)
        // and then deletes only the survey_run_units row. An orphaned unit
        // definition is the price, and the admin UI already has a path for
        // those ("Just delete orphan units", AdminAjaxController).
        return parent::removeFromRun($special);
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
        $ocpu_session = opencpu_evaluate($this->conditionForEvaluation(), $opencpu_vars, 'text', null, true);
        $output = opencpu_debug($ocpu_session, null, 'text');

        // Maybe there is a way that we prevent 'calling opencpu' in a loop by gathering what is needed to be evaluated
        // at opencpu in some 'box' and sending one request (also create new func in formr R package to open this box, evaluate what is inside and return the box)
        $rows = '';
        foreach ($results as $unitSession) {
            $opencpu_vars = $unitSession->getRunData($this->condition);
            $eval = opencpu_evaluate($this->conditionForEvaluation(), $opencpu_vars);
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
     * Does this evaluated condition ask for a computed jump, and to where?
     *
     * Returns the target position, or null when the value should be read as
     * a boolean instead. The single place that decides — both
     * getUnitSessionExpirationData() (the real routing) and describeRouting()
     * (the admin Test view's "Routes to" column) call it, so the preview
     * cannot drift from the behaviour it claims to preview.
     *
     * See MIN_COMPUTED_JUMP_POSITION for why 0 and 1 are not jump targets.
     */
    protected function computedJumpTarget($eval) {
        if (is_bool($eval) || !is_numeric($eval)) {
            return null;
        }
        $target = (int) round((float) $eval);

        return $target >= self::MIN_COMPUTED_JUMP_POSITION ? $target : null;
    }

    /**
     * Fold the interpretation notes and an optional terminal message into one
     * result_log string. Returns null when there is nothing to say, so a
     * clean TRUE/FALSE condition records no log at all, as before.
     */
    protected function joinNotes(array $notes, $message = null) {
        if ($message !== null) {
            $notes[] = $message;
        }

        return $notes ? implode(' ', $notes) : null;
    }

    /**
     * Human-readable destination for a single evaluated result, mirroring the
     * routing in getUnitSessionExpirationData() so the Test view shows where a
     * participant would actually go (not just the raw R value).
     */
    protected function describeRouting($eval, UnitSession $unitSession) {
        if ($eval === []) {
            // logical(0) / NULL: halts on the live path with its own message.
            return 'halt (R returned an empty result — make the condition return TRUE/FALSE)';
        }
        if (is_array($eval)) {
            $eval = array_shift($eval);
        }
        if (is_array($eval)) {
            // A nested list: array_shift() unwrapped one level and found
            // another. Say so rather than falling through to strtotime(),
            // which takes a string and would TypeError out of ajax_test_unit,
            // fataling the whole Test view over one participant's result.
            return 'cannot route (R returned a nested list)';
        }
        if ($eval === null) {
            // Same split as getUnitSessionExpirationData(): a failed
            // evaluation leaves a message behind, NA does not. Both halt.
            return (string) opencpu_last_error() !== ''
                ? 'halt (R error — fix the condition)'
                : 'halt (R returned NA — make the condition return TRUE/FALSE)';
        }
        if (($target = $this->computedJumpTarget($eval)) !== null) {
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

    /**
     * Patch 080's wrapper exactly as the migration wrote it: `as.logical({`
     * at the very start of the condition. An author-written
     * as.logical(...) call (no brace) is deliberately not matched.
     */
    const LEGACY_BOOLEAN_WRAPPER = '/^\s*as\.logical\(\{/';

    /**
     * The R code actually sent for evaluation.
     *
     * A condition wrapped by patch 080 is meant to keep the reading it had
     * before computed jumps (v1.8.0). as.logical() reproduces it for numbers
     * (non-zero → TRUE) and booleans, but not for a Date / POSIXct result:
     * pre-1.10.3 that came back as a string and the coercion below read a
     * past date as TRUE and a future one as FALSE, whereas as.logical() of a
     * Date is its day count — always TRUE — so a "not yet" skip silently
     * became "jump". as.logical is an S3 internal generic: two methods
     * defined in the closure the evaluation runs in give a wrapped condition
     * its past/future reading back. They are visible to that one evaluation
     * only and are added only when the condition carries the wrapper.
     * Strings are unaffected (as.logical("2026-01-01") stays NA, the
     * documented halt).
     */
    protected function conditionForEvaluation() {
        $code = (string) $this->condition;
        if (!preg_match(self::LEGACY_BOOLEAN_WRAPPER, $code)) {
            return $this->condition;
        }
        return "as.logical.Date <- function(x, ...) x <= Sys.Date()\n"
             . "as.logical.POSIXt <- function(x, ...) x <= Sys.time()\n"
             . $code;
    }

    public function getUnitSessionExpirationData(UnitSession $unitSession) {
        $data = ['expire_relatively' => null, 'check_failed' => false];
        $opencpu_vars = $unitSession->getRunData($this->condition);
        $eval = opencpu_evaluate($this->conditionForEvaluation(), $opencpu_vars);

        // null is what opencpu_evaluate() returns for a failed evaluation —
        // and ALSO what R's NA decodes to (JSON null). Only a failure leaves
        // a message in opencpu_last_error(); NA is handled further down.
        if ($eval === null && ($error = (string) opencpu_last_error()) !== '') {
            notify_study_admin($unitSession, 'Branch unit: OpenCPU error evaluating condition. ' . $error, 'error');
            $data['log'] = $this->getLogMessage('error_opencpu_r', "OpenCPU error. Fix R code \n\n" . $error);
            $data['wait_opencpu'] = true;
            // v1.8.0: also flag check_failed so UnitSession::isExpired()'s F19
            // backoff re-arms this row (queued=QUEUED_TO_EXECUTE at an
            // escalating deadline). Pause sets check_failed on the identical
            // OpenCPU failure; this class did not — so a skip unit that was
            // already queued kept its past `expires` and the daemon re-picked
            // it on EVERY pass: ~5,760 OpenCPU evaluations a day, and an admin
            // error mail every throttle window, for one stuck participant.
            //
            // Reaches SkipForward/SkipBackward, which extend this class and
            // inherit this method — 'Branch' is an internal base class and
            // never a survey_units.type value. Rows parked at queued=0 are a
            // separate concern already covered by the stalled-session sweep,
            // which lists both concrete skip types.
            $data['check_failed'] = true;
            return $data;
        }
        // Notes about how the raw R value had to be interpreted. Collected
        // rather than written straight into $data['log'], because every
        // terminal branch below assigns $data['log'] wholesale — so the
        // "more than one result" warning that used to be set here never
        // reached result_log at all. They ride along on the terminal message
        // instead, which is the row an admin actually reads.
        $notes = [];

        if ($eval === []) {
            // logical(0) / NULL — OpenCPU serialises both to an empty JSON
            // value. Typically `survey$x == 'yes'` where x does not exist for
            // this participant. There is nothing to route on; before v1.10.3
            // the unwrap below turned it into null and (bool) null read as
            // "continue", silently. Halt like NA does, but say what it was:
            // the NA message and the "more than one result" note would both
            // be wrong for an empty vector.
            $reason = 'Skip unit: your R code returned an empty result (logical(0) or NULL) instead of TRUE/FALSE, so the participant cannot be routed. This is usually a comparison against a column that does not exist for this participant. Return a single TRUE/FALSE, e.g. isTRUE(survey$consent == "yes").';
            notify_study_admin($unitSession, $reason, 'error');
            $data['log'] = $this->getLogMessage('error_opencpu_r', $reason);
            $data['wait_opencpu'] = true;
            $data['check_failed'] = true;
            $this->outputData = $data;
            return $data;
        }

        if (is_array($eval)) {
            $eval = array_shift($eval);
            $notes[] = "Your R code is returning more than one result. Please fix your code, so it returns only true/false.";
        }

        if (is_array($eval)) {
            // A NESTED list: the shift above unwrapped one level and found
            // another. Everything below assumes a scalar — `strtotime($eval)`
            // in particular takes a string, and handing it an array is a PHP 8
            // TypeError. That is an Error, not an Exception, so neither
            // RunSession::execute()'s finally (lock release only) nor
            // UnitSessionQueue::run()'s catch(Exception) contains it: the
            // participant gets a 500 and a queued row crash-loops the run
            // daemon. It also threw before isExpired() could call logOutput(),
            // so the row kept result and result_log NULL and recorded
            // nothing at all. describeRouting() gained this guard in fc4f5ebb
            // and the live path did not — exactly the preview/behaviour drift
            // computedJumpTarget() was extracted to prevent. Treat it as the
            // author error it is: halt, notify, and back off like any other
            // unevaluable condition.
            $reason = 'Skip unit: your R code returned a nested list. Return a single TRUE/FALSE or a position number.';
            notify_study_admin($unitSession, $reason, 'error');
            $data['log'] = $this->getLogMessage('error_opencpu_r', $this->joinNotes($notes, $reason));
            $data['wait_opencpu'] = true;
            $data['check_failed'] = true;
            $this->outputData = $data;
            return $data;
        }

        if ($eval === null) {
            // R's NA (v1.10.3): a comparison against missing data —
            // `survey$x == 1` with x unanswered — or as.logical() of free
            // text (patch 080's wrapper). There is no right way to route on
            // "unknown", and this did halt before — but since null is also
            // the failed-evaluation signal it landed in the branch above as
            // "OpenCPU error evaluating condition. " with nothing after the
            // colon, while the Test view previewed the same value as
            // "continue (coerced)". Name it and say how to fix it; the row
            // backs off like any other unevaluable condition and picks up
            // the fix at the next retry. describeRouting() mirrors this.
            $reason = 'Skip unit: your R code returned NA (a missing value) instead of TRUE/FALSE, so the participant cannot be routed. Handle missing data in the condition, e.g. isTRUE(...) or !is.na(x) && x == 1.';
            notify_study_admin($unitSession, $reason, 'error');
            $data['log'] = $this->getLogMessage('error_opencpu_r', $this->joinNotes($notes, $reason));
            $data['wait_opencpu'] = true;
            $data['check_failed'] = true;
            $this->outputData = $data;
            return $data;
        }

        // Numeric return ≥ MIN_COMPUTED_JUMP_POSITION → treat as an absolute
        // run position (computed jump). 0 and 1 keep their boolean reading
        // and fall through below; see MIN_COMPUTED_JUMP_POSITION.
        //
        // Unlike the boolean path below, a computed jump is unconditional by
        // design: it ignores automatically_jump/automatically_go_on and the
        // cron gate (there is no "wait for user" semantics for a computed
        // destination), so it routes immediately whether executed live or by
        // cron.
        if (($target = $this->computedJumpTarget($eval)) !== null) {
            if ($unitSession->runSession->getUnitIdAtPosition($target)) {
                $data['log'] = $this->getLogMessage('skip_to', $this->joinNotes($notes, "Computed jump to position $target."));
                $data['end_session'] = true;
                $data['run_to'] = $target;
            } else {
                $reason = "Skip unit: computed position $target has no unit; continuing after the skip.";
                notify_study_admin($unitSession, $reason, 'error');
                $data['log'] = $this->getLogMessage('skip_to_invalid', $this->joinNotes($notes, $reason));
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
            if (is_numeric($eval)) {
                // Says which of the two readings applied, because a numeric
                // condition is exactly where an author can be surprised now
                // that larger numbers ARE jump targets.
                $notes[] = "Your R code returned the number " . (0 + $eval) . ", which is read as "
                    . ($result ? 'TRUE' : 'FALSE') . ". Only " . self::MIN_COMPUTED_JUMP_POSITION
                    . " and above are treated as jump targets — 0 and 1 always mean FALSE/TRUE. Please return TRUE/FALSE explicitly.";
            } else {
                $notes[] = "Your R code is not returning true/false. Please fix your code soon.";
            }
        }
        
        if ($result && ($this->automatically_jump || !$unitSession->isExecutedByCron())) {
            // if condition is true and we're set to jump automatically, or if the user reacted
            // Audit F9 (2026-07): validate the jump target BEFORE ending
            // this branch. A dangling if_true (target unit deleted/
            // renumbered) otherwise ended the branch and then failed the
            // jump, so the next request fell through sequentially into
            // the exact arm the branch was meant to skip — silently, with
            // no admin signal. Halt and notify instead; the participant
            // waits until the admin fixes the target.
            if ($this->if_true === null || !$unitSession->runSession->getUnitIdAtPosition($this->if_true)) {
                notify_study_admin($unitSession, 'Branch unit: jump target (if_true = ' . h($this->if_true) . ') does not exist in the run. Fix the branch.', 'error');
                $data['log'] = $this->getLogMessage('error_opencpu_r', $this->joinNotes($notes, 'Branch jump target position ' . h($this->if_true) . ' does not exist. Fix the branch structure.'));
                $data['wait_opencpu'] = true;
                // v1.8.0: same reason as the OpenCPU-error path above — flag
                // check_failed so the F19 backoff re-arms the row instead of
                // leaving it to be re-picked every daemon pass (or, at
                // queued=0, never again). The admin fix is then picked up at
                // the next retry.
                $data['check_failed'] = true;
                $this->outputData = $data;
                return $data;
            }
            $data['log'] = $this->getLogMessage('skip_true', $this->joinNotes($notes));
            $data['end_session'] = true;
            $data['run_to'] = $this->if_true;
        } elseif (!$result && ($this->automatically_go_on || !$unitSession->isExecutedByCron())) {
             // the condition is false and it goes on
            $data['log'] = $this->getLogMessage('skip_false', $this->joinNotes($notes));
            $data['end_session'] = $data['move_on'] = true;
        } else {
            $data['log'] = $this->getLogMessage('waiting_deprecated', $this->joinNotes($notes, 'formr is phasing out support for delayed skipbackwards/forwards. Please switch to a different approach soon'));
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
