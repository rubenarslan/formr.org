<?php

class ItemFactory {

	public $errors;
	public $showifs = array();
	public $openCPU_errors = array();
	private $choice_lists = array();
	private $used_choice_lists = array();

	function __construct($choice_lists) {
		$this->choice_lists = $choice_lists;
	}

	public function make($item) {
		$type = "";
		if (isset($item['type'])) {
			$type = $item['type'];
		}

		if (!empty($item['choice_list'])) { // if it has choices
			if (isset($this->choice_lists[$item['choice_list']])) { // if this choice_list exists
				$item['choices'] = $this->choice_lists[$item['choice_list']]; // take it
				$this->used_choice_lists[] = $item['choice_list']; // check it as used
			} else {
				$item['val_errors'] = array(__("Choice list %s does not exist, but is specified for item %s", $item['choice_list'], $item['name']));
			}
		}

		$type = str_replace('-', '_', $type);
		$class = $this->getItemClass($type);

		if (!class_exists($class, true)) {
			return false;
		}

		return new $class($item);
	}

	public function unusedChoiceLists() {
		return array_diff(
				array_keys($this->choice_lists), $this->used_choice_lists
		);
	}

	protected function getItemClass($type) {
		$parts = explode('_', $type);
		$parts = array_map('ucwords', $parts);
		$item = implode('', $parts);
		return $item . '_Item';
	}

}

/**
 * HTML Item
 * The default item is a text input, as many browser render any input type they don't understand as 'text'.
 * The base class should also work for inputs like date, datetime which are either native or polyfilled but don't require special handling here
 * 
 */

class Item {

	public $id = null;
	public $name = null;
	public $type = null;
	public $type_options = null;
	public $choice_list = null;
	public $label = null;
	public $label_parsed = null;
	public $optional = 0;
	public $class = null;
	public $showif = null;
	public $js_showif = null;
	public $value = null; // syntax for sticky value
	public $value_validated = null;
	public $order = null;
	public $block_order = null;
	public $item_order = null;
	public $displaycount = null;
	public $error = null;
	public $dont_validate = null;
	public $reply = null;
	public $val_errors = array();
	public $val_warnings = array();
	public $mysql_field = 'TEXT DEFAULT NULL';
	public $choices = array();
	public $hidden = false;
	public $no_user_input_required = false;
	public $save_in_results_table = true;
	public $input_attributes = array(); // so that the pre-set value can be set externally
	public $parent_attributes = array();
	public $presetValue = null;
	public $allowed_classes = array();
	public $skip_validation = false;
	public $data_showif = false;

	protected $prepend = null;
	protected $append = null;
	protected $type_options_array = array();
	protected $hasChoices = false;
	protected $classes_controls = array('controls');
	protected $classes_wrapper = array('form-group', 'form-row');
	protected $classes_input = array();
	protected $classes_label = array('control-label');
	protected $presetValues = array();
	protected $probably_render = null;
	protected $js_hidden = false;

	/**
	 * Minimized attributes
	 *
	 * @var array
	 */
	protected $_minimizedAttributes = array(
		'compact', 'checked', 'declare', 'readonly', 'disabled', 'selected',
		'defer', 'ismap', 'nohref', 'noshade', 'nowrap', 'multiple', 'noresize',
		'autoplay', 'controls', 'loop', 'muted', 'required', 'novalidate', 'formnovalidate'
	);

	/**
	 * Format to attribute
	 *
	 * @var string
	 */
	protected $_attributeFormat = '%s="%s"';

	/**
	 * Format to attribute
	 *
	 * @var string
	 */
	protected $_minimizedAttributeFormat = '%s="%s"';

