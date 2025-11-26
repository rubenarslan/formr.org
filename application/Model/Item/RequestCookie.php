<?php

class RequestCookie_Item extends Item {
    public $type = 'request_cookie';
    public $no_user_input_required = false;
    public $hasChoices = true;
    public $mysql_field = "VARCHAR(20) DEFAULT NULL";

    // Use same classes as other button-style items
    protected $classes_input = array('btn', 'btn-primary', 'request-cookie');

    protected function setMoreOptions() {
        // If functional cookie consent already exists, mark as answered automatically
        if (gave_functional_cookie_consent()) {
            $this->no_user_input_required = true;
            $this->input_attributes['value'] = 'functional_cookie';
        } else {
            // Configure as button that opens preferences dialog
            $this->input_attributes['type'] = 'button';
            unset($this->input_attributes['value']);
        }
    }

    public function getResultField() {
        return "`{$this->name}` {$this->mysql_field}";
    }

    protected function render_input() {
        $hidden_input = sprintf(
            '<input type="text" name="%s" value="%s" id="%s" style="display:none;" />',
            $this->name,
            isset($this->input_attributes['value']) ? $this->input_attributes['value'] : 'not_checked',
            $this->name
        );

        // Already consented – show confirmation only
        if (isset($this->input_attributes['value']) && $this->input_attributes['value'] === 'functional_cookie') {
            $template = '<div class="request-cookie-wrapper"><p class="instructions">Functional cookies are already enabled on this device.</p>%s<div class="status-message">You may proceed with the survey.</div></div>';
            return sprintf($template, $hidden_input);
        }

        // Button label (choice1 if provided)
        $choice_label = count($this->choices) > 0 ? reset($this->choices) : 'Enable Functional Cookies';
        $button_html = sprintf('<button type="button" class="btn btn-primary request-cookie"><i class="fa fa-cookie"></i> %s</button>', h($choice_label));

        $template = <<<HTML
<div class="request-cookie-wrapper">
    %s
    <div class="status-message"></div>
    %s
</div>
HTML;
        return sprintf($template, $hidden_input, $button_html);
    }

    public function validateInput($reply) {
        if (in_array($reply, array('functional_cookie', 'consent_given'))) {
            return $reply;
        }
        return null;
    }
}
