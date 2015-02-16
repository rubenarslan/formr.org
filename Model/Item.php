<?php

class ItemFactory {

	public $errors;
	private $choice_lists = array();
	private $used_choice_lists = array();
	public $showifs = array();
	public $openCPU_errors = array();

	function __construct($choice_lists) {
		$this->choice_lists = $choice_lists;
	}

	public function make($item) {
		$type = "";
		if (isset($item['type'])) {
			$type = $item['type'];
		}

		if(isset($item['choice_list']) AND $item['choice_list']): // if it has choices
			if(isset($this->choice_lists[ $item['choice_list'] ])): // if this choice_list exists
				$item['choices'] = $this->choice_lists[ $item['choice_list'] ]; // take it
				$this->used_choice_lists[ $item['choice_list'] ] = true; // check it as used
			else:
				$item['val_errors'] = array(__("Choice list %s does not exist, but is specified for item %s", $item['choice_list'], $item['name']));
			endif;
		endif;
		
		$type = str_replace("-","_",$type);
		$class = "Item_".$type;
	
		if (!class_exists($class, true)) {
			return false;
		}
	
		return new $class($item);
	}

	public function unusedChoiceLists() {
		return array_diff(
			array_keys($this->choice_lists), array_keys($this->used_choice_lists)
		);
	}
	public function showif($survey, $showif) {
		if (array_key_exists($showif, $this->showifs)) {
			return $this->showifs[$showif];
		}
		
		if(strstr($showif, "//js_only")):
			$result = null;
		else:
		
			if($survey->openCPU === NULL):
				$survey->openCPU = $survey->makeOpenCPU();
			else:
				$survey->openCPU->clearUserData();
			endif;

			$survey->openCPU->admin_usage = $survey->admin_usage;

			$survey->openCPU->addUserData($survey->getUserDataInRun(
				$survey->dataNeeded($survey->dbh, $showif, $survey->name)
			));
		
			$result = $survey->openCPU->evaluateWith($survey->name, $showif);

			if($survey->openCPU->anyErrors()):
				$result = true;
				$this->openCPU_errors[$showif] =  _('There were problems with openCPU.');
			endif;

		endif;
		
		$this->showifs[$showif] = $result;
		
		return $this->showifs[$showif];
	}

}

// the default item is a text input, as many browser render any input type they don't understand as 'text'.
// the base class should also work for inputs like date, datetime which are either native or polyfilled but don't require
// special handling here

class Item extends HTML_element {

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
	public $value = null; // syntax for sticky value
	public $order = null;
	public $displaycount = 0;
	public $error = null;
	public $dont_validate = null;
	public $val_errors = array();
	public $mysql_field =  'TEXT DEFAULT NULL';
	protected $prepend = null;
	protected $append = null;
	protected $type_options_array = array();
	public $choices = array();
	protected $hasChoices = false;
	protected $data_showif = false;
	public $hidden = false;
	public $no_user_input_required = false;
	public $save_in_results_table = true;
	public $input_attributes = array(); // so that the pre-set value can be set externally
	protected $classes_controls = array('controls') ;
	protected $classes_wrapper = array('form-group','form-row');
	protected $classes_input = array();
	protected $classes_label = array('control-label');
	protected $presetValues = array();
		
	public function __construct($options = array()) {
		
		// simply load the array into the object, with some sensible defaults
		$this->id = isset($options['id']) ? $options['id'] : 0;

		if(isset($options['type'])):
			$this->type = $options['type'];
		endif;
		
		if (isset($options['name'])) {
			$this->name = $options['name'];
		}

		if (isset($options['value'])) {
			$this->value = $options['value'];
		}

		if (isset($options['order'])) {
			$this->order = $options['order'];
		}
		
		$this->label = isset($options['label'])?$options['label']:'';
		$this->label_parsed = isset($options['label_parsed'])?$options['label_parsed']:null;

		if(isset($options['type_options'])):
			$this->type_options = $options['type_options'];
			$this->type_options_array = explode(" ",$options['type_options']);
		endif;
		
		if (isset($options['choice_list'])) {
			$this->choice_list =  $options['choice_list'];
		}

		if (isset($options['choices'])) {
			$this->choices =  $options['choices'];
		}

		if (isset($options['showif'])) {
			$this->showif = $options['showif'];
		}

		if (isset($options['val_error']) AND $options['val_error']) {
			$this->val_error = $options['val_error'];
		}
		
		if (isset($options['error']) AND $options['error']) {
			$this->error = $options['error'];
			$this->classes_wrapper[] = "has-error";
		}
		
		if (isset($options['displaycount']) AND $options['displaycount'] > 0) {
			$this->displaycount = $options['displaycount'];
			if (!$this->error) {
				$this->classes_wrapper[] = "has-warning";
		}
		}
		
		$this->input_attributes['name'] = $this->name;

		if (empty($options['skip_more_options'])) {
			$this->setMoreOptions();
		}

		// after the easily overriden setMoreOptions, some post-processing that is universal to all items.

		if (isset($options['optional']) AND $options['optional']) {
			$this->optional = 1;
			unset($options['optional']);
		} elseif (isset($options['optional']) AND ! $options['optional']) {
			$this->optional = 0;
		} // else optional stays default
		
		if (!$this->optional) {
			$this->classes_wrapper[] = 'required';
			$this->input_attributes['required'] = 'required';
		} else {
			$this->classes_wrapper[] = 'optional';			
		}
		
		if(isset($options['class']) AND $options['class']):
			$this->classes_wrapper = array_merge( $this->classes_wrapper, explode(" ",$options['class']) );
			$this->class = $options['class'];
		endif;
		
		$this->classes_wrapper[] = "item-" . $this->type;
		
		if (!isset($this->input_attributes['type'])) {
			$this->input_attributes['type'] = $this->type;
		}
		
		$this->input_attributes['class'] = implode(" ",$this->classes_input);
		
		$this->input_attributes['id'] = "item{$this->id}";

		if (in_array("label_as_placeholder", $this->classes_wrapper)) {
			$this->input_attributes['placeholder'] = $this->label;
	}
	}
	public function mightBeShown($survey) {
		$probably_render = true;
		if (trim($this->showif) != null) {
			$probably_render = $survey->item_factory->showif($survey, $this->showif);

			if ($probably_render === null) { // we don't know what happens yet, maybe JS, maybe not
				$this->hide();
				$probably_render = true;
			} elseif (!$probably_render) { // do not force this to be false, could be "0", 0, false, but not NULL!
				$this->hide();
				$probably_render = false;
			} elseif (isset($survey->item_factory->openCPU_errors[$this->showif])) {
				$this->alwaysInvalid();
				$this->error = $survey->item_factory->openCPU_errors[$this->showif];
			}
		}
		return $probably_render;
	}
	public function willbeShown($survey) {
		$this->mightBeShown($survey);
		return !$this->hidden;
	}

	protected function chooseResultFieldBasedOnChoices() {
		if ($this->mysql_field == null) {
			return;
		}
		$choices = array_keys($this->choices);
		
		$len = count($choices);
		if( $len == count(array_filter($choices, "is_numeric")) ):
			$this->mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
		
			$min = min($choices);
			$max = max($choices);
			
			if($min < 0 ):
				$this->mysql_field = str_replace("UNSIGNED ", "",$this->mysql_field);
			endif;
			
			if( abs($min)>32767 OR abs($max)>32767 ):
				$this->mysql_field = str_replace("TINYINT", "MEDIUMINT", $this->mysql_field);
			elseif( abs($min)>126 OR abs($min)>126 ):
				$this->mysql_field = str_replace("TINYINT", "SMALLINT", $this->mysql_field);
			elseif( count(array_filter($choices, "is_float")) ):
				$this->mysql_field = str_replace("TINYINT", "FLOAT", $this->mysql_field);
			endif;
		else:
			$lengths = array_map("strlen", $choices);
			$maxlen = max($lengths);
			$this->mysql_field = 'VARCHAR ('.$maxlen.') DEFAULT NULL';
		endif;
	}

	public function getResultField() {
		if(!empty($this->choices)):
			$this->chooseResultFieldBasedOnChoices();
		endif;
		
		if($this->mysql_field !== null):
			return "`{$this->name}` {$this->mysql_field}";
		else:
			return null;
		endif;
	}

	public function validate() {
		if(!$this->hasChoices AND $this->choice_list!=null):
			$this->val_errors[] = "'{$this->name}' You defined choices for this item, even though this type doesn't have choices.";
		elseif($this->hasChoices AND $this->choice_list==null AND $this->type !== "select_or_add_multiple"):
				$this->val_errors[] = "'{$this->name}' You forgot to define choices for this item.";
		endif;
		if( !preg_match('/^[A-Za-z][A-Za-z0-9_]+$/',$this->name) ): 
			$this->val_errors[] = "'{$this->name}' The variable name can contain <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore. It needs to start with a letter. You cannot use spaces, dots, or dashes.";
		endif;
		
		if( trim($this->type) == "" ):
			$this->val_errors[] = "{$this->name}: The type column must not be empty.";
#		elseif(!in_array($this->type,$this->allowedTypes) ):
#			$this->val_errors[] = "{$this->name}: Typ '{$this->type}' nicht erlaubt. In den Admineinstellungen änderbar.";
		endif;
		
		return $this->val_errors;
	}
	
	public function viewedBy($view_update) {		
		$view_update->bindParam(":item_id", $this->id);
   	   	$view_update->execute();
	}

	public function validateInput($reply) {
		$this->reply = $reply;

		if (!$this->optional AND ( ( $reply === null || $reply === false || $reply === array() || $reply === '') OR ( is_array($reply) AND count($reply) === 1 AND current($reply) === ''))
		) { // missed a required field
			$this->error = _("This field is required.");			
		} elseif ($this->optional AND $reply == '') {
			$reply = null;
		}
		return $reply;
	}
	
	protected function setMoreOptions() {
		
	}

	protected function render_label() {
		return '<label class="'. implode(" ",$this->classes_label) .'" for="item' . $this->id . '">'.
		($this->error ? '<span class="label label-danger hastooltip" title="'.$this->error.'"><i class="fa fa-exclamation-triangle"></i></span> ' : '').
			 	$this->label_parsed . '</label>';
	}

	protected function render_prepended() {
		if (isset($this->prepend)) {
			return '<span class="input-group-addon"><i class="fa '.$this->prepend.'"></i></span>';
	}
		return '';
	}

	protected function render_input() {
		return '<span><input ' . self::_parseAttributes($this->input_attributes) . '></span>';
	}

	protected function render_appended() {
		if (isset($this->append)) {
			return '<span class="input-group-addon"><i class="'.$this->append.'"></i></span>';
	}

		return '';
	}

	protected function render_inner() {
		$inputgroup = isset($this->prepend) OR isset($this->append);
		return $this->render_label() . '
					<div class="'. implode(" ",$this->classes_controls) .'"><div class="controls-inner">'.
		($inputgroup ? '<div class="input-group">' : '').
					$this->render_prepended().
					$this->render_input().
					$this->render_appended().
		($inputgroup ? '</div>' : '').
					'</div></div>
		';
	}

	public function render() {
		if ($this->error) {
			$this->classes_wrapper[] = "has-error";
		}
		
		return '<div class="' . implode(" ", $this->classes_wrapper) . '"' . ($this->data_showif ? ' data-showif="' . h($this->showif) . '"' : '') . '>' . $this->render_inner() . '</div>';
	}

	protected function splitValues() {
		if(isset($this->input_attributes['value'])):
			$this->presetValues = array_map("trim",explode(",",$this->input_attributes['value']));
			unset($this->input_attributes['value']);
		else:
			$this->presetValues = array();
		endif;
	}

	public function hide() {
		if(!$this->hidden):
			$this->classes_wrapper[] = "hidden";
			$this->data_showif = true;
			$this->input_attributes['disabled'] = true; ## so it isn't submitted or validated
			$this->hidden = true; ## so it isn't submitted or validated
		endif;
	}

	public function needsDynamicLabel() {
		if($this->label_parsed === null): // if there is a sticky value to be had
				return true;
		else:
			return false;
		endif;
	}

	public function alwaysInvalid() {
		$this->error =  _('There were problems with openCPU.');
		if (!isset($this->input_attributes['class'])) {
			$this->input_attributes['class'] = '';
		}
		$this->input_attributes['class'] .= " always_invalid";
	}

	public function determineDynamicLabel($survey) {
		if($survey->openCPU === NULL):
			$survey->openCPU = $survey->makeOpenCPU();
		else:
			$survey->openCPU->clearUserData();
		endif;
		
		$survey->openCPU->admin_usage = $survey->admin_usage;
	
		$survey->openCPU->addUserData($survey->getUserDataInRun($survey->dataNeeded($survey->dbh, $this->label, $survey->name)));
		
		$markdown = $survey->openCPU->knitForUserDisplay($this->label);
	
		if($survey->openCPU->anyErrors()):
			$this->alwaysInvalid();
		endif;
		
		if (mb_substr_count($markdown, "</p>") === 1 AND preg_match("@^<p>(.+)</p>$@", trim($markdown), $matches)) { // simple wraps are eliminated
			$this->label_parsed = $matches[1];
		} else {
			$this->label_parsed = $markdown;
		}
	}

	public function needsDynamicValue() {
		if(trim($this->value) != null): // if there is a sticky value to be had
			if(is_numeric($this->value)):
				$this->input_attributes['value'] = $this->value;
			else:
				return true;
			endif;
		else:
			$this->presetValue = null;
		endif;
		return false;
	}
		
	public function determineDynamicValue($survey) {
		if ($this->value == "sticky") {
			$this->value = "tail(na.omit({$survey->results_table}\${$this->name}),1)";
		}

		if($survey->openCPU === NULL):
			$survey->openCPU = $survey->makeOpenCPU();
		else:
			$survey->openCPU->clearUserData();
		endif;

		$survey->openCPU->admin_usage = $survey->admin_usage;

		$survey->openCPU->addUserData($survey->getUserDataInRun($survey->dataNeeded($survey->dbh, $this->value, $survey->name)));
		
		$this->input_attributes['value'] = $survey->openCPU->evaluateWith($survey->name, $this->value);
		
		if($this->type == 'opencpu_session'):
			$this->input_attributes['value'] = $survey->openCPU->session_location;
		endif;

		if($survey->openCPU->anyErrors()):
			$this->alwaysInvalid();
		endif;
	}
		
	}

class Item_text extends Item {

	public $type = 'text';
	public $input_attributes = array('type' => 'text');
	public $mysql_field =  'TEXT DEFAULT NULL';
	
	protected function setMoreOptions() {
		if (is_array($this->type_options_array) AND count($this->type_options_array) == 1) {
			$val = trim(current($this->type_options_array));
			if (is_numeric($val)) {
				$this->input_attributes['maxlength'] = (int)$val;
			} else {
				$this->input_attributes['pattern'] = trim(current($this->type_options_array));	
		}
		}
		$this->classes_input[] = 'form-control';
	}

