<?php

/**
 * Radio Buttons
 */
class Mc_Item extends Item {

    public $type = 'mc';
    public $lower_text = '';
    public $upper_text = '';
    public $input_attributes = array('type' => 'radio');
    public $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
    protected $hasChoices = true;

    public function validateInput($reply) {
        if (!($this->optional && $reply == '') && !empty($this->choices) && // check
                ( is_string($reply) && !in_array($reply, array_keys($this->choices)) ) || // mc
                ( is_array($reply) && ($diff = array_diff($reply, array_keys($this->choices))) && !empty($diff) && current($diff) !== '' ) // mc_multiple
        ) { // invalid multiple choice answer 
            if (isset($diff)) {
                $problem = $diff;
            } else {
                $problem = $reply;
            }

            if (is_array($problem)) {
                $problem = implode("', '", $problem);
            }

            $this->error = __("You chose an option '%s' that is not permitted.", h($problem));
        }
        return parent::validateInput($reply);
    }

    protected function render_label() {
        $template = '<label class="%{class}">%{error} %{text} </label>';

        return Template::replace($template, array(
                    'class' => implode(' ', $this->classes_label),
                    'error' => $this->render_error_tip(),
                    'text' => $this->label_parsed,
        ));
    }

    protected function render_input() {

        $this->splitValues();

        $ret = '
			<div class="mc-table %{js_hidden}">
				<input type="hidden" value="" id="item%{id}_" %{input_attributes} />
		';

        $opt_values = array_count_values($this->choices);
        if (isset($opt_values['']) && /* $opt_values[''] > 0 && */ current($this->choices) != '') { // and the first option isn't empty
            $this->label_first = true;  // the first option label will be rendered before the radio button instead of after it.
        } else {
            $this->label_first = false;
        }

        if (mb_strpos(implode(' ', $this->classes_wrapper), 'mc-first-left') !== false) {
            $this->label_first = true;
        }
        $all_left = false;
        if (mb_strpos(implode(' ', $this->classes_wrapper), 'mc-all-left') !== false) {
            $all_left = true;
        }

        if ($this->value_validated) {
            $this->presetValues[] = $this->value_validated;
        }

        foreach ($this->choices as $value => $option) {
            // determine whether options needs to be checked
            if (in_array($value, $this->presetValues)) {
                $this->input_attributes['checked'] = true;
            } else {
                $this->input_attributes['checked'] = false;
            }

            $label = '
				<label class="radio-inline" for="item%{id}_%{value}">
					%{left_span}
					<input id="item%{id}_%{value}" value="%{value}" %{input_attributes} />
					%{right_span}
				</label>
			';
            $label = Template::replace($label, array(
                        'id' => $this->id,
                        'value' => $value,
                        'input_attributes' => self::_parseAttributes($this->input_attributes, array('id')),
                        'left_span' => ($this->label_first || $all_left) ? '<span class="mc-span">' . $option . '</span>' : '',
                        'right_span' => ($this->label_first || $all_left) ? '<span>&nbsp;</span>' : ' <span class="mc-span">' . $option . '</span>'
            ));

            if (in_array('mc_vertical', $this->classes_wrapper)) {
                $ret .= '<div class="radio">' . $label . '</div>';
            } else {
                $ret .= $label;
            }

            if ($this->label_first) {
                $this->label_first = false;
            }
        }

        $ret .= '</div>';

        return Template::replace($ret, array(
            'js_hidden' => $this->js_hidden ? ' js_hidden' : '',
            'id' => $this->id,
            'input_attributes' => self::_parseAttributes($this->input_attributes, array('type', 'id', 'required')),
        ));
    }

}
