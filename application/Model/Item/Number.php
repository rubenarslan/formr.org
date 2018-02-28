<?php

/**
 * @todo Description
 * - the min/max stuff is confusing and will fail for real big numbers
 * - spinbox is polyfilled in browsers that lack it
 */

class Number_Item extends Item {

	public $type = 'number';
	public $input_attributes = array('type' => 'number', 'min' => 0, 'max' => 10000000, 'step' => 1);
	public $mysql_field = 'INT UNSIGNED DEFAULT NULL';

	protected function setMoreOptions() {
		$this->classes_input[] = 'form-control';
		if (isset($this->type_options) && trim($this->type_options) != "") {
			$this->type_options_array = explode(",", $this->type_options, 3);

			$min = trim(reset($this->type_options_array));
			if (is_numeric($min) OR $min === 'any') {
				$this->input_attributes['min'] = $min;
			}

			$max = trim(next($this->type_options_array));
			if (is_numeric($max) OR $max === 'any') {
				$this->input_attributes['max'] = $max;
			}

			$step = trim(next($this->type_options_array));
			if (is_numeric($step) OR $step === 'any') {
				$this->input_attributes['step'] = $step;
			}
		}

		$multiply = 2;
		if ($this->input_attributes['min'] < 0) :
			$this->mysql_field = str_replace("UNSIGNED", "", $this->mysql_field);
			$multiply = 1;
		endif;

		if ($this->input_attributes['step'] === 'any' OR $this->input_attributes['min'] === 'any' OR $this->input_attributes['max'] === 'any'): // is any any
			$this->mysql_field = str_replace(array("INT"), "FLOAT", $this->mysql_field); // use FLOATing point accuracy
		else:
			if (
					(abs($this->input_attributes['min']) < ($multiply * 127) ) && ( abs($this->input_attributes['max']) < ($multiply * 127) )
			):
				$this->mysql_field = preg_replace("/^INT\s/", "TINYINT ", $this->mysql_field);
			elseif (
					(abs($this->input_attributes['min']) < ($multiply * 32767) ) && ( abs($this->input_attributes['max']) < ($multiply * 32767) )
			):
				$this->mysql_field = preg_replace("/^INT\s/", "SMALLINT ", $this->mysql_field);
			elseif (
					(abs($this->input_attributes['min']) < ($multiply * 8388608) ) && ( abs($this->input_attributes['max']) < ($multiply * 8388608) )
			):
				$this->mysql_field = preg_replace("/^INT\s/", "MEDIUMINT ", $this->mysql_field);
			elseif (
					(abs($this->input_attributes['min']) < ($multiply * 2147483648) ) && ( abs($this->input_attributes['max']) < ($multiply * 2147483648) )
			):
				$this->mysql_field = str_replace("INT", "INT", $this->mysql_field);
			elseif (
					(abs($this->input_attributes['min']) < ($multiply * 9223372036854775808) ) && ( abs($this->input_attributes['max']) < ($multiply * 9223372036854775808) )
			):
				$this->mysql_field = preg_replace("/^INT\s/", "BIGINT ", $this->mysql_field);
			endif;

			// FIXME: why not use is_int()? why casting to int before strlen?
			if ((string) (int) $this->input_attributes['step'] != $this->input_attributes['step']): // step is integer?
				$before_point = max(strlen((int) $this->input_attributes['min']), strlen((int) $this->input_attributes['max'])); // use decimal with this many digits
				$after_point = strlen($this->input_attributes['step']) - 2;
				$d = $before_point + $after_point;

				$this->mysql_field = str_replace(array("TINYINT", "SMALLINT", "MEDIUMINT", "INT", "BIGINT"), "DECIMAL($d, $after_point)", $this->mysql_field);
			endif;
		endif;
	}

	public function validateInput($reply) { // fixme: input is not re-displayed after this
		$reply = trim(str_replace(",", ".", $reply));
		if (!$reply && $reply !== 0 && $this->optional) {
			return null;
		}

		if ($this->input_attributes['min'] !== 'any' && $reply < $this->input_attributes['min']) { // lower number than allowed
			$this->error = __("The minimum is %d.", $this->input_attributes['min']);
		} elseif ($this->input_attributes['max'] !== 'any' && $reply > $this->input_attributes['max']) { // larger number than allowed
			$this->error = __("The maximum is %d.", $this->input_attributes['max']);
		} elseif ($this->input_attributes['step'] !== 'any' AND
				abs(
						(round($reply / $this->input_attributes['step']) * $this->input_attributes['step'])  // divide, round and multiply by step
						- $reply // should be equal to reply
				) > 0.000000001 // with floats I have to leave a small margin of error
		) {
			$this->error = __("Numbers have to be in steps of at least %d.", $this->input_attributes['step']);
		}

		return parent::validateInput($reply);
	}

	public function getReply($reply) {
		$reply = trim(str_replace(",", ".", $reply));
		if (!$reply && $reply !== 0 && $this->optional) {
			return null;
		}
		return $reply;
	}

}
