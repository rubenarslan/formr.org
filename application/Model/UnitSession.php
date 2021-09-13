<?php

class UnitSession extends Model {

    public $id;
    public $unit_id; // survey_units.id
    public $run_session_id;
    public $created;
    public $expires;
    public $queued = 0;
    public $result;
    public $result_log;
    public $ended;
    public $expired;
    public $meta;
    public $queueable = 1;

    public $pending = true;

    /**
     * @var RunSession
     */
    public $runSession;
    /**
     * @var RunUnit
     */
    public $runUnit;

    /**
     * A UnitSession needs a RunUnit to operate and belongs to a RunSession
     *
     * @param RunSession $runSession
     * @param RunUnit $runUnit
     * @param array $options An array of other options used to fetch a unit ID
     */
    public function __construct(RunSession $runSession, RunUnit $runUnit, $options = []) {
        parent::__construct();
        
        $this->runSession = $runSession;
        $this->runUnit = $runUnit;
        $this->assignProperties($options);
        if (isset($options['id'], $options['load'])) {
            $this->load();
        }
    }

    public function create($new_current_unit = true) {
        // only one can be current unit session at all times
        try {
            $this->db->beginTransaction();
            $session = $this->assignProperties([
                'unit_id' => $this->runUnit->id,
                'run_session_id' => $this->runSession->id,
                'created' => mysql_now(),
            ]);
            $this->id = $this->db->insert('survey_unit_sessions', $session);

            if ($this->run_session_id !== null && $new_current_unit) {
                $this->runSession->currentUnitSession = $this;
                $this->db->update('survey_run_sessions', ['current_unit_session_id' => $this->id], ['id' => $this->runSession->id]);

                $this->db->update('survey_unit_sessions', ['queued' => -9], [
                    'run_session_id' => $this->runSession->id,
                    'id <>' => $this->id,
                    'queued > 0',
                ]);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
        }

        return $this->id;
    }

    public function load() {
        if ($this->id !== null) {
            $vars = $this->db->findRow('survey_unit_sessions', ['id' => $this->id], 'id, created, unit_id, run_session_id, ended');
        } else {
            $vars = $this->db->select('id, created, unit_id, run_session_id, ended')
                    ->from('survey_unit_sessions')
                    ->where(['run_session_id' => $this->run_session_id, 'unit_id' => $this->unit_id])
                    ->where('ended IS NULL AND expired IS NULL')
                    ->order('created', 'desc')->limit(1)
                    ->fetch();
        }
        
        $this->assignProperties($vars);
    }

    public function __sleep() {
        return array('id', 'session', 'unit_id', 'created');
    }
    
    public function exec() {
        // Check if session has expired by getting relevant unit data
        
        // Gather data needed for computation from unit 
        
        // etc..
        
        return ['output' => [
            'title' => 'Test UnitSession',
            'body' => 'Unit Session Body',
        ]];
    }

}
