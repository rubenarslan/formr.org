<?php

class Date_Item extends Datetime_Item {

    public $type = 'date';
    public $input_attributes = array('type' => 'date', 'placeholder' => 'yyyy-mm-dd');
    public $mysql_field = 'DATE DEFAULT NULL';
    //protected $prepend = 'fa-calendar';
    protected $html5_date_format = 'Y-m-d';

}
