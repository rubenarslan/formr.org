<?php

class Yearmonth_Item extends Datetime_Item {

	public $type = 'yearmonth';
	public $input_attributes = array('type' => 'yearmonth');
	public $mysql_field = 'DATE DEFAULT NULL';
	
	protected $prepend = 'fa-calendar-o';
	protected $html5_date_format = 'Y-m-01';

}

