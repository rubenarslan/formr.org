<?php
require_once INCLUDE_ROOT."Model/DB.php";
require_once INCLUDE_ROOT."Model/Item.php";
require_once INCLUDE_ROOT."Model/RunUnit.php";
require_once INCLUDE_ROOT . "vendor/erusev/parsedown/Parsedown.php";

class Survey extends RunUnit {
	public $id = null;
	public $name = null;
	public $run_name = null;
	public $logo_name = null;
	public $items = array();
	public $maximum_number_displayed = null;
	public $unanswered_batch = array();
	public $already_answered = 0;
	public $not_answered = 0;
	public $progress = 0;
	public $session = null;
	public $errors = array();
	public $results_table = null;
	public $run_session_id = null;
	public $settings = array();
	protected $dbh;
	
	public function __construct($fdb, $session, $unit)
	{
		parent::__construct($fdb,$session,$unit);
		

		$study_data = $this->dbh->prepare("SELECT id,name FROM `survey_studies` WHERE id = :study_id LIMIT 1");
		$study_data->bindParam(":study_id",$unit['unit_id']);
		$study_data->execute() or die(print_r($study_data->errorInfo(), true));
		$vars = $study_data->fetch(PDO::FETCH_ASSOC);
		
		if($vars):
			$this->id = $vars['id'];
			$this->name = $vars['name'];
#			$this->logo_name = $vars['logo_name'];
			$this->results_table = $this->name;
			$this->getSettings();
		endif;
		
		$this->startEntry();
		
		$this->getNextItems();

		$this->post($_POST);
#		if(isset($_POST['session_id'])) 
#		{
#		}
		
		if($this->getProgress()===1)
			$this->end();
	}
	protected function getSettings()
	{
		$study_settings = $this->dbh->prepare("SELECT `key`, `value` FROM `survey_settings` WHERE study_id = :study_id");
		$study_settings->bindParam(":study_id",$this->id);
		$study_settings->execute() or die(print_r($study_settings->errorInfo(), true));
		while($setting = $study_settings->fetch(PDO::FETCH_ASSOC))
			$this->settings[$setting['key']] = $setting['value'];

		return $this->settings;
	}
	public function render() {
		$ret = $this->render_form_header().
		$this->render_items().
		$this->render_form_footer();
		$this->dbh = NULL;
		return $ret;
	}
	protected function startEntry()
	{
		
		$start_entry = $this->dbh->prepare("INSERT INTO `{$this->results_table}` (`session_id`, `study_id`, `created`, `modified`)
																  VALUES(:session_id, :study_id, NOW(),	    NOW()) 
		ON DUPLICATE KEY UPDATE modified = NOW();");
		$start_entry->bindParam(":session_id", $this->session_id);
		$start_entry->bindParam(":study_id", $this->id);
		$start_entry->execute() or die(print_r($start_entry->errorInfo(), true));
	}
	public function post($posted) {

		unset($posted['id']); // cant overwrite your session
		unset($posted['session']); // cant overwrite your session
		unset($posted['session_id']); // cant overwrite your session ID
		unset($posted['study_id']); // cant overwrite your study ID
		unset($posted['created']); // cant overwrite
		unset($posted['modified']); // cant overwrite
		unset($posted['ended']); // cant overwrite

		
		$answered = $this->dbh->prepare("INSERT INTO `survey_items_display` (item_id, session_id, answered, answered_time, modified)
																  VALUES(	:item_id,  :session_id, 1, 		NOW(),	NOW()	) 
		ON DUPLICATE KEY UPDATE 											answered = 1,answered_time = NOW()");
		
		$answered->bindParam(":session_id", $this->session_id);
		
		foreach($posted AS $name => $value)
		{
	        if (isset($this->unanswered_batch[$name])) {
				
				$value = $this->unanswered_batch[$name]->validateInput($value);
				if( ! $this->unanswered_batch[$name]->error )
				{
					$this->dbh->beginTransaction() or die(print_r($answered->errorInfo(), true));
					$answered->bindParam(":item_id", $this->unanswered_batch[$name]->id);
			   	   	$answered->execute() or die(print_r($answered->errorInfo(), true));
					
					$post_form = $this->dbh->prepare("UPDATE `{$this->results_table}`
					SET 
					`$name` = :$name
					WHERE session_id = :session_id AND study_id = :study_id;");
				    $post_form->bindParam(":$name", $value);
					$post_form->bindParam(":session_id", $this->session_id);
					$post_form->bindParam(":study_id", $this->id);

					try
					{
						$post_form->execute();
						$this->dbh->commit();
					}
					catch(Exception $e)
					{
						pr($e);
						pr($value);
					}
					unset($this->unanswered_batch[$name]);
				} else {
					$this->errors[$name] = $this->unanswered_batch[$name]->error;
				}
			}
		} //endforeach

		if(empty($this->errors) AND !empty($posted))
		{ // PRG
			redirect_to(WEBROOT."{$this->run_name}");
		} else
		{
			$this->getProgress();
		}
		
	}
	protected function getProgress() {
		
	    $query = "SELECT `survey_items_display`.answered, COUNT(1) AS count
					FROM 
						`survey_items` LEFT JOIN `survey_items_display`
					ON `survey_items_display`.session_id = :session_id
					AND `survey_items`.id = `survey_items_display`.item_id
					WHERE 
					`survey_items`.study_id = :study_id AND
			        `survey_items`.type NOT IN (
							'note',
							'mc_heading',
							'submit'
						)
					GROUP BY `survey_items_display`.answered;";

		//fixme: progress can become smaller when questions enabling a lot of showifs turn on
		$progress = $this->dbh->prepare($query);
		$progress->bindParam(":session_id", $this->session_id);
		$progress->bindParam(":study_id", $this->id);
		
		$progress->execute() or die(print_r($progress->errorInfo(), true));

		$this->already_answered = 0;
		while($item = $progress->fetch(PDO::FETCH_ASSOC) )
		{	
			if($item['answered']!=null) $this->already_answered += $item['count'];
		}
		
		
		$this->not_answered = count( array_filter($this->unanswered_batch, function ($item)
		{
			if(
				in_array($item->type, array('submit','mc_heading')) 
				OR ($item->type == 'note' AND $item->displaycount > 0) 
				OR $item->hidden
			)
				return false;
			else 
				return true;
		}
) );
		$all_items = $this->already_answered + $this->not_answered;
		
		#pr(array_filter($this->unanswered_batch,'proper_type'));
		if($all_items !== 0) {
			$this->progress = $this->already_answered / $all_items ;

			return $this->progress;
		}
		else
		{
			$this->errors[] = _('Something went wrong, there are no items in this survey!');
			$this->progress = 0;
			return 0;
		}
	}
	protected function getChoices()
	{
		$get_item_choices = $this->dbh->prepare("SELECT list_name, name, label, label_parsed FROM `survey_item_choices` WHERE `survey_item_choices`.study_id = :study_id 
		ORDER BY `survey_item_choices`.id ASC;");
		$get_item_choices->bindParam(":study_id", $this->id); // delete cascades to item display
		$get_item_choices->execute() or die(print_r($get_item_choices->errorInfo(), true));
		$choice_lists = array();
		while($row = $get_item_choices->fetch(PDO::FETCH_ASSOC)):
			if(!isset($choice_lists[ $row['list_name'] ])):
				$choice_lists[ $row['list_name'] ] = array();
			endif;
			
			if($row['label_parsed'] === null):
				$openCPU = $this->makeOpenCPU();
		
				$openCPU->addUserData($this->getUserDataInRun(
					$this->dataNeeded($this->dbh, $row['label'])
				));
				
				$markdown = $openCPU->knitForUserDisplay($row['label']);
				
				if(mb_substr_count($markdown,"</p>")===1 AND preg_match("@^<p>(.+)</p>$@",trim($markdown),$matches)): // simple wraps are eliminated
					$row['label_parsed'] = $matches[1];
				else:
					$row['label_parsed'] = $markdown;
				endif;
			endif;
			$choice_lists[ $row['list_name'] ][$row['name']] = $row['label_parsed'];
		endwhile;
		return $choice_lists;	
	}
	protected function getNextItems() {
		$this->unanswered_batch = array();
		
		$item_query = "SELECT 
				`survey_items`.id,
				`survey_items`.study_id,
				`survey_items`.type,
				`survey_items`.choice_list,
				`survey_items`.type_options,
				`survey_items`.name,
				`survey_items`.label,
				`survey_items`.label_parsed,
				`survey_items`.optional,
				`survey_items`.class,
				`survey_items`.showif,
				`survey_items`.value,
				`survey_items`.`order`,
				
		`survey_items_display`.displaycount, 
		`survey_items_display`.session_id
		
					FROM 
			`survey_items` LEFT JOIN `survey_items_display`
		ON `survey_items_display`.session_id = :session_id
		AND `survey_items`.id = `survey_items_display`.item_id
		WHERE 
		`survey_items`.study_id = :study_id AND
		`survey_items_display`.answered IS NULL
		ORDER BY `survey_items`.id ASC;";
		
#		if($this->maximum_number_displayed) $item_query .= " LIMIT {$this->maximum_number_displayed}";
		$get_items = $this->dbh->prepare($item_query) or die(print_r($this->dbh->errorInfo(), true));
		
		$get_items->bindParam(":session_id",$this->session_id);
		$get_items->bindParam(":study_id", $this->id);

		$get_items->execute() or die(print_r($get_items->errorInfo(), true));
		
		$choice_lists = $this->getChoices();
		$item_factory = new ItemFactory($choice_lists);
		
		
		while($item = $get_items->fetch(PDO::FETCH_ASSOC) )
		{
			$name = $item['name'];
			$this->unanswered_batch[$name] = $item_factory->make($item);
//			pr($this->unanswered_batch[$name]);
			$showif = $this->unanswered_batch[$name]->showif;
			if($showif !== null AND $showif !== '' AND $showif !== "TRUE" AND trim($showif) !== '')
			{
				if(isset($item_factory->showifs[ $showif ]))
				{
					$show = $item_factory->showifs[ $showif ]; // take the cached one
				}
				else
				{
					$openCPU = $this->makeOpenCPU();

					$dataNeeded = $this->dataNeeded($this->dbh, $showif );
					$dataNeeded[] = $this->results_table; // currently we stupidly add the current results table to every request, because it would be bothersome to parse the statement to understand whether it is not needed
					$dataNeeded = array_unique($dataNeeded); // no need to add it twice
					
					$openCPU->addUserData($this->getUserDataInRun(
						$dataNeeded
					));
					
					$show = $item_factory->showif($this->results_table, $openCPU, $showif);
				}
				
				if(!$show)
				{
					$this->unanswered_batch[$name]->hide();
//					unset($this->unanswered_batch[$name]); // todo: just hide it when we want JS
				}
			}
		}
		return $this->unanswered_batch;
	}
	protected function render_form_header() {
		$action = WEBROOT."{$this->run_name}";

		if(!isset($this->settings['form_classes'])) $this->settings['form_classes'] = '';
		$ret = '<form action="'.$action.'" method="post" class="form-horizontal '.$this->settings['form_classes'].'" accept-charset="utf-8">';
		
	    /* pass on hidden values */
	    $ret .= '<input type="hidden" name="session_id" value="' . $this->session_id . '" />';
	
		if(!isset($this->settings["displayed_percentage_maximum"]))
			$this->settings["displayed_percentage_maximum"] = 90;
		$prog = round($this->progress,2) * $this->settings["displayed_percentage_maximum"];
		if(isset($this->settings["add_percentage_points"]))
			$prog += $this->settings["add_percentage_points"];
		
	    $ret .= '<div class="progress">
				  <div data-starting-percentage="'.$prog.'" data-number-of-items="'.$this->not_answered.'" class="progress-bar" style="width: '.$prog.'%;">'.$prog.'%</div>
			</div>';
		$ret .= '<div class="form-group error form-message">
			<div class="control-label">'.implode("<br>",array_unique($this->errors)).'
			</div></div>';	
		return $ret;

	}

	protected function render_items() 
	{
		$ret = '';
		$items = $this->unanswered_batch;
		
		$view_query = "INSERT INTO `survey_items_display` (item_id,  session_id, displaycount, created, modified)
											     VALUES(:item_id, :session_id, 1,				 NOW(), NOW()	) 
		ON DUPLICATE KEY UPDATE displaycount = displaycount + 1, modified = NOW()";
		$view_update = $this->dbh->prepare($view_query);

		$view_update->bindParam(":session_id", $this->session_id);
	
		$itemsDisplayed = $i = 0;
		$need_submit = true;
	    foreach($items AS &$item) 
		{
			$i++;

	        // fork-items sind relevant, werden aber nur behandelt, wenn sie auch an erster Stelle sind, also alles vor ihnen schon behandelt wurde
			if ($item->type === 'submit')
			{
				if($itemsDisplayed === 0)
					continue; // skip submit buttons once everything before them was dealt with				
			}
			else if ($item->type === "note")
			{
				$next = current($items);
				if(
					$item->displaycount AND 											 // if this was displayed before
					(
						$next === false OR 								    				 // this is the end of the survey
#						$next->hidden === true OR 								    				 // the next item is hidden
						in_array( $next->type , array('note','submit','mc_heading'))  		 // the next item isn't a normal item
					)
				)
				{
					continue; // skip this note							
				}
			}
			else if ($item->type === "mc_heading")
			{
				$next = current($items);
				if(
					(
						$next === false OR 								    				 // this is the end of the survey
#						$next->hidden === true OR 								    				 // the next item is hidden
						!in_array( $next->type , array('mc','mc_multiple','mc_button','mc_multiple_button'))  		 // the next item isn't a mc item
					)
				)
				{
					continue; // skip this note							
				}
			}
			
			
			$item->viewedBy($view_update);
			if(! $item->hidden)
				$itemsDisplayed++;
			
			if($item->label_parsed === null): // item label has to be dynamically generated with user data
				$openCPU = $this->makeOpenCPU();
		
				$openCPU->addUserData($this->getUserDataInRun(
					$this->dataNeeded($this->dbh,$item->label)
				));
				$markdown = $openCPU->knitForUserDisplay($item->label);
				
				if(mb_substr_count($markdown,"</p>")===1 AND preg_match("@^<p>(.+)</p>$@",trim($markdown),$matches)): // simple wraps are eliminated
					$item->label_parsed = $matches[1];
				else:
					$item->label_parsed = $markdown;
				endif;
			endif;
						
			if($item->value !== null): // if there is a sticky value to be had
				if(is_numeric($item->value)):
					$item->input_attributes['value'] = $item->value;
				else:
					$openCPU = $this->makeOpenCPU();
					if($item->value=="sticky") $item->value = "tail(na.omit({$this->results_table}\${$item->name}),1)";
					
					$dataNeeded = $this->dataNeeded($this->dbh, $item->value );
					$dataNeeded[] = $this->results_table; // currently we stupidly add the current results table to every request, because it would be bothersome to parse the statement to understand whether it is not needed
					$dataNeeded = array_unique($dataNeeded); // no need to add it twice
					
					$openCPU->addUserData($this->getUserDataInRun(
						$dataNeeded
					));
		
					$item->input_attributes['value'] = h( $openCPU->evaluateWith($this->results_table, $item->value) );
				endif;
			else:
				$item->presetValue = null;
			endif;

			$ret .= $item->render();

	        // when the maximum number of items to display is reached, stop
	        if (
				($this->maximum_number_displayed != null AND
				$itemsDisplayed >= $this->maximum_number_displayed) OR 
				$item->type === 'submit' 
			)
			{
				$need_submit = ($item->type !== 'submit');
	            break;
	        }
	    } //end of for loop
		
		if($need_submit) // only if no submit was part of the form
		{
			if(isset($this->settings["submit_button_text"])):
				$sub_sets = array(
								'label_parsed' => $this->settings["submit_button_text"]
				);
			else:
				$sub_sets = array('label_parsed' => 'Weiter', 'class_input' => 'btn-info');
			endif;
			$item = new Item_submit($sub_sets);
			$ret .= $item->render();
		}
		
		return $ret;
	}

	protected function render_form_footer() 
	{
	    return "</form>"; /* close form */
	}

	public function end()
	{
		$post_form = $this->dbh->prepare("UPDATE 
					`{$this->results_table}` 
			SET `ended` = NOW() 
		WHERE `session_id` = :session_id AND 
		`study_id` = :study_id AND 
		`ended` IS NULL;");
		$post_form->bindParam(":session_id", $this->session_id);
		$post_form->bindParam(":study_id", $this->id);
		$post_form->execute() or die(print_r($post_form->errorInfo(), true));
		
		return parent::end();
	}
	public function exec()
	{
		if($this->called_by_cron)
			return true; // never show to the cronjob
		
		if($this->progress===1) {
			$this->end();
			return false;
		}
		return array('title' => (isset($this->settings['title'])?$this->settings['title']: null),
		'body' => 
			'
	
        '.

		 (isset($this->settings['title'])?"<h1>{$this->settings['title']}</h1>":'') . 
		 (isset($this->settings['description'])?"<p class='lead'>{$this->settings['description']}</p>":'') .
		 '
		<div class="row">
			<div class="col-md-12">

		'.

		 $this->render().
		
		 '

			</div> <!-- end of col-md-12 div -->
		</div> <!-- end of row div -->
		'.
		(isset($this->settings['problem_email'])?
		'
		<div class="row">
			<div class="col-md-12">'.
			(isset($this->settings['problem_text'])?
				str_replace("%s",$this->settings['problem_email'],$this->settings['problem_text']) :
				('<a href="mailto:'.$this->settings['problem_email'].'">'.$this->settings['problem_email'].'</a>')
			).
			'</div>
		</div>
		':'')
		);

	}
}