	public function validateInput($reply) {
		if (isset($this->input_attributes['maxlength']) AND $this->input_attributes['maxlength'] > 0 AND strlen($reply) > $this->input_attributes['maxlength']) { // verify maximum length 
			$this->error = __("You can't use that many characters. The maximum is %d",$this->input_attributes['maxlength']);
		}
		return parent::validateInput($reply);
	}

}

// textarea automatically chosen when size exceeds a certain limit
class Item_textarea extends Item {

	public $type = 'textarea';
	public $mysql_field = 'TEXT DEFAULT NULL'; // change to mediumtext to get 64KiB to 16MiB
	
	protected function setMoreOptions() {
		$this->classes_input[] = 'form-control';
	}
		
	protected function render_input() {

		$value = isset($this->input_attributes['value']) ? $this->input_attributes['value'] : '';
		unset($this->input_attributes['value']);
		return '<textarea ' . self::_parseAttributes($this->input_attributes, array('type')) . '>' . $value . '</textarea>';
	}

}

// textarea automatically chosen when size exceeds a certain limit
class Item_letters extends Item_text {

	public $type = 'letters';
	public $input_attributes = array('type' => 'text');
	public $mysql_field =  'TEXT DEFAULT NULL';
	
	protected function setMoreOptions() {
		$this->input_attributes['pattern'] = "[A-Za-züäöß.;,!: ]+";
		return parent::setMoreOptions();
	}

}

// todo: the min/max stuff is confusing and will fail for real big numbers
// spinbox is polyfilled in browsers that lack it 
class Item_number extends Item {

	public $type = 'number';
	public $input_attributes = array('type' => 'number', 'min' => 0, 'max' => 10000000, 'step' => 1);
	public $mysql_field = 'INT UNSIGNED DEFAULT NULL';
	
	protected function setMoreOptions() {
		$this->classes_input[] = 'form-control';
		if (isset($this->type_options_array) AND is_array($this->type_options_array)) {
			if (count($this->type_options_array) == 1) {
				$this->type_options_array = explode(",",current($this->type_options_array));
			}

			$min = trim(reset($this->type_options_array));
			if (is_numeric($min) OR $min === 'any') {
				$this->input_attributes['min'] = $min;
			}
		
			$max = trim(next($this->type_options_array));
			if (is_numeric($max) OR $max === 'any') {
				$this->input_attributes['max'] = $max;
			}
			
			$step = trim(next($this->type_options_array));
			if (is_numeric($step) OR $step === 'any') {
				$this->input_attributes['step'] = $step;
		}
		}
		
		$multiply = 2;
                if($this->input_attributes['min'] < 0 ) :
			$this->mysql_field = str_replace("UNSIGNED", "", $this->mysql_field);
			$multiply = 1;
		endif;
		
		if($this->input_attributes['step']==='any' OR $this->input_attributes['min'] === 'any' OR $this->input_attributes['max'] === 'any'): // is any any
			$this->mysql_field = str_replace(array("INT"), "FLOAT", $this->mysql_field); // use FLOATing point accuracy
		else:
			if(
					(abs($this->input_attributes['min']) < ($multiply * 127) ) AND ( abs($this->input_attributes['max']) < ($multiply * 127) )
			):
				$this->mysql_field = str_replace("INT", "TINYINT", $this->mysql_field);
			elseif(
					(abs($this->input_attributes['min']) < ($multiply * 32767) ) AND ( abs($this->input_attributes['max']) < ($multiply * 32767) )
			):
				$this->mysql_field = str_replace("INT", "SMALLINT", $this->mysql_field);
			elseif(
					(abs($this->input_attributes['min']) < ($multiply * 8388608) ) AND ( abs($this->input_attributes['max']) < ($multiply * 8388608) )
			):
				$this->mysql_field = str_replace("INT", "MEDIUMINT", $this->mysql_field);
			elseif(
					(abs($this->input_attributes['min']) < ($multiply * 2147483648) ) AND ( abs($this->input_attributes['max']) < ($multiply * 2147483648) )
			):
				$this->mysql_field = str_replace("INT", "INT", $this->mysql_field);
			elseif(
					(abs($this->input_attributes['min']) < ($multiply * 9223372036854775808) ) AND ( abs($this->input_attributes['max']) < ($multiply * 9223372036854775808) )
			):
				$this->mysql_field = str_replace("INT", "BIGINT", $this->mysql_field);
			endif;

			if((string)(int)$this->input_attributes['step'] != $this->input_attributes['step']): // step is integer?
				$before_point = max(strlen((int)$this->input_attributes['min']), strlen((int)$this->input_attributes['max'])); // use decimal with this many digits
				$after_point = strlen($this->input_attributes['step']) - 2;
				$d = $before_point + $after_point;
			
				$this->mysql_field = str_replace(array("TINYINT","SMALLINT","MEDIUMINT","INT","BIGINT"), "DECIMAL($d, $after_point)", $this->mysql_field);
			endif;
		endif;
	}

	public function validateInput($reply) { // fixme: input is not re-displayed after this
		$reply = str_replace(",",".",$reply);
		
		if ($this->input_attributes['min'] !== 'any' AND $reply < $this->input_attributes['min']) { // lower number than allowed
			$this->error = __("The minimum is %d.",$this->input_attributes['min']);
		} elseif ($this->input_attributes['max'] !== 'any' AND $reply > $this->input_attributes['max']) { // larger number than allowed
			$this->error = __("The maximum is %d.",$this->input_attributes['max']);
		} elseif ($this->input_attributes['step'] !== 'any' AND
			abs( 
		 			(round($reply / $this->input_attributes['step']) * $this->input_attributes['step'])  // divide, round and multiply by step
					- $reply // should be equal to reply
			) > 0.000000001 // with floats I have to leave a small margin of error
		) {
			$this->error = __("Numbers have to be in steps of at least %d.",$this->input_attributes['step']);
		}

		return parent::validateInput($reply);
	}

}

// slider, polyfilled in firefox etc, native in many, ..?
class Item_range extends Item_number {

	public $type = 'range';
	public $input_attributes = array('type' => 'range', 'min' => 0, 'max' => 100, 'step' => 1);
	protected $hasChoices = true;
	public $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	
	protected function setMoreOptions() {
		$this->lower_text = current($this->choices);
		$this->upper_text = next($this->choices);		
		parent::setMoreOptions();
		
		$this->classes_input = array_diff(
				$this->classes_input, array('form-control')
		);
	}

	protected function render_input() {
		return (isset($this->choices[1]) ? '<label class="pad-right">'. $this->choices[1] . ' </label>': '') . 		
				'<input ' . self::_parseAttributes($this->input_attributes, array('required')) . ' />' .
			(isset($this->choices[2]) ? ' <label class="pad-left">'. $this->choices[2] . ' </label>': '') ;
	}

}

// slider with ticks
class Item_range_ticks extends Item_number {

	public $type = 'range_ticks';
	public $input_attributes = array('type' => 'range');
	protected $hasChoices = true;
	
	protected function setMoreOptions() {
		$this->input_attributes['min'] = 0;
		$this->input_attributes['max'] = 100;
		$this->input_attributes['list'] = 'dlist'.$this->id;
		$this->input_attributes['data-range'] = '{"animate": true, "classes": "show-activevaluetooltip"}';
		$this->classes_input[] = "range-list";
		
		$this->classes_wrapper[] = 'range_ticks_output';
		
		parent::setMoreOptions();
		$this->classes_input = array_diff($this->classes_input, array('form-control'));
	}

