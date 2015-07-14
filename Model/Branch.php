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
		$dialog = '<div class="padding-below">
			<label>if… <br>
				<textarea style="width:388px;"  data-editor="r" class="form-control col-md-5" name="condition" rows="4" placeholder="Condition: You can use R here: survey1$item2 == 2">' . h($this->condition) . '</textarea>
			</label><br>
			<select style="width:120px" name="automatically_jump">
			<option value="1" ' . ($this->automatically_jump ? 'selected' : '') . '>automatically</option>
			<option value="0" ' . ($this->automatically_jump ? '' : 'selected') . '>if user reacts</option>
			</select>
			<label>skip forward to
			<input type="number" class="form-control" style="width:70px" name="if_true" max="32000" min="' . ($this->position + 2) . '" step="1" value="' . h($this->if_true) . '">
			</label><br>
			<strong>else</strong>
			<select style="width:120px" name="automatically_go_on">
			<option value="1" ' . ($this->automatically_go_on ? 'selected' : '') . '>automatically</option>
			<option value="0" ' . ($this->automatically_go_on ? '' : 'selected') . '>if user reacts</option>
			</select>
			<strong>go on</strong>
		</div>';
		$dialog .= '
			<p class="btn-group">
				<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=SkipForward">Save.</a>
				<a class="btn btn-default unit_test" href="ajax_test_unit?type=SkipForward">Test</a>
			</p>';

		$dialog = $prepend . $dialog;

		return parent::runDialog($dialog);
	}

	public function removeFromRun() {
		return $this->delete();
	}

	public function test() {
		$results = $this->getSampleSessions();

		if (!$results) {
			return false;
		}

		$this->run_session_id = current($results)['id'];
		$opencpu_vars = $this->getUserDataInRun($this->condition);
		$ocpu_session = opencpu_evaluate($this->condition, $opencpu_vars, '', null, true);
		echo opencpu_debug($ocpu_session);

		echo '<table class="table table-striped">
				<thead><tr>
					<th>Code (Position)</th>
					<th>Test</th>
				</tr></thead>
				<tbody>"';

		// Maybe there is a way that we prevent 'calling opencpu' in a loop by gathering what is needed to be evaluated
		// at opencpu in some 'box' and sending one request (also create new func in formr R package to open this box, evaluate what is inside and return the box)
		foreach ($results as $row) {
			$this->run_session_id = $row['id'];
			$opencpu_vars = $this->getUserDataInRun($this->condition);
			$eval = opencpu_evaluate($this->condition, $opencpu_vars);

			echo "<tr>
					<td style='word-wrap:break-word;max-width:150px'><small>" . $row['session'] . " ({$row['position']})</small></td>
					<td>" . stringBool($eval) . "</td>
				</tr>";
		}

		echo '</tbody></table>';
		$this->run_session_id = null;
	}

	public function exec() {
		$opencpu_vars = $this->getUserDataInRun($this->condition);
		$eval = opencpu_evaluate($this->condition, $opencpu_vars);
		if ($eval === null) {
			return true; // don't go anywhere, wait for the error to be fixed!
		}

		$result = (bool)$eval;
		// if condition is true and we're set to jump automatically, or if the user reacted
		if ($result AND ( $this->automatically_jump OR ! $this->called_by_cron)):
			if ($this->run_session->session):
				$this->end();
				return ! $this->run_session->runTo($this->if_true);
			endif;
		elseif (!$result AND ( $this->automatically_go_on OR ! $this->called_by_cron)): // the condition is false and it goes on
			$this->end();
			return false;
		else: // we wait for the condition to turn true or false, depends.
			return true;
		endif;
	}

}
