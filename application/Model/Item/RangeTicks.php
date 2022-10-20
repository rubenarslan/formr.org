<?php

/**
 * Slider with ticks
 */
class RangeTicks_Item extends Number_Item {

    public $type = 'range_ticks';
    public $input_attributes = array('type' => 'range', 'step' => 1, 'min' => 0, 'max' => 100);
    protected $labels = array();
    protected $left_label = '';
    protected $right_labeel = '';
    protected $hasChoices = true;

    public function setChoices($choices) {
        $this->labels = $choices;
        $this->left_label = $this->render_pad_label(1, 'right');
        $this->right_labeel = $this->render_pad_label(2, 'left');
    }

    protected function setMoreOptions() {
        $this->input_attributes['min'] = 0;
        $this->input_attributes['max'] = 100;
        $this->input_attributes['list'] = 'dlist' . $this->id;
        $this->input_attributes['data-range'] = '{"animate": true, "classes": "show-activevaluetooltip"}';
        $this->classes_input[] = "range-list";

        $this->classes_wrapper[] = 'range_ticks_output';

        parent::setMoreOptions();
        $this->classes_input = array_diff($this->classes_input, array('form-control'));

        // Set actual choices based on defined range and step
        $this->choices = array();
        for ($i = $this->input_attributes['min']; $i <= $this->input_attributes['max']; $i = $i + $this->input_attributes['step']) {
            $this->choices[(string)$i] = $i;
        }
    }

    protected function render_input() {
        $tpl = '
			%{left_label}
			<input %{input_attributes} />
			<datalist id="dlist%{id}">
				<select class="">
					%{options}
				</select>
			</datalist>
			%{right_label}
		';

        $options = '';
        for ($i = $this->input_attributes['min']; $i <= $this->input_attributes['max']; $i = $i + $this->input_attributes['step']) {
            $options .= sprintf('<option value="%s">%s</option>', $i, $i);
        }

        return Template::replace($tpl, array(
                    'left_label' => $this->left_label,
                    'input_attributes' => self::_parseAttributes($this->input_attributes, array('required')),
                    'id' => $this->id,
                    'options' => $options,
                    'right_label' => $this->right_labeel,
        ));
    }

    private function render_pad_label($choice, $pad) {
        if (isset($this->labels[$choice])) {
            return sprintf('<label class="pad-%s keep-label">%s</label>', $pad, $this->labels[$choice]);
        }
        return '';
    }

}
