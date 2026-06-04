<?php

class Year_Item extends Datetime_Item {

    public $type = 'year';
    // `type="year"` isn't a real HTML type — browsers fall back to text, which
    // shows the alpha keyboard on mobile. inputmode=numeric restores the digit
    // pad without changing the (text) field's validation behaviour.
    public $input_attributes = array('type' => 'year', 'inputmode' => 'numeric');
    public $mysql_field = 'YEAR DEFAULT NULL';
    protected $html5_date_format = 'Y';
    protected $prepend = 'fa-calendar-o';

}
