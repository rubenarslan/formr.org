<?php

/**
 * email is a special HTML5 type, validation is polyfilled in browsers that lack it
 */
class Email_Item extends Text_Item {

	public $type = 'email';
	public $input_attributes = array('type' => 'email', 'maxlength' => 255);
	public $mysql_field = 'VARCHAR(255) DEFAULT NULL';

	protected $prepend = 'fa-envelope';

	public function validateInput($reply) {
		if ($this->optional && trim($reply) == '') {
			return parent::validateInput($reply);
		} else {
			$reply_valid = filter_var($reply, FILTER_VALIDATE_EMAIL);
			if (!$reply_valid) {
				$this->error = __('The email address %s is not valid', h($reply));
			}
		}

		return $reply_valid;
	}

}
