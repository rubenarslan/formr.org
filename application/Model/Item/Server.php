<?php

class Server_Item extends Item {

	public $type = 'server';
	public $input_attributes = array('type' => 'hidden');
	public $mysql_field = 'TEXT DEFAULT NULL';
	public $no_user_input_required = true;
	public $optional = 1;
	private $get_var = 'HTTP_USER_AGENT';

	protected function setMoreOptions() {
		if (isset($this->type_options_array) && is_array($this->type_options_array)) {
			if (count($this->type_options_array) == 1) {
				$this->get_var = trim(current($this->type_options_array));
			}
		}
		$this->input_attributes['value'] = array_val($_SERVER, $this->get_var);
	}

	public function getReply($reply) {
		return $this->input_attributes['value'];
	}

	public function validate() {
		$vars = array(
			'HTTP_USER_AGENT',
			'HTTP_ACCEPT',
			'HTTP_ACCEPT_CHARSET',
			'HTTP_ACCEPT_ENCODING',
			'HTTP_ACCEPT_LANGUAGE',
			'HTTP_CONNECTION',
			'HTTP_HOST',
			'QUERY_STRING',
			'REQUEST_TIME',
			'REQUEST_TIME_FLOAT'
		);

		if (!in_array($this->get_var, $vars)) {
			$this->val_errors[] = __('The server variable %s with the value %s cannot be saved', $this->name, $this->get_var);
			return parent::validate();
		}

		return $this->val_errors;
	}

	public function render() {
		return $this->render_input();
	}

}
