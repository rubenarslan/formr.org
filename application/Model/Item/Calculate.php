<?php

class Calculate_Item extends Item {

    public $type = 'calculate';
    public $input_attributes = array('type' => 'hidden');
    public $no_user_input_required = true;
    public $mysql_field = 'MEDIUMTEXT DEFAULT NULL';

    public function render() {
        return $this->render_input();
    }

    public function formV2Unsupported() {
        return "'{$this->name}': v2 has no calculate items. Use a hidden item with the same value instead, or compute the value where you use it (in a later unit or in feedback).";
    }

}
