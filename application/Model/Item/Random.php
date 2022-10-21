<?php

class Random_Item extends Number_Item {

    public $type = 'random';
    public $input_attributes = array('type' => 'hidden', 'step' => 1, 'min' => 0, 'max' => 10000000);
    public $mysql_field = 'INT UNSIGNED DEFAULT NULL';
    public $no_user_input_required = true;

    protected function setMoreOptions() {
        parent::setMoreOptions();
        $this->input_attributes['value'] = $this->validateInput();
    }

    public function validateInput($reply = '') {
        if (isset($this->input_attributes['min']) && isset($this->input_attributes['max'])) { // both limits specified
            $reply = mt_rand($this->input_attributes['min'], $this->input_attributes['max']);
        } elseif (!isset($this->input_attributes['min']) && !isset($this->input_attributes['max'])) { // neither limit specified
            $reply = mt_rand(0, 1);
        } else {
            $this->error = __("Both random minimum and maximum need to be specified");
        }
        return $reply;
    }

    public function getReply($reply) {
        return $this->input_attributes['value'];
    }

}
