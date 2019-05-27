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

    public function validateInput($reply) {
        if (isset($this->input_attributes['maxlength']) && $this->input_attributes['maxlength'] > 0 && strlen($reply) > $this->input_attributes['maxlength']) { // verify maximum length 
            $this->error = __("You can't use that many characters. The maximum is %d", $this->input_attributes['maxlength']);
        }
        return parent::validateInput($reply);
    }

}
