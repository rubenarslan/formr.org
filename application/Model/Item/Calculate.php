<?php

class Calculate_Item extends Item {

    public $type = 'calculate';
    public $input_attributes = array('type' => 'hidden');
    public $no_user_input_required = true;
    public $mysql_field = 'MEDIUMTEXT DEFAULT NULL';

    public function render() {
        return $this->render_input();
    }

}
