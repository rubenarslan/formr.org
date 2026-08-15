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
    public $export_attribs = array('type', 'description', 'position', 'special', 'address', 'api_end', 'expire_after');

    public function __construct(Run $run, array $props = []) {
        parent::__construct($run, $props);

        if (isset($props['external_link'])) {
            $this->address = $props['external_link'];
        }
        
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
        // v1.8.0: unlink the placement, do NOT delete the unit definition.
        //
        // This override used to be `return $this->delete($special)`, and
        // delete() drops the survey_units row — which
        // survey_unit_sessions.unit_id references ON DELETE CASCADE. So
        // removing one of these units from a run silently deleted every
        // participant's unit session for it: not a strand, an erasure of the
        // history an admin was still looking at. Six of the nine unit types
        // did this (Page/Endpage, Pause, External, Branch, SkipForward,
        // SkipBackward, Shuffle); the three that use the base removeFromRun
        // (Survey, Email, PushMessage) only ever unlinked, which is why the
        // difference went unnoticed.
        //
        // The base ends live sessions at the placement first (audit F22/F7)
        // and then deletes only the survey_run_units row. An orphaned unit
        // definition is the price, and the admin UI already has a path for
        // those ("Just delete orphan units", AdminAjaxController).
        return parent::removeFromRun($special);
    }

    private function isR($address) {
        if (substr((string)$address, 0, 4) == "http") {
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
                $error = (string) opencpu_last_error();
                $data['log'] = $this->getLogMessage('error_opencpu', "OpenCPU error. Fix R code. \n\n" . $error);
                $data['wait_opencpu'] = true; // don't go anywhere, wait for the error to be fixed!
                // v1.8.0: re-arm so the row is retried. wait_opencpu alone left
                // it inert at queued=0 — and this unit type is the one case the
                // stalled-session sweep deliberately skips (its docblock
                // reasons that an External waits for a callback or for the
                // participant's browser, which is true of api_end and address
                // externals but NOT of one whose R address failed to evaluate:
                // that waits for neither). So the participant parked here
                // forever unless the run set expire_after, and the R
                // side-effect — an SMS, an API call — silently never happened.
                $data['queue'] = [
                    'expires' => mysql_datetime(strtotime(Config::get('unit_session.queue_expiration_extension', '+10 minutes'))),
                    'queued' => UnitSessionQueue::QUEUED_TO_EXECUTE,
                ];

                notify_study_admin($unitSession, 'External unit: OpenCPU error in R address evaluation. ' . $error, 'error');
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
        } else {
            // v1.8.0: the daemon is only ever here because the OpenCPU-error
            // branch above armed QUEUED_TO_EXECUTE on a previous pass — and
            // the address has now evaluated. Nothing downstream clears that
            // arming: executeUnitSession() returns at its formr_in_console()
            // guard before it touches the queue, so the row would keep its
            // past-due `expires` and be re-executed on EVERY daemon pass,
            // re-firing whatever the address does (an SMS, an API POST) for
            // as long as the participant sits on this unit. Hand the queue
            // this unit's ordinary expiry verdict instead: expire_after
            // re-arms QUEUED_TO_END so abandonment stays bounded, and no
            // expire_after dequeues the row (addItem() removes on an empty
            // `expires`). Either way the participant still gets the redirect
            // on their next visit — a cron cascade deliberately does not
            // follow one (Audit F5).
            $data['queue'] = [
                'expires' => array_val($expired, 'expires', null),
                'queued' => array_val($expired, 'queued', UnitSessionQueue::QUEUED_NOT),
            ];
        }

        return $data;
    }
}