	public function __construct($options = array()) {
		// Load options to object
		$optional = $this->optional;
		foreach ($options as $property => $value) {
			if (property_exists($this, $property)) {
				$this->{$property} = $value;
			}
		}

		// Assign needed defaults
		$this->id = isset($options['id']) ? $options['id'] : 0;
		$this->label = isset($options['label']) ? $options['label'] : '';
		$this->label_parsed = isset($options['label_parsed']) ? $options['label_parsed'] : null;
		$this->allowed_classes = Config::get('css_classes', array());

		if (isset($options['type_options'])) {
			$this->type_options = trim($options['type_options']);
			$this->type_options_array = array($options['type_options']);
		}

		if (empty($this->choice_list) && $this->hasChoices && $this->type_options != '') {
			$lc = explode(' ', trim($this->type_options));
			if (count($lc) > 1) {
				$this->choice_list = end($lc);
			}
		}

		if (!empty($options['val_error'])) {
			$this->val_error = $options['val_error'];
		}

		if (!empty($options['error'])) {
			$this->error = $options['error'];
			$this->classes_wrapper[] = "has-error";
		}

		if (isset($options['displaycount']) && $options['displaycount'] !== null) {
			$this->displaycount = $options['displaycount'];
		}

		$this->input_attributes['name'] = $this->name;

		$this->setMoreOptions();

		// after the easily overridden setMoreOptions, some post-processing that is universal to all items.
		if ($this->type == 'note') {
			// notes can not be "required"
			unset($options['optional']);
		}

		if (isset($options['optional']) && $options['optional']) {
			$this->optional = 1;
			unset($options['optional']);
		} elseif (isset($options['optional']) && !$options['optional']) {
			$this->optional = 0;
		} else {
			$this->optional = $optional;
		}

		if (!$this->optional) {
			$this->classes_wrapper[] = 'required';
			$this->input_attributes['required'] = 'required';
		} else {
			$this->classes_wrapper[] = 'optional';
		}

		if (!empty($options['class'])) {
			$this->classes_wrapper = array_merge($this->classes_wrapper, explode(' ', $options['class']));
			$this->class = $options['class'];
		}

		$this->classes_wrapper[] = 'item-' . $this->type;

		if (!isset($this->input_attributes['type'])) {
			$this->input_attributes['type'] = $this->type;
		}

		$this->input_attributes['class'] = implode(' ', $this->classes_input);

		$this->input_attributes['id'] = "item{$this->id}";

		if (in_array('label_as_placeholder', $this->classes_wrapper)) {
			$this->input_attributes['placeholder'] = $this->label;
		}

		if ($this->showif) {
			// primitive R to JS translation
			$this->js_showif = preg_replace("/current\(\s*(\w+)\s*\)/", "$1", $this->showif); // remove current function
			$this->js_showif = preg_replace("/tail\(\s*(\w+)\s*, 1\)/", "$1", $this->js_showif); // remove current function, JS evaluation is always in session			
			// all other R functions may break
			$this->js_showif = preg_replace("/(^|[^&])(\&)([^&]|$)/", "$1&$3", $this->js_showif); // & operators, only single ones need to be doubled
			$this->js_showif = preg_replace("/(^|[^|])(\|)([^|]|$)/", "$1|$3", $this->js_showif); // | operators, only single ones need to be doubled
			$this->js_showif = preg_replace("/FALSE/", "false", $this->js_showif); // uppercase, R, FALSE, to lowercase, JS, false
			$this->js_showif = preg_replace("/TRUE/", "true", $this->js_showif); // uppercase, R, TRUE, to lowercase, JS, true
			$quoted_string = "([\"'])((\\\\{2})*|(.*?[^\\\\](\\\\{2})*))\\1";
			$this->js_showif = preg_replace("/\s*\%contains\%\s*" . $quoted_string . "/", ".toString().indexOf($1$2$1) > -1", $this->js_showif);
			$this->js_showif = preg_replace("/\s*\%contains_word\%\s*" . $quoted_string . "/", ".toString().match(/\\b$2\\b/) !== null", $this->js_showif);
			$this->js_showif = preg_replace("/\s*\%begins_with\%\s*" . $quoted_string . "/", ".toString().indexOf($1$2$1) === 0", $this->js_showif);
			$this->js_showif = preg_replace("/\s*\%starts_with\%\s*" . $quoted_string . "/", ".toString().indexOf($1$2$1) === 0", $this->js_showif);
			$this->js_showif = preg_replace("/\s*\%ends_with\%\s*" . $quoted_string . "/", ".toString().endsWith($1$2$1)", $this->js_showif);
			$this->js_showif = preg_replace("/\s*stringr::str_length\(([a-zA-Z0-9_'\"]+)\)/", "$1.length", $this->js_showif);

			if (strstr($this->showif, "//js_only") !== false) {
				$this->setVisibility(array(null));
			}
		}
	}

