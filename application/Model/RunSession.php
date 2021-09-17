<?php

class RunSession extends Model {

    public $id;
    public $run_id;
    public $user_id;
    public $session = null;
    public $created;
    public $ended;
    public $last_access;
    public $position;
    public $current_unit_session_id;
    public $deactivated = 0;
    public $no_email;
    public $testing = 0;

    /**
     * 
     * @var Run
     */
    protected $run;

    /**
     * 
     * @var User
     */
    public $user;
    protected $table = 'survey_run_sessions';

    /**
     * Currently active unit session;
     *
     * @var UnitSession
     */
    public $currentUnitSession;

    /**
     * A RunSession should always be initiated with a Run and a User
     * since a RunSession should belong to a User and needs a Run
     * 
     * @param string $session The code of the user executing the run
     * @param Run $run
     * @param array $options Other options that could be used to initiate a RunSession
     */
    public function __construct($session, Run $run, $options = []) {
        parent::__construct();

        $this->session = $session;
        $this->run = $run;
        $this->assignProperties($options);

        if (($this->id || $this->session) && $this->run) {
            $this->load();
        }

        if (!$this->valid && !$run->testingStudy) {
            // Run session is not yet in database, create one for this run
        } elseif ($run->testingStudy) {
            // User is just testing the survey so we only need a dummy run session since data is not saved
            $this->id = -1;
            $this->testing = true;
            Site::getInstance()->setRunSession($this);
        }

        if (!$this->user) {
            $this->user = new User(null, $this->session);
        }
    }

    private function load() {
        $options = [];
        if ($this->id) {
            $options['id'] = (int) $this->id;
        } elseif ($this->session) {
            $options['session'] = $this->session;
            $options['run_id'] = $this->run->id;
        }

        if (!$options) {
            return;
        }

        $data = $this->db->findRow('survey_run_sessions', $options);
        if ($data) {
            $this->assignProperties($data);
            $this->valid = true;
            Site::getInstance()->setRunSession($this);
            return true;
        }

        return false;
    }

    public function getRun() {
        return $this->run;
    }

    public function getLastAccess() {
        return $this->db->findValue('survey_run_sessions', array('id' => $this->id), array('last_access'));
    }

    public function setLastAccess() {
        if (!$this->cron && (int) $this->id > 0) {
            $this->db->update('survey_run_sessions', array('last_access' => mysql_now()), array('id' => (int) $this->id));
        }
    }

    public function runAccessExpired() {
        if (!$this->run || !($last_access = $this->getLastAccess())) {
            return false;
        }

        if (($timestamp = strtotime($last_access)) && $this->run->expire_cookie) {
            return $timestamp + $this->run->expire_cookie < time();
        }

        return false;
    }

    public function create($session = null, $testing = 0) {
        if ($this->run->id === -1) {
            return false;
        }

        if ($session !== null) {
            if (strlen($session) != 64) {
                alert("<strong>Error.</strong> Session tokens need to be exactly 64 characters long.", 'alert-danger');
                return false;
            }
        } else {
            $session = crypto_token(48);
        }

        $this->db->insert_update('survey_run_sessions', array(
            'run_id' => $this->run->id,
            'user_id' => $this->user->id,
            'session' => $session,
            'created' => mysql_now(),
            'testing' => $testing
                ), array('user_id'));

        $this->session = $session;
        return $this->load();
    }

    /**
     * Create a new unit session for this run session
     *
     * @param RunUnit $unit
     * @param boolean $setAsCurrent
     * @param boolean $save Should unit session be saved on TV?
     * @return \RunSession
     */
    public function createUnitSession(RunUnit $unit, $setAsCurrent = true, $save = true) {
        formr_log("=======================================");
        formr_log("CREATE {$unit->type}", $this->id);
        $unitSession = new UnitSession($this, $unit);
        if ($save === false) {
            $this->currentUnitSession = $unitSession;
            return $this;
        }

        $this->currentUnitSession = $unitSession->create($setAsCurrent);
        return $this;
    }

