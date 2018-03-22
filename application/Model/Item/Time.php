<?php

// time is polyfilled, we prepended a clock
class Time_Item extends Datetime_Item {

	public $type = 'time';
	public $input_attributes = array('type' => 'time', 'style' => 'width:160px');
	public $mysql_field = 'TIME DEFAULT NULL';

	//protected $prepend = 'fa-clock-o';
	protected $html5_date_format = 'H:i';

}
