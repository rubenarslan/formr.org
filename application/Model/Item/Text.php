<?php

class Text_Item extends Item {

    public $type = 'text';
    public $input_attributes = array('type' => 'text');
    public $mysql_field = 'TEXT DEFAULT NULL';

    protected function setMoreOptions() {
        if (is_array($this->type_options_array) && count($this->type_options_array) == 1) {
            $val = trim(current($this->type_options_array));
            if (is_numeric($val)) {
                $this->input_attributes['maxlength'] = (int) $val;
            } else if (trim(current($this->type_options_array))) {
                $this->input_attributes['pattern'] = trim(current($this->type_options_array));
            }
        }
        $this->classes_input[] = 'form-control';
    }

    /**
     * What a participant is told when the input doesn't match `pattern`
     * (#269): the item's first choice, if the author gave one — a pattern
     * item has no other use for choices — else null, leaving the browser's
     * generic "Please match the requested format."
     */
    protected function patternMessage() {
        foreach ((array) $this->choices as $label) {
            $text = trim(html_entity_decode(strip_tags((string) $label), ENT_QUOTES, 'UTF-8'));
            if ($text !== '') {
                return $text;
            }
        }
        return null;
    }

    protected function render_input() {
        // `title` is what browsers (and v2's inline feedback) show for a
        // pattern mismatch.
        if (isset($this->input_attributes['pattern']) && !isset($this->input_attributes['title'])) {
            $message = $this->patternMessage();
            if ($message !== null) {
                $this->input_attributes['title'] = $message;
            }
        }
        return parent::render_input();
    }

    /**
     * A pattern item's choices are its mismatch message (patternMessage), not
     * answer options, so the base check "this type doesn't have choices" must
     * not reject them. Same approach as BotCheck_Item::validate().
     */
    public function validate() {
        if (!isset($this->input_attributes['pattern'])) {
            return parent::validate();
        }
        $choices = $this->choices;
        $choiceList = $this->choice_list;
        $this->choices = array();
        $this->choice_list = null;
        $result = parent::validate();
        $this->choices = $choices;
        $this->choice_list = $choiceList;
        return $result;
    }

    public function validateInput($reply) {
        if ($this->rejectNonScalarReply($reply)) {
            return null;
        }
        if (isset($this->input_attributes['maxlength']) && $this->input_attributes['maxlength'] > 0 && strlen($reply) > $this->input_attributes['maxlength']) { // verify maximum length 
            $this->error = __("You can't use that many characters. The maximum is %d", $this->input_attributes['maxlength']);
        }
        return parent::validateInput($reply);
    }

}
