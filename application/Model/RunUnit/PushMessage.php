<?php
class PushMessage extends RunUnit {
    public $type = 'PushMessage';
    public $message;
    public $errors = array();
    public $id = null;
    public $unit = null;

	public $icon = "fa-bell";
    public $topic;
    public $priority = 'high';  // normal, high, urgent
    public $time_to_live = 86400; // 24 hours in seconds
    public $badge_count = 1;          // Custom property - used in notification data
    public $vibrate = true;
    public $require_interaction = true;
    public $renotify = true;
    public $silent = false;

    public $export_attribs = array(
        'type', 'description', 'position', 'special',
        'message', 'topic', 'priority', 'time_to_live', 'badge_count',
        'vibrate', 'require_interaction', 'renotify', 'silent'
    );

    protected $defaults = array(
        'message' => '',
        'topic' => '',
        'priority' => 'normal',
        'time_to_live' => 86400,
        'badge_count' => 1,
        'vibrate' => true,
        'require_interaction' => true,
        'renotify' => true,
        'silent' => false
    );

    public function __construct(Run $run, array $props = []) {
        parent::__construct($run, $props);

        if ($this->id) {
            $vars = $this->db->findRow('push_messages', array('id' => $this->id), 'message, topic, priority, time_to_live, badge_count, vibrate, require_interaction, renotify, silent');
            if ($vars) {
                $vars['valid'] = true;
                $this->assignProperties($vars);
            }
        }
    }

    public function create($options = array()) {
        $this->assignProperties($this->defaults);
        if (isset($options['message'])) {
            array_walk($options, "emptyNull");
            // Handle notification mode
            if (isset($options['notification_mode'])) {
                switch ($options['notification_mode']) {
                    case 'sound_vibration':
                        $options['vibrate'] = true;
                        $options['silent'] = false;
                        break;
                    case 'sound_only':
                        $options['vibrate'] = false;
                        $options['silent'] = false;
                        break;
                    case 'silent':
                        $options['vibrate'] = false;
                        $options['silent'] = true;
                        break;
                }
            }
            // Convert checkbox values to booleans
            $options['require_interaction'] = isset($options['require_interaction']) && $options['require_interaction'];
            $options['renotify'] = isset($options['renotify']) && $options['renotify'];
            $this->assignProperties($options);
        }
        
        // Use parent create method which handles both new units and updates
        parent::create($options);
        
        // Save PushMessage-specific settings after parent creates/updates base unit
        $this->saveSettings();
        $this->valid = true;

        return $this;
    }

    public function saveSettings() {
        $settings = array(
            'message' => $this->message,
            'topic' => $this->topic,
            'priority' => $this->priority,
            'time_to_live' => (int)$this->time_to_live,
            'badge_count' => (int)$this->badge_count,
            'vibrate' => (bool)$this->vibrate ? 1 : 0,
            'require_interaction' => (bool)$this->require_interaction ? 1 : 0,
            'renotify' => (bool)$this->renotify ? 1 : 0,
            'silent' => (bool)$this->silent ? 1 : 0
        );

        $this->db->insert_update('push_messages', array_merge(
            $settings,
            array('id' => $this->id)
        ), $settings);
    }

