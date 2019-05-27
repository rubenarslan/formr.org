<?php

class Range_Item extends Number_Item {

    public $type = 'range';
    public $input_attributes = array('type' => 'range', 'min' => 0, 'max' => 100, 'step' => 1);
    public $mysql_field = 'INT UNSIGNED DEFAULT NULL';
    protected $hasChoices = true;

    protected function setMoreOptions() {
        $this->lower_text = current($this->choices);
        $this->upper_text = next($this->choices);
        parent::setMoreOptions();

        $this->classes_input = array_diff($this->classes_input, array('form-control'));
    }

    protected function render_input() {
        if ($this->value_validated) {
            $this->input_attributes['value'] = $this->value_validated;
        }

        $tpl = '%{left_label} <input %{input_attributes} /> %{right_label}';

        return Template::replace($tpl, array(
                    'left_label' => $this->render_pad_label(1, 'right'),
                    'input_attributes' => self::_parseAttributes($this->input_attributes, array('required')),
                    'right_label' => $this->render_pad_label(2, 'left'),
        ));
    }

    private function render_pad_label($choice, $pad) {
        if (isset($this->choices[$choice])) {
            return sprintf('<label class="pad-%s keep-label">%s</label>', $pad, $this->choices[$choice]);
        }
        return '';
    }

}
