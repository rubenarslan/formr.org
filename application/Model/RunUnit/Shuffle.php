<?php

class Shuffle extends RunUnit {

    public $type = 'Shuffle';
    
    public $icon = "fa-random";
    
    protected $groups = 2;

    /**
     * An array of unit's exportable attributes
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special', 'groups');

   public function __construct(Run $run, array $props = []) {
        parent::__construct($run, $props);

        if ($this->id) {
            $groups = $this->db->findValue('survey_shuffles', array('id' => $this->id), array('groups'));
            if ($groups) {
                $this->groups = $groups;
                $this->valid = true;
            }
        }
    }

    public function create($options = []) {
        parent::create($options);

        if (isset($options['groups'])) {
            $this->groups = $options['groups'];
        }

        $this->db->insert_update('survey_shuffles', array(
            'id' => $this->id,
            'groups' => $this->groups,
        ));

        $this->valid = true;

        return $this;
    }

    public function displayForRun($prepend = '') {

        $dialog = Template::get($this->getTemplatePath(), array(
            'prepend' => $prepend,
            'groups' => $this->groups
        ));

        return parent::runDialog($dialog);
    }

    public function removeFromRun($special = null) {
        return $this->delete($special);
    }

    public function selectRandomGroup() {
        return mt_rand(1, $this->groups);
    }

    public function test() {
        $test_tpl = '
			<h3>Randomisation</h3>
			<p>We just generated fifty random group assignments:</p>
			<div> %{groups} </div>
			<p>Remember that we start counting at one (1), so if you have two groups you will check <code>shuffle$group == 1</code> and <code>shuffle$group == 2</code>.
			You can read a person\'s group using <code>shuffle$group</code>.
			If you generate more than one random group in a run, you might have to use the last one <code>tail(shuffle$group,1)</code>, 
			but usually you shouldn\'t do this.</p>
		';

        $groups = '';
        for ($i = 0; $i < 50; $i++) {
            $groups .= $this->selectRandomGroup() . '&nbsp; ';
        }

        return Template::replace($test_tpl, array('groups' => $groups));
    }
    
    public function getUnitSessionOutput(UnitSession $unitSession) {
        // BY DESIGN each visit draws a fresh random group: every revisit
        // (SkipBackward loop) is a new unit session and re-randomizes —
        // designs wanting a constant experimental group keep the Shuffle
        // OUT of the loop. (Review 2026-07, item 12: audit F18 briefly made
        // the first-ever draw sticky per participant + unit; that was a
        // mistake and is reverted per the maintainer.)
        $group = $this->selectRandomGroup();

        // Audit F17 (2026-07): idempotent write. The shuffle PK is
        // session_id, so a re-execution of the SAME unit-session (crash
        // between this INSERT and end()) hit an uncaught duplicate-key
        // error and stranded the participant on every retry. ON DUPLICATE
        // KEY UPDATE keeps the originally drawn group; report the STORED
        // value so a retry logs what actually stands, not the losing draw.
        $this->db->exec(
            "INSERT INTO `shuffle` (`session_id`, `unit_id`, `group`, `created`)
             VALUES (:session_id, :unit_id, :group, :created)
             ON DUPLICATE KEY UPDATE `group` = `group`",
            [
                'session_id' => $unitSession->id,
                'unit_id'    => $this->id,
                'group'      => $group,
                'created'    => mysql_now(),
            ]
        );
        $group = (int) $this->db->findValue('shuffle', ['session_id' => $unitSession->id], 'group');

        return [
            'log' => $this->getLogMessage('group_' . $group),
            'end_session' => true,
            'move_on' => true,
        ];
    }

}
