<?php

class SelectOrAddOne_Item extends Item {

    public $type = 'select_or_add_one';
    public $mysql_field = 'TEXT DEFAULT NULL';
    public $input_attributes = array('type' => 'text');
    protected $hasChoices = true;
    private $maxSelect = 0;
    private $maxType = 255;

    protected function setMoreOptions() {
        parent::setMoreOptions();

        if (isset($this->type_options) && trim($this->type_options) != "") {
            $this->type_options_array = explode(",", $this->type_options, 3);

            $this->maxType = trim(reset($this->type_options_array));
            if (!is_numeric($this->maxType)) {
                $this->maxType = 255;
            }

            if (count($this->type_options_array) > 1) {
                $this->maxSelect = trim(next($this->type_options_array));
            }
            if (!isset($this->maxSelect) || !is_numeric($this->maxSelect)) {
                $this->maxSelect = 0;
            }
        }

        $this->classes_input[] = 'select2add';
        $this->classes_input[] = 'form-control';
    }

    /**
     * A choice list written in one cell: a list whose single entry holds the
     * separator is that many choices. v2 separates with ";", the separator
     * of a multiple answer (items/tom-select.js), so a choice may hold a
     * comma; v1 separates with ",".
     *
     * @param array $choices the list as stored
     * @param bool $formV2 the study's engine
     * @return array
     */
    public static function unpackChoices(array $choices, $formV2) {
        $choice = (string) reset($choices);
        $separator = $formV2 ? ';' : ',';
        if (count($choices) !== 1 || strpos($choice, $separator) === false) {
            return $choices;
        }
        if (!$formV2) {
            return explode(',', $choice);
        }
        return array_values(array_filter(array_map('trim', explode(';', $choice)), 'strlen'));
    }

    public function setChoices($choices) {
        $this->choices = self::unpackChoices((array) $choices, $this->formV2);
        $for_select2 = array();

        foreach ($this->choices AS $option) {
            $for_select2[] = array('id' => $option, 'text' => $option);
        }

        $this->input_attributes['data-select2add'] = json_encode($for_select2, JSON_UNESCAPED_UNICODE);
        $this->input_attributes['data-select2maximum-selection-size'] = (int) $this->maxSelect;
        $this->input_attributes['data-select2maximum-input-length'] = (int) $this->maxType;
    }

    protected function chooseResultFieldBasedOnChoices() {
        
    }

// override parent
}
