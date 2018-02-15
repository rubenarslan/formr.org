<?php

class RatingButton_Item extends McButton_Item {

	public $mysql_field = 'SMALLINT DEFAULT NULL';
	public $type = "rating_button";
	private $step = 1;
	private $lower_limit = 1;
	private $upper_limit = 5;

	protected function setMoreOptions() {
		parent::setMoreOptions();
		$this->step = 1;
		$this->lower_limit = 1;
		$this->upper_limit = 5;

		if (isset($this->type_options_array) && is_array($this->type_options_array)) {
			if (count($this->type_options_array) == 1) {
				$this->type_options_array = explode(",", current($this->type_options_array));
			}

			if (count($this->type_options_array) == 1) {
				$this->upper_limit = (int) trim($this->type_options_array[0]);
			} elseif (count($this->type_options_array) == 2) {
				$this->lower_limit = (int) trim($this->type_options_array[0]);
				$this->upper_limit = (int) trim($this->type_options_array[1]);
			} elseif (count($this->type_options_array) == 3) {
				$this->lower_limit = (int) trim($this->type_options_array[0]);
				$this->upper_limit = (int) trim($this->type_options_array[1]);
				$this->step = (int) trim($this->type_options_array[2]);
			}
		}

		/**
		 * For obvious reason $this->choices can still be empty at this point (if user doesn't have choice1, choice2 columns but used a choice_list instead)
		 * So get labels from choice list which should be gotten from last item in options array
		 */
		// force step to be a non-zero positive number less than or equal to upper limit
		if ($this->step <= 0 || $this->step > $this->upper_limit) {
			$this->step = $this->upper_limit;
		}

		if ($this->upper_limit >= $this->lower_limit + $this->step) {
			$choices = array($this->lower_limit, $this->upper_limit);
		} else {
			$choices = range($this->lower_limit, $this->upper_limit, $this->step);
		}
		$this->choices = array_combine($choices, $choices);
	}

	public function setChoices($choices) {
		$this->lower_text = current($choices);
		$this->upper_text = next($choices);
	}

	protected function render_input() {

		$this->splitValues();
		$tpl = '
			<input type="hidden" value="" id="item%{id}_" %{input_attributes} />
			<label class="keep-label">%{lower_text}</label>
			<span class="js_hidden">
				%{labels}
			</span>
		';

		if ($this->value_validated) {
			$this->presetValues[] = $this->value_validated;
		}

		$labels = '';
		foreach ($this->choices as $option) {
			// determine whether options needs to be checked
			if (in_array($option, $this->presetValues)) {
				$this->input_attributes['checked'] = true;
			} else {
				$this->input_attributes['checked'] = false;
			}

			$label = '<label for="item%{id}_%{option}"><input value="%{option}" id="item%{id}_%{option}" %{input_attributes} /> %{option} </label> ';
			$labels .= Template::replace($label, array(
				'id' => $this->id,
				'option' => $option,
				'input_attributes' => self::_parseAttributes($this->input_attributes, array('id')),
			));
		}

		return Template::replace($tpl, array(
			'id' => $this->id,
			'lower_text' => $this->lower_text,
			'labels' => $labels,
			'input_attributes' => self::_parseAttributes($this->input_attributes, array('type', 'id', 'required')),
		));
	}

	protected function render_appended() {
		$ret = parent::render_appended();
		$ret .= " <label class='keep-label'> {$this->upper_text}</label>";

		return $ret;
	}
}
