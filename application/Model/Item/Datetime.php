<?php

class Datetime_Item extends Item {

	public $type = 'datetime';
	public $input_attributes = array('type' => 'datetime');
	public $mysql_field = 'DATETIME DEFAULT NULL';
	protected $prepend = 'fa-calendar';
	protected $html5_date_format = 'Y-m-d\TH:i';

	protected function setMoreOptions() {
#		$this->input_attributes['step'] = 'any';
		$this->classes_input[] = 'form-control';

		if (isset($this->type_options) && trim($this->type_options) != "") {
			$this->type_options_array = explode(",", $this->type_options, 3);

			$min = trim(reset($this->type_options_array));
			if (strtotime($min)) {
				$this->input_attributes['min'] = date($this->html5_date_format, strtotime($min));
			}

			$max = trim(next($this->type_options_array));
			if (strtotime($max)) {
				$this->input_attributes['max'] = date($this->html5_date_format, strtotime($max));
			}
		}
	}

	public function validateInput($reply) {
		if (!($this->optional && $reply == '')) {

			$time_reply = strtotime($reply);
			if ($time_reply === false) {
				$this->error = _('You did not enter a valid date.');
			}

			if (isset($this->input_attributes['min']) && $time_reply < strtotime($this->input_attributes['min'])) { // lower number than allowed
				$this->error = __("The minimum is %d", $this->input_attributes['min']);
			} elseif (isset($this->input_attributes['max']) && $time_reply > strtotime($this->input_attributes['max'])) { // larger number than allowed
				$this->error = __("The maximum is %d", $this->input_attributes['max']);
			}
		}
		return parent::validateInput($reply);
	}

	public function getReply($reply) {
		if (!$reply) {
			return null;
		}
		$time_reply = strtotime($reply);
		return date($this->html5_date_format, $time_reply);
	}

}
