<?php
class ItemFactory
{
	public $errors;
	private $choice_lists = array();
	private $used_choice_lists = array();
	public $skipifs = array();
	function __construct($choice_lists)
	{
		$this->choice_lists = $choice_lists;
	}
	public function make($item) {
		$type = $item['type'];

		if(isset($item['choice_list']) AND $item['choice_list']):
			if(isset($this->choice_lists[ $item['choice_list'] ])):
				$item['choices'] = $this->choice_lists[ $item['choice_list'] ];
				$this->used_choice_lists[ $item['choice_list'] ] = true;
			else:
				$item['val_errors'] = array(__("Choice list %s does not exist, but is specified for item %s", $item['choice_list'], $item['name']));
			endif;
			
		endif;
		
		$type = str_replace("-","_",$type);
		$class = "Item_".$type;
	
		if(!class_exists($class,false)) // false to combat false positives using the spl_autoloader 
			return false;
	
		return new $class($item);
	}
	public function unusedChoiceLists()
	{
		return array_diff(
				array_keys($this->choice_lists),
				array_keys($this->used_choice_lists)
		);
	}
	public function skip($results_table, $openCPU, $skipif)
	{
		$this->skipifs[$skipif] = $openCPU->evaluateWith($results_table, $skipif);
		return $this->skipifs[$skipif];
	}
}

// the default item is a text input, as many browser render any input type they don't understand as 'text'.
// the base class should also work for inputs like date, datetime which are either native or polyfilled but don't require
// special handling here

class Item extends HTML_element
{
	public $id = null;
	public $name = null;
	public $type = null;
	public $type_options = null;
	public $choice_list = null;
	public $label = null;
	public $optional = 0;
	public $class = null;
	public $skipif = null;
	
	public $displaycount = 0;
	public $error = null;
	public $val_errors = array();
	

	protected $mysql_field =  'TEXT DEFAULT NULL';
	protected $prepend = null;
	protected $append = null;
	protected $type_options_array = array();
	public $choices = array();
	protected $hasChoices = false;
	
	
	protected $input_attributes = array();
	protected $classes_controls = array('controls');
	protected $classes_wrapper = array('control-group','form-row');
	protected $classes_input = array();
	protected $classes_label = array('control-label');
		
