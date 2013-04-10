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
	public $input_attributes = array();
	public $classes_controls = array('controls');
	public $classes_wrapper = array('control-group','form-row');
	public $classes_input = array();
	public $classes_label = array('control-label');
	public $type_options = array();
	
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
		
		$this->id = isset($options['id'])?$options['id']:0;
		$this->type = isset($options['type'])?$options['type']:'text';
		$this->name = $name;
		
		$this->text = isset($options['text'])?$options['text']:'';
		
		if(!empty($options['reply_options']))
			$this->size = count($options['reply_options']);
		elseif(isset($options['size'])) 
		{
			$this->size = (int)$options['size'];
			$this->input_attributes['maxlength'] = $this->size;
		}
		if(isset($options['type_options']))
			$this->type_options = $options['type_options'];
				
		$this->reply_options = isset($options['reply_options'])?$options['reply_options']:array();
		$this->skipIf = isset($options['skipIf'])?$options['skipIf']:null;

		if(isset($options['displayed_before']))
			$this->classes_wrapper[] = "warning";

		if(!isset($this->options['optional'])) 
		{
			$this->optional = true;
			unset($this->options['optional']);
		} else $this->optional = false;
		
		$this->setMoreOptions();

		if(!$this->optional) 
		{
			$this->classes_wrapper[] = 'required';
			$this->input_attributes['required'] = 'required';
		}
		
		$this->classes_wrapper[] = "item-" . $this->type;
	}
	
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
	
	
	public function validateInput($reply) 
	{
		$this->valInp_errors = array();
		
		if(!empty($this->reply_options)) 
		{
			
			if( ( is_string($reply) AND !in_array($reply,$this->reply_options,true) ) OR // mc
				( is_array($reply) AND $diff = array_diff($reply,$this->reply_options) AND !empty($diff) ) // mmc
				) 
			{
				$this->valInp_errors[$this->id] = _("You chose an option that is not permitted.");
			}
		} elseif (empty($this->reply_options)) 
		{
			if(isset($this->size) AND $this->size > 0 AND strlen($reply) > $this->size) 
			{
				$this->valInp_errors[$this->id] = _("You can't use that many characters. The maximum is {$this->size}.");
			}
		}
		
		return $this->val_errors;
	}
	
	protected function setMoreOptions() 
	{	
		if(count($type_options) == 1)
		{
			$this->$size = $type_split[1];
		}		
	}
	protected function render_label() 
	{
		return '
					<label class="'. implode(" ",$this->classes_label) .'" for="item' . $this->id . '">' . $this->text . '</label>
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
			'<input type="'. $this->type .'"'. 
			( $this->optional ? '':' required="required"' ) .
			' id="item' . $this->id . '" class="'. implode(" ",$this->classes_input) .'" size="'. $this->size .'" name="' . $this->name . '">';
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
		return '<div class="'. implode(" ",$this->classes_wrapper) .'">' .
			$this->render_inner().
		 '</div>';
	}
}

// textarea automatically chosen when size exceeds a certain limit
class Item_textarea extends Item 
{
	protected function setMoreOptions() 
	{
		parent::setMoreOptions();
		$this->type = 'textarea';
		$this->classes_wrapper[] = "item-" . $this->type;
	}
	protected function render_input() 
	{
		return 		
			'<textarea '. 
			( $this->optional ? '':' required="required"' ) .
			' id="item' . $this->id . '" class="'. implode(" ",$this->classes_input) .'" rows="'. $this->size .'" name="' . $this->name . '"></textarea>';
	}
}

// spinbox is polyfilled in browsers that lack it 
class Item_number extends Item 
{
	protected function setMoreOptions() 
	{
		if(count($this->type_options) == 1) 
		{
			$constraint_split = explode(",",$this->type_options[1]);
			$input_attributes['min'] = @is_numeric($constraint_split[0]) ? $constraint_split[0] : null; // take care to allow 0 as lim
			$input_attributes['max'] = @is_numeric($constraint_split[1]) ? $constraint_split[1] : null; // but also allow omission
			$input_attributes['step'] = @is_numeric($constraint_split[2]) ? $constraint_split[2] : null;
		} else 
		{
			$input_attributes['min'] = @is_numeric($reply_options[0]) ? (int)$reply_options[0] : null;
			$input_attributes['max'] = @is_numeric($reply_options[1]) ? (int)$reply_options[1] : null;
			$input_attributes['step'] = @is_numeric($reply_options[2]) ? (int)$reply_options[2] : null;
		}
		
		$this->type = 'number';
		$this->classes_wrapper[] = "item-" . $this->type;
		
	}
	protected function render_input() 
	{
		return 		
			'<input type="'. $this->type .'"'. 
			( $this->optional ? '':' required="required"' ) .
			' id="item' . $this->id . '" class="'. implode(" ",$this->classes_input) .'" size="'. $this->size .'" name="' . $this->name . '">';
		
	}
}


