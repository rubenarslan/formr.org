<?php

class Pause extends RunUnit {

    public $type = "Pause";
    
    public $icon = "fa-pause";

    protected $body = '';
    protected $body_parsed = '';
    protected $relative_to = null;
    protected $wait_minutes = null;
    protected $wait_until_time = null;
    protected $wait_until_date = null;
    
    // @TODO maybe remove
    protected $has_relative_to = false;
    protected $has_wait_minutes = false;
    protected $relative_to_result = null;
    protected $default_relative_to = 'tail(survey_unit_sessions$created,1)';

    /**
     * An array of unit's exportable attributes
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special', 'wait_until_time', 'wait_until_date', 'wait_minutes', 'relative_to', 'body');

    public function __construct(Run $run, array $props = []) {
        parent::__construct($run, $props);

        if ($this->id) {
            $cols = 'id, body, body_parsed, wait_until_time, wait_minutes, wait_until_date, relative_to';
            $vars = $this->db->findRow('survey_pauses', ['id' => $this->id], $cols);
            if ($vars) {
                array_walk($vars, "emptyNull");
                $vars['valid'] = true;
                $this->assignProperties($vars);
            }
        }
    }

    public function create($options = []) {
        $this->db->beginTransaction();
        
        parent::create($options);

        if (isset($options['body'])) {
            array_walk($options, "emptyNull");
            $this->assignProperties($options);
        }

        $parsedown = new ParsedownExtra();
        $parsedown->setBreaksEnabled(true);

        if (!knitting_needed($this->body) && !empty($this->body)) {
            $this->body_parsed = $parsedown->text($this->body); // transform upon insertion into db instead of at runtime
        }

        $this->db->insert_update('survey_pauses', array(
            'id' => $this->id,
            'body' => $this->body,
            'body_parsed' => $this->body_parsed,
            'wait_until_time' => $this->wait_until_time,
            'wait_until_date' => $this->wait_until_date,
            'wait_minutes' => $this->wait_minutes,
            'relative_to' => $this->relative_to,
        ));
        
        $this->db->commit();
        $this->valid = true;

        return $this;
    }

    public function displayForRun($prepend = '') {
        $dialog = Template::get($this->getTemplatePath(), array(
            'prepend' => $prepend,
            'wait_until_time' => $this->wait_until_time,
            'wait_until_date' => $this->wait_until_date,
            'wait_minutes' => $this->wait_minutes,
            'relative_to' => $this->relative_to,
            'body' => $this->body,
            'type' => $this->type,
        ));

        return parent::runDialog($dialog);
    }

    public function removeFromRun($special = null) {
        return $this->delete($special);
    }

    protected function parseRelativeTo() {
        $this->relative_to = trim((string) $this->relative_to);
        $this->wait_minutes = trim((string) $this->wait_minutes);
        $this->has_wait_minutes = !($this->wait_minutes === null || $this->wait_minutes == '');
        $this->has_relative_to = !($this->relative_to === null || $this->relative_to == '');

        // disambiguate what user meant
        if ($this->has_wait_minutes && !$this->has_relative_to) {
            // If user specified waiting minutes but did not specify relative to which timestamp,
            // we imply we are waiting relative to when the user arrived at the pause
            $this->relative_to = $this->default_relative_to;
            $this->has_relative_to = true;
        }

        return $this->has_relative_to;
    }

    public function getUnitSessionExpirationData(UnitSession $unitSession) {
        $this->parseRelativeTo();
        $data = [
            'check_failed' => false,
            'expire_relatively' => null,
            'expired' => false,
            'queued' => UnitSessionQueue::QUEUED_TO_END,
        ];
      
        if ($unitSession->expires && ($timestamp = strtotime($unitSession->expires)) > time()) {
            // Pause has not expired, no need to fetch new data about it
            $data['expires'] = $timestamp;
            return $data;
        }

        // if a relative_to has been defined by user or automatically, we need to retrieve its value
        if ($this->has_relative_to) {
            if($this->relative_to === 'tail(survey_unit_sessions$created,1)' && $unitSession->created) {
                $result = $unitSession->created;
            } else {
                $opencpu_vars = $unitSession->getRunData($this->relative_to);
				$result = opencpu_evaluate($this->relative_to, $opencpu_vars, 'json');
                if ($result === null) {
                    $data['check_failed'] = true;
                    $data['log'] = $this->getLogMessage('error_pause_relative_to', 'OpenCPU R error. Fix code.');
                    $this->errors[] = 'Could not evaluate relative_to value on opencpu';
                    return $data;
                }
            }
            $this->relative_to_result = $relative_to = $result;
        }

        $bind_relative_to = false;
        $conditions = array();

        if (!$this->has_wait_minutes && $this->has_relative_to) {
            // if no wait minutes but a relative to was defined, we just use this as the param (useful for complex R expressions)
            if ($relative_to === true) {
                $conditions['relative_to'] = '1=1';
                $data['expire_relatively'] = true;
            } elseif ($relative_to === false) {
                $conditions['relative_to'] = '0=1';
                $data['expire_relatively'] = false;
            } elseif (!is_array($relative_to) && strtotime($relative_to)) {
                $conditions['relative_to'] = ':relative_to <= NOW()';
                $bind_relative_to = true;
                $data['expires'] = strtotime($relative_to);
                // If there was a wait_time, set the timestamp to have this time
                if ($time = $this->parseWaitTime(true)) {
                    $ts = $data['expires'];
                    $data['expires'] = mktime((int)$time[0], (int)$time[1], 0, (int)date('m', $ts), (int)date('d', $ts), (int)date('Y', $ts));
                    $relative_to = date('Y-m-d H:i:s', $data['expires']);
                }
            } else {
                $this->errors[] = "Pause {$this->position}: Relative to yields neither true nor false, nor a date, nor a time. " . print_r($relative_to, true);
                $data['check_failed'] = true;
                $data['log'] = $this->getLogMessage('error_pause_relative_to', 'OpenCPU R error. Fix code.');
                return $data;
            }
        } elseif ($this->has_wait_minutes) {
            if (!is_array($relative_to) && strtotime($relative_to)) {
                $conditions['minute'] = "DATE_ADD(:relative_to, INTERVAL :wait_seconds SECOND) <= NOW()";
                $bind_relative_to = true;
                $data['expires'] = strtotime($relative_to) + ($this->wait_minutes * 60);
            } else {
                $this->errors[] = "Pause {$this->position}: Relative to yields neither a date, nor a time. " . print_r($relative_to, true);
                $data['check_failed'] = true;
                $data['log'] = $this->getLogMessage('error_pause_wait_minutes', 'Relative to yields neither a date, nor a time');
                return $data;
            }
        }

        if ($this->wait_until_date && $this->wait_until_date != '0000-00-00') {
            $wait_date = $this->wait_until_date;
        }

        if ($this->wait_until_time && $this->wait_until_time != '00:00:00') {
            $wait_time = $this->wait_until_time;
        }

        $wait_date_defined = $this->wait_until_date && $this->wait_until_date != '0000-00-00';
        $wait_time_defined = $this->wait_until_time && $this->wait_until_time != '00:00:00';
        $wait_date = $this->parseWaitDate();
        $wait_time = $this->parseWaitTime();

        if (!empty($wait_date) && empty($wait_time)) {
            $wait_time = '00:00:01';
        }

        if (!empty($wait_time) && empty($wait_date)) {
            $wait_date = date('Y-m-d');
        }

        if (!empty($wait_date) && !empty($wait_time) && empty($data['expires'])) {
            $wait_datetime = $wait_date . ' ' . $wait_time;
            $data['expires'] = strtotime($wait_datetime);

            // If the expiration hour already passed before the user entered the pause, set expiration to the next day (in 24 hours)
            $exp_ts = $data['expires'];
            $created_ts = strtotime($unitSession->created);
            $exp_hour_min = mktime(date('G', $exp_ts), date('i', $exp_ts), 0);
            if ($created_ts > $exp_hour_min && !$wait_date_defined) {
                $data['expires'] += 24 * 60 * 60;
                return $data;
            }

            $conditions['datetime'] = ':wait_datetime <= NOW()';
        }

        $now = time();
        $result = !empty($data['expires']) && ($data['expires'] <= $now);

        if ($conditions) {
            $condition = implode(' AND ', $conditions);
            $stmt = $this->db->prepare("SELECT {$condition} AS test LIMIT 1");
            if ($bind_relative_to) {
                $stmt->bindValue(':relative_to', $relative_to);
            }
            if (isset($conditions['minute'])) {
                $stmt->bindValue(':wait_seconds', floatval($this->wait_minutes) * 60);
            }
            if (isset($conditions['datetime'])) {
                $stmt->bindValue(':wait_datetime', $wait_datetime);
            }

            $stmt->execute();
            if ($stmt->rowCount() === 1 && ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
                $result = (bool) $row['test'];
            }
        } else {
            $result = true;
        }

        $data['end_session'] = $data['expired'] = $result;
        
        return $data;
    }

    protected function parseWaitTime($parts = false) {
        if ($this->wait_until_time && $this->wait_until_time != '00:00:00') {
            return $parts ? explode(':', $this->wait_until_time) : $this->wait_until_time;
        }

        return null;
    }

    protected function parseWaitDate($parts = false) {
        if ($this->wait_until_date && $this->wait_until_date != '0000-00-00') {
            return $parts ? explode('-', $this->wait_until_date) : $this->wait_until_date;
        }

        return null;
    }

    public function test() {
        $results = $this->getSampleSessions();
        if (!$results) {
            $this->noTestSession();
            return null;
        }

        // take the first sample session
        $unitSession = current($results);

        $output = "<h3>Pause message</h3>";
        $output .= $this->getParsedBody($this->body, $unitSession, ['admin' => true]);
        $this->setDefaultRelativeTo($unitSession);

        if ($this->parseRelativeTo()) {
            $output .= "<h3>Pause relative to</h3>";
            $opencpu_vars = $unitSession->getRunData($this->relative_to);
            $session = opencpu_evaluate($this->relative_to, $opencpu_vars, 'json', null, true);
            $output .= opencpu_debug($session);
        }

        if (!empty($results) && (empty($session) || !$session->hasError())) {

            $test_tpl = $output . '
				<table class="table table-striped">
					<thead>
						<tr>
							<th>Code</th>
							<th>Relative to</th>
							<th>Over</th>
						</tr>
						%{rows}
					</thead>
				</table>
			';

            $row_tpl = '
				<tr>
					<td style="word-wrap:break-word;max-width:150px"><small>%{session} (%{position})</small></td>
					<td><small>%{relative_to}</small></td>
					<td>%{pause_over}</td>
				<tr>
			';

            $rows = '';
            foreach ($results as $unitSession) {
                $expired = $this->getUnitSessionExpirationData($unitSession);
                $pause_over = !empty($expired['check_failed']) ? 'check_failed' : !empty($expired['expired']);
                $rows .= Template::replace($row_tpl, array(
                    'session' => $unitSession->runSession->session,
                    'position' => $unitSession->runSession->position,
                    'pause_over' => stringBool($pause_over),
                    'relative_to' => stringBool($this->relative_to_result),
                ));
            }

            return Template::replace($test_tpl, array('rows' => $rows));
        }
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        $body = $this->getParsedBody($this->body, $unitSession);
        if ($body === false) {
            // opencpu error
            $output['log'] = array_val($this->errors, 'log', []);
            $output['wait_opencpu'] = true; // wait for openCPU to be fixed!
            return $output;
        }

        return [
            'content' => $body, 
            'log' => $this->getLogMessage('pause_waiting')
        ];
    }
    
    protected function setDefaultRelativeTo(UnitSession $unitSession = null) {
        
    }

}