	protected function render_input() {
		$ret = (isset($this->choices[1]) ? '<label class="pad-right">'. $this->choices[1] . ' </label> ': '') . 		
			'<input '.self::_parseAttributes($this->input_attributes, array('required')).'>';
		$ret .= '<datalist id="dlist'.$this->id.'">
        <select class="">';
		for($i = $this->input_attributes['min']; $i <= $this->input_attributes['max']; $i = $i + $this->input_attributes['step']):
        	$ret .= '<option value="'.$i.'">'.$i.'</option>';
		endfor;
			$ret .= '
	        </select>
	    </datalist>';
		$ret .= (isset($this->choices[2]) ? ' <label class="pad-left">'. $this->choices[2] . ' </label>': '') ;
		
		return $ret;
	}

}

// email is a special HTML5 type, validation is polyfilled in browsers that lack it
class Item_email extends Item_text {

	public $type = 'email';
	public $input_attributes = array('type' => 'email', 'maxlength' => 255);
	protected $prepend = 'fa-envelope';
	public $mysql_field = 'VARCHAR (255) DEFAULT NULL';

	public function validateInput($reply) {
		if($this->optional AND trim($reply)==''):
			return parent::validateInput($reply);
		else:
			$reply_valid = filter_var( $reply, FILTER_VALIDATE_EMAIL);
			if(!$reply_valid):
				$this->error = __('The email address %s is not valid', h($reply));
			endif;
		endif;
		return $reply_valid;
	}

}

class Item_url extends Item_text {

	public $type = 'url';
	public $input_attributes = array('type' => 'url');
	protected $prepend = 'fa-link';
	public $mysql_field = 'VARCHAR(255) DEFAULT NULL';

	public function validateInput($reply) {
		if($this->optional AND trim($reply)==''):
			return parent::validateInput($reply);
		else:
			$reply_valid = filter_var( $reply, FILTER_VALIDATE_URL);
			if(!$reply_valid):
				$this->error = __('The URL %s is not valid', h($reply));
			endif;
		endif;
		return $reply_valid;
	}

	protected function setMoreOptions() {
		$this->classes_input[] = 'form-control';		
	}
	
}

class Item_tel extends Item_text {

	public $type = 'tel';
	public $input_attributes = array('type' => 'tel');
	protected $prepend = 'fa-phone';
	public $mysql_field = 'VARCHAR(100) DEFAULT NULL';

	protected function setMoreOptions() {
		$this->classes_input[] = 'form-control';		
	}
	
}

class Item_cc extends Item_text {

	public $type = 'cc';
	public $input_attributes = array('type' => 'cc');
	protected $prepend = 'fa-credit-card';
	public $mysql_field = 'VARCHAR(255) DEFAULT NULL';	

	protected function setMoreOptions() {
		$this->classes_input[] = 'form-control';		
	}

}

class Item_color extends Item {

	public $type = 'color';
	public $input_attributes = array('type' => 'color');
	protected $prepend = 'fa-tint';
	public $mysql_field = 'CHAR(7) DEFAULT NULL';

	protected function setMoreOptions() {
		$this->classes_input[] = 'form-control';		
	}

	public function validateInput($reply) {
		if($this->optional AND trim($reply)==''):
			return parent::validateInput($reply);
		else:
			$reply_valid = preg_match( "/^#[0-9A-Fa-f]{6}$/", $reply);
			if(!$reply_valid):
				$this->error = __('The color %s is not valid', h($reply));
			endif;
		endif;
		return $reply;
	}

}

class Item_datetime extends Item {

	public $type = 'datetime';
	public $input_attributes = array('type' => 'datetime');
	protected $prepend = 'fa-calendar';	
	public $mysql_field = 'DATETIME DEFAULT NULL';
	protected $html5_date_format = 'Y-m-d\TH:i';

	protected function setMoreOptions() {
#		$this->input_attributes['step'] = 'any';
		$this->classes_input[] = 'form-control';
		
		if (isset($this->type_options_array) AND is_array($this->type_options_array)) {
			if (count($this->type_options_array) == 1) {
				$this->type_options_array = explode(",",current($this->type_options_array));
			}
			$min = trim(reset($this->type_options_array));
			if (strtotime($min)) {
				$this->input_attributes['min'] = date($this->html5_date_format, strtotime($min));
			}
		
			$max = trim(next($this->type_options_array));
			if (strtotime($max)) {
				$this->input_attributes['max'] = date($this->html5_date_format, strtotime($max));
			}
		
#			$step = trim(next($this->type_options_array));
#			if(strtotime($step) OR $step==='any') $this->input_attributes['step'] = $step;	
		}
	}
				
	public function validateInput($reply) {
		if (!($this->optional AND $reply == '')) {

			$time_reply = strtotime($reply);
			if ($time_reply === false) {
				$this->error = _('You did not enter a valid date.');	
			}

			if (isset($this->input_attributes['min']) AND $time_reply < strtotime($this->input_attributes['min'])) { // lower number than allowed
				$this->error = __("The minimum is %d",$this->input_attributes['min']);
			} elseif (isset($this->input_attributes['max']) AND $time_reply > strtotime($this->input_attributes['max'])) { // larger number than allowed
				$this->error = __("The maximum is %d",$this->input_attributes['max']);
			}
			$reply = date($this->html5_date_format, $time_reply);
		}
		return parent::validateInput($reply);
	}

}

// time is polyfilled, we prepended a clock
class Item_time extends Item_datetime {

	public $type = 'time';
	public $input_attributes = array('type' => 'time', 'style' => 'width:160px');
	protected $prepend = 'fa-clock-o';
	public $mysql_field = 'TIME DEFAULT NULL';
	protected $html5_date_format = 'H:i';

}

class Item_datetime_local extends Item_datetime {

	public $type = 'datetime-local';
	public $input_attributes = array('type' => 'datetime-local');

}

class Item_date extends Item_datetime {

	public $type = 'date';
	public $input_attributes = array('type' => 'date');
	protected $prepend = 'fa-calendar';	
	public $mysql_field = 'DATE DEFAULT NULL';
	protected $html5_date_format = 'Y-m-d';
	
}

class Item_yearmonth extends Item_datetime {

	public $type = 'yearmonth';
	public $input_attributes = array('type' => 'yearmonth');
	protected $prepend = 'fa-calendar-o';	
	public $mysql_field = 'DATE DEFAULT NULL';
	protected $html5_date_format = 'Y-m-01';

}

class Item_month extends Item_yearmonth {

	public $type = 'month';
	protected $prepend = 'fa-calendar-o';		
	public $input_attributes = array('type' => 'month');

}

class Item_year extends Item_datetime {

	public $type = 'year';
	public $input_attributes = array('type' => 'year');
	protected $html5_date_format = 'Y';
	protected $prepend = 'fa-calendar-o';	
	public $mysql_field = 'YEAR DEFAULT NULL';

}

class Item_week extends Item_datetime {

	public $type = 'week';
	public $input_attributes = array('type' => 'week');
	protected $html5_date_format = 'Y-mW';
	protected $prepend = 'fa-calendar-o';	
	public $mysql_field = 'VARCHAR(9) DEFAULT NULL';

}

// notes are rendered at full width
class Item_note extends Item {

	public $type = 'note';
	public $mysql_field = null;
	public $input_attributes = array('type' => 'hidden', "value" => 1);
	public $save_in_results_table = false;

