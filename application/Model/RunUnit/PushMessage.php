<?php

class PushMessage extends RunUnit {
    public $type = 'PushMessage';
    public $message;
    public $errors = array();
    public $id = null;
    public $unit = null;

	public $icon = "fa-bell";
    public $topic;
    public $priority = 'normal';  // normal, high, urgent
    public $time_to_live = 86400; // 24 hours in seconds
    public $badge_count;
    public $vibrate = true;
    public $require_interaction = false;
    public $renotify = false;
    public $silent = false;
    public $showif;

    protected $defaults = array(
        'message' => '',
        'topic' => '',
        'priority' => 'normal',
        'time_to_live' => 86400,
        'badge_count' => null,
        'icon' => null,
        'vibrate' => true,
        'require_interaction' => false,
        'renotify' => false,
        'silent' => false,
        'showif' => null
    );

    public function create($options = array()) {
        $this->assignProperties($this->defaults);
        $this->assignProperties($options);

        $unit = array(
            'type' => $this->type,
            'created' => mysql_now(),
            'modified' => mysql_now()
        );

        $this->id = $this->db->insert('survey_units', $unit);
        if (!$this->id) {
            return false;
        }

        $this->saveSettings();
		$this->valid = true;

        return $this->addToRun($options);
    }

    public function saveSettings() {
        $settings = array(
            'message' => $this->message,
            'topic' => $this->topic,
            'priority' => $this->priority,
            'time_to_live' => $this->time_to_live,
            'badge_count' => $this->badge_count,
            'icon' => $this->icon,
            'vibrate' => $this->vibrate ? 1 : 0,
            'require_interaction' => $this->require_interaction ? 1 : 0,
            'renotify' => $this->renotify ? 1 : 0,
            'silent' => $this->silent ? 1 : 0,
            'showif' => $this->showif
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

        // Check if we should send based on showif condition
        if ($this->showif) {
            $opencpu_vars = $unitSession->getRunData($this->showif);
            $send = opencpu_evaluate($this->showif, $opencpu_vars);
            if (!$send) {
                $output['log']['result'] = 'skipped_showif';
                $output['move_on'] = true;
                return $output;
            }
        }

        try {
            // Get subscription from the user's session
            $subscription = $this->getSubscription($unitSession);
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

            // Send push notification
            $pushService = new \App\Services\PushNotificationService(
                $unitSession->runSession->getRun(),
                $this->db
            );

            $pushService->sendPushMessage(
                $unitSession->id,
                $subscription,
                $message
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

    protected function getMessage(UnitSession $unitSession) {
        $opencpu_vars = $unitSession->getRunData($this->message);
        return opencpu_evaluate($this->message, $opencpu_vars, 'text');
    }

    protected function getSubscription(UnitSession $unitSession) {
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
            ':run_session_id' => $unitSession->runSession->id
        ], false, true);

        if (!$result || empty($result['answer'])) {
            return null;
        }

        return json_decode($result['answer'], true);
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
            'silent' => $this->silent,
            'showif' => $this->showif
        ));

        return parent::runDialog($dialog);
    }

    public function getExportUnit() {
        $unit = parent::getExportUnit();
        $unit = array_merge($unit, array(
            'message' => $this->message,
            'topic' => $this->topic,
            'priority' => $this->priority,
            'time_to_live' => $this->time_to_live,
            'badge_count' => $this->badge_count,
            'icon' => $this->icon,
            'vibrate' => $this->vibrate,
            'require_interaction' => $this->require_interaction,
            'renotify' => $this->renotify,
            'silent' => $this->silent,
            'showif' => $this->showif
        ));
        return $unit;
    }
} 