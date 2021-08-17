<?php

class UnitSession {

    public $session = null;
    public $id;
    public $unit_id;
    public $created;
    public $ended;
    public $expired;
    public $queued;
    public $expires;
    public $result;
    public $result_log;
    public $run_session_id;

    /**
     * @var DB
     */
    private $dbh;

    public function __construct($fdb, $run_session_id, $unit_id, $unit_session_id = null, $load = true) {
        $this->dbh = $fdb;
        $this->unit_id = $unit_id;
        $this->run_session_id = $run_session_id;
        $this->id = $unit_session_id;
        if ($load === true) {
            $this->load();
        }
    }

    public function create($new_current_unit = TRUE) {
        // only one can be current unit session at all times
        $this->dbh->beginTransaction();
        $now = mysql_now();
        $this->id = $this->dbh->insert('survey_unit_sessions', array(
            'unit_id' => $this->unit_id,
            'run_session_id' => $this->run_session_id,
            'created' => $now
        ));
        if($this->run_session_id !== null && $new_current_unit) {
                $this->dbh->update('survey_run_sessions', 
                array("current_unit_session_id" => $this->id), 
                array("id" => $this->run_session_id));
            $this->dbh->update('survey_unit_sessions', 
                array("queued" => -9), 
                array("run_session_id" => $this->run_session_id,
                    "id <>" => $this->id,
                    "queued > " => 0));
        }
        $this->dbh->commit();
        $this->created = $now;
        return $this->id;
    }

    public function load() {
        if ($this->id !== null) {
            $vars = $this->dbh->select('id, created, unit_id, run_session_id, ended')
                    ->from('survey_unit_sessions')
                    ->where(array('id' => $this->id))
                    ->fetch();
        } else {
            $vars = $this->dbh->select('id, created, unit_id, run_session_id, ended')
                    ->from('survey_unit_sessions')
                    ->where(array('run_session_id' => $this->run_session_id, 'unit_id' => $this->unit_id))
                    ->where('ended IS NULL AND expired IS NULL')
                    ->order('created', 'desc')->limit(1)
                    ->fetch();
        }

        if (!$vars) {
            return;
        }

        foreach ($vars as $property => $value) {
            if (property_exists($this, $property)) {
                $this->{$property} = $value;
            }
        }
    }

    public function __sleep() {
        return array('id', 'session', 'unit_id', 'created');
    }

}