	public function refresh($options = array(), $properties = array()) {
		foreach ($properties as $property) {
			if (property_exists($this, $property) && isset($options[$property])) {
				$this->{$property} = $options[$property];
			}
		}

		$this->setMoreOptions();
		$this->classes_wrapper = array_merge($this->classes_wrapper, array('item-' . $this->type));
		return $this;
	}

	public function hasBeenRendered() {
		return $this->displaycount !== null;
	}

	public function hasBeenViewed() {
		return $this->displaycount > 0;
	}

	protected function chooseResultFieldBasedOnChoices() {
		if ($this->mysql_field == null) {
			return;
		}
		$choices = array_keys($this->choices);

		$len = count($choices);
		if ($len == count(array_filter($choices, 'is_numeric'))) {
			$this->mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';

			$min = isset($this->input_attributes['min']) ? $this->input_attributes['min'] : min($choices);
			$max = isset($this->input_attributes['max']) ? $this->input_attributes['max'] : max($choices);

			if ($min < 0) {
				$this->mysql_field = str_replace('UNSIGNED ', '', $this->mysql_field);
			}

			if (abs($min) > 32767 OR abs($max) > 32767) {
				$this->mysql_field = str_replace("TINYINT", "MEDIUMINT", $this->mysql_field);
			} elseif (abs($min) > 126 OR abs($min) > 126) {
				$this->mysql_field = str_replace("TINYINT", "SMALLINT", $this->mysql_field);
			} elseif (count(array_filter($choices, "is_float"))) {
				$this->mysql_field = str_replace("TINYINT", "FLOAT", $this->mysql_field);
			}
		} else {
			$lengths = array_map("strlen", $choices);
			$maxlen = max($lengths);
			$this->mysql_field = 'VARCHAR (' . $maxlen . ') DEFAULT NULL';
		}
	}

	public function isStoredInResultsTable() {
		return $this->save_in_results_table;
	}

	public function getResultField() {
		if (!empty($this->choices)) {
			$this->chooseResultFieldBasedOnChoices();
		}

		if ($this->mysql_field !== null){
			return "`{$this->name}` {$this->mysql_field}";
		} else {
			return null;
		}
	}

	public function validate() {
		if (!$this->hasChoices && ($this->choice_list !== null || count($this->choices))) {
			$this->val_errors[] = "'{$this->name}' You defined choices for this item, even though this type doesn't have choices.";
		} elseif ($this->hasChoices && ($this->choice_list === null && count($this->choices) === 0) && $this->type !== "select_or_add_multiple") {
			$this->val_errors[] = "'{$this->name}' You forgot to define choices for this item.";
		} elseif ($this->hasChoices && count(array_unique($this->choices)) < count($this->choices)) {
			$dups = implode(array_diff_assoc($this->choices, array_unique($this->choices)), ", ");
			$this->val_errors[] = "'{$this->name}' You defined duplicated choices (" . h($dups) . ") for this item.";
		}

		if (!preg_match('/^[A-Za-z][A-Za-z0-9_]+$/', $this->name)) {
			$this->val_errors[] = "'{$this->name}' The variable name can contain <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore. It needs to start with a letter. You cannot use spaces, dots, or dashes.";
		}

		if (trim($this->type) == '') {
			$this->val_errors[] = "{$this->name}: The type column must not be empty.";
		}

		$defined_classes = array_map('trim', explode(" ", $this->class));
		$missing_classes = array_diff($defined_classes, $this->allowed_classes);
		if (count($missing_classes) > 0) {
			$this->val_warnings[] = "'{$this->name}' You used CSS classes that aren't part of the standard set (but maybe you defined them yourself): " . implode(", ", $missing_classes);
		}

		return array('val_errors' => $this->val_errors, 'val_warnings' => $this->val_warnings);
	}

