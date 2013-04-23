<?php
require_once "includes/DB.php";

class Survey {
	public $items = array();
	public $maximum_number_displayed = null;
	public $unanswered_batch = array();
	public $already_answered = 0;
	public $not_answered = 0;
	public $progress = 0;
	public $person = null;
	public $timestarted = null;
	public $errors = array();
	
	public function __construct($person, $study, $run, $options = array()) {
		$this->person = $person;
		$this->study = $study;
		$this->run = $run;
		$this->timestarted = @$options['timestarted'];
		$this->dbh = new DB();
		
		if($this->getProgress()===1)
			$this->finish();
		else
			$this->getNextItems();
	}
	public function render() {
		$ret = $this->render_form_header().
		$this->render_items().
		$this->render_form_footer();
		$this->dbh = NULL;
		return $ret;
	}
	public function post($posted) {
		/*
		validate each item
		prepare statement.
		insert on duplicate update.
		*/
		unset($posted['vpncode']); // cant overwrite your own vpncode
		// todo: should prevent changing some other meta-data as well
		
		$answered = $this->dbh->prepare("INSERT INTO `" . ITEMDISPLAYTABLE . "` (variablenname,  vpncode, answered, modified)
																  VALUES(	:variablenname, :vpncode, 1, 			NOW()	) 
		ON DUPLICATE KEY UPDATE 											answered = 1, modified = NOW()");
		
		$answered->bindParam(":vpncode", $this->person);
		
		foreach($posted AS $name => $value)
		{
	        if (isset($this->unanswered_batch[$name])) {
				$this->unanswered_batch[$name]->validateInput($value);
				if( ! $this->unanswered_batch[$name]->error )
				{
					
					if(is_array($posted[$name])) $value = implode(", ",$posted[$name]);
					else $value = $posted[$name];

					$this->dbh->beginTransaction() or die(print_r($answered->errorInfo(), true));
					$answered->bindParam(":variablenname", $name);
			   	   	$answered->execute() or die(print_r($answered->errorInfo(), true));
					
					$post_form = $this->dbh->prepare("INSERT INTO `".RESULTSTABLE."` (`vpncode`, `created_at`, `updated_at`, `$name`)
																			  VALUES(:vpncode, 		NOW(),	    NOW(),	 		:$name) 
					ON DUPLICATE KEY UPDATE `$name` = :$name, updated_at = NOW();");
				    $post_form->bindParam(":$name", $value);
					$post_form->bindParam(":id", $id);
					$post_form->bindParam(":vpncode", $this->person);
					$post_form->execute() or die(print_r($post_form->errorInfo(), true));

					$this->dbh->commit() or die(print_r($answered->errorInfo(), true));
					unset($this->unanswered_batch[$name]);
				} else {
					$this->errors[$name] = $this->unanswered_batch[$name]->error;
				}
			}
		} //endforeach

		if(empty($this->errors) AND !empty($variables))
		{ // PRG
			redirect_to("survey.php?study_id=".$this->study->id);
		}
	}
	protected function getProgress() {
		
	    $query = "SELECT `".ITEMDISPLAYTABLE."`.answered, COUNT(1) AS count
					FROM 
						`".ITEMSTABLE."` LEFT JOIN `".ITEMDISPLAYTABLE."`
					ON `".ITEMDISPLAYTABLE."`.vpncode = :vpncode
					AND `".ITEMSTABLE."`.variablenname = `".ITEMDISPLAYTABLE."`.variablenname
					WHERE 
					(`".ITEMSTABLE."`.skipif =  '' OR `".ITEMSTABLE."`.skipif IS NULL) AND
			        `".ITEMSTABLE."`.typ NOT IN (
							'instruction',
							'fork',
							'submit'
						)
			        AND (`".ITEMSTABLE."`.special =  '' OR `".ITEMSTABLE."`.special IS NULL)
					GROUP BY `".ITEMDISPLAYTABLE."`.answered;";

		//fixme: just realised the progress bar score is not the same as the actual progress (skipifs etc.)
		$progress = $this->dbh->prepare($query);
		$progress->bindParam(":vpncode", $this->person);
		$progress->execute() or die(print_r($progress->errorInfo(), true));

		while($item = $progress->fetch(PDO::FETCH_ASSOC) )
		{	
			if($item['answered']!=null) $this->already_answered += $item['count'];
			else $this->not_answered += $item['count'];
		}
		$all_items = $this->already_answered + count($this->unanswered_batch);

		$this->progress = $this->already_answered / $all_items ;			

		return $this->progress;
	}
	protected function getNextItems() {
		$this->unanswered_batch = array();
		
		$item_query = "SELECT 
				`".ITEMSTABLE."`.*,
		`".ITEMDISPLAYTABLE."`.displaycount, 
		`".ITEMDISPLAYTABLE."`.vpncode
					FROM 
			`".ITEMSTABLE."` LEFT JOIN `".ITEMDISPLAYTABLE."`
		ON `".ITEMDISPLAYTABLE."`.vpncode = :vpncode
		AND `".ITEMSTABLE."`.variablenname = `".ITEMDISPLAYTABLE."`.variablenname
		WHERE 
		`".ITEMDISPLAYTABLE."`.answered IS NULL;";
		
#		if($this->maximum_number_displayed) $item_query .= " LIMIT {$this->maximum_number_displayed}";
#		todo: max_displayed many annoyances with forward looking kind of items, and I want to do this dynamically anyway.		
		$get_items = $this->dbh->prepare($item_query) or die(print_r($this->dbh->errorInfo(), true));
		
		$get_items->bindParam(":vpncode",$this->person);
		
		$get_items->execute() or die(print_r($get_items->errorInfo(), true));
		
		while($item = $get_items->fetch(PDO::FETCH_ASSOC) )
		{
			$this->unanswered_batch[$item['variablenname']] = $this->legacy_translate_item($item);
		}
		return $this->unanswered_batch;
		
		// todo: add answered bool field to itemdisplaytable
	}
	protected function render_form_header() {
		$action = "survey.php?study_id=".$this->study->id;
		if(isset($this->run))
			$action .= "&run_id=".$this->run->id;

		$ret = '<form novalidate action="'.$action.'" method="post" class="form-horizontal" accept-charset="utf-8">';

	    /* pass on hidden values */
	    $ret .= '<input type="hidden" name="vpncode" value="' . $this->person . '" />';
	    if( !empty( $timestarted ) ) {
	        $ret .= '<input type="hidden" name="timestarted" value="' . $timestarted .'" />';
		} else {
			debug("<strong>render_form_header:</strong> timestarted was not set or empty");
		}
	
		pr($this->errors);
	    $ret .= '<div class="progress">
				  <div class="bar" style="width: '.(round($this->progress,2)*100).'%;"></div>
			</div>';
		$ret .= '<div class="control-group error form-message">
			<div class="control-label">'.implode("<br>",array_unique($this->errors)).'
			</div></div>';	
		return $ret;

	}

	protected function render_items() 
	{
		$ret = '';
		$substitutions = $this->getSubstitutions();
		$items = $this->unanswered_batch;
		
		$view_query = "INSERT INTO `" . ITEMDISPLAYTABLE . "` (variablenname,  vpncode, displaycount, created, modified)
																  VALUES(	:variablenname, :vpncode, 1, NOW(), NOW()	) 
		ON DUPLICATE KEY UPDATE displaycount = displaycount + 1, modified = NOW()";
		$view_update = $this->dbh->prepare($view_query);
		$view_update->bindParam(":vpncode", $this->person);
	
		$itemsDisplayed = $i = 0;
		$need_submit = true;
	    foreach($items AS &$item) 
		{
			$i++;
	        // fork-items sind relevant, werden aber nur behandelt, wenn sie auch an erster Stelle sind, also alles vor ihnen schon behandelt wurde

	        if ($item->type === "fork") 
			{
				if($itemsDisplayed !== 0)
					break; // only render items up to a fork
	        }
			elseif ($item->type === 'submit')
			{
				if($itemsDisplayed === 0)
					continue; // skip submit buttons once everything before them was dealt with				
			}
			else if ($item->type === "instruction")
			{
				$next = current($items);
				if(
					$item->displayed_before AND 											 // if this was displayed before
					(
						$next === false OR 								    				 // this is the end of the survey
						in_array( $next->type , array('instruction','fork'))  				 // the next item isn't a normal item
					)
				) 
				{
					continue; // skip this instruction							
				}
			}
				
			
	        // Gibt es Bedingungen, unter denen das Item alternativ formuliert wird?
			
			$item->viewedBy($view_update);
			$itemsDisplayed++;
			
			$item->switchText($this->person,$this->dbh);
			
			if(!empty($substitutions['search']))
			{
		        $item->substituteText($substitutions);				
			}

			
			$ret .= $item->render();
#			$ret .= '<strong>'.key($items);

	        // when the maximum number of items to display is reached, stop
	        if (
				($this->maximum_number_displayed != null AND
				$itemsDisplayed >= $this->maximum_number_displayed) OR 
				$item->type === 'fork'  OR 
				$item->type === 'submit'  // todo: relevant-column can be killed off now
			)
			{
				$need_submit = ($item->type !== 'submit');
	            break;
	        }
	    } //end of for loop
		
		if($need_submit) // only if no submit was part of the form
		{
			$item = new Item_submit('final_submit',array('text'=>'Weiter!'));
			$ret .= $item->render();
		}
		
		return $ret;
	}

	protected function legacy_translate_item($item) { // may have been a bad idea to name (array)input and (object)return value identically?
		$options = array();
		$options['id'] = $item['id'];
		$name = $item['variablenname'];
		$type = trim(strtolower($item['typ']));
		if($type === 'offen') $type = 'text';
		if($type === 'instruktion') $type = 'instruction'; 
	
		$options['text'] = $item['wortlaut'];
		$options['displayed_before'] = (int)$item['displaycount'];

		$reply_options = array();
		
		if(isset($item['antwortformatanzahl']))
		{
			$options['size'] = $item['antwortformatanzahl'];
		}
		
		if(strpos($type," ")!==false)
		{
			$type = preg_replace(" +"," ",$type); // multiple spaces collapse into one
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
				$reply_options = array_fill(1, $item['size'], '');
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
					$reply_options = range(1, $item['size']);
					$reply_options = array_combine($reply_options, $reply_options);
					$lower = 1;
					$upper = $item['size'];
				}
				$reply_options[1] = $lower;
				$reply_options[$item['size']] = $upper;
			
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
				if(isset($options['size']) AND $options['size'] / 150 < 1) // of course Item_textarea can also be specified directly, but in old surveys it isn't
					$class = 'Item';
				else
					$class = 'Item_textarea';

				$item = new $class($name, $options);

				break;
	
			default:
				$class = "Item_".strtoupper($type);
				if(!class_exists($class)) 
					$class = 'Item';
				$item = new $class($name, $options);

				break;
		}

		return $item;
	}
	protected function render_form_footer() 
	{
	    return "</form>"; /* close form */
	}
	protected function getSubstitutions() 
	{

	$subs_query = $this->dbh->query ( "SELECT * FROM ".SUBSTABLE." ORDER BY id DESC" ) or die(print_r($this->dbh->errorInfo(), true));	// get all substitutions


	$search = $replace = array();
	while( $substitution = $subs_query->fetch() ) 
		{
		
	        switch( $substitution['mode'] ) 
			{
		        case NULL:
					$get_entered = $this->dbh->prepare("SELECT `{$substitution['value']}` FROM ".RESULTSTABLE." WHERE vpncode = :person AND `{$substitution['value']}` IS NOT NULL ORDER BY created_at DESC LIMIT 1;");
					break;
				default:
					$get_entered = $this->dbh->prepare("SELECT `{$substitution['value']}` FROM ".RESULTSTABLE." WHERE vpncode = :vpncode AND `{$substitution['value']}` IS NOT NULL AND iteration = :mode LIMIT 1;");
					$get_entered->bindParam(":mode",$subst['mode']);
					break;
	        }
			$get_entered->bindParam(":person",$this->person);
			$get_entered->execute() or die(print_r($switch_condition->errorInfo(), true));
		
	        if( $data = $get_entered->fetch(PDO::FETCH_NUM) ) {
	            $search[] = $subst['key'];
	            $replace[] = htmlspecialchars($data[0]);
	        }
		}
		return array('search' => $search,'replace' => $replace);
	}
	
	protected function finish()
	{
		
	}
}