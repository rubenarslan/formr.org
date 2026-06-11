<?php

class Year_Item extends Datetime_Item {

    public $type = 'year';
    // `type="year"` isn't a real HTML type — browsers fall back to text, which
    // shows the alpha keyboard on mobile. inputmode=numeric restores the digit
    // pad without changing the (text) field's validation behaviour.
    public $input_attributes = array('type' => 'year', 'inputmode' => 'numeric');
    public $mysql_field = 'YEAR DEFAULT NULL';
    protected $html5_date_format = 'Y';
    protected $prepend = 'fa-calendar-o';

    protected function setMoreOptions() {
        // Let Datetime_Item add form-control and any admin-supplied
        // type_options min/max (formatted via html5_date_format = 'Y').
        parent::setMoreOptions();

        // `type="year"` falls back to a plain text input, which carries no
        // native constraint — so v2's Constraint-Validation client (blur +
        // submit) had nothing to check and never flagged a bad year. Give it a
        // 4-digit pattern + length + range so it validates instantly, the same
        // way number/email do. Only fill defaults the admin didn't set.
        $this->input_attributes['pattern'] = '\\d{4}';
        $this->input_attributes['maxlength'] = 4;
        if (!isset($this->input_attributes['min'])) {
            $this->input_attributes['min'] = 1901; // MySQL YEAR lower bound
        }
        if (!isset($this->input_attributes['max'])) {
            $this->input_attributes['max'] = 2155; // MySQL YEAR upper bound
        }
    }

}