    /**
     * Loop over units in Run for a session until you get a unit with output
     *
     * @param UnitSession $referenceUnitSession
     * @param boolean $executeReferenceUnit If TRUE, the first unit with session matching the $referenceUnitSession will be executed
     * @return mixed
     */
    public function execute(UnitSession $referenceUnitSession = null, $executeReferenceUnit = false) {
        if ($this->ended) {
            // User tried to access an already ended run session, logout
            return redirect_to(run_url($this->run->name, 'logout', ['prev' => $this->session]));
        }

        if ($this->run->testingStudy) {
            return $this->executeTest();
        }
        // Get the initial position if this run session hasn't executed before
        if ($this->position === null && !($position = $this->run->getFirstPosition())) {
            alert('This study has not been defined.', 'alert-danger');
            return false;
        }

        if (!$this->position) {
            $this->position = $position;
            $this->save();
        }

        $currentUnitSession = $this->getCurrentUnitSession();
        // If there is a referenceUnitSession then it is sent by the queue
        if ($referenceUnitSession && $currentUnitSession && $referenceUnitSession->id == $currentUnitSession->id && !$executeReferenceUnit) {
            $this->endCurrentUnitSession();
            return $this->moveOn();
        } elseif ($referenceUnitSession && $currentUnitSession && $referenceUnitSession->id != $currentUnitSession->id) {
            UnitSessionQueue::removeItem($referenceUnitSession->id);
            return $this->moveOn();
        }

        $unitSession = $currentUnitSession;

        formr_log('Current Unit Is ' . ($unitSession ? $unitSession->runUnit->type : ''), $this->id);
        if (!$unitSession && $this->position === $this->run->getFirstPosition()) {
            // We are in the first unit of the run
            return $this->moveOn(true);
        } elseif (!$unitSession) {
            // We maybe all previous unit sessions have ended so move on
            return $this->moveOn();
        } else {
            // Currently active unit session. Should most likey be a survey or pause
            $this->currentUnitSession = $unitSession;
        }

        return $this->executeUnitSession();
    }

    /**
     * Move on to the next unit of the Run
     * 
     * @param boolean $starting TRUE if we are in the first run unit. FALSE otherwise.
     * @param boolean $execute TRUE means we continue executing the next unit
     * @return type
     */
    public function moveOn($starting = false, $execute = true) {
        if (!$starting) {
            $this->position = $this->run->getNextPosition($this->position);
            if ($this->position !== null) {
                $this->save();
            }
        }

        if ($this->position && ($unit_id = $this->getUnitIdAtPosition($this->position))) {
            $runUnit = RunUnitFactory::make($this->run, ['id' => $unit_id]);
            $this->createUnitSession($runUnit);
            return $execute ? $this->execute() : null;
        }

        alert('Run ' . $this->run->name . ':<br /> Oops, this study\'s creator forgot to give it a proper ending (a Stop button), user ' . h($this->session) . ' is dangling at the end.', 'alert-danger');
        $this->end();
        return ['body' => ''];
    }

    protected function executeUnitSession() {
        formr_log("Execute {$this->currentUnitSession->runUnit->type}", $this->id);
        $result = $this->currentUnitSession->execute();
        formr_log($result, $this->id . $this->currentUnitSession->runUnit->type);

        if (!empty($result['end_session'])) {
            formr_log("END {$this->currentUnitSession->runUnit->type}", $this->id);
            $this->currentUnitSession->end();
        } elseif (!empty($result['expired'])) {
            formr_log("EXPIRE {$this->currentUnitSession->runUnit->type}", $this->id);
            $this->currentUnitSession->expire();
        } elseif (isset($result['queue'])) {
            formr_log($result['queue'], 'QUEUED');
            $this->currentUnitSession->queue();
            return ['body' => array_val($result, 'content')];
        }

        if (isset($result['wait_opencpu']) || isset($result['wait_user'])) {
            return ['body' => ''];
        }

        if (isset($result['redirect'])) {
            // move on in the run before redirecting to external service (except for surveys)
            if ($this->currentUnitSession->runUnit->type !== 'Survey') {
                $this->moveOn(false, false);
            }
            return $result;
        }

        if (isset($result['move_on'])) {
            return $this->moveOn();
        }

        if (isset($result['run_to'])) {
            return $this->runTo($result['run_to']);
        }

        if (isset($result['end_run_session'])) {
            $this->end();
        }
        
        if (isset($result['content'])) {
            return ['body' => $result['content']];
        } elseif (isset($result['move_on'])) {
            // @TODO end unit then move on
            return $this->moveOn();
        }

        // @TODO Check if run session should be ended here
    }

