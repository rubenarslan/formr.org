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

    /**
     * Describe the request driving this execution, for the audit trail
     * (v1.8.0).
     *
     * The "participant came back early" branch fires for ANY non-cron
     * execution, and this line records which shape of request that was.
     * Browsers set Sec-Fetch-Dest/Mode themselves and scripts cannot forge
     * them (they are forbidden header names), so `document`/`navigate`
     * means a real navigation, while `empty`/`cors` means something
     * fetched the run URL programmatically — which is how a service worker
     * precaching start_url used to advance parked waits (fixed in v1.8.0
     * by not precaching it). If an unattended executor ever reappears,
     * this column names it instead of leaving us to re-derive the chain.
     *
     * Deliberately diagnostic only: nothing branches on the result. The
     * headers are absent on older browsers and on non-browser clients, so
     * a policy built on them could only ever fail open — which is why the
     * request shape is logged rather than enforced.
     *
     * The values are attacker-controllable request headers and result_log
     * is rendered by admin templates that do not auto-escape, so they are
     * whitelisted down to the token characters the spec allows rather than
     * interpolated raw.
     */
    /**
     * Reduce a Sec-Fetch-* header to the token characters the spec allows.
     *
     * Separate from describeRequestShape() so the safety property is
     * testable: that method short-circuits on formr_in_console(), which is
     * always true in the CLI test lane. These are request headers, i.e.
     * attacker-controlled, and they land in result_log, which admin
     * templates render without auto-escaping — so a raw value here would
     * be a participant-to-admin XSS sink.
     */
    protected function sanitizeFetchToken($raw) {
        $clean = preg_replace('/[^a-z-]/', '', strtolower((string) $raw));
        return substr((string) $clean, 0, 20);
    }

    protected function describeRequestShape() {
        if (formr_in_console()) {
            return 'driven by: console/cron';
        }
        $dest = $this->sanitizeFetchToken(isset($_SERVER['HTTP_SEC_FETCH_DEST']) ? $_SERVER['HTTP_SEC_FETCH_DEST'] : '');
        $mode = $this->sanitizeFetchToken(isset($_SERVER['HTTP_SEC_FETCH_MODE']) ? $_SERVER['HTTP_SEC_FETCH_MODE'] : '');
        if ($dest === '' && $mode === '') {
            return 'driven by: web request (no Sec-Fetch headers — older browser or non-browser client)';
        }
        return 'driven by: web request (Sec-Fetch-Dest=' . ($dest ?: '?') . ', Sec-Fetch-Mode=' . ($mode ?: '?') . ')';
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        $output = [];
        $expiration = $this->getUnitSessionExpirationData($unitSession);
        $output['wait_opencpu'] = !empty($expiration['check_failed']);
		
        if (empty($expiration['expired']) && !$unitSession->isExecutedByCron() && empty($expiration['check_failed'])) {
            $output['end_session'] = true;
            $output['run_to'] = $this->body;
            // Record WHAT kind of request advanced the wait, so the next
            // report of "it ended by itself" is answerable from the data
            // instead of by re-deriving the whole chain (v1.8.0).
            $output['log'] = $this->getLogMessage('wait_ended_by_user', $this->describeRequestShape());
            // v1.8.0: carry the reason into end(), which otherwise hardcodes
            // result='wait_ended' for this unit type and overwrites what the
            // log just recorded — so BOTH exits from this method read
            // 'wait_ended' in the results table, and a participant who came
            // back early became indistinguishable from a wait that simply
            // elapsed.
            //
            // The collapse arrived in v1.7.1: dropping the leaked `expired`
            // flag correctly routed an elapsed Wait through end() instead of
            // expire(), so the elapsed case stopped recording 'expired' and
            // started recording 'wait_ended' — the label this branch had
            // always produced. Reported from the field as "the participant
            // came back early condition fires by itself"; the tell that it
            // really is this branch is that the run jumps to `body` instead
            // of advancing to the next position.
            $output['end_reason'] = 'wait_ended_by_user';
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
