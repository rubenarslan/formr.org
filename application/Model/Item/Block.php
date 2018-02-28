<?php

class Block_Item extends Note_Item {

	public $type = 'block';
	public $input_attributes = array('type' => 'checkbox');
	public $optional = 0;

	public function setMoreOptions() {
		$this->classes_wrapper[] = 'alert alert-danger';
	}

}