	public function setMoreOptions() {
		
	}

	protected function render_label() {
		return '<div class="'. implode(" ",$this->classes_label) .'">'.
		($this->error ? '<span class="label label-danger hastooltip" title="'.$this->error.'"><i class="fa fa-exclamation-triangle"></i></span> ' : '').
			 	$this->label_parsed . '</div>';
	}

	public function validateInput($reply) {
		if ($reply != 1) {
			$this->error = _("You can only answer notes by viewing them.");
		}
		return $reply;
	}

/*	protected function render_inner() 
	{
		return '
					<div class="'. implode(" ",$this->classes_label) .'">'.
					$this->label_parsed.
					'</div>
		';
	}
	*/
}

class Item_submit extends Item {

	public $type = 'submit';
	public $input_attributes = array('type' => 'submit');
	public $mysql_field = null;
	
	protected function setMoreOptions() {
		$this->classes_wrapper = array('form-group');
		$this->classes_input[] = 'btn';
		$this->classes_input[] = 'btn-lg';
		$this->classes_input[] = 'btn-info';
	}

	public function validateInput($reply) {
		$this->error = _("You cannot answer buttons.");
		return $reply;
	}

	protected function render_inner() {
		return '<button ' . self::_parseAttributes($this->input_attributes, array('required', 'name', 'value')) . '>' . $this->label_parsed . '</button>';
	}

}

// radio buttons
class Item_mc extends Item {

	public $type = 'mc';
	public $input_attributes = array('type' => 'radio');
	public $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	protected $hasChoices = true;
	
	public function validateInput($reply) {
		if (!($this->optional AND $reply == '') AND ! empty($this->choices) AND // check
			( is_string($reply) AND !in_array($reply,array_keys($this->choices)) ) OR // mc
				( is_array($reply) AND $diff = array_diff($reply, array_keys($this->choices) ) AND !empty($diff) && current($diff) !=='' ) // mc_multiple
		) { // invalid multiple choice answer 
#				pr($reply);
			if (isset($diff)) {
#					pr($diff);
					$problem = $diff;
			} else {
				$problem = $reply;
				}

			if (is_array($problem)) {
				$problem = implode("', '", $problem);
			}

				$this->error = __("You chose an option '%s' that is not permitted.",h($problem));
		}
		return parent::validateInput($reply);
	}

	protected function render_label() {
		return '<div class="' . implode(" ", $this->classes_label) . '">' .
					($this->error ? '<span class="label label-danger hastooltip" title="' . $this->error . '"><i class="fa fa-exclamation-triangle"></i></span> ' : '') . $this->label_parsed . 
				'</div>';
	}
	
	protected function render_input() {

		$this->splitValues();
		
		$ret = '<div class="mc-table"><input ' . self::_parseAttributes($this->input_attributes, array('type', 'id', 'required')) . ' type="hidden" value="" id="item' . $this->id . '_">';
		
		$opt_values = array_count_values($this->choices);
		if (isset($opt_values['']) AND /*$opt_values[''] > 0 AND */ current($this->choices) != '') { // and the first option isn't empty
			$this->label_first = true;  // the first option label will be rendered before the radio button instead of after it.
		} else {
			$this->label_first = false;
		}
#		pr((implode(" ",$this->classes_wrapper)));
		if (mb_strpos(implode(" ", $this->classes_wrapper), 'mc-first-left') !== false) {
			$this->label_first = true;
		}
		$all_left = false;
		if (mb_strpos(implode(" ", $this->classes_wrapper), 'mc-all-left') !== false) {
			$all_left = true;
		}
		
		foreach($this->choices AS $value => $option):
			// determine whether options needs to be checked
			if (in_array($value, $this->presetValues)) {
				$this->input_attributes['checked'] = true;
			} else {
				$this->input_attributes['checked'] = false;
			}
			$ret .= '<label for="item' . $this->id . '_' . $value . '">' . 
					(($this->label_first || $all_left) ? '<span> ' .$option. ' </span>' : '') . 
				'<input '.self::_parseAttributes($this->input_attributes,array('id')).
				' value="'.$value.'" id="item' . $this->id . '_' . $value . '">' .
						(($this->label_first || $all_left) ? "<span>&nbsp;</span>" : '<span> ' . $option . '</span>') . 
					'</label>';
					
			if ($this->label_first) {
				$this->label_first = false;
			}
			
		endforeach;
		
		$ret .= '</div>';
		return $ret;
	}

}

// multiple multiple choice, also checkboxes
class Item_mc_multiple extends Item_mc {

	public $type = 'mc_multiple';
	public $input_attributes = array('type' => 'checkbox');
	public $optional = 1;
	public $mysql_field = 'VARCHAR(40) DEFAULT NULL';
	
	protected function setMoreOptions() {
		$this->input_attributes['name'] = $this->name . '[]';
	}

	protected function chooseResultFieldBasedOnChoices() {
		$choices = array_keys($this->choices);
		$max = implode(", ",array_filter($choices));
		$maxlen = strlen($max);
		$this->mysql_field = 'VARCHAR ('.$maxlen.') DEFAULT NULL';
	}
		
	protected function render_input() {
		if (!$this->optional) {
			$this->input_attributes['class'] .= ' group-required';
		}
		$this->splitValues();
		
#		$this->classes_wrapper = array_diff($this->classes_wrapper, array('required'));
		unset($this->input_attributes['required']);
		
		$ret = '<div class="mc-table"><input type="hidden" value="" id="item' . $this->id . '_" ' . self::_parseAttributes($this->input_attributes, array('id', 'type', 'required')) . '>';
		foreach($this->choices AS $value => $option) {
			// determine whether options needs to be checked
			if (in_array($value, $this->presetValues)) {
				$this->input_attributes['checked'] = true;
			} else {
				$this->input_attributes['checked'] = false;
			}
			
			$ret .= '<label for="item' . $this->id . '_' . $value . '">' . 
						'<input ' . self::_parseAttributes($this->input_attributes, array('id')) . ' value="' . $value . '" id="item' . $this->id . '_' . $value . '" /> ' . $option . 
					'</label> ';
		}
		$ret .= '</div>';
		return $ret;
	}

	public function validateInput($reply) {
		$reply = parent::validateInput($reply);
		if (is_array($reply)) {
			$reply = implode(", ", array_filter($reply));
		}
		return $reply;
	}

}

// multiple multiple choice, also checkboxes
class Item_check extends Item_mc_multiple {

	public $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	public $choice_list = '*';
	
	protected function setMoreOptions() {
		parent::setMoreOptions();
		$this->input_attributes['name'] = $this->name;
	}
	
	protected function render_label() {
		return '<label  for="item' . $this->id . '_1" class="'. implode(" ",$this->classes_label) .'">' .
					($this->error ? '<span class="label label-danger hastooltip" title="' . $this->error . '"><i class="fa fa-exclamation-triangle"></i></span> ' : '') . $this->label_parsed . 
				'</label>';
	}

	public function validateInput($reply) {
		if (!in_array($reply, array(0, 1))) {
			$this->error = __("You chose an option '%s' that is not permitted.",h($reply));	
		}
		$reply = parent::validateInput($reply);
		return $reply ? 1 : 0;
	}

