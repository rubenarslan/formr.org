<?php

class Color_Item extends Item {

    public $type = 'color';
    public $input_attributes = array('type' => 'color');
    public $mysql_field = 'CHAR(7) DEFAULT NULL';
    protected $prepend = 'fa-tint';

    protected function setMoreOptions() {
        $this->classes_input[] = 'form-control';
    }

    public function validateInput($reply) {
        if ($this->optional && trim($reply) == '') {
            return parent::validateInput($reply);
        } else {
            if (!preg_match("/^#[0-9A-Fa-f]{6}$/", $reply)) {
                $this->error = __('The color %s is not valid', h($reply));
            }
        }

        return $reply;
    }

}
