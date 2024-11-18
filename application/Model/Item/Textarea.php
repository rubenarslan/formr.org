<?php

class Textarea_Item extends Text_Item {

    public $type = 'textarea';
    public $mysql_field = 'MEDIUMTEXT DEFAULT NULL';

    protected function render_input() {
        if ($this->value_validated) {
            $this->input_attributes['value'] = $this->value_validated;
        }

        $value = array_val($this->input_attributes, 'value');
        unset($this->input_attributes['value']);
        return sprintf('<textarea %s>%s</textarea>', self::_parseAttributes($this->input_attributes, array('type')), $value);
    }

}
