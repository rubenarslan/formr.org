<?php

#require '/Applications/MAMP/htdocs/zwang/app/webroot/survey/Markdown/Michelf/Markdown.php';
function legacy_translate_item($item) { // may have been a bad idea to name (array)input and (object)return value identically?
	$options = array();
	$options['id'] = $item['id'];
	$name = $item['variablenname'];
	$type = trim(strtolower($item['typ']));
	if($type === 'offen') $type = 'text';
	elseif($type === 'instruktion') $type = 'instruction'; 

	$options['text'] = $item['wortlaut'];
	$options['alt_text'] = @$item['altwortlaut'];
	$options['switch_text'] = @$item['altwortlautbasedon'];
	$options['displayed_before'] = (int)@$item['displaycount'];
	$options['class'] = @$item['class'];
	$options['optional'] = @$item['optional'];
	$options['skipIf'] = @$item['skipif'];

	$reply_options = array();
	
	if(isset($item['antwortformatanzahl']))
	{
		$options['size'] = $item['antwortformatanzahl'];
	}
	
	if(strpos($type," ")!==false)
	{
		$type = preg_replace("/ +/"," ",$type); // multiple spaces collapse into one
		$type_options = explode(" ",$type); // get real type and options
		$type = $type_options[0];
		unset($type_options[0]); // remove real type from options
		
		$options['type_options'] = $type_options;
	}
	$options['type'] = $type;

	// INSTRUCTION
	switch($type) {
		case "fork":
			if(isset($item['ratinguntererpol']) ) 
				$redirect = $item['ratinguntererpol'];
			elseif(isset($item['MCalt1']) ) 
				$redirect = $item['MCalt1'];
			else 
				$redirect = 'survey.php';
			$item = new Item_fork($name, array(
					'redirect' => $redirect,
					) + $options);
			
			break;
		case "rating": // todo: ratings will disappear and just be MCs with empty options
			$reply_options = array_fill(1, $options['size'], '');
			if(isset($item['ratinguntererpol']) ) 
			{
				$lower = $item['ratinguntererpol'];
				$upper = $item['ratingobererpol'];
			} elseif(isset($item['MCalt1']) ) 
			{
				$lower = $item['MCalt1'];
				$upper = $item['MCalt2'];	
			} else 
			{
				$reply_options = range(1, $options['size']);
				$reply_options = array_combine($reply_options, $reply_options);
				$lower = 1;
				$upper = $options['size'];
			}
			$reply_options[1] = $lower;
			$reply_options[$options['size']] = $upper;
		
			$item = new Item_mc($name, array(
					'reply_options' => $reply_options,
					) + $options);
	
			break;
		case "mc":
		case "mmc":
		case "select":
		case "mselect":
		case "range":
		case "btnradio":
		case "btncheckbox":
			$reply_options = array();
						
			for($op = 1; $op <= 12; $op++) 
			{
				if(isset($item['MCalt'.$op]))
					$reply_options[ $op ] = $item['MCalt'.$op];
			}
			$class = "Item_".$type;
		
			$item = new $class($name, array(
					'reply_options' => $reply_options,
					) + $options);

			break;
		case "text":
			if(isset($options['size']) AND $options['size'] / 255 < 1) // of course Item_textarea can also be specified directly, but in old surveys it isn't
				$class = 'Item_text';
			else
				$class = 'Item_textarea';

			$item = new $class($name, $options);

			break;

		default:
			$class = "Item_".$type;
			if(!class_exists($class,false)) // false to combat false positives using the spl_autoloader 
				$class = 'Item';
			$item = new $class($name, $options);

			break;
	}

	return $item;
}

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
	public $skipIf = null;
	protected $prepend = null;
	protected $append = null;
	protected $input_attributes = array();
	protected $classes_controls = array('controls');
	protected $classes_wrapper = array('control-group','form-row');
	protected $classes_input = array();
	protected $classes_label = array('control-label');
	protected $type_options = array();
	protected $mysql_field =  'TEXT DEFAULT NULL';
	
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
		if(isset($options['type']) AND $this->type === NULL):
			$this->type = $options['type'];
