<?php

class RunUnitFactory {

    const SupportedUnits = array('Survey', 'Pause', 'Email', 'External', 'Page', 'SkipBackward', 'SkipForward', 'Shuffle', 'Wait');

    /**
     * Create a RunUnit object based on supported types
     *
     * @param Run $run The run associated to a unit
     * @param array|null $props
     * 
     * @return RunUnit
     * @throws Exception
     */
    public static function make(Run $run = null, array $props = []) {
        if (isset($props['id']) && empty($props['type'])) {
            $props['type'] = DB::getInstance()->findValue('survey_units', ['id' => (int)$props['id']], 'type');
        }

        if (empty($props['type'])) {
            throw new RuntimeException('Please specify the Unit Type in $props[]');
        }

        $type = $props['type'];

        if (!in_array($type, static::SupportedUnits)) {
            throw new Exception("Unsupported unit type '$type'");
        }

        return new $type($run, $props);
    }

    public static function getSupportedUnits() {
        return static::SupportedUnits;
    }

}

/**
 * Base class for run units. A RunUnit "belongs to" a Run
 * 
 */

class RunUnit extends Model {
    
    public $id = null;
    
    public $description = "";
    
    public $position = 0;
    
    public $type = null;
    
    public $special = null;
    
    public $created = null;
    
    public $modified = null;
    
    public $run_unit_id = null;
    
    public $unit_id = null;

    public $icon = "fa-user";


    /**
     * 
     * @var Run $run
     */
    public $run = null;


    /* TODO remove
    public $user_id = null;
    public $run_unit_id = null; // this is the ID of the unit-to-run-link entry
    public $session = null;
    public $unit = null;
    public $ended = false;
    public $expired = false;
    public $called_by_cron = false;
    public $knitr = false;
    public $session_id = null;
    public $run_session_id = null;
    public $type = '';
    public $icon = 'fa-wrench';
    public $special = false;
    public $valid;
    public $run_id;
    public $ocpu = null;
    public $session_result = null;
    public $session_error = null;
    protected $had_major_changes = false;

     */

    /**
     * An array of unit's exportable attributes
     * 
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special');

    /**
     * 
     * @param Run $run
     */
    public function __construct(Run $run, array $props = []) {
        parent::__construct();
        $this->run = $run;
        $this->assignProperties($props);
        
        if ($this->id) {
            $this->find($this->id, $this->special, $props);
        }
    }

    /**
     * Create a RunUnit and assign it to a run
     *
     * @param array $props
     * @return RunUnit
     */
    public function create($props = []) {
        $this->assignProperties($props);
        
        if ($this->id) {
            return $this->modify($props);
        } else {
            $id = $this->db->insert('survey_units', array(
                'type' => $this->type,
                'created' => mysql_now(),
                'modified' => mysql_now(),
            ));

            $this->valid = true;
            $this->id = $id;

            return $this->addToRun();
        }
    }

    protected function addToRun() {
        if (!is_numeric($this->position)) {
            $this->position = 10;
        }

        if ($this->special && $this->run) {
            $run_unit_id = $this->db->insert('survey_run_special_units', array(
                'id' => $this->id,
                'run_id' => $this->run->id,
                'type' => $this->special,
                'description' => $this->description ?? '',
            ));
        } elseif ($this->run->id) {
            $run_unit_id = $this->db->insert('survey_run_units', array(
                'unit_id' => $this->id,
                'run_id' => $this->run->id,
                'position' => $this->position,
                'description' => $this->description ?? ''
            ));
        }

        $this->run_unit_id = $run_unit_id ?? 0;
        
        return $this;
    }
    
    public function updateUnitId() {
        return $this->dbh->update(
                'survey_run_units', 
                array('unit_id' => $this->id), 
                array('id' => $this->run_unit_id), 
                array('int'), 
                array('int')
        );
    }

    public function modify($options = []) {
        $change = array('modified' => mysql_now());
        $table = empty($options['special']) ? 'survey_run_units' : 'survey_run_special_units';
        if ($this->id && isset($options['description'])):
            $this->db->update($table, array("description" => $options['description']), array('id' => $this->run_unit_id));
            $this->description = $options['description'];
        endif;
        
        return $this->db->update('survey_units', $change, array('id' => $this->id));
    }

    
    public function removeFromRun($special = null) {
        // todo: set run modified
        if ($special !== null) {
            return $this->db->delete('survey_run_special_units', array('id' => $this->run_unit_id, 'type' => $special));
        } else {
            return $this->db->delete('survey_run_units', array('id' => $this->run_unit_id));
        }
    }