	public function __construct($options = array()) 
	{ 
		$this->id = isset($options['id']) ? $options['id'] : 0;

		if(isset($options['type'])):
			$this->type = $options['type'];
		endif;
		
		if(isset($options['name']))
			$this->name = $options['name'];
		
		$this->label = isset($options['label'])?$options['label']:'';
				
		if(isset($options['type_options'])):
			$this->type_options = $options['type_options'];
			$this->type_options_array = explode(" ",$options['type_options']);
		endif;
		
		if(isset($options['choice_list']))
			$this->choice_list =  $options['choice_list'];

		if(isset($options['choices']))
			$this->choices =  $options['choices'];

		if(isset($options['skipif']))
			$this->skipif = $options['skipif'];

		if(isset($options['val_error']) AND $options['val_error'])
			$this->val_error = $options['val_error'];
		
		if(isset($options['error']) AND $options['error'])
		{
			$this->error = $options['error'];
			$this->classes_wrapper[] = "error";
		}
		
		if(isset($options['displaycount']) AND $options['displaycount']>0)
		{
			$this->displaycount = $options['displaycount'];
			if(!$this->error)
				$this->classes_wrapper[] = "warning";
		}
		
		$this->input_attributes['name'] = $this->name;
		
		$this->setMoreOptions();

		if(isset($options['optional']) AND $options['optional']) 
		{
			$this->optional = 1;
			unset($options['optional']);
		}
		elseif(isset($options['optional']) AND !$options['optional'])
		{ 
			$this->optional = 0;
		} // else optional stays default
		
		if(!$this->optional) 
		{
			$this->classes_wrapper[] = 'required';
			$this->input_attributes['required'] = 'required';
		} else
		{
			$this->classes_wrapper[] = 'optional';			
		}
		
		if(isset($options['class']) AND $options['class']):
			$this->classes_wrapper[] = $options['class'];
			$this->class = $options['class'];
		endif;
		
		$this->classes_wrapper[] = "item-" . $this->type;
		
		if(!isset($this->input_attributes['type']))
			$this->input_attributes['type'] = $this->type;
		
		$this->input_attributes['class'] = implode(" ",$this->classes_input);
		
		$this->input_attributes['id'] = "item{$this->id}";
		
		if(!empty($this->choices)):
			$this->chooseResultFieldBasedOnChoices();
		endif;
	}
	protected function chooseResultFieldBasedOnChoices()
	{
		if($this->mysql_field==null) return;
		$choices = array_keys($this->choices);
		
		$len = count($choices);
		if( $len == count(array_filter($choices, "is_numeric")) ):
			$this->mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
		
			$min = min($choices);
			$max = max($choices);
			
			if($min < 0 ):
				$this->mysql_field = str_replace($this->mysql_field,"UNSIGNED ", "");
			endif;
			
			if( abs($min)>32767 OR abs($max)>32767 ):
				$this->mysql_field = str_replace($this->mysql_field,"TINYINT", "MEDIUMINT");
			elseif( abs($min)>126 OR abs($min)>126 ):
				$this->mysql_field = str_replace($this->mysql_field,"TINYINT", "SMALLINT");
			elseif( count(array_filter($choices, "is_float")) ):
				$this->mysql_field = str_replace($this->mysql_field,"TINYINT", "FLOAT");
			endif;
		else:
			$lengths = array_map("strlen",$choices);
			$maxlen = max($lengths);
			$this->mysql_field = 'VARCHAR ('.$maxlen.') DEFAULT NULL';
		endif;
	}
	public function getResultField()
	{
		if($this->mysql_field!==null)
			return "`{$this->name}` {$this->mysql_field}";
		else return null;
	}
	public function skip($session_id, $run_session_id, $rdb, $results_table)
	{	
		if($this->skipif!=null):
			
			if(
			(strpos($this->skipif,'AND')!==false AND strpos($this->skipif,'OR')!==false) // and/or mixed? 
				OR strpos($this->skipif,'.') !== false // references to other tables (very simplistic check)
				): // fixme: SO UNSAFE, should at least use least privilege principle and readonly user (not possible on all-inkl...)
					$join = join_builder($rdb, $this->skipif);
					
					$q = "SELECT ( {$this->skipif} ) AS test FROM `survey_run_sessions`
		
					$join
		
					WHERE 
					`survey_run_sessions`.`id` = :run_session_id

					ORDER BY IF(ISNULL( ( {$this->skipif} ) ),1,0), `survey_unit_sessions`.id DESC
		
					LIMIT 1";
					$evaluate = $rdb->prepare($q); // should use readonly
					$evaluate->bindParam(":run_session_id", $run_session_id);

					$evaluate->execute() or die(print_r($evaluate->errorInfo(), true));
					if($evaluate->rowCount()===1):
						$temp = $evaluate->fetch();
						if($temp['test']===null) $result = true;
						else $result = (bool)$temp['test'];
					else:
						$result = true;
					endif;
					return $result;
			endif;
			
			$skipifs = preg_split('/(AND|OR)/',$this->skipif);
			$constraints = array();
			foreach($skipifs AS $skip):
				if(! preg_match("/^([A-Za-z0-9_]+)\s*(!=|=|==|>|<|>=|<=|LIKE)\s*['\"]*([\w%_]+)['\"]*\s*$/",trim($skip), $matches) ):
					die ($this->name . " invalid skipif");
				else:
					if($matches[2] == '==') $matches[2] = '=';
					
					$q = "SELECT (
						`{$matches[1]}` IS NULL OR 
						`{$matches[1]}` {$matches[2]} :value 
					) AS test FROM `{$results_table}` WHERE 
					`session_id` = :session_id
					
					LIMIT 1";
					
#					echo $q;
					$should_skip = $rdb->prepare($q); // IS NULL clause so that skipifs are not shown if the relevant question has not yet been answered. this will be more conspicuous during testing
					$should_skip->bindParam(":session_id", $session_id);

					$should_skip->bindParam(":value", $matches[3]);

					$should_skip->execute() or die(print_r($should_skip->errorInfo(), true));
					if($should_skip->rowCount() > 0):
						$tmp = $should_skip->fetch(PDO::FETCH_ASSOC);
						$constraints[$skip] = (bool)$tmp['test'];
					else:
						$constraints[$skip] = true;
					endif;
				endif;
			endforeach;
#			echo $this->name;
#			pr($constraints);
			
			if(strpos($this->skipif,'AND')!==false AND !in_array(false,$constraints,true)):
				return true; // skip if all AND conditions evaluate to true 
			elseif(strpos($this->skipif,'OR')!==false AND in_array(true,$constraints,true)):
				return true; // skip when one of the OR conditions evaluates to true
			elseif(in_array(true,$constraints,true)):
				return true; // skip
			endif;
		endif;
		return false;
	}
	public function validate() 
	{
		if(!$this->hasChoices AND $this->choice_list!=null):
			$this->val_errors[] = "'{$this->name}' You defined choices for this item, even though this type doesn't have choices.";
		endif;
		if( !preg_match('/^[A-Za-z0-9_]+$/',$this->name) ): 
			$this->val_errors[] = "'{$this->name}' The variable name can only contain a-Z, 0-9 and the underscore (_).";
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
		
   	   	$view_update->execute() or die(print_r($view_update->errorInfo(), true));
	}
	public function substituteText($substitutions) {
        $this->label = str_replace($substitutions['search'], $substitutions['replace'], $this->label);
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
		} elseif($this->optional AND $reply=='')
			$reply = null;
		return $reply;
	}
	
	protected function setMoreOptions() 
	{	
	}
	protected function render_label() 
	{
		return '
					<label class="'. implode(" ",$this->classes_label) .'" for="item' . $this->id . '">'.
		($this->error ? '<span class="label label-important hastooltip" title="'.$this->error.'"><i class="icon-warning-sign"></i></span> ' : '').
			 	$this->label . '</label>
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
	protected $input_attributes = array('type' => 'text');
	protected function setMoreOptions() 
	{	
		if(is_array($this->type_options_array) AND count($this->type_options_array) == 1)
		{
			$val = (int)trim(current($this->type_options_array));
			if(is_numeric($val))
				$this->input_attributes['maxlength'] = $val;
			else
				$this->input_attributes['pattern'] = trim(current($this->type_options_array));	
		}
	}
	public function validateInput($reply)
	{
		if (isset($this->input_attributes['maxlength']) AND $this->input_attributes['maxlength'] > 0 AND strlen($reply) > $this->input_attributes['maxlength']) // verify maximum length 
		{
			$this->error = __("You can't use that many characters. The maximum is %d",$this->input_attributes['maxlength']);
		}
		return parent::validateInput($reply);
	}
}
// textarea automatically chosen when size exceeds a certain limit
class Item_textarea extends Item 
{
	public $type = 'textarea';
	protected function render_input() 
	{
		return 		
			'<textarea '.self::_parseAttributes($this->input_attributes, array('type')).'></textarea>';
	}
}

