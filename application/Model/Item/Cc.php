<?php

/*
 * Credit Card
 */

class Cc_Item extends Text_Item {

	public $type = 'cc';
	public $input_attributes = array('type' => 'cc', "data-luhn" => "");
	public $mysql_field = 'VARCHAR(255) DEFAULT NULL';

	protected $prepend = 'fa-credit-card';

	protected function setMoreOptions() {
		$this->classes_input[] = 'form-control';
	}

}
