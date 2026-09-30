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
        return "'{$this->name}': calculate items are not supported by the form_v2 engine. Use a hidden item with the same value (it is resolved when its page is shown and submitted with it), or compute the value where it is used (a later unit's R, feedback).";
    }

}
