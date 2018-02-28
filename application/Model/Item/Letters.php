<?php

class Letters_Item extends Text_Item {

	public $type = 'letters';
	public $input_attributes = array('type' => 'text');
	public $mysql_field = 'TEXT DEFAULT NULL';

	protected function setMoreOptions() {
		$this->input_attributes['pattern'] = "[A-Za-züäöß.;,!: ]+";
		return parent::setMoreOptions();
	}

}
