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
        // Platform detection will be handled in JavaScript
    }

    public function getResultField() {
        return "`{$this->name}` {$this->mysql_field}";
    }

    protected function render_input() {
        // Create hidden input with same name as button
        $hidden_input = sprintf(
            '<input type="text" name="%s" value="not_requested" id="%s" style="display: none;" />',
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
                <p class="instructions"></p>
                %s
                %s
                <div class="status-message"></div>
            </div>
        ';

        return sprintf($template, $hidden_input, $button);
    }

    public function validateInput($reply) {
        if (in_array($reply, array('added', 'ios_not_prompted', 'not_requested', 'not_prompted', 'already_added', 'no_support', 'not_added'))) {
            return $reply;
        }
        return null;
    }
} 