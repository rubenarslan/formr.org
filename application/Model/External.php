<?php

class External extends RunUnit {

    public $errors = array();
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

    public function __construct($fdb, $session = null, $unit = null, $run_session = NULL, $run = NULL) {
        parent::__construct($fdb, $session, $unit, $run_session, $run);

        if ($this->id):
            $vars = $this->dbh->findRow('survey_externals', array('id' => $this->id), 'id, address, api_end, expire_after');
            if ($vars):
                $this->address = $vars['address'];
                $this->api_end = $vars['api_end'] ? 1 : 0;
                $this->expire_after = (int) $vars['expire_after'];
                $this->valid = true;
            endif;
        endif;
    }

    public function create($options) {
        $this->dbh->beginTransaction();
        if (!$this->id) {
            $this->id = parent::create('External');
        } else {
            $this->modify($options);
        }

        if (isset($options['external_link'])) {
            $this->address = $options['external_link'];
            $this->api_end = $options['api_end'] ? 1 : 0;
            $this->expire_after = (int) $options['expire_after'];
        }

        $this->dbh->insert_update('survey_externals', array(
            'id' => $this->id,
            'address' => $this->address,
            'api_end' => $this->api_end,
            'expire_after' => $this->expire_after,
        ));
        $this->dbh->commit();
        $this->valid = true;

        return true;
    }

    public function displayForRun($prepend = '') {
        $dialog = Template::get($this->getUnitTemplatePath(), array(
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

    private function makeAddress($address) {
        $login_link = run_url($this->run_name, null, array('code' => $this->session));
        $address = str_replace("{{login_link}}", $login_link, $address);
        $address = str_replace("{{login_code}}", $this->session, $address);
        return $address;
    }

    public function test() {
        if ($this->isR($this->address)) {
            if ($results = $this->getSampleSessions()) {
                if (!$results) {
                    return false;
                }

                $this->run_session_id = current($results)['id'];

                $opencpu_vars = $this->getUserDataInRun($this->address);
                $ocpu_session = opencpu_evaluate($this->address, $opencpu_vars, '', null, true);
                $output = opencpu_debug($ocpu_session, null, 'text');
            } else {
                $output = '';
            }
        } else {
            $output = Template::replace('<a href="%{address}">%{address}</a>', array('address' => $this->address));
        }

        $this->session = "TESTCODE";
        return $this->makeAddress($output);
    }

    private function hasExpired() {
        $expire = (int) $this->expire_after;
        if ($expire === 0) {
            return false;
        } else {
            $last = $this->run_session->unit_session->created;
            if (!$last || !strtotime($last)) {
                return false;
            }

            $expire_ts = strtotime($last) + ($expire * 60);
            if (($expired = $expire_ts < time())) {
                return true;
            } else {
                $this->execData['expire_timestamp'] = $expire_ts;
                return false;
            }
        }
    }

    public function exec() {
        // never redirect, if we're just in the cronjob. just text for expiry
        $expired = $this->hasExpired();
        if ($this->called_by_cron) {
            if ($expired) {
                $this->expire();
                return false;
            }
        }

        // if it's the user, redirect them or do the call
        if ($this->isR($this->address)) {
            $goto = null;
            $opencpu_vars = $this->getUserDataInRun($this->address);
            $result = opencpu_evaluate($this->address, $opencpu_vars);

            if ($result === null) {
                $this->session_result = "error_opencpu";
                $this->logResult();
                return true; // don't go anywhere, wait for the error to be fixed!
            } elseif ($result === false) {
                $this->session_result = "external_r_call_no_redirect";
                $this->end();
                return false; // go on, no redirect
            } elseif ($this->isAddress($result)) {
                $this->session_result = "external_r_redirect";
                $this->logResult();
                $goto = $result;
            }
        } else { // the simplest case, just an address
            $this->session_result = "external_redirect";
            $this->logResult();
            $goto = $this->address;
        }

        // replace the code placeholder, if any
        $goto = $this->makeAddress($goto);

        // never redirect if we're just in the cron job
        if (!$this->called_by_cron) {
            // sometimes we aren't able to control the other end
            if (!$this->api_end) {
                $this->end();
                $this->run_session->execute();
            } else {
                $this->session_result = "external_wait_for_api";
                $this->logResult();
            }

            redirect_to($goto);
            return false;
        }
        return true;
    }
}
