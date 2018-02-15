<?php

/*
 * Single checkbox
 */

class Check_Item extends McMultiple_Item {

	public $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	public $choice_list = NULL;
	protected $hasChoices = false;

	protected function setMoreOptions() {
		parent::setMoreOptions();
		$this->input_attributes['name'] = $this->name;
	}

	protected function render_label() {
		$template = '<label class="%{class}" for="item%{id}_1">%{error} %{text} </label>';

		return Template::replace($template, array(
			'class' => implode(' ', $this->classes_label),
			'error' => $this->render_error_tip(),
			'text' => $this->label_parsed,
			'id' => $this->id,
		));
	}

	public function validateInput($reply) {
		if (!in_array($reply, array(0, 1))) {
			$this->error = __("You chose an option '%s' that is not permitted.", h($reply));
		}
		$reply = parent::validateInput($reply);
		return $reply ? 1 : 0;
	}

	public function getReply($reply) {
		return $reply ? 1 : 0;
	}

	protected function render_input() {
		if (!empty($this->input_attributes['value']) || !empty($this->value_validated)) {
			$this->input_attributes['checked'] = true;
		} else {
			$this->input_attributes['checked'] = false;
		}
		unset($this->input_attributes['value']);
		
		$template = '
			<div class="checkbox">
				<input type="hidden" value="" id="item%{id}_" %{attributes} />
				<label class="%{class}" for="%{id}"><input id="item%{id}_1" value="1" %{input_attributes} /></label>
			</div>
		';

		return Template::replace($template, array(
			'id' => $this->id,
			'class' => $this->js_hidden ? ' js_hidden' : '',
			'attributes' => self::_parseAttributes($this->input_attributes, array('id', 'type', 'required')),
			'input_attributes' => self::_parseAttributes($this->input_attributes, array('id')),
			
		));
	}

}
