<?php

class RequestPhone_Item extends Item {
    public $type = 'request_phone';
    public $no_user_input_required = false;
    public $hasChoices = false;
    public $mysql_field = 'VARCHAR(20) DEFAULT NULL';
    protected $classes_input = array('btn', 'btn-primary', 'request-phone');

    protected function setMoreOptions() {
        // Check if user is on a mobile device using user agent
        $is_mobile = false;
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $user_agent = $_SERVER['HTTP_USER_AGENT'];
            $mobile_patterns = array(
                'Android', 'webOS', 'iPhone', 'iPad', 'iPod', 'BlackBerry', 'IEMobile', 'Opera Mini'
            );
            foreach ($mobile_patterns as $pattern) {
                if (stripos($user_agent, $pattern) !== false) {
                    $is_mobile = true;
                    break;
                }
            }
        }

        // If on mobile, set as answered and don't require interaction
        if ($is_mobile) {
            $this->no_user_input_required = true;
            $this->input_attributes['value'] = 'is_phone';
        } else {
            // Add Font Awesome icon and text for the button
            $this->input_attributes['type'] = 'button';
            // Remove value from button as it will be stored in hidden input
            unset($this->input_attributes['value']);
        }
    }

    public function getResultField() {
        return "`{$this->name}` {$this->mysql_field}";
    }

    protected function render_input() {
        // Create hidden input with same name as button
        $hidden_input = sprintf(
            '<input type="text" name="%s" value="%s" id="%s" />',
            $this->name,
            isset($this->input_attributes['value']) ? $this->input_attributes['value'] : 'not_checked',
            $this->name
        );

        // If already on mobile, show confirmation message instead of button
        if (isset($this->input_attributes['value']) && $this->input_attributes['value'] === 'is_phone') {
            $template = '
                <div class="request-phone-wrapper">
                    <p class="instructions">You are already using a mobile device.</p>
                    %s
                    <div class="status-message">You may proceed with the survey.</div>
                </div>
            ';
            return sprintf($template, $hidden_input);
        }

        // Otherwise show the regular button and QR code interface
        $choice_label = 'Continue on Phone';
        if(count($this->choices) > 0) {
            $choice_label = reset($this->choices);
        }
        $text = sprintf(
            '<p class="text-center"><i class="fa fa-mobile"></i> %s</p>',
            $choice_label
        );

        $template = '
            <div class="request-phone-wrapper">
                <p class="instructions"></p>
                %s
                %s
                <div class="qr-code-container" style="width: 250px; height: 250px; margin: 20px auto; display: none;"></div>
                <div class="status-message"></div>
            </div>
        ';

        return sprintf($template, $hidden_input, $text);
    }

    public function validateInput($reply) {
        if (in_array($reply, array('is_phone', 'is_desktop', 'qr_scanned', 'not_checked'))) {
            return $reply;
        }
        return null;
    }
} 