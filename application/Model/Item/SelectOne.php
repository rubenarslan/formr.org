<?php

/**
 * Dropdown to choose one item
 */
class SelectOne_Item extends Item {

    public $type = 'select';
    public $mysql_field = 'TEXT DEFAULT NULL';
    public $input_attributes = array('type' => 'select');
    protected $hasChoices = true;

    protected function setMoreOptions() {
        $this->classes_input[] = "form-control";
    }

    protected function render_input() {
        $this->splitValues();
        $tpl = '
			<input type="hidden" value="" id="item%{id}_" %{input_attributes} />
			<select %{select_attributes}>
				%{empty_option}
				%{options}
			</select>
		';

        if ($this->value_validated) {
            $this->presetValues[] = $this->value_validated;
        }

        // Hack to split choices if comma separated and have only one element
        // ASSUMPTION: choices are not suppose to have commas (weirdo)
        $choice = current($this->choices);
        if (count($this->choices) == 1 && strpos($choice, ',') !== false) {
            $choices = explode(',', $choice);
            $this->choices = array_combine($choices, $choices);
        }

        $options = '';
        foreach ($this->choices as $value => $option) {
            // determine whether options needs to be checked
            $selected = '';
            if (in_array($value, $this->presetValues)) {
                $selected = ' selected="selected"';
            }
            $options .= sprintf('<option value="%s" %s >%s</option>', $value, $selected, $option);
        }

        return Template::replace($tpl, array(
                    'id' => $this->id,
                    'empty_option' => !isset($this->input_attributes['multiple']) ? '<option value=""> &nbsp; </option>' : '',
                    'options' => $options,
                    'input_attributes' => self::_parseAttributes($this->input_attributes, array('id', 'type', 'required', 'multiple')),
                    'select_attributes' => self::_parseAttributes($this->input_attributes, array('type')),
        ));
    }

    protected function chooseResultFieldBasedOnChoices() {
        if (count($this->choices) == count(array_filter($this->choices, 'is_numeric'))) {
            return parent::chooseResultFieldBasedOnChoices();
        }
    }

}