    public function getUnitIdAtPosition($position) {
        return $this->db->findValue('survey_run_units', [
                    'run_id' => $this->run->id,
                    'position' => $position],
                        'unit_id');
    }

    public function forceTo($position) {
        // If there a unit for current position, then end the unit's session before moving
        if (($unitSession = $this->getCurrentUnitSession())) {
            $unitSession->end();
            $unitSession->result = 'manual_admin_push';
            $unitSession->logResult();
        }
        return $this->runTo($position);
    }

    public function runTo($position, $unit_id = null) {
        if ($unit_id === null) {
            $unit_id = $this->getUnitIdAtPosition($position);
        }

        if ($unit_id) {
            $this->position = $position;
            $unit = RunUnitFactory::make($this->run, ['id' => $unit_id]);
            if ($unit->valid) {
                $this->createUnitSession($unit);
                $this->db->update('survey_run_sessions', ['position' => $position], ['id' => $this->id]);
                return $this->execute();
            } else {
                alert(__('<strong>Error.</strong> Could not create unit session for unit %s at pos. %s.', $unit_id, $position), 'alert-danger');
            }
        } else {
            alert('<strong>Error.</strong> You tried to jump to a non-existing run position or forgot to specify one entirely.', 'alert-danger');
        }

        return false;
    }