// slider, polyfilled in most browsers, native in chrome, ..?
class Item_range extends Item_number 
{
	protected function setMoreOptions() 
	{
		parent::setMoreOptions();
		$this->type = 'range';
	}
	protected function render_input() 
	{
		return (isset($this->reply_options[1]) ? '<label>'. $this->reply_options[1] . ' </label>': '') . 		
			'<input type="'. $this->type .'"'. 
			( $this->optional ? '':' required="required"' ) .
			( $this->size ? ' size="'. $this->size .'"':'' ) .
			' id="item' . $this->id . '" class="'. implode(" ",$this->classes_input) .'"  name="' . $this->name . '">'.
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
	}
	public function render() 
	{
		return '<div class="'. implode(" ",$this->classes_wrapper) .'">
			<div class="input-prepend">' .
			$this->render_inner().
		 '</div>
	</div>';
	}
}

// time is polyfilled, we prepended a clock
class Item_time extends Item 
{
	protected function setMoreOptions() 
	{
		$this->type = 'time';
		$this->prepend = 'icon-time';
		$this->classes_input[] = 'span4';
	}
	public function render() 
	{
		return '<div class="'. implode(" ",$this->classes_wrapper) .'">
			<div class="input-prepend">' .
			$this->render_inner().
		 '</div>
	</div>';
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
					<div class="'. implode(" ",$this->classes_controls) .'">'.
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
		$this->type = 'submit';
	}
	protected function render_inner() 
	{
		return '
					<div class="'. implode(" ",$this->classes_controls) .'">'.
						'<input type="'. $this->type .'"'. 
						' class="'. implode(" ",$this->classes_input) .'" name="' . $this->name . '" value="' . $this->text . '">'.
					'</div>
		';
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
					<label class="'. implode(" ",$this->classes_label) .'">' . $this->text . '</label>
		';
	}
	protected function render_input() 
	{
		$ret = '
			<input type="hidden" value="" id="item' . $this->id . '_" name="' . $this->name . '">
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
				<label for="item' . $this->id . $value . '">' . 
					($this->label_first ? $option.'&nbsp;' : '') . 
				'<input type="' . $this->type . '" '. 
					( $this->optional ? '':' required="required"' ) .
				' value="'.$value.'" class="'. implode(" ",$this->classes_input) .'" id="item' . $this->id .$value . '" name="' . $this->name . '">' .
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
		$this->name .= '[]';
		$this->optional = true;
	}
	protected function render_input() 
	{
		$ret = '
			<input type="hidden" value="" id="item' . $this->id . '_" name="' . $this->name . '">
		';
		foreach($this->reply_options AS $value => $option) {
			$ret .= '
			<label for="item' . $this->id .$value . '">
			<input type="' . $this->type . '" '. 
			( $this->optional ? '':' required="required"' ) .
			' value="'.$value.'" class="'. implode(" ",$this->classes_input) .'" id="item' . $this->id . $value . '" name="' . $this->name . '">
			
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
		$ret = '<select id="item' . $this->id . '" class="'. implode(" ",$this->classes_input) .'" name="' . $this->name . 
				( $this->optional ? '':' required="required"' ) .'">'; 
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
		$this->name .= '[]';
	}
	protected function render_input() 
	{
		$ret = '<select multiple size="'.$this->size.'" id="item' . $this->id . '" class="'. implode(" ",$this->classes_input) .'" name="' . $this->name . 
				( $this->optional ? '':' required="required"' ) .'">'; 
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
			<button class="btn" data-for="item' . $this->id . $value . '">' . 
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
			<button class="btn" data-for="item' . $this->id . $value . '">' . 
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
		$this->reply_options = array('♀','♂');
	}
}