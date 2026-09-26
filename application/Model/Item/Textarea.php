<?php

class Textarea_Item extends Text_Item {

    public $type = 'textarea';
    public $mysql_field = 'MEDIUMTEXT DEFAULT NULL';

    protected function render_input() {
        // `!== null`, not truthiness: a stored answer "0" is an answer.
        if ($this->value_validated !== null) {
            $this->input_attributes['value'] = $this->value_validated;
        }

        // Escaped like every other input's value attribute: textarea content
        // is RCDATA, so a raw stored answer had its entities decoded ("x &lt; y"
        // came back as "x < y") and "</textarea>" ended the element — and v2
        // re-renders every stored answer, which the next submit then saved.
        $value = array_val($this->input_attributes, 'value');
        unset($this->input_attributes['value']);
        return sprintf('<textarea %s>%s</textarea>', self::_parseAttributes($this->input_attributes, array('type')), h((string) $value));
    }

}
