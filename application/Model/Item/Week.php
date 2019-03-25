<?php

class Week_Item extends Datetime_Item {

    public $type = 'week';
    public $input_attributes = array('type' => 'week');
    public $mysql_field = 'VARCHAR(9) DEFAULT NULL';
    protected $html5_date_format = 'Y-mW';

    //protected $prepend = 'fa-calendar-o';
}