// textarea automatically chosen when size exceeds a certain limit
class Item_letters extends Item 
{
	public $type = 'letters';
	protected $input_attributes = array('type' => 'text');
	
	protected function setMoreOptions()
	{
		$this->input_attributes['pattern'] = "[A-Za-züäöß.;,!: ]+";
	}
}

// spinbox is polyfilled in browsers that lack it 
class Item_number extends Item 
{
	public $type = 'number';
	protected $input_attributes = array('type' => 'number');
	protected $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	
	protected function setMoreOptions() 
	{
		$this->input_attributes['step'] = 1;
		
		if(isset($this->type_options_array) AND is_array($this->type_options_array))
		{
			if(count($this->type_options_array) == 1) 
				$this->type_options_array = explode(",",current($this->type_options_array));

			$min = trim(reset($this->type_options_array));
			if(is_numeric($min)) $this->input_attributes['min'] = $min;
		
			$max = trim(next($this->type_options_array));
			if(is_numeric($max)) $this->input_attributes['max'] = $max;
			
			$step = trim(next($this->type_options_array));
			if(is_numeric($step) OR $step==='any') $this->input_attributes['step'] = $step;	
		}
		
		$multiply = 2;
		if(isset($this->input_attributes['min']) AND $this->input_attributes['min']<0)
		{
			$this->mysql_field = str_replace($this->mysql_field,"UNSIGNED", "");
			$multiply = 1;
		}
		if(
			(isset($this->input_attributes['min']) AND abs($this->input_attributes['min'])>32767) OR 			
			(isset($this->input_attributes['max']) AND abs($this->input_attributes['max'])> ($multiply*32767) )
		)
			$this->mysql_field = str_replace($this->mysql_field,"TINYINT", "MEDIUMINT");
		elseif(
			(isset($this->input_attributes['min']) AND abs($this->input_attributes['min'])>126) OR 			
			(isset($this->input_attributes['max']) AND abs($this->input_attributes['max'])> ($multiply*126) )
		)
			$this->mysql_field = str_replace($this->mysql_field,"TINYINT", "SMALLINT");
			
		if(isset($this->input_attributes['step']) AND 
		(string)(int)$this->input_attributes['step'] != $this->input_attributes['step'])
			$this->mysql_field = str_replace($this->mysql_field,array("TINYINT","SMALLINT","MEDIUMINT"), "FLOAT");
		
	}
	public function validateInput($reply)
	{
		if(isset($this->input_attributes['min']) AND $reply < $this->input_attributes['min']) // lower number than allowed
		{
			$this->error = __("The minimum is %d",$this->input_attributes['min']);
		}
		elseif(isset($this->input_attributes['max']) AND $reply > $this->input_attributes['max']) // larger number than allowed
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
		return parent::validateInput($reply);
	}
}


// slider, polyfilled in firefox etc, native in many, ..?
class Item_range extends Item_number 
{
	public $type = 'range';
	protected $input_attributes = array('type' => 'range');
	protected $hasChoices = true;

	protected function setMoreOptions() 
	{
		$this->input_attributes['min'] = 0;
		$this->input_attributes['max'] = 100;
		$this->lower_text = current($this->choices);
		$this->upper_text = next($this->choices);
		
			
		parent::setMoreOptions();
	}
	protected function render_input() 
	{
		return (isset($this->choices[1]) ? '<label>'. $this->choices[1] . ' </label> ': '') . 		
			'<input '.self::_parseAttributes($this->input_attributes, array('required')).'>'.
			(isset($this->choices[2]) ? ' <label>'. $this->choices[2] . ' </label>': '') ;
	}
}

