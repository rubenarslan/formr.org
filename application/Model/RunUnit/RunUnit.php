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

    public $icon = "fa-question";

    public $surveyStudy;

    protected $body = '';
    
    protected $body_parsed = '';

    /**
     * 
     * @var Run $run
     */
    public $run = null;

    /**
     * An array of unit's exportable attributes
     * 
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special');

	/**
	 * 
	 * @var boolean If set to True unit would try to generate output again for a session
	 */
	protected $retryOutput = true;
	
	/**
     * 
     * @param Run $run
	 * @param array $props
     */
    public function __construct(Run $run, array $props = []) {
        parent::__construct();
        $this->run = $run;
        $this->assignProperties($props);
        
        if ($this->id && empty($props['importing'])) {
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
            $this->modify($props);
            if (!empty($props['add_to_run'])) {
                $this->addToRun();
            }
            
            return $this;
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

        if ($this->special && $this->run->id) {
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
        return $this->db->update(
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

    public function getTemplatePath($tpl = null) {
        $tpl = $tpl ?? strtolower($this->type);
        if ($tpl === 'page') {
            $tpl = 'endpage';
        }
        
        return 'admin/run/units/' . $tpl;
    }

    /**
     * Get random unit sessions that have passed this unit
     * 
     * @return \UnitSession[]
     */
    protected function getSampleSessions() {
        // Select a maximum of 20 random sessions that are on or have passed this unit
        $results = [];
        $rs = [];
        $rows = $this->db->select('session, id, position')
                        ->from('survey_run_sessions')
                        ->order('position', 'desc')->order('RAND')
                        ->where(array('run_id' => $this->run->id, 'position >=' => $this->position))
                        ->limit(20)->fetchAll();
        
        foreach ($rows as $row) {
            if (!isset($rs[$row['id']])) {
                $rs[$row['id']] = new RunSession($row['session'], $this->run, ['id' => $row['id']]);
            }

            $unitSession = (new UnitSession($rs[$row['id']], $this))->load();
            if ($unitSession->id) {
                $results[] = $unitSession;
            }
        }

        return $results;
    }

    /**
     * Get a random unit session that has past this unit
     * 
     * @return UnitSession|null
     */
    protected function grabRandomSession() {
        // Select a random run session that has past this unit's position
        $row = $this->db->select('session, id, position')
                    ->from('survey_run_sessions')
                    ->order('position', 'desc')->order('RAND')
                    ->where(['run_id' => $this->run->id, 'position >=' => $this->position])
                    ->limit(1)
                    ->fetch();
        if (!$row) {
            return null;
        }
        
        $runSession = new RunSession($row['session'], $this->run, ['id' => $row['id']]);
        $unitSession = (new UnitSession($runSession, $this))->load();
        if (!$unitSession->id) {
            return null;
        }

        return $unitSession;
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
            if ($this->run->isStudyTest() && $this->unit_id) {
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
            $run = new Run(null, $row['run_id']);
            if (!$row['unit_id'] && isset($params['ignore_missing'])) {
                $row['run_unit_id'] = $id;
                $row['id'] = null;
                return new RunUnit($run, $row);
            }

            $params = array_merge($params, ['id' => $row['unit_id']]);
            return RunUnitFactory::make($run, $params);
        }
    }
    
    public function exec() {
        return null;
    }
    
    public function getUnitSessionExpirationData(UnitSession $unitSession) {
        return [];
    }
    
    public function getUnitSessionOutput(UnitSession $unitSession) {
        return null;
    }
    
    public function getParsedBody($source = null, UnitSession $unitSession = null, $options = []) {
        $email_embed = array_val($options, 'email_embed');
        if (!knitting_needed($source)) {
            return $email_embed ? ['body' => $this->body_parsed, 'images' => []] : $this->body_parsed;
        }
        
        $admin = array_val($options, 'admin', false);
        $isCron = $this->isCron();
        $sessionId = $unitSession->id;

        /* @var $ocpu OpenCPU_Session */
        $ocpu = null;
        $cache_session = false;
        $baseUrl = null;

        if (!$admin) {
            $opencpu_url = $this->db->findValue('survey_reports', array(
                'unit_id' => $this->id,
                'session_id' => $sessionId,
                'created >=' => $this->modified // if the definition of the unit changed, don't use old reports
            ), array('opencpu_url'));

            // If there is a cache of opencpu, check if it still exists
            if ($opencpu_url && ($ocpu = opencpu_get($opencpu_url, '', null, true))) {
                if ($isCron) {
                    // don't regenerate once we once had a report for this feedback, if it's only the cronjob
                    return null;
                }

                $filesMatch = 'files/';
                $baseUrl = $opencpu_url;
            }
        }

        // If there no session or old session (from aquired url) has an error for some reason, then get a new one for current request
        if (empty($ocpu) || $ocpu->hasError()) {
            $ocpu_vars = $unitSession->getRunData($source);
            /* @var $ocpu OpenCPU_Session */
            if ($email_embed) {
                $ocpu = opencpu_knit_email($source, $ocpu_vars, '', true);
            } else {
                $ocpu = opencpu_knit_iframe($source, $ocpu_vars, true, null, $this->run->description, $this->run->footer_text);
            }

            $filesMatch = 'knit.html';
            $cache_session = true;
        }

        // At this stage we are sure to have an OpenCPU_Session in $ocpu. If there is an error in the session return FALSE
        if (empty($ocpu)) {
            $this->errors['log'] = $this->getLogMessage('error_opencpu_down', 'OpenCPU is probably down or inaccessible.');
            
            alert('OpenCPU is probably down or inaccessible. Please retry in a few minutes.', 'alert-danger');
            return false;
        } elseif ($ocpu->hasError()) {
            $this->errors['log'] = $this->getLogMessage('error_opencpu_r', 'OpenCPU R error. Fix code.' . $ocpu->getError());
            notify_user_error(opencpu_debug($ocpu), 'There was a computational error.');

            // @TODO: notify study admin
            return false;
        } elseif ($admin) {
            return opencpu_debug($ocpu);
        } else {
            $this->messages['log'] = $this->getLogMessage('success_knitted');
            
            print_hidden_opencpu_debug_message($ocpu, "OpenCPU debugger for run R code in {$this->type} at {$this->position}.");
            $files = $ocpu->getFiles($filesMatch, $baseUrl);
            $images = $ocpu->getFiles('/figure-html', $baseUrl);
            $opencpu_url = $ocpu->getLocation();

            if ($email_embed) {
                $report = array(
                    'body' => $ocpu->getObject(),
                    'images' => $images,
                );
            } else {
                $this->run->renderedDescAndFooterAlready = true;
                $iframesrc = $files['knit.html'];
                $report = '' .
                '<div class="rmarkdown_iframe">
					<iframe src="' . $iframesrc . '">
					  <p>Your browser does not support iframes.</p>
					</iframe>
				</div>';
            }

            if ($sessionId && $cache_session) {
                $set_report = $this->db->prepare(
                    "INSERT INTO `survey_reports` (`session_id`, `unit_id`, `opencpu_url`, `created`, `last_viewed`) 
					VALUES  (:session_id, :unit_id, :opencpu_url,  NOW(), 	NOW() ) 
					ON DUPLICATE KEY UPDATE opencpu_url = VALUES(opencpu_url), created = VALUES(created)"
                );

                $set_report->bindParam(":unit_id", $this->id);
                $set_report->bindParam(":opencpu_url", $opencpu_url);
                $set_report->bindParam(":session_id", $sessionId);
                $set_report->execute();
            }

            return $report;
        }
    }
    
    public function getParsedText($source, UnitSession $unitSession = null, $options = []) {
        $admin = array_val($options, 'admin', false);
        if (!knitting_needed($source) || $unitSession === null) {
            return $source;
        }
        
        $ocpu_vars = $unitSession->getRunData($source);
        if (!$admin) {
            return opencpu_knit_plaintext($source, $ocpu_vars, false);
        } else {
            return opencpu_debug(opencpu_knit_plaintext($source, $ocpu_vars, true));
        }
    }

    public function getLogMessage($result, $result_log = null) {
        return compact('result', 'result_log');
    }

    protected function getTestSession($source) {
        if (!knitting_needed($source)) {
            return null;
        }
        
        if (!($session = $this->grabRandomSession())) {
            $this->noTestSession();
            return false;
        }
        
        return $session;
    }
    
    protected function noTestSession() {
        return alert('No data to compare to yet. Create some test data by sending guinea pigs through the run using the "Test run" function on the left.', 'alert-info');
    }
}