	protected function render_input() {
		if (isset($this->input_attributes['value']) AND trim($this->input_attributes['value'])) {
			$this->input_attributes['checked'] = true;
		} else {
			$this->input_attributes['checked'] = false;
		}
		unset($this->input_attributes['value']);
		
		$ret = '<input type="hidden" value="" id="item' . $this->id . '_" ' . self::_parseAttributes($this->input_attributes, array('id', 'type', 'required')) . '>
			<label for="item' . $this->id . '_1"><input ' . self::_parseAttributes($this->input_attributes, array('id')) . ' value="1" id="item' . $this->id . '_1"></label>';
		return $ret;
	}

}

// dropdown select, choose one
class Item_select_one extends Item {

	public $type = 'select';
	public $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	public $input_attributes = array('type' => 'select');
	protected $hasChoices = true;

	protected function setMoreOptions() {
		$this->classes_input[] = "form-control";
	}

	protected function render_input() {
		$this->splitValues();
		
		$ret = '<select '.self::_parseAttributes($this->input_attributes, array('type')).'>'; 
		
		if (!isset($this->input_attributes['multiple'])) {
			$ret .= '<option value=""> </option>';
		}
		
		foreach($this->choices AS $value => $option):
			// determine whether options needs to be checked
			$selected = '';
			if (in_array($value, $this->presetValues)) {
				$selected = ' selected="selected"';
			}
			
			$ret .= '<option value="' . $value . '"' . $selected . '>' . $option . '</option>';
		endforeach;

		$ret .= '</select>';
		
		return $ret;
	}

}

// dropdown select, choose multiple
class Item_select_multiple extends Item_select_one {

	public $type = 'select_multiple';
	public $mysql_field = 'VARCHAR (40) DEFAULT NULL';
	
	protected function chooseResultFieldBasedOnChoices() {
		$choices = array_keys($this->choices);
		$max = implode(", ",array_filter($choices));
		$maxlen = strlen($max);
		$this->mysql_field = 'VARCHAR ('.$maxlen.') DEFAULT NULL';
	}

	protected function setMoreOptions() {
		parent::setMoreOptions();
		$this->input_attributes['multiple'] = true;
		$this->input_attributes['name'] = $this->name.'[]';
	}

	public function validateInput($reply) {
		$reply = parent::validateInput($reply);
		if (is_array($reply)) {
			$reply = implode(", ", array_filter($reply));
		}
		return $reply;
	}

}

// dropdown select, choose one
class Item_select_or_add_one extends Item {

	public $type = 'select_or_add_one';
	public $mysql_field = 'VARCHAR(255) DEFAULT NULL';
	public $input_attributes = array('type' => 'text');
	protected $hasChoices = true;
	
	protected function setMoreOptions() {
		parent::setMoreOptions();
		if (isset($this->type_options_array) AND is_array($this->type_options_array)) {
			if (count($this->type_options_array) == 1) {
				$this->type_options_array = explode(",",current($this->type_options_array));
			}

		
			$maxType = trim(reset($this->type_options_array));
			if (!is_numeric($maxType)) {
				$maxType = 255;
			}
		
			if (count($this->type_options_array) > 1) {
				$maxSelect = trim(next($this->type_options_array));
			}
			if (!isset($maxSelect) OR ! is_numeric($maxSelect)) {
				$maxSelect = 0;
		}
		}
		
		$this->classes_input[] = 'select2add';
		$this->classes_input[] = 'form-control';
		$for_select2 = array();
		foreach ($this->choices AS $option) {
			$for_select2[] = array('id' => $option, 'text' => $option);
		}

		$this->input_attributes['data-select2add'] = json_encode($for_select2, JSON_UNESCAPED_UNICODE);
		$this->input_attributes['data-select2maximumSelectionSize'] = (int)$maxSelect;
		$this->input_attributes['data-select2maximumInputLength'] = (int)$maxType;
	}

	protected function chooseResultFieldBasedOnChoices() {
		$choices = array_keys($this->choices);
		$lengths = array_map("strlen",$choices);
		$lengths[] = $this->input_attributes['data-select2maximumInputLength'];
		$maxlen = max($lengths);
		$this->mysql_field = 'VARCHAR ('.$maxlen.') DEFAULT NULL';
	}

}

class Item_select_or_add_multiple extends Item_select_or_add_one {

	public $type = 'select_or_add_multiple';
	public $mysql_field = 'TEXT DEFAULT NULL';
	public $input_attributes = array('type' => 'text');
	
	protected function setMoreOptions() {
		parent::setMoreOptions();
		$this->text_choices = true;
		$this->input_attributes['data-select2multiple'] = 1;
	}

	public function validateInput($reply) {
		$reply = parent::validateInput($reply);
		if (is_array($reply)) {
			$reply = implode("\n", array_filter($reply));
		}
		return $reply;
	}

	protected function chooseResultFieldBasedOnChoices() {
		$choices = array_keys($this->choices);
		$max = implode(", ",array_filter($choices));
		if(!$this->input_attributes['data-select2maximumSelectionSize']):
			$this->mysql_field = 'TEXT DEFAULT NULL';
		else:
			$maxUserAdded = ($this->input_attributes['data-select2maximumInputLength']+2) * $this->input_attributes['data-select2maximumSelectionSize'];
			$maxlen = strlen($max) + $maxUserAdded;
	#		$this->mysql_field = 'VARCHAR ('.$maxlen.') DEFAULT NULL'; // oh why be so stingy, may miscalculate after all with all that utf8 stuff
			$this->mysql_field = 'TEXT DEFAULT NULL';
		endif;
	}

}

// dropdown select, choose multiple
class Item_mc_button extends Item_mc {

	public $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	
	protected function setMoreOptions() {
		parent::setMoreOptions();
		$this->classes_wrapper[] = 'btn-radio';
	}

	protected function render_appended() {
		$ret = '<div class="btn-group hidden">';
		foreach($this->choices AS $value => $option):			
			$ret .= '<button class="btn" data-for="item' . $this->id . '_' . $value . '">' .
				"<span class='btn_value'>$value</span><span class='btn_label'>$option</span>".
			'</button>';
		endforeach;
		$ret .= '</div>';
		
		return $ret;
	}

}

// dropdown select, choose multiple
class Item_rating_button extends Item_mc_button {

	public $mysql_field = 'SMALLINT DEFAULT NULL';
	public $type = "rating_button";

	protected function setMoreOptions() {
		parent::setMoreOptions();
		$step = 1;
		$lower_limit = 1;
		$upper_limit = 5;
		
		if (isset($this->type_options_array) AND is_array($this->type_options_array)) {
			if (count($this->type_options_array) == 1) {
				$this->type_options_array = explode(",",current($this->type_options_array));
			}

			if (count($this->type_options_array) == 1) {
				$upper_limit = (int)trim(current($this->type_options_array));
			} elseif (count($this->type_options_array) == 2) {
				$lower_limit = (int)trim(current($this->type_options_array));
				$upper_limit = (int)trim(next($this->type_options_array));
			} elseif (count($this->type_options_array) == 3) {
				$lower_limit = (int)trim(current($this->type_options_array));
				$upper_limit = (int)trim(next($this->type_options_array));
				$step = (int)trim(next($this->type_options_array));
			}
		}
		
		$this->lower_text = current($this->choices);
		$this->upper_text = next($this->choices);
		// force step to be a non-zero positive number less than or equal to upper limit
		if ($step <= 0 || $step > $upper_limit) {
			$step = $upper_limit;
		}
		$this->choices = array_combine(range($lower_limit, $upper_limit, $step), range($lower_limit, $upper_limit, $step));
	}

	protected function render_input() {
		$this->splitValues();
		
		
		$ret = '<input ' . self::_parseAttributes($this->input_attributes, array('type', 'id', 'required')) . ' type="hidden" value="" id="item' . $this->id . '_">';
		
		$ret .= "<label class='keep-label'>{$this->lower_text} </label> ";
		foreach($this->choices AS $option):	
			// determine whether options needs to be checked
			if (in_array($option, $this->presetValues)) {
				$this->input_attributes['checked'] = true;
			} else {
				$this->input_attributes['checked'] = false;
			}
					
			$ret .= '<label for="item' . $this->id . '_' . $option . '">' . 
						'<input ' . self::_parseAttributes($this->input_attributes, array('id')) . ' value="' . $option . '" id="item' . $this->id . '_' . $option . '">' . $option . 
					'</label>';
		endforeach;
		
		return $ret;
	}

	protected function render_appended() {
		$ret = parent::render_appended();
		$ret .= " <label class='keep-label'> {$this->upper_text}</label>";
		
		return $ret;
	}
		
	}

class Item_mc_multiple_button extends Item_mc_multiple {

	public $mysql_field = 'VARCHAR (40) DEFAULT NULL';
	public $type = "mc_multiple_button";
	
	protected function setMoreOptions() {
		parent::setMoreOptions();
		$this->classes_wrapper[] = 'btn-checkbox';
	}

	protected function render_appended() {
		$ret = '<div class="btn-group hidden">';
		foreach($this->choices AS $value => $option):			
			$ret .= '<button class="btn" data-for="item' . $this->id . '_' . $value . '">' .
				"<span class='btn_value'>$value</span><span class='btn_label'>$option</span>".
			'</button>';
		endforeach;
		$ret .= '</div>';
		
		return $ret;
	}

}

class Item_check_button extends Item_check {

	public $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	
	protected function setMoreOptions() {
		parent::setMoreOptions();
		$this->classes_wrapper[] = 'btn-check';
	}

	protected function render_appended() {
		$ret = '<div class="btn-group hidden">
					<button class="btn" data-for="item' . $this->id . '_1"><i class="fa fa-2x fa-fw"></i></button>' .
				'</div>';
		
		return $ret;
	}

}

class Item_sex extends Item_mc_button {

	public $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	public $choice_list = '*';

	protected function setMoreOptions() {
		parent::setMoreOptions();
		$this->choices = array(1=>'♂',2=>'♀');
	}

}

class Item_geopoint extends Item {

	public $type = 'geopoint';
	public $input_attributes = array('type' => 'text', 'readonly');
	protected $append = true;
	public $mysql_field =  'TEXT DEFAULT NULL';

	protected function setMoreOptions() {
		$this->input_attributes['name'] = $this->name.'[]';
		$this->classes_input[] = "form-control";
	}

	public function validateInput($reply) {
		$reply = parent::validateInput($reply);
		if(is_array($reply)):
			$reply = array_filter($reply);
			$reply = end($reply);
		endif;
		return $reply;
	}

	protected function render_prepended() {
		return '
			<input type="hidden" name="'.$this->name.'" value="">
			<div class="input-group">';
	}

	protected function render_appended() {
		$ret = '
			<span class="input-group-btn hidden">
				<button class="btn btn-default geolocator item' . $this->id . '">
					<i class="fa fa-location-arrow"></i>
				</button>
			</span>
			</div>
			';
			return $ret;
	}

}

class Item_random extends Item_number {

	public $type = 'random';
	public $input_attributes = array('type' => 'hidden');
	public $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	public $no_user_input_required = true;
	
	protected function setMoreOptions() {
		$this->input_attributes['value'] = $this->validateInput();
	}

	public function validateInput($reply) {
		if (isset($this->input_attributes['min']) AND isset($this->input_attributes['max'])) { // both limits specified
			$reply = mt_rand($this->input_attributes['min'],$this->input_attributes['max']);
		} elseif (!isset($this->input_attributes['min']) AND ! isset($this->input_attributes['max'])) { // neither limit specified
			$reply = mt_rand(0,1);
		} else {
			$this->error = __("Both random minimum and maximum need to be specified");
		}
		return $reply;
	}

}

class Item_calculate extends Item {

	public $type = 'calculate';
	public $input_attributes = array('type' => 'hidden');
	public $no_user_input_required = true;
	public $mysql_field =  'TEXT DEFAULT NULL';

	public function render() {
		return $this->render_input();
	}

}

class Item_opencpu_session extends Item {

	public $type = 'opencpu_session';
	public $input_attributes = array('type' => 'hidden');
	public $no_user_input_required = true;
	public $mysql_field =  'VARCHAR (255) DEFAULT NULL';

	public function render() {
		return $this->render_input();
	}

}

class Item_ip extends Item {

	public $type = 'ip';
	public $input_attributes = array('type' => 'hidden');
	public $mysql_field =  'VARCHAR (46) DEFAULT NULL';
	public $no_user_input_required = true;
	
	protected function setMoreOptions() {
		$this->input_attributes['value'] = $_SERVER["REMOTE_ADDR"];
	}

	public function validateInput($reply) {
		return $_SERVER["REMOTE_ADDR"];
	}

	public function render() {
		return $this->render_input();
	}

}

class Item_referrer extends Item {

	public $type = 'referrer';
	public $input_attributes = array('type' => 'hidden');
	public $mysql_field = 'TEXT DEFAULT NULL';
	public $no_user_input_required = true;
	
	protected function setMoreOptions() {
		global $site;
		
		$this->input_attributes['value'] = $site->last_outside_referrer;
	}

	public function validateInput($reply) {
		global $site;
		return $site->last_outside_referrer;
	}

	public function render() {
		return $this->render_input();
	}

}

class Item_server extends Item {

	public $type = 'server';
	public $input_attributes = array('type' => 'hidden');
	private $get_var = 'HTTP_USER_AGENT';
	public $mysql_field =  'TEXT DEFAULT NULL';
	public $no_user_input_required = true;
	
	protected function setMoreOptions() {
		if (isset($this->type_options_array) AND is_array($this->type_options_array)) {
			if (count($this->type_options_array) == 1) {
				$this->get_var = trim(current($this->type_options_array));
				$this->input_attributes['value'] = $_SERVER[$this->get_var];
			}
		}
	}

	public function validateInput($reply) {
		return $_SERVER[$this->get_var];
	}

	public function validate() {
		parent::validate();
		if(!in_array($this->get_var, array(
			'HTTP_USER_AGENT',
			'HTTP_ACCEPT',
			'HTTP_ACCEPT_CHARSET',
			'HTTP_ACCEPT_ENCODING',
			'HTTP_ACCEPT_LANGUAGE',
			'HTTP_CONNECTION',
			'HTTP_HOST',
			'QUERY_STRING',
			'REQUEST_TIME',
			'REQUEST_TIME_FLOAT'
				))) {
			$this->val_errors[] = __('The server variable %s with the value %s cannot be saved', $this->name, $this->get_var);
		}
		
		return $this->val_errors;
	}
	
	public function render() {
		return $this->render_input();
	}

}

class Item_get extends Item {

	public $type = 'get';
	public $input_attributes = array('type' => 'hidden');
	private $get_var = 'referred_by';
	protected $hasChoices = false;
	public $no_user_input_required = true;
	public $mysql_field =  'TEXT DEFAULT NULL';

	protected function setMoreOptions() {
		if (isset($this->type_options_array) AND is_array($this->type_options_array)) {
			if (count($this->type_options_array) == 1) {
				$this->get_var = trim(current($this->type_options_array));
		}
		}

			$this->input_attributes['value'] = '';
		if (isset($_GET[$this->get_var])) {
			$this->input_attributes['value'] = $_GET[$this->get_var];
	}
	}

