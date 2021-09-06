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

}
