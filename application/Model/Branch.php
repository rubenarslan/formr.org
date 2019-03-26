<?php

class Branch extends RunUnit {

    public $errors = array();
    public $id = null;
    public $session = null;
    public $unit = null;
    protected $condition = null;
    protected $if_true = null;
    protected $automatically_jump = 1;
    protected $automatically_go_on = 1;
    public $type = 'Branch';
    public $icon = 'fa-code-fork fa-flip-vertical';

    public function __construct($fdb, $session = null, $unit = null, $run_session = NULL, $run = NULL) {
        parent::__construct($fdb, $session, $unit, $run_session, $run);

        if ($this->id):
            $vars = $this->dbh->select('id, condition, if_true, automatically_jump, automatically_go_on')
                    ->from('survey_branches')
                    ->where(array('id' => $this->id))
                    ->fetch();
            if ($vars):
                array_walk($vars, "emptyNull");
                $this->condition = $vars['condition'];
                $this->if_true = $vars['if_true'];
                $this->automatically_jump = $vars['automatically_jump'];
                $this->automatically_go_on = $vars['automatically_go_on'];
                $this->valid = true;
            endif;
        endif;
    }

    public function create($options) {
        $this->dbh->beginTransaction();
        if (!$this->id) {
            $this->id = parent::create($this->type);
        } else {
            $this->modify($options);
        }

        if (isset($options['condition'])) {
            array_walk($options, "emptyNull");
            $this->condition = $options['condition'];
            if (isset($options['if_true'])) {
                $this->if_true = $options['if_true'];
            }
            if (isset($options['automatically_jump'])) {
                $this->automatically_jump = $options['automatically_jump'];
            }
            if (isset($options['automatically_go_on'])) {
                $this->automatically_go_on = $options['automatically_go_on'];
            }
        }
        $this->condition = cr2nl($this->condition);

        $this->dbh->insert_update('survey_branches', array(
            'id' => $this->id,
            'condition' => $this->condition,
            'if_true' => $this->if_true,
            'automatically_jump' => $this->automatically_jump,
            'automatically_go_on' => $this->automatically_go_on
        ));
        $this->dbh->commit();
        $this->valid = true;

        return true;
    }

    public function displayForRun($prepend = '') {
        $dialog = Template::get($this->getUnitTemplatePath(), array(
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
            return false;
        }

        $test_tpl = '
			<table class="table table-striped">
				<thead>
					<tr>
						<th>Code (Position)</th>
						<th>Test</th>
					</tr>
					%{rows}
				</thead>
			</table>
		';

        $row_tpl = '
			<tr>
				<td style="word-wrap:break-word;max-width:150px"><small>%{session} (%{position})</small></td>
				<td>%{result}</td>
			<tr>
		';

        $this->run_session_id = current($results)['id'];
        $opencpu_vars = $this->getUserDataInRun($this->condition);
        $ocpu_session = opencpu_evaluate($this->condition, $opencpu_vars, 'text', null, true);
        echo opencpu_debug($ocpu_session, null, 'text');

        // Maybe there is a way that we prevent 'calling opencpu' in a loop by gathering what is needed to be evaluated
        // at opencpu in some 'box' and sending one request (also create new func in formr R package to open this box, evaluate what is inside and return the box)
        $rows = '';
        foreach ($results as $row) {
            $this->run_session_id = $row['id'];
            $opencpu_vars = $this->getUserDataInRun($this->condition);
            $eval = opencpu_evaluate($this->condition, $opencpu_vars);
            $rows .= Template::replace($row_tpl, array(
                        'session' => $row['session'],
                        'position' => $row['position'],
                        'result' => stringBool($eval),
            ));
        }

        echo Template::replace($test_tpl, array('rows' => $rows));
        $this->run_session_id = null;
    }

    public function exec() {
        $opencpu_vars = $this->getUserDataInRun($this->condition);
        $eval = opencpu_evaluate($this->condition, $opencpu_vars);
        if ($eval === null) {
            return true; // don't go anywhere, wait for the error to be fixed!
        }

        // If execution returned a timestamp less than that now(), skipping is FALSE
        if (($time = strtotime($eval)) && $time < time()) {
            $eval = false;
        }

        $result = (bool) $eval;
        // if condition is true and we're set to jump automatically, or if the user reacted
        if ($result && ($this->automatically_jump || !$this->called_by_cron)):
            if ($this->run_session->session):
                $this->end();
                return !$this->run_session->runTo($this->if_true);
            endif;
        elseif (!$result && ($this->automatically_go_on || !$this->called_by_cron)): // the condition is false and it goes on
            $this->end();
            return false;
        else: // we wait for the condition to turn true or false, depends.
            return true;
        endif;
    }

}