// slider with ticks
class Item_range_list extends Item_number 
{
	public $type = 'range_list';
	protected $input_attributes = array('type' => 'range');
	protected $hasChoices = true;
	
	protected function setMoreOptions() 
	{
		$this->input_attributes['min'] = 0;
		$this->input_attributes['max'] = 100;
		$this->input_attributes['list'] = 'dlist'.$this->id;
		$this->input_attributes['data-range'] = "{'animate': true}";
		$this->classes_input[] = "range-list";
		$this->classes_wrapper[] = 'range_list_output';
		
		parent::setMoreOptions();
	}
	protected function render_input() 
	{
		$ret = (isset($this->choices[1]) ? '<label>'. $this->choices[1] . ' </label> ': '') . 		
			'<input '.self::_parseAttributes($this->input_attributes, array('required')).'>'.
			(isset($this->choices[2]) ? ' <label>'. $this->choices[2] . ' </label>': '') ;
		$ret .= '<output id="output'.$this->id.'" class="output"></output>';
		$ret .= '<datalist id="dlist'.$this->id.'">
        <select>';
		for($i = $this->input_attributes['min']; $i <= $this->input_attributes['max']; $i = $i + $this->input_attributes['step']):
        	$ret .= '<option value="'.$i.'">'.$i.'</option>';
		endfor;
			$ret .= '
	        </select>
	    </datalist>';
		return $ret;
	}
}