	public function validate() {
		parent::validate();
		if( !preg_match('/^[A-Za-z0-9_]+$/',$this->get_var) ): 
			$this->val_errors[] = __('Problem with variable %s "get %s". The part after get can only contain a-Z0-9 and the underscore.', $this->name, $this->get_var);
		endif;
		return $this->val_errors;
	}
	
	public function render() {
		return $this->render_input();
	}

}

class Item_choose_two_weekdays extends Item_mc_multiple {

	protected function setMoreOptions() {
		$this->optional = 0;
		$this->classes_input[] = 'choose2days';
		$this->input_attributes['name'] = $this->name . '[]';
	}

}

class Item_timezone extends Item_select_one {

	public $mysql_field = 'FLOAT DEFAULT NULL';
	public $choice_list = '*';
	
	protected function chooseResultFieldBasedOnChoices() {
		
	}

	protected function setMoreOptions() {
		$zonenames = timezone_identifiers_list();
		asort($zonenames);
		$zones = array();
		$offsets = array();
		foreach($zonenames AS $zonename):
			$zone = timezone_open($zonename);
			$offsets[] = timezone_offset_get($zone,date_create());
			$zones[] = str_replace("/"," - ",str_replace("_"," ",$zonename));
		endforeach;
		$this->choices = $zones;
		$this->offsets = $offsets;
		$this->classes_input[] = 'select2zone';
		
		parent::setMoreOptions();
	}

	protected function render_input() {
		$ret = '<select '.self::_parseAttributes($this->input_attributes, array('type')).'>'; 
		
		if (!isset($this->input_attributes['multiple'])) {
			$ret .= '<option value=""> </option>';
		}
		
		foreach($this->choices AS $value => $option):
			$ret .= '<option value="' . $this->offsets[$value] . '">' . $option . '</option>';
		endforeach;

		$ret .= '</select>';
		
		return $ret;
	}

}

class Item_mc_heading extends Item_mc {

	public $type = 'mc_heading';
	public $mysql_field = null;
	
	protected function setMoreOptions() {
		$this->input_attributes['disabled'] = 'disabled';
	}

	public function validateInput($reply) {
		$this->error = _("You cannot answer headings.");
		return $reply;
	}

	protected function render_label() {
		return '<div class="' . implode(" ", $this->classes_label) . '">' .
					($this->error ? '<span class="label label-danger hastooltip" title="' . $this->error . '"><i class="fa fa-exclamation-triangle"></i></span> ' : '') . $this->label . 
				'</div>
		';
	}

	protected function render_input() {
		$ret = '<div class="mc-table">';
		$this->input_attributes['type'] = 'radio';
		$opt_values = array_count_values($this->choices);
		if (isset($opt_values['']) AND /*// if there are empty options $opt_values[''] > 0 AND */ current($this->choices) != '') { // and the first option isn't empty
			$this->label_first = true;  // the first option label will be rendered before the radio button instead of after it.
		} else {
			$this->label_first = false;
		}
#		pr((implode(" ",$this->classes_wrapper)));
		if (mb_strpos(implode(" ", $this->classes_wrapper), 'mc-first-left') !== false) {
			$this->label_first = true;
		}
		$all_left = false;
		if (mb_strpos(implode(" ", $this->classes_wrapper), 'mc-all-left') !== false)  {
			$all_left = true;
		}
		
		foreach($this->choices AS $value => $option):			
			$ret .= '<label for="item' . $this->id . '_' . $value . '">' . 
					(($this->label_first || $all_left) ? $option.'&nbsp;' : '') . 
				'<input '.self::_parseAttributes($this->input_attributes,array('id')).
				' value="'.$value.'" id="item' . $this->id . '_' . $value . '">' .
					(($this->label_first || $all_left) ? "&nbsp;" : ' ' . $option) . '</label>';
					
			if ($this->label_first) {
				$this->label_first = false;
			}
			
		endforeach;
		
		$ret .= '</div>';
		
		return $ret;
	}

}
	
/*
 * todo: item - rank / sortable
 * todo: item - facebook connect?
 * todo: captcha items

*/

class Item_file extends Item {

	public $type = 'file';
	public $input_attributes = array('type' => 'file','accept'=> "image/*,video/*,audio/*,text/*;capture=camera");
	public $mysql_field = 'VARCHAR(1000) DEFAULT NULL';
	protected $file_endings = array(
		'image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif', 'image/tiff' => '.tif',
		'video/mpeg' => '.mpg', 'video/quicktime' => '.mov', 'video/x-flv' => '.flv', 'video/x-f4v' => '.f4v', 'video/x-msvideo' => '.avi',
		'audio/mpeg' => '.mp3',
		'text/csv' => '.csv', 'text/css' =>  '.css', 'text/tab-separated-values' => '.tsv', 'text/plain' => '.txt'
	);
	protected $embed_html = '%s';
	protected $max_size = 16777219;
	
	protected function setMoreOptions() {
		if (is_array($this->type_options_array) AND count($this->type_options_array) == 1) {
			$val = (int)trim(current($this->type_options_array));
			if (is_numeric($val)) {
				$bytes = $val * 1048576; # size is provided in MB
				$this->max_size = $bytes;
			}
		}
	}

	public function validateInput($reply) {
		if ($reply['error'] === 0) { // verify maximum length and no errors
			if (filesize($reply['tmp_name']) < $this->max_size) {
			    $finfo = new finfo(FILEINFO_MIME_TYPE);
				$mime = $finfo->file($reply['tmp_name']);
				if (!in_array($mime, array_keys($this->file_endings))) {
				    $this->error = 'Files of type' . $mime . ' are not allowed to be uploaded.';
				} else {
					$new_file_name = crypto_token(66) . $this->file_endings[$mime];
					$reply = null;
					if (move_uploaded_file($reply['tmp_name'], INCLUDE_ROOT . 'webroot/assets/tmp/' . $new_file_name)) {
						$public_path = WEBROOT.'assets/tmp/'.$new_file_name;
						$reply = __($this->embed_html,$public_path);
					}
				}
			} else {
				$this->error = __("This file is too big the maximum is %d megabytes.", round($this->max_size / 1048576, 2));
				$reply = null;
			}
		} else {
			$this->error = "Error uploading file";
			$reply = null;
		}
		return parent::validateInput($reply);
	}

}

class Item_image extends Item_file {

	public $type = 'image';
	public $input_attributes = array('type' => 'file','accept'=>"image/*;capture=camera");
	public $mysql_field = 'VARCHAR(1000) DEFAULT NULL';
	protected $file_endings = array('image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif', 'image/tiff' => '.tif');
	protected $embed_html = '<img src="%s">';
	protected $max_size = 16777219;

}

class Item_blank extends Item_text {

	public $type = 'blank';
	public $mysql_field = 'TEXT DEFAULT NULL';

	public function render() {
		if ($this->error) {
			$this->classes_wrapper[] = "has-error";
		}

		return '<div class="'. implode(" ",$this->classes_wrapper) .'"'.($this->data_showif? ' data-showif="' . h($this->showif) .'"' : '').'>' .
			$this->label_parsed.
		 '</div>';
	}

	public function validateInput($reply) {
		return $reply;
	}

}

class HTML_element {
	
	// from CakePHP
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
			$options = (array)$options + array('escape' => true);

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
			$value = implode(' ' , $value);
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

}
