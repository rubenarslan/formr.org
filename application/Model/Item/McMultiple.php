<?php

/**
 *  Multiple multiple choice, including Checkboxes
 * 
 */
class McMultiple_Item extends Mc_Item {

    public $type = 'mc_multiple';
    public $input_attributes = array('type' => 'checkbox');
    public $optional = 1;
    public $mysql_field = 'VARCHAR(40) DEFAULT NULL';

    protected function setMoreOptions() {
        $this->input_attributes['name'] = $this->name . '[]';
    }

    protected function chooseResultFieldBasedOnChoices() {
        $choices = array_keys($this->choices);
        $max = implode(", ", array_filter($choices));
        $maxlen = strlen($max);
        $this->mysql_field = 'VARCHAR (' . $maxlen . ') DEFAULT NULL';
    }

    protected function render_input() {
        if (!$this->optional) {
            $this->input_attributes['data-grouprequired'] = '';
        }
        $this->splitValues();

        $ret = '
			<div class="mc-table %{js_hidden}">
				<input type="hidden" value="" id="item%{id}_" %{input_attributes} />
		';

        if (!$this->optional) {
            // this is a kludge, but if I don't add this, checkboxes are always circled red
            $ret .= '<input class="hidden" value="" id="item%{id}__" %{input_attributes_} />';
        }

        if ($this->value_validated) {
            $this->presetValues[] = $this->value_validated;
        }

        foreach ($this->choices AS $value => $option) {
            // determine whether options needs to be checked
            if (in_array($value, $this->presetValues)) {
                $this->input_attributes['checked'] = true;
            } else {
                $this->input_attributes['checked'] = false;
            }

            $label = '
				<label class="checkbox-inline" for="item%{id}_%{value}">
					<input id="item%{id}_%{value}" value="%{value}" %{input_attributes} /> %{option}
				</label>
			';
            $label = Template::replace($label, array(
                        'id' => $this->id,
                        'value' => $value,
                        'option' => $option,
                        'input_attributes' => self::_parseAttributes($this->input_attributes, array('id', 'required', 'data-grouprequired')),
            ));

            if (in_array('mc_vertical', $this->classes_wrapper)) {
                $ret .= '<div class="checkbox">' . $label . '</div>';
            } else {
                $ret .= $label;
            }
        }

        $ret .= '</div>';

        return Template::replace($ret, array(
                    'js_hidden' => $this->js_hidden ? ' js_hidden' : '',
                    'id' => $this->id,
                    'input_attributes' => self::_parseAttributes($this->input_attributes, array('id', 'type', 'required', 'data-grouprequired')),
                    'input_attributes_' => self::_parseAttributes($this->input_attributes, array('class', 'id', 'required')),
        ));
    }

    public function getReply($reply) {
        if (is_array($reply)) {
            $reply = implode(", ", array_filter($reply));
        }
        return $reply;
    }

}