#		elseif($this->type !== NULL AND $this->type!==$options['type']):
#			echo "$name: Type mismatch {$this->type} != {$options['type']}"; // mc != radio, mselect != select etc
		endif;
		
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

		
		if(isset($options['error']) AND $options['error'])
		{
			$this->error = $options['error'];
			$this->classes_wrapper[] = "error";
		}
		if(isset($options['displayed_before']) AND $options['displayed_before']>0)
		{
			$this->displayed_before = $options['displayed_before'];
			if(!$this->error)
				$this->classes_wrapper[] = "warning";
		}
		
		if(isset($options['optional']) AND $options['optional']) 
		{
			$this->optional = true;
			unset($options['optional']);
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
		
		if(isset($options['class']) AND $options['class'])
			$this->classes_wrapper[] = $options['class'];
		
		$this->classes_wrapper[] = "item-" . $this->type;
		
		$this->input_attributes['type'] = $this->type;
		
		if($this->size) 
			$this->input_attributes['maxlength'] = $this->size;
		
		$this->input_attributes['class'] = implode(" ",$this->classes_input);
		
		$this->input_attributes['id'] = "item{$this->id}";
		
/*		echo "<pre>".
			self::_parseAttributes($this->input_attributes).
			'</pre>';
*/	
	}
	public function getResultField()
	{
		if($this->mysql_field!==null)
			return "`{$this->name}` {$this->mysql_field}";
		else return null;
	}
	public function skip($person,$dbh,$results_table)
	{	
		// fixme: cant deal with parentheses, mixed and/or etc.
		if($this->skipIf!=null):
			
			if(
			(strpos($this->skipIf,'AND')!==false AND strpos($this->skipIf,'OR')!==false)
				OR strpos($this->skipIf,'.') !== false
				):
				die($this->name . " mixed AND/OR not yet possible.");
			endif;
			
			$skipIfs = preg_split('/(AND|OR)/',$this->skipIf);
			$constraints = array();
			foreach($skipIfs AS $skip):
				if(! preg_match("/([A-Za-z0-9_]+)\s*(!=|=|==|>|<|>=|<=)\s*['\"]*(\w+)['\"]*\s*/",trim($skip), $matches) ):
					die ($this->name . " invalid skipIf");
				else:
					if($matches[2] == '==') $matches[2] = '=';
					
					$q = "SELECT (
						`{$matches[1]}` IS NULL OR 
						`{$matches[1]}` {$matches[2]} :value 
					) AS test FROM `{$results_table}` WHERE 
					`session_id` = :session_id
					
					LIMIT 1";
					
					// fixme: multiple results rows being added
					echo $q;
					$should_skip = $dbh->prepare($q); // IS NULL clause so that skipifs are not shown if the relevant question has not yet been answered. this will be more conspicuous during testing
					$should_skip->bindParam(":session_id", $person);

					$should_skip->bindParam(":value", $matches[3]);

					$should_skip->execute() or die(print_r($should_skip->errorInfo(), true));
					if($should_skip->rowCount()>0):
						$constraints[$skip] = $should_skip->fetch();
						$constraints[$skip] = $constraints[$skip][0];
					else:
						$constraints[$skip] = true;
					endif;
				endif;
			endforeach;
			echo $this->name;
			pr($constraints);
			
			if(strpos($this->skipIf,'AND')!==false AND !in_array(false,$constraints,true)):
				return true; // skip if all AND conditions evaluate to true 
			elseif(strpos($this->skipIf,'OR')!==false AND in_array(true,$constraints,true)):
				return true; // skip when one of the OR conditions evaluates to true
			elseif(in_array(true,$constraints,true)):
				return true; // skip
			endif;
		endif;
		return false;
	}
	public function validate() 
	{
		$this->val_errors = array();
		
		if( !preg_match('/[A-Za-z0-9_]+/',$this->name) ): 
			$this->val_errors[] = "'{$this->name}' Variablenname darf nur a-Z, 0-9 und den Unterstrich enthalten.";
		endif;
		
		if( trim($this->type) == "" ):
			$this->val_errors[] = "{$this->name}: Typ darf nicht leer sein.";
#		elseif(!in_array($this->type,$this->allowedTypes) ):
#			$this->val_errors[] = "{$this->name}: Typ '{$this->type}' nicht erlaubt. In den Admineinstellungen änderbar.";
		endif;
		
		return $this->val_errors;
	}
	
	public function viewedBy($view_update) {		
		$view_update->bindParam(":item_id", $this->id);
		
   	   	$view_update->execute() or die(print_r($view_update->errorInfo(), true));
	}
	public function switchText($person,$dbh,$results_table) {
        if (@$this->switch_text != null) 
		{
			if(! preg_match("(/([A-Za-z0-9_]+)\s*(!=|=|==|>|<)\s*['\"]*(\w+)['\"]*\s*/",trim($this->switch_text), $matches) )
			{
				die ($this->name . " invalid switch_text");
			} 
			else
			{
				if($matches[2] == '==') $matches[2] = '=';
				
				$switch_condition = $dbh->prepare("SELECT (`{$matches[1]}` {$matches[2]} :value) AS test FROM `{$results_table}` WHERE session_id = :session_id");
				$switch_condition->bindParam(":session_id", $person);
				$switch_condition->bindParam(":value", $matches[3]);
				$switch_condition->execute() or die(print_r($switch_condition->errorInfo(), true));
				$switch = $switch_condition->fetch();
				$switch = (bool)$switch[0];
				
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

		if (!$this->optional AND 
			(( $reply===null || $reply===false || $reply === array() || $reply === '') OR 
			(is_array($reply) AND count($reply)===1 AND current($reply)===''))
		) // missed a required field
		{
			$this->error = _("This field is required.");			
		}
	}
	
	protected function setMoreOptions() 
	{	
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

class Item_text extends Item
{
	public $type = 'text';
	protected function setMoreOptions() 
	{	
		if(is_array($this->type_options) AND count($this->type_options) == 1)
		{
			$this->size = current($type_split);
		}		
	}
	public function validateInput($reply)
	{
		if (isset($this->input_attributes['maxlength']) AND $this->input_attributes['maxlength'] > 0 AND strlen($reply) > $this->input_attributes['maxlength']) // verify maximum length 
		{
			$this->error = __("You can't use that many characters. The maximum is %d",$this->input_attributes['maxlength']);
		}
		parent::validateInput($reply);
	}
}
// textarea automatically chosen when size exceeds a certain limit
class Item_textarea extends Item 
{
	public $type = 'textarea';
	protected function render_input() 
	{
		return 		
			'<textarea '.self::_parseAttributes($this->input_attributes).' rows="'. round($this->size/150) .'" cols="150" name="' . $this->name . '"></textarea>';
	}
}

// spinbox is polyfilled in browsers that lack it 
class Item_number extends Item 
{
	public $type = 'number';
	protected $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	
	protected function setMoreOptions() 
	{
		$this->input_attributes['step'] = 1;
		
		if(isset($this->type_options) AND is_array($this->type_options))
		{
			if(count($this->type_options) == 1) 
				$this->type_options = explode(",",current($this->type_options));

			$min = reset($this->type_options);
			if(is_numeric($min)) $this->input_attributes['min'] = $min;
		
			$max = next($this->type_options);
			if(is_numeric($max)) $this->input_attributes['max'] = $max;
		
			$step = next($this->type_options);
			if(is_numeric($step) OR $step==='any') $this->input_attributes['step'] = $step;	
		}
		
		if(isset($this->input_attributes['min']) AND $this->input_attributes['min']<0)
			$this->mysql_field = str_replace($this->mysql_field,"UNSIGNED ", "");
		if(
			(isset($this->input_attributes['min']) OR isset($this->input_attributes['max'])) AND
				 (abs($this->input_attributes['min'])>32767 OR abs($this->input_attributes['max'])>32767))
			$this->mysql_field = str_replace($this->mysql_field,"TINYINT ", "MEDIUMINT");
		elseif(
			(isset($this->input_attributes['min']) OR isset($this->input_attributes['max'])) AND
				(abs($this->input_attributes['min'])>126 OR abs($this->input_attributes['max'])>126))
			$this->mysql_field = str_replace($this->mysql_field,"TINYINT ", "SMALLINT");
		if(isset($this->input_attributes['step']) AND 
		(string)(int)$this->input_attributes['step'] == $this->input_attributes['step'])
			$this->mysql_field = str_replace($this->mysql_field,"TINYINT ", "FLOAT");
		
	}
	public function validateInput($reply)
	{
		if(isset($this->input_attributes['min']) AND $reply <= $this->input_attributes['min']) // lower number than allowed
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
		parent::validateInput($reply);
	}
}


// slider, polyfilled in most browsers, native in chrome, ..?
class Item_range extends Item_number 
{
	public $type = 'range';

	protected function setMoreOptions() 
	{
		$this->input_attributes['min'] = 0;
		$this->input_attributes['max'] = 100;
			
		parent::setMoreOptions();
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
	public $type = 'email';
	public $size = 250;
	protected $prepend = 'icon-envelope';
	protected $mysql_field = 'VARCHAR (255) DEFAULT NULL';
	
}

// time is polyfilled, we prepended a clock
class Item_time extends Item 
{
	public $type = 'time';
	protected $prepend = 'icon-time';
	protected $input_attributes = array('style' => 'width:80px');
	protected $mysql_field = 'TIME DEFAULT NULL';
	
}

class Item_date extends Item 
{
	public $type = 'date';
	protected $mysql_field = 'DATE DEFAULT NULL';
}

class Item_yearmonth extends Item_date 
{
	public $type = 'yearmonth';
}

class Item_month extends Item_date 
{
	public $type = 'month';
}
class Item_datetime extends Item 
{
	public $type = 'datetime';
	protected $mysql_field = 'DATETIME DEFAULT NULL';
}

class Item_year extends Item 
{
	public $type = 'year';
	protected $mysql_field = 'YEAR DEFAULT NULL';
}

// instructions are rendered at full width
class Item_instruction extends Item 
{
	public $type = 'instruction';
	protected $mysql_field = null;
	
	public function validateInput($reply)
	{
		$this->error = _("You cannot answer instructions.");
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
	public $type = 'submit';
	protected $mysql_field = null;
	
	protected function setMoreOptions() 
	{
		$this->classes_wrapper = array('control-group');
		$this->classes_input[] = 'btn';
		$this->classes_input[] = 'btn-large';
		$this->classes_input[] = 'btn-success';
		unset($this->input_attributes['required']);
		unset($this->input_attributes['name']);
	}
	public function validateInput($reply)
	{
		$this->error = _("You cannot answer instructions.");
	}
	protected function render_input() 
	{
		return 		
			'<button type="submit" '.self::_parseAttributes($this->input_attributes).'>'.$this->text.'</button>';
	}
	protected function render_label() 
	{
		return '';
	}
}

// radio buttons
class Item_mc extends Item 
{
	public $type = 'radio';
	protected $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	
	public function validateInput($reply)
	{
		if(!empty($this->reply_options) AND
			( is_string($reply) AND !in_array($reply,array_keys($this->reply_options)) ) OR // mc
				( is_array($reply) AND $diff = array_diff($reply, array_keys($this->reply_options) ) AND !empty($diff) && current($diff) !=='' ) // mmc
		) // invalid multiple choice answer 
		{
#				pr($reply);
				if(isset($diff)) 
				{
#					pr($diff);
					$problem = $diff;
				}
				else $problem = $reply;
				if(is_array($problem)) $problem = implode("', '",$problem);
				$this->error = __("You chose an option '%s' that is not permitted.",h($problem));
		}
		parent::validateInput($reply);
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
			<input '.self::_parseAttributes($this->input_attributes,array('type','id','required')).' type="hidden" value="" id="item' . $this->id . '_">
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
	public $type = 'checkbox';
	public $optional = true;
	protected $mysql_field = 'VARCHAR (40) DEFAULT NULL';
	
	protected function setMoreOptions() 
	{
		$this->input_attributes['name'] = $this->name . '[]';
	}
	
	protected function render_input() 
	{
		$ret = '
			<input type="hidden" value="" id="item' . $this->id . '_" '.self::_parseAttributes($this->input_attributes,array('id','type','required')).'>
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

// multiple multiple choice, also checkboxes
class Item_check extends Item_mmc 
{
	protected $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	
	protected function setMoreOptions() 
	{
		parent::setMoreOptions();
		$this->input_attributes['name'] = $this->name;
	}
	
	protected function render_label() 
	{
		return '
					<label  for="item' . $this->id . '_1" class="'. implode(" ",$this->classes_label) .'">' .
		($this->error ? '<span class="label label-important hastooltip" title="'.$this->error.'"><i class="icon-warning-sign"></i></span> ' : '').
		 $this->text . '</label>
		';
	}
	public function validateInput($reply)
	{
		if(!in_array($reply,array(0,1)))
		{
			$this->error = __("You chose an option '%s' that is not permitted.",h($reply));	
		}
	}
	protected function render_input() 
	{
		$ret = '
			<input type="hidden" value="" id="item' . $this->id . '_" '.self::_parseAttributes($this->input_attributes,array('id','type','required')).'>
		<label for="item' . $this->id . '_1">
		<input '.self::_parseAttributes($this->input_attributes,array('id')).
		' value="1" id="item' . $this->id . '_1"></label>		
		';
		return $ret;
	}
}

// dropdown select, choose one
class Item_select extends Item 
{
	public $type = 'select';
	protected $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	
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
	protected $mysql_field = 'VARCHAR (40) DEFAULT NULL';
	
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
	protected $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	
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
	protected $mysql_field = 'VARCHAR (40) DEFAULT NULL';
	
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

class Item_btncheck extends Item_check 
{
	protected $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	
	protected function setMoreOptions() 
	{
		parent::setMoreOptions();
		$this->classes_wrapper[] = 'btn-check';
	}
	protected function render_appended () 
	{
		$ret = '<div class="btn-group hidden">
			<button class="btn" data-for="item' . $this->id . '_1">' . 
		'<i class="icon-check-empty"></i>
			</button>';
		$ret .= '</div>';
		
		return $ret;
	}
}

class Item_sex extends Item_btnradio 
{
	protected $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	
	protected function setMoreOptions() 
	{
		parent::setMoreOptions();
		$this->reply_options = array(1=>'♂',2=>'♀');
	}
}

class Item_fork extends Item {
	public $type = 'fork';
	protected $mysql_field =  null;
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
	public $type = 'ip';
	protected $mysql_field =  'VARCHAR 100 DEFAULT NULL';
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