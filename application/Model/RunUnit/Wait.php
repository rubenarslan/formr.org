<?php

class Wait extends Pause {

    public $type = "Wait";
    public $icon = "fa-hourglass-half";
    //protected $default_relative_to = 'FALSE';

    public function __construct(Run $run, array $props = []) {
        parent::__construct($run, $props);
    }

    protected function parseRelativeTo() {
        $this->relative_to = trim((string)$this->relative_to);
        $this->wait_minutes = trim((string)$this->wait_minutes);
        $this->has_wait_minutes = !($this->wait_minutes === null || $this->wait_minutes == '');
        $this->has_relative_to = !($this->relative_to === null || $this->relative_to == '' || !$this->relative_to);

        // disambiguate what user meant
        if ($this->has_wait_minutes && !$this->has_relative_to) {
            // If user specified waiting minutes but did not specify relative to which timestamp,
            // we imply we are waiting relative to when the user arrived at the previous unit
            $this->relative_to = $this->default_relative_to;
            $this->has_relative_to = true;
        }
		
		return $this->has_relative_to;
    }

    protected function setDefaultRelativeTo(UnitSession $unitSession = null) {
		//formr_log(!$this->has_relative_to && !$this->has_relative_to, 'setDefaultRelativeTo');
		
        if ($unitSession && $this->has_wait_minutes && !$this->has_relative_to) {
            // Get previous unit session creation date
            $q = "SELECT id, created FROM survey_unit_sessions WHERE id < {$unitSession->id} AND run_session_id = {$unitSession->runSession->id} ORDER BY id DESC LIMIT 1";
            $result = $this->db->query($q, true)->fetch(PDO::FETCH_ASSOC);
			
            if ($result) {
                $this->default_relative_to = json_encode($result['created']);
                return;
            }
        }
    }

    public function getUnitSessionExpirationData(UnitSession $unitSession) {
        $this->setDefaultRelativeTo($unitSession);
        return parent::getUnitSessionExpirationData($unitSession);
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        $output = [];
        $expiration = $this->getUnitSessionExpirationData($unitSession);
        $output['wait_opencpu'] = !empty($expiration['check_failed']);
		
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
            $output['wait_user'] = true;
        }

        return $output;
    }

}
