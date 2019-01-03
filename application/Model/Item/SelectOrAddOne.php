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

	public function setChoices($choices) {
		$this->choices = $choices;
		// Hack to split choices if comma separated and have only one element
		// ASSUMPTION: choices are not suppose to have commas (weirdo)
		$choice = current($this->choices);
		if (count($this->choices) == 1 && strpos($choice, ',') !== false) {
			$this->choices = explode(',', $choice);
		}
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
