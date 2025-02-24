<?php

class AddToHomeScreen_Item extends Item {
    public $type = 'add_to_home_screen';
    public $no_user_input_required = false;
    public $hasChoices = true;
    public $mysql_field = 'VARCHAR(20) DEFAULT NULL';
    protected $classes_input = array('btn', 'btn-primary', 'add-to-homescreen');

    protected function setMoreOptions() {
        // Add Font Awesome icon and text for the button
        $this->input_attributes['type'] = 'button';
        // Remove value from button as it will be stored in hidden input
        unset($this->input_attributes['value']);
        $this->input_attributes['data-platform'] = $this->detectPlatform();
    }

    public function getResultField() {
        return "`{$this->name}` {$this->mysql_field}";
    }

    protected function detectPlatform() {
        // This will be used by JavaScript to show appropriate instructions
        if (strpos($_SERVER['HTTP_USER_AGENT'], 'iPhone') !== false || 
            strpos($_SERVER['HTTP_USER_AGENT'], 'iPad') !== false || 
            strpos($_SERVER['HTTP_USER_AGENT'], 'iPod') !== false) {
            return 'ios';
        }
        return 'other';
    }

    protected function render_input() {
        $platform = $this->input_attributes['data-platform'];
        
        // Different instructions based on platform
        $instructions = $platform === 'ios' 
            ? 'To add this app to your home screen, use the share menu <i class="fa fa-share-square-o"></i> and then select "Add to Home Screen"'
            : 'Click the button below to add this app to your home screen';

        // Create hidden input with same name as button
        $hidden_input = sprintf(
            '<input type="hidden" name="%s" value="not_clicked" id="%s" />',
            $this->name,
            $this->name
        );

        $button_label = 'Add to Home Screen';
        if(count($this->choices) > 0) {
            $button_label = reset($this->choices);
        }
        $button = sprintf(
            '<button %s><i class="fa fa-home"></i> %s</button>',
            self::_parseAttributes($this->input_attributes),
            $button_label
        );

        $template = '
            <div class="add-to-homescreen-wrapper">
                <p class="instructions">%s</p>
                %s
                %s
                <pwa-install manual-chrome="true" style="display: none;"></pwa-install>
                <div class="status-message"></div>
            </div>
        ';

        return sprintf($template, $instructions, $hidden_input, $button);
    }

    public function validateInput($reply) {
        if (in_array($reply, array('added', 'ios_not_prompted', 'not_clicked', 'not_prompted', 'already_added', 'no_support', 'not_added'))) {
            return $reply;
        }
        return null;
    }
} 