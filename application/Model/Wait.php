<?php

class Wait extends Pause {

    public $type = "Wait";
    public $icon = "fa-spinner";

    public function __construct($fdb, $session = null, $unit = null, $run_session = null, $run = null) {
        parent::__construct($fdb, $session, $unit, $run_session, $run);
    }

    protected function getPreviousUnitSessionCreateDate() {
        if (empty($this->run_session->unit_session->id)) {
            return null;
        }

        $id = (int) $this->run_session->unit_session->id;
        $q = "SELECT id, unit_id, created, ended FROM survey_unit_sessions WHERE id < {$id} ORDER BY id DESC LIMIT 1";
        $result = $this->dbh->query($q, true)->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            return null;
        }

        return $result['created'];
    }

    protected function checkRelativeTo() {
        $this->relative_to = trim($this->relative_to);
        $this->wait_minutes = trim($this->wait_minutes);
        $this->has_wait_minutes = !($this->wait_minutes === null || $this->wait_minutes == '');
        $this->has_relative_to = !($this->relative_to === null || $this->relative_to == '');

        // disambiguate what user meant
        if ($this->has_wait_minutes && !$this->has_relative_to) {
            // If user specified waiting minutes but did not specify relative to which timestamp,
            // we imply we are waiting relative to when the user arrived at the previous unit
            $relative_to = $this->getPreviousUnitSessionCreateDate();
            $this->relative_to = $relative_to ? json_encode($relative_to) : 'FALSE';
            $this->has_relative_to = true;
        }

        return $this->has_relative_to;
    }
}

