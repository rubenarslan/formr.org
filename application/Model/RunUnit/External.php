<?php

class External extends RunUnit {

    public $id = null;
    public $session = null;
    public $unit = null;
    protected $address = null;
    protected $api_end = 0;
    protected $expire_after = 0;
    public $icon = "fa-external-link-square";
    public $type = "External";

    /**
     * An array of unit's exportable attributes
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special', 'address', 'api_end');

    public function __construct(Run $run, array $props = []) {
        parent::__construct($run, $props);

        if ($this->id) {
            $vars = $this->db->findRow('survey_externals', array('id' => $this->id), 'id, address, api_end, expire_after');
            if ($vars) {
                $this->address = $vars['address'];
                $this->api_end = $vars['api_end'] ? 1 : 0;
                $this->expire_after = (int) $vars['expire_after'];
                $this->valid = true;
            }
        }
    }

    public function create($options = []) {
        $this->db->beginTransaction();
        parent::create($options);

        if (isset($options['external_link'])) {
            $this->address = $options['external_link'];
            $this->api_end = $options['api_end'] ? 1 : 0;
            $this->expire_after = (int) $options['expire_after'];
        }

        $this->db->insert_update('survey_externals', array(
            'id' => $this->id,
            'address' => $this->address,
            'api_end' => $this->api_end,
            'expire_after' => $this->expire_after,
        ));
        $this->db->commit();
        $this->valid = true;

        return $this;
    }

    public function displayForRun($prepend = '') {
        $dialog = Template::get($this->getTemplatePath(), array(
            'prepend' => $prepend,
            'address' => $this->address,
            'expire_after' => $this->expire_after,
            'api_end' => $this->api_end,
        ));

        return parent::runDialog($dialog);
    }

    public function removeFromRun($special = null) {
        return $this->delete($special);
    }

    private function isR($address) {
        if (substr($address, 0, 4) == "http") {
            return false;
        }
        return true;
    }

    private function isAddress($address) {
        return !$this->isR($address);
    }

    public function test() {
        $unitSession = $this->grabRandomSession();
        if ($this->isR($this->address)) {
            if (!$unitSession) {
                $this->noTestSession();
                return;
            }
            
            $opencpu_vars = $unitSession->getRunData($this->address);
            $ocpu_session = opencpu_evaluate($this->address, $opencpu_vars, '', null, true);
            $output = opencpu_debug($ocpu_session, null, 'text');
        } else {
            $output = Template::replace('<a href="%{address}">%{address}</a>', ['address' => $this->address]);
        }
        
        $run_name = $session = null;
        if (!empty($unitSession)) {
            $run_name = $unitSession->runSession->getRun()->name;
            $session = $unitSession->runSession->session;
        }

        return do_run_shortcodes($output, $run_name, $session);
     }

    public function getUnitSessionExpirationData(UnitSession $unitSession) {
        $data = [];
        $expire = (int) $this->expire_after;
        if ($expire) {
            $last = $unitSession->created;
            if (!$last || !strtotime($last)) {
                return $data;
            }

            $expire_ts = strtotime($last) + ($expire * 60);
            if (($expired = $expire_ts < time())) {
                $data['expired'] = true;
            } else {
                $data['expires'] = $expire_ts;
                $data['queued'] = UnitSessionQueue::QUEUED_TO_END;
            }
        }
        
        return $data;
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        $data = [];
        $expired = $this->getUnitSessionExpirationData($unitSession);
        if ($unitSession->isExecutedByCron()) {
            if (!empty($expired['expired'])) {
                $data['expired'] = true;
                return $expired;
            }
        }

        // if it's the user, redirect them or do the call
        if ($this->isR($this->address)) {
            $opencpu_vars = $unitSession->getRunData($this->address);
            $result = opencpu_evaluate($this->address, $opencpu_vars);

            if ($result === null) {
                $data['log'] = $this->getLogMessage('error_opencpu');
                $data['wait_opencpu'] = true; // don't go anywhere, wait for the error to be fixed!
                return $data;
            } elseif ($result === false) {
                $data['log'] = $this->getLogMessage('external_r_call_no_redirect');
                $data['end_session'] = true;
                $data['move_on'] = true; // go on, no redirect
                return $data;
            } elseif ($this->isAddress($result)) {
                $data['log'] = $this->getLogMessage('external_r_redirect');
                $data['redirect'] = $result;
            } else {
                $data['log'] = $this->getLogMessage('external_compute', $result);
                $data['end_session'] = true;
                $data['move_on'] = true;
                return $data;
            }
        } else { // the simplest case, just an address
            $data['log'] = $this->getLogMessage('external_redirect');
            $data['redirect'] = $this->address;
        }
        
        $data['redirect'] = do_run_shortcodes($data['redirect'], $unitSession->runSession->getRun()->name, $unitSession->runSession->session);

        // never redirect if we're just in the cron job
        if (!$unitSession->isExecutedByCron()) {
            // sometimes we aren't able to control the other end
            if (!$this->api_end) {
                $data['end_session'] = $data['move_on'] = true;
            } else {
                $data['log'] = $this->getLogMessage('external_wait_for_api');
            }
        }
        
        return $data;
    }
}
