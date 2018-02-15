<?php

class Timezone_Item extends SelectOne_Item {

	public $mysql_field = 'VARCHAR(255)';
	public $choice_list = '*';

	protected function chooseResultFieldBasedOnChoices() {

	}

	protected function setMoreOptions() {
		$this->classes_input[] = 'select2zone';

		parent::setMoreOptions();
		$this->setChoices(array());
	}

	public function setChoices($choices) {
		$zonenames = timezone_identifiers_list();
		asort($zonenames);
		$zones = array();
		$offsets = array();
		foreach ($zonenames AS $zonename):
			$zone = timezone_open($zonename);
			$offsets[] = timezone_offset_get($zone, date_create());
			$zones[] = str_replace("/", " - ", str_replace("_", " ", $zonename));
		endforeach;
		$this->choices = $zones;
		$this->offsets = $offsets;
	}

	protected function render_input() {
		$tpl = '
			<select %{select_attributes}>
				%{empty_option}
				%{options}
			</select>
		';

		$options = '';
		foreach ($this->choices as $value => $option) {
			$selected = array('selected' => $this->isSelectedOptionValue($value, $this->value_validated));
			$options .= sprintf('<option value="%s" %s>%s</option>', $value, self::_parseAttributes($selected, array('type')), $option);
		}
		
		return Template::replace($tpl, array(
			'empty_option' => !isset($this->input_attributes['multiple']) ? '<option value=""> &nbsp; </option>' : '',
			'options' => $options,
			'select_attributes' => self::_parseAttributes($this->input_attributes, array('type')),
		));
	}

}
