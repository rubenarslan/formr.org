<?php

class PushNotification_Item extends Item {
    public $type = 'push_notification';
    public $no_user_input_required = false;
    public $hasChoices = true;
    public $mysql_field = 'TEXT DEFAULT NULL';
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

        // Create hidden input with same name as button to store subscription data
        $hidden_input = sprintf(
            '<input type="text" name="%s" value="not_requested" id="%s" />',
            $this->name,
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
        // For optional items, accept all valid states
        if ($this->optional) {
            if ($reply === 'not_requested' || 
                $reply === 'not_supported' || 
                $reply === 'permission_denied') {
                return $reply;
            }
        }
        
        // Validate subscription JSON - required for non-optional items
        if ($reply) {
            $data = json_decode($reply, true);
            if (json_last_error() === JSON_ERROR_NONE && 
                isset($data['endpoint']) && 
                isset($data['keys']) && 
                isset($data['keys']['p256dh']) && 
                isset($data['keys']['auth'])) {
                return $reply; // Valid subscription JSON
            }
        }
        
        return null;
    }
} 