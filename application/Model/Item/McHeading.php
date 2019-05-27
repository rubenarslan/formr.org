<?php

class McHeading_Item extends Mc_Item {

    public $type = 'mc_heading';
    public $mysql_field = null;
    public $save_in_results_table = false;

    protected function setMoreOptions() {
        $this->input_attributes['disabled'] = 'disabled';
    }

    protected function render_label() {
        $template = '
			<div class="%{class}">
				%{error} %{text}
				<input type="hidden" name="%{name}" value="1" />
			</div>
		';

        return Template::replace($template, array(
            'class' => implode(' ', $this->classes_label),
            'error' => $this->render_error_tip(),
            'text' => $this->label_parsed,
            'name' => $this->name,
        ));
    }

    protected function render_input() {
        $ret = '<div class="mc-table">';

        $this->input_attributes['type'] = 'radio';
        $opt_values = array_count_values($this->choices);
        if (isset($opt_values['']) && /* // if there are empty options $opt_values[''] > 0 && */ current($this->choices) != '') { // and the first option isn't empty
            $this->label_first = true;  // the first option label will be rendered before the radio button instead of after it.
        } else {
            $this->label_first = false;
        }

        if (mb_strpos(implode(" ", $this->classes_wrapper), 'mc_first_left') !== false) {
            $this->label_first = true;
        }
        $all_left = false;
        if (mb_strpos(implode(" ", $this->classes_wrapper), 'mc_all_left') !== false) {
            $all_left = true;
        }

        foreach ($this->choices as $value => $option) {
            $this->input_attributes['selected'] = $this->isSelectedOptionValue($value, $this->value_validated);
            $label = '
				<label for="item%{id}_%{value}">
					%{left_span}
					<input id="item%{id}_%{value}" value="%{value}" %{input_attributes} />
					%{right_span}
				</label>
			';
            $ret .= Template::replace($label, array(
                        'id' => $this->id,
                        'value' => $value,
                        'input_attributes' => self::_parseAttributes($this->input_attributes, array('id')),
                        'left_span' => ($this->label_first || $all_left) ? $option . '&nbsp;' : '',
                        'right_span' => ($this->label_first || $all_left) ? "&nbsp;" : ' ' . $option,
            ));
        }

        $ret .= '</div>';

        return $ret;
    }

}