// email is a special HTML5 type, validation is polyfilled in browsers that lack it
class Item_email extends Item 
{
	public $type = 'email';
	protected $input_attributes = array('type' => 'email', 'maxlength' => 255);
	protected $prepend = 'icon-envelope';
	protected $mysql_field = 'VARCHAR (255) DEFAULT NULL';
	public function validateInput($reply)
	{
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


class Item_url extends Item 
{
	public $type = 'url';
	protected $input_attributes = array('type' => 'url');
	protected $prepend = 'icon-link';
	protected $mysql_field = 'VARCHAR(255) DEFAULT NULL';
	public function validateInput($reply)
	{
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
}

class Item_tel extends Item 
{
	public $type = 'tel';
	protected $input_attributes = array('type' => 'tel');
	
	protected $prepend = 'icon-phone';
	protected $mysql_field = 'VARCHAR(100) DEFAULT NULL';	
}

class Item_cc extends Item 
{
	public $type = 'cc';
	protected $input_attributes = array('type' => 'cc');
	
	protected $prepend = 'icon-credit-card';
	protected $mysql_field = 'VARCHAR(255) DEFAULT NULL';	
}

class Item_color extends Item 
{
	public $type = 'color';
	protected $input_attributes = array('type' => 'color');
	
	protected $prepend = 'icon-tint';
	protected $mysql_field = 'CHAR(7) DEFAULT NULL';	
	public function validateInput($reply)
	{
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


class Item_datetime extends Item 
{
	public $type = 'datetime';
	protected $input_attributes = array('type' => 'datetime');
	
	protected $prepend = 'icon-calendar';	
	protected $mysql_field = 'DATETIME DEFAULT NULL';
	protected $html5_date_format = 'Y-m-d\TH:i';
	protected function setMoreOptions() 
	{
#		$this->input_attributes['step'] = 'any';
		
		if(isset($this->type_options_array) AND is_array($this->type_options_array))
		{
			if(count($this->type_options_array) == 1) 
				$this->type_options_array = explode(",",current($this->type_options_array));

			$min = trim(reset($this->type_options_array));
			if(strtotime($min)) $this->input_attributes['min'] = date($this->html5_date_format, strtotime($min));
		
			$max = trim(next($this->type_options_array));
			if(strtotime($max)) $this->input_attributes['max'] = date($this->html5_date_format, strtotime($max));
		
#			$step = trim(next($this->type_options_array));
#			if(strtotime($step) OR $step==='any') $this->input_attributes['step'] = $step;	
		}
		
	}
	public function validateInput($reply)
	{
		if(!($this->optional AND $reply==''))
		{
				
			$time_reply = strtotime($reply);
			if($time_reply===false)
			{
				$this->error = _('You did not enter a valid date.');	
			}
			if(isset($this->input_attributes['min']) AND $time_reply < strtotime($this->input_attributes['min'])) // lower number than allowed
			{
				$this->error = __("The minimum is %d",$this->input_attributes['min']);
			}
			elseif(isset($this->input_attributes['max']) AND $time_reply > strtotime($this->input_attributes['max'])) // larger number than allowed
			{
				$this->error = __("The maximum is %d",$this->input_attributes['max']);
			}
			$reply = date($this->html5_date_format, $time_reply);
		}
		return parent::validateInput($reply);
	}
}
// time is polyfilled, we prepended a clock
class Item_time extends Item_datetime 
{
	public $type = 'time';
	protected $input_attributes = array('type' => 'time', 'style' => 'width:80px');
	
	protected $prepend = 'icon-time';
	protected $mysql_field = 'TIME DEFAULT NULL';
	protected $html5_date_format = 'H:i';	
}
class Item_datetime_local extends Item_datetime 
{
	public $type = 'datetime-local';
	protected $input_attributes = array('type' => 'datetime-local');
	
}

class Item_date extends Item_datetime 
{
	public $type = 'date';
	protected $input_attributes = array('type' => 'date');
	
	protected $prepend = 'icon-calendar';	
	protected $mysql_field = 'DATE DEFAULT NULL';
	protected $html5_date_format = 'Y-m-d';
	
}

class Item_yearmonth extends Item_datetime 
{
	public $type = 'yearmonth';
	protected $input_attributes = array('type' => 'yearmonth');
	
	protected $prepend = 'icon-calendar-empty';	
	protected $mysql_field = 'DATE DEFAULT NULL';
	protected $html5_date_format = 'Y-m-01';
}

class Item_month extends Item_yearmonth 
{
	public $type = 'month';
	protected $input_attributes = array('type' => 'month');
}

class Item_year extends Item_datetime 
{
	public $type = 'year';
	protected $input_attributes = array('type' => 'year');
	
	protected $html5_date_format = 'Y';
	protected $prepend = 'icon-calendar-empty';	
	protected $mysql_field = 'YEAR DEFAULT NULL';
}
class Item_week extends Item_datetime 
{
	public $type = 'week';
	protected $input_attributes = array('type' => 'week');
	
	protected $html5_date_format = 'Y-mW';
	protected $prepend = 'icon-calendar-empty';	
	protected $mysql_field = 'VARCHAR(9) DEFAULT NULL';
}

// instructions are rendered at full width
class Item_instruction extends Item 
{
	public $type = 'instruction';
	protected $mysql_field = null;
	
	public function validateInput($reply)
	{
		$this->error = _("You cannot answer instructions.");
		return $reply;
	}
	protected function render_inner() 
	{
		return '
					<div class="'. implode(" ",$this->classes_label) .'">'.
					$this->label.
					'</div>
		';
	}
}

class Item_submit extends Item 
{
	public $type = 'submit';
	protected $input_attributes = array('type' => 'submit');
	
	protected $mysql_field = null;
	
	protected function setMoreOptions() 
	{
		$this->classes_wrapper = array('control-group');
		$this->classes_input[] = 'btn';
		$this->classes_input[] = 'btn-large';
		$this->classes_input[] = 'btn-info';
	}
	public function validateInput($reply)
	{
		$this->error = _("You cannot answer buttons.");
		return $reply;
	}
	protected function render_input() 
	{
		return 		
			'<button '.self::_parseAttributes($this->input_attributes, array('required','name')).'>'.$this->label.'</button>';
	}
	protected function render_label() 
	{
		return '';
	}
}

// radio buttons
class Item_mc extends Item 
{
	public $type = 'mc';
	protected $input_attributes = array('type' => 'radio');
	protected $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
	protected $hasChoices = true;
	
	public function validateInput($reply)
	{
		if( !($this->optional AND $reply=='') AND
		!empty($this->choices) AND // check
			( is_string($reply) AND !in_array($reply,array_keys($this->choices)) ) OR // mc
				( is_array($reply) AND $diff = array_diff($reply, array_keys($this->choices) ) AND !empty($diff) && current($diff) !=='' ) // mmc
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
		return parent::validateInput($reply);
	}
	protected function render_label() 
	{
		return '
					<div class="'. implode(" ",$this->classes_label) .'">' .
		($this->error ? '<span class="label label-important hastooltip" title="'.$this->error.'"><i class="icon-warning-sign"></i></span> ' : '').
		 $this->label . '</div>
		';
	}
	protected function render_input() 
	{
		$ret = '<div class="mc-table">
			<input '.self::_parseAttributes($this->input_attributes,array('type','id','required')).' type="hidden" value="" id="item' . $this->id . '_">
		';
		
#		pr($this->choices);
		
		$opt_values = array_count_values($this->choices);
		if(
			isset($opt_values['']) AND // if there are empty options
#			$opt_values[''] > 0 AND 
			current($this->choices)!= '' // and the first option isn't empty
		) $this->label_first = true;  // the first option label will be rendered before the radio button instead of after it.
		else $this->label_first = false;
#		pr((implode(" ",$this->classes_wrapper)));
		if(strpos(implode(" ",$this->classes_wrapper),'mc-first-left')!==false) $this->label_first = true;
		$all_left = false;
		if(strpos(implode(" ",$this->classes_wrapper),'mc-all-left')!==false) $all_left = true;
		
		foreach($this->choices AS $value => $option):			
			$ret .= '
				<label for="item' . $this->id . '_' . $value . '">' . 
					(($this->label_first || $all_left) ? $option.'&nbsp;' : '') . 
				'<input '.self::_parseAttributes($this->input_attributes,array('id')).
				' value="'.$value.'" id="item' . $this->id . '_' . $value . '">' .
					(($this->label_first || $all_left) ? "&nbsp;" : ' ' . $option) . '</label>';
					
			if($this->label_first) $this->label_first = false;
			
		endforeach;
		
		$ret .= '</div>';
		return $ret;
	}
}

// multiple multiple choice, also checkboxes
class Item_mmc extends Item_mc 
{
	public $type = 'mmc';
	protected $input_attributes = array('type' => 'checkbox');
	
	public $optional = 1;
	protected $mysql_field = 'VARCHAR(40) DEFAULT NULL';
	
	protected function setMoreOptions() 
	{
		$this->input_attributes['name'] = $this->name . '[]';
	}
	protected function chooseResultFieldBasedOnChoices()
	{
		$choices = array_keys($this->choices);
		$max = implode(", ",array_filter($choices));
		$maxlen = strlen($max);
		$this->mysql_field = 'VARCHAR ('.$maxlen.') DEFAULT NULL';
	}
	protected function render_input() 
	{
		if(!$this->optional)
			$this->input_attributes['class'] .= ' group-required';
#		$this->classes_wrapper = array_diff($this->classes_wrapper, array('required'));
		unset($this->input_attributes['required']);
		
		$ret = '<div class="mc-table">
			<input type="hidden" value="" id="item' . $this->id . '_" '.self::_parseAttributes($this->input_attributes,array('id','type','required')).'>
		';
		foreach($this->choices AS $value => $option) {
			$ret .= '
			<label for="item' . $this->id . '_' . $value . '">
			<input '.self::_parseAttributes($this->input_attributes,array('id')).
			' value="'.$value.'" id="item' . $this->id . '_' . $value . '">
			' . $option . '</label>
		';
		}
		$ret .= '</div>';
		return $ret;
	}
	public function validateInput($reply)
	{
		$reply = parent::validateInput($reply);
		if(is_array($reply)) $reply = implode(", ",array_filter($reply));
		return $reply;
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
		 $this->label . '</label>
		';
	}
	public function validateInput($reply)
	{
		if(!in_array($reply,array(0,1)))
		{
			$this->error = __("You chose an option '%s' that is not permitted.",h($reply));	
		}
		$reply = parent::validateInput($reply);
		return $reply ? 1 : 0;
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
	protected $hasChoices = true;
	
	protected function render_input() 
	{
		$ret = '<select '.self::_parseAttributes($this->input_attributes, array('type')).'>'; 
		
		if(!isset($this->input_attributes['multiple'])) $ret .= '<option value=""></option>';
		
		foreach($this->choices AS $value => $option):
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
	
	protected function chooseResultFieldBasedOnChoices()
	{
		$choices = array_keys($this->choices);
		$max = implode(", ",array_filter($choices));
		$maxlen = strlen($max);
		$this->mysql_field = 'VARCHAR ('.$maxlen.') DEFAULT NULL';
	}
	protected function setMoreOptions() 
	{
		parent::setMoreOptions();
		$this->input_attributes['multiple'] = true;
		$this->input_attributes['name'] = $this->name.'[]';
	}
	public function validateInput($reply)
	{
		$reply = parent::validateInput($reply);
		if(is_array($reply)) $reply = implode(", ",array_filter($reply));
		return $reply;
	}
}


// dropdown select, choose one
class Item_select_add extends Item
{
	public $type = 'text';
	protected $mysql_field = 'VARCHAR(255) DEFAULT NULL';
	protected $hasChoices = true;
	
	protected function setMoreOptions() 
	{
		parent::setMoreOptions();
		if(isset($this->type_options_array) AND is_array($this->type_options_array))
		{
			if(count($this->type_options_array) == 1) 
				$this->type_options_array = explode(",",current($this->type_options_array));

		
			$maxType = trim(reset($this->type_options_array));
			if(!is_numeric($maxType)) $maxType = 255;
		
			if(count($this->type_options_array) > 1)
			{ 
				$maxSelect = trim(next($this->type_options_array));
			}
			if(!isset($maxSelect) OR !is_numeric($maxSelect)) $maxSelect = 0;
		}
		
		$this->classes_input[] = 'select2add';
		$for_select2 = array();
		foreach($this->choices AS $option)
			$for_select2[] = array('id' => $option, 'text' => $option);

		$this->input_attributes['data-select2add'] = json_encode($for_select2);
		$this->input_attributes['data-select2maximumSelectionSize'] = (int)$maxSelect;
		$this->input_attributes['data-select2maximumInputLength'] = (int)$maxType;
	}
	protected function chooseResultFieldBasedOnChoices()
	{
		$choices = array_keys($this->choices);
		$lengths = array_map("strlen",$choices);
		$lengths[] = $this->input_attributes['data-select2maximumInputLength'];
		$maxlen = max($lengths);
		$this->mysql_field = 'VARCHAR ('.$maxlen.') DEFAULT NULL';
	}
}
class Item_mselect_add extends Item_select_add
{
	public $type = 'text';
	protected $mysql_field = 'TEXT DEFAULT NULL';
	protected function setMoreOptions() 
	{
		parent::setMoreOptions();
		$this->text_choices = true;
		$this->input_attributes['multiple'] = true;
	}
	public function validateInput($reply)
	{
		$reply = parent::validateInput($reply);
		if(is_array($reply)) $reply = implode("\n",array_filter($reply));
		return $reply;
	}
	protected function chooseResultFieldBasedOnChoices()
	{
		$choices = array_keys($this->choices);
		$max = implode(", ",array_filter($choices));
		if(!$this->input_attributes['data-select2maximumSelectionSize']):
			$this->mysql_field = 'TEXT DEFAULT NULL';
		else:
			$maxUserAdded = ($this->input_attributes['data-select2maximumInputLength']+2) * $this->input_attributes['data-select2maximumSelectionSize'];
			$maxlen = strlen($max) + $maxUserAdded;
	#		$this->mysql_field = 'VARCHAR ('.$maxlen.') DEFAULT NULL';
			$this->mysql_field = 'TEXT DEFAULT NULL';
		endif;
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
		foreach($this->choices AS $value => $option):			
		$ret .= '
			<button class="btn" data-for="item' . $this->id . '_' . $value . '">' . 
				$option.
			'</button>';
		endforeach;
		$ret .= '</div>';
		
		return $ret;
	}
}
// dropdown select, choose multiple
class Item_btnrating extends Item_btnradio 
{
	protected $mysql_field = 'SMALLINT DEFAULT NULL';
	protected function setMoreOptions() 
	{	
		parent::setMoreOptions();
		$step = 1;
		$lower_limit = 1;
		$upper_limit = 5;
		
		if(isset($this->type_options_array) AND is_array($this->type_options_array))
		{
			if(count($this->type_options_array) == 1) 
				$this->type_options_array = explode(",",current($this->type_options_array));

			if(count($this->type_options_array) == 1)
			{
				$upper_limit = (int)trim(current($this->type_options_array));
			}
			elseif(count($this->type_options_array) == 2)
			{
				$lower_limit = (int)trim(current($this->type_options_array));
				$upper_limit = (int)trim(next($this->type_options_array));
			}
			elseif(count($this->type_options_array) == 3)
			{
				$lower_limit = (int)trim(current($this->type_options_array));
				$upper_limit = (int)trim(next($this->type_options_array));
				$step = (int)trim(next($this->type_options_array));
			}
		}
		
		$this->lower_text = current($this->choices);
		$this->upper_text = next($this->choices);
		$this->choices =array_combine(range($lower_limit,$upper_limit, $step),range($lower_limit,$upper_limit, $step));
		
	}
	protected function render_input() 
	{
		$ret = '
			<input '.self::_parseAttributes($this->input_attributes,array('type','id','required')).' type="hidden" value="" id="item' . $this->id . '_">
		';
		

		$ret .= "<label class='keep-label'>{$this->lower_text} </label> ";
		foreach($this->choices AS $option):			
			$ret .= '
				<label for="item' . $this->id . '_' . $option . '">' . 
				'<input '.self::_parseAttributes($this->input_attributes,array('id')).
				' value="'.$option.'" id="item' . $this->id . '_' . $option . '">' .
					$option . '</label>';
		endforeach;
		
		return $ret;
	}
	protected function render_appended () 
	{
		$ret = parent::render_appended();
		$ret .= " <label class='keep-label'> {$this->upper_text}</label>";
		
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
		foreach($this->choices AS $value => $option):			
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
		$this->choices = array(1=>'♂',2=>'♀');
	}
}

class Item_geolocation extends Item {
	public $type = 'geolocation';
	protected $input_attributes = array('type' => 'text', 'readonly');
	protected $append = true;
	
	protected $mysql_field =  'TEXT DEFAULT NULL';
	protected function setMoreOptions() 
	{
		$this->input_attributes['name'] = $this->name.'[]';
	}
	public function validateInput($reply)
	{
		$reply = parent::validateInput($reply);
		if(is_array($reply)):
			$reply = array_filter($reply);
			$reply = end($reply);
		endif;
		return $reply;
	}
	protected function render_appended () 
	{
		$ret = '
			<input type="hidden" name="'.$this->name.'" value="">
			<div class="btn-group hidden">
			<button class="btn geolocator item' . $this->id . '">
			<i class="icon-location-arrow"></i>
			</button>';
		$ret .= '</div>';
		
		return $ret;
	}
	
}

class Item_ip extends Item {
	public $type = 'ip';
	protected $input_attributes = array('type' => 'hidden');
	
	protected $mysql_field =  'VARCHAR (46) DEFAULT NULL';
	public function validateInput($reply)
	{
		return $_SERVER["REMOTE_ADDR"];
	}
	public function render() {
		return $this->render_input();
	}
}



class Item_referrer extends Item {
	public $type = 'referrer';
	protected $input_attributes = array('type' => 'hidden');
	protected $mysql_field =  'VARCHAR (255) DEFAULT NULL';
	public function validateInput($reply)
	{
		global $site;
		return $site->last_outside_referrer;
	}
	public function render() {
		return $this->render_input();
	}
}


class Item_server extends Item {
	public $type = 'server';
	protected $input_attributes = array('type' => 'hidden');
	private $get_var = 'HTTP_USER_AGENT';
	
	protected $mysql_field =  'VARCHAR (255) DEFAULT NULL';
	protected function setMoreOptions() 
	{	
		if(isset($this->type_options_array) AND is_array($this->type_options_array))
		{
			if(count($this->type_options_array) == 1) 
				$this->get_var = trim(current($this->type_options_array));
		}
	}
	public function validateInput($reply)
	{
		return $_SERVER[$this->get_var];
	}
	public function validate() 
	{
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
		)))
		{
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
	protected $input_attributes = array('type' => 'hidden');
	private $get_var = 'referred_by';
	
	protected $mysql_field =  'TEXT DEFAULT NULL';
	protected function setMoreOptions() 
	{
		if(isset($this->type_options_array) AND is_array($this->type_options_array))
		{
			if(count($this->type_options_array) == 1) 
				$this->get_var = trim(current($this->type_options_array));
		}
		if(isset($_GET[$this->get_var]))
			$this->input_attributes['value'] = $_GET[$this->get_var];
		else
			$this->input_attributes['value'] = '';
	}
	public function validate() 
	{
		parent::validate();
		if( !preg_match('/^[A-Za-z0-9_]+$/',$this->get_var) ): 
			$this->val_errors[] = __('Problem wiht variable %s "get %s". The part after get can only contain a-Z0-9 and the underscore.', $this->name, $this->get_var);
		endif;
		return $this->val_errors;
	}
	
	public function render() {
		return $this->render_input();
	}
}


class Item_place extends Item_text
{
	protected function setMoreOptions() 
	{
		$this->classes_input[] = 'select2place';
	}
}

class Item_choose_two_weekdays extends Item_mmc
{
	protected function setMoreOptions() 
	{
		$this->optional = 0;
		$this->classes_input[] = 'choose2days';
		$this->input_attributes['name'] = $this->name . '[]';
	}
}
class Item_timezone extends Item_select
{
	protected $mysql_field = 'FLOAT DEFAULT NULL';
	protected function chooseResultFieldBasedOnChoices()
	{
	}
	protected function setMoreOptions()
	{
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
	protected function render_input() 
	{
		$ret = '<select '.self::_parseAttributes($this->input_attributes, array('type')).'>'; 
		
		if(!isset($this->input_attributes['multiple'])) $ret .= '<option value=""></option>';
		
		foreach($this->choices AS $value => $option):
			$ret .= '
				<option value="' . $this->offsets[$value] . '">' . 
					 $option .
				'</option>';
		endforeach;

		$ret .= '</select>';
		
		return $ret;
	}
}


// instructions are rendered at full width
class Item_mc_heading extends Item_mc
{
	public $type = 'mc_heading';
	protected $mysql_field = null;
	
	protected function setMoreOptions()
	{
		$this->input_attributes['disabled'] = 'disabled';
	}
	public function validateInput($reply)
	{
		$this->error = _("You cannot answer headings.");
		return $reply;
	}
	protected function render_label() 
	{
		return '
					<div class="'. implode(" ",$this->classes_label) .'">' .
		($this->error ? '<span class="label label-important hastooltip" title="'.$this->error.'"><i class="icon-warning-sign"></i></span> ' : '').
		 $this->label . '</div>
		';
	}
	protected function render_input() 
	{
		$ret = '<div class="mc-table">';
		$this->input_attributes['type'] = 'radio';
		$opt_values = array_count_values($this->choices);
		if(
			isset($opt_values['']) AND // if there are empty options
#			$opt_values[''] > 0 AND 
			current($this->choices)!= '' // and the first option isn't empty
		) $this->label_first = true;  // the first option label will be rendered before the radio button instead of after it.
		else $this->label_first = false;
#		pr((implode(" ",$this->classes_wrapper)));
		if(strpos(implode(" ",$this->classes_wrapper),'mc-first-left')!==false) $this->label_first = true;
		$all_left = false;
		if(strpos(implode(" ",$this->classes_wrapper),'mc-all-left')!==false) $all_left = true;
		
		foreach($this->choices AS $value => $option):			
			$ret .= '
				<label for="item' . $this->id . '_' . $value . '">' . 
					(($this->label_first || $all_left) ? $option.'&nbsp;' : '') . 
				'<input '.self::_parseAttributes($this->input_attributes,array('id')).
				' value="'.$value.'" id="item' . $this->id . '_' . $value . '">' .
					(($this->label_first || $all_left) ? "&nbsp;" : ' ' . $option) . '</label>';
					
			if($this->label_first) $this->label_first = false;
			
		endforeach;
		
		$ret .= '</div>';
		
		return $ret;
	}
}
	
/*
 * todo: item - rank / sortable
 * todo: item - facebook connect?
 * todo: captcha items
 * todo: item - random number

*/

class HTML_element
{
	
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
}