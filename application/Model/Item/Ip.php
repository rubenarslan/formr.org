<?php

class Ip_Item extends Item {

	public $type = 'ip';
	public $input_attributes = array('type' => 'hidden');
	public $mysql_field = 'VARCHAR (46) DEFAULT NULL';
	public $no_user_input_required = true;
	public $optional = 1;

	protected function setMoreOptions() {
		$this->input_attributes['value'] = $_SERVER["REMOTE_ADDR"];
	}

	public function getReply($reply) {
		return isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : null;
	}

	public function render() {
		return $this->render_input();
	}

}
