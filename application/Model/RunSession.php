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
    private $run_owner_id;
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
     * Cache for unit ids in various positions
     * 
     * @var array
     */
    protected $positionedUnitIds = [];
    /** Maximum number of recursions to happen during a run session execution; */
    const MAX_EXECUTION_COUNT = 10;
    /**
     * Current number of execution counts while recursive
     * @var int
     */
    protected $executionCount = 0;

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

        if ($run->isStudyTest()) {
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
        $code_rule = Config::get("user_code_regular_expression");
        if ($session !== null) {
            if (!preg_match($code_rule, $session)) {
                alert("<strong>Error.</strong> Session tokens needs to match $code_rule", 'alert-danger');
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
			if (formr_in_console()) {
				$referenceUnitSession->end('ended_by_queue_rse');
				UnitSessionQueue::removeItem($referenceUnitSession->id);
			} elseif ($this->current_unit_session_id) {
				$this->currentUnitSession = new UnitSession($this, null, [
					'id' => $this->current_unit_session_id, 
					'load' => true
				]);
				if (!$this->currentUnitSession->runUnit) {
					formr_error(404, 'Run Unit Not Found', 'The Run Unit you are trying to access may have been deleted');
				}
				return $this->executeUnitSession();
			}
			
            // logout if we are unable to get a current unit session
            return redirect_to(run_url($this->run->name, 'logout', ['prev' => $this->session]));
        }
        
        if ($this->executionCount > self::MAX_EXECUTION_COUNT) {
            return $this->spam();
        }

        if ($this->run->isStudyTest()) {
            return $this->executeTest();
        }
        // Get the initial position if this run session hasn't executed before
        if ($this->position === null && !($position = $this->run->getFirstPosition())) {
            alert('This study has not been defined.', 'alert-danger');
            return false;
        }

        if ($this->position === null) {
            $this->position = $position;
            $this->save();
        }

        $currentUnitSession = $this->getCurrentUnitSession();
		
        // If there is a referenceUnitSession then it is sent by the queue
        if ($referenceUnitSession && $currentUnitSession && $referenceUnitSession->id == $currentUnitSession->id && !$executeReferenceUnit) {
            $this->debug("END-q");
            $this->endCurrentUnitSession();
            return $this->moveOn();
        } elseif ($referenceUnitSession && $currentUnitSession && $referenceUnitSession->id != $currentUnitSession->id) {
            // if $currenUnitSession is not identical to the $referenceUnitSession sent by queue then something went terribly bad
            UnitSessionQueue::removeItem($referenceUnitSession->id);
            return $this->moveOn();
        }

        $this->debug('Current Unit Is ' . ($currentUnitSession ? $currentUnitSession->runUnit->type : ''), true);
        if (!$currentUnitSession && $this->position === $this->run->getFirstPosition()) {
            // We are in the first unit of the run
            return $this->moveOn(true);
        } elseif (!$currentUnitSession) {
            // We maybe all previous unit sessions have ended so move on
            return $this->moveOn();
        } else {
            // Currently active unit session. Should most likely be a survey or pause
            $this->currentUnitSession = $currentUnitSession;
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
        if ($this->run->isStudyTest()) {
            // nothing to move on to
            return null;
        }
        
        if (!$starting) {
            $this->currentUnitSession = null;
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
        $this->executionCount++;
        $this->debug("Execute");
        
        $result = $this->currentUnitSession->execute();
        $this->debug($result, true);

        if (!empty($result['expired'])) {
            $this->debug("EXPIRE");
            $this->currentUnitSession->expire();
        } elseif (!empty($result['end_session'])) {
            $this->debug("END");
            $this->currentUnitSession->end();
        } elseif (isset($result['queue'])) {
            $this->debug('QUEUE');
            $this->currentUnitSession->queue();
            return [
                'body' => array_val($result, 'content'),
                'redirect' => array_val($result, 'redirect')
            ];
        }

        if (!empty($result['wait_opencpu']) || !empty($result['wait_user'])) {
            return ['body' => ''];
        }

        if (isset($result['redirect'])) {
            // move on in the run before redirecting to external service (except for surveys)
            if ($this->currentUnitSession->runUnit->type !== 'Survey') {
                $this->moveOn(false, false);
            }
            return $result;
        }

        if (isset($result['run_to'])) {
            return $this->runTo($result['run_to'], null, true);
        }
		
		if (isset($result['move_on'])) {
            return $this->moveOn();
        }

        if (isset($result['end_run_session'])) {
            $this->end();
        }
        
        if (isset($result['content'])) {
            return ['body' => $result['content']];
        }

        return $this->moveOn();

        alert('Error: Premature study end.', "alert-danger");
        return ['body' => 'FORMR_END'];
    }

    public function getUnitIdAtPosition($position) {
        if (empty($this->positionedUnitIds[$position])) {
            $this->positionedUnitIds[$position] = $this->db->findValue('survey_run_units', ['run_id' => $this->run->id, 'position' => $position], 'unit_id');
        }

        return $this->positionedUnitIds[$position];
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

    public function runTo($position, $unit_id = null, $execute = false) {
        if ($unit_id === null) {
            $unit_id = $this->getUnitIdAtPosition($position);
        }

        if ($unit_id) {
            $this->position = $position;
            $unit = RunUnitFactory::make($this->run, ['id' => $unit_id]);
            if ($unit->valid) {
                $this->createUnitSession($unit);
                //$this->db->update('survey_run_sessions', ['position' => $position], ['id' => $this->id]);
				$this->db->exec(
					"UPDATE `survey_run_sessions` SET  `ended` = NULL, `position` = :position WHERE `id` = :id",
					[
						'id' => $this->id,
						'position' => $position
					]
				);
				
				$this->ended = null;
				$exec = ['body' => null];
				if (formr_in_console() || $execute) {
					$exec = $this->execute();
				}

                return $exec;
            } else {
                alert(__('<strong>Error.</strong> Could not create unit session for unit %s at pos. %s.', $unit_id, $position), 'alert-danger');
            }
        } else {
            alert('<strong>Error.</strong> You tried to jump to a non-existing run position or forgot to specify one entirely.', 'alert-danger');
        }

        return false;
    }

    public function getCurrentUnitSession() {
        if ($this->currentUnitSession) {
            $this->debug("Using current unit session at {$this->position} [{$this->currentUnitSession->id}]", true);
            return $this->currentUnitSession;
        }

        $this->debug("Getting current unit session at {$this->position} [0]", true);
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
            $u['id'] = $u['unit_id'];
            $unit = RunUnitFactory::make($this->run, $u);
            $this->currentUnitSession = new UnitSession($this, $unit, $row);
            return $this->currentUnitSession;
        } else {
            return false;
        }
    }

    public function endCurrentUnitSession($reason = null) {
        if ($this->getCurrentUnitSession()) {
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
            $this->ended = mysql_datetime();
            return true;
        }

        return false;
    }
    
    public function spam() {
        $this->debug('SPAM');
        $this->endCurrentUnitSession('spam_' . $this->executionCount);
        $this->end();

        alert('This session is spamming us. Please fix your run definition', 'alert-danger');
        return ['body' => 'FORMR_SPAM'];
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

    /**
     * Get push notification subscription for this run session
     * 
     * @return array|null The subscription data or null if no subscription found
     */
    public function getSubscription() {
        // Query the subscription from survey_items_display for this user's session
        $query = "SELECT sid.answer 
                 FROM survey_items_display sid
                 JOIN survey_items si ON si.id = sid.item_id
                 JOIN survey_unit_sessions sus ON sus.id = sid.session_id
                 WHERE sus.run_session_id = :run_session_id 
                 AND si.type = 'push_notification'
                 AND sid.answer != 'not_requested'
                 AND sid.answer != 'not_supported'
                 ORDER BY sid.created DESC
                 LIMIT 1";

        $result = $this->db->execute($query, [
            ':run_session_id' => $this->id
        ], false, true);

        if (!$result || empty($result['answer'])) {
            return null;
        }

        return json_decode($result['answer'], true);
    }

    /**
     * Update the most recent push notification subscription for this run session
     * 
     * @param array|string|null $subscriptionData The subscription data (array or JSON string) or null to remove subscription
     * @return bool True if update was successful, false otherwise
     */
    public function updateSubscription($subscriptionData) {
        // Convert array to JSON string if needed
        if (is_array($subscriptionData)) {
            $subscriptionJson = json_encode($subscriptionData);
        } elseif (is_string($subscriptionData)) {
            $subscriptionJson = $subscriptionData;
        } elseif ($subscriptionData === null) {
            $subscriptionJson = 'not_requested';
        } else {
            return false;
        }

        // Find the most recent push notification item for this session
        $query = "SELECT sid.id, sid.item_id
                 FROM survey_items_display sid
                 JOIN survey_items si ON si.id = sid.item_id
                 JOIN survey_unit_sessions sus ON sus.id = sid.session_id
                 WHERE sus.run_session_id = :run_session_id 
                 AND si.type = 'push_notification'
                 ORDER BY sid.created DESC
                 LIMIT 1";

        $result = $this->db->execute($query, [
            ':run_session_id' => $this->id
        ], false, true);

        if (!$result || empty($result['id'])) {
            return false;
        }

        // Update the subscription data
        $updateQuery = "UPDATE survey_items_display 
                       SET answer = :answer, saved = NOW()
                       WHERE id = :id";

        $updateResult = $this->db->execute($updateQuery, [
            ':answer' => $subscriptionJson,
            ':id' => $result['id']
        ]);

        return $updateResult !== false;
    }

    /**
     * Get email recipient field for this run session
     * 
     * @param string|null $recipient_field The recipient field to evaluate, or null to get most recent email
     * @param bool $return_session Whether to return OpenCPU session for debugging
     * @param UnitSession|null $unitSession Optional unit session for dynamic field evaluation
     * @return string|OpenCPU_Session|null The recipient email address or OpenCPU session
     */
    public function getRecipientEmail($recipient_field = null, $return_session = false, $unitSession = null) {
        $mostrecent = "most recent reported address";
        
        if (!$recipient_field || $recipient_field === $mostrecent) {
            $recent_email_query = "
                SELECT survey_items_display.answer AS email FROM survey_unit_sessions
                LEFT JOIN survey_units ON survey_units.id = survey_unit_sessions.unit_id AND survey_units.type = 'Survey'
                LEFT JOIN survey_run_units ON survey_run_units.unit_id = survey_units.id
                LEFT JOIN survey_items_display ON survey_items_display.session_id = survey_unit_sessions.id
                LEFT JOIN survey_items ON survey_items.id = survey_items_display.item_id
                WHERE
                survey_unit_sessions.run_session_id = :run_session_id AND 
                survey_run_units.run_id = :run_id AND 
                survey_items.type = 'email'
                ORDER BY survey_items_display.answered DESC
                LIMIT 1
            ";

            $result = $this->db->execute($recent_email_query, [
                ':run_id' => $this->run->id,
                ':run_session_id' => $this->id
            ], false, true);

            $recipient = array_val($result, 'email', null);
        } else {
            // For dynamic recipient fields, we need a UnitSession to get run data
            $unitSessionToUse = $unitSession ?: $this->currentUnitSession;
            if ($unitSessionToUse) {
                $opencpu_vars = $unitSessionToUse->getRunData($recipient_field);
                $recipient = opencpu_evaluate($recipient_field, $opencpu_vars, 'json', null, $return_session);
            } else {
                // Fallback: try to get the most recent email
                return $this->getRecipientEmail(null, $return_session);
            }
        }

        return $recipient;
    }

    /**
     * Update the most recent email address for this run session
     * 
     * @param string $email The email address to update
     * @return bool True if update was successful, false otherwise
     */
    public function updateRecipientField($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Find the most recent email item for this session
        $query = "SELECT sid.id, sid.item_id
                 FROM survey_items_display sid
                 JOIN survey_items si ON si.id = sid.item_id
                 JOIN survey_unit_sessions sus ON sus.id = sid.session_id
                 WHERE sus.run_session_id = :run_session_id 
                 AND si.type = 'email'
                 ORDER BY sid.created DESC
                 LIMIT 1";

        $result = $this->db->execute($query, [
            ':run_session_id' => $this->id
        ], false, true);

        if (!$result || empty($result['id'])) {
            return false;
        }

        // Update the email address
        $updateQuery = "UPDATE survey_items_display 
                       SET answer = :answer, saved = NOW()
                       WHERE id = :id";

        $updateResult = $this->db->execute($updateQuery, [
            ':answer' => $email,
            ':id' => $result['id']
        ]);

        return $updateResult !== false;
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
    
    public static function getTestSession(Run $run) {
        $animal_name = AnimalName::haikunate(["tokenLength" => 0, "delimiter" => "",]) . "XXX";
        $animal_name = str_replace(" ", "", $animal_name);
        $test_code = crypto_token(48 - floor(3 / 4 * strlen($animal_name)));
        $test_code = $animal_name . substr($test_code, 0, 64 - strlen($animal_name));
        $run_session = new RunSession($test_code, $run);
        $run_session->create($test_code, true);

        return $run_session;
    }
    
    public static function getNamedSession(Run $run, $name, $testing = 0) {
        $name = str_replace(" ", "_", $name);
        if ($name && !preg_match('/^[a-zA-Z0-9_-~]{0,32}$/', $name)) {
            alert("Invalid characters in suggested name. Only a-z, numbers, _ - and ~ are allowed. Spaces are automatically replaced by a _.", 'alert-danger');
            return false;
        }

        if ($name) {
            $name .= 'XXX';
        }

        $new_code = crypto_token(48 - floor(3 / 4 * strlen($name)));
        $new_code = $name . substr($new_code, 0, 64 - strlen($name));
        $run_session = new RunSession($new_code, $run); // does this user have a session?
        $run_session->create($new_code, $testing);

        return $run_session;
    }
    
    public function isTestingStudy() {
        return $this->run->isStudyTest() || $this->id === -1;
    }
    
    protected function debug($message = '', $only = false) {
        if (!DEBUG) {
            return;
        }
        if (is_array($message)) {
             unset($message['content']);
        }
        $message = "(Count {$this->executionCount}) " . print_r($message, true);

        if ($this->currentUnitSession && $only === false) {
            formr_log("{$message} {$this->currentUnitSession->runUnit->type} [{$this->currentUnitSession->id}]", $this->id);
        } else {
            formr_log($message, $this->id);
        }
    }

}
