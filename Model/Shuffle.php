<?php

class Shuffle extends RunUnit {

	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	private $groups = 2;
	public $ended = false;
	public $type = 'Shuffle';
	public $icon = "fa-random";

	public function __construct($fdb, $session = null, $unit = null, $run_session = NULL) {
		parent::__construct($fdb, $session, $unit, $run_session);

		if ($this->id):
			$groups = $this->dbh->findValue('survey_shuffles', array('id' => $this->id), array('groups'));
			if ($groups):
				$this->groups = $groups;
				$this->valid = true;
			endif;
		endif;
	}

	public function create($options) {
		$this->dbh->beginTransaction();
		if (!$this->id) {
			$this->id = parent::create('Shuffle');
		} else {
			$this->modify($options);
		}

		if (isset($options['groups'])) {
			$this->groups = $options['groups'];
		}

		$this->dbh->insert_update('survey_shuffles', array(
			'id' => $this->id,
			'groups' => $this->groups,
		));
		$this->dbh->commit();
		$this->valid = true;

		return true;
	}

	public function displayForRun($prepend = '') {
		$dialog = '<h5>Randomly assign to one of <input style="width:100px" class="form-control" type="number" placeholder="2" name="groups" value="' . h($this->groups) . '"> groups counting from one.</h5>
			<p>You can later read the assigned group using <code>shuffle$group</code>. <br>
		You can then for example use a SkipForward to send one group to a different arm/path in the run or use a showif in a survey to show certain items/stimuli to one group only.</p>
		';
#			'<p><input type="hidden" name="end" value="0"><label><input type="checkbox" name="end" value="1"'.($this->can_be_ended ?' checked ':'').'> allow user to continue after viewing page</label></p>';
		$dialog .= '<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Shuffle">Save.</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=Shuffle">Preview</a></p>';


		$dialog = $prepend . $dialog;

		return parent::runDialog($dialog, 'fa-random fa-1-5x');
	}

	public function removeFromRun() {
		return $this->delete();
	}

	public function randomise_into_group() {
		return mt_rand(1, $this->groups);
	}

	public function test() {

		echo '<h3>Randomisation</h3>
			<p>We just generated fifty random group assignments:</p>';
		for ($i = 0; $i < 50; $i++):
			echo $this->randomise_into_group() . '&nbsp; ';
		endfor;
		echo '<p>Remember that we start counting at one (1), so if you have two groups you will check <code>shuffle$group == 1</code> and <code>shuffle$group == 2</code>. You can read a person\'s 
			group using <code>shuffle$group</code>. If you generate more than one
		random group in a run, you might have to use the last one <code>tail(shuffle$group,1)</code>, but
		usually you shouldn\'t do this.</p>';
	}

	public function exec() {
		$group = $this->randomise_into_group();
		$this->dbh->insert('shuffle', array(
			'session_id' => $this->session_id,
			'unit_id' => $this->id,
			'group' => $group,
			'created' => mysql_now()
		));
		$this->end();
		return false;
	}

}
