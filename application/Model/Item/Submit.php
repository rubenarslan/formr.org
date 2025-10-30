<?php

class Submit_Item extends Item {

    public $type = 'submit';
    public $input_attributes = array('type' => 'submit', 'value' => 1);
    public $mysql_field = null;
    public $save_in_results_table = false;

    protected function setMoreOptions() {
        $this->classes_input[] = 'btn';
        $this->classes_input[] = 'btn-lg';
        $this->classes_input[] = 'btn-info';
        if ($this->type_options !== NULL) {
            if (is_numeric($this->type_options)) {
                $this->input_attributes["data-timeout"] = $this->type_options;
                $this->classes_input[] = "submit_automatically_after_timeout";
            } elseif (trim(strtolower($this->type_options)) === 'auto') {
                $this->classes_input[] = "submit_on_all_answered";
            }
        }
        $this->input_attributes['value'] = $this->label_parsed;
    }

    protected function render_inner() {
        return Template::replace('<button %{input_attributes}>%{label}</button> %{time_out}', array(
                    'label' => $this->label_parsed,
                    'input_attributes' => self::_parseAttributes($this->input_attributes, array('required')),
                    'time_out' => isset($this->input_attributes["data-timeout"]) ? '<div class="white_cover"></div>' : '',
        ));
    }

}
