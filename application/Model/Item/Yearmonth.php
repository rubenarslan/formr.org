<?php

class Yearmonth_Item extends Datetime_Item {

    public $type = 'yearmonth';
    public $input_attributes = array('type' => 'yearmonth');
    public $mysql_field = 'DATE DEFAULT NULL';
    //protected $prepend = 'fa-calendar-o';
    protected $html5_date_format = 'Y-m-01';

    protected function render_input() {
        if ($this->value_validated !== null) {
            $parts = explode('-', $this->value_validated);
            array_pop($parts);
            $this->input_attributes['value'] = implode('-', $parts);
        }

        return sprintf('<span><input %s /></span>', self::_parseAttributes($this->input_attributes));
    }

}
