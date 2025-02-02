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
        $this->input_attributes['value'] = 'not_clicked';
        $this->input_attributes['data-platform'] = $this->detectPlatform();
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
                <div class="status-message"></div>
            </div>
        ';

        return sprintf($template, $instructions, $button);
    }

    public function validateInput($reply) {
        if (in_array($reply, array('added', 'ios_not_prompted', 'not_clicked', 'not_prompted', 'already_added', 'no_support'))) {
            return $reply;
        }
        return null;
    }
} 