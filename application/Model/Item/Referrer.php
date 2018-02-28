<?php

class Referrer_Item extends Item {

	public $type = 'referrer';
	public $input_attributes = array('type' => 'hidden');
	public $mysql_field = 'TEXT DEFAULT NULL';
	public $no_user_input_required = true;
	public $optional = 1;

	protected function setMoreOptions() {
		$this->input_attributes['value'] = Site::getInstance()->last_outside_referrer;
	}

	public function validateInput($reply) {
		return $reply;
	}

	public function getReply($reply) {
		return Site::getInstance()->last_outside_referrer;
	}

	public function render() {
		return $this->render_input();
	}

}
