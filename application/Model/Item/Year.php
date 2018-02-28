<?php

class Year_Item extends Datetime_Item {

	public $type = 'year';
	public $input_attributes = array('type' => 'year');
	public $mysql_field = 'YEAR DEFAULT NULL';

	protected $html5_date_format = 'Y';
	protected $prepend = 'fa-calendar-o';

}
