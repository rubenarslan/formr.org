<?php

class Hidden_Item extends Item {

    public $type = 'hidden';
    public $mysql_field = 'MEDIUMTEXT DEFAULT NULL';
    public $input_attributes = array('type' => 'hidden');
    public $optional = 1;

    public function setMoreOptions() {
        unset($this->input_attributes["required"]);
        $this->classes_wrapper[] = "hidden";
    }

    public function render_inner() {
        return $this->render_input();
    }

}