	public function validateInput($reply) {
		$this->reply = $reply;

		if (!$this->optional && (($reply === null || $reply === false || $reply === array() || $reply === '') || (is_array($reply) && count($reply) === 1 && current($reply) === ''))) {
			// missed a required field
			$this->error = __("You missed entering some required information");
		} elseif ($this->optional && $reply == '') {
			$reply = null;
		}
		return $reply;
	}

	protected function setMoreOptions() {
		
	}

	protected function render_label() {
		$template = '<label class="%{class}" for="item%{id}">%{error} %{text} </label>';

		return Template::replace($template, array(
			'class' => implode(' ', $this->classes_label),
			'error' => $this->render_error_tip(),
			'text' => $this->label_parsed,
			'id' => $this->id,
		));
	}

	protected function render_prepended() {
		$template = $this->prepend ? '<span class="input-group-addon"><i class="fa fa-fw %s"></i></span>' : '';
		return sprintf($template, $this->prepend);
	}

	protected function render_input() {
		if ($this->value_validated !== null) {
			$this->input_attributes['value'] = $this->value_validated;
		}

		return sprintf('<span><input %s /></span>', self::_parseAttributes($this->input_attributes));
	}

	protected function render_appended() {
		$template = $this->append ? '<span class="input-group-addon"><i class="%s"></i></span>' : '';
		return sprintf($template, $this->append);
	}

	protected function render_inner() {
		$template = $this->render_label();
		$template .= '
			<div class="%{classes_controls}">
				<div class="controls-inner">
					%{input_group_open}
						%{prepended} %{input} %{appended}
					%{input_group_close}
				</div>
			</div>
		';
 
		$inputgroup = isset($this->prepend) || isset($this->append);
		return Template::replace($template, array(
			'classes_controls' => implode(' ', $this->classes_controls),
			'input_group_open' => $inputgroup ? '<div class="input-group">' : '',
			'input_group_close' => $inputgroup ? '</div>' : '',
			'prepended' => $this->render_prepended(),
			'input' => $this->render_input(),
			'appended' => $this->render_appended(),
		));
	}

	protected function render_item_view_input() {
		$template = '
			<span class="item-view-inputs">
				<input class="item_shown" type="hidden" name="_item_views[shown][%{id}]" />
				<input class="item_shown_relative" type="hidden" name="_item_views[shown_relative][%{id}]" />
				<input class="item_answered" type="hidden" name="_item_views[answered][%{id}]" />
				<input class="item_answered_relative" type="hidden" name="_item_views[answered_relative][%{id}]" />
			</span>
		';
		return Template::replace($template, array('id' => $this->id));
	}

	public function render() {
		if ($this->error) {
			$this->classes_wrapper[] = "has-error";
		}
		$this->classes_wrapper = array_unique($this->classes_wrapper);
		if ($this->data_showif) {
			$this->parent_attributes['data-showif'] = $this->js_showif;
		}
		$template = '
			<div class="%{classes_wrapper}" %{parent_attributes}>
				%{item_content}
				<div class="hidden_debug_message hidden item_name">
					<span class="badge hastooltip" title="%{title}">%{name}</span>
				</div>
			</div>
		';
		return Template::replace($template, array(
			'classes_wrapper' => implode(' ', $this->classes_wrapper),
			'parent_attributes' => self::_parseAttributes($this->parent_attributes),
			'item_content' => $this->render_inner() . $this->render_item_view_input(),
			'title' => h($this->js_showif),
			'name' => h($this->name),
		));
	}

	public function render_error_tip() {
		$format = $this->error ? '<span class="label label-danger hastooltip" title="%s"><i class="fa fa-exclamation-triangle"></i></span> ' : '';
		return sprintf($format, h($this->error));
	}

	protected function splitValues() {
		if (isset($this->value_validated)) {
			if (is_array($this->value_validated)) {
				$this->presetValues = $this->value_validated;
			} else {
				$this->presetValues = array_map("trim", explode(",", $this->value_validated));
			}
			unset($this->input_attributes['value']);
		} elseif (isset($this->input_attributes['value'])) {
			$this->presetValues[] = $this->input_attributes['value'];
		} else {
			$this->presetValues = array();
		}
	}

