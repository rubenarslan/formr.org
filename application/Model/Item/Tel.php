<?php

class Tel_Item extends Text_Item {

    public $type = 'tel';
    public $input_attributes = array('type' => 'tel');
    public $mysql_field = 'VARCHAR(100) DEFAULT NULL';
    protected $prepend = 'fa-phone';

    protected function setMoreOptions() {
        $this->classes_input[] = 'form-control';
    }

}
