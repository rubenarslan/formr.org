<?php
// the default item is a text input, as many browser render any input type they don't understand as 'text'.
// the base class should also work for inputs like date, datetime which are either native or polyfilled but don't require
// special label handling

// todo: geolocation
// todo: parse attributes properly, generalise items
class Item 
{
	public $id = null;
	public $type = null;
	public $name = null;
	public $text = null;
	public $reply_options = null;
	public $required = true;
	public $displayed_before = 0;
	public $error = null;
	public $optional = false;
	public $size = null;
	protected $input_attributes = array();
	protected $classes_controls = array('controls');
	protected $classes_wrapper = array('control-group','form-row');
	protected $classes_input = array();
	protected $classes_label = array('control-label');
	protected $type_options = array();
	
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
		protected function _parseAttributes($options, $exclude = null, $insertBefore = ' ', $insertAfter = null) 
		{
			if (!is_string($options)) 
			{
				$options = (array)$options + array('escape' => true);

				if (!is_array($exclude)) 
				{
					$exclude = array();
				}

				$exclude = array('escape' => true) + array_flip($exclude);
				$escape = $options['escape'];
				$attributes = array();

				foreach ($options as $key => $value) 
				{
					if (!isset($exclude[$key]) && $value !== false && $value !== null) 
					{
						$attributes[] = $this->_formatAttribute($key, $value, $escape);
					}
				}
				$out = implode(' ', $attributes);
			} else 
			{
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
		
	public function __construct($name,$options = array()) 
	{ 
		global $allowedtypes;
		$this->allowedTypes = $allowedtypes;
		
		$this->id = isset($options['id']) ? $options['id'] : 0;
		$this->type = isset($options['type']) ? $options['type'] : 'text';
		$this->name = $name;
		
		$this->text = isset($options['text'])?$options['text']:'';
		
		if(isset($options['size'])) 
			$this->size = (int)$options['size'];
		
		if(isset($options['type_options']))
			$this->type_options = $options['type_options'];
		
		if(isset($options['reply_options']))
			$this->reply_options =  $options['reply_options'];

		if(isset($options['skipIf']))
			$this->skipIf = $options['skipIf'];

		
		if(@$options['error'])
		{
			$this->error = $options['error'];
			$this->classes_wrapper[] = "error";
		}
		if(@$options['displayed_before']>0)
		{
			$this->displayed_before = $options['displayed_before'];
			if(!$this->error)
				$this->classes_wrapper[] = "warning";
		}
		
		
		if(isset($this->options['optional'])) 
		{
			$this->optional = true;
			unset($this->options['optional']);
		}
		$this->input_attributes['name'] = $this->name;
		
		$this->setMoreOptions();

		if(!$this->optional) 
		{
			$this->classes_wrapper[] = 'required';
			$this->input_attributes['required'] = 'required';
		} else
		{
			$this->classes_wrapper[] = 'optional';			
		}
		
		$this->classes_wrapper[] = "item-" . $this->type;
		
		$this->input_attributes['type'] = $this->type;
		
		if($this->size) 
			$this->input_attributes['maxlength'] = $this->size;
		
		$this->input_attributes['class'] = implode(" ",$this->classes_input);
		
		$this->input_attributes['id'] = "item{$this->id}";
		
		
/*		echo "<pre>".
			self::_parseAttributes($this->input_attributes).
			'</pre>';
*/	}
	
	public function validate() 
	{
		$this->val_errors = array();
		
		if( !preg_match('/[A-Za-z0-9_]+/',$this->name) ): 
			$this->val_errors[] = "'{$this->name}' Variablenname darf nur a-Z, 0-9 und den Unterstrich enthalten. Zeile übersprungen.";
		endif;
		
		if( trim($this->type) == "" ):
			$this->val_errors[] = "ID {$this->id}: Typ darf nicht leer sein.";
		elseif(!in_array($this->type,$this->allowedTypes) ):
			$this->val_errors[] = "ID {$this->id}: Typ '{$this->type}' nicht erlaubt. In den Admineinstellungen änderbar.";
		endif;
			
			
		if( trim($this->skipIf) != "" AND is_null(json_decode($this->skipIf, true)) ):
			$this->val_errors[] = "ID {$this->id}: skipif '{$this->skipIf}' cannot be decoded: check the skipif!";
		endif;
		
		return $this->val_errors;
	}
	
	public function viewedBy($view_update) {		
		$view_update->bindParam(":variablenname", $this->name);
		
   	   	$view_update->execute() or die(print_r($view_update->errorInfo(), true));
	}
	public function switchText($person,$dbh) {
        if (@$this->switch_text != null) 
		{
			if(! preg_match("(/[A-Za-z0-9_]+)\s*(!=|=|==|>|<)\s*['\"]*(\w+)['\"]*\s*/",trim($this->switch_text), $matches) )
			{
				die ($this->name . " invalid switch_text");
			} else {
				$switch_condition = $dbh->prepare("SELECT COUNT(*) FROM `" . RESULTSTABLE . "` WHERE 
				vpncode = :vpncode AND
				`{$matches[1]}` {$matches[2]} :value");
				$switch_condition->bindParam(":vpncode", $person);
				$switch_condition->bindParam(":value", $matches[3]);
				$switch_condition->execute() or die(print_r($switch_condition->errorInfo(), true));
				$switch = (bool)$switch_condition->rowCount();
				if($switch)
	                $item->text = $item->alt_text;
			}
        }
	}
	public function substituteText($substitutions) {
        $this->text = str_replace($substitutions['search'], $substitutions['replace'], $this->text);
	}
	public function validateInput($reply) 
	{
		$this->reply = $reply;
#		var_dump($this->optional);
		if (!$this->optional AND 
			(( $reply===null || $reply===false || $reply === array() || $reply === '') OR 
			(is_array($reply) AND count($reply)===1 AND current($reply)===''))
		) // missed a required field
		{
			$this->error = _("This field is required.");			
		}
		elseif(isset($this->input_attributes['min']) AND $reply <= $this->input_attributes['min']) // lower number than allowed
		{
			$this->error = __("The minimum is %d",$this->input_attributes['min']);
		}
		elseif(isset($this->input_attributes['max']) AND $reply >= $this->input_attributes['max']) // larger number than allowed
		{
			$this->error = __("The maximum is %d",$this->input_attributes['max']);
		}
		elseif(isset($this->input_attributes['step']) AND $this->input_attributes['step'] !== 'any' AND 
			abs( 
		 			(round($reply / $this->input_attributes['step']) * $this->input_attributes['step'])  // divide, round and multiply by step
					- $reply // should be equal to reply
			) > 0.000000001 // with floats I have to leave a small margin of error
		)
		{
			$this->error = __("The minimum is %d",$this->input_attributes['min']);
		}
		elseif (isset($this->input_attributes['maxlength']) AND $this->input_attributes['maxlength'] > 0 AND strlen($reply) > $this->input_attributes['maxlength']) // verify maximum length 
		{
			$this->error = __("You can't use that many characters. The maximum is %d",$this->input_attributes['maxlength']);
		}
		elseif($this->type != 'range' AND !empty($this->reply_options) AND
			( is_string($reply) AND !in_array($reply,array_keys($this->reply_options)) ) OR // mc
				( is_array($reply) AND $diff = array_diff($reply, array_keys($this->reply_options) ) AND !empty($diff) && current($diff) !=='' ) // mmc
		) // invalid multiple choice answer 
		{
#				pr($reply);
				pr(array_keys($this->reply_options));
				if(isset($diff)) 
				{
#					pr($diff);
					$problem = $diff;
				}
				else $problem = $reply;
				if(is_array($problem)) $problem = implode("', '",$problem);
				$this->error = __("You chose an option '%s' that is not permitted.",h($problem));
		} 
		elseif($this->type == 'instruction')
		{
			$this->error = _("You cannot answer instructions.");
		}
		
		return $this->error;
	}
	
	protected function setMoreOptions() 
	{	
		if(count($this->type_options) == 1)
		{
			$this->size = $type_split[1];
		}		
	}
	protected function render_label() 
	{
		return '
					<label class="'. implode(" ",$this->classes_label) .'" for="item' . $this->id . '">'.
		($this->error ? '<span class="label label-important hastooltip" title="'.$this->error.'"><i class="icon-warning-sign"></i></span> ' : '').
			 	$this->text . '</label>
		';
	}
	protected function render_prepended () 
	{
		if(isset($this->prepend))
			return '<span class="add-on"><i class="'.$this->prepend.'"></i></span>';
		else return '';
	}
	protected function render_input() 
	{
		return 		
			'<input '.self::_parseAttributes($this->input_attributes).'>';
	}
	protected function render_appended () 
	{
		if(isset($this->append))
			return '<span class="add-on"><i class="'.$this->append.'"></i></span>';
		else return '';
	}
	protected function render_inner() 
	{
		return $this->render_label() . '
					<div class="'. implode(" ",$this->classes_controls) .'">'.
					$this->render_prepended().
					$this->render_input().
					$this->render_appended().
					'</div>
		';
	}
	public function render() 
	{
		if(!isset($this->prepend) AND !isset($this->append))
			return '<div class="'. implode(" ",$this->classes_wrapper) .'">' .
				$this->render_inner().
			 '</div>';
		else 
		{
			$classes = isset($this->prepend) ? 'input-prepend':'';
			$classes .= isset($this->append) ? ' input-append':'';
			return '<div class="'. implode(" ",$this->classes_wrapper) .'">
				<div class="'.$classes.'">' .
				$this->render_inner().
			 '</div>
		</div>';
		}
	}
}

// textarea automatically chosen when size exceeds a certain limit
class Item_textarea extends Item 
{
	protected function setMoreOptions() 
	{
		parent::setMoreOptions();
		$this->type = 'textarea';
	}
	protected function render_input() 
	{
		return 		
			'<textarea '.self::_parseAttributes($this->input_attributes).' rows="'. round($this->size/150) .'" cols="150" name="' . $this->name . '"></textarea>';
	}
}

// spinbox is polyfilled in browsers that lack it 
class Item_number extends Item 
{
	protected function setMoreOptions() 
	{
		$this->input_attributes['step'] = 1;
		
		if(count($this->type_options) == 1) 
			$constraint_split = explode(",",$this->type_options[1]);

		$min = reset($this->type_options);
		if(is_numeric($min)) $this->input_attributes['min'] = $min;
		
		$max = next($this->type_options);
		if(is_numeric($max)) $this->input_attributes['max'] = $max;
		
		$step = next($this->type_options);
		if(is_numeric($step) OR $step==='any') $this->input_attributes['step'] = $step;
		
		$this->type = 'number';
	}
}


// slider, polyfilled in most browsers, native in chrome, ..?
class Item_range extends Item_number 
{
	protected function setMoreOptions() 
	{
		$this->input_attributes['min'] = 0;
		$this->input_attributes['max'] = 100;
		parent::setMoreOptions();
		$this->type = 'range';
	}
	protected function render_input() 
	{
		return (isset($this->reply_options[1]) ? '<label>'. $this->reply_options[1] . ' </label>': '') . 		
			'<input '.self::_parseAttributes($this->input_attributes).'>'.
			(isset($this->reply_options[2]) ? '<label>'. $this->reply_options[2] . ' </label>': '') ;
	}
}


// email is a special HTML5 type, validation is polyfilled in browsers that lack it
class Item_email extends Item 
{
	protected function setMoreOptions() 
	{
		$this->type = 'email';
		$this->prepend = 'icon-envelope';
		$this->size = 250;
	}
}

// time is polyfilled, we prepended a clock
class Item_time extends Item 
{
	protected function setMoreOptions() 
	{
		$this->type = 'time';
		$this->prepend = 'icon-time';
		$this->input_attributes['style'] = 'width:80px';
	}
}
// instructions are rendered at full width
class Item_instruction extends Item 
{
	protected function setMoreOptions() 
	{
		$this->type = 'instruction';
	}
	protected function render_inner() 
	{
		return '
					<div class="'. implode(" ",$this->classes_label) .'">'.
					$this->text.
					'</div>
		';
	}
}

// todo: should this be addable by the user? instead of relevant-column?
class Item_submit extends Item 
{
	protected function setMoreOptions() 
	{
		$this->classes_wrapper = array('control-group');
		$this->classes_input[] = 'btn';
		$this->classes_input[] = 'btn-large';
		$this->classes_input[] = 'btn-success';
		$this->input_attributes['value'] = $this->text;
		$this->text = '';
		$this->type = 'submit';
	}
}

// radio buttons
class Item_mc extends Item 
{
	protected function setMoreOptions() 
	{
		$this->type = 'radio';
	}
	protected function render_label() 
	{
		return '
					<label class="'. implode(" ",$this->classes_label) .'">' .
		($this->error ? '<span class="label label-important hastooltip" title="'.$this->error.'"><i class="icon-warning-sign"></i></span> ' : '').
		 $this->text . '</label>
		';
	}
	protected function render_input() 
	{
		$ret = '
			<input '.self::_parseAttributes($this->input_attributes,array('type','id')).' type="hidden" value="" id="item' . $this->id . '_">
		';
		
		$opt_values = array_count_values($this->reply_options);
		if(
			isset($opt_values['']) AND // if there are empty options
#			$opt_values[''] > 0 AND 
			current($this->reply_options)!= '' // and the first option isn't empty
		) $this->label_first = true;  // the first option label will be rendered before the radio button instead of after it.
		else $this->label_first = false;
		
		foreach($this->reply_options AS $value => $option):			
			$ret .= '
				<label for="item' . $this->id . '_' . $value . '">' . 
					($this->label_first ? $option.'&nbsp;' : '') . 
				'<input '.self::_parseAttributes($this->input_attributes,array('id')).
				' value="'.$value.'" id="item' . $this->id . '_' . $value . '">' .
					($this->label_first ? "&nbsp;" : ' ' . $option) . '</label>';
					
			if($this->label_first) $this->label_first = false;
			
		endforeach;
		
		return $ret;
	}
}

// multiple multiple choice, also checkboxes
class Item_mmc extends Item_mc 
{
	protected function setMoreOptions() 
	{
		$this->type = 'checkbox';
		$this->optional = true;
		$this->input_attributes['name'] = $this->name . '[]';
	}
	protected function render_input() 
	{
		$ret = '
			<input type="hidden" value="" id="item' . $this->id . '_" '.self::_parseAttributes($this->input_attributes,array('id','type')).'>
		';
		foreach($this->reply_options AS $value => $option) {
			$ret .= '
			<label for="item' . $this->id . '_' . $value . '">
			<input '.self::_parseAttributes($this->input_attributes,array('id')).
			' value="'.$value.'" id="item' . $this->id . '_' . $value . '">
			' . $option . '</label>
		';
		}
		return $ret;
	}
}

// dropdown select, choose one
class Item_select extends Item 
{
	protected function setMoreOptions() 
	{
		$this->type = 'select';
	}
	protected function render_input() 
	{
		$ret = '<select '.self::_parseAttributes($this->input_attributes).'>'; 
		
		if(!isset($this->input_attributes['multiple'])) $ret .= '<option value=""></option>';
		
		foreach($this->reply_options AS $value => $option):
			$ret .= '
				<option value="' . $value . '">' . 
					 $option .
				'</option>';
		endforeach;

		$ret .= '</select>';
		
		return $ret;
	}
}


// dropdown select, choose multiple
class Item_mselect extends Item_select 
{
	protected function setMoreOptions() 
	{
		parent::setMoreOptions();
		$this->input_attributes['multiple'] = true;
		$this->input_attributes['name'] = $this->name.'[]';
	}
}


// dropdown select, choose multiple
class Item_btnradio extends Item_mc 
{
	protected function setMoreOptions() 
	{
		parent::setMoreOptions();
		$this->classes_wrapper[] = 'btn-radio';		
	}
	protected function render_appended () 
	{
		$ret = '<div class="btn-group hidden">
		';
		foreach($this->reply_options AS $value => $option):			
		$ret .= '
			<button class="btn" data-for="item' . $this->id . '_' . $value . '">' . 
				$option.
			'</button>';
		endforeach;
		$ret .= '</div>';
		
		return $ret;
	}
}
class Item_btncheckbox extends Item_mmc 
{
	protected function setMoreOptions() 
	{
		parent::setMoreOptions();
		$this->classes_wrapper[] = 'btn-checkbox';
	}
	protected function render_appended () 
	{
		$ret = '<div class="btn-group hidden">
		';
		foreach($this->reply_options AS $value => $option):			
		$ret .= '
			<button class="btn" data-for="item' . $this->id . '_' . $value . '">' . 
				$option.
			'</button>';
		endforeach;
		$ret .= '</div>';
		
		return $ret;
	}
}

class Item_sex extends Item_btnradio 
{
	protected function setMoreOptions() 
	{
		parent::setMoreOptions();
		$this->reply_options = array(1=>'♂',2=>'♀');
	}
}

class Item_fork extends Item {
	protected function setMoreOptions() 
	{
		$this->type = 'fork';
	}
	public function render() {
        global $study;
        global $run;
		$ret = '';
	    // fixme: forks should do PROPER redirects, but at the moment the primitive MVC separation makes this a problem
		if(isset($run))
			$link=$ratinguntererpol."?study_id=".$study->id."&run_id=".$run->id;
		else
			$link=$ratinguntererpol."?study_id=".$study->id;
		
		if(TIMEDMODE) 
			$link .= "&ts=$timestarted";
		redirect_to($link);
	}
}

class Item_ip extends Item {
	protected function setMoreOptions() 
	{
		$this->type = 'ip';
	}
	protected function render_input() 
	{
		return '
			<input type="hidden" value="IP" id="item' . $this->id . '_" name="' . $this->name . '">
		';
	}
	public function render() {
		return $this->render_input();
	}
}

/*
 * todo: item - rank / sortable
 * todo: item - select + or add our own (optionally: load other users' entries), both as dropdown and as radio/btnradio, checkbox/btncheckbox
 * todo: item - likert scale with head (special kind of instruction?)
 * todo: item - facebook connect?
 * todo: item - IP
*/