	public function hide() {
		if (!$this->hidden) {
			$this->classes_wrapper[] = "hidden";
			$this->data_showif = true;
			$this->input_attributes['disabled'] = true; ## so it isn't submitted or validated
			$this->hidden = true; ## so it isn't submitted or validated
		}
	}

	public function alwaysInvalid() {
		$this->error = _('There were problems with openCPU.');
		if (!isset($this->input_attributes['class'])) {
			$this->input_attributes['class'] = '';
		}
		$this->input_attributes['class'] .= " always_invalid";
	}

	public function needsDynamicLabel($survey = null) {
		return $this->label_parsed === null;
	}

	public function getShowIf() {
		if (strstr($this->showif, "//js_only") !== false) {
			return "NA";
		}

		if ($this->hidden !== null) {
			return false;
		}
		if (trim($this->showif) != "") {
			return $this->showif;
		}
		return false;
	}

	public function needsDynamicValue() {
		$this->value = trim($this->value);
		if (!(is_formr_truthy($this->value))) {
			$this->presetValue = null;
			return false;
		}
		if (is_numeric($this->value)) {
			$this->input_attributes['value'] = $this->value;
			return false;
		}

		return true;
	}

	public function evaluateDynamicValue(Survey $survey) {
		
	}

	public function getValue(Survey $survey = null) {
		if ($survey && $this->value === 'sticky') {
			$this->value = "tail(na.omit({$survey->name}\${$this->name}),1)";
		}
		return trim($this->value);
	}

	/**
	 * Set the visibility of an item based on show-if results returned from opencpu
	 * $showif_result Can be an array or an interger value returned by ocpu. If a non-empty array then $showif_result[0] can have the following values
	 * - NULL if the variable in $showif is Not Avaliable,
	 * - TRUE if it avalaible and true,
	 * - FALSE if it avalaible and not true
	 * - An empty array if a problem occured with opencpu
	 *
	 * @param array|int $showif_result
	 * @return null
	 */
	public function setVisibility($showif_result) {
		if (!$showif_result) {
			return true;
		}

		$result = true;
		if (is_array($showif_result) && array_key_exists(0, $showif_result)) {
			$result = $showif_result[0];
		} elseif ($showif_result === array()) {
			notify_user_error("You made a mistake, writing a showif <code class='r hljs'>" . $this->showif . "</code> that returns an element of length 0. The most common reason for this is to e.g. refer to data that does not exist. Valid return values for a showif are TRUE, FALSE and NULL/NA.", " There are programming problems in this survey.");
			$this->alwaysInvalid();
			$this->error = _('Incorrectly defined showif.');
		}

		if (!$result) {
			$this->hide();
			$this->probably_render = false;
		}
		// null means we can't determine clearly if item should be visible or not
		if ($result === null) {
			$this->probably_render = true;
		}
		return $result;
	}

	/**
	 * Set the dynamic value computed on opencpu
	 *
	 * @param mixed $value Value
	 * @return null
	 */
	public function setDynamicValue($value) {
		if (!$value) {
			return;
		}

		$currentValue = $this->getValue();
		if ($value === array()) {
			notify_user_error("You made a mistake, writing a dynamic value <code class='r hljs'>" . h($currentValue) . "</code> that returns an element of length 0. The most common reason for this is to e.g. refer to data that does not exist, e.g. misspell an item. Valid values need to have a length of one.", " There are programming problems related to zero-length dynamic values in this survey.");
			$this->openCPU_errors[$value] = _('Incorrectly defined value (zero length).');
			$this->alwaysInvalid();
			$value = null;
		} elseif ($value === null) {
			notify_user_error("You made a mistake, writing a dynamic value <code class='r hljs'>" . h($currentValue) . "</code> that returns NA (missing). The most common reason for this is to e.g. refer to data that is not yet set, i.e. referring to questions that haven't been answered yet. To circumvent this, add a showif to your item, checking whether the item is answered yet using is.na(). Valid values need to have a length of one.", " There are programming problems related to null dynamic values in this survey.");
			$this->openCPU_errors[$value] = _('Incorrectly defined value (null).');
			$this->alwaysInvalid();
		} elseif (is_array($value) && array_key_exists(0, $value)) {
			$value = $value[0];
		}

		$this->input_attributes['value'] = $value;
	}

