<?php

class Shuffle extends RunUnit {

	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	public $ended = false;
	public $type = 'Shuffle';
	public $icon = "fa-random";
	protected $groups = 2;

	/**
	 * An array of unit's exportable attributes
	 * @var array
	 */
	public $export_attribs = array('type', 'description', 'position', 'special', 'groups');

	public function __construct($fdb, $session = null, $unit = null, $run_session = NULL, $run = NULL) {
		parent::__construct($fdb, $session, $unit, $run_session, $run);

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
		
		$dialog = Template::get($this->getUnitTemplatePath(), array(
			'prepend' => $prepend,
			'groups' => $this->groups
		));

		return parent::runDialog($dialog);
	}

	public function removeFromRun($special = null) {
		return $this->delete($special);
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