    public function getExportUnit() {
        $unit = array();
        foreach ($this->export_attribs as $property) {
            if (property_exists($this, $property)) {
                $unit[$property] = $this->{$property};
            }
        }
        return $unit;
    }

    public static function getDefaults($type) {
        $defaults = array();
        $defaults['ServiceMessagePage'] = array(
            'type' => 'Page',
            'title' => 'Service message',
            'special' => 'ServiceMessagePage',
            'description' => 'Service Message ' . date('d.m.Y'),
            'body' => "# Service message \n This study is currently being serviced. Please return at a later time."
        );

        $defaults['OverviewScriptPage'] = array(
            'type' => 'Page',
            'title' => 'Overview script',
            'special' => 'OverviewScriptPage',
            'body' => "# Intersperse Markdown with R
```{r}
plot(cars)
```");
        $defaults['ReminderEmail'] = array(
            'type' => 'Email',
            'subject' => 'Reminder',
            'special' => 'ReminderEmail',
            'recipient_field' => '',
            'body' => "\nPlease take part in our study at {{login_link}}.",
        );

        return array_val($defaults, $type, array());
    }

    protected function getTemplatePath($tpl = null) {
        $tpl = $tpl ?? strtolower($this->type);
        if ($tpl === 'page') {
            $tpl = 'endpage';
        }
        
        return 'admin/run/units/' . $tpl;
    }

    /** @TODO move to RUN and pass unit **/
    protected function getSampleSessions() {
        $current_position = -9999999;
        if (isset($this->unit['position'])) {
            $current_position = $this->unit['position'];
        }
        $results = $this->db->select('session, id, position')
                        ->from('survey_run_sessions')
                        ->order('position', 'desc')->order('RAND')
                        ->where(array('run_id' => $this->run_id, 'position >=' => $current_position))
                        ->limit(20)->fetchAll();

        if (!$results) {
            alert('No data to compare to yet. Create some test data by sending guinea pigs through the run using the "Test run" function on the left.', 'alert-info');
            return false;
        }
        return $results;
    }

    /** @TODO move to RUN  and pass unit **/
    protected function grabRandomSession() {
        if ($this->run_session_id === NULL) {
            $current_position = -9999999;
            if (isset($this->unit['position'])) {
                $current_position = $this->unit['position'];
            }

            $temp_user = $this->db->select('session, id, position')
                    ->from('survey_run_sessions')
                    ->order('position', 'desc')->order('RAND')
                    ->where(array('run_id' => $this->run_id, 'position >=' => $current_position))
                    ->limit(1)
                    ->fetch();

            if (!$temp_user) {
                alert('No data to compare to yet. Create some test data by sending guinea pigs through the run using the "Test run" function on the left.', 'alert-info');
                return false;
            }

            $this->run_session_id = $temp_user['id'];
        }

        return $this->run_session_id;
    }

    public function getUnitSessionsCount() {
        $reached = $this->db->select(
                array(
                    'SUM(`survey_unit_sessions`.ended IS NULL AND `survey_unit_sessions`.expired IS NULL)' => 'begun', 
                    'SUM(`survey_unit_sessions`.ended IS NOT NULL)' => 'finished', 
                    'SUM(`survey_unit_sessions`.expired IS NOT NULL)' => 'expired'
                 ))
                ->from('survey_unit_sessions')
                ->leftJoin('survey_run_sessions', 'survey_run_sessions.id = survey_unit_sessions.run_session_id')
                ->where('survey_unit_sessions.unit_id = :unit_id')
                ->where('survey_run_sessions.run_id = :run_id')
                ->bindParams(array('unit_id' => $this->id, 'run_id' => $this->run->id))
                ->fetch();

        return $reached;
    }

    public function displayUnitSessionsCount() {
        $reached = $this->getUnitSessionsCount();
        if (!$reached['begun']) {
            $reached['begun'] = "";
        }
        if (!$reached['finished']) {
            $reached['finished'] = "";
        }
        if (!$reached['expired']) {
            $reached['expired'] = "";
        }
        return "
			<span class='hastooltip badge badge-info' title='Number of unfinished sessions'>" . $reached['begun'] . "</span>
			<span class='hastooltip badge' title='Number of expired sessions'>" . $reached['expired'] . "</span>
            <span class='hastooltip badge badge-success' title='Number of finished sessions'>" . $reached['finished'] . "</span>
		";
    }

    public function runDialog($dialog) {
        $tpl = $this->getTemplatePath('unit');
        return Template::get($tpl, array('dialog' => $dialog, 'unit' => $this));
    }

    public function displayForRun($prepend = '') {
        return $this->runDialog($prepend); // FIXME: This class has no parent
    }

    public function delete($special = null) {
        // todo: set run modified
        if ($special !== null) {
            return $this->db->delete('survey_run_special_units', array('id' => $this->run_unit_id, 'type' => $special));
        } else {
            $affected = $this->db->delete('survey_units', array('id' => $this->id));
            if ($affected) { // remove from all runs
                $affected += $this->db->delete('survey_run_units', array('unit_id' => $this->id));
            }
        }
        return $affected;
    }

    /**
     * Find a unit using the id of the survey_unit
     * 
     * @param int $id
     * @param string $special
     * @param array $props
     * 
     * @return boolean|RunUnit
     */
    public function find($id, $special = false, $props = []) {
        $params = array('run_id' => $this->run->id, 'id' => $id);
        
        if (!$special) {
            $select = $this->db->select('
				`survey_run_units`.id AS run_unit_id,
				`survey_run_units`.run_id,
				`survey_run_units`.unit_id,
				`survey_run_units`.position,
				`survey_run_units`.description,
				`survey_units`.type,
				`survey_units`.created,
				`survey_units`.modified')
                            ->from('survey_run_units')
                            ->leftJoin('survey_units', 'survey_units.id = survey_run_units.unit_id')
                            ->where('survey_run_units.run_id = :run_id')
                            ->where('survey_units.id = :id');
            
            if (!empty($props['run_unit_id'])) {
                $select->where('survey_run_units.id = :run_unit_id');
                $params['run_unit_id'] = $props['run_unit_id'];
            }
            
            $unit = $select->bindParams($params)->limit(1)->fetch();
            
        } else {
            $specials = array('ServiceMessagePage', 'OverviewScriptPage', 'ReminderEmail');
            if (!in_array($special, $specials)) {
                die("Special unit not allowed");
            }

            $select = $this->db->select("
				`survey_run_special_units`.`id` AS run_unit_id,
				`survey_run_special_units`.`run_id`,
				`survey_run_special_units`.`description`,
				`survey_units`.id,
				`survey_units`.type,
				`survey_units`.created,
				`survey_units`.modified")
                            ->from('survey_run_special_units')
                            ->leftJoin('survey_units', "survey_units.id = `survey_run_special_units`.`id`")
                            ->where('survey_run_special_units.run_id = :run_id')
                            ->where("`survey_run_special_units`.`id` = :id");
            
            if (!empty($props['run_unit_id'])) {
                $select->where('survey_run_special_units.id = :run_unit_id');
                $params['run_unit_id'] = $props['run_unit_id'];
            }
            
            $unit = $select->bindParams($params)->limit(1)->fetch();
            $unit["special"] = $special;
        }

        if ($unit === false) { // or maybe we've got a problem
            if ($this->run->testingStudy && $this->unit_id) {
                return $this;
            }
            alert("Missing unit! $id", 'alert-danger');
            return false;
        }


        $unit['valid'] = true;
        
        $this->assignProperties($unit);
        
        return $this;
    }
    
    public function load() {
        return $this->find($this->id, $this->special);
    }


    /**
     * Get Run unit using run_unit_id
     * 
     * @param int $id
     * @param array $params
     * @return RunUnit
     */
    public static function findByRunUnitId($id, $params = []) {
        $row = DB::getInstance()->findRow('survey_run_units', ['id' => $id]);
        if ($row) {
            $run = new Run(DB::getInstance(), null, $row['run_id']);
            $params = array_merge($params, ['id' => $row['unit_id']]);
            return RunUnitFactory::make($run, $params);
        }
    }
    
    protected function knittingNeeded($source) {
        if (mb_strpos($source, '`r ') !== false OR mb_strpos($source, '```{r') !== false) {
            return true;
        }
        return false;
    }
    
    public function exec() {
        return null;
    }
}