    public function load() {
        $vars = $this->db->findRow('push_messages', array('id' => $this->id));
        if ($vars) {
            $this->assignProperties($vars);
            $this->valid = true;
        }
        return $this;
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        // Idempotency guard: a successful prior send leaves result='sent'
        // (or 'no_subscription' / 'error' / 'message_parse_failed' /
        // 'title_parse_failed' for terminal-failure cases). Re-executing
        // would deliver a duplicate push notification. See the matching
        // guard in Email::getUnitSessionOutput and
        // tests/e2e/double-expiry.spec.js for context.
        // Track A A8: end_session is included so re-encountering the
        // row transitions state to ENDED (end() is a no-op on rows
        // where `ended IS NOT NULL`, so this is idempotent).
        if (in_array($unitSession->result, ['sent', 'no_subscription', 'error', 'message_parse_failed', 'title_parse_failed'], true)) {
            return ['end_session' => true, 'move_on' => true];
        }

        // Track A A4 — closes R5 for push. Insert a "claim" row in
        // push_logs with idempotency_key = "push:{unit_session_id}"
        // BEFORE invoking sendPushMessage. If the daemon was SIGKILL'd
        // mid-send and restart re-enters this branch (with result still
        // NULL, so the guard above doesn't fire), the duplicate
        // INSERT collides on UNIQUE(idempotency_key) and the handler
        // bails — preventing a duplicate push notification at the cost
        // of "we tried, can't tell if it landed" semantics. Push UX is
        // recoverable (user opens app to check); a duplicate buzz isn't.
        // See REFACTOR_QUEUE_PLAN.md A4 for trade-off rationale.
        $idempotency_key = "push:{$unitSession->id}";
        $claimed = $this->db->exec(
            "INSERT INTO `push_logs`
                (`unit_session_id`, `run_id`, `message`, `status`, `attempt`, `created`, `idempotency_key`)
             VALUES
                (:us_id, :run_id, :message, 'queued', 1, NOW(), :idempotency_key)
             ON DUPLICATE KEY UPDATE `id` = `id`",
            [
                'us_id'           => $unitSession->id,
                'run_id'          => $this->run->id,
                // We haven't parsed the message yet; just store the
                // template body so the row carries something useful.
                'message'         => (string) $this->message,
                'idempotency_key' => $idempotency_key,
            ]
        );
        if ((int) $claimed === 0) {
            // Idempotent skip: duplicate INSERT means a prior attempt
            // already claimed this unit-session. Bail with the same
            // shape as the v0.25.7 terminal-result guard so the cascade
            // dispatcher treats this as completed and moves on. Track A
            // A8: include end_session so this row also transitions to
            // ENDED (no-op if ended IS NOT NULL).
            return ['end_session' => true, 'move_on' => true];
        }

        $output = array();
        $output['log'] = array();

        try {
            // Get subscription from the user's session
            $subscription = $unitSession->runSession->getSubscription();
            if (!$subscription) {
                $output['log']['result'] = 'no_subscription';
                // Track A A8: end_session terminates the unit-session
                // cleanly (state → ENDED) instead of leaving it dangling
                // in PENDING. All four exits below get the same fix.
                $output['end_session'] = true;
                $output['move_on'] = true;
                return $output;
            }

            // Parse message with run data
            $message = $this->getMessage($unitSession);
            if (!$message) {
                $output['log']['result'] = 'message_parse_failed';
                $output['end_session'] = true;
                $output['move_on'] = true;
                return $output;
            }

            $title = $this->getTitle($unitSession);
            if (!$title) {
                $output['log']['result'] = 'title_parse_failed';
                $output['end_session'] = true;
                $output['move_on'] = true;
                return $output;
            }

            // Send push notification
            $pushService = new PushNotificationService(
                $unitSession->runSession->getRun(),
                $this->db
            );

            // Create configuration array with all notification options
            $options = [
                'message' => $message,
                'title' => $title,
                'clickTarget' => run_url($this->run->name, '', ['code' => $unitSession->runSession->session]),
                'priority' => $this->priority,
                // Use explicit casting for numeric values
                'timeToLive' => (int)$this->time_to_live,
                // Handle badge_count specifically - could be null, 0, or a positive number
                'badgeCount' => $this->badge_count !== null && $this->badge_count !== '' ? (int)$this->badge_count : null,
                // Convert to proper boolean, ensuring values like "0" become false
                'vibrate' => $this->vibrate && $this->vibrate !== "0" ? true : false,
                'requireInteraction' => $this->require_interaction && $this->require_interaction !== "0" ? true : false,
                'renotify' => $this->renotify && $this->renotify !== "0" ? true : false,
                'silent' => $this->silent && $this->silent !== "0" ? true : false
            ];

            $pushService->sendPushMessage(
                $unitSession->id,
                $subscription,
                $options
            );

            $output['log']['result'] = 'sent';
            $output['end_session'] = true;
            $output['move_on'] = true;

        } catch (Exception $e) {
            $output['log']['result'] = 'error';
            $output['log']['result_log'] = $e->getMessage();
            $output['end_session'] = true;
            $output['move_on'] = true;
        }

        return $output;
    }

    protected function getMessage(UnitSession $unitsession) {
        if (knitting_needed($this->message)) {
                if ($unitsession !== null) {
                    return $this->getParsedText($this->message, $unitsession);
                } else {
                    return false;
                }
        } else {
            return $this->message;
        }
    }

    protected function getTitle(UnitSession $unitSession) {
        if (knitting_needed($this->topic)) {
            return $this->getParsedText($this->topic, $unitSession);
        } else {
            return $this->topic;
        }
    }

	public function displayForRun($prepend = '') {

        $dialog = Template::get($this->getTemplatePath(), array(
            'push_message' => $this,
            'prepend' => $prepend,
            'message' => $this->message,
            'topic' => $this->topic,
            'priority' => $this->priority,
            'priority_options' => array(
                'normal' => 'Normal',
                'high' => 'High',
                'urgent' => 'Urgent'
            ),
            'time_to_live' => $this->time_to_live,
            'badge_count' => $this->badge_count,
            'vibrate' => $this->vibrate,
            'require_interaction' => $this->require_interaction,
            'renotify' => $this->renotify,
            'silent' => $this->silent
        ));

        return parent::runDialog($dialog);
    }

    public function modify($options = []) {
        parent::modify($options);
        // Add property assignment from options
        if ($options) {
            array_walk($options, "emptyNull");
            // Handle notification mode
            if (isset($options['notification_mode'])) {
                switch ($options['notification_mode']) {
                    case 'sound_vibration':
                        $options['vibrate'] = true;
                        $options['silent'] = false;
                        break;
                    case 'sound_only':
                        $options['vibrate'] = false;
                        $options['silent'] = false;
                        break;
                    case 'silent':
                        $options['vibrate'] = false;
                        $options['silent'] = true;
                        break;
                }
            }
            $options['require_interaction'] = isset($options['require_interaction']) && $options['require_interaction'];
            $options['renotify'] = isset($options['renotify']) && $options['renotify'];
            $this->assignProperties($options);
        }
        $this->saveSettings();
        return true;
    }
} 