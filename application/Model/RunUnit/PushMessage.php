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
        $output = array();
        $output['log'] = array();

        try {
            // Get subscription from the user's session
            $subscription = $unitSession->runSession->getSubscription();
            if (!$subscription) {
                $output['log']['result'] = 'no_subscription';
                $output['move_on'] = true;
                return $output;
            }

            // Parse message with run data
            $message = $this->getMessage($unitSession);
            if (!$message) {
                $output['log']['result'] = 'message_parse_failed';
                $output['move_on'] = true;
                return $output;
            }

            $title = $this->getTitle($unitSession);
            if (!$title) {
                $output['log']['result'] = 'title_parse_failed';
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
            $output['move_on'] = true;

        } catch (Exception $e) {
            $output['log']['result'] = 'error';
            $output['log']['result_log'] = $e->getMessage();
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