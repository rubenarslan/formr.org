<?php

class Wait extends Pause {

    public $type = "Wait";
    
    public $icon = "fa-hourglass-half";

   public function __construct(Run $run, array $props = []) {
        parent::__construct($run, $props);
   }

    protected function getPreviousUnitSessionCreateDate() {
        if (empty($this->run_session->unit_session->id)) {
            return null;
        }

        $id = (int) $this->run_session->unit_session->id;
        $run_session_id = $this->run_session->id;
        
        $q = "SELECT id, created FROM survey_unit_sessions WHERE id < {$id} AND run_session_id = {$run_session_id} ORDER BY id DESC LIMIT 1";
        $result = $this->dbh->query($q, true)->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            return null;
        }

        return $result['created'];
    }

    protected function parseRelativeTo() {
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

    public function getUnitSessionOutput(UnitSession $unitSession) {
        $output = [];
        $expiration = $this->getUnitSessionExpirationData($unitSession);

        if (empty($expiration['expired']) && !$unitSession->isExecutedByCron() && empty($expiration['check_failed'])) {
            $output['end_session'] = true;
            $output['run_to'] = $this->body;
            $output['log'] = $this->getLogMessage('wait_ended_by_user');
        } elseif ($expiration['expired'] === true) {
            $output['end_session'] = true;
            $output['move_on'] = true;
            $output['log'] = $this->getLogMessage('wait_ended');
        } else {
            // maybe errors
            $output['wait_opencpu'] = true;
        }
        
        return $output;
    }
}

