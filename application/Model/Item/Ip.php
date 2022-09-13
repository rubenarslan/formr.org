<?php

class Ip_Item extends Item {

    public $type = 'ip';
    public $input_attributes = array('type' => 'hidden');
    public $mysql_field = 'VARCHAR(46) DEFAULT NULL';
    public $no_user_input_required = true;
    public $optional = 1;

    protected function setMoreOptions() {
        $this->input_attributes['value'] = $this->getIp();
    }

    public function getReply($reply) {
        return $this->getIp();
    }

    public function render() {
        return $this->render_input();
    }
	
	protected function getIp() {
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$ip = env('REMOTE_ADDR');
		}

		return $ip;
	}

}