    public function getCurrentUnitSession() {
        $query = $this->db->select('
			`survey_unit_sessions`.unit_id,
			`survey_unit_sessions`.id,
            `survey_unit_sessions`.run_session_id,
            `survey_unit_sessions`.created,
            `survey_unit_sessions`.expires,
			`survey_unit_sessions`.ended,
            `survey_unit_sessions`.expired,
			`survey_units`.type')
                ->from('survey_unit_sessions')
                ->leftJoin('survey_units', 'survey_unit_sessions.unit_id = survey_units.id')
                ->where('survey_unit_sessions.run_session_id = :run_session_id')
                ->where('survey_unit_sessions.unit_id = :unit_id')
                ->where('survey_unit_sessions.ended IS NULL AND survey_unit_sessions.expired IS NULL') //so we know when to runToNextUnit
                ->bindParams(array('run_session_id' => $this->id, 'unit_id' => $this->getUnitIdAtPosition($this->position)))
                ->order('survey_unit_sessions`.id', 'desc')
                ->limit(1);

        $row = $query->fetch();

        if ($row) {
            $u = $row;
            $u['id'] = (int) $u['unit_id'];
            $unit = RunUnitFactory::make($this->run, $u);
            return new UnitSession($this, $unit, $row);
        } else {
            return false;
        }
    }

    public function getUnitSession() {
        if (!$this->currentUnitSession) {
            $this->currentUnitSession = $this->getCurrentUnitSession();
        }

        return $this->currentUnitSession;
    }

    public function endCurrentUnitSession($reason = null) {
        if ($us = $this->getCurrentUnitSession()) {
            $this->currentUnitSession = $us;
            $type = $this->currentUnitSession->runUnit->type;
            if ($type == 'Survey' || $type == 'External') {
                $this->currentUnitSession->expire();
            } else {
                $this->currentUnitSession->end($reason);
            }

            return true;
        }

        return false;
    }

    public function endLastExternal() {
        $query = "UPDATE `survey_unit_sessions`
			LEFT JOIN `survey_units` ON `survey_unit_sessions`.unit_id = `survey_units`.id
			SET `survey_unit_sessions`.`ended` = NOW()
			WHERE `survey_unit_sessions`.run_session_id = :id AND `survey_units`.type = 'External' AND  `survey_unit_sessions`.ended IS NULL AND `survey_unit_sessions`.expired IS NULL;";

        $updated = $this->db->exec($query, array('id' => $this->id));
        $success = $updated !== false;
        return $success;
    }

    public function end() {
        $query = "UPDATE `survey_run_sessions` SET `ended` = NOW() WHERE `id` = :id AND `ended` IS NULL";
        $updated = $this->db->exec($query, array('id' => $this->id));

        if ($updated === 1) {
            $this->ended = true;
            return true;
        }

        return false;
    }

    public function setTestingStatus($status = 0) {
        $this->db->update("survey_run_sessions", array('testing' => $status), array('id' => $this->id));
    }

    public function isTesting() {
        return $this->testing;
    }

    public function isCron() {
        return $this->user->isCron();
    }

    /**
     * Check if current run session is a test
     *
     * @param User $user
     * @return boolean True if current user in run is testing. False otherwise
     */
    public function isTest(User $user) {
        return $this->run_owner_id == $user->id;
    }

    public function __sleep() {
        return array('id', 'session', 'run_id');
    }

    public function saveSettings($settings, $update = null) {
        if (!empty($update)) {
            $this->db->update('survey_run_sessions', $update, array('id' => $this->id));
        }

        $oldSettings = $this->getSettings();
        unset($oldSettings['code']);
        if ($oldSettings) {
            $settings = array_merge($oldSettings, $settings);
        }

        $this->db->insert_update('survey_run_settings', array(
            'run_session_id' => $this->id,
            'settings' => json_encode($settings),
        ));
    }

    public function getSettings() {
        $settings = array();
        $row = $this->db->findRow('survey_run_settings', array('run_session_id' => $this->id));
        if ($row) {
            $settings = (array) json_decode($row['settings']);
        }
        $settings['code'] = $this->session;
        return $settings;
    }

    public static function toggleTestingStatus($sessions) {
        $dbh = DB::getInstance();
        if (is_string($sessions)) {
            $sessions = array($sessions);
        }

        foreach ($sessions as $session) {
            $qs[] = $dbh->quote($session);
        }

        $query = 'UPDATE survey_run_sessions SET testing = 1 - testing WHERE session IN (' . implode(',', $qs) . ')';
        return $dbh->query($query)->rowCount();
    }

    public static function deleteSessions($sessions) {
        $dbh = DB::getInstance();
        if (is_string($sessions)) {
            $sessions = array($sessions);
        }

        foreach ($sessions as $session) {
            $qs[] = $dbh->quote($session);
        }

        $query = 'DELETE FROM survey_run_sessions WHERE session IN (' . implode(',', $qs) . ')';
        return $dbh->query($query)->rowCount();
    }

    public static function positionSessions(Run $run, $sessions, $position) {
        if (is_string($sessions)) {
            $sessions = array($sessions);
        }

        $count = 0;
        foreach ($sessions as $session) {
            $runSession = new RunSession($session, $run);
            if ($runSession->position != $position && $runSession->forceTo($position)) {
                $runSession->execute();
                $count++;
            }
        }
        return $count;
    }

    public static function getSentRemindersBySessionId($id) {
        $stmt = DB::getInstance()->prepare('
            SELECT survey_unit_sessions.id as unit_session_id, survey_run_special_units.id as unit_id FROM survey_unit_sessions 
			LEFT JOIN survey_units ON survey_unit_sessions.unit_id = survey_units.id
			LEFT JOIN survey_run_special_units ON survey_run_special_units.id = survey_units.id
			WHERE survey_unit_sessions.run_session_id = :run_session_id AND survey_run_special_units.type = "ReminderEmail"
		');
        $stmt->bindValue('run_session_id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function toArray() {
        return [
            'id' => $this->id,
            'run_id' => $this->run->id,
            'user_id' => $this->user->id,
            'session' => $this->session,
            'created' => $this->created,
            'ended' => $this->ended,
            'last_access' => $this->last_access,
            'position' => $this->position,
            'current_unit_session_id' => $this->current_unit_session_id,
            'deactivated' => $this->deactivated,
            'no_email' => $this->no_email,
            'testing' => $this->testing,
            'created' => $this->created,
        ];
    }

    public function executeTest() {
        return $this->executeUnitSession();
    }

    public function canReceiveMails() {
        if ($this->no_email === null) {
            return true;
        }

        // If no mail is 0 then user has choose not to receive emails
        if ((int) $this->no_email === 0) {
            return false;
        }

        // If no_mail is set && the timestamp is less that current time then the snooze period has expired
        if ($this->no_email <= time()) {
            // modify subscription settings
            $this->saveSettings(array('no_email' => '1'), array('no_email' => null));
            return true;
        }

        return false;
    }

}