	public function getComputedValue() {
		if (isset($this->input_attributes['value'])) {
			return $this->input_attributes['value'];
		} else {
			return null;
		}
	}

	/**
	 * Says if an item is visible or not. An item is visible if:
	 * - It's hidden property is FALSE OR
	 * - It's state cannot be determined at time of rendering
	 *
	 * @return boolean
	 */
	public function isRendered() {
		return $this->requiresUserInput() && (!$this->hidden || $this->probably_render);
	}

	/**
	 * Says if an item requires user input.
	 *
	 * @return boolean
	 */
	public function requiresUserInput() {
		return !$this->no_user_input_required;
	}

	/**
	 * Is an element hidden in DOM but rendered?
	 *
	 * @return boolean
	 */
	public function isHiddenButRendered() {
		return $this->hidden && $this->probably_render;
	}

	public function setChoices($choices) {
		$this->choices = $choices;
	}

	public function getReply($reply) {
		return $reply;
	}

	/**
	 * Returns a space-delimited string with items of the $options array. If a key
	 * of $options array happens to be one of those listed in `Helper::$_minimizedAttributes`
	 *
	 * And its value is one of:
	 *
	 * - '1' (string)
	 * - 1 (integer)
	 * - true (boolean)
	 * - 'true' (string)
	 *
	 * Then the value will be reset to be identical with key's name.
	 * If the value is not one of these 3, the parameter is not output.
	 *
	 * 'escape' is a special option in that it controls the conversion of
	 *  attributes to their html-entity encoded equivalents. Set to false to disable html-encoding.
	 *
	 * If value for any option key is set to `null` or `false`, that option will be excluded from output.
	 *
	 * @param array $options Array of options.
	 * @param array $exclude Array of options to be excluded, the options here will not be part of the return.
	 * @param string $insertBefore String to be inserted before options.
	 * @param string $insertAfter String to be inserted after options.
	 * @return string Composed attributes.
	 * @deprecated This method will be moved to HtmlHelper in 3.0
	 */
	protected function _parseAttributes($options, $exclude = null, $insertBefore = ' ', $insertAfter = null) {
		if (!is_string($options)) {
			$options = (array) $options + array('escape' => true);

			if (!is_array($exclude)) {
				$exclude = array();
			}

			$exclude = array('escape' => true) + array_flip($exclude);
			$escape = $options['escape'];
			$attributes = array();

			foreach ($options as $key => $value) {
				if (!isset($exclude[$key]) && $value !== false && $value !== null) {
					$attributes[] = $this->_formatAttribute($key, $value, $escape);
				}
			}
			$out = implode(' ', $attributes);
		} else {
			$out = $options;
		}
		return $out ? $insertBefore . $out . $insertAfter : '';
	}

	/**
	 * Formats an individual attribute, and returns the string value of the composed attribute.
	 * Works with minimized attributes that have the same value as their name such as 'disabled' and 'checked'
	 *
	 * @param string $key The name of the attribute to create
	 * @param string $value The value of the attribute to create.
	 * @param boolean $escape Define if the value must be escaped
	 * @return string The composed attribute.
	 * @deprecated This method will be moved to HtmlHelper in 3.0
	 */
	protected function _formatAttribute($key, $value, $escape = true) {
		if (is_array($value)) {
			$value = implode(' ', $value);
		}
		if (is_numeric($key)) {
			return sprintf($this->_minimizedAttributeFormat, $value, $value);
		}
		$truthy = array(1, '1', true, 'true', $key);
		$isMinimized = in_array($key, $this->_minimizedAttributes);
		if ($isMinimized && in_array($value, $truthy, true)) {
			return sprintf($this->_minimizedAttributeFormat, $key, $key);
		}
		if ($isMinimized) {
			return '';
		}
		return sprintf($this->_attributeFormat, $key, ($escape ? h($value) : $value));
	}

	protected function isSelectedOptionValue($expected = null, $actual = null) {
		if ($expected !== null && $actual !== null && $expected == $actual) {
			return true;
		}
		return false;
	}

}
