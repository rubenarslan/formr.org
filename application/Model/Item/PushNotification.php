<?php

class PushNotification_Item extends Item {
    public $type = 'push_notification';
    public $no_user_input_required = false;
    public $hasChoices = true;
    public $mysql_field = 'VARCHAR(20) DEFAULT NULL';
    protected $classes_input = array('btn', 'btn-primary', 'push-notification-permission');

    protected function setMoreOptions() {
        // Add Font Awesome icon and text for the button
        $this->input_attributes['type'] = 'button';
        // Remove value from button as it will be stored in hidden input
        unset($this->input_attributes['value']);
    }

    public function getResultField() {
        return "`{$this->name}` {$this->mysql_field}";
    }

    protected function render_input() {
        $button_label = 'Enable Push Notifications';
        if(count($this->choices) > 0) {
            $button_label = reset($this->choices);
        }

        // Create hidden input with same name as button
        $hidden_input = sprintf(
            '<input type="hidden" name="%s" value="not_requested" />',
            $this->name
        );

        $button = sprintf(
            '<button %s><i class="fa fa-bell"></i> %s</button>',
            self::_parseAttributes($this->input_attributes),
            $button_label
        );

        $template = '
            <div class="push-notification-wrapper">
                <p class="instructions">Allow us to send you important notifications about your study progress.</p>
                %s
                %s
                <div class="status-message"></div>
            </div>
        ';

        return sprintf($template, $hidden_input, $button);
    }

    public function validateInput($reply) {
        // Valid states for push notification permission
        if (in_array($reply, array('granted', 'denied', 'default', 'not_supported', 'not_requested'))) {
            return $reply;
        }
        return null;
    }
